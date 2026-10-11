<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\SearchesMarketSymbols;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\MarketData\MarketQuotes;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use UnitEnum;

class Markets extends Page
{
    use SearchesMarketSymbols;

    protected static ?string $slug = 'mercados';

    protected static ?string $title = 'Mercados';

    protected static ?string $navigationLabel = 'Seguimiento';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static string|UnitEnum|null $navigationGroup = 'Mercados';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.markets';

    public function getSubheading(): ?string
    {
        return 'Cotizaciones con unos minutos de retraso. Pulsa un símbolo para ver su gráfico.';
    }

    protected function getViewData(): array
    {
        $watchlists = Watchlist::query()->with('items')->orderBy('position')->orderBy('name')->get();

        return [
            'watchlists' => $watchlists,
            'data' => app(MarketQuotes::class)->get($watchlists->flatMap->items->pluck('market_symbol')->all()),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addSymbol')
                ->label('Añadir símbolo')
                ->icon('heroicon-o-plus')
                ->modalWidth(Width::Large)
                ->schema(fn (): array => [
                    Select::make('watchlist_id')
                        ->label('Lista')
                        ->options(Watchlist::query()->orderBy('position')->pluck('name', 'id'))
                        ->default(Watchlist::query()->orderBy('position')->value('id'))
                        ->required(),
                    ...$this->marketSymbolFields(),
                    TextInput::make('label')
                        ->label('Nombre a mostrar')
                        ->maxLength(80),
                ])
                ->action(function (array $data): void {
                    $symbol = $this->pickedSymbol($data);

                    WatchlistItem::query()->firstOrCreate(
                        ['watchlist_id' => $data['watchlist_id'], 'market_symbol' => $symbol],
                        [
                            'label' => $data['label'] ?: null,
                            'position' => (int) WatchlistItem::query()->where('watchlist_id', $data['watchlist_id'])->max('position') + 1,
                        ],
                    );

                    Notification::make()->success()->title("Añadido {$symbol}")->send();
                }),
            Action::make('newWatchlist')
                ->label('Nueva lista')
                ->icon('heroicon-o-queue-list')
                ->color('gray')
                ->schema([
                    TextInput::make('name')->label('Nombre')->required()->maxLength(80),
                ])
                ->action(fn (array $data) => Watchlist::query()->create([
                    'name' => $data['name'],
                    'position' => (int) Watchlist::query()->max('position') + 1,
                ])),
        ];
    }

    public function removeItemAction(): Action
    {
        return Action::make('removeItem')
            ->label('Quitar')
            ->icon('heroicon-m-x-mark')
            ->iconButton()
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('¿Quitar de la lista?')
            ->action(fn (array $arguments) => WatchlistItem::query()->whereKey($arguments['item'] ?? null)->delete());
    }

    public function deleteWatchlistAction(): Action
    {
        return Action::make('deleteWatchlist')
            ->label('Borrar lista')
            ->icon('heroicon-m-trash')
            ->link()
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('¿Borrar la lista y sus símbolos?')
            ->action(fn (array $arguments) => Watchlist::query()->whereKey($arguments['watchlist'] ?? null)->delete());
    }
}
