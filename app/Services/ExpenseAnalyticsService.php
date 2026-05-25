<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ExpenseItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ExpenseAnalyticsService
{
    public function categoryOptions(): array
    {
        return Category::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Category $category): array => [
                $category->id => $this->formatCategoryLabel($category),
            ])
            ->all();
    }

    public function tableQuery(array $filters): Builder
    {
        return $this->baseItemQuery($filters)
            ->select([
                'expense_items.*',
            ])
            ->selectRaw('expenses.date as expense_date')
            ->selectRaw('expenses.total as expense_total')
            ->selectRaw('expenses.id as expense_id')
            ->selectRaw('establishments.name as establishment_name')
            ->selectRaw("COALESCE(item_categories.name, establishment_categories.name, 'Sin categoría') as effective_category_name")
            ->selectRaw('COALESCE(expense_items.category_id, establishments.category_id) as effective_category_id')
            ->orderByDesc('expenses.date')
            ->orderByDesc('expense_items.line_total');
    }

    public function groupedByEffectiveCategory(array $filters, int $limit = 8): Collection
    {
        return $this->collapseTopResults(
            $this->baseAggregationQuery($filters)
                ->selectRaw("COALESCE(item_categories.name, establishment_categories.name, 'Sin categoría') as label")
                ->selectRaw('SUM(expense_items.line_total) as total')
                ->groupByRaw("COALESCE(item_categories.name, establishment_categories.name, 'Sin categoría')")
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row): array => [
                    'label' => (string) $row->label,
                    'total' => (float) $row->total,
                ]),
            $limit,
        );
    }

    public function groupedByEstablishment(array $filters, int $limit = 8): Collection
    {
        return $this->collapseTopResults(
            $this->baseAggregationQuery($filters)
                ->selectRaw("COALESCE(establishments.name, 'Sin establecimiento') as label")
                ->selectRaw('SUM(expense_items.line_total) as total')
                ->groupByRaw("COALESCE(establishments.name, 'Sin establecimiento')")
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row): array => [
                    'label' => (string) $row->label,
                    'total' => (float) $row->total,
                ]),
            $limit,
        );
    }

    public function totalForFilters(array $filters): float
    {
        return (float) $this->baseAggregationQuery($filters)->sum('expense_items.line_total');
    }

    public function totalForDateRange(array $filters): float
    {
        $filtersWithoutCategory = $filters;
        unset($filtersWithoutCategory['categoryId']);

        return (float) $this->baseAggregationQuery($filtersWithoutCategory)->sum('expense_items.line_total');
    }

    public function selectedCategoryLabel(array $filters): ?string
    {
        $categoryId = $filters['categoryId'] ?? null;

        if (! filled($categoryId)) {
            return null;
        }

        $category = Category::query()->find($categoryId);

        if (! $category) {
            return null;
        }

        return $this->formatCategoryLabel($category);
    }

    protected function baseAggregationQuery(array $filters): Builder
    {
        return $this->baseItemQuery($filters);
    }

    protected function baseItemQuery(array $filters): Builder
    {
        $query = ExpenseItem::query()
            ->join('expenses', 'expense_items.expense_id', '=', 'expenses.id')
            ->leftJoin('establishments', 'expenses.establishment_id', '=', 'establishments.id')
            ->leftJoin('categories as item_categories', 'expense_items.category_id', '=', 'item_categories.id')
            ->leftJoin('categories as establishment_categories', 'establishments.category_id', '=', 'establishment_categories.id');

        $query = $this->applyFilters($query, $filters);

        return $query;
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        $startDate = $filters['startDate'] ?? null;
        $endDate = $filters['endDate'] ?? null;
        $categoryId = $filters['categoryId'] ?? null;

        return $query
            ->when(
                filled($startDate),
                fn (Builder $query) => $query->whereDate('expenses.date', '>=', $startDate),
            )
            ->when(
                filled($endDate),
                fn (Builder $query) => $query->whereDate('expenses.date', '<=', $endDate),
            )
            ->when(
                filled($categoryId),
                function (Builder $query) use ($categoryId): Builder {
                    $categoryIds = $this->resolveCategoryIds((string) $categoryId);

                    return $query->where(function (Builder $query) use ($categoryIds): Builder {
                        foreach (array_values($categoryIds) as $index => $resolvedCategoryId) {
                            $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';

                            $query->{$method}(
                                'COALESCE(expense_items.category_id, establishments.category_id) = ?',
                                [$resolvedCategoryId],
                            );
                        }

                        return $query;
                    });
                },
            );
    }

    protected function resolveCategoryIds(string $categoryId): array
    {
        $category = Category::query()->withDescendants()->find($categoryId);

        if (! $category) {
            return [$categoryId];
        }

        return collect([$category->id])
            ->concat($category->descendants()->pluck('id'))
            ->unique()
            ->values()
            ->all();
    }

    protected function collapseTopResults(Collection $items, int $limit): Collection
    {
        $sorted = $items
            ->sortByDesc('total')
            ->values();

        if ($sorted->count() <= $limit) {
            return $sorted;
        }

        $topItems = $sorted->take($limit)->values();
        $otherTotal = $sorted->slice($limit)->sum('total');

        return $topItems->push([
            'label' => 'Otros',
            'total' => (float) $otherTotal,
        ]);
    }

    protected function formatCategoryLabel(Category $category): string
    {
        $ancestors = $category->ancestor_names;

        if ($ancestors === []) {
            return $category->name;
        }

        return implode(' / ', array_merge($ancestors, [$category->name]));
    }
}
