<?php

namespace App\Filament\Resources\ExpenseItems\Schemas;

use App\Enums\ExpenseItemType;
use App\Enums\Recurrence;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TagsInput;
use Filament\Schemas\Schema;

class ExpenseItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Relaciones y Concepto')
                    ->columns(2)
                    ->schema([
                        Select::make('expense_id')
                            ->relationship('expense', 'establishment')
                            ->label('Gasto Principal')
                            ->required(),
                        Select::make('product_id')
                            ->relationship('product', 'name')
                            ->label('Producto Base'),
                        TextInput::make('concept')
                            ->label('Concepto')
                            ->columnSpanFull()
                            ->required(),
                        TagsInput::make('tags')
                            ->label('Etiquetas')
                            ->columnSpanFull(),
                    ]),
                Section::make('Finanzas')
                    ->columns(2)
                    ->schema([
                        TextInput::make('quantity')
                            ->label('Cantidad')
                            ->required()
                            ->numeric()
                            ->default(1),
                        TextInput::make('unit_price')
                            ->label('Precio Unitario (€)')
                            ->required()
                            ->numeric()
                            ->prefix('€'),
                    ]),
                Section::make('Clasificación y Fechas')
                    ->columns(2)
                    ->schema([
                        Select::make('item_type')
                            ->label('Clasificación')
                            ->options(ExpenseItemType::class)
                            ->required(),
                        Select::make('recurrence')
                            ->label('Recurrencia')
                            ->options(Recurrence::class)
                            ->default('NONE')
                            ->required(),
                        Toggle::make('is_consumable')
                            ->label('¿Consumible?')
                            ->default(true)
                            ->required(),
                        DatePicker::make('actual_start_date')->label('Fecha Inicio Real'),
                        DatePicker::make('projected_start_date')->label('Fecha Inicio Proyectada'),
                        DatePicker::make('end_date')->label('Fecha Fin'),
                    ])
            ]);
    }
}
