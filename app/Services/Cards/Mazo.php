<?php

namespace App\Services\Cards;

use App\Models\Auditoria;
use App\Models\CardsDecision;
use App\Models\CardsEvento;
use App\Models\CardsMazo;
use App\Models\Expense;
use App\Services\Acciones\GestionGastos;
use App\Services\Chat\ErrorChat;
use Closure;
use Illuminate\Database\QueryException;

/**
 * El mazo de revision de gastos (interfaz «cards») y su estado por instancia en el SQLite del chat. Laravel es la fuente de verdad: el
 * traductor gRPC solo pide el mazo, los eventos con version y envia las decisiones (docs/chat-api-interna.md, «Cards»).
 *
 * - El mazo se GENERA CUANDO LA PERSONA LO PIDE (abre la pantalla de cards o la reconecta: mazo()), con los gastos pendientes de revisar en
 *   ese momento (ReviewCards). Si la pantalla se reconecta mientras la sesion sigue (hay actividad hace menos de `cards.reanudar_s` y
 *   quedan tarjetas por resolver) se reanuda el mismo mazo; si no, se genera uno nuevo.
 * - `deck_id` es unico por mazo generado: las decisiones de un mazo anterior ya no cuentan.
 * - Acciones del mazo: `revisado` (derecha) pone pending_review = false; `luego` (izquierda, keep) deja la tarjeta para el final del mazo
 *   sin tocar el gasto. Boton de tarjeta: `corregir` (Corregir con el agente), que solo la deja para luego: la correccion la hace la
 *   persona despues en el chat. Deshacer vuelve a poner pending_review = true. Una clave de idempotencia ya vista no repite nada.
 * - Nunca cambia otro dato que pending_review. Cada decision queda en la auditoria.
 * - Cada cambio es un evento con version (cards_eventos.id, nunca baja). Todo se serializa con un cerrojo de fichero, asi que no hay carreras
 *   entre la web y los workers.
 */
class Mazo
{
    public const REVISADO = 'revisado';

    public const LUEGO = 'luego';

    public const CORREGIR = 'corregir';

    /** Decisiones que dejan la tarjeta para el final del mazo, todavia por resolver. */
    private const APARTADAS = [self::LUEGO, self::CORREGIR];

    private const PATRON = '/^[A-Za-z0-9_-]{1,64}$/';

    public function __construct(private readonly ReviewCards $tarjetas, private readonly GestionGastos $gastos) {}

    // ------------------------------------------------------------------ API

    /** @return array{version: int, deck: array<string, mixed>} el mazo entero y la version a la que corresponde */
    public function mazo(): array
    {
        return $this->exclusivo(function (): array {
            $this->abrir();

            return ['version' => $this->version(), 'deck' => $this->deck()];
        });
    }

    /**
     * Eventos con version mayor que `$despues`. `reiniciar` = true si el que llama esta tan atras (o tan adelantado) que debe volver a pedir el mazo.
     *
     * @return array{version: int, reiniciar: bool, eventos: list<array<string, mixed>>}
     */
    public function eventos(int $despues): array
    {
        return $this->exclusivo(function () use ($despues): array {
            if (($mazo = CardsMazo::query()->first()) !== null) {
                $this->tocar($mazo);   // un Watch abierto mantiene viva la sesion
            }
            $version = $this->version();
            $primero = CardsEvento::query()->min('id');
            if ($despues > $version || ($primero !== null && $despues > 0 && $despues < (int) $primero - 1)) {
                return ['version' => $version, 'reiniciar' => true, 'eventos' => []];
            }
            $eventos = CardsEvento::query()->where('id', '>', $despues)->orderBy('id')->limit((int) config('cards.max_eventos_leidos'))->get()
                ->map(fn (CardsEvento $e): array => ['version' => (int) $e->id, 'deck_id' => $e->deck_id, 'tipo' => $e->tipo, 'carga' => $e->carga])->all();

            return ['version' => $version, 'reiniciar' => false, 'eventos' => $eventos];
        });
    }

