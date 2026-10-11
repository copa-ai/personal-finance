<?php

namespace App\Filament\Pages;

use App\Enums\PriceAlertCondition;
use App\Filament\Widgets\Markets\SymbolHistoryChart;
use App\Filament\Widgets\Markets\SymbolQuote;
use App\Models\PriceAlert;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\MarketData\MarketQuotes;
use App\Services\MarketData\MarketSymbol;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

class MarketSymbolPage extends Page
{
    protected static ?string $slug = 'mercados/simbolo';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.market-symbol';

    #[Url]
    public string $s = '';

    public function mount(): void
    {
        $this->s = MarketSymbol::normalize($this->s) ?? abort(404);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->quote()['name'] ?? $this->s;
    }

    public function getSubheading(): ?string
    {
        return sprintf('%s · %s', MarketSymbol::KINDS[MarketSymbol::kind($this->s)] ?? '', $this->s);
    }

    public function quote(): ?array
    {
        return once(fn () => app(MarketQuotes::class)->get([$this->s])['quotes'][$this->s] ?? null);
    }

    protected function getViewData(): array
    {
        return ['tradingView' => MarketSymbol::toTradingView($this->s)];
    }

    public function getWidgetData(): array
    {
        return ['symbol' => $this->s];
    }

    protected function getHeaderWidgets(): array
    {
        return [SymbolQuote::class, SymbolHistoryChart::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Mercados')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(Markets::getUrl()),
            Action::make('watch')
                ->label('Añadir a una lista')
                ->icon('heroicon-o-plus')
                ->schema([
                    Select::make('watchlist_id')
                        ->label('Lista')
                        ->options(fn () => Watchlist::query()->orderBy('position')->pluck('name', 'id'))
                        ->required(),
                ])
                ->action(function (array $data): void {
                    WatchlistItem::query()->firstOrCreate(
                        ['watchlist_id' => $data['watchlist_id'], 'market_symbol' => $this->s],
                        ['position' => (int) WatchlistItem::query()->where('watchlist_id', $data['watchlist_id'])->max('position') + 1],
                    );
                    Notification::make()->success()->title('Añadido a la lista')->send();
                }),
            Action::make('alert')
                ->label('Crear alerta')
                ->icon('heroicon-o-bell')
                ->color('gray')
                ->schema(fn (): array => [
                    Select::make('condition')
                        ->label('Cuando')
                        ->options(PriceAlertCondition::class)
                        ->default(PriceAlertCondition::ABOVE->value)
                        ->required(),
                    TextInput::make('threshold')
                        ->label('Valor')
                        ->numeric()
                        ->default($this->quote()['price'] ?? null)
                        ->helperText('Precio en ' . ($this->quote()['currency'] ?? 'la divisa del símbolo') . ', o porcentaje si es variación diaria.')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    PriceAlert::query()->create([...$data, 'market_symbol' => $this->s, 'active' => true]);
                    Notification::make()->success()->title('Alerta creada')->body('Se comprueba cada 15 minutos.')->send();
                }),
        ];
    }
}
