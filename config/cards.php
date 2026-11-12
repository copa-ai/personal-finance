<?php

/*
 * Revision de gastos con la interfaz «cards» de chatui (cards.md, revision 1). El traductor gRPC de docker/chat/ (segundo puerto del
 * mismo proceso) habla con Laravel por HTTP interno bajo /interno/cards, con el mismo Bearer que el chat. Los limites de tarjetas y
 * textos son los de cards.md §8.
 */
return [
    // Puerto del slot «cards» en service.json -> api. Es el que lleva el slot_link del chat (chat.md §5.8): el cliente lo busca entre los slots.
    'puerto_slot' => (int) env('CARDS_PUERTO', 50052),

    'max_tarjetas' => 500,
    'max_titulo_bytes' => 256,
    'max_texto_bytes' => 1024,
    'max_detalle_bytes' => 16384,
    'max_grupo_bytes' => 64,
    'max_bytes_mazo' => 700000,          // un DeckEvent no pasa de 1 MiB: si el mazo no cabe, se acortan los detalles (nunca se parte)

    'max_eventos_leidos' => 200,         // por sondeo; el traductor vuelve a pedir el mazo entero si se queda muy atras
    'eventos_conservados' => 500,
    'dias_decisiones' => 30,             // las decisiones y claves de mazos antiguos se borran pasado este tiempo
    // Segundos de inactividad tras los que el mazo ya no se reanuda al reconectar: se genera uno nuevo con lo pendiente de ese momento.
    'reanudar_s' => 300,

    // Fotos de ticket: solo se muestran las PNG o JPEG que quepan en este tamano (cards.md exige esos dos tipos).
    'max_imagen_bytes' => 4194304,
    'max_lineas_detalle' => 40,
];
