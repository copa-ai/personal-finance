<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Watchlist extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'position',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(WatchlistItem::class)->orderBy('position');
    }
}
