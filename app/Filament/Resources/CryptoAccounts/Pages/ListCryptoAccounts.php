<?php

namespace App\Filament\Resources\CryptoAccounts\Pages;

use App\Filament\Resources\CryptoAccounts\CryptoAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCryptoAccounts extends ListRecords
{
    protected static string $resource = CryptoAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
