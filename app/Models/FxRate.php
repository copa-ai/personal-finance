<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FxRate extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'date',
        'currency',
        'units_per_eur',
        'source',
    ];

    protected $casts = [
        'date' => 'date',
        'units_per_eur' => 'decimal:10',
    ];
}
