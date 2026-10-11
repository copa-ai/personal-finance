<?php

namespace App\Models;

use App\Enums\PlatformType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvestmentPlatform extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'type',
        'country',
        'notes',
    ];

    protected $casts = [
        'type' => PlatformType::class,
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(InvestmentTransaction::class, 'platform_id');
    }

    public function contributionPlans(): HasMany
    {
        return $this->hasMany(ContributionPlan::class, 'platform_id');
    }

    public function establishments(): HasMany
    {
        return $this->hasMany(Establishment::class, 'investment_platform_id');
    }

    public function isForeign(): bool
    {
        return strtoupper((string) $this->country) !== 'ES';
    }
}
