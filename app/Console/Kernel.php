<?php

namespace App\Console;

use App\Console\Commands\BackupDatabase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * @var array
     */
    protected $commands = [
        Commands\ProcessOcr::class,
        BackupDatabase::class,
    ];

    /**
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command(BackupDatabase::class)
            ->dailyAt('03:15')
            ->timezone('Europe/Madrid')
            ->withoutOverlapping()
            ->runInBackground();
    }

    /**
     * Registra los comandos para la aplicación.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
