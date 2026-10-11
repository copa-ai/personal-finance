<?php

namespace App\Filament\Widgets\Markets;

use App\Services\MarketData\MarketQuotes;
use Filament\Widgets\Widget;

class SymbolQuote extends Widget
{
    public string $symbol;

    protected string $view = 'filament.widgets.symbol-quote';

    protected int | string | array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $result = app(MarketQuotes::class)->get([$this->symbol]);

        return [
            'quote' => $result['quotes'][$this->symbol] ?? null,
            'error' => $result['error'] ?? ($result['errors'][$this->symbol] ?? null),
        ];
    }
}
