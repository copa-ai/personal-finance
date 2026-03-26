<?php

namespace App\Filament\Resources\Needs\Pages;

use App\Filament\Resources\Needs\NeedResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNeed extends EditRecord
{
    protected static string $resource = NeedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
