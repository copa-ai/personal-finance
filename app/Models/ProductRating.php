<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductRating extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'quality_rating',
        'value_rating',
        'comment',
        'expense_item_id',
        'created_at',
    ];

    protected $casts = [
        'quality_rating' => 'decimal:1',
        'value_rating' => 'decimal:1',
        'created_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function expenseItem(): BelongsTo
    {
        return $this->belongsTo(ExpenseItem::class, 'expense_item_id');
    }
}
