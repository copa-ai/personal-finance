<?php

namespace App\Filament\Resources\ProductRatings\Pages;

use App\Filament\Resources\ProductRatings\ProductRatingResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProductRating extends ViewRecord
{
    protected static string $resource = ProductRatingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
