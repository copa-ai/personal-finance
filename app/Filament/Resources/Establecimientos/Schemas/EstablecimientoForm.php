<?php

namespace App\Filament\Resources\Establecimientos\Schemas;

use App\Models\Category;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EstablecimientoForm
{
    public static function components(): array
    {
        return [
            Section::make('Datos del Establecimiento')
                ->columns(2)
                ->schema([
                    TextInput::make('nombre')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(150),
                    Select::make('categoria')
                        ->label('Categoría')
                        ->options(fn () => Category::orderBy('name')->pluck('name', 'name'))
                        ->searchable(),
                ]),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(self::components());
    }
}
