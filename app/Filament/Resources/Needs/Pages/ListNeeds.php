<?php

namespace App\Filament\Resources\Needs\Pages;

use App\Filament\Resources\Needs\NeedResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNeeds extends ListRecords
{
    protected static string $resource = NeedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
