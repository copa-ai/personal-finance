<?php

namespace App\Console\Commands;

use App\Services\Investments\MarketPriceUpdater;
use App\Services\MarketData\MarketDataClient;
use App\Services\MarketData\MarketDataUnavailable;
use Illuminate\Console\Command;

class MarketRefresh extends Command
{
    protected $signature = 'market:refresh {--fx : Solo tipos de cambio del BCE} {--prices : Solo precios de instrumentos}';

    protected $description = 'Actualiza precios de los instrumentos con símbolo de mercado y los tipos de cambio del BCE.';

    public function handle(MarketDataClient $client, MarketPriceUpdater $updater): int
    {
        if (! $client->enabled()) {
            $this->warn('MARKET_DATA_URL no está configurado: los precios se apuntan a mano.');

            return self::SUCCESS;
        }

        $both = ! $this->option('fx') && ! $this->option('prices');

        try {
            if ($both || $this->option('fx')) {
                $this->info(sprintf('Tipos de cambio actualizados: %d.', $updater->refreshFx()));
            }

            if ($both || $this->option('prices')) {
                $result = $updater->refreshPrices();
                $this->info(sprintf('Precios actualizados: %d.', $result['updated']));

                foreach ($result['errors'] as $error) {
                    $this->warn($error);
                }
            }
        } catch (MarketDataUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
