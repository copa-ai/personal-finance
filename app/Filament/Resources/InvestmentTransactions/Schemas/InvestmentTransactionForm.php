<?php

namespace App\Filament\Resources\InvestmentTransactions\Schemas;

use App\Enums\InvestmentTransactionType as T;
use App\Filament\Resources\Instruments\Schemas\InstrumentForm;
use App\Filament\Resources\InvestmentPlatforms\Schemas\InvestmentPlatformForm;
use App\Models\FxRate;
use App\Models\Instrument;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class InvestmentTransactionForm
{
    /** Movimientos que mueven unidades. */
    private const WITH_QUANTITY = [T::BUY, T::SELL, T::TRANSFER_IN, T::TRANSFER_OUT, T::STAKING_REWARD, T::SPLIT, T::ADJUSTMENT];

    /** Movimientos con precio por unidad. */
    private const WITH_PRICE = [T::BUY, T::SELL, T::TRANSFER_IN, T::STAKING_REWARD, T::ADJUSTMENT];

    /** Movimientos solo de efectivo. */
    private const WITH_AMOUNT = [T::DIVIDEND, T::INTEREST, T::FEE];

    public static function type(Get $get): ?T
    {
        $type = $get('type');

        return $type instanceof T ? $type : T::tryFrom((string) $type);
    }

    private static function typeIn(Get $get, array $types): bool
    {
        return in_array(self::type($get), $types, true);
    }

    /**
     * Euros por unidad de la divisa con el último tipo del BCE en o antes de la fecha.
     */
    public static function defaultFxRate(?string $currency, mixed $date): ?string
    {
        $currency = strtoupper((string) $currency);

        if ($currency === '' || $currency === 'EUR') {
            return '1';
        }

        $rate = FxRate::query()
            ->where('currency', $currency)
            ->when($date, fn ($query) => $query->where('date', '<=', \Illuminate\Support\Carbon::parse($date)->toDateString()))
            ->orderByDesc('date')
            ->value('units_per_eur');

        return $rate === null ? null : bcdiv('1', (string) $rate, 10);
    }

    private static function refreshFx(Get $get, Set $set): void
    {
        $rate = self::defaultFxRate($get('currency'), $get('date'));

        if ($rate !== null) {
            $set('fx_rate_eur', $rate);
        }
    }

    /**
     * @param  bool  $includePlatform  false dentro del relation manager de una plataforma.
     * @param  bool  $includeInstrument  false dentro del relation manager de un instrumento.
     */
    public static function components(bool $includePlatform = true, bool $includeInstrument = true): array
    {
        $main = [];

        if ($includePlatform) {
            $main[] = Select::make('platform_id')
                ->label('Plataforma')
                ->relationship('platform', 'name')
                ->searchable()
                ->preload()
                ->createOptionForm(InvestmentPlatformForm::components())
                ->required();
        }

        if ($includeInstrument) {
            $main[] = Select::make('instrument_id')
                ->label('Instrumento')
                ->relationship('instrument', 'name')
                ->searchable()
                ->preload()
                ->createOptionForm(InstrumentForm::components())
                ->live()
                ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                    $currency = $state ? Instrument::query()->whereKey($state)->value('currency') : null;

                    if ($currency) {
                        $set('currency', $currency);
                        self::refreshFx($get, $set);
                    }
                })
                ->required();
        }

        $main[] = Select::make('type')
            ->label('Tipo')
            ->options(T::class)
            ->default(T::BUY->value)
            ->live()
            ->required();

        $main[] = DatePicker::make('date')
            ->label('Fecha')
            ->default(now(config('app.display_timezone'))->toDateString())
            ->live(onBlur: true)
            ->afterStateUpdated(fn (Get $get, Set $set) => self::refreshFx($get, $set))
            ->required();

        return [
            Section::make('Movimiento')
                ->columns(2)
                ->columnSpanFull()
                ->schema($main),
            Section::make('Importes')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('quantity')
                        ->label(fn (Get $get): string => self::type($get) === T::SPLIT ? 'Factor del split' : 'Cantidad (unidades)')
                        ->helperText(fn (Get $get): ?string => match (self::type($get)) {
                            T::SPLIT => 'Unidades nuevas por cada una antigua: 2 para un split 2:1, 0.1 para un contrasplit 1:10.',
                            T::ADJUSTMENT => 'Positivo suma unidades y negativo las quita. Úsalo para cuadrar el saldo sin apuntar el histórico.',
                            default => null,
                        })
                        ->numeric()
                        ->step('any')
                        ->default(0)
                        ->minValue(fn (Get $get): ?float => self::type($get) === T::ADJUSTMENT ? null : 0)
                        ->visible(fn (Get $get): bool => self::typeIn($get, self::WITH_QUANTITY))
                        ->required(fn (Get $get): bool => self::typeIn($get, self::WITH_QUANTITY))
                        // Los movimientos de efectivo guardan 0 unidades.
                        ->dehydrateStateUsing(fn (mixed $state, Get $get): string => self::typeIn($get, self::WITH_QUANTITY) ? (string) $state : '0')
                        ->dehydratedWhenHidden(),
                    TextInput::make('price')
                        ->label(fn (Get $get): string => self::type($get) === T::TRANSFER_IN ? 'Coste por unidad (si viene de fuera)' : 'Precio por unidad')
                        ->helperText(fn (Get $get): ?string => match (self::type($get)) {
                            T::TRANSFER_IN => 'Si viene de otra plataforma tuya se conserva su coste y esto se ignora.',
                            T::STAKING_REWARD => 'Valor de mercado al recibirla: es el rendimiento que tributa.',
                            default => null,
                        })
                        ->numeric()
                        ->step('any')
                        ->minValue(0)
                        ->visible(fn (Get $get): bool => self::typeIn($get, self::WITH_PRICE))
                        ->required(fn (Get $get): bool => self::typeIn($get, [T::BUY, T::SELL]))
                        ->dehydrateStateUsing(fn (mixed $state, Get $get): mixed => self::typeIn($get, self::WITH_PRICE) ? $state : null)
                        ->dehydratedWhenHidden(),
                    TextInput::make('amount')
                        ->label('Importe')
                        ->numeric()
                        ->step('0.01')
                        ->minValue(0)
                        ->visible(fn (Get $get): bool => self::typeIn($get, self::WITH_AMOUNT))
                        ->required(fn (Get $get): bool => self::typeIn($get, self::WITH_AMOUNT))
                        ->dehydrateStateUsing(fn (mixed $state, Get $get): mixed => self::typeIn($get, self::WITH_AMOUNT) ? $state : null)
                        ->dehydratedWhenHidden(),
                    TextInput::make('fees')
                        ->label('Comisiones')
                        ->helperText(fn (Get $get): ?string => self::typeIn($get, [T::DIVIDEND, T::INTEREST]) ? 'Retenciones o comisiones descontadas.' : null)
                        ->numeric()
                        ->step('0.01')
                        ->minValue(0)
                        ->default(0)
                        ->dehydrateStateUsing(fn (mixed $state): string => filled($state) ? (string) $state : '0'),
                    TextInput::make('currency')
                        ->label('Divisa')
                        ->default('EUR')
                        ->length(3)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                            $set('currency', strtoupper((string) $state));
                            self::refreshFx($get, $set);
                        })
                        ->dehydrateStateUsing(fn (?string $state): string => strtoupper((string) $state))
                        ->required(),
                    TextInput::make('fx_rate_eur')
                        ->label('Tipo de cambio')
                        ->helperText('Euros por 1 unidad de la divisa en esa fecha. Se rellena con el BCE si está disponible.')
                        ->numeric()
                        ->step('any')
                        ->minValue(0)
                        ->default(1)
                        ->visible(fn (Get $get): bool => strtoupper((string) $get('currency')) !== 'EUR')
                        ->required(fn (Get $get): bool => strtoupper((string) $get('currency')) !== 'EUR')
                        ->dehydrateStateUsing(fn (mixed $state, Get $get): string => strtoupper((string) $get('currency')) === 'EUR' ? '1' : (string) $state)
                        ->dehydratedWhenHidden(),
                ]),
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
