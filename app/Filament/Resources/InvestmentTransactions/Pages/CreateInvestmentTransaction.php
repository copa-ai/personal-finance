<?php

namespace App\Filament\Resources\InvestmentTransactions\Pages;

use App\Filament\Resources\InvestmentTransactions\InvestmentTransactionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInvestmentTransaction extends CreateRecord
{
    protected static string $resource = InvestmentTransactionResource::class;
}
