<?php

namespace App\Services\Chat\Bot;

use App\Models\Ajuste;
use App\Models\ChatArchivo;
use App\Models\ChatMensaje;
use App\Models\ChatPeticionSecreto;
use App\Services\Acciones\GestionGastos;
use App\Services\Acciones\ResumenGastos;
use App\Services\Chat\Marcado\MarcadoInvalido;
use App\Services\Chat\Marcado\Mensaje;
use App\Services\Chat\Marcado\Reglas;
use App\Services\Chat\Marcado\Widget;
use App\Services\Chat\Respondedor;
use App\Services\Chat\Secretos;
use App\Services\Menu\TokenClaude;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * El motor de respuesta sin IA: reconoce intenciones en español (Interprete), consulta los datos de la app y contesta con texto,
 * widgets (bloques html) y formularios (bloques keyboard) hechos con las clases de Marcado. Responde completo en un solo paso.
 *
 * Sabe: guardar un ticket que llega como foto (crea un gasto pendiente de revisar y lanza el OCR si esta disponible), decir cuanto se
 * ha gastado (hoy, ayer, semana, mes, mes pasado, año; con desglose por categoria) y listar lo pendiente de revisar. Todo lo demas
 * (crear o corregir gastos, categorizar, analizar...) es del modo Claude.
 *
 * Estado que necesita entre mensajes: nada en memoria; «ya pedí el token de Claude» se lee de chat_peticiones_secreto.
 */
class RespondedorBot implements Respondedor
{
    public function responder(ChatMensaje $pregunta, ChatMensaje $respuesta): void
    {
        $contestacion = $this->contestar($pregunta);

        if ($contestacion->metadata !== []) {
            // Antes de escribir: el mensaje sigue abierto y solo cambia esta columna.
            $respuesta->update(['metadata' => $contestacion->metadata]);
        }
        if ($contestacion->enlace !== null) {
            $respuesta->enlazarSlot($contestacion->texto, $contestacion->enlace['puerto'], $contestacion->enlace['etiqueta']);

            return;
        }
        if (! $respuesta->anadirTexto($contestacion->texto)) {
            return;
        }
        if ($contestacion->secreto !== null) {
            $respuesta->pedirSecreto($contestacion->secreto['request_id'], $contestacion->secreto['nombre'], $contestacion->secreto['prosa']);

            return;
        }
        $respuesta->finalizar($contestacion->sugerencias);
    }

    public function contestar(ChatMensaje $pregunta): Respuesta
    {
        $intencion = Interprete::interpretar($pregunta->texto(), $pregunta->imagenes() !== []);

        return match ($intencion->tipo) {
            Intencion::SALUDO => new Respuesta($this->acciones('¡Hola! Soy el asistente de tus gastos. ¿Qué quieres hacer?')),
            Intencion::GRACIAS => new Respuesta('¡De nada! Aquí estoy para lo que necesites.', ['Gasto del mes', 'Pendiente de revisar']),
            Intencion::ADIOS => new Respuesta('¡Hasta luego! Cuando quieras, aquí me tienes.'),
            Intencion::AYUDA => $this->ayuda(),
            Intencion::GASTO => $this->gasto((string) $intencion->datos['periodo'], (bool) $intencion->datos['por_categoria']),
            Intencion::PENDIENTES => $this->pendientes(),
            Intencion::REVISAR => $this->revisar(),
            Intencion::REGISTRAR => $this->registrar($pregunta),
            Intencion::IA => $this->ia(),
            default => $this->noEntiendo(),
        };
    }

    // ------------------------------------------------------------------ piezas comunes

    /** @param  list<string>  $sugerencias  @return list<string> */
    private function sugerencias(array $sugerencias): array
    {
        return array_slice(array_map(fn (string $s): string => Reglas::cortar($s, 64), $sugerencias), 0, (int) config('chat.max_sugerencias', 3));
    }

    private function acciones(string $introduccion): string
    {
        return Mensaje::nuevo()->texto($introduccion)->teclado(Formularios::acciones())->render();
    }

