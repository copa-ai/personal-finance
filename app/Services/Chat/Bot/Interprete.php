<?php

namespace App\Services\Chat\Bot;

/**
 * Entiende, sin IA y de forma determinista, lo que dice la persona. Solo mira el texto (y si trae fotos): no toca la base, así
 * que se prueba sin ella. El orden de las reglas importa y está en interpretar().
 */
final class Interprete
{
    /** Minúsculas, sin tildes, con todo lo que no sea letra, número, «/», «-» o «:» convertido en espacio. */
    public static function normalizar(string $texto): string
    {
        $texto = strtr(mb_strtolower($texto), ['á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'ú' => 'u', 'ù' => 'u', 'ü' => 'u',
            'û' => 'u', 'ñ' => 'n', 'ç' => 'c']);

        return trim((string) preg_replace('/[^a-z0-9\/:\-]+/', ' ', $texto));
    }

    /**
     * Mensajes (ya normalizados) que atiende siempre el bot basico aunque Claude este activo, porque no necesitan IA y Claude no
     * podria darlos: el enlace al slot «cards» para revisar tickets.
     */
    public static function soloBot(string $norm): bool
    {
        return self::pideRevisar($norm);
    }

    public static function interpretar(string $texto, bool $traeImagenes): Intencion
    {
        $norm = self::normalizar($texto);

        if ($traeImagenes) {
            return new Intencion(Intencion::REGISTRAR, ['con_imagenes' => true]);
        }
        if (self::pideRevisar($norm)) {
            return new Intencion(Intencion::REVISAR);
        }
        if (self::quiereRegistrar($norm)) {
            return new Intencion(Intencion::REGISTRAR, ['con_imagenes' => false]);
        }
        if (self::pideIa($norm)) {
            return new Intencion(Intencion::IA);
        }
        if (self::pidePendientes($norm)) {
            return new Intencion(Intencion::PENDIENTES);
        }
        if (self::pideGasto($norm)) {
            return new Intencion(Intencion::GASTO, ['periodo' => self::periodo($norm), 'por_categoria' => self::pideCategorias($norm)]);
        }
        if (preg_match('/^(gracias|muchas gracias|mil gracias|vale gracias|perfecto gracias|genial gracias)\b/', $norm) === 1) {
            return new Intencion(Intencion::GRACIAS);
        }
        if (preg_match('/^(adios|hasta luego|hasta manana|chao|chau|nos vemos)\b/', $norm) === 1) {
            return new Intencion(Intencion::ADIOS);
        }
        if (preg_match('/\b(ayuda|ayudame|help|que (puedes|sabes|haces) hacer|para que sirves|que puedo (preguntar|pedir|hacer)|comandos|opciones)\b/', $norm) === 1) {
            return new Intencion(Intencion::AYUDA);
        }
        if (preg_match('/^(hola|holi|buenas|buenos dias|buenas tardes|buenas noches|buen dia|hey|ey|ei|hello|saludos|que tal|como estas|empezar|inicio)\b/', $norm) === 1 && count(explode(' ', $norm)) <= 6) {
            return new Intencion(Intencion::SALUDO);
        }

        return new Intencion(Intencion::NO_ENTIENDO);
    }

    /** «revisar tickets», «quiero revisar los gastos pendientes», «repasar tickets»… (ya normalizado): abre el mazo de tarjetas. */
    public static function pideRevisar(string $norm): bool
    {
        if (count(explode(' ', $norm)) > 8) {
            return false;   // una frase larga es otra cosa (p. ej. una orden para Claude), no el atajo al mazo
        }

        return preg_match('/\b(revis\w*|repas\w*)\b.{0,30}\b(ticket|tiquet|gasto|recibo|factura|compra|pendiente)\w*/', $norm) === 1
            || preg_match('/\b(mazo|tarjetas)\b.{0,30}\b(gasto|ticket|revis)\w*/', $norm) === 1;
    }

    /** «registrar un gasto», «apunta este ticket», «quiero subir un ticket»… (ya normalizado). */
    private static function quiereRegistrar(string $norm): bool
    {
        return preg_match('/\b(registr\w*|anota\w*|apunta\w*|sub\w*|guarda\w*|anad\w*|agrega\w*|nuevo|nueva)\b.{0,30}\b(gasto|ticket|tiquet|recibo|factura|compra)s?\b/', $norm) === 1;
    }

    /** «qué hay pendiente de revisar», «tickets por revisar»… */
    private static function pidePendientes(string $norm): bool
    {
        return preg_match('/\b(pendientes?|por revisar|sin revisar|a revisar|que (tengo|debo|hay que|falta) (que )?revisar|revision)\b/', $norm) === 1;
    }

    /** «cuánto he gastado hoy», «gasto del mes», «en qué gasto»… */
    private static function pideGasto(string $norm): bool
    {
        return preg_match('/\b(cuanto|cuantos|cuanta)\b.{0,40}\b(gast\w*|llevo|pagad\w*|he pagado|costado)\b/', $norm) === 1
            || preg_match('/\b(gasto|gastos|gastado|gastamos|gaste|gasti\w*)\b/', $norm) === 1 && self::tienePeriodo($norm)
            || preg_match('/\b(gasto|gastos) (por|de cada|en cada) categoria\b|\bpor categorias?\b|\ben que (gasto|gastamos|he gastado|se va)\b/', $norm) === 1
            || preg_match('/^(gasto|gastos|resumen|resumen de gastos|resumen del mes)$/', $norm) === 1;
    }

    private static function tienePeriodo(string $norm): bool
    {
        return preg_match('/\b(hoy|ayer|semana|mes|mensual|anio|ano|este ano|enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre)\b/', $norm) === 1;
    }

    /** Periodo pedido: hoy, ayer, semana, mes, mes_pasado o anio (por defecto, el mes). */
    public static function periodo(string $norm): string
    {
        return match (true) {
            preg_match('/\b(mes pasado|ultimo mes|mes anterior)\b/', $norm) === 1 => 'mes_pasado',
            preg_match('/\b(hoy)\b/', $norm) === 1 => 'hoy',
            preg_match('/\b(ayer)\b/', $norm) === 1 => 'ayer',
            preg_match('/\b(semana|semanal)\b/', $norm) === 1 => 'semana',
            preg_match('/\b(este anio|este ano|anual|del anio|del ano|en el anio|en el ano)\b/', $norm) === 1 => 'anio',
            default => 'mes',
        };
    }

    private static function pideCategorias(string $norm): bool
    {
        return preg_match('/\b(categorias?|en que|desglose|detalle|por tipo|reparto)\b/', $norm) === 1;
    }

    private static function pideIa(string $norm): bool
    {
        return preg_match('/\b(ahorr\w*|presupuesto\w*|recomiend\w*|recomendacion\w*|aconsej\w*|consejo\w*|sugiere\w*|sugerencia\w*|analiz\w*|analisis|compar\w*|tendencia\w*|evolucion|prevision|predic\w*|por que|explica\w*|ideas?|evalua\w*|valora\w*|opinas|opinion|mejorar|reducir|recortar|cambia\w*|corrige\w*|edita\w*|borra\w*|elimina\w*|categoriza\w*|fusiona\w*|renombra\w*|crea\w*)\b/', $norm) === 1;
    }
}
