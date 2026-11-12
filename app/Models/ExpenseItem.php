<?php

namespace App\Models;

use App\Enums\ExpenseItemType;
use App\Enums\MovementType;
use App\Enums\Recurrence;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Kirschbaum\Commentions\Contracts\Commentable;
use Kirschbaum\Commentions\HasComments;

class ExpenseItem extends Model implements Commentable
{
    use HasComments;
    use HasUuids;

    /** SQL del importe de una linea (cantidad x precio unitario, a 2 decimales): ya no hay columna generada. */
    public const LINE_TOTAL_SQL = 'ROUND(expense_items.quantity * expense_items.unit_price, 2)';

    protected $fillable = [
        'expense_id',
        'category_id',
        'concept',
        'quantity',
        'unit_price',
        // 'line_total' no es columna: lo calcula getLineTotalAttribute()
        'tags',
        'item_type',
        'is_consumable',
        'end_date',
        'recurrence',
        'projected_start_date',
        'actual_start_date',
        'movement_type',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'tags' => 'array',
        'is_consumable' => 'boolean',
        'end_date' => 'date',
        'projected_start_date' => 'date',
        'actual_start_date' => 'date',
        'item_type' => ExpenseItemType::class,
        'recurrence' => Recurrence::class,
        'movement_type' => MovementType::class,
    ];

    /** Importe de la linea, con 2 decimales (cantidad x precio unitario). */
    public function getLineTotalAttribute(): string
    {
        $total = round((float) ($this->attributes['quantity'] ?? 0) * (float) ($this->attributes['unit_price'] ?? 0), 2);

        return number_format($total, 2, '.', '');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'expense_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function getEffectiveCategoryAttribute(): ?Category
    {
        return $this->category ?? $this->expense?->establishment?->category;
    }

    public function scopeWithEffectiveCategory(Builder $query): Builder
    {
        return $query
            ->with([
                'category',
                'expense.establishment.category',
            ]);
    }
}
