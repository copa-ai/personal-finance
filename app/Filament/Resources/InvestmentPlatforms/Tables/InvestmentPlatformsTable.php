<?php

namespace App\Filament\Resources\InvestmentPlatforms\Tables;

use App\Enums\PlatformType;
use App\Models\InvestmentPlatform;
use App\Services\Portfolio\Dec;
use App\Services\Portfolio\PortfolioService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvestmentPlatformsTable
{
    /** @var array<string, string>|null Valor en EUR por plataforma, calculado una vez por petición. */
    private static ?array $values = null;

    public static function valueFor(InvestmentPlatform $platform): string
    {
        self::$values ??= collect(app(PortfolioService::class)->summary()['positions'])
            ->groupBy('platform_id')
            ->map(fn ($positions): string => Dec::round($positions->reduce(fn (string $c, array $p): string => Dec::add($c, $p['value_eur']), '0')))
            ->all();

        return self::$values[$platform->id] ?? '0.00';
    }

    public static function configure(Table $table): Table
    {
        // Nueva petición (Livewire reutiliza el proceso en tests): recalcular.
        self::$values = null;

        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->sortable(),
                TextColumn::make('country')
                    ->label('País')
                    ->badge()
                    ->color(fn (?string $state): string => strtoupper((string) $state) === 'ES' ? 'gray' : 'warning')
                    ->sortable(),
                TextColumn::make('value')
                    ->label('Valor')
                    ->state(fn (InvestmentPlatform $record): string => self::valueFor($record))
                    ->money('EUR')
                    ->alignEnd(),
                TextColumn::make('transactions_count')
                    ->label('Movimientos')
                    ->counts('transactions')
                    ->alignEnd()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(PlatformType::class),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
