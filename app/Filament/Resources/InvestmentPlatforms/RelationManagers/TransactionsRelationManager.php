<?php

namespace App\Filament\Resources\InvestmentPlatforms\RelationManagers;

use App\Filament\Resources\InvestmentTransactions\Schemas\InvestmentTransactionForm;
use App\Filament\Resources\InvestmentTransactions\Tables\InvestmentTransactionsTable;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Movimientos';

    protected static ?string $modelLabel = 'Movimiento';

    public function form(Schema $schema): Schema
    {
        return $schema->components(InvestmentTransactionForm::components(includePlatform: false));
    }

    public function table(Table $table): Table
    {
        return InvestmentTransactionsTable::configure($table, includePlatformColumn: false)
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(fn (array $data): array => [
                        ...$data,
                        'currency' => $data['currency'] ?? 'EUR',
                    ]),
            ]);
    }
}
