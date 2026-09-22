<?php

namespace App\Filament\Resources\Assets\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AssetDocumentForm
{
    public static function components(): array
    {
        return [
            TextInput::make('title')
                ->label('Título')
                ->helperText('P. ej. "Contrato de apertura" o "Folleto informativo 2025".')
                ->required()
                ->maxLength(150),
            DatePicker::make('issued_at')
                ->label('Fecha del documento'),
            FileUpload::make('file_path')
                ->label('Archivo')
                ->directory('asset-documents')
                ->disk('local')
                ->acceptedFileTypes([
                    'application/pdf',
                    'image/jpeg',
                    'image/png',
                    'image/webp',
                ])
                ->maxSize(20480)
                ->columnSpanFull(),
            Textarea::make('notes')
                ->label('Notas')
                ->columnSpanFull(),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(self::components());
    }
}
