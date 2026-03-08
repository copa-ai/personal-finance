<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kirschbaum\Commentions\Contracts\Commentable;
use Kirschbaum\Commentions\HasComments;

class ProductCategory extends Model implements Commentable
{
    use HasComments;
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'name',
        'description',
        'priority',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'priority' => 'integer',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }
}
