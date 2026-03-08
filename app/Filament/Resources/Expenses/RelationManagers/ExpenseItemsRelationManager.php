<?php

namespace App\Filament\Resources\Expenses\RelationManagers;

use App\Filament\Resources\Expenses\Schemas\ExpenseItemForm;
use App\Filament\Resources\Expenses\Tables\ExpenseItemsTable;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class ExpenseItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Líneas de Gasto';

    public function form(Schema $schema): Schema
    {
        return ExpenseItemForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return ExpenseItemsTable::configure($table)
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
