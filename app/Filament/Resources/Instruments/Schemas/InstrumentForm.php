<?php

namespace App\Filament\Resources\Instruments\Schemas;

use App\Enums\AssetClass;
use App\Enums\InstrumentType;
use App\Enums\MarketProvider;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class InstrumentForm
{
    /** Un instrumento manual no cotiza en ningún proveedor: su precio se apunta a mano. */
    private static function isManual(Get $get): bool
    {
        $provider = $get('provider');

        return ($provider instanceof MarketProvider ? $provider : MarketProvider::tryFrom((string) $provider)) === MarketProvider::MANUAL;
    }

    public static function components(): array
    {
        return [
            TextInput::make('symbol')
                ->label('Símbolo')
                ->helperText('Identificador propio y único. P. ej. "SAN" o "BTC". En un instrumento manual se genera solo.')
                ->required(fn (Get $get): bool => ! self::isManual($get))
                ->unique(ignoreRecord: true)
                ->maxLength(40),
            TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(255),
            Select::make('type')
                ->label('Tipo')
                ->options(InstrumentType::class)
                ->live()
                ->required(),
            Select::make('provider')
                ->label('Proveedor de datos')
                ->options(MarketProvider::class)
                ->default(MarketProvider::YAHOO->value)
                ->helperText('«Manual» para depósitos, planes de pensiones o fondos sin cotización: el precio se apunta a mano.')
                ->live()
                ->required(),
            TextInput::make('provider_symbol')
                ->label('Símbolo en el proveedor')
                ->helperText('Yahoo: "SAN.MC", "IWDA.AS". Binance: "BTCEUR".')
                ->required(fn (Get $get): bool => ! self::isManual($get))
                ->visible(fn (Get $get): bool => ! self::isManual($get))
                ->maxLength(80),
            TextInput::make('isin')
                ->label('ISIN')
                ->maxLength(12),
            TextInput::make('currency')
                ->label('Moneda')
                ->default('EUR')
                ->required()
                ->maxLength(20),
            Select::make('asset_class')
                ->label('Clase de activo')
                ->helperText('Para el reparto de la cartera. Vacío: la que corresponde al tipo.')
                ->options(AssetClass::class)
                ->placeholder('Según el tipo'),
            DatePicker::make('maturity_date')
                ->label('Vencimiento')
                ->helperText('Se avisa unos días antes.')
                ->visible(fn (Get $get): bool => in_array(
                    $get('type') instanceof InstrumentType ? $get('type') : InstrumentType::tryFrom((string) $get('type')),
                    [InstrumentType::DEPOSIT, InstrumentType::BOND],
                    true,
                )),
            Toggle::make('active')
                ->label('Sincronizar a diario')
                ->default(true)
                ->visible(fn (Get $get): bool => ! self::isManual($get)),
            Textarea::make('notes')
                ->label('Notas')
                ->columnSpanFull(),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::components());
    }
}
