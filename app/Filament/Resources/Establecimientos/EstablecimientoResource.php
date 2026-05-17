<?php

namespace App\Filament\Resources\Establecimientos;

use App\Filament\Resources\Establecimientos\Pages\CreateEstablecimiento;
use App\Filament\Resources\Establecimientos\Pages\EditEstablecimiento;
use App\Filament\Resources\Establecimientos\Pages\ListEstablecimientos;
use App\Filament\Resources\Establecimientos\Tables\EstablecimientosTable;
use App\Filament\Resources\Establecimientos\Schemas\EstablecimientoForm;
use App\Models\Establecimiento;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EstablecimientoResource extends Resource
{
    protected static ?string $model = Establecimiento::class;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $modelLabel = 'Establecimiento';

    protected static ?string $pluralModelLabel = 'Establecimientos';

    public static function form(Schema $schema): Schema
    {
        return EstablecimientoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EstablecimientosTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEstablecimientos::route('/'),
            'create' => CreateEstablecimiento::route('/create'),
            'edit' => EditEstablecimiento::route('/{record}/edit'),
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
