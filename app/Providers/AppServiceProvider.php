<?php

namespace App\Providers;

use App\Models\ExpenseItem;
use App\Observers\ExpenseItemObserver;
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
        ExpenseItem::observe(ExpenseItemObserver::class);
    }
}
