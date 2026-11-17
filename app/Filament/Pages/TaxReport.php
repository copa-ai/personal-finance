<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Investments\TaxIncomeTable;
use App\Filament\Widgets\Investments\TaxSalesTable;
use App\Filament\Widgets\Investments\TaxTotals;
use App\Models\InvestmentTransaction;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Livewire\Attributes\Url;
use UnitEnum;

class TaxReport extends Page
{
    protected static ?string $slug = 'fiscal';

    protected static ?string $title = 'Fiscal';

    protected static ?string $navigationLabel = 'Fiscal';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|UnitEnum|null $navigationGroup = 'Inversiones';

    protected static ?int $navigationSort = 90;

    #[Url]
    public ?int $year = null;

    public function mount(): void
    {
        // Por defecto, el año que se declara: el anterior.
        $this->year ??= (int) now(config('app.display_timezone'))->subYear()->year;
    }

    public function getSubheading(): ?string
    {
        return "Ejercicio {$this->year}. Orientativo: revísalo con los datos fiscales de cada plataforma antes de presentar la Renta.";
    }

    public function getWidgetData(): array
    {
        return ['year' => $this->year];
    }

    protected function getHeaderWidgets(): array
    {
        return [TaxTotals::class];
    }

    protected function getFooterWidgets(): array
    {
        return [TaxSalesTable::class, TaxIncomeTable::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('year')
                ->label('Cambiar año')
                ->icon('heroicon-o-calendar')
                ->schema([
                    Select::make('year')
                        ->label('Año')
                        ->options(fn (): array => $this->years())
                        ->default($this->year)
                        ->required(),
                ])
                ->action(fn (array $data) => $this->redirect(static::getUrl(['year' => $data['year']]))),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function years(): array
    {
        $first = InvestmentTransaction::query()->min('date');
        $current = (int) now(config('app.display_timezone'))->year;
        $start = $first ? (int) substr((string) $first, 0, 4) : $current;

        return collect(range($current, min($start, $current)))->mapWithKeys(fn (int $y): array => [$y => $y])->all();
    }
}