    /**
     * Una decision del usuario (SendAction, cards.md §6.2). Devuelve sin error cuando se ignora (mazo o tarjeta que ya no estan, accion
     * desconocida, clave repetida).
     *
     * @param  array<string, mixed>  $d  deck_id, item_id, action_id, idempotency_key, undo?, acted_at?
     *
     * @throws ErrorChat bad_request si falta un campo o no cumple las reglas
     */
    public function decidir(array $d): void
    {
        foreach (['deck_id', 'item_id', 'action_id', 'idempotency_key'] as $campo) {
            if (! is_string($d[$campo] ?? null) || preg_match(self::PATRON, $d[$campo]) !== 1) {
                throw ErrorChat::badRequest("El campo «{$campo}» falta o no es válido.");
            }
        }
        $deshacer = ($d['undo'] ?? false) === true;
        $cuando = isset($d['acted_at']) && is_int($d['acted_at']) && $d['acted_at'] > 0 ? $d['acted_at'] : null;

        $this->exclusivo(function () use ($d, $deshacer, $cuando): void {
            if (CardsDecision::query()->where('idempotency_key', $d['idempotency_key'])->exists()) {
                return;
            }
            $mazo = CardsMazo::query()->first();
            $fila = $mazo !== null && $mazo->deck_id === $d['deck_id'] ? $this->fila($mazo, $d['item_id']) : null;
            if ($fila === null) {
                return;
            }

            $this->tocar($mazo);
            $antes = $this->orden($mazo);
            if ($deshacer) {
                $this->deshacer($mazo, $fila, $d, $cuando);
            } else {
                $this->hacer($mazo, $fila, $d, $cuando);
            }
            $this->emitirOrden($mazo, $fila['item_id'], $antes);
        });
    }

    // ------------------------------------------------------------------ estado

    /**
     * La persona pide el mazo. Sin gastos pendientes, el mazo queda vacio. Si el mazo guardado sigue en uso se reanuda; si no (otra sesion,
     * terminado o parado hace tiempo) se genera uno nuevo con lo pendiente de ahora.
     */
    private function abrir(): void
    {
        $mazo = CardsMazo::query()->first();

        if ($mazo !== null && $this->reanudable($mazo)) {
            $this->tocar($mazo);

            return;
        }

        $filas = $this->tarjetas->filas();
        CardsMazo::query()->delete();
        if ($filas === []) {
            if (CardsEvento::query()->orderByDesc('id')->value('deck_id') !== '') {
                $this->emitir('', 'deck', ['deck' => $this->deckVacio()]);
            }

            return;
        }

        $nuevo = 'revision-'.substr(sha1($this->ahora().'|'.random_int(0, PHP_INT_MAX)), 0, 20);
        CardsMazo::query()->create([
            'deck_id' => $nuevo, 'titulo' => 'Revisar gastos', 'tarjetas' => $filas,
            'creado_ms' => $this->ahora(), 'actualizado_ms' => $this->ahora(),
        ]);
        $this->emitir($nuevo, 'deck', ['deck' => $this->deck()]);
        $this->podar();
    }

    /** True si la sesion del mazo sigue: hubo actividad hace menos de cards.reanudar_s y queda alguna tarjeta por resolver. */
    private function reanudable(CardsMazo $mazo): bool
    {
        if ($this->ahora() - (int) $mazo->actualizado_ms >= (int) config('cards.reanudar_s') * 1000) {
            return false;
        }
        $decisiones = $this->decisiones($mazo->deck_id);
        foreach ($mazo->tarjetas as $fila) {
            $d = $decisiones[$fila['item_id']] ?? null;
            if ($d === null || in_array($d->action_id, self::APARTADAS, true)) {
                return true;
            }
        }

        return false;
    }

    /** Marca actividad en el mazo (como mucho cada 30 s, para no escribir en cada sondeo). */
    private function tocar(CardsMazo $mazo): void
    {
        if ($this->ahora() - (int) $mazo->actualizado_ms > 30000) {
            $mazo->update(['actualizado_ms' => $this->ahora()]);
        }
    }

