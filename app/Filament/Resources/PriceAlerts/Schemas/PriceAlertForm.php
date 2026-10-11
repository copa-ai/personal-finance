<?php

namespace App\Filament\Resources\PriceAlerts\Schemas;

use App\Enums\PriceAlertCondition;
use App\Filament\Forms\MarketSymbolInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PriceAlertForm
{
    public static function components(): array
    {
        return [
            MarketSymbolInput::make(required: true),
            Select::make('condition')
                ->label('Condición')
                ->options(PriceAlertCondition::class)
                ->default(PriceAlertCondition::ABOVE->value)
                ->live()
                ->required(),
            TextInput::make('threshold')
                ->label('Umbral')
                ->helperText(fn (Get $get): string => ($get('condition') instanceof PriceAlertCondition ? $get('condition') : PriceAlertCondition::tryFrom((string) $get('condition'))) === PriceAlertCondition::CHANGE_PCT
                    ? 'En %: avisa si el precio sube o baja más de esto en el día.'
                    : 'Precio en la divisa en la que cotiza el símbolo.')
                ->numeric()
                ->step('any')
                ->minValue(0)
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
