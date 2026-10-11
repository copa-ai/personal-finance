<?php

namespace App\Models;

use App\Enums\ReminderKind;
use App\Enums\ReminderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Reminder extends Model
{
    use HasUuids;

    protected $fillable = [
        'kind',
        'status',
        'title',
        'body',
        'due_on',
        'remindable_type',
        'remindable_id',
        'dedupe_key',
        'resolved_at',
    ];

    protected $casts = [
        'kind' => ReminderKind::class,
        'status' => ReminderStatus::class,
        'due_on' => 'date',
        'resolved_at' => 'datetime',
    ];

    public function remindable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ReminderStatus::PENDING);
    }

    public function resolve(ReminderStatus $status): void
    {
        $this->forceFill([
            'status' => $status,
            'resolved_at' => now(),
        ])->save();
    }
}
