<?php

namespace Tests\Feature\Demo;

use App\Models\CryptoAccount;
use App\Models\PriceBar;
use App\Models\ValueSnapshot;
use App\Services\Demo\RealPortfolioService;
use App\Services\Market\FxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealPortfolioServiceTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $name): CryptoAccount
    {
        return CryptoAccount::query()->create(['name' => $name, 'currency' => 'BTC']);
    }

    private function snap(CryptoAccount $a, string $date, float $value, string $currency = 'EUR'): void
    {
        $a->valueSnapshots()->create(['date' => $date, 'value' => $value, 'currency' => $currency]);
    }

    private function curve(?string $from = null, ?string $to = null): array
    {
        return app(RealPortfolioService::class)->curve($from, $to);
    }

    public function test_empty_when_no_snapshots(): void
    {
        $this->assertSame([], $this->curve());
    }

    public function test_sums_items_and_carries_forward_each_one(): void
    {
        $a = $this->account('A');
        $b = $this->account('B');
        $this->snap($a, '2026-01-01', 100);
        $this->snap($b, '2026-01-10', 50);
        $this->snap($a, '2026-01-20', 120);

        $this->assertSame([
            ['date' => '2026-01-01', 'value' => 100.0],
            ['date' => '2026-01-10', 'value' => 150.0],
            ['date' => '2026-01-20', 'value' => 170.0],
        ], $this->curve());
    }

    public function test_converts_non_eur_snapshots_with_fx_of_that_date(): void
    {
        $pair = app(FxService::class)->ensurePair('USD');
        PriceBar::query()->create([
            'instrument_id' => $pair->id, 'date' => '2026-01-01',
            'open' => 2, 'high' => 2, 'low' => 2, 'close' => 2,
        ]);
        $a = $this->account('A');
        $this->snap($a, '2026-01-05', 100, 'USD');

        $this->assertSame([['date' => '2026-01-05', 'value' => 50.0]], $this->curve());
    }

    public function test_ignores_snapshots_of_other_valuables(): void
    {
        ValueSnapshot::query()->forceCreate([
            'valuable_type' => 'App\\Models\\Other', 'valuable_id' => (string) \Illuminate\Support\Str::uuid(),
            'date' => '2026-01-01', 'value' => 999, 'currency' => 'EUR',
        ]);

        $this->assertSame([], $this->curve());
    }

    public function test_from_and_to_clip_the_range_and_add_boundary_points(): void
    {
        $a = $this->account('A');
        $this->snap($a, '2026-01-01', 100);
        $this->snap($a, '2026-02-01', 200);
        $this->snap($a, '2026-03-01', 300);

        $this->assertSame([
            ['date' => '2026-01-15', 'value' => 100.0],
            ['date' => '2026-02-01', 'value' => 200.0],
            ['date' => '2026-02-15', 'value' => 200.0],
        ], $this->curve('2026-01-15', '2026-02-15'));
    }

    public function test_to_before_first_snapshot_returns_nothing(): void
    {
        $this->snap($this->account('A'), '2026-03-01', 100);

        $this->assertSame([], $this->curve(null, '2026-01-01'));
    }
}
