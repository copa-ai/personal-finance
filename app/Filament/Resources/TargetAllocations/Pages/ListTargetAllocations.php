<?php

namespace App\Filament\Resources\TargetAllocations\Pages;

use App\Filament\Resources\TargetAllocations\TargetAllocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTargetAllocations extends ListRecords
{
    protected static string $resource = TargetAllocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
