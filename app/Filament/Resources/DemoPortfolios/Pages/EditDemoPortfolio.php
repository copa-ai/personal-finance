<?php

namespace App\Filament\Resources\DemoPortfolios\Pages;

use App\Filament\Resources\DemoPortfolios\DemoPortfolioResource;
use App\Services\Demo\DemoTradeException;
use App\Services\Demo\DemoTradeService;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;

class EditDemoPortfolio extends EditRecord
{
    protected static string $resource = DemoPortfolioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Cambiar puntos iniciales o fecha de inicio no puede dejar operaciones existentes fuera de la línea temporal.
     */
    protected function beforeSave(): void
    {
        $portfolio = $this->getRecord()->replicate();
        $portfolio->id = $this->getRecord()->id;
        $portfolio->exists = true;
        $portfolio->fill($this->data);

        try {
            $first = $this->getRecord()->trades()->min('date');
            if ($first !== null && Carbon::parse($first)->lt(Carbon::parse($portfolio->start_date))) {
                throw new DemoTradeException('La fecha de inicio es posterior a operaciones existentes ('.Carbon::parse($first)->toDateString().').');
            }
            app(DemoTradeService::class)->assertTimeline($portfolio);
        } catch (DemoTradeException $e) {
            Notification::make()->title('No se puede guardar')->body($e->getMessage())->danger()->send();
            $this->halt();
        }
    }
}
