<?php

namespace App\Filament\Resources\Reminders\Tables;

use App\Enums\ReminderKind;
use App\Enums\ReminderStatus;
use App\Filament\Resources\Instruments\InstrumentResource;
use App\Filament\Resources\PriceAlerts\PriceAlertResource;
use App\Models\ContributionPlan;
use App\Models\FxRate;
use App\Models\Instrument;
use App\Models\PriceAlert;
use App\Models\Reminder;
use App\Services\Investments\ContributionService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use InvalidArgumentException;

class RemindersTable
{
    private static function isPendingContribution(Reminder $record): bool
    {
        return $record->kind === ReminderKind::CONTRIBUTION && $record->status === ReminderStatus::PENDING;
    }

    private static function plan(Reminder $record): ?ContributionPlan
    {
        return $record->remindable instanceof ContributionPlan ? $record->remindable : null;
    }

    public static function completeAction(): Action
    {
        return Action::make('complete')
            ->label('Hecho')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Reminder $record): bool => self::isPendingContribution($record))
            ->modalHeading(fn (Reminder $record): string => 'Registrar ' . mb_strtolower($record->title))
            ->modalDescription(fn (Reminder $record): ?string => $record->body)
            ->modalSubmitActionLabel('Registrar compra')
            ->fillForm(function (Reminder $record): array {
                $plan = self::plan($record);
                $rate = null;

                if ($plan && strtoupper($plan->currency) !== 'EUR') {
                    $units = FxRate::query()->where('currency', strtoupper($plan->currency))->orderByDesc('date')->value('units_per_eur');
                    $rate = $units ? bcdiv('1', (string) $units, 10) : null;
                }

                return [
                    'date' => now(config('app.display_timezone'))->toDateString(),
                    'instrument_id' => $plan?->instrument_id,
                    'fees' => 0,
                    'fx_rate_eur' => $rate ?? 1,
                ];
            })
            ->schema(fn (Reminder $record): array => [
                Select::make('instrument_id')
                    ->label('Instrumento')
                    ->options(fn (): array => Instrument::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->visible(fn (): bool => self::plan($record)?->instrument_id === null)
                    ->required(fn (): bool => self::plan($record)?->instrument_id === null),
                DatePicker::make('date')
                    ->label('Fecha')
                    ->required(),
                TextInput::make('quantity')
                    ->label('Cantidad (unidades)')
                    ->numeric()
                    ->step('any')
                    ->minValue(0)
                    ->required(),
                TextInput::make('price')
                    ->label('Precio por unidad')
                    ->numeric()
                    ->step('any')
                    ->minValue(0)
                    ->required(),
                TextInput::make('fees')
                    ->label('Comisiones')
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0),
                TextInput::make('fx_rate_eur')
                    ->label('Tipo de cambio')
                    ->helperText('Euros por 1 unidad de la divisa del plan.')
                    ->numeric()
                    ->step('any')
                    ->minValue(0)
                    ->visible(fn (): bool => strtoupper((string) self::plan($record)?->currency) !== 'EUR'),
                Textarea::make('notes')
                    ->label('Notas'),
            ])
            ->action(function (Reminder $record, array $data): void {
                try {
                    app(ContributionService::class)->complete($record, array_filter(
                        $data,
                        fn ($value): bool => $value !== null && $value !== '',
                    ));
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('No se pudo registrar')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Aportación registrada')->send();
            });
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('due_on')
            ->columns([
                TextColumn::make('due_on')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->color(fn (Reminder $record): ?string => $record->status === ReminderStatus::PENDING
                        && $record->due_on->lte(now(config('app.display_timezone'))->startOfDay()) ? 'warning' : null)
                    ->sortable(),
                TextColumn::make('kind')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (ReminderKind $state): string => match ($state) {
                        ReminderKind::CONTRIBUTION => 'success',
                        ReminderKind::PRICE_ALERT, ReminderKind::DEPOSIT_MATURITY, ReminderKind::TAX => 'warning',
                        ReminderKind::REBALANCE => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('title')
                    ->label('Recordatorio')
                    ->description(fn (Reminder $record): ?string => $record->body)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (ReminderStatus $state): string => match ($state) {
                        ReminderStatus::PENDING => 'warning',
                        ReminderStatus::DONE => 'success',
                        ReminderStatus::SKIPPED => 'gray',
                    }),
                TextColumn::make('resolved_at')
                    ->label('Resuelto el')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(ReminderStatus::class)
                    ->default(ReminderStatus::PENDING->value),
                SelectFilter::make('kind')
                    ->label('Tipo')
                    ->options(ReminderKind::class),
            ])
            ->recordActions([
                self::completeAction(),
                Action::make('skip')
                    ->label('Saltar')
                    ->icon('heroicon-o-forward')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Se deja constancia de que este periodo no se aportó.')
                    ->visible(fn (Reminder $record): bool => self::isPendingContribution($record))
                    ->action(fn (Reminder $record) => app(ContributionService::class)->skip($record)),
                Action::make('resolve')
                    ->label('Resuelto')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (Reminder $record): bool => $record->status === ReminderStatus::PENDING && $record->kind !== ReminderKind::CONTRIBUTION)
                    ->action(fn (Reminder $record) => $record->resolve(ReminderStatus::DONE)),
                ActionGroup::make([
                    Action::make('open')
                        ->label(fn (Reminder $record): string => $record->remindable instanceof PriceAlert ? 'Ver alerta' : 'Ver instrumento')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->visible(fn (Reminder $record): bool => $record->remindable instanceof Instrument || $record->remindable instanceof PriceAlert)
                        ->url(fn (Reminder $record): ?string => match (true) {
                            $record->remindable instanceof Instrument => InstrumentResource::getUrl('edit', ['record' => $record->remindable]),
                            $record->remindable instanceof PriceAlert => PriceAlertResource::getUrl('edit', ['record' => $record->remindable]),
                            default => null,
                        }),
                    Action::make('dismiss')
                        ->label('Descartar')
                        ->icon('heroicon-o-x-mark')
                        ->visible(fn (Reminder $record): bool => $record->status === ReminderStatus::PENDING && $record->kind !== ReminderKind::CONTRIBUTION)
                        ->action(fn (Reminder $record) => $record->resolve(ReminderStatus::SKIPPED)),
                    DeleteAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nada pendiente')
            ->emptyStateDescription('Los recordatorios se generan cada mañana: aportaciones, valoraciones sin actualizar, vencimientos, rebalanceo y fechas fiscales.');
    }
}
