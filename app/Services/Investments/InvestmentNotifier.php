<?php

namespace App\Services\Investments;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avisos de inversiones: notificación en el panel para todos los usuarios y, si está
 * activado (INVESTMENTS_NTFY=true y INVESTMENTS_NTFY_URL), push a un tema de ntfy.
 */
class InvestmentNotifier
{
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

        $this->push($title, $body, $important);
    }

    private function push(string $title, string $body, bool $important): void
    {
        $topic = config('investments.ntfy_url');

        if (! config('investments.ntfy') || blank($topic)) {
            return;
        }

        try {
            Http::timeout((int) config('investments.ntfy_timeout'))
                ->withHeaders([
                    // Las cabeceras HTTP van en ASCII: ntfy acepta el título codificado en RFC 2047.
                    'Title' => '=?UTF-8?B?'.base64_encode($title).'?=',
                    'Priority' => $important ? 'high' : 'default',
                    'Tags' => 'moneybag',
                ])
                ->withBody($body, 'text/plain; charset=utf-8')
                ->post($topic)
                ->throw();
        } catch (Throwable $e) {
            // El aviso ya está en el panel: un fallo de ntfy no debe parar el resto.
            Log::warning('[InvestmentNotifier] No se pudo enviar a ntfy', ['error' => $e->getMessage()]);
        }
    }
}
