<?php

use App\Console\Commands\SyncMarketData;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// La copia diaria de la base ya no se programa aqui (backup:database necesita pg_dump, que la imagen del servicio no lleva):
// la hace `finance:vigilar-base` (App\Services\CopiaBase) desde start.sh.
Schedule::command(SyncMarketData::class)
    ->dailyAt('06:30')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping()
    ->runInBackground();
