<?php

namespace App\Filament\Resources\DemoPortfolios\RelationManagers;

use App\Enums\TradeSide;
use App\Models\Instrument;
use App\Models\DemoTrade;
use App\Services\Demo\DemoTradeException;
use App\Services\Demo\DemoTradeService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Text;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

class DemoTradesRelationManager extends RelationManager
{
    protected static string $relationship = 'trades';

    protected static ?string $title = 'Operaciones';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('date')->label('Fecha')->date()->sortable(),
                TextColumn::make('side')->label('Operación')->badge(),
                TextColumn::make('instrument.symbol')->label('Instrumento')->searchable(),
                TextColumn::make('units')->label('Unidades')->numeric(4)->alignEnd(),
                TextColumn::make('price')->label('Precio')->numeric(4)->alignEnd(),
                TextColumn::make('fx_rate')->label('Cambio a EUR')->numeric(6)->alignEnd(),
                TextColumn::make('fee')->label('Comisión')->numeric(2)->alignEnd(),
                TextColumn::make('total_points')->label('Total (puntos)')->numeric(2)->alignEnd(),
            ])
            ->headerActions([
                $this->tradeAction('buy', TradeSide::BUY),
                $this->tradeAction('sell', TradeSide::SELL),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->using(function (DemoTrade $record, DeleteAction $action): bool {
                        try {
                            app(DemoTradeService::class)->delete($record);
                        } catch (DemoTradeException $exception) {
                            $action->failureNotification(
                                Notification::make()->title('No se pudo eliminar la operación')->body($exception->getMessage())->danger(),
                            );

                            return false;
                        }

                        return true;
                    }),
            ]);
    }

    private function tradeAction(string $name, TradeSide $side): Action
    {
        return Action::make($name)
            ->label($side->getLabel())
            ->icon($side === TradeSide::BUY ? 'heroicon-o-arrow-down-tray' : 'heroicon-o-arrow-up-tray')
            ->color($side->getColor())
            ->modalHeading($side->getLabel())
            ->schema([
                Select::make('instrument_id')
                    ->label('Instrumento')
                    ->options(fn () => Instrument::query()->where('type', '!=', 'FX')->orderBy('symbol')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->live(),
                DatePicker::make('date')
                    ->label('Fecha')
                    ->default(now()->toDateString())
                    ->maxDate(now())
                    ->required()
                    ->live(),
                TextInput::make('units')
                    ->label('Unidades')
                    ->numeric()
                    ->minValue(0.00000001)
                    ->required()
                    ->live(debounce: 500),
                Text::make(fn (Get $get): string => $this->previewText($side, $get) ?? 'Rellena instrumento, fecha y unidades para ver la previsión.'),
            ])
            ->action(function (array $data) use ($side): void {
                try {
                    app(DemoTradeService::class)->execute(
                        $this->getOwnerRecord(),
                        Instrument::query()->findOrFail($data['instrument_id']),
                        $side,
                        $data['date'],
                        (float) $data['units'],
                    );
                } catch (DemoTradeException $exception) {
                    Notification::make()->title('No se pudo registrar la operación')->body($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Operación registrada')->success()->send();
            });
    }

    private function previewText(TradeSide $side, Get $get): ?string
    {
        $instrumentId = $get('instrument_id');
        $date = $get('date');
        $units = $get('units');

        if (! $instrumentId || ! $date || ! is_numeric($units) || (float) $units <= 0) {
            return null;
        }

        try {
            $preview = app(DemoTradeService::class)->preview(
                $this->getOwnerRecord(),
                Instrument::query()->findOrFail($instrumentId),
                $side,
                $date,
                (float) $units,
            );
        } catch (Throwable $exception) {
            return $exception->getMessage();
        }

        return sprintf(
            'Precio %s · Cambio %s · Comisión %s · Total %s puntos',
            number_format($preview['price'], 4, ',', '.'),
            number_format($preview['fx_rate'], 6, ',', '.'),
            number_format($preview['fee'], 2, ',', '.'),
            number_format($preview['total_points'], 2, ',', '.'),
        );
    }
}
