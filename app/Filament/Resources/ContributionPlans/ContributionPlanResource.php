<?php

namespace App\Filament\Resources\ContributionPlans;

use App\Filament\Resources\ContributionPlans\Pages\CreateContributionPlan;
use App\Filament\Resources\ContributionPlans\Pages\EditContributionPlan;
use App\Filament\Resources\ContributionPlans\Pages\ListContributionPlans;
use App\Filament\Resources\ContributionPlans\Schemas\ContributionPlanForm;
use App\Filament\Resources\ContributionPlans\Tables\ContributionPlansTable;
use App\Models\ContributionPlan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class ContributionPlanResource extends Resource
{
    protected static ?string $model = ContributionPlan::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|UnitEnum|null $navigationGroup = 'Inversiones';

    protected static ?int $navigationSort = 13;

    protected static ?string $modelLabel = 'Aportación periódica';

    protected static ?string $pluralModelLabel = 'Aportaciones periódicas';

    public static function form(Schema $schema): Schema
    {
        return ContributionPlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContributionPlansTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContributionPlans::route('/'),
            'create' => CreateContributionPlan::route('/create'),
            'edit' => EditContributionPlan::route('/{record}/edit'),
        ];
    }
}
