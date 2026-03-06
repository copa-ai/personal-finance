<?php

namespace App\Filament\Resources\ProductCategories\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Información de Categoría')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->required(),
                        TextInput::make('priority')
                            ->label('Prioridad (1-10)')
                            ->required()
                            ->numeric()
                            ->default(5)
                            ->minValue(1)
                            ->maxValue(10),
                        Textarea::make('description')
                            ->label('Descripción')
                            ->columnSpanFull(),
                    ])
            ]);
    }
}
