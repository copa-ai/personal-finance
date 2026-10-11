<?php

namespace App\Filament\Resources\Instruments\Schemas;

use App\Enums\AssetClass;
use App\Enums\InstrumentKind;
use App\Filament\Forms\MarketSymbolInput;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class InstrumentForm
{
    public static function components(): array
    {
        return [
            TextInput::make('name')
                ->label('Nombre')
                ->helperText('P. ej. "iShares Core MSCI World", "Bitcoin" o "Depósito 12 meses".')
                ->required()
                ->maxLength(150),
            Select::make('kind')
                ->label('Tipo')
                ->options(InstrumentKind::class)
                ->default(InstrumentKind::ETF->value)
                ->live()
                ->required(),
            Select::make('asset_class')
                ->label('Clase de activo')
                ->helperText('Se usa para el reparto de la cartera y la asignación objetivo.')
                ->options(AssetClass::class)
                ->default(AssetClass::EQUITY->value)
                ->required(),
            TextInput::make('isin')
                ->label('ISIN')
                ->length(12)
                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtoupper($state) : null),
            TextInput::make('ticker')
                ->label('Ticker')
                ->maxLength(30),
            TextInput::make('currency')
                ->label('Divisa')
                ->helperText('Divisa en la que cotiza.')
                ->default('EUR')
                ->length(3)
                ->dehydrateStateUsing(fn (?string $state): string => strtoupper((string) $state))
                ->required(),
            MarketSymbolInput::make()
                ->helperText('Opcional. Con símbolo, el precio se actualiza solo desde el servicio de mercado. Formato: crypto:BTC-EUR, etf:IWDA.AS, index:^GSPC.'),
            DatePicker::make('maturity_date')
                ->label('Vencimiento')
                ->helperText('Se avisa unos días antes.')
                ->visible(fn (Get $get): bool => in_array(
                    $get('kind') instanceof InstrumentKind ? $get('kind') : InstrumentKind::tryFrom((string) $get('kind')),
                    [InstrumentKind::DEPOSIT, InstrumentKind::BOND],
                    true,
                )),
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
