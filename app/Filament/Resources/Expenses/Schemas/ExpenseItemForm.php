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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ExpenseItemForm
{
    private static function parseNumeric(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

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
                ->columns(3)
                ->schema([
                    TextInput::make('quantity')
                        ->label('Cantidad')
                        ->required()
                        ->numeric()
                        ->default(1)
                        ->reactive()
                        ->afterStateUpdated(static function (Set $set, Get $get): void {
                            $quantity = self::parseNumeric($get('quantity'));
                            $unitPrice = self::parseNumeric($get('unit_price'));

                            if (($quantity === null) || ($unitPrice === null)) {
                                return;
                            }

                            $total = self::money($quantity * $unitPrice);
                            $currentTotal = self::parseNumeric($get('total_price'));

                            if (($currentTotal !== null) && (self::money($currentTotal) === $total)) {
                                return;
                            }

                            $set('total_price', $total);
                        }),
                    TextInput::make('total_price')
                        ->label('Gasto Total (€)')
                        ->numeric()
                        ->prefix('€')
                        ->dehydrated(false)
                        ->reactive()
                        ->afterStateHydrated(static function (Set $set, Get $get, mixed $state): void {
                            if (filled($state)) {
                                return;
                            }

                            $quantity = self::parseNumeric($get('quantity'));
                            $unitPrice = self::parseNumeric($get('unit_price'));

                            if (($quantity === null) || ($unitPrice === null)) {
                                return;
                            }

                            $set('total_price', self::money($quantity * $unitPrice));
                        })
                        ->afterStateUpdated(static function (Set $set, Get $get, mixed $state): void {
                            $quantity = self::parseNumeric($get('quantity'));
                            $total = self::parseNumeric($state);

                            if (($quantity === null) || ($quantity <= 0) || ($total === null)) {
                                return;
                            }

                            $unitPrice = self::money($total / $quantity);
                            $currentUnitPrice = self::parseNumeric($get('unit_price'));

                            if (($currentUnitPrice === null) || (self::money($currentUnitPrice) !== $unitPrice)) {
                                $set('unit_price', $unitPrice);
                            }
                        }),
                    TextInput::make('unit_price')
                        ->label('Precio Unitario (€)')
                        ->required()
                        ->numeric()
                        ->prefix('€')
                        ->reactive()
                        ->afterStateUpdated(static function (Set $set, Get $get): void {
                            $quantity = self::parseNumeric($get('quantity'));
                            $unitPrice = self::parseNumeric($get('unit_price'));

                            if (($quantity === null) || ($unitPrice === null)) {
                                return;
                            }

                            $total = self::money($quantity * $unitPrice);
                            $currentTotal = self::parseNumeric($get('total_price'));

                            if (($currentTotal !== null) && (self::money($currentTotal) === $total)) {
                                return;
                            }

                            $set('total_price', $total);
                        }),
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
