<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE expense_status_enum AS ENUM ('PAID', 'PENDING', 'REFUNDED', 'BLOCKED')");
        DB::statement("CREATE TYPE expense_item_type_enum AS ENUM ('FIXED', 'VARIABLE_REGULAR', 'VARIABLE_IRREGULAR')");
        DB::statement("CREATE TYPE recurrence_enum AS ENUM ('NONE', 'MONTHLY', 'QUARTERLY', 'YEARLY')");
        DB::statement("CREATE TYPE movement_type_enum AS ENUM ('PURCHASE', 'CONSUMPTION', 'ADJUSTMENT', 'LOSS')");
    }

    public function down(): void
    {
        DB::statement('DROP TYPE IF EXISTS movement_type_enum');
        DB::statement('DROP TYPE IF EXISTS recurrence_enum');
        DB::statement('DROP TYPE IF EXISTS expense_item_type_enum');
        DB::statement('DROP TYPE IF EXISTS expense_status_enum');
    }
};
