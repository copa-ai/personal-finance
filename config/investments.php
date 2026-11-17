<?php

return [
    // Días sin precio a partir de los cuales una posición pide actualizar su valoración.
    'stale_price_days' => (int) env('INVESTMENTS_STALE_PRICE_DAYS', 30),

    // Antelación del aviso de vencimiento de depósitos y bonos.
    'maturity_notice_days' => (int) env('INVESTMENTS_MATURITY_NOTICE_DAYS', 15),

    // Desviación (puntos porcentuales) respecto a la asignación objetivo que dispara el aviso.
    'rebalance_threshold_pp' => (float) env('INVESTMENTS_REBALANCE_THRESHOLD_PP', 5),

    // Además del panel, enviar los avisos a un tema de ntfy (INVESTMENTS_NTFY_URL, p. ej. https://ntfy.sh/mi-tema).
    'ntfy' => (bool) env('INVESTMENTS_NTFY', false),
    'ntfy_url' => env('INVESTMENTS_NTFY_URL'),
    'ntfy_timeout' => (int) env('INVESTMENTS_NTFY_TIMEOUT', 10),
];
