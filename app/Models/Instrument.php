<?php

namespace App\Models;

use App\Enums\AssetClass;
use App\Enums\InstrumentType;
use App\Enums\MarketProvider;
use App\Services\Market\FxService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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
        'asset_class',
        'maturity_date',
        'notes',
    ];

    protected $casts = [
        'type' => InstrumentType::class,
        'provider' => MarketProvider::class,
        'active' => 'boolean',
        'asset_class' => AssetClass::class,
        'maturity_date' => 'date',
    ];

    protected static function booted(): void
    {
        // Un instrumento manual no tiene símbolo de mercado: se le da uno propio y único a partir del nombre.
        static::creating(function (Instrument $instrument): void {
            if (blank($instrument->symbol)) {
                $instrument->symbol = static::manualSymbol((string) $instrument->name);
            }
        });

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

    public function transactions(): HasMany
    {
        return $this->hasMany(InvestmentTransaction::class, 'instrument_id');
    }

    public function isManual(): bool
    {
        return $this->provider === MarketProvider::MANUAL;
    }

    /**
     * Clase de activo del instrumento: la que se le fijó o, si no, la que corresponde a su tipo.
     */
    public function resolvedAssetClass(): AssetClass
    {
        return $this->asset_class ?? ($this->type?->defaultAssetClass() ?? AssetClass::OTHER);
    }

    /**
     * Símbolo único para un instrumento manual: el nombre en mayúsculas y sin acentos más un sufijo aleatorio.
     */
    public static function manualSymbol(string $name): string
    {
        $base = Str::upper(Str::limit(Str::slug($name, '-'), 28, ''));

        return ($base !== '' ? $base : 'MANUAL').'-'.Str::upper(Str::random(5));
    }
}
