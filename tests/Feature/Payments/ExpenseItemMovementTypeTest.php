<?php

namespace Tests\Feature\Payments;

use App\Enums\MovementType;
use App\Models\ExpenseItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesExpenses;
use Tests\TestCase;

class ExpenseItemMovementTypeTest extends TestCase
{
    use CreatesExpenses;
    use RefreshDatabase;

    public function test_movement_type_is_persisted(): void
    {
        $expense = $this->createExpense('10.00');

        foreach (MovementType::cases() as $type) {
            $item = $this->addItem($expense, '1', '0', ['movement_type' => $type]);

            $this->assertSame($type, $item->fresh()->movement_type);
        }
    }

    public function test_movement_type_defaults_to_purchase(): void
    {
        $expense = $this->createExpense('10.00');

        $item = ExpenseItem::query()->create([
            'expense_id' => $expense->id,
            'concept' => 'Pan',
            'quantity' => 1,
            'unit_price' => '10.00',
        ]);

        $this->assertSame(MovementType::PURCHASE, $item->fresh()->movement_type);
    }

    public function test_line_total_is_quantity_times_unit_price(): void
    {
        $expense = $this->createExpense('7.47');

        $item = $this->addItem($expense, '3', '2.49');

        $this->assertSame('7.47', $item->fresh()->line_total);
    }

    public function test_ocr_is_available_when_only_adjustments_exist(): void
    {
        $expense = $this->createExpense('10.00');
        $this->addItem($expense, '1', '10.00', ['movement_type' => MovementType::ADJUSTMENT]);

        $this->assertTrue($expense->isOcrAvailable());

        $this->addItem($expense, '1', '1.00');

        $this->assertFalse($expense->isOcrAvailable());
    }

    public function test_migration_fixes_adjustments_saved_as_purchases(): void
    {
        $expense = $this->createExpense('20.00');
        $wrong = $this->addItem($expense, '1', '5.00', ['concept' => 'Ajuste de descuadre', 'is_consumable' => true]);
        $real = $this->addItem($expense, '1', '15.00', ['concept' => 'Leche']);

        $migration = require database_path('migrations/2026_10_11_000001_fix_adjustment_items_saved_as_purchase.php');
        $migration->up();

        $this->assertSame(MovementType::ADJUSTMENT, $wrong->fresh()->movement_type);
        $this->assertFalse($wrong->fresh()->is_consumable);
        $this->assertSame(MovementType::PURCHASE, $real->fresh()->movement_type);
    }
}
