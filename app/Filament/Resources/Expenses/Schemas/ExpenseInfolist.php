<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Models\Expense;
use App\Models\User;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
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
                            ->mentionables(fn (Model $record) => User::query()->get())
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
