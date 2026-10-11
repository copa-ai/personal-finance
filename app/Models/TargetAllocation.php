<?php

namespace App\Models;

use App\Enums\AssetClass;
use Illuminate\Database\Eloquent\Model;

class TargetAllocation extends Model
{
    protected $fillable = [
        'asset_class',
        'target_pct',
    ];

    protected $casts = [
        'asset_class' => AssetClass::class,
        'target_pct' => 'decimal:2',
    ];
}
