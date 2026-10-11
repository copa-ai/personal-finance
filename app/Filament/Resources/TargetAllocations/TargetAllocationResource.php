<?php

namespace App\Filament\Resources\TargetAllocations;

use App\Filament\Resources\TargetAllocations\Pages\CreateTargetAllocation;
use App\Filament\Resources\TargetAllocations\Pages\EditTargetAllocation;
use App\Filament\Resources\TargetAllocations\Pages\ListTargetAllocations;
use App\Filament\Resources\TargetAllocations\Schemas\TargetAllocationForm;
use App\Filament\Resources\TargetAllocations\Tables\TargetAllocationsTable;
use App\Models\TargetAllocation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class TargetAllocationResource extends Resource
{
    protected static ?string $model = TargetAllocation::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static string|UnitEnum|null $navigationGroup = 'Inversiones';

    protected static ?int $navigationSort = 15;

    protected static ?string $modelLabel = 'Asignación objetivo';

    protected static ?string $pluralModelLabel = 'Asignación objetivo';

    public static function form(Schema $schema): Schema
    {
        return TargetAllocationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TargetAllocationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTargetAllocations::route('/'),
            'create' => CreateTargetAllocation::route('/create'),
            'edit' => EditTargetAllocation::route('/{record}/edit'),
        ];
    }
}
