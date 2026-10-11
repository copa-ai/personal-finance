<?php

namespace App\Filament\Resources\Instruments\Pages;

use App\Filament\Resources\Instruments\InstrumentResource;
use App\Filament\Resources\Instruments\Tables\InstrumentsTable;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditInstrument extends EditRecord
{
    protected static string $resource = InstrumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InstrumentsTable::refreshPricesAction()
                ->visible(fn (): bool => filled($this->getRecord()->market_symbol)),
            DeleteAction::make(),
        ];
    }
}
