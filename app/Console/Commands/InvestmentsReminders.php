<?php

namespace App\Console\Commands;

use App\Services\Investments\ReminderService;
use Illuminate\Console\Command;

class InvestmentsReminders extends Command
{
    protected $signature = 'investments:reminders';

    protected $description = 'Genera los recordatorios de inversión del día (aportaciones, valoraciones, vencimientos, rebalanceo, fiscal).';

    public function handle(ReminderService $reminders): int
    {
        foreach ($reminders->run() as $kind => $count) {
            $this->line(sprintf('%s: %d nuevos', $kind, $count));
        }

        return self::SUCCESS;
    }
}
