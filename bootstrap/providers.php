<?php

use EragLaravelPwa\EragLaravelPwaServiceProvider;

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\AuditoriaServiceProvider::class,
    App\Providers\ChatServiceProvider::class,
    EragLaravelPwaServiceProvider::class,
];
