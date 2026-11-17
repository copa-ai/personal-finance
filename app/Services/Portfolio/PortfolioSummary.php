<?php

namespace App\Services\Portfolio;

/**
 * Un solo cálculo de la cartera por petición: la página de cartera tiene varios
 * widgets y cada uno lo necesita.
 */
final class PortfolioSummary
{
    public static function current(): array
    {
        return once(fn () => app(PortfolioService::class)->summary());
    }
}
