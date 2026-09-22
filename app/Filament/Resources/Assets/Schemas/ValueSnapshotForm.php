<?php

namespace App\Filament\Resources\Assets\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ValueSnapshotForm
{
    public static function components(): array
    {
        return [
            DatePicker::make('date')
                ->label('Fecha')
                ->default(now())
                ->required(),
            TextInput::make('value')
                ->label('Valor')
                ->numeric()
                ->prefix('€')
                ->required(),
            TextInput::make('currency')
                ->label('Moneda')
                ->default('EUR')
                ->required()
                ->maxLength(3),
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
