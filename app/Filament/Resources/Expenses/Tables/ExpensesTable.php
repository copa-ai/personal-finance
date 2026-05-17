<?php

namespace App\Filament\Resources\Expenses\Tables;

use App\Models\Expense;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Columns\Summarizers\Sum;
use Illuminate\Database\Eloquent\Model;
use Kirschbaum\Commentions\Filament\Actions\CommentsAction;

class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('ID'),
                TextColumn::make('establishment')
                    ->label('Establecimiento')
                    ->searchable(),
                TextColumn::make('date')
                    ->label('Fecha y Hora')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('total')
                    ->label('Total')
                    ->local_money()
                    ->summarize(Sum::make())
                    ->sortable(),
                TextColumn::make('ticket_photo_hash')
                    ->label('Foto del Ticket')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? 'Sí' : 'No')
                    ->badge()
                    ->color(fn (?string $state): string => filled($state) ? 'success' : 'gray'),
                TextColumn::make('pending_review')
                    ->label('Pendiente de Revisar')
                    ->getStateUsing(fn (Expense $record): bool => $record->pending_review || $record->hasPendingSubexpenses())
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Sí' : 'No')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray'),
                TextColumn::make('created_at')
                    ->label('Creado el')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge(),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('status')
                    ->options(\App\Enums\ExpenseStatus::class)
                    ->label('Estado'),
                Filter::make('pending_subexpenses')
                    ->label('Subgastos pendientes')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->withPendingSubexpenses())
                    ->indicator('Subgastos pendientes'),
                Filter::make('pending_review')
                    ->label('Pendiente de revisar')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('pending_review', true))
                    ->indicator('Pendiente de revisar'),
                \Filament\Tables\Filters\Filter::make('date')
                    ->label('Fecha')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('created_from')->label('Desde'),
                        \Filament\Forms\Components\DatePicker::make('created_until')->label('Hasta'),
                    ])
                    ->query(function (\Illuminate\Database\Eloquent\Builder $query, array $data): \Illuminate\Database\Eloquent\Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (\Illuminate\Database\Eloquent\Builder $query, $date): \Illuminate\Database\Eloquent\Builder => $query->whereDate('date', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (\Illuminate\Database\Eloquent\Builder $query, $date): \Illuminate\Database\Eloquent\Builder => $query->whereDate('date', '<=', $date),
                            );
                    }),
                \Filament\Tables\Filters\TernaryFilter::make('has_ticket_photo')
                    ->label('¿Tiene Foto del Ticket?')
                    ->queries(
                        true: fn (\Illuminate\Database\Eloquent\Builder $query) => $query->whereNotNull('ticket_photo_hash'),
                        false: fn (\Illuminate\Database\Eloquent\Builder $query) => $query->whereNull('ticket_photo_hash'),
                        blank: fn (\Illuminate\Database\Eloquent\Builder $query) => $query,
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                CommentsAction::make()
                    ->mentionables(fn (Model $record) => User::query()->get()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
