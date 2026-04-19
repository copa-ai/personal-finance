<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schema\Components\Group;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use \Filament\Forms\Components\FileUpload;

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
                        ->required()
                        ->live()
                        ->afterStateUpdated(static function (Set $set, Get $get, mixed $state): void {
                            if (! $get('create_single_item')) {
                                return;
                            }

                            if ($get('single_item.concept_manually_set')) {
                                return;
                            }

                            $set('single_item.concept', $state);
                        }),
                    DateTimePicker::make('date')
                        ->label('Fecha y Hora')
                        ->default(now())
                        ->required(),
                    TextInput::make('total')
                        ->label('Total (€)')
                        ->numeric()
                        ->prefix('€')
                        ->required(fn (Get $get): bool => (bool) $get('create_single_item'))
                        ->live()
                        ->afterStateUpdated(static function (Set $set, Get $get, mixed $state): void {
                            if (! $get('create_single_item')) {
                                return;
                            }

                            $quantity = $get('single_item.quantity');
                            $quantity = is_numeric((string) $quantity) && ((float) $quantity > 0) ? (float) $quantity : 1.0;
                            $total = is_numeric((string) $state) ? (float) str_replace(',', '.', (string) $state) : null;

                            if ($total === null) {
                                return;
                            }

                            $set('single_item.total_price', number_format($total, 2, '.', ''));
                            $set('single_item.unit_price', number_format($total / $quantity, 2, '.', ''));
                        }),
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
                        ->live()
                        ->afterStateUpdated(static function (Set $set, Get $get, mixed $state): void {
                            if (! $state) {
                                $set('single_item', null);

                                return;
                            }

                            $establishment = $get('establishment');
                            $total = $get('total');
                            $quantity = $get('single_item.quantity');
                            $quantity = is_numeric((string) $quantity) && ((float) $quantity > 0) ? (float) $quantity : 1.0;
                            $total = is_numeric((string) $total) ? (float) str_replace(',', '.', (string) $total) : 0.0;

                            $set('single_item.quantity', number_format($quantity, 3, '.', ''));
                            $set('single_item.total_price', number_format($total, 2, '.', ''));
                            $set('single_item.unit_price', number_format($total / $quantity, 2, '.', ''));
                            $set('single_item.item_type', 'VARIABLE_IRREGULAR');
                            $set('single_item.recurrence', 'NONE');
                            $set('single_item.is_consumable', true);
                            $set('single_item.concept_manually_set', filled($get('single_item.concept')));

                            if (! $get('single_item.concept_manually_set')) {
                                $set('single_item.concept', $establishment);
                            }
                        })
                        ->columnSpanFull(),
                    FileUpload::make('ticket_photo_hash')
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
                    Group::make()
                        ->schema(ExpenseItemForm::components(statePath: 'single_item'))
                        ->statePath('single_item')
                        ->visible(fn (Get $get): bool => (bool) $get('create_single_item'))
                        ->columnSpanFull(),
                ]),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(self::components());
    }
}
