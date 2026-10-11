<?php

use App\Console\Commands\BackupDatabase;
use App\Console\Commands\InvestmentsReminders;
use App\Console\Commands\InvestmentsSnapshot;
use App\Console\Commands\MarketCheckAlerts;
use App\Console\Commands\MarketRefresh;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(BackupDatabase::class)
    ->dailyAt('03:15')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping()
    ->runInBackground();

// Inversiones y mercados. Sin MARKET_DATA_URL los comandos de mercado no hacen nada.
Schedule::command(MarketRefresh::class, ['--prices'])
    ->hourly()
    ->withoutOverlapping();

// El BCE publica sus tipos hacia las 16:00 CET.
Schedule::command(MarketRefresh::class, ['--fx'])
    ->dailyAt('16:30')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping();

Schedule::command(MarketCheckAlerts::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command(InvestmentsReminders::class)
    ->dailyAt('08:00')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping();

Schedule::command(InvestmentsSnapshot::class)
    ->dailyAt('23:50')
    ->timezone('Europe/Madrid');
