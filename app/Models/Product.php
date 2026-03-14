<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kirschbaum\Commentions\Contracts\Commentable;
use Kirschbaum\Commentions\HasComments;

class Product extends Model implements Commentable
{
    use HasComments;
    use HasUuids;

    public $timestamps = false;

    protected $attributes = [
        'unit_of_measure' => 'Und',
    ];

    protected $fillable = [
        'name',
        'brand',
        'variant',
        'category_id',
        'unit_of_measure',
        'is_consumable',
        'current_quantity',
        'daily_consumption_rate',
        'target_price',
        'notes',
        'active',
        'last_updated_at',
        'created_at',
    ];

    protected $casts = [
        'is_consumable' => 'boolean',
        'active' => 'boolean',
        'current_quantity' => 'decimal:3',
        'daily_consumption_rate' => 'decimal:5',
        'target_price' => 'decimal:2',
        'last_updated_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(ProductRating::class);
    }
}
