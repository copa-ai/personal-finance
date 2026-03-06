<?php

namespace App\Filament\Resources\ProductRatings\Pages;

use App\Filament\Resources\ProductRatings\ProductRatingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductRating extends CreateRecord
{
    protected static string $resource = ProductRatingResource::class;
}
