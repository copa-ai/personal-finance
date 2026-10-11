<?php

namespace App\Models;

use App\Enums\ContributionFrequency;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ContributionPlan extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'platform_id',
        'instrument_id',
        'amount',
        'currency',
        'frequency',
        'next_due_on',
        'active',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'frequency' => ContributionFrequency::class,
        'next_due_on' => 'date',
        'active' => 'boolean',
    ];

    public function platform(): BelongsTo
    {
        return $this->belongsTo(InvestmentPlatform::class, 'platform_id');
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class, 'instrument_id');
    }

    public function reminders(): MorphMany
    {
        return $this->morphMany(Reminder::class, 'remindable');
    }

    public function advance(): void
    {
        $this->next_due_on = $this->frequency->advance($this->next_due_on);
        $this->save();
    }
}
