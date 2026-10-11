<?php

namespace App\Filament\Widgets\Investments;

use App\Enums\AssetClass;
use App\Services\Portfolio\PortfolioSummary;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class PositionsTable extends TableWidget
{
    protected static ?string $heading = 'Posiciones';

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $sortColumn, ?string $sortDirection, ?string $search, array $filters): array {
                $rows = collect(PortfolioSummary::current()['positions'])
                    ->map(fn (array $p): array => [
                        ...$p,
                        'asset_class_value' => $p['asset_class']->value,
                        'price_label' => $p['price'] === null ? null : rtrim(rtrim(number_format((float) $p['price'], 6, ',', '.'), '0'), ',') . ' ' . $p['price_currency'],
                    ])
                    ->keyBy(fn (array $p): string => $p['platform_id'] . '|' . $p['instrument_id']);

                if (filled($search)) {
                    $rows = $rows->filter(fn (array $p): bool => str_contains(mb_strtolower($p['instrument'] . ' ' . $p['platform']), mb_strtolower($search)));
                }

                if (filled($class = $filters['asset_class']['value'] ?? null)) {
                    $rows = $rows->where('asset_class_value', $class);
                }

                if (filled($platform = $filters['platform']['value'] ?? null)) {
                    $rows = $rows->where('platform', $platform);
                }

                if ($sortColumn) {
                    $rows = $rows->sortBy(
                        fn (array $p) => is_numeric($p[$sortColumn] ?? null) ? (float) $p[$sortColumn] : ($p[$sortColumn] ?? ''),
                        SORT_REGULAR,
                        $sortDirection === 'desc',
                    );
                }

                return $rows->all();
            })
            ->searchable()
            ->columns([
                TextColumn::make('instrument')
                    ->label('Instrumento')
                    ->description(fn (array $record): ?string => $record['market_symbol'])
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('platform')
                    ->label('Plataforma')
                    ->sortable(),
                TextColumn::make('asset_class')
                    ->label('Clase')
                    ->badge(),
                TextColumn::make('quantity')
                    ->label('Cantidad')
                    ->formatStateUsing(fn (string $state): string => rtrim(rtrim(number_format((float) $state, 8, ',', '.'), '0'), ','))
                    ->alignEnd(),
                TextColumn::make('price_label')
                    ->label('Precio')
                    ->description(fn (array $record): ?string => match (true) {
                        $record['valued_at_cost'] => 'sin precio: a coste',
                        $record['price_age_days'] === null => null,
                        default => "{$record['price_source']} · hace {$record['price_age_days']} d",
                    })
                    ->placeholder('—')
                    ->alignEnd(),
                TextColumn::make('cost_eur')
                    ->label('Coste')
                    ->money('EUR', locale: 'es')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('value_eur')
                    ->label('Valor')
                    ->money('EUR', locale: 'es')
                    ->sortable()
                    ->weight('bold')
                    ->alignEnd(),
                TextColumn::make('unrealized_eur')
                    ->label('Plusvalía')
                    ->money('EUR', locale: 'es')
                    ->description(fn (array $record): ?string => $record['unrealized_pct'] === null ? null : PortfolioStats::pct($record['unrealized_pct']))
                    ->color(fn (string $state): string => (float) $state >= 0 ? 'success' : 'danger')
                    ->sortable()
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('asset_class')
                    ->label('Clase de activo')
                    ->options(AssetClass::class),
                SelectFilter::make('platform')
                    ->label('Plataforma')
                    ->options(fn (): array => collect(PortfolioSummary::current()['positions'])->pluck('platform', 'platform')->all()),
            ])
            ->defaultSort('value_eur', 'desc')
            ->paginated(false)
            ->emptyStateHeading('Aún no hay posiciones')
            ->emptyStateDescription('Apunta una compra o un ajuste de saldo en Inversiones → Movimientos.');
    }
}
