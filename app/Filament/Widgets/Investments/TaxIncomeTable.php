<?php

namespace App\Filament\Widgets\Investments;

use App\Services\Portfolio\PortfolioService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class TaxIncomeTable extends TableWidget
{
    public int $year;

    protected static ?string $heading = 'Rendimientos';

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => collect(app(PortfolioService::class)->taxYear($this->year)['income'])
                ->keyBy('transaction_id')
                ->all())
            ->columns([
                TextColumn::make('date')->label('Fecha')->date('d/m/Y'),
                TextColumn::make('type')->label('Tipo')->badge(),
                TextColumn::make('instrument')->label('Instrumento'),
                TextColumn::make('platform')->label('Plataforma'),
                TextColumn::make('amount_eur')->label('Importe')->money('EUR', locale: 'es')->alignEnd(),
            ])
            ->paginated(false)
            ->emptyStateHeading('Sin rendimientos este año');
    }
}
