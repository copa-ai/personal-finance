<?php

namespace App\Filament\Resources\Needs\Pages;

use App\Filament\Resources\Needs\NeedResource;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Kirschbaum\Commentions\Filament\Actions\CommentsAction;

class ViewNeed extends ViewRecord
{
    protected static string $resource = NeedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            CommentsAction::make()->mentionables(User::query()->get()),
        ];
    }
}
