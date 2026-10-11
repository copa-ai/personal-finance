<?php

namespace App\Filament\Resources\Instruments;

use App\Filament\Resources\Instruments\Pages\CreateInstrument;
use App\Filament\Resources\Instruments\Pages\EditInstrument;
use App\Filament\Resources\Instruments\Pages\ListInstruments;
use App\Filament\Resources\Instruments\RelationManagers\PricesRelationManager;
use App\Filament\Resources\Instruments\RelationManagers\TransactionsRelationManager;
use App\Filament\Resources\Instruments\Schemas\InstrumentForm;
use App\Filament\Resources\Instruments\Tables\InstrumentsTable;
use App\Models\Instrument;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class InstrumentResource extends Resource
{
    protected static ?string $model = Instrument::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|UnitEnum|null $navigationGroup = 'Inversiones';

    protected static ?int $navigationSort = 11;

    protected static ?string $modelLabel = 'Instrumento';

    protected static ?string $pluralModelLabel = 'Instrumentos';

    public static function form(Schema $schema): Schema
    {
        return InstrumentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InstrumentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PricesRelationManager::class,
            TransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstruments::route('/'),
            'create' => CreateInstrument::route('/create'),
            'edit' => EditInstrument::route('/{record}/edit'),
        ];
    }
}
