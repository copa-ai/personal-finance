<?php

namespace App\Console\Commands;

use App\Services\Portfolio\PortfolioService;
use Illuminate\Console\Command;

class InvestmentsSnapshot extends Command
{
    protected $signature = 'investments:snapshot';

    protected $description = 'Guarda el valor de la cartera del día para la gráfica de evolución.';

    public function handle(PortfolioService $portfolio): int
    {
        $snapshot = $portfolio->takeSnapshot();
        $this->info(sprintf('Cartera el %s: %s € (aportado %s €).', $snapshot->date->toDateString(), $snapshot->value_eur, $snapshot->invested_eur));

        return self::SUCCESS;
    }
}
