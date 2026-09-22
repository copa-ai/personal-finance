<?php

namespace App\Filament\Resources\Assets\Schemas;

use App\Enums\AssetType;
use App\Filament\Resources\BankAccounts\Schemas\BankAccountForm;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AssetForm
{
    public static function components(bool $includeBankAccountField = false): array
    {
        $components = [];

        if ($includeBankAccountField) {
            $components[] = Select::make('bank_account_id')
                ->label('Cuenta bancaria')
                ->relationship('bankAccount', 'name')
                ->createOptionForm(BankAccountForm::components())
                ->searchable()
                ->preload()
                ->required();
        }

        $components[] = TextInput::make('name')
            ->label('Nombre')
            ->helperText('P. ej. "Fondo indexado MSCI World" o "Depósito a 12 meses".')
            ->required()
            ->maxLength(150);
        $components[] = Select::make('type')
            ->label('Tipo')
            ->options(AssetType::class)
            ->default(AssetType::OTRO->value)
            ->required();
        $components[] = TextInput::make('current_value')
            ->label('Valor actual')
            ->numeric()
            ->prefix('€')
            ->required();
        $components[] = TextInput::make('currency')
            ->label('Moneda')
            ->default('EUR')
            ->required()
            ->maxLength(3);
        $components[] = Textarea::make('notes')
            ->label('Notas')
            ->columnSpanFull();

        return $components;
    }

    public static function configure(Schema $schema, bool $includeBankAccountField = false): Schema
    {
        return $schema
            ->components(self::components($includeBankAccountField));
    }
}
