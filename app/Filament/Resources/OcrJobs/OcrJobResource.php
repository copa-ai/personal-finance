<?php

namespace App\Filament\Resources\OcrJobs;

use App\Filament\Resources\OcrJobs\Pages\ListOcrJobs;
use App\Filament\Resources\OcrJobs\Tables\OcrJobsTable;
use App\Models\OcrJob;
use Filament\Resources\Resource;
use Filament\Tables\Table;

class OcrJobResource extends Resource
{
    protected static ?string $model = OcrJob::class;

    protected static ?string $modelLabel = 'Proceso OCR';
    protected static ?string $pluralModelLabel = 'Procesos de IA';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';
    protected static ?string $navigationLabel = 'Procesos de IA';

    // protected static string|\UnitEnum|null $navigationGroup = 'IA';

    public static function table(Table $table): Table
    {
        return OcrJobsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOcrJobs::route('/'),
        ];
    }
}
