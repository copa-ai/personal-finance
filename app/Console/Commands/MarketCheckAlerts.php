<?php

namespace App\Console\Commands;

use App\Services\Investments\PriceAlertService;
use App\Services\MarketData\MarketDataClient;
use App\Services\MarketData\MarketDataUnavailable;
use Illuminate\Console\Command;

class MarketCheckAlerts extends Command
{
    protected $signature = 'market:check-alerts';

    protected $description = 'Comprueba las alertas de precio activas y avisa de las que se cumplen.';

    public function handle(MarketDataClient $client, PriceAlertService $alerts): int
    {
        if (! $client->enabled()) {
            return self::SUCCESS;
        }

        try {
            $this->info(sprintf('Alertas disparadas: %d.', $alerts->check()));
        } catch (MarketDataUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
