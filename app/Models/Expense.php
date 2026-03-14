<?php

namespace App\Models;

use App\Enums\ExpenseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kirschbaum\Commentions\Contracts\Commentable;
use Kirschbaum\Commentions\HasComments;

class Expense extends Model implements Commentable
{
    use HasComments;
    use HasUuids;

    public bool $shouldCreateSingleItem = false;

    public $timestamps = false;

    protected ?string $subexpensesTotalCache = null;

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

    public function subexpensesTotal(): string
    {
        if ($this->subexpensesTotalCache !== null) {
            return $this->subexpensesTotalCache;
        }

        $this->subexpensesTotalCache = (string) ($this->items()->sum('line_total') ?? '0');

        return $this->subexpensesTotalCache;
    }

    public function hasPendingSubexpenses(): bool
    {
        if ($this->total === null) {
            return false;
        }

        return self::moneyToCents($this->subexpensesTotal()) !== self::moneyToCents($this->total);
    }

    public function subexpensesDifferenceCents(): ?int
    {
        if ($this->total === null) {
            return null;
        }

        return self::moneyToCents($this->subexpensesTotal()) - self::moneyToCents($this->total);
    }

    public static function formatCents(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.') . ' €';
    }

    /**
     * @param  string|int|float|null  $value
     */
    public static function formatMoney(string | int | float | null $value): string
    {
        return self::formatCents(self::moneyToCents($value));
    }

    /**
     * @param  string|int|float|null  $value
     */
    protected static function moneyToCents(string | int | float | null $value): int
    {
        return (int) round(((float) ($value ?? 0)) * 100);
    }

    public function scopeWithPendingSubexpenses(Builder $query): Builder
    {
        $expensesTable = $this->getTable();
        $itemsTable = (new ExpenseItem)->getTable();

        return $query
            ->whereNotNull("{$expensesTable}.total")
            ->whereRaw(
                "COALESCE((SELECT SUM(line_total) FROM {$itemsTable} WHERE {$itemsTable}.expense_id = {$expensesTable}.id), 0) <> {$expensesTable}.total",
            );
    }
}
