<?php

namespace App\Filament\Resources\ProductRatings\Schemas;

use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Kirschbaum\Commentions\Filament\Infolists\Components\CommentsEntry;

class ProductRatingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Detalles')
                    ->schema([
                        TextEntry::make('product.name')
                            ->label('Producto'),
                        TextEntry::make('quality_rating')
                            ->label('Calidad'),
                        TextEntry::make('value_rating')
                            ->label('Relacion Calidad-Precio'),
                        TextEntry::make('comment')
                            ->label('Comentario')
                            ->columnSpanFull(),
                        TextEntry::make('expenseItem.concept')
                            ->label('Linea de Gasto'),
                        TextEntry::make('created_at')
                            ->label('Creado')
                            ->dateTime(),
                    ]),
                Section::make('Comentarios')
                    ->components([
                        CommentsEntry::make('comments')
                            ->mentionables(fn (Model $record) => User::query()->get()),
                    ]),
            ]);
    }
}
