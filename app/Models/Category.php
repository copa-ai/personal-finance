<?php

namespace App\Models;

use App\Models\Concerns\UuidDeClaveNatural;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Category extends Model
{
    use UuidDeClaveNatural;
    use SoftDeletes;

    /** El id es un UUIDv5 del nombre: dos bases que crean la misma categoria por separado coinciden al fusionarse. */
    public static function claveNatural(): string
    {
        return 'name';
    }

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
            'children' => fn (HasMany $query) => $query->withoutTrashed(),
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
