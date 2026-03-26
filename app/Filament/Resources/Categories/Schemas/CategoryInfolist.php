<?php

namespace App\Filament\Resources\Categories\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CategoryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Detalles')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')
                            ->label('Nombre'),
                        TextEntry::make('parent.name')
                            ->label('Categoría Padre')
                            ->formatStateUsing(fn (?string $state): string => $state ?: 'Sin padre'),
                        TextEntry::make('created_at')
                            ->label('Creada')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->label('Actualizada')
                            ->dateTime(),
                    ]),
            ]);
    }
}
