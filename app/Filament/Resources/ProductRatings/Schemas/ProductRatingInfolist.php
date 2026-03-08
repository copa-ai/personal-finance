<?php

namespace App\Filament\Resources\ProductRatings\Schemas;

use App\Models\User;
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
                Section::make('Comentarios')
                    ->components([
                        CommentsEntry::make('comments')
                            ->mentionables(fn (Model $record) => User::query()->get()),
                    ]),
            ]);
    }
}
