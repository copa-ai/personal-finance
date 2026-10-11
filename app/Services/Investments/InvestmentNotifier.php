<?php

namespace App\Services\Investments;

use App\Models\User;
use App\Services\MarketData\MarketDataClient;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avisos de inversiones: notificación en el panel para todos los usuarios y, si está
 * activado, push por ntfy a través de pf-market-data.
 */
class InvestmentNotifier
{
    public function __construct(private MarketDataClient $marketData)
    {
    }

    public function send(string $title, string $body, ?string $url = null, bool $important = false): void
    {
        $notification = Notification::make()
            ->title($title)
            ->body($body)
            ->icon('heroicon-o-bell-alert')
            ->{$important ? 'warning' : 'info'}();

        if ($url !== null) {
            $notification->actions([
                Action::make('open')->label('Abrir')->url($url)->markAsRead(),
            ]);
        }

        foreach (User::query()->get() as $user) {
            $notification->sendToDatabase($user);
        }

        if (! config('investments.ntfy')) {
            return;
        }

        try {
            $this->marketData->notify($title, $body, $important ? 'high' : 'default', ['moneybag']);
        } catch (Throwable $e) {
            // El aviso ya está en el panel: un fallo de ntfy no debe parar el resto.
            Log::warning('[InvestmentNotifier] No se pudo enviar a ntfy', ['error' => $e->getMessage()]);
        }
    }
}
