<?php

namespace App\Filament\Resources\Needs\RelationManagers;

use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Kirschbaum\Commentions\Filament\Actions\CommentsAction;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $title = 'Productos';

    public function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema, hideNeedField: true);
    }

    public function table(Table $table): Table
    {
        return ProductsTable::configure($table)
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                CommentsAction::make()
                    ->mentionables(fn (Model $record) => User::query()->get()),
            ]);
    }
}