<?php

namespace App\Filament\Resources\Assets\RelationManagers;

use App\Filament\Resources\Assets\Schemas\ValueSnapshotForm;
use App\Filament\Resources\Assets\Tables\ValueSnapshotsTable;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class ValueSnapshotsRelationManager extends RelationManager
{
    protected static string $relationship = 'valueSnapshots';

    protected static ?string $title = 'Histórico de valor';

    public function form(Schema $schema): Schema
    {
        return ValueSnapshotForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return ValueSnapshotsTable::configure($table)
            ->headerActions([
                CreateAction::make(),
            ]);
    }
}
