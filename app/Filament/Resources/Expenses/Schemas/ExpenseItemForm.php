<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Enums\ExpenseItemType;
use App\Enums\Recurrence;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ExpenseItemForm
{
    public static function components(bool $includeExpenseField = false): array
    {
        $conceptSectionSchema = [];

        if ($includeExpenseField) {
            $conceptSectionSchema[] = Select::make('expense_id')
                ->relationship('expense', 'id')
                ->label('Gasto')
                ->createOptionForm(ExpenseForm::components())
                ->editOptionForm(ExpenseForm::components())
                ->required();
        }

        $conceptSectionSchema[] = Select::make('product_id')
            ->relationship('product', 'name')
            ->label('Producto Base')
            ->createOptionForm(ProductForm::components())
            ->editOptionForm(ProductForm::components());
        $conceptSectionSchema[] = TextInput::make('concept')
            ->label('Concepto')
            ->columnSpanFull()
            ->required();
        $conceptSectionSchema[] = TagsInput::make('tags')
            ->label('Etiquetas')
            ->columnSpanFull();

        return [
            Section::make('Concepto y Producto')
                ->columns(2)
                ->schema($conceptSectionSchema),
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
                ]),
        ];
    }

    public static function configure(Schema $schema, bool $includeExpenseField = false): Schema
    {
        return $schema
            ->components(self::components($includeExpenseField));
    }
}
