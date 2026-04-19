<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use App\Models\Product;

class ExpenseForm
{
    public static function components(): array
    {
        return [
            Callout::make('Subgastos pendientes')
                ->warning()
                ->description(function (?Expense $record): ?string {
                    if (! $record) {
                        return null;
                    }

                    $differenceCents = $record->subexpensesDifferenceCents();

                    if ($differenceCents === null) {
                        return null;
                    }

                    $differenceLabel = $differenceCents < 0 ? 'Faltan' : 'Sobran';

                    return sprintf(
                        'El sumatorio de subgastos (%s) no coincide con el total del gasto (%s). %s %s.',
                        Expense::formatMoney($record->subexpensesTotal()),
                        Expense::formatMoney($record->total),
                        $differenceLabel,
                        Expense::formatCents(abs($differenceCents)),
                    );
                })
                ->visible(fn (?Expense $record): bool => $record?->hasPendingSubexpenses() ?? false)
                ->columnSpanFull(),
            Section::make('Detalles del Gasto')
                ->columns(2)
                ->schema([
                    TextInput::make('establishment')
                        ->label('Establecimiento')
                        ->required(),
                    DateTimePicker::make('date')
                        ->label('Fecha y Hora')
                        ->default(now())
                        ->required(),
                    TextInput::make('total')
                        ->label('Total (€)')
                        ->numeric()
                        ->prefix('€')
                        ->required(fn (Get $get): bool => (bool) $get('create_single_item')),
                    Select::make('status')
                        ->label('Estado')
                        ->options(ExpenseStatus::class)
                        ->default('PAID')
                        ->required(),
                    Toggle::make('create_single_item')
                        ->label('Crear subgasto único por el total')
                        ->helperText('Crea automáticamente un subgasto con el importe total del gasto.')
                        ->default(false)
                        ->dehydrated(function ($livewire): bool {
                            if (method_exists($livewire, 'getFormContext')) {
                                return $livewire->getFormContext() === 'create';
                            }

                            return $livewire instanceof \Filament\Resources\Pages\CreateRecord;
                        })
                        ->visible(function ($livewire): bool {
                            if (method_exists($livewire, 'getFormContext')) {
                                return $livewire->getFormContext() === 'create';
                            }

                            return $livewire instanceof \Filament\Resources\Pages\CreateRecord;
                        })
                        ->reactive()
                        ->columnSpanFull(),
                    TextInput::make('singleItemConcept')
                        ->label('Concepto del subgasto único')
                        ->helperText('Específica el concepto del subgasto que se creará automáticamente con el importe total del gasto.')
                        ->dehydrated(function ($livewire): bool {
                            if (method_exists($livewire, 'getFormContext')) {
                                return $livewire->getFormContext() === 'create';
                            }

                            return $livewire instanceof \Filament\Resources\Pages\CreateRecord;
                        })
                        ->visible(fn (Get $get): bool => (bool) $get('create_single_item'))
                        ->datalist(fn() => Product::pluck('name')->toArray()),
                    \Filament\Forms\Components\FileUpload::make('ticket_photo_hash')
                        ->label('Foto del Ticket')
                        ->image()
                        ->directory('tickets')
                        ->columnSpanFull()
                        ->acceptedFileTypes([
                            'image/jpeg',
                            'image/png',
                            'image/webp',
                            'image/heic',
                            'image/heif',
                        ])
                        ->maxSize(20480)
                        ->extraInputAttributes([
                            'accept'  => 'image/*',
                            'capture' => 'environment', // 'user' = cámara frontal, 'environment' = trasera
                        ])
                        ->disk('local'),
                ]),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(self::components());
    }
}
