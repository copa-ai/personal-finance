<?php

namespace App\Filament\Widgets\Investments;

use App\Services\Portfolio\PortfolioSummary;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class AllocationTable extends TableWidget
{
    protected static ?string $heading = 'Asignación frente al objetivo';

    public function table(Table $table): Table
    {
        $threshold = (float) config('investments.rebalance_threshold_pp');

        return $table
            ->records(fn (): array => collect(PortfolioSummary::current()['allocation'])
                ->keyBy(fn (array $row): string => $row['asset_class']->value)
                ->all())
            ->description("Se avisa cuando una clase se desvía {$threshold} puntos o más. Los objetivos se definen en Inversiones → Asignación objetivo.")
            ->columns([
                TextColumn::make('asset_class')->label('Clase')->badge(),
                TextColumn::make('value_eur')->label('Valor')->money('EUR', locale: 'es')->alignEnd(),
                TextColumn::make('pct')->label('Actual')->suffix(' %')->numeric(decimalPlaces: 1, locale: 'es')->alignEnd(),
                TextColumn::make('target_pct')->label('Objetivo')->suffix(' %')->numeric(decimalPlaces: 1, locale: 'es')->placeholder('—')->alignEnd(),
                TextColumn::make('drift_pp')
                    ->label('Desviación')
                    ->formatStateUsing(fn (?float $state): string => $state === null ? '—' : PortfolioStats::pct($state) . ' pp')
                    ->color(fn (?float $state): string => $state !== null && abs($state) >= $threshold ? 'danger' : 'gray')
                    ->alignEnd(),
            ])
            ->paginated(false);
    }
}
