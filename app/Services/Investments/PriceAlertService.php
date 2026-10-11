<?php

namespace App\Services\Investments;

use App\Enums\PriceAlertCondition;
use App\Enums\ReminderKind;
use App\Models\PriceAlert;
use App\Services\MarketData\MarketDataClient;
use Illuminate\Support\Carbon;

class PriceAlertService
{
    public function __construct(
        private MarketDataClient $client,
        private ReminderService $reminders,
    ) {
    }

    /**
     * @return int  Alertas disparadas.
     */
    public function check(): int
    {
        $alerts = PriceAlert::query()->where('active', true)->get();

        if ($alerts->isEmpty()) {
            return 0;
        }

        $quotes = collect($this->client->quotes($alerts->pluck('market_symbol')->all())['quotes'])->keyBy('symbol');
        $cooldown = now()->subHours((int) config('investments.price_alert_cooldown_hours'));
        $fired = 0;

        foreach ($alerts as $alert) {
            $quote = $quotes->get($alert->market_symbol);

            // Un precio servido de caché vieja no dispara nada.
            if ($quote === null || ! is_numeric($quote['price'] ?? null) || ($quote['stale'] ?? false)) {
                continue;
            }

            $price = (float) $quote['price'];
            $alert->forceFill(['last_price' => $price])->save();

            if ($alert->last_triggered_at?->gt($cooldown) || ! $this->matches($alert, $quote)) {
                continue;
            }

            $now = Carbon::now(config('app.display_timezone'));
            $this->reminders->remind(
                kind: ReminderKind::PRICE_ALERT,
                key: "alert:{$alert->id}:{$now->format('Y-m-d-H')}",
                title: "Alerta: {$alert->market_symbol}",
                body: sprintf(
                    '%s cotiza a %s %s (%s %s).',
                    $quote['name'] ?? $alert->market_symbol,
                    $this->number($price),
                    $quote['currency'] ?? '',
                    $alert->condition->getLabel(),
                    $this->number((float) $alert->threshold) . ($alert->condition === PriceAlertCondition::CHANGE_PCT ? ' %' : ''),
                ),
                due: $now,
                about: $alert,
                important: true,
            );

            $alert->forceFill(['last_triggered_at' => now()])->save();
            $fired++;
        }

        return $fired;
    }

    private function matches(PriceAlert $alert, array $quote): bool
    {
        $threshold = (float) $alert->threshold;

        return match ($alert->condition) {
            PriceAlertCondition::ABOVE => (float) $quote['price'] >= $threshold,
            PriceAlertCondition::BELOW => (float) $quote['price'] <= $threshold,
            PriceAlertCondition::CHANGE_PCT => is_numeric($quote['change_pct'] ?? null) && abs((float) $quote['change_pct']) >= $threshold,
        };
    }

    private function number(float $value): string
    {
        return number_format($value, $value >= 100 ? 2 : 4, ',', '.');
    }
}
