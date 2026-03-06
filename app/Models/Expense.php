<?php

namespace App\Models;

use App\Enums\ExpenseStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Expense extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'establishment',
        'date',
        'total',
        'status',
        'ticket_photo_hash',
        'created_at',
    ];

    protected $casts = [
        'date' => 'datetime',
        'created_at' => 'datetime',
        'total' => 'decimal:2',
        'status' => ExpenseStatus::class,
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ExpenseItem::class, 'expense_id');
    }
}
