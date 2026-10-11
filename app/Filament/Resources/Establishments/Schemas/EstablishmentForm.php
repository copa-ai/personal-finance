<?php

namespace App\Filament\Resources\Establishments\Schemas;

use App\Models\Category;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use App\Filament\Resources\Categories\Schemas\CategoryForm;

class EstablishmentForm
{
    public static function components(): array
    {
        return [
            TextInput::make('name')
                ->label('Name')
                ->required()
                ->maxLength(150),
            Select::make('category_id')
                ->label('Category')
                ->placeholder('No category')
                ->relationship('category', 'name')
                ->preload()
                ->createOptionForm(CategoryForm::components())
                ->searchable()
                ->nullable(),
            Select::make('investment_platform_id')
                ->label('Plataforma de inversión')
                ->helperText('Si es tu broker o exchange, las transferencias a él no cuentan como gasto.')
                ->placeholder('No es una plataforma de inversión')
                ->relationship('investmentPlatform', 'name')
                ->preload()
                ->searchable()
                ->nullable(),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(self::components());
    }
}
