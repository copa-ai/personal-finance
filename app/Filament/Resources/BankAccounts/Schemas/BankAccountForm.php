<?php

namespace App\Filament\Resources\BankAccounts\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class BankAccountForm
{
    public static function components(): array
    {
        return [
            TextInput::make('name')
                ->label('Nombre')
                ->helperText('Un alias para identificar la cuenta, p. ej. "Nómina Santander".')
                ->required()
                ->maxLength(150),
            TextInput::make('bank_name')
                ->label('Entidad bancaria')
                ->required()
                ->maxLength(150),
            TextInput::make('iban')
                ->label('IBAN')
                ->maxLength(34),
            TextInput::make('currency')
                ->label('Moneda')
                ->default('EUR')
                ->required()
                ->maxLength(3),
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
