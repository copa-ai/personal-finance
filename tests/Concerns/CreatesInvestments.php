<?php

namespace Tests\Concerns;

use App\Enums\AssetClass;
use App\Enums\InstrumentType;
use App\Enums\MarketProvider;
use App\Enums\InvestmentTransactionType;
use App\Enums\PlatformType;
use App\Models\Instrument;
use App\Models\InvestmentPlatform;
use App\Models\InvestmentTransaction;
use App\Models\PriceBar;
use App\Services\Market\FxService;

trait CreatesInvestments
{
    protected function platform(string $name = 'MyInvestor', string $country = 'ES', PlatformType $type = PlatformType::BROKER): InvestmentPlatform
    {
        return InvestmentPlatform::query()->create(['name' => $name, 'type' => $type, 'country' => $country]);
    }

    protected function instrument(string $name = 'MSCI World', array $attributes = []): Instrument
    {
        return Instrument::query()->create([
            'name' => $name,
            'type' => InstrumentType::ETF,
            'provider' => MarketProvider::MANUAL,
            'active' => false,
            'asset_class' => AssetClass::EQUITY,
            'currency' => 'EUR',
            ...$attributes,
        ]);
    }

    protected function tx(
        InvestmentPlatform $platform,
        Instrument $instrument,
        InvestmentTransactionType $type,
        string $date,
        string|int|float $quantity = 0,
        string|int|float|null $price = null,
        array $attributes = [],
    ): InvestmentTransaction {
        return InvestmentTransaction::query()->create([
            'platform_id' => $platform->id,
            'instrument_id' => $instrument->id,
            'type' => $type,
            'date' => $date,
            'quantity' => $quantity,
            'price' => $price,
            'currency' => $instrument->currency,
            ...$attributes,
        ]);
    }

    /** Precio apuntado a mano: una vela con open = high = low = close. */
    protected function price(Instrument $instrument, string $date, string|int|float $price): PriceBar
    {
        return PriceBar::query()->create([
            'instrument_id' => $instrument->id,
            'date' => $date,
            'open' => $price,
            'high' => $price,
            'low' => $price,
            'close' => $price,
        ]);
    }

    /** Cierre del par EUR/{divisa} (unidades de la divisa por 1 EUR). */
    protected function fx(string $currency, string $date, string|int|float $unitsPerEur): PriceBar
    {
        return $this->price(app(FxService::class)->ensurePair($currency), $date, $unitsPerEur);
    }
}
