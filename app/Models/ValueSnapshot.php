<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ValueSnapshot extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'date',
        'value',
        'currency',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'value' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function valuable(): MorphTo
    {
        return $this->morphTo();
    }
}
