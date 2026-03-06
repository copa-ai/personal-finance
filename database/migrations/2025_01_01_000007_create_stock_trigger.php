<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            CREATE OR REPLACE FUNCTION update_stock_on_purchase()
            RETURNS TRIGGER AS \$\$
            BEGIN
                IF NEW.product_id IS NOT NULL AND NEW.is_consumable THEN
                    UPDATE products
                    SET current_quantity = current_quantity + NEW.quantity,
                        last_updated_at = NOW()
                    WHERE id = NEW.product_id;
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        DB::statement("
            CREATE TRIGGER trg_expense_item_purchase
                AFTER INSERT ON expense_items
                FOR EACH ROW
                EXECUTE FUNCTION update_stock_on_purchase();
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_expense_item_purchase ON expense_items');
        DB::statement('DROP FUNCTION IF EXISTS update_stock_on_purchase()');
    }
};
