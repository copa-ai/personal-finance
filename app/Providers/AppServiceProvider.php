<?php

namespace App\Providers;

use App\Models\Expense;
use App\Models\ExpenseItem;
use App\Observers\ExpenseItemObserver;
use App\Observers\ExpenseObserver;
use App\Services\Coder\Coder;
use App\Services\Coder\Saldo;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Coder::class, fn (): Coder => Coder::desdeConfig());
        $this->app->bind(Saldo::class, fn (): Saldo => Saldo::desdeConfig($this->app->make(Coder::class)));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        FilamentTimezone::set(config('app.display_timezone'));

        FilamentAsset::register([
            Js::make('lightweight-charts', resource_path('js/vendor/lightweight-charts.js')),
        ]);

        // Observers
        Expense::observe(ExpenseObserver::class);
        ExpenseItem::observe(ExpenseItemObserver::class);
    }
}
