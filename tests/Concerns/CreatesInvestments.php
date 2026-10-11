<?php

namespace Tests\Concerns;

use App\Enums\AssetClass;
use App\Enums\InstrumentKind;
use App\Enums\InvestmentTransactionType;
use App\Enums\PlatformType;
use App\Models\Instrument;
use App\Models\InstrumentPrice;
use App\Models\InvestmentPlatform;
use App\Models\InvestmentTransaction;

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
            'kind' => InstrumentKind::ETF,
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

    protected function price(Instrument $instrument, string $date, string|int|float $price, string $source = 'manual', ?string $currency = null): InstrumentPrice
    {
        return InstrumentPrice::query()->create([
            'instrument_id' => $instrument->id,
            'date' => $date,
            'price' => $price,
            'currency' => $currency ?? $instrument->currency,
            'source' => $source,
        ]);
    }
}
