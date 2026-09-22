<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\StockTable;
use Filament\Pages\Page;

class Stock extends Page
{
    protected static string $routePath = '/stock';

    protected static ?string $title = 'Stock';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'Stock';

    protected static ?int $navigationSort = 3;

    protected function getFooterWidgets(): array
    {
        return [
            StockTable::class,
        ];
    }
}
