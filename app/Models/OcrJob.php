<?php

namespace App\Models;

use App\Enums\OcrJobStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OcrJob extends Model
{
    use HasUuids;

    protected $fillable = [
        'expense_id',
        'user_id',
        'status',
        'ticket_path',
        'model',
        'items_imported',
        'started_at',
        'finished_at',
        'error_message',
        'error_trace',
    ];

    protected $casts = [
        'status' => OcrJobStatus::class,
        'items_imported' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
