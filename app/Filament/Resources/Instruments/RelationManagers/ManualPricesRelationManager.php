<?php

namespace App\Filament\Resources\Instruments\RelationManagers;

use App\Models\Instrument;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Precios apuntados a mano de un instrumento manual: cada uno es una vela diaria con open = high = low = close.
 */
class ManualPricesRelationManager extends RelationManager
{
    protected static string $relationship = 'priceBars';

    protected static ?string $title = 'Precios';

    protected static ?string $modelLabel = 'Precio';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Instrument && $ownerRecord->isManual();
    }

    /** @return array<string, mixed> */
    private static function flat(array $data): array
    {
        return [...$data, 'open' => $data['close'], 'high' => $data['close'], 'low' => $data['close']];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('date')
                ->label('Fecha')
                ->default(now(config('app.display_timezone'))->toDateString())
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('instrument_id', $this->getOwnerRecord()->getKey()))
                ->required(),
            TextInput::make('close')
                ->label('Precio por unidad')
                ->helperText(fn (): string => 'En '.($this->getOwnerRecord()->currency ?? 'EUR').'.')
                ->numeric()
                ->step('any')
                ->minValue(0)
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
                TextColumn::make('close')
                    ->label('Precio')
                    ->formatStateUsing(fn (?string $state): string => rtrim(rtrim((string) $state, '0'), '.'))
                    ->alignEnd(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Apuntar precio')
                    ->mutateDataUsing(fn (array $data): array => self::flat($data)),
            ])
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data): array => self::flat($data)),
                DeleteAction::make(),
            ]);
    }
}
