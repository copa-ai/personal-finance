<?php

namespace App\Filament\Resources\ProductRatings\Schemas;

use App\Filament\Resources\Expenses\Schemas\ExpenseItemForm;
use App\Filament\Resources\Products\Schemas\ProductForm;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class ProductRatingForm
{
    public static function components(bool $hideProductField = false): array
    {
        return [
            Select::make('product_id')
                ->relationship('product', 'name')
                ->createOptionForm(ProductForm::components())
                ->editOptionForm(ProductForm::components())
                ->hidden($hideProductField)
                ->dehydrated(! $hideProductField)
                ->required(! $hideProductField),
            TextInput::make('quality_rating')
                ->numeric(),
            TextInput::make('value_rating')
                ->numeric(),
            Textarea::make('comment')
                ->columnSpanFull(),
            Select::make('expense_item_id')
                ->relationship('expenseItem', 'id')
                ->createOptionForm(ExpenseItemForm::components(includeExpenseField: true))
                ->editOptionForm(ExpenseItemForm::components(includeExpenseField: true)),
        ];
    }

    public static function configure(Schema $schema, bool $hideProductField = false): Schema
    {
        return $schema
            ->components(self::components($hideProductField));
    }
}
