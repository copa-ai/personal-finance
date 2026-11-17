<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class PriceBar extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'instrument_id',
        'date',
        'open',
        'high',
        'low',
        'close',
        'volume',
    ];

    protected $casts = [
        'date' => 'date',
        'open' => 'decimal:8',
        'high' => 'decimal:8',
        'low' => 'decimal:8',
        'close' => 'decimal:8',
        'volume' => 'decimal:8',
    ];

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class, 'instrument_id');
    }

    /**
     * Última vela en o antes de $date de cada instrumento.
     *
     * @param  iterable<string>  $instrumentIds
     * @return Collection<string, self>
     */
    public static function latestFor(iterable $instrumentIds, string $date): Collection
    {
        $ids = collect($instrumentIds)->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return self::query()
            ->fromRaw('(
                SELECT DISTINCT ON (instrument_id) *
                FROM price_bars
                WHERE instrument_id IN ('.$ids->map(fn () => '?')->implode(',').') AND date <= ?
                ORDER BY instrument_id, date DESC
            ) AS price_bars', [...$ids->all(), $date])
            ->get()
            ->keyBy('instrument_id');
    }
}
