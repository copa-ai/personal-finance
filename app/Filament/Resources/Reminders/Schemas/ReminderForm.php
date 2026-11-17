<?php

namespace App\Filament\Resources\Reminders\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ReminderForm
{
    public static function components(): array
    {
        return [
            TextInput::make('title')
                ->label('Título')
                ->required()
                ->maxLength(255),
            DatePicker::make('due_on')
                ->label('Fecha')
                ->default(now(config('app.display_timezone'))->toDateString())
                ->required(),
            Textarea::make('body')
                ->label('Detalle')
                ->columnSpanFull(),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(self::components());
    }
}
