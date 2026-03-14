<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE expense_items ALTER COLUMN concept DROP NOT NULL');
        DB::statement("ALTER TABLE expense_items ALTER COLUMN item_type SET DEFAULT 'FIXED'::expense_item_type_enum");
    }

    public function down(): void
    {
        DB::statement("UPDATE expense_items SET concept = '' WHERE concept IS NULL");
        DB::statement('ALTER TABLE expense_items ALTER COLUMN concept SET NOT NULL');
        DB::statement('ALTER TABLE expense_items ALTER COLUMN item_type DROP DEFAULT');
    }
};

