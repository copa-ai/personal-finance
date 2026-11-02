<?php

namespace App\Filament\Resources\DemoPortfolios\Schemas;

use App\Models\Instrument;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class DemoPortfolioForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(255),
            TextInput::make('initial_points')
                ->label('Puntos iniciales')
                ->helperText('1 punto = 1 EUR.')
                ->numeric()
                ->minValue(0.01)
                ->required(),
            DatePicker::make('start_date')
                ->label('Fecha de inicio')
                ->default(now()->toDateString())
                ->required(),
            TextInput::make('fee_fixed')
                ->label('Comisión fija (puntos)')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->required(),
            TextInput::make('fee_percent')
                ->label('Comisión (%)')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->required(),
            Select::make('benchmark_instrument_id')
                ->label('Referencia (benchmark)')
                ->options(fn () => Instrument::query()->orderBy('symbol')->pluck('name', 'id'))
                ->searchable()
                ->preload(),
            Textarea::make('notes')
                ->label('Notas')
                ->columnSpanFull(),
        ]);
    }
}