    private function podar(): void
    {
        $limite = $this->ahora() - (int) config('cards.dias_decisiones') * 86400000;
        CardsDecision::query()->where('creado_ms', '<', $limite)->delete();
        $conservar = (int) CardsEvento::query()->max('id') - (int) config('cards.eventos_conservados');
        CardsEvento::query()->where('id', '<=', $conservar)->delete();
    }

    private function version(): int
    {
        return (int) CardsEvento::query()->max('id');
    }

    private function ahora(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /** @param  array<string, mixed>  $carga */
    private function emitir(string $deckId, string $tipo, array $carga): void
    {
        CardsEvento::query()->create(['deck_id' => $deckId, 'tipo' => $tipo, 'carga' => $carga, 'creado_ms' => $this->ahora()]);
    }

    // ------------------------------------------------------------------ decisiones

    /**
     * @param  array<string, mixed>  $fila
     * @param  array<string, mixed>  $d
     */
    private function hacer(CardsMazo $mazo, array $fila, array $d, ?int $cuando): void
    {
        $accion = $d['action_id'];
        if (! in_array($accion, [self::REVISADO, self::LUEGO, self::CORREGIR], true)) {
            return;
        }
        $actual = $this->decisiones($mazo->deck_id)[$fila['item_id']] ?? null;
        if ($actual !== null && ! in_array($actual->action_id, self::APARTADAS, true)) {
            return;   // la tarjeta ya esta resuelta: otra decision no la cambia (se deshace antes)
        }

        $efecto = null;
        if ($accion === self::REVISADO) {
            $gasto = Expense::query()->find($fila['item_id']);
            // Si el gasto ya no esta pendiente (o ya no existe) no hay nada que cambiar ni que deshacer despues.
            if ($gasto !== null && $gasto->pending_review) {
                $this->gastos->marcarRevision($gasto, false, 'cards');
                $efecto = ['gasto_id' => (string) $gasto->id, 'pendiente_antes' => true];
            }
        }
        try {
            CardsDecision::query()->create([
                'deck_id' => $mazo->deck_id, 'item_id' => $fila['item_id'], 'action_id' => $accion, 'idempotency_key' => $d['idempotency_key'],
                'undo' => false, 'acted_ms' => $cuando, 'efecto' => $efecto, 'creado_ms' => $this->ahora(),
            ]);
        } catch (QueryException $e) {
            $this->restaurar($efecto);

            throw $e;
        }
        Auditoria::registrar('cards.decision', 'Decisión en la revisión de gastos', ['gasto_id' => $fila['item_id'], 'accion' => $accion, 'efecto' => $efecto !== null]);
        $this->emitir($mazo->deck_id, 'card_changed', ['card' => $this->tarjeta($mazo, $fila)]);
    }

    /**
     * @param  array<string, mixed>  $fila
     * @param  array<string, mixed>  $d
     */
    private function deshacer(CardsMazo $mazo, array $fila, array $d, ?int $cuando): void
    {
        $ultima = $this->decisiones($mazo->deck_id)[$fila['item_id']] ?? null;
        if ($ultima === null) {
            return;
        }
        $this->restaurar(is_array($ultima->efecto) ? $ultima->efecto : null);
        $ultima->update(['deshecha' => true]);
        CardsDecision::query()->create([
            'deck_id' => $mazo->deck_id, 'item_id' => $fila['item_id'], 'action_id' => $d['action_id'], 'idempotency_key' => $d['idempotency_key'],
            'undo' => true, 'acted_ms' => $cuando, 'efecto' => null, 'creado_ms' => $this->ahora(),
        ]);
        Auditoria::registrar('cards.decision_deshecha', 'Decisión deshecha en la revisión de gastos', ['gasto_id' => $fila['item_id'], 'accion' => $ultima->action_id]);
        $this->emitir($mazo->deck_id, 'card_changed', ['card' => $this->tarjeta($mazo, $fila)]);
    }

    /** Deshace el efecto de «revisado»: el gasto vuelve a estar pendiente de revisar. @param  array<string, mixed>|null  $efecto */
    private function restaurar(?array $efecto): void
    {
        if ($efecto === null || empty($efecto['gasto_id'])) {
            return;
        }
        $gasto = Expense::query()->find($efecto['gasto_id']);
        if ($gasto !== null && ! $gasto->pending_review) {
            $this->gastos->marcarRevision($gasto, true, 'cards');
        }
    }

    /** Si la decision cambio el sitio de la tarjeta (una tarjeta «luego» va al final), un card_moved. @param list<string> $antes */
    private function emitirOrden(CardsMazo $mazo, string $itemId, array $antes): void
    {
        $despues = $this->orden($mazo);
        if ($antes === $despues) {
            return;
        }
        $posicion = array_search($itemId, $despues, true);
        $this->emitir($mazo->deck_id, 'card_moved', ['item_id' => $itemId, 'before_item_id' => $despues[$posicion + 1] ?? null]);
    }

    /**
     * La decision actual de cada tarjeta (la ultima no deshecha), por item_id.
     *
     * @return array<string, CardsDecision>
     */
    private function decisiones(string $deckId): array
    {
        $porItem = [];
        foreach (CardsDecision::query()->where('deck_id', $deckId)->where('undo', false)->where('deshecha', false)->orderBy('id')->get() as $d) {
            $porItem[$d->item_id] = $d;
        }

        return $porItem;
    }

    // ------------------------------------------------------------------ forma del mazo (JSON de cards.proto)

    /** @return array<string, mixed>|null */
    private function fila(CardsMazo $mazo, string $itemId): ?array
    {
        foreach ($mazo->tarjetas as $fila) {
            if ($fila['item_id'] === $itemId) {
                return $fila;
            }
        }

        return null;
    }

    /**
     * Orden de las tarjetas: el del mazo, salvo las dejadas para luego, que van al final por orden de decision.
     *
     * @return list<string>
     */
    private function orden(CardsMazo $mazo): array
    {
        $decisiones = $this->decisiones($mazo->deck_id);
        $normales = [];
        $apartadas = [];
        foreach ($mazo->tarjetas as $fila) {
            $d = $decisiones[$fila['item_id']] ?? null;
            if ($d !== null && in_array($d->action_id, self::APARTADAS, true)) {
                $apartadas[(int) $d->id] = $fila['item_id'];
            } else {
                $normales[] = $fila['item_id'];
            }
        }
        ksort($apartadas);

        return [...$normales, ...array_values($apartadas)];
    }

    /** @return array<string, mixed> */
    private function deckVacio(): array
    {
        return ['deck_id' => '', 'actions' => [], 'cards' => [], 'show_progress' => false];
    }

    /** @return array<string, mixed> el Deck actual: acciones, tarjetas en orden y con su decision */
    private function deck(): array
    {
        $mazo = CardsMazo::query()->first();
        if ($mazo === null) {
            return $this->deckVacio();
        }
        $porId = [];
        foreach ($mazo->tarjetas as $fila) {
            $porId[$fila['item_id']] = $fila;
        }
        $decisiones = $this->decisiones($mazo->deck_id);
        $tarjetas = array_map(fn (string $id): array => $this->tarjeta($mazo, $porId[$id], $decisiones), $this->orden($mazo));

        return [
            'deck_id' => $mazo->deck_id,
            'title' => 'Revisar gastos',
            'actions' => [
                ['action_id' => self::LUEGO, 'label' => 'Luego', 'icon' => 'ICON_LATER', 'tone' => 'TONE_WARNING', 'direction' => 'DIRECTION_LEFT', 'keep' => true, 'sound' => 'SOUND_LATER'],
                ['action_id' => self::REVISADO, 'label' => 'Revisado', 'icon' => 'ICON_CHECK', 'tone' => 'TONE_POSITIVE', 'direction' => 'DIRECTION_RIGHT', 'keep' => false, 'sound' => 'SOUND_CONFIRM'],
            ],
            'cards' => $tarjetas,
            'show_progress' => true,
            'end_text' => 'Revisión terminada. Los gastos que has marcado como revisados ya no están pendientes.',
            'done_text' => 'He terminado de revisar los gastos. Los que dejé para corregir con el agente siguen pendientes: ayúdame a corregirlos en el chat.',
        ];
    }

    /**
     * Una tarjeta (cards.proto Card) a partir de su fila de la foto del mazo y su decision.
     *
     * @param  array<string, mixed>  $fila
     * @param  array<string, CardsDecision>|null  $decisiones  las del mazo, si ya se leyeron
     * @return array<string, mixed>
     */
    private function tarjeta(CardsMazo $mazo, array $fila, ?array $decisiones = null): array
    {
        $tarjeta = [
            'item_id' => $fila['item_id'],
            'title' => mb_strcut($fila['titulo'], 0, (int) config('cards.max_titulo_bytes'), 'UTF-8'),
            'text' => mb_strcut($fila['texto'], 0, (int) config('cards.max_texto_bytes'), 'UTF-8'),
            'detail' => $this->detalle($fila, $this->topeDetalle(count($mazo->tarjetas))),
            'buttons' => [['action_id' => self::CORREGIR, 'label' => 'Corregir con el agente', 'icon' => 'ICON_LATER', 'tone' => 'TONE_NEUTRAL', 'sound' => 'SOUND_LATER']],
            'tone' => 'TONE_NEUTRAL',
            'layout' => ($fila['imagen'] ?? null) !== null ? 'LAYOUT_IMAGE_TOP' : 'LAYOUT_TEXT',
            'group' => mb_strcut((string) $fila['grupo'], 0, (int) config('cards.max_grupo_bytes'), 'UTF-8'),
        ];
        if (($fila['imagen'] ?? null) !== null) {
            $tarjeta['image'] = $fila['imagen'] + ['alt' => 'Foto del ticket'];
        }
        $decision = ($decisiones ?? $this->decisiones($mazo->deck_id))[$fila['item_id']] ?? null;
        if ($decision !== null) {
            $tarjeta['decision'] = $decision->action_id;
        }

        return $tarjeta;
    }

    /** Bytes de detalle por tarjeta: los de cards.md, menos si hay tantas tarjetas que el mazo no cabria en 1 MiB. */
    private function topeDetalle(int $tarjetas): int
    {
        return max(512, min((int) config('cards.max_detalle_bytes'), intdiv((int) config('cards.max_bytes_mazo'), max(1, $tarjetas)) - 700));
    }

    /** @param  array<string, mixed>  $fila */
    private function detalle(array $fila, int $tope): string
    {
        $lineas = $fila['lineas'] ?? [];
        if ($lineas === []) {
            return mb_strcut('Este gasto no tiene líneas todavía.', 0, $tope, 'UTF-8');
        }
        $texto = 'Líneas:';
        foreach ($lineas as $i => $linea) {
            $nueva = "\n- ".$linea;
            $resto = (int) ($fila['lineas_total'] ?? count($lineas)) - $i;
            $cierre = $resto > 1 ? "\n…y ".($resto - 1).' líneas más' : '';
            if (strlen($texto) + strlen($nueva) + strlen($cierre) > $tope) {
                $texto .= "\n…y {$resto} líneas más";

                break;
            }
            $texto .= $nueva;
        }
        if ((int) ($fila['lineas_total'] ?? 0) > count($lineas) && ! str_contains($texto, 'líneas más')) {
            $texto .= "\n…y ".((int) $fila['lineas_total'] - count($lineas)).' líneas más';
        }

        return mb_strcut($texto, 0, $tope, 'UTF-8');
    }

    // ------------------------------------------------------------------ cerrojo

    /**
     * Serializa el estado del mazo entre procesos (la web y los workers) con un cerrojo de fichero junto al SQLite del chat.
     *
     * @template T
     *
     * @param  Closure(): T  $trabajo
     * @return T
     */
    private function exclusivo(Closure $trabajo): mixed
    {
        $ruta = dirname((string) config('database.connections.chat.database')).'/cards.lock';
        $manejador = @fopen($ruta, 'c');
        if ($manejador === false) {
            throw ErrorChat::serviceError();
        }
        flock($manejador, LOCK_EX);
        try {
            return $trabajo();
        } finally {
            flock($manejador, LOCK_UN);
            fclose($manejador);
        }
    }
}
