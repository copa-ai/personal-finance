<?php

namespace App\Filament\Resources\Instruments\RelationManagers;

use App\Filament\Resources\InvestmentTransactions\Tables\InvestmentTransactionsTable;
use App\Models\InstrumentPrice;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PricesRelationManager extends RelationManager
{
    protected static string $relationship = 'prices';

    protected static ?string $title = 'Precios';

    protected static ?string $modelLabel = 'Precio';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('date')
                ->label('Fecha')
                ->default(now(config('app.display_timezone'))->toDateString())
                ->required(),
            TextInput::make('price')
                ->label('Precio por unidad')
                ->numeric()
                ->step('any')
                ->minValue(0)
                ->required(),
            TextInput::make('currency')
                ->label('Divisa')
                ->default(fn (): string => $this->getOwnerRecord()->currency ?? 'EUR')
                ->length(3)
                ->dehydrateStateUsing(fn (?string $state): string => strtoupper((string) $state))
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('price')
                    ->label('Precio')
                    ->formatStateUsing(fn (?string $state): string => InvestmentTransactionsTable::trimDecimals($state))
                    ->alignEnd(),
                TextColumn::make('currency')
                    ->label('Divisa')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('source')
                    ->label('Fuente')
                    ->badge()
                    ->color(fn (string $state): string => $state === InstrumentPrice::SOURCE_MANUAL ? 'primary' : 'gray'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Apuntar precio')
                    // Los precios que se apuntan aquí son siempre manuales.
                    ->mutateDataUsing(fn (array $data): array => [...$data, 'source' => InstrumentPrice::SOURCE_MANUAL]),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (InstrumentPrice $record): bool => $record->source === InstrumentPrice::SOURCE_MANUAL),
                DeleteAction::make(),
            ]);
    }
}
