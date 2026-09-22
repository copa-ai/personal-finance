<?php

namespace App\Filament\Resources\Assets\Tables;

use App\Models\AssetDocument;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class AssetDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('issued_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label('Título')
                    ->searchable(),
                TextColumn::make('issued_at')
                    ->label('Fecha del documento')
                    ->date()
                    ->sortable(),
                TextColumn::make('file_path')
                    ->label('Archivo')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? 'Sí' : 'No')
                    ->badge()
                    ->color(fn (?string $state): string => filled($state) ? 'success' : 'gray'),
                TextColumn::make('created_at')
                    ->label('Añadido el')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Descargar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (AssetDocument $record): bool => filled($record->file_path))
                    ->url(fn (AssetDocument $record): string => Storage::disk('local')->url($record->file_path))
                    ->openUrlInNewTab(),
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
