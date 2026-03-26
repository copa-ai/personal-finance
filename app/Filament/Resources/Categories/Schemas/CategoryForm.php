<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Models\Category;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function components(): array
    {
        return [
            Section::make('Datos de Categoría')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(100),
                    Select::make('parent_id')
                        ->label('Categoría Padre')
                        ->placeholder('Sin padre')
                        ->options(fn (?Category $record) => Category::query()
                            ->when($record, fn ($query) => $query->whereKeyNot($record->id))
                            ->orderBy('name')
                            ->pluck('name', 'id'))
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
