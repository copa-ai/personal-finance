<?php

namespace App\Filament\Resources\Assets\RelationManagers;

use App\Filament\Resources\Assets\Schemas\AssetDocumentForm;
use App\Filament\Resources\Assets\Tables\AssetDocumentsTable;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class AssetDocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documentación legal';

    public function form(Schema $schema): Schema
    {
        return AssetDocumentForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return AssetDocumentsTable::configure($table)
            ->headerActions([
                CreateAction::make(),
            ]);
    }
}
