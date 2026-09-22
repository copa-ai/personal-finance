<?php

namespace App\Filament\Resources\BankAccounts\RelationManagers;

use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Assets\Schemas\AssetForm;
use App\Filament\Resources\Assets\Tables\AssetsTable;
use App\Models\Asset;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class AssetsRelationManager extends RelationManager
{
    protected static string $relationship = 'assets';

    protected static ?string $title = 'Activos';

    public function form(Schema $schema): Schema
    {
        return AssetForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return AssetsTable::configure($table, includeBankAccountColumn: false)
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                Action::make('viewAsset')
                    ->label('Ver')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Asset $record): string => AssetResource::getUrl('view', ['record' => $record])),
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ]);
    }
}
