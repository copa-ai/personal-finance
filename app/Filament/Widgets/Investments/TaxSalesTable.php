<?php

namespace App\Filament\Widgets\Investments;

use App\Services\Portfolio\PortfolioService;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class TaxSalesTable extends TableWidget
{
    public int $year;

    protected static ?string $heading = 'Ventas';

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => collect(app(PortfolioService::class)->taxYear($this->year)['sales'])
                ->keyBy('transaction_id')
                ->all())
            ->description('Coste de adquisición por FIFO, con comisiones de compra y venta incluidas.')
            ->columns([
                TextColumn::make('date')->label('Venta')->date('d/m/Y'),
                TextColumn::make('instrument')->label('Instrumento')->description(fn (array $record): ?string => $record['isin']),
                TextColumn::make('platform')->label('Plataforma'),
                TextColumn::make('acquired_from')->label('Adquirido desde')->date('d/m/Y'),
                TextColumn::make('quantity')->label('Cantidad')
                    ->formatStateUsing(fn (string $state): string => rtrim(rtrim(number_format((float) $state, 8, ',', '.'), '0'), ','))
                    ->alignEnd(),
                TextColumn::make('proceeds_eur')->label('Transmisión')->money('EUR', locale: 'es')->alignEnd(),
                TextColumn::make('cost_eur')->label('Adquisición')->money('EUR', locale: 'es')->alignEnd(),
                TextColumn::make('gain_eur')->label('Resultado')->money('EUR', locale: 'es')
                    ->color(fn (string $state): string => (float) $state >= 0 ? 'success' : 'danger')
                    ->weight('bold')
                    ->alignEnd(),
                IconColumn::make('two_month_rule')
                    ->label('Regla 2 meses')
                    ->tooltip('Pérdida con recompra del mismo valor en los dos meses anteriores o posteriores: puede no ser compensable este año.')
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('warning')
                    ->falseIcon('heroicon-o-minus')
                    ->falseColor('gray'),
            ])
            ->paginated(false)
            ->emptyStateHeading('Sin ventas este año');
    }
}
