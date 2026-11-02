<?php

namespace App\Filament\Resources\DemoPortfolios\Tables;

use App\Models\DemoPortfolio;
use App\Services\Demo\DemoValuationService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

class DemoPortfoliosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('initial_points')
                    ->label('Puntos iniciales')
                    ->numeric(2)
                    ->alignEnd(),
                TextColumn::make('start_date')
                    ->label('Inicio')
                    ->date()
                    ->sortable(),
                TextColumn::make('trades_count')
                    ->label('Operaciones')
                    ->counts('trades')
                    ->alignEnd(),
                TextColumn::make('benchmarkInstrument.symbol')
                    ->label('Referencia')
                    ->placeholder('—'),
                TextColumn::make('pnl')
                    ->label('P&L')
                    ->state(function (DemoPortfolio $record): ?string {
                        try {
                            $summary = app(DemoValuationService::class)->summary($record);
                        } catch (Throwable) {
                            return null;
                        }

                        return number_format($summary['pnl'], 2, ',', '.').' ('.number_format($summary['pnl_percent'], 2, ',', '.').' %)';
                    })
                    ->placeholder('—')
                    ->alignEnd(),
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