    private function ayuda(): Respuesta
    {
        $texto = "Esto es lo que sé hacer, sin necesidad de inteligencia artificial:\n\n"
            ."- **Guardar un ticket**: mándame la foto del ticket y creo el gasto, pendiente de revisar (y lanzo el OCR si está disponible).\n"
            ."- **Cuánto has gastado**: «cuánto he gastado hoy», «gasto de la semana», «gasto del mes», «gasto por categoría», «gasto del mes pasado».\n"
            ."- **Pendiente de revisar**: «qué hay pendiente de revisar».\n"
            ."- **Revisar tickets**: «revisar tickets» te abre los gastos pendientes como tarjetas.\n\n"
            .'Para corregir o crear gastos con palabras, categorizar, analizar o recibir consejos hace falta conectar Claude.';

        return new Respuesta(Mensaje::nuevo()->texto($texto)->teclado(Formularios::acciones())->render());
    }

    private function noEntiendo(): Respuesta
    {
        return new Respuesta(
            $this->acciones('No te he entendido bien. Puedo decirte cuánto has gastado, qué hay pendiente de revisar o guardar la foto de un ticket. Elige una opción o escríbemelo de otra forma.'),
            $this->sugerencias(['Gasto del mes', 'Pendiente de revisar', 'Ayuda']),
        );
    }

    /** Un widget como texto; si no cabe (8000 caracteres) o no es válido, el aviso que se da en su lugar. */
    private function mostrar(Widget $widget, string $siNoCabe): string
    {
        try {
            return $widget->render();
        } catch (MarcadoInvalido) {
            return Mensaje::neutralizar($siNoCabe);
        }
    }

    // ------------------------------------------------------------------ gasto

    private function gasto(string $clave, bool $porCategoria): Respuesta
    {
        $resumen = app(ResumenGastos::class);
        $periodo = $resumen->periodo($clave);
        $total = $resumen->total($periodo['desde'], $periodo['hasta']);
        $categorias = $porCategoria ? $resumen->porCategoria($periodo['desde'], $periodo['hasta']) : [];
        $widget = $this->mostrar(Vistas::gasto($periodo['etiqueta'], $total, $categorias, $porCategoria), 'El resumen es demasiado largo para mostrarlo. Mira el detalle en el panel (Análisis de gastos).');

        $texto = $total['gastos'] === 0
            ? 'No hay gastos pagados '.$periodo['etiqueta'].'.'
            : 'Esto es lo que has gastado '.$periodo['etiqueta'].':';
        $siguientes = $porCategoria ? ['Gasto del mes', 'Pendiente de revisar'] : ['Gasto por categoría', 'Pendiente de revisar'];

        return new Respuesta(Mensaje::nuevo()->texto($texto)->render()."\n\n".$widget, $this->sugerencias($siguientes));
    }

    // ------------------------------------------------------------------ pendientes

    private function pendientes(): Respuesta
    {
        $resumen = app(ResumenGastos::class);
        $total = $resumen->numeroPendientes();
        $widget = $this->mostrar(Vistas::pendientes($resumen->pendientes(10), $total), 'La lista es demasiado larga para mostrarla. Revísala en el panel (Gastos).');
        $texto = $total === 0 ? 'No tienes nada pendiente de revisar.' : "Tienes {$total} gasto(s) pendiente(s) de revisar:";

        return new Respuesta(Mensaje::nuevo()->texto($texto)->render()."\n\n".$widget, $this->sugerencias(['Revisar tickets', 'Gasto del mes']));
    }

    // ------------------------------------------------------------------ revisar

    /** El enlace al slot «cards» con el mazo de gastos pendientes (chat.md §5.8). El mazo se genera cuando la pantalla lo pide. */
    private function revisar(): Respuesta
    {
        $total = app(ResumenGastos::class)->numeroPendientes();
        if ($total === 0) {
            return new Respuesta('No tienes ningún gasto pendiente de revisar.', $this->sugerencias(['Gasto del mes', 'Registrar un ticket']));
        }

        return new Respuesta(
            "Tienes {$total} gasto(s) pendiente(s) de revisar. Ábrelos como tarjetas: desliza a la derecha para darlos por revisados o a la izquierda para dejarlos para luego.",
            enlace: ['puerto' => (int) config('cards.puerto_slot'), 'etiqueta' => 'Revisar tickets'],
        );
    }

    // ------------------------------------------------------------------ registrar

