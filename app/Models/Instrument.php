<?php

namespace App\Models;

use App\Enums\InstrumentType;
use App\Enums\MarketProvider;
use App\Services\Market\FxService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Instrument extends Model
{
    use HasUuids;

    protected $fillable = [
        'symbol',
        'isin',
        'name',
        'type',
        'provider',
        'provider_symbol',
        'currency',
        'active',
    ];

    protected $casts = [
        'type' => InstrumentType::class,
        'provider' => MarketProvider::class,
        'active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Al guardar un instrumento en divisa distinta del EUR se asegura su par de cambio para valorarlo.
        static::saved(function (Instrument $instrument): void {
            $currency = strtoupper((string) $instrument->currency);

            if ($instrument->type !== InstrumentType::FX && $currency !== 'EUR' && preg_match('/^[A-Z]{3}$/', $currency) === 1) {
                app(FxService::class)->ensurePair($currency);
            }
        });
    }

    public function priceBars(): HasMany
    {
        return $this->hasMany(PriceBar::class, 'instrument_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'instrument_id');
    }

    public function cryptoAccounts(): HasMany
    {
        return $this->hasMany(CryptoAccount::class, 'instrument_id');
    }
}
