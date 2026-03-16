<?php

namespace App\Filament\Resources\OcrJobs\Pages;

use App\Filament\Resources\OcrJobs\OcrJobResource;
use Filament\Resources\Pages\ListRecords;

class ListOcrJobs extends ListRecords
{
    protected static string $resource = OcrJobResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
