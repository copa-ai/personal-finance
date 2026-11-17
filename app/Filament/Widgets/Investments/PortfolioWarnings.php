<?php

namespace App\Filament\Widgets\Investments;

use App\Services\Portfolio\PortfolioSummary;
use Filament\Widgets\Widget;

class PortfolioWarnings extends Widget
{
    protected string $view = 'filament.widgets.portfolio-warnings';

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return PortfolioSummary::current()['warnings'] !== [];
    }

    protected function getViewData(): array
    {
        return ['warnings' => array_values(array_unique(PortfolioSummary::current()['warnings']))];
    }
}
