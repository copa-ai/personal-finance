<?php

namespace App\Models;

use App\Models\Concerns\UuidDeClaveNatural;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class Establishment extends Model
{
    use HasFactory;
    use UuidDeClaveNatural;

    /** El id es un UUIDv5 del nombre: dos bases que crean el mismo establecimiento por separado coinciden al fusionarse. */
    public static function claveNatural(): string
    {
        return 'name';
    }

    protected $fillable = [
        'name',
        'category_id',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
