<?php

namespace App\Filament\Resources\Needs\Schemas;

use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Kirschbaum\Commentions\Filament\Infolists\Components\CommentsEntry;

class NeedInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Flex::make([
                    Section::make('Detalles')
                        ->columns(2)
                        ->schema([
                            TextEntry::make('name')
                                ->label('Nombre'),
                            TextEntry::make('priority')
                                ->label('Prioridad')
                                ->badge(),
                            TextEntry::make('created_at')
                                ->label('Creada')
                                ->dateTime(),
                            TextEntry::make('description')
                                ->label('Descripción')
                                ->columnSpanFull(),
                        ])
                        ->grow(),
                    Section::make('Comentarios')
                        ->schema([
                            CommentsEntry::make('comments')
                                ->mentionables(fn (Model $record) => User::query()->get())
                                ->columnSpanFull(),
                        ])
                        ->grow(),
                ])
                    ->from('md')
                    ->columnSpanFull(),
            ]);
    }
}