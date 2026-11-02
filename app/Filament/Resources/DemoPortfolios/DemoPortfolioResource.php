<?php

namespace App\Filament\Resources\DemoPortfolios;

use App\Filament\Resources\DemoPortfolios\Pages\CreateDemoPortfolio;
use App\Filament\Resources\DemoPortfolios\Pages\EditDemoPortfolio;
use App\Filament\Resources\DemoPortfolios\Pages\ListDemoPortfolios;
use App\Filament\Resources\DemoPortfolios\RelationManagers\DemoTradesRelationManager;
use App\Filament\Resources\DemoPortfolios\Schemas\DemoPortfolioForm;
use App\Filament\Resources\DemoPortfolios\Tables\DemoPortfoliosTable;
use App\Models\DemoPortfolio;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class DemoPortfolioResource extends Resource
{
    protected static ?string $model = DemoPortfolio::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-beaker';

    protected static string|UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?string $modelLabel = 'Cartera demo';

    protected static ?string $pluralModelLabel = 'Carteras demo';

    public static function form(Schema $schema): Schema
    {
        return DemoPortfolioForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DemoPortfoliosTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            DemoTradesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDemoPortfolios::route('/'),
            'create' => CreateDemoPortfolio::route('/create'),
            'edit' => EditDemoPortfolio::route('/{record}/edit'),
        ];
    }
}
