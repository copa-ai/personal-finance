<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Models\User;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Kirschbaum\Commentions\Filament\Infolists\Components\CommentsEntry;

class ExpenseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Detalles del Gasto')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('id')
                            ->label('ID'),
                        TextEntry::make('establishment')
                            ->label('Establecimiento'),
                        TextEntry::make('date')
                            ->label('Fecha y Hora')
                            ->dateTime(),
                        TextEntry::make('status')
                            ->label('Estado')
                            ->badge(),
                        TextEntry::make('total')
                            ->label('Total')
                            ->money('EUR'),
                        ImageEntry::make('ticket_photo_hash')
                            ->label('Foto del Ticket')
                            ->disk('local')
                            ->visibility('private')
                            ->columnSpanFull(),
                    ]),
                Section::make('Comentarios')
                    ->components([
                        CommentsEntry::make('comments')
                            ->mentionables(fn (Model $record) => User::query()->get()),
                    ]),
            ]);
    }
}
