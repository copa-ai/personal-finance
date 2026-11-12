<?php

namespace App\Services\Chat\Claude;

use App\Enums\EstadoChat;
use App\Enums\ExpenseItemType;
use App\Enums\ExpenseStatus;
use App\Enums\Recurrence;
use App\Models\AgenteArchivo;
use App\Models\Category;
use App\Models\ChatMensaje;
use App\Models\Establishment;
use App\Models\Expense;
use App\Models\ExpenseItem;
use App\Services\Acciones\GestionLineas;
use App\Services\Acciones\LanzaOcr;
use App\Services\Acciones\ResumenGastos;
use App\Services\Agente\AlmacenAgente;
use App\Services\Chat\Archivos;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Instantanea de SOLO LECTURA de los datos del dominio para un turno de chat: JSON/CSV legibles en agente/encargos/chat-{id}/
 * (con AlmacenAgente activo tambien en la base, de donde los envia EjecutorClaude al runner).
 *
 * PRIVACIDAD: el agente solo ve categorias, establecimientos, gastos y lineas de gasto (y los totales calculados de ellos). Nunca ve
 * bancos, cuentas, activos, cripto, carteras demo, mercado, proveedores OCR, usuarios, ajustes, copias ni auditoria: no hay forma de
 * pedirlos porque aqui solo se exportan esas cuatro tablas, cada una con una lista BLANCA de columnas. LEEME.json describe lo
 * exportado, lo recortado y lo excluido.
 *
 * Tamano acotado (config chat_claude.instantanea): cada archivo y el total tienen tope; si no cabe, se quitan filas del final
 * de la lista (siempre ordenadas de lo mas reciente o relevante a lo menos) y LEEME.json lo marca con `truncada`.
 */
class InstantaneaDatos
{
    /** Lo que NO se exporta (se publica en LEEME.json para que el agente no lo busque). */
    public const EXCLUIDO = ['bancos y cuentas bancarias', 'activos y sus documentos', 'cripto', 'carteras demo y operaciones demo', 'mercado y cotizaciones',
        'proveedores y trabajos de OCR', 'usuarios y contraseñas', 'ajustes de la instancia', 'copias de seguridad', 'auditoría', 'sesiones, cola, caché, notificaciones, comentarios y notas rápidas',
        'archivos del agente', 'peticiones de secretos del chat', 'chat_archivos (salvo las fotos referenciadas en el historial)'];

    /** @var ?Collection<int, Expense> */
    private ?Collection $gastos = null;

    private string $carpeta = '';

    private int $restante = 0;

    /** @var array<int, array<string, mixed>> */
    private array $indice = [];

    /** @var array<string, string> ruta relativa -> contenido (para subirlo a la base del agente) */
    private array $archivos = [];

    public static function relativa(string $id): string
    {
        return "encargos/chat-{$id}";
    }

    public static function ruta(string $id): string
    {
        return AlmacenAgente::carpeta().'/'.static::relativa($id);
    }

    /** Borra la instantanea de un turno (disco y base del agente) y las de turnos abandonados hace mas de 6 horas. */
    public static function limpiar(string $id): void
    {
        static::borrarDirectorio(static::ruta($id));
        if (AlmacenAgente::activo()) {
            AgenteArchivo::query()->where('ruta', 'like', static::relativa($id).'/%')->delete();
        }
        foreach (glob(AlmacenAgente::carpeta().'/encargos/chat-*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < time() - 6 * 3600) {
                static::borrarDirectorio($dir);
                AlmacenAgente::activo() && AgenteArchivo::query()->where('ruta', 'like', 'encargos/'.basename($dir).'/%')->delete();
            }
        }
    }

