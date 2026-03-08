<?php

namespace App\Filament\Resources\ProductRatings;

use App\Filament\Resources\ProductRatings\Pages\CreateProductRating;
use App\Filament\Resources\ProductRatings\Pages\EditProductRating;
use App\Filament\Resources\ProductRatings\Pages\ListProductRatings;
use App\Filament\Resources\ProductRatings\Pages\ViewProductRating;
use App\Filament\Resources\ProductRatings\Schemas\ProductRatingForm;
use App\Filament\Resources\ProductRatings\Schemas\ProductRatingInfolist;
use App\Filament\Resources\ProductRatings\Tables\ProductRatingsTable;
use App\Models\ProductRating;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class ProductRatingResource extends Resource
{
    protected static ?string $model = ProductRating::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-star';

    protected static ?string $modelLabel = 'Valoración';

    protected static ?string $pluralModelLabel = 'Valoraciones';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return ProductRatingForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProductRatingInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductRatingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductRatings::route('/'),
            'create' => CreateProductRating::route('/create'),
            'view' => ViewProductRating::route('/{record}'),
            'edit' => EditProductRating::route('/{record}/edit'),
        ];
    }
}
