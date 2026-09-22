<?php

namespace App\Filament\Resources\CryptoAccounts\Pages;

use App\Filament\Resources\CryptoAccounts\CryptoAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCryptoAccount extends EditRecord
{
    protected static string $resource = CryptoAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
