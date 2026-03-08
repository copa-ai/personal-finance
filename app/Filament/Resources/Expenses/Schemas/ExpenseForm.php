<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Enums\ExpenseStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ExpenseForm
{
    public static function components(): array
    {
        return [
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
                        ->prefix('€'),
                    Select::make('status')
                        ->label('Estado')
                        ->options(ExpenseStatus::class)
                        ->default('PAID')
                        ->required(),
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
