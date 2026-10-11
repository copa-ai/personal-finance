<?php

namespace App\Filament\Resources\TargetAllocations\Tables;

use App\Models\TargetAllocation;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TargetAllocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('target_pct', 'desc')
            ->description(function (): ?string {
                $total = (float) TargetAllocation::query()->sum('target_pct');

                if ($total === 0.0 || abs($total - 100) < 0.005) {
                    return 'La cartera avisa cuando una clase se desvía del objetivo más de lo configurado (INVESTMENTS_REBALANCE_THRESHOLD_PP).';
                }

                return sprintf('⚠ Los objetivos suman %s %%: deberían sumar 100 %%.', number_format($total, 2, ',', '.'));
            })
            ->columns([
                TextColumn::make('asset_class')
                    ->label('Clase de activo')
                    ->badge(),
                TextColumn::make('target_pct')
                    ->label('Objetivo')
                    ->suffix(' %')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
