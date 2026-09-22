<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'bank_name',
        'iban',
        'currency',
        'notes',
    ];

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'bank_account_id');
    }

    public function getTotalValueAttribute(): string
    {
        return (string) ($this->assets()->sum('current_value') ?? '0');
    }
}
