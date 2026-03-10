<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE product_ratings ALTER COLUMN quality_rating TYPE numeric(3,1)');
        DB::statement('ALTER TABLE product_ratings ALTER COLUMN value_rating TYPE numeric(3,1)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE product_ratings ALTER COLUMN quality_rating TYPE numeric(2,1)');
        DB::statement('ALTER TABLE product_ratings ALTER COLUMN value_rating TYPE numeric(2,1)');
    }
};
