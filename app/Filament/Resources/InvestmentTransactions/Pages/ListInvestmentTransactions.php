<?php

namespace App\Filament\Resources\InvestmentTransactions\Pages;

use App\Filament\Resources\InvestmentTransactions\InvestmentTransactionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInvestmentTransactions extends ListRecords
{
    protected static string $resource = InvestmentTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
