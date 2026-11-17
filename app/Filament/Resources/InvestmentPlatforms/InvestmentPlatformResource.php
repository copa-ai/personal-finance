<?php

namespace App\Filament\Resources\InvestmentPlatforms;

use App\Filament\Resources\InvestmentPlatforms\Pages\CreateInvestmentPlatform;
use App\Filament\Resources\InvestmentPlatforms\Pages\EditInvestmentPlatform;
use App\Filament\Resources\InvestmentPlatforms\Pages\ListInvestmentPlatforms;
use App\Filament\Resources\InvestmentPlatforms\RelationManagers\TransactionsRelationManager;
use App\Filament\Resources\InvestmentPlatforms\Schemas\InvestmentPlatformForm;
use App\Filament\Resources\InvestmentPlatforms\Tables\InvestmentPlatformsTable;
use App\Models\InvestmentPlatform;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class InvestmentPlatformResource extends Resource
{
    protected static ?string $model = InvestmentPlatform::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|UnitEnum|null $navigationGroup = 'Inversiones';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Plataforma';

    protected static ?string $pluralModelLabel = 'Plataformas';

    public static function form(Schema $schema): Schema
    {
        return InvestmentPlatformForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InvestmentPlatformsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            TransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvestmentPlatforms::route('/'),
            'create' => CreateInvestmentPlatform::route('/create'),
            'edit' => EditInvestmentPlatform::route('/{record}/edit'),
        ];
    }
}
