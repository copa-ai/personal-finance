<?php

namespace App\Filament\Resources\InvestmentPlatforms\Schemas;

use App\Enums\PlatformType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class InvestmentPlatformForm
{
    public static function components(): array
    {
        return [
            TextInput::make('name')
                ->label('Nombre')
                ->helperText('P. ej. "MyInvestor", "Trade Republic", "Binance" o "Ledger".')
                ->required()
                ->maxLength(150),
            Select::make('type')
                ->label('Tipo')
                ->options(PlatformType::class)
                ->default(PlatformType::BROKER->value)
                ->required(),
            TextInput::make('country')
                ->label('País')
                ->helperText('Código de 2 letras (ES, DE, IE…). Fuera de España cuenta para el Modelo 720/721.')
                ->default('ES')
                ->length(2)
                ->dehydrateStateUsing(fn (?string $state): string => strtoupper((string) $state))
                ->required(),
            Textarea::make('notes')
                ->label('Notas')
                ->columnSpanFull(),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(self::components());
    }
}
