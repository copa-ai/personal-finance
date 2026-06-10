<?php

namespace App\Filament\Resources\Establishments;

use App\Filament\Resources\Establishments\Pages\CreateEstablishment;
use App\Filament\Resources\Establishments\Pages\ListEstablishments;
use App\Filament\Resources\Establishments\Tables\EstablishmentsTable;
use App\Filament\Resources\Establishments\Schemas\EstablishmentForm;
use App\Models\Establishment;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EstablishmentResource extends Resource
{
    protected static ?string $model = Establishment::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $modelLabel = 'Establecimiento';

    protected static ?string $pluralModelLabel = 'Establecimientos';

    protected static string|\UnitEnum|null $navigationGroup = 'Gestión';

    public static function form(Schema $schema): Schema
    {
        return EstablishmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EstablishmentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEstablishments::route('/'),
            'create' => CreateEstablishment::route('/create')
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
