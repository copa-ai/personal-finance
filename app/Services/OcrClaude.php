<?php

namespace App\Services;

use App\Models\AgenteArchivo;
use App\Models\Category;
use App\Services\Agente\AlmacenAgente;
use App\Services\Chat\RespondedorMixto;
use App\Services\Claude\EjecutorClaude;
use App\Services\Claude\LimiteUso;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * OCR de tickets a traves de Claude: la foto se deja en agente/encargos/ocr-{id}/ y un turno de `claude -p` (skill ocr-ticket) la lee
 * con la herramienta Read y responde con el mismo JSON que un servicio OCR (`establishment`, `category`, `items`), de modo que OcrService
 * lo trata igual. El agente no escribe nada: su respuesta es el resultado, y Laravel la valida y la importa.
 *
 * Disponible con las mismas condiciones que el chat con Claude: ajuste «Chat con Claude» activo y token puesto (RespondedorMixto::claudeActivo()).
 */
class OcrClaude
{
    /** Marca fija con la que empieza el prompt; agente/CLAUDE.md la usa para distinguir un encargo de OCR de un turno de chat. */
    public const MARCA = 'Encargo de OCR';

    /** Formatos que la herramienta Read de Claude sabe leer. */
    private const EXTENSIONES = [
        'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf',
    ];

    public function __construct(private readonly EjecutorClaude $ejecutor)
    {
    }

    public function disponible(): bool
    {
        return RespondedorMixto::claudeActivo();
    }

    /** Para dejar constancia en el OcrJob de quien hizo el OCR. */
    public function etiqueta(): string
    {
        return 'Claude ('.config('agente.modelo').')';
    }

    public static function prompt(string $id, string $relativa, array $categorias): string
    {
        $existentes = $categorias === [] ? 'Todavía no hay ninguna.' : implode(', ', $categorias).'.';

        return self::MARCA." {$id}. Usa la skill ocr-ticket: la foto del ticket está en {$relativa}. Léela con la herramienta Read y responde "
            .'SOLO con el JSON del catálogo de la skill, sin texto antes ni después y sin bloque de código. '
            ."Categorías que ya existen (reutiliza una si encaja): {$existentes} No hagas preguntas bloqueantes.";
    }

    /**
     * @param  string  $ticket  ruta del ticket en el disco local (la de Expense::$ticket_photo_hash)
     * @return array<string, mixed> el JSON decodificado, con el contrato de docs/ocr-service.md
     *
     * @throws RuntimeException si no se puede leer el ticket, el agente falla o su respuesta no es un JSON
     */
    public function extraer(string $ticket, string $id): array
    {
        $disco = Storage::disk('local');
        $extension = self::EXTENSIONES[strtolower((string) $disco->mimeType($ticket))] ?? null;
        if ($extension === null) {
            throw new RuntimeException('Claude solo lee tickets en JPG, PNG, WebP, GIF o PDF; este es de otro formato ('.($disco->mimeType($ticket) ?: 'desconocido').').');
        }

        $relativa = "encargos/ocr-{$id}/ticket.{$extension}";
        $this->dejarTicket($relativa, $disco->get($ticket));

        try {
            $categorias = Category::query()->orderBy('name')->limit(50)->pluck('name')->all();
            $resultado = $this->lanzar(self::prompt($id, $relativa, $categorias));
        } finally {
            $this->limpiar($id);
        }

        if (! $resultado->exitoso()) {
            if (($renovacion = LimiteUso::renovacion($resultado->texto())) !== null) {
                throw new RuntimeException('Claude alcanzó el límite de uso de su plan; se renueva sobre las '.$renovacion->format('H:i').'.');
            }
            throw new RuntimeException('Claude no pudo leer el ticket (código '.$resultado->codigo.'): '.mb_substr($resultado->texto(), 0, 300));
        }

        return $this->decodificar($resultado->salida);
    }

    /** Lanza el turno; si el agente esta ocupado (429, AGENTE_MAX_SIMULTANEAS) espera y reintenta hasta el tope del chat. */
    private function lanzar(string $prompt): \App\Services\Claude\ResultadoClaude
    {
        $hasta = microtime(true) + max(0, (int) config('chat_claude.espera_agente_max_s'));
        $cada = max(0, (int) config('chat_claude.espera_agente_cada_s'));
        while (true) {
            try {
                // stream: con agente remoto, solo asi el runner recibe los archivos de entrada (la foto).
                return $this->ejecutor->ejecutar($prompt, 'Read', (float) config('agente.ocr_timeout'), null, null, null, true);
            } catch (RuntimeException $e) {
                if (! str_contains($e->getMessage(), '(429)') || microtime(true) >= $hasta) {
                    throw $e;
                }
                $cada > 0 && sleep($cada);
            }
        }
    }

    /** Extrae el objeto JSON de la respuesta aunque Claude lo envuelva en un bloque de codigo o le ponga texto alrededor. */
    public function decodificar(string $texto): array
    {
        $inicio = strpos($texto, '{');
        $fin = strrpos($texto, '}');
        $json = $inicio !== false && $fin !== false && $fin > $inicio ? json_decode(substr($texto, $inicio, $fin - $inicio + 1), true) : null;

        if (! is_array($json)) {
            throw new RuntimeException('La respuesta de Claude no es un JSON válido: '.mb_substr(trim($texto), 0, 200));
        }

        return $json;
    }

    private function dejarTicket(string $relativa, string $contenido): void
    {
        $destino = AlmacenAgente::carpeta().'/'.$relativa;
        if (! is_dir(dirname($destino)) && ! @mkdir(dirname($destino), 0775, true) && ! is_dir(dirname($destino))) {
            throw new RuntimeException('No se pudo crear la carpeta '.dirname($destino));
        }
        file_put_contents($destino, $contenido);
        // Con almacen en base, el runner no ve esta carpeta: EjecutorClaude le manda encargos/ desde la tabla agente_archivos.
        AlmacenAgente::activo() && AlmacenAgente::guardar($relativa, $contenido);
    }

    /** Borra la foto del encargo (disco y base del agente): no se queda ningun ticket en el agente despues del turno. */
    private function limpiar(string $id): void
    {
        $carpeta = AlmacenAgente::carpeta()."/encargos/ocr-{$id}";
        foreach (glob($carpeta.'/*') ?: [] as $archivo) {
            @unlink($archivo);
        }
        @rmdir($carpeta);
        AlmacenAgente::activo() && AgenteArchivo::query()->where('ruta', 'like', "encargos/ocr-{$id}/%")->delete();
    }
}
