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
use Filament\Tables\Columns\Summarizers\Sum;
use Illuminate\Support\Facades\DB;
use Kirschbaum\Commentions\HasComments;

class ExpenseItem extends Model implements Commentable
{
    use HasComments;
    use HasUuids;

    /** SQL del importe de una linea (cantidad x precio unitario, a 2 decimales): ya no hay columna generada. */
    public const LINE_TOTAL_SQL = 'ROUND(expense_items.quantity * expense_items.unit_price, 2)';

    /**
     * Total de la columna de importe de linea. `line_total` no es columna, así que el sumador por defecto de Filament
     * (`sum("expense_items"."line_total")`) falla. Con `->query()` Filament no usa su SELECT agrupado y aplica `->using()`.
     */
    public static function lineTotalSummarizer(): Sum
    {
        return Sum::make()
            ->numeric(decimalPlaces: 2)
            ->suffix(' €')
            ->query(fn ($query) => $query)
            ->using(fn ($query) => $query->sum(DB::raw(self::LINE_TOTAL_SQL)));
    }

    protected $fillable = [
        'expense_id',
        'category_id',
        'concept',
        'quantity',
        'unit_price',
        // 'line_total' no es columna: lo calcula getLineTotalAttribute()
        'tags',
        'item_type',
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
