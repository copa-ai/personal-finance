<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Crear columna temporalmente nullable
        DB::statement("
            ALTER TABLE expense_items
            ADD COLUMN movement_type movement_type_enum
        ");

        // 2. Marcar ajustes de descuadre
        DB::statement("
            UPDATE expense_items
            SET movement_type = 'ADJUSTMENT'
            WHERE concept ILIKE '%Ajuste de descuadre%'
        ");

        // 3. El resto como PURCHASE
        DB::statement("
            UPDATE expense_items
            SET movement_type = 'PURCHASE'
            WHERE movement_type IS NULL
        ");

        // 4. Hacer obligatoria la columna
        DB::statement("
            ALTER TABLE expense_items
            ALTER COLUMN movement_type SET NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE expense_items
            DROP COLUMN IF EXISTS movement_type
        ");
    }
};