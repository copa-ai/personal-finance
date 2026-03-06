<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('expense_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('expense_id');
            $table->uuid('product_id')->nullable();
            $table->string('concept', 255);
            $table->decimal('quantity', 10, 3)->default(1.0);
            $table->decimal('unit_price', 10, 2);
            $table->jsonb('tags')->default('[]');
            $table->boolean('is_consumable')->default(true);
            $table->date('end_date')->nullable();
            $table->date('projected_start_date')->nullable();
            $table->date('actual_start_date')->nullable();

            $table->foreign('expense_id')
                ->references('id')
                ->on('expenses')
                ->onDelete('cascade');

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->onDelete('set null');
        });

        // Add enum columns via raw SQL (PostgreSQL native enums)
        DB::statement("ALTER TABLE expense_items ADD COLUMN item_type expense_item_type_enum NOT NULL");
        DB::statement("ALTER TABLE expense_items ADD COLUMN recurrence recurrence_enum NOT NULL DEFAULT 'NONE'");

        // Add generated column for line_total (STORED)
        DB::statement('ALTER TABLE expense_items ADD COLUMN line_total NUMERIC(10,2) GENERATED ALWAYS AS (quantity * unit_price) STORED');
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_items');
    }
};
