<?php

return [
    // Días sin precio a partir de los cuales una posición pide actualizar su valoración.
    'stale_price_days' => (int) env('INVESTMENTS_STALE_PRICE_DAYS', 30),

    // Antelación del aviso de vencimiento de depósitos y bonos.
    'maturity_notice_days' => (int) env('INVESTMENTS_MATURITY_NOTICE_DAYS', 15),

    // Desviación (puntos porcentuales) respecto a la asignación objetivo que dispara el aviso.
    'rebalance_threshold_pp' => (float) env('INVESTMENTS_REBALANCE_THRESHOLD_PP', 5),

    // Horas mínimas entre dos disparos de la misma alerta de precio.
    'price_alert_cooldown_hours' => (int) env('INVESTMENTS_PRICE_ALERT_COOLDOWN_HOURS', 24),

    // Además del panel, enviar los avisos a ntfy a través de pf-market-data (MD_NTFY_URL).
    'ntfy' => (bool) env('INVESTMENTS_NTFY', false),
];
