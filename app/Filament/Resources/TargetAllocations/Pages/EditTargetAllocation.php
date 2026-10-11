<?php

namespace App\Filament\Resources\TargetAllocations\Pages;

use App\Filament\Resources\TargetAllocations\TargetAllocationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTargetAllocation extends EditRecord
{
    protected static string $resource = TargetAllocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
