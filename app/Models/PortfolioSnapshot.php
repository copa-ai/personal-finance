<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortfolioSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'date',
        'value_eur',
        'invested_eur',
        'by_asset_class',
    ];

    protected $casts = [
        'date' => 'date',
        'value_eur' => 'decimal:2',
        'invested_eur' => 'decimal:2',
        'by_asset_class' => 'array',
        'created_at' => 'datetime',
    ];
}
