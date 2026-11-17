<?php

namespace App\Filament\Resources\Reminders;

use App\Filament\Resources\Reminders\Pages\ListReminders;
use App\Filament\Resources\Reminders\Schemas\ReminderForm;
use App\Filament\Resources\Reminders\Tables\RemindersTable;
use App\Models\Reminder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class ReminderResource extends Resource
{
    protected static ?string $model = Reminder::class;

    protected static ?string $recordTitleAttribute = 'title';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static string|UnitEnum|null $navigationGroup = 'Inversiones';

    protected static ?int $navigationSort = 14;

    protected static ?string $modelLabel = 'Recordatorio';

    protected static ?string $pluralModelLabel = 'Recordatorios';

    public static function pendingDueCount(): int
    {
        return Reminder::query()
            ->pending()
            ->where('due_on', '<=', now(config('app.display_timezone'))->toDateString())
            ->count();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = self::pendingDueCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return ReminderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RemindersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReminders::route('/'),
        ];
    }
}
