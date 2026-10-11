<?php

namespace App\Services\Investments;

use App\Enums\ReminderKind;
use App\Enums\ReminderStatus;
use App\Models\ContributionPlan;
use App\Models\Instrument;
use App\Models\InvestmentPlatform;
use App\Models\InvestmentTransaction;
use App\Models\Reminder;
use App\Services\Portfolio\PortfolioService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Genera los recordatorios diarios. Cada aviso tiene una clave de deduplicación,
 * así que ejecutar el comando varias veces el mismo día no repite nada.
 */
class ReminderService
{
    /** Máximo de periodos atrasados que se generan de golpe para un plan. */
    private const MAX_CATCH_UP = 12;

    public function __construct(
        private PortfolioService $portfolio,
        private InvestmentNotifier $notifier,
    ) {
    }

    /**
     * @return array<string, int>  Recordatorios nuevos por tipo.
     */
    public function run(?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::parse(($today ?? now(config('app.display_timezone')))->toDateString());

        return [
            'contributions' => $this->contributions($today),
            'stale_prices' => $this->stalePrices($today),
            'maturities' => $this->maturities($today),
            'rebalance' => $this->rebalance($today),
            'tax' => $this->tax($today),
        ];
    }

    public function contributions(CarbonImmutable $today): int
    {
        $created = 0;

        $plans = ContributionPlan::query()
            ->with(['platform', 'instrument'])
            ->where('active', true)
            ->where('next_due_on', '<=', $today->toDateString())
            ->get();

        foreach ($plans as $plan) {
            // Un aviso por periodo vencido: si no se abrió la app en dos meses, hay dos.
            for ($i = 0; $i < self::MAX_CATCH_UP && $plan->next_due_on->lte($today); $i++) {
                $due = $plan->next_due_on->toDateString();
                $what = $plan->instrument?->name ?? $plan->platform->name;

                $created += (int) $this->remind(
                    kind: ReminderKind::CONTRIBUTION,
                    key: "contribution:{$plan->id}:{$due}",
                    title: "Aportación: {$plan->name}",
                    body: sprintf('Toca aportar %s %s a %s (%s).', number_format((float) $plan->amount, 2, ',', '.'), $plan->currency, $what, $plan->platform->name),
                    due: $plan->next_due_on,
                    about: $plan,
                );

                $plan->advance();
            }
        }

        return $created;
    }

    public function stalePrices(CarbonImmutable $today): int
    {
        $days = (int) config('investments.stale_price_days');
        $created = 0;

        foreach ($this->portfolio->ledger($today)['positions'] as $position) {
            // Lo que tiene símbolo de mercado se actualiza solo.
            if ($position['market_symbol'] !== null) {
                continue;
            }

            $age = $position['price_age_days'];
            if ($age !== null && $age < $days) {
                continue;
            }

            $created += (int) $this->remind(
                kind: ReminderKind::STALE_PRICE,
                key: "stale:{$position['instrument_id']}:{$today->format('Y-m')}",
                title: "Actualiza la valoración de {$position['instrument']}",
                body: $age === null
                    ? "No tiene ningún precio apuntado; se valora a coste ({$position['platform']})."
                    : "Su último precio es de hace {$age} días ({$position['platform']}).",
                due: $today,
                about: Instrument::query()->find($position['instrument_id']),
            );
        }

        return $created;
    }

    public function maturities(CarbonImmutable $today): int
    {
        $limit = $today->addDays((int) config('investments.maturity_notice_days'));
        $created = 0;

        $instruments = Instrument::query()
            ->whereNotNull('maturity_date')
            ->whereBetween('maturity_date', [$today->toDateString(), $limit->toDateString()])
            ->get();

        foreach ($instruments as $instrument) {
            $created += (int) $this->remind(
                kind: ReminderKind::DEPOSIT_MATURITY,
                key: "maturity:{$instrument->id}:{$instrument->maturity_date->toDateString()}",
                title: "Vence {$instrument->name}",
                body: "Vence el {$instrument->maturity_date->format('d/m/Y')}. Decide si renovar o mover el dinero.",
                due: $instrument->maturity_date,
                about: $instrument,
                important: true,
            );
        }

        return $created;
    }

    public function rebalance(CarbonImmutable $today): int
    {
        $threshold = (float) config('investments.rebalance_threshold_pp');
        $summary = $this->portfolio->summary($today);

        $off = collect($summary['allocation'])
            ->filter(fn (array $row): bool => $row['drift_pp'] !== null && abs($row['drift_pp']) >= $threshold);

        if ($off->isEmpty()) {
            return 0;
        }

        return (int) $this->remind(
            kind: ReminderKind::REBALANCE,
            key: "rebalance:{$today->format('Y-m')}",
            title: 'La cartera se ha desviado del objetivo',
            body: $off->map(fn (array $row): string => sprintf(
                '%s: %s %% (objetivo %s %%)',
                $row['asset_class']->getLabel(),
                number_format($row['pct'], 1, ',', '.'),
                number_format($row['target_pct'], 1, ',', '.'),
            ))->implode('; ') . '.',
            due: $today,
        );
    }

    /**
     * Fechas fiscales en España: campaña de la Renta y Modelo 720/721 si hay
     * plataformas en el extranjero.
     */
    public function tax(CarbonImmutable $today): int
    {
        $created = 0;
        $previousYear = $today->year - 1;

        if ($today->month === 4 && InvestmentTransaction::query()->whereYear('date', $previousYear)->exists()) {
            $created += (int) $this->remind(
                kind: ReminderKind::TAX,
                key: "tax:renta:{$today->year}",
                title: "Renta {$previousYear}: ganancias y rendimientos",
                body: "Empieza la campaña de la Renta. En Inversiones → Fiscal tienes las ganancias, pérdidas y rendimientos de {$previousYear}.",
                due: $today,
            );
        }

        $foreign = InvestmentPlatform::query()->where('country', '!=', 'ES')->pluck('name');

        if ($today->month === 3 && $foreign->isNotEmpty()) {
            $created += (int) $this->remind(
                kind: ReminderKind::TAX,
                key: "tax:720:{$today->year}",
                title: 'Modelo 720 / 721 (hasta el 31 de marzo)',
                body: 'Tienes plataformas fuera de España (' . $foreign->implode(', ') . '). Revisa si superas 50.000 € por bloque y si cambió más de 20.000 € desde la última declaración.',
                due: $today->setDate($today->year, 3, 31),
                important: true,
            );
        }

        return $created;
    }

    /**
     * Crea el recordatorio si no existía y avisa. Devuelve si se creó.
     */
    public function remind(ReminderKind $kind, string $key, string $title, string $body, CarbonInterface $due, ?Model $about = null, bool $important = false): bool
    {
        $reminder = DB::transaction(fn () => Reminder::query()->firstOrCreate(
            ['dedupe_key' => $key],
            [
                'kind' => $kind,
                'status' => ReminderStatus::PENDING,
                'title' => $title,
                'body' => $body,
                'due_on' => $due->toDateString(),
                'remindable_type' => $about?->getMorphClass(),
                'remindable_id' => $about?->getKey(),
            ],
        ));

        if (! $reminder->wasRecentlyCreated) {
            return false;
        }

        $this->notifier->send($title, $body, $this->reminderUrl(), $important);

        return true;
    }

    private function reminderUrl(): ?string
    {
        try {
            return \App\Filament\Resources\Reminders\ReminderResource::getUrl('index');
        } catch (\Throwable) {
            return null;
        }
    }
}
