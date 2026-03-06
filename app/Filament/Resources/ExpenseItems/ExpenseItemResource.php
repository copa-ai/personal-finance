<?php

namespace App\Filament\Resources\ExpenseItems;

use App\Filament\Resources\ExpenseItems\Pages\CreateExpenseItem;
use App\Filament\Resources\ExpenseItems\Pages\EditExpenseItem;
use App\Filament\Resources\ExpenseItems\Pages\ListExpenseItems;
use App\Filament\Resources\ExpenseItems\Schemas\ExpenseItemForm;
use App\Filament\Resources\ExpenseItems\Tables\ExpenseItemsTable;
use App\Models\ExpenseItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ExpenseItemResource extends Resource
{
    protected static ?string $model = ExpenseItem::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';
    protected static ?string $modelLabel = 'Línea de Gasto';
    protected static ?string $pluralModelLabel = 'Líneas de Gasto';
    protected static string|\UnitEnum|null $navigationGroup = 'Finanzas';

    public static function form(Schema $schema): Schema
    {
        return ExpenseItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExpenseItemsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenseItems::route('/'),
            'create' => CreateExpenseItem::route('/create'),
            'edit' => EditExpenseItem::route('/{record}/edit'),
        ];
    }
}
