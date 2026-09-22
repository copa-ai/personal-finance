<?php

namespace App\Filament\Resources\BankAccounts\Schemas;

use App\Models\BankAccount;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BankAccountInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Detalles de la cuenta')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')
                            ->label('Nombre'),
                        TextEntry::make('bank_name')
                            ->label('Entidad bancaria'),
                        TextEntry::make('iban')
                            ->label('IBAN')
                            ->placeholder('Sin IBAN'),
                        TextEntry::make('currency')
                            ->label('Moneda'),
                        TextEntry::make('total_value')
                            ->label('Valor total de los activos')
                            ->state(fn (BankAccount $record): string => $record->total_value)
                            ->money('EUR'),
                        TextEntry::make('notes')
                            ->label('Notas')
                            ->placeholder('Sin notas')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
