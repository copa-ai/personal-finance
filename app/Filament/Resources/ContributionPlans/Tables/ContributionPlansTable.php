<?php

namespace App\Filament\Resources\ContributionPlans\Tables;

use App\Enums\ContributionFrequency;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ContributionPlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('next_due_on')
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('platform.name')
                    ->label('Plataforma')
                    ->sortable(),
                TextColumn::make('instrument.name')
                    ->label('Instrumento')
                    ->placeholder('A elegir'),
                TextColumn::make('amount')
                    ->label('Importe')
                    ->money(fn ($record): string => $record->currency)
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('frequency')
                    ->label('Frecuencia')
                    ->badge(),
                TextColumn::make('next_due_on')
                    ->label('Próxima')
                    ->date('d/m/Y')
                    ->sortable(),
                ToggleColumn::make('active')
                    ->label('Activa'),
            ])
            ->filters([
                SelectFilter::make('frequency')
                    ->label('Frecuencia')
                    ->options(ContributionFrequency::class),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
