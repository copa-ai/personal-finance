<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Filament\Resources\ProductCategories\Schemas\ProductCategoryForm;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function components(bool $hideCategoryField = false): array
    {
        return [
            Section::make('Información Básica')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre')
                        ->required(),
                    Select::make('category_id')
                        ->relationship('category', 'name')
                        ->label('Necesidad')
                        ->createOptionForm(ProductCategoryForm::components())
                        ->editOptionForm(ProductCategoryForm::components())
                        ->hidden($hideCategoryField)
                        ->dehydrated(! $hideCategoryField)
                        ->required(! $hideCategoryField),
                    TextInput::make('brand')
                        ->label('Marca'),
                    TextInput::make('variant')
                        ->label('Variante'),
                    TextInput::make('unit_of_measure')
                        ->label('Unidad de Medida (Ej: kg, L, ud)')
                        ->required(),
                ]),
            Section::make('Métricas y Estado')
                ->columns(2)
                ->schema([
                    Toggle::make('is_consumable')
                        ->label('¿Es Consumible?')
                        ->default(true)
                        ->required(),
                    Toggle::make('active')
                        ->label('¿Activo?')
                        ->default(true)
                        ->required(),
                    TextInput::make('current_quantity')
                        ->label('Cantidad Actual')
                        ->required()
                        ->numeric()
                        ->default(0),
                    TextInput::make('daily_consumption_rate')
                        ->label('Tasa de Consumo Diario')
                        ->numeric(),
                    TextInput::make('target_price')
                        ->label('Precio Objetivo (€)')
                        ->numeric()
                        ->prefix('€'),
                    DateTimePicker::make('last_updated_at')
                        ->label('Última Actualización')
                        ->default(now())
                        ->required(),
                ]),
            Section::make('Comentarios')
                ->schema([
                    Textarea::make('notes')
                        ->label('Notas')
                        ->columnSpanFull(),
                ]),
        ];
    }

    public static function configure(Schema $schema, bool $hideCategoryField = false): Schema
    {
        return $schema
            ->components(self::components($hideCategoryField));
    }
}
