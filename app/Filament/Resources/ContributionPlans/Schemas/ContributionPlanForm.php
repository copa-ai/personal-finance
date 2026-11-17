<?php

namespace App\Filament\Resources\ContributionPlans\Schemas;

use App\Enums\ContributionFrequency;
use App\Filament\Resources\Instruments\Schemas\InstrumentForm;
use App\Filament\Resources\InvestmentPlatforms\Schemas\InvestmentPlatformForm;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ContributionPlanForm
{
    public static function components(): array
    {
        return [
            TextInput::make('name')
                ->label('Nombre')
                ->helperText('P. ej. "Indexado mensual" o "DCA bitcoin".')
                ->required()
                ->maxLength(150),
            Select::make('platform_id')
                ->label('Plataforma')
                ->relationship('platform', 'name')
                ->searchable()
                ->preload()
                ->createOptionForm(InvestmentPlatformForm::components())
                ->required(),
            Select::make('instrument_id')
                ->label('Instrumento')
                ->helperText('Opcional: si lo dejas vacío, lo eliges al marcar la aportación como hecha.')
                ->relationship('instrument', 'name')
                ->searchable()
                ->preload()
                ->createOptionForm(InstrumentForm::components()),
            TextInput::make('amount')
                ->label('Importe')
                ->numeric()
                ->step('0.01')
                ->minValue(0.01)
                ->required(),
            TextInput::make('currency')
                ->label('Divisa')
                ->default('EUR')
                ->length(3)
                ->dehydrateStateUsing(fn (?string $state): string => strtoupper((string) $state))
                ->required(),
            Select::make('frequency')
                ->label('Frecuencia')
                ->options(ContributionFrequency::class)
                ->default(ContributionFrequency::MONTHLY->value)
                ->required(),
            DatePicker::make('next_due_on')
                ->label('Próxima aportación')
                ->helperText('Ese día llega el recordatorio; después se calcula la siguiente según la frecuencia.')
                ->default(now(config('app.display_timezone'))->toDateString())
                ->required(),
            Toggle::make('active')
                ->label('Activa')
                ->default(true),
            Textarea::make('notes')
                ->label('Notas')
                ->columnSpanFull(),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(self::components());
    }
}