    private static function borrarDirectorio(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() && ! $f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * Escribe la instantanea del turno y la deja en la base del agente (si esta activa).
     *
     * @return array<string, mixed> el contenido de LEEME.json
     */
    public function exportar(ChatMensaje $pregunta, ChatMensaje $respuesta): array
    {
        static::limpiar($respuesta->id);
        $this->gastos = null;
        $this->carpeta = static::ruta($respuesta->id);
        $this->restante = (int) $this->limite('max_bytes_datos');
        $this->indice = [];
        $this->archivos = [];
        if (! is_dir($this->carpeta) && ! @mkdir($this->carpeta, 0775, true) && ! is_dir($this->carpeta)) {
            throw new \RuntimeException('No se pudo crear la carpeta de la instantánea.');
        }

        $this->categorias();
        $this->establecimientos();
        $this->gastos();
        $this->lineas();
        $this->totales();
        $this->catalogos();
        $this->conversacion($pregunta, $respuesta);
        $leeme = $this->leeme($respuesta);
        $this->escribir('LEEME.json', (string) json_encode($leeme, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false);

        if (AlmacenAgente::activo()) {
            foreach ($this->archivos as $relativa => $contenido) {
                AlmacenAgente::guardar(static::relativa($respuesta->id).'/'.$relativa, $contenido);
            }
        }
        $this->archivos = [];

        return $leeme;
    }

    private function limite(string $clave): int|float
    {
        return config("chat_claude.instantanea.{$clave}");
    }

    // ------------------------------------------------------------------ escritura acotada

    /** @param  bool  $contar  si cuenta contra el presupuesto de datos (LEEME y las fotos no) */
    private function escribir(string $relativa, string $contenido, bool $contar = true): void
    {
        $destino = $this->carpeta.'/'.$relativa;
        if (! is_dir(dirname($destino))) {
            @mkdir(dirname($destino), 0775, true);
        }
        file_put_contents($destino, $contenido);
        $this->archivos[$relativa] = $contenido;
        $contar && $this->restante -= strlen($contenido);
    }

    /** Ni siquiera el archivo vacio cabe en lo que queda del presupuesto de datos. */
    private function noCabe(string $contenido): bool
    {
        return strlen($contenido) > max(0, $this->restante);
    }

    /**
     * El presupuesto se agoto: el archivo no se escribe (no se pasa del tope) y el indice lo dice.
     *
     * @param  array<string, string>  $columnas
     * @param  array<int, string>  $relaciones
     */
    private function omitir(string $archivo, string $descripcion, string $formato, int $filasTotales, array $columnas, array $relaciones): void
    {
        $this->indice[] = ['archivo' => $archivo, 'descripcion' => $descripcion, 'formato' => $formato, 'filas' => 0, 'filas_totales' => $filasTotales,
            'truncada' => true, 'omitido' => 'sin presupuesto de datos', 'columnas' => $columnas, 'relaciones' => $relaciones];
    }

    /**
     * Quita filas del final hasta que el JSON quepa en el tope del archivo y en lo que queda de presupuesto.
     *
     * @param  array<int, mixed>  $filas
     * @return array{0: string, 1: array<int, mixed>, 2: bool} contenido, filas escritas, si se recorto
     */
    private function acotarLista(array $filas, callable $serializar): array
    {
        $tope = (int) min($this->limite('max_bytes_archivo'), max(0, $this->restante));
        $total = count($filas);
        $contenido = $serializar($filas);
        while (strlen($contenido) > $tope && $filas !== []) {
            $filas = array_slice($filas, 0, max(0, (int) floor(count($filas) * 0.85) - 1));
            $contenido = $serializar($filas);
        }

        return [$contenido, $filas, count($filas) < $total];
    }

    /**
     * @param  array<int, array<string, mixed>>  $filas
     * @param  array<string, string>  $columnas  nombre => descripcion
     * @param  array<int, string>  $relaciones
     */
    private function lista(string $archivo, string $descripcion, array $filas, array $columnas, array $relaciones = []): void
    {
        [$contenido, $escritas, $truncada] = $this->acotarLista($filas, fn (array $f): string => $this->jsonLineas($f));
        if ($this->noCabe($contenido)) {
            $this->omitir($archivo, $descripcion, 'json', count($filas), $columnas, $relaciones);

            return;
        }
        $this->escribir($archivo, $contenido);
        $this->indice[] = ['archivo' => $archivo, 'descripcion' => $descripcion, 'formato' => 'json', 'filas' => count($escritas), 'filas_totales' => count($filas),
            'truncada' => $truncada, 'columnas' => $columnas, 'relaciones' => $relaciones];
    }

    /** @param  array<int, array<string, mixed>>  $filas */
    private function jsonLineas(array $filas): string
    {
        $lineas = array_map(fn (array $f): string => (string) json_encode($f, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), $filas);

        return $lineas === [] ? "[]\n" : "[\n".implode(",\n", $lineas)."\n]\n";
    }

    /**
     * @param  array<int, array<int, mixed>>  $filas
     * @param  array<int, string>  $cabecera
     * @param  array<string, string>  $columnas
     * @param  array<int, string>  $relaciones
     */
    private function csv(string $archivo, string $descripcion, array $cabecera, array $filas, array $columnas, array $relaciones = []): void
    {
        [$contenido, $escritas, $truncada] = $this->acotarLista($filas, fn (array $f): string => $this->csvTexto($cabecera, $f));
        if ($this->noCabe($contenido)) {
            $this->omitir($archivo, $descripcion, 'csv', count($filas), $columnas, $relaciones);

            return;
        }
        $this->escribir($archivo, $contenido);
        $this->indice[] = ['archivo' => $archivo, 'descripcion' => $descripcion, 'formato' => 'csv', 'filas' => count($escritas), 'filas_totales' => count($filas),
            'truncada' => $truncada, 'columnas' => $columnas, 'relaciones' => $relaciones];
    }

    /**
     * @param  array<int, string>  $cabecera
     * @param  array<int, array<int, mixed>>  $filas
     */
    private function csvTexto(array $cabecera, array $filas): string
    {
        $f = fopen('php://memory', 'w+');
        fputcsv($f, $cabecera, ',', '"', '');
        foreach ($filas as $fila) {
            fputcsv($f, array_map(fn (mixed $v): string => is_bool($v) ? ($v ? '1' : '0') : (string) ($v ?? ''), $fila), ',', '"', '');
        }
        rewind($f);
        $texto = (string) stream_get_contents($f);
        fclose($f);

        return $texto;
    }

    // ------------------------------------------------------------------ tablas

    private function categorias(): void
    {
        $filas = Category::query()->orderBy('name')->limit((int) $this->limite('max_categorias'))->get()->map(fn (Category $c): array => [
            'id' => $c->id, 'name' => $c->name, 'parent_id' => $c->parent_id,
        ])->all();
        $this->lista('categories.json', 'Categorías de gasto (árbol: parent_id apunta a otra categoría).', $filas, [
            'id' => 'identificador (el que se usa en las acciones)', 'name' => 'nombre', 'parent_id' => 'categoría padre (categories.json id) o null',
        ], ['parent_id -> categories.json id']);
    }

    private function establecimientos(): void
    {
        $filas = Establishment::query()->orderBy('name')->limit((int) $this->limite('max_establecimientos'))->get()->map(fn (Establishment $e): array => [
            'id' => $e->id, 'name' => $e->name, 'category_id' => $e->category_id,
        ])->all();
        $this->lista('establishments.json', 'Establecimientos donde se gasta. category_id es su categoría por defecto: la que cuenta en las líneas que no tienen la suya.', $filas, [
            'id' => 'identificador (el que se usa en las acciones)', 'name' => 'nombre', 'category_id' => 'categoría por defecto (categories.json id) o null',
        ], ['category_id -> categories.json id']);
    }

    /** Gastos de la ventana reciente mas todos los pendientes de revisar. @return Collection<int, Expense> */
    private function gastosDeLaInstantanea(): Collection
    {
        if ($this->gastos !== null) {
            return $this->gastos;
        }
        $desde = now()->subDays((int) $this->limite('dias_gastos'));
        $recientes = Expense::query()->where('date', '>=', $desde)->orderByDesc('date')->orderByDesc('id')->limit((int) $this->limite('max_gastos'))->pluck('id')->all();
        $pendientes = Expense::query()->where('pending_review', true)->orderByDesc('date')->limit((int) $this->limite('max_pendientes'))->pluck('id')->all();

        return $this->gastos = Expense::query()->with('establishment:id,name')->withCount('items')->whereIn('id', array_values(array_unique([...$pendientes, ...$recientes])))
            ->orderByDesc('date')->orderByDesc('id')->get();
    }

    private function gastos(): void
    {
        $zona = ResumenGastos::zona();
        $sumas = ExpenseItem::query()->whereIn('expense_id', $this->gastosDeLaInstantanea()->pluck('id'))->selectRaw('expense_id, SUM(quantity * unit_price) as suma')
            ->groupBy('expense_id')->pluck('suma', 'expense_id');
        $filas = $this->gastosDeLaInstantanea()->map(function (Expense $g) use ($zona, $sumas): array {
            $suma = round((float) ($sumas[$g->id] ?? 0), 2);

            return [
                'id' => $g->id, 'establishment_id' => $g->establishment_id, 'establecimiento' => $g->establishment?->name,
                'date' => $g->date?->setTimezone($zona)->format('Y-m-d H:i'), 'total' => $g->total === null ? null : (float) $g->total,
                'status' => $g->status?->value, 'pending_review' => (bool) $g->pending_review, 'tiene_foto_de_ticket' => filled($g->ticket_photo_hash),
                'lineas' => (int) $g->items_count, 'suma_de_lineas' => $suma, 'descuadre' => $g->total === null ? null : round($suma - (float) $g->total, 2),
            ];
        })->all();
        $this->lista('expenses.json', 'Gastos de los últimos días más TODOS los pendientes de revisar, lo más reciente primero. Las lineas de cada uno están en expense_items.csv.', $filas, [
            'id' => 'identificador (el que se usa en las acciones)', 'establishment_id' => 'establishments.json id o null', 'establecimiento' => 'nombre del establecimiento',
            'date' => 'fecha y hora local AAAA-MM-DD HH:MM', 'total' => 'total del gasto en euros (null si aún no se sabe)', 'status' => 'PAID (pagado), PENDING, REFUNDED (reembolsado) o BLOCKED',
            'pending_review' => 'true si está marcado para revisar (p. ej. creado por OCR o desde una foto)', 'tiene_foto_de_ticket' => 'si tiene la foto del ticket guardada (no se te manda la imagen)',
            'lineas' => 'número de líneas', 'suma_de_lineas' => 'suma de cantidad x precio de sus líneas', 'descuadre' => 'suma_de_lineas - total (null sin total); lo normal es 0',
        ], ['establishment_id -> establishments.json id']);
    }

    private function lineas(): void
    {
        $ids = $this->gastosDeLaInstantanea()->pluck('id');
        $filas = ExpenseItem::query()->whereIn('expense_id', $ids)->orderByDesc('expense_id')->orderBy('id')->limit((int) $this->limite('max_lineas'))->get()
            ->map(fn (ExpenseItem $l): array => [$l->id, $l->expense_id, $l->category_id, $l->concept, (float) $l->quantity, (float) $l->unit_price,
                GestionLineas::importe($l), $l->item_type?->value, $l->recurrence?->value, $l->movement_type?->value])->all();
        $this->csv('expense_items.csv', 'Líneas de los gastos de expenses.json (varias por gasto).',
            ['id', 'expense_id', 'category_id', 'concept', 'quantity', 'unit_price', 'line_total', 'item_type', 'recurrence', 'movement_type'], $filas, [
                'id' => 'identificador (el que se usa en las acciones)', 'expense_id' => 'expenses.json id', 'category_id' => 'categories.json id o vacío (entonces cuenta la categoría del establecimiento)',
                'concept' => 'concepto', 'quantity' => 'cantidad', 'unit_price' => 'precio unitario en euros (negativo = descuento)', 'line_total' => 'cantidad x precio unitario',
                'item_type' => 'FIXED, VARIABLE_REGULAR o VARIABLE_IRREGULAR', 'recurrence' => 'NONE, WEEKLY, MONTHLY, QUARTERLY o YEARLY',
                'movement_type' => 'PURCHASE normal; ADJUSTMENT es un ajuste de descuadre',
            ], ['expense_id -> expenses.json id', 'category_id -> categories.json id']);
    }

    /** Totales pagados por mes y por mes y categoria (ultimos meses). */
    private function totales(): void
    {
        $resumen = app(ResumenGastos::class);
        $zona = ResumenGastos::zona();
        $meses = max(1, (int) $this->limite('meses_totales'));
        $porMes = [];
        $porCategoria = [];
        $inicio = CarbonImmutable::now($zona)->startOfMonth();
        for ($i = 0; $i < $meses; $i++) {
            $mes = $inicio->subMonths($i);
            $desde = $mes->utc();
            $hasta = $mes->addMonth()->utc();
            $t = $resumen->total($desde, $hasta);
            $porMes[] = ['mes' => $mes->format('Y-m'), 'total' => $t['total'], 'gastos' => $t['gastos'], 'gastos_sin_total' => $t['sin_total']];
            foreach ($resumen->porCategoria($desde, $hasta) as $c) {
                $porCategoria[] = ['mes' => $mes->format('Y-m'), 'categoria_id' => $c['categoria_id'], 'categoria' => $c['categoria'], 'total' => $c['total']];
            }
        }
        $datos = ['aviso' => 'Solo gastos con estado PAID. Por categoría se suman las líneas (cantidad x precio) con la categoría de la línea o, si no tiene, la del establecimiento.',
            'por_mes' => $porMes, 'por_mes_y_categoria' => $porCategoria, 'gastos_pendientes_de_revisar_en_total' => $resumen->numeroPendientes()];
        $this->escribir('totales.json', (string) json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false);
        $this->indice[] = ['archivo' => 'totales.json', 'descripcion' => 'Totales pagados por mes y por mes y categoría (últimos meses), ya calculados: úsalos en lugar de sumar a mano.',
            'formato' => 'json', 'filas' => count($porMes), 'filas_totales' => count($porMes), 'truncada' => false, 'columnas' => [
                'por_mes' => 'mes AAAA-MM, total en euros, gastos y gastos_sin_total', 'por_mes_y_categoria' => 'mes, categoría (null = sin categoría) y total en euros',
            ], 'relaciones' => ['categoria_id -> categories.json id']];
    }

    /** Valores validos de los campos cerrados y lista de acciones admitidas (lo que el agente necesita para proponer sin equivocarse). */
    private function catalogos(): void
    {
        $datos = [
            'estados_de_gasto' => array_map(fn (ExpenseStatus $e): array => ['valor' => $e->value, 'etiqueta' => $e->getLabel()], ExpenseStatus::cases()),
            'tipos_de_linea' => array_map(fn (ExpenseItemType $e): array => ['valor' => $e->value, 'etiqueta' => $e->getLabel()], ExpenseItemType::cases()),
            'recurrencias' => array_map(fn (Recurrence $e): array => ['valor' => $e->value, 'etiqueta' => $e->getLabel()], Recurrence::cases()),
            'moneda' => 'EUR',
            'acciones_admitidas' => app(AccionesChat::class)->tipos(),
            'tope_acciones_por_turno' => (int) config('chat_claude.acciones.max_por_turno'),
            'tope_lineas_por_gasto' => (int) config('chat_claude.acciones.max_lineas_por_gasto'),
            'tope_lineas_en_bloque' => (int) config('chat_claude.acciones.max_lineas_masivo'),
            'dias_atras_maximos_para_crear_un_gasto' => (int) config('chat_claude.acciones.dias_atras'),
            'minutos_para_confirmar' => (int) config('chat_claude.acciones.caduca_min'),
            'ocr_disponible' => app(LanzaOcr::class)->disponible(),
        ];
        $this->escribir('catalogos.json', (string) json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false);
        $this->indice[] = ['archivo' => 'catalogos.json', 'descripcion' => 'Valores válidos de los campos cerrados (estados, tipos de línea, recurrencias) y lista de acciones admitidas.',
            'formato' => 'json', 'filas' => 1, 'filas_totales' => 1, 'truncada' => false, 'columnas' => [], 'relaciones' => []];
    }

    // ------------------------------------------------------------------ conversacion y fotos

    private function conversacion(ChatMensaje $pregunta, ChatMensaje $respuesta): void
    {
        $mensajes = ChatMensaje::query()->where('sequence', '<', $respuesta->sequence)
            ->where(fn (Builder $q) => $q->where('from_service', false)->orWhere('estado', EstadoChat::Completado->value))
            ->orderByDesc('sequence')->limit((int) $this->limite('historial_mensajes'))->get();   // lo mas reciente primero: la pregunta, la primera
        $fotosRestantes = (int) $this->limite('max_fotos');
        $bytesRestantes = (int) $this->limite('max_bytes_fotos');
        $copiadas = [];
        $filas = [];
        foreach ($mensajes as $m) {
            $fotos = [];
            foreach ($m->imagenes() as $parte) {
                $hash = (string) ($parte['hash'] ?? '');
                if ($m->from_service || Archivos::normalizarHash($hash) === null) {
                    continue;   // solo las fotos de la persona
                }
                $archivo = $copiadas[$hash] ?? null;
                $motivo = null;
                if ($archivo === null) {
                    $f = app(Archivos::class)->buscar($hash);
                    if (! $f) {
                        $motivo = 'no disponible';
                    } elseif ($fotosRestantes <= 0 || $f->tamano > $bytesRestantes) {
                        $motivo = 'omitida por tamaño o por haber demasiadas fotos';
                    } else {
                        $archivo = 'fotos/'.substr($hash, 0, 16).($f->mime === 'image/png' ? '.png' : '.jpg');
                        $this->escribir($archivo, $f->bytes(), false);
                        $copiadas[$hash] = $archivo;
                        $fotosRestantes--;
                        $bytesRestantes -= (int) $f->tamano;
                    }
                }
                $fotos[] = ['foto_hash' => $hash, 'archivo' => $archivo, 'mime' => $parte['mime'] ?? null, 'nota' => $motivo];
            }
            $texto = $this->textoDelMensaje($m);
            $filas[] = [
                'sequence' => $m->sequence, 'de' => $m->from_service ? 'asistente' : 'persona', 'hora' => gmdate('c', intdiv((int) $m->created_ms, 1000)),
                'texto' => mb_strlen($texto) > $this->limite('max_texto_mensaje') ? mb_substr($texto, 0, (int) $this->limite('max_texto_mensaje')).'… [recortado]' : $texto,
                'fotos' => $fotos, 'es_la_pregunta' => $m->id === $pregunta->id,
            ];
        }
        $filas = array_reverse($filas);   // orden cronologico
        $this->escribir('conversacion.json', (string) json_encode(['pregunta_sequence' => $pregunta->sequence, 'mensajes' => $filas],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false);
        $this->indice[] = ['archivo' => 'conversacion.json', 'descripcion' => 'Los últimos mensajes de la conversación (la pregunta de ahora es el que tiene es_la_pregunta). Las fotos están como archivos de imagen en fotos/: léelas con Read.',
            'formato' => 'json', 'filas' => count($filas), 'filas_totales' => count($filas), 'truncada' => false, 'columnas' => [
                'sequence' => 'orden del mensaje', 'de' => 'persona o asistente', 'hora' => 'ISO 8601 UTC', 'texto' => 'texto del mensaje (los widgets y formularios de asistente salen como el texto que se envió)',
                'fotos' => 'fotos de la persona: foto_hash (el que se usa en crear_gasto y adjuntar_foto), archivo (ruta en fotos/, o null) y nota si no se copió',
            ], 'relaciones' => []];
    }

    private function textoDelMensaje(ChatMensaje $m): string
    {
        // Las partes peticion_secreto no son de texto: no salen. Los tokens de confirmacion de acciones pendientes tampoco: son de Laravel.
        return ConfirmacionAcciones::ocultarTokens($m->texto());
    }

    // ------------------------------------------------------------------ indice

    /** @return array<string, mixed> */
    private function leeme(ChatMensaje $respuesta): array
    {
        $hoy = now();

        return [
            'formato' => 'instantanea-chat', 'version' => 1, 'turno' => $respuesta->id,
            'generada' => $hoy->toIso8601String(), 'hoy' => $hoy->toDateString(), 'dia_de_la_semana' => (int) $hoy->dayOfWeekIso,
            'aviso' => 'Instantánea de SOLO LECTURA tomada justo antes de este turno. Cualquier cambio que escribas aquí se pierde y no llega a la base. '
                .'Para cambiar datos propón acciones en workspace/chat-acciones-'.$respuesta->id.'.json (ver la skill chat-finanzas).',
            'limites' => [
                'tamano_total_datos_bytes' => (int) $this->limite('max_bytes_datos'), 'tamano_maximo_archivo_bytes' => (int) $this->limite('max_bytes_archivo'),
                'dias_de_gastos_recientes' => (int) $this->limite('dias_gastos'), 'max_gastos_recientes' => (int) $this->limite('max_gastos'),
                'max_gastos_pendientes' => (int) $this->limite('max_pendientes'), 'max_lineas' => (int) $this->limite('max_lineas'),
                'historial_mensajes' => (int) $this->limite('historial_mensajes'), 'fotos' => (int) $this->limite('max_fotos'),
                'nota' => 'Si un archivo no cabe se quitan sus filas más antiguas y su entrada dice truncada: true. Lo que no está aquí no se conoce: no lo inventes.',
            ],
            'archivos' => $this->indice,
            'fotos' => array_values(array_filter(array_keys($this->archivos), fn (string $r): bool => str_starts_with($r, 'fotos/'))),
            'excluido' => self::EXCLUIDO,
        ];
    }
}