    private function registrar(ChatMensaje $pregunta): Respuesta
    {
        $hashes = array_values(array_unique(array_filter(array_map(fn (array $p): string => (string) ($p['hash'] ?? ''), $pregunta->imagenes()))));
        $hashes = array_values(array_filter($hashes, fn (string $h): bool => ChatArchivo::query()->where('hash', $h)->exists()));

        if ($hashes === []) {
            return new Respuesta(
                'Mándame la foto del ticket (puedes sacarla con la cámara) y lo guardo como un gasto pendiente de revisar. Si quieres apuntar un gasto con palabras, necesito que Claude esté conectado.',
                $this->sugerencias(['Gasto del mes', 'Pendiente de revisar']),
            );
        }

        $gastos = app(GestionGastos::class);
        $resultados = [];
        $fallos = 0;
        foreach (array_slice($hashes, 0, (int) config('chat.max_archivos', 4)) as $hash) {
            try {
                $resultados[] = $gastos->crearDesdeFotoDeChat($hash);
            } catch (InvalidArgumentException) {
                $fallos++;
            }
        }
        if ($resultados === []) {
            return new Respuesta('No he podido guardar la foto del ticket. Inténtalo de nuevo.', $this->sugerencias(['Ayuda']));
        }

        $widget = $this->mostrar(Vistas::tickets($resultados), 'Ticket guardado.');
        $texto = 'Hecho, lo he guardado'.(count($resultados) > 1 ? ' ('.count($resultados).' tickets)' : '').'.'.($fallos > 0 ? " {$fallos} foto(s) no se han podido guardar." : '');

        return new Respuesta(Mensaje::nuevo()->texto($texto)->render()."\n\n".$widget, $this->sugerencias(['Pendiente de revisar', 'Gasto del mes']));
    }

    // ------------------------------------------------------------------ lo que requiere IA

    private function ia(): Respuesta
    {
        $alternativas = $this->acciones('Mientras tanto puedo decirte cuánto has gastado, qué hay pendiente de revisar o guardar un ticket.');

        // Desactivada a proposito (aunque haya token): no se ofrece ni se pide nada.
        if (! Ajuste::claudeChatHabilitado()) {
            return new Respuesta(
                Mensaje::nuevo()->texto('La inteligencia artificial está desactivada en los ajustes del servicio (Chat con Claude), así que respondo sin ella.')->render()."\n\n".$alternativas,
                $this->sugerencias(['Gasto del mes', 'Pendiente de revisar']),
            );
        }

        if (TokenClaude::existe()) {
            return new Respuesta(
                Mensaje::nuevo()->texto('Eso necesita el asistente con inteligencia artificial y ahora mismo no está disponible. Inténtalo de nuevo dentro de un rato.')->render()."\n\n".$alternativas,
                $this->sugerencias(['Gasto del mes', 'Pendiente de revisar']),
            );
        }

        $previa = ChatPeticionSecreto::query()->where('nombre', Secretos::NOMBRE_TOKEN_CLAUDE)->orderByDesc('id')->first();
        if ($previa === null) {
            return new Respuesta(
                'Para eso necesito la inteligencia artificial de Claude (corregir o crear gastos, categorizar, análisis libres, consejos para ahorrar...), y todavía no está conectada. '
                .'Si quieres activarla, pégame ahora tu token de Claude. Si no, sigo respondiendo sin IA: gasto por periodo, pendientes y tickets.',
                [],
                [],
                [
                    'request_id' => 'claude-'.Str::lower(Str::random(16)),
                    'nombre' => Secretos::NOMBRE_TOKEN_CLAUDE,
                    'prosa' => 'Pega aquí tu token de Claude (se genera con «claude setup-token»). Se guarda cifrado en esta instancia y no queda en la conversación. Puedes omitirlo.',
                ],
            );
        }

        $texto = $previa->estado === ChatPeticionSecreto::PENDIENTE
            ? 'Para eso necesito Claude. Antes te pedí el token y sigue pendiente: cuando lo pegues podré responder.'
            : 'Para eso hace falta la inteligencia artificial de Claude y no está conectada. Si cambias de idea, pega el token en el panel de administración (Conexión con Claude) y podré ayudarte.';

        return new Respuesta(Mensaje::nuevo()->texto($texto)->render()."\n\n".$alternativas, $this->sugerencias(['Gasto del mes', 'Pendiente de revisar']));
    }
}
