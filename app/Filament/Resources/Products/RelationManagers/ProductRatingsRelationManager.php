<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Resources\ProductRatings\Schemas\ProductRatingForm;
use App\Filament\Resources\ProductRatings\Tables\ProductRatingsTable;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class ProductRatingsRelationManager extends RelationManager
{
    protected static string $relationship = 'ratings';

    protected static ?string $title = 'Valoraciones';

    public function form(Schema $schema): Schema
    {
        return ProductRatingForm::configure($schema, hideProductField: true);
    }

    public function table(Table $table): Table
    {
        return ProductRatingsTable::configure($table)
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
