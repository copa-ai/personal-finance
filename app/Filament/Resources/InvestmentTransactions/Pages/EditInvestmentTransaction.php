<?php

namespace App\Filament\Resources\InvestmentTransactions\Pages;

use App\Filament\Resources\InvestmentTransactions\InvestmentTransactionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditInvestmentTransaction extends EditRecord
{
    protected static string $resource = InvestmentTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
