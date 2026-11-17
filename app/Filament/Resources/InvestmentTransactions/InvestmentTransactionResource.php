<?php

namespace App\Filament\Resources\InvestmentTransactions;

use App\Filament\Resources\InvestmentTransactions\Pages\CreateInvestmentTransaction;
use App\Filament\Resources\InvestmentTransactions\Pages\EditInvestmentTransaction;
use App\Filament\Resources\InvestmentTransactions\Pages\ListInvestmentTransactions;
use App\Filament\Resources\InvestmentTransactions\Schemas\InvestmentTransactionForm;
use App\Filament\Resources\InvestmentTransactions\Tables\InvestmentTransactionsTable;
use App\Models\InvestmentTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class InvestmentTransactionResource extends Resource
{
    protected static ?string $model = InvestmentTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string|UnitEnum|null $navigationGroup = 'Inversiones';

    protected static ?int $navigationSort = 12;

    protected static ?string $modelLabel = 'Movimiento';

    protected static ?string $pluralModelLabel = 'Movimientos';

    public static function form(Schema $schema): Schema
    {
        return InvestmentTransactionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InvestmentTransactionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvestmentTransactions::route('/'),
            'create' => CreateInvestmentTransaction::route('/create'),
            'edit' => EditInvestmentTransaction::route('/{record}/edit'),
        ];
    }
}
