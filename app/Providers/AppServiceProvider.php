<?php

namespace App\Providers;

use App\Models\Expense;
use App\Models\ExpenseItem;
use App\Observers\ExpenseObserver;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        FilamentTimezone::set('Europe/Madrid');

        // Observers
        Expense::observe(ExpenseObserver::class);
    }
}
