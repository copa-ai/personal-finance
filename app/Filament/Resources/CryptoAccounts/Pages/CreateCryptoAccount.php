<?php

namespace App\Filament\Resources\CryptoAccounts\Pages;

use App\Filament\Resources\CryptoAccounts\CryptoAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCryptoAccount extends CreateRecord
{
    protected static string $resource = CryptoAccountResource::class;
}
