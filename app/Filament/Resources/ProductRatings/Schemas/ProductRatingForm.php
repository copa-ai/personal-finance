<?php

namespace App\Filament\Resources\ProductRatings\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class ProductRatingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->relationship('product', 'name')
                    ->required(),
                TextInput::make('quality_rating')
                    ->numeric(),
                TextInput::make('value_rating')
                    ->numeric(),
                Textarea::make('comment')
                    ->columnSpanFull(),
                Select::make('expense_item_id')
                    ->relationship('expenseItem', 'id'),
            ]);
    }
}
