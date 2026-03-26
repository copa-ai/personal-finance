<?php

namespace App\Filament\Resources\Needs;

use App\Filament\Resources\Needs\Pages\CreateNeed;
use App\Filament\Resources\Needs\Pages\EditNeed;
use App\Filament\Resources\Needs\Pages\ListNeeds;
use App\Filament\Resources\Needs\Pages\ViewNeed;
use App\Filament\Resources\Needs\RelationManagers\ProductsRelationManager;
use App\Filament\Resources\Needs\Schemas\NeedForm;
use App\Filament\Resources\Needs\Schemas\NeedInfolist;
use App\Filament\Resources\Needs\Tables\NeedsTable;
use App\Models\Need;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class NeedResource extends Resource
{
    protected static ?string $model = Need::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?string $modelLabel = 'Necesidad';

    protected static ?string $pluralModelLabel = 'Necesidades';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventario';

    public static function form(Schema $schema): Schema
    {
        return NeedForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return NeedInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NeedsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ProductsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNeeds::route('/'),
            'create' => CreateNeed::route('/create'),
            'view' => ViewNeed::route('/{record}'),
            'edit' => EditNeed::route('/{record}/edit'),
        ];
    }
}
