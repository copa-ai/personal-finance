<?php

namespace App\Models;

use App\Enums\AssetClass;
use App\Enums\InstrumentKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Instrument extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'kind',
        'asset_class',
        'isin',
        'ticker',
        'currency',
        'market_symbol',
        'maturity_date',
        'notes',
    ];

    protected $casts = [
        'kind' => InstrumentKind::class,
        'asset_class' => AssetClass::class,
        'maturity_date' => 'date',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(InvestmentTransaction::class, 'instrument_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(InstrumentPrice::class, 'instrument_id');
    }

    /**
     * Último precio conocido. A igual fecha manda el manual sobre el de mercado.
     */
    public function latestPrice(): ?InstrumentPrice
    {
        return InstrumentPrice::latestFor([$this->id])->get($this->id);
    }

    protected function marketSymbol(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        // Mismo formato que pf-market-data: tipo en minúsculas, código en mayúsculas.
        return \Illuminate\Database\Eloquent\Casts\Attribute::set(
            fn (?string $value): ?string => blank($value) ? null : (\App\Services\MarketData\MarketSymbol::normalize($value) ?? trim($value)),
        );
    }
}
