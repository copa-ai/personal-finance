<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatchlistItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'watchlist_id',
        'market_symbol',
        'label',
        'position',
    ];

    public function watchlist(): BelongsTo
    {
        return $this->belongsTo(Watchlist::class);
    }

    protected function marketSymbol(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        // Mismo formato que pf-market-data: tipo en minúsculas, código en mayúsculas.
        return \Illuminate\Database\Eloquent\Casts\Attribute::set(
            fn (?string $value): ?string => blank($value) ? null : (\App\Services\MarketData\MarketSymbol::normalize($value) ?? trim($value)),
        );
    }
}
