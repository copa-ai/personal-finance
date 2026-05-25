<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Category extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'parent_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function getAncestorNamesAttribute(): array
    {
        $this->loadMissing('parent.parent');

        $names = [];
        $current = $this->parent;

        while ($current) {
            $names[] = $current->name;
            $current = $current->parent;
        }

        return array_reverse($names);
    }

    public function descendants(): Collection
    {
        $this->loadMissing([
            'children' => fn (Builder $query) => $query->withoutTrashed(),
        ]);

        return $this->children
            ->flatMap(function (Category $child): Collection {
                return collect([$child])->concat($child->descendants());
            })
            ->values();
    }

    public function scopeWithDescendants(Builder $query): Builder
    {
        return $query->with([
            'children' => fn ($childQuery) => $childQuery->withDescendants(),
        ]);
    }
}
