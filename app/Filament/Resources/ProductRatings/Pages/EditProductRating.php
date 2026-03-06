<?php

namespace App\Filament\Resources\ProductRatings\Pages;

use App\Filament\Resources\ProductRatings\ProductRatingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProductRating extends EditRecord
{
    protected static string $resource = ProductRatingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
