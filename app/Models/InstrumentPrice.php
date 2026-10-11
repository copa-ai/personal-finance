<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class InstrumentPrice extends Model
{
    use HasUuids;

    public const SOURCE_MANUAL = 'manual';

    public $timestamps = false;

    protected $fillable = [
        'instrument_id',
        'date',
        'price',
        'currency',
        'source',
    ];

    protected $casts = [
        'date' => 'date',
        'price' => 'decimal:10',
        'created_at' => 'datetime',
    ];

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class, 'instrument_id');
    }

    /**
     * Último precio de cada instrumento: fecha más reciente; a igual fecha, el manual;
     * después, el último guardado.
     *
     * @param  iterable<string>  $instrumentIds
     * @return Collection<string, self>
     */
    public static function latestFor(iterable $instrumentIds): Collection
    {
        $ids = collect($instrumentIds)->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return self::query()
            ->fromRaw('(
                SELECT DISTINCT ON (instrument_id) *
                FROM instrument_prices
                WHERE instrument_id IN (' . $ids->map(fn () => '?')->implode(',') . ')
                ORDER BY instrument_id, date DESC, (source = \'manual\') DESC, created_at DESC
            ) AS instrument_prices', $ids->all())
            ->get()
            ->keyBy('instrument_id');
    }
}
