<?php

namespace App\Filament\Resources\TargetAllocations\Schemas;

use App\Enums\AssetClass;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TargetAllocationForm
{
    public static function components(): array
    {
        return [
            Select::make('asset_class')
                ->label('Clase de activo')
                ->options(AssetClass::class)
                ->unique(ignoreRecord: true)
                ->required(),
            TextInput::make('target_pct')
                ->label('Objetivo')
                ->numeric()
                ->step('0.01')
                ->minValue(0)
                ->maxValue(100)
                ->suffix('%')
                ->required(),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(self::components());
    }
}
