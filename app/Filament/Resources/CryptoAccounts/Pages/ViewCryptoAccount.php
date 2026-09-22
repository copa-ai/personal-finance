<?php

namespace App\Filament\Resources\CryptoAccounts\Pages;

use App\Filament\Resources\CryptoAccounts\CryptoAccountResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCryptoAccount extends ViewRecord
{
    protected static string $resource = CryptoAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
