<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Kirschbaum\Commentions\Filament\Infolists\Components\CommentsEntry;

class ProductInfolist
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
                            TextEntry::make('category.name')
                                ->label('Necesidad'),
                            TextEntry::make('brand')
                                ->label('Marca'),
                            TextEntry::make('variant')
                                ->label('Variante')
                                ->afterLabel(Schema::start([
                                    Icon::make(Heroicon::QuestionMarkCircle)
                                        ->tooltip('Especifica la variante del producto, por ejemplo sabor, tamano o modelo.'),
                                ])),
                            TextEntry::make('unit_of_measure')
                                ->label('Unidad'),
                            TextEntry::make('current_quantity')
                                ->label('Cantidad Actual'),
                            TextEntry::make('daily_consumption_rate')
                                ->label('Consumo Diario'),
                            TextEntry::make('target_price')
                                ->label('Precio Objetivo')
                                ->afterLabel(Schema::start([
                                    Icon::make(Heroicon::QuestionMarkCircle)
                                        ->tooltip('Indica el precio por UD, KG, L...'),
                                ]))
                                ->money('EUR'),
                            TextEntry::make('active')
                                ->label('Activo')
                                ->afterLabel(Schema::start([
                                    Icon::make(Heroicon::QuestionMarkCircle)
                                        ->tooltip('Indica si el producto está disponible (activo) o archivado (inactivo). Los productos inactivos se mantienen para el historial.'),
                                ]))
                                ->badge()
                                ->formatStateUsing(fn (?bool $state): string => $state ? 'Si' : 'No'),
                            TextEntry::make('notes')
                                ->label('Notas')
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
