<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_ratings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('product_id');
            $table->decimal('quality_rating', 2, 1)->nullable();
            $table->decimal('value_rating', 2, 1)->nullable();
            $table->text('comment')->nullable();
            $table->uuid('expense_item_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->onDelete('cascade');

            $table->foreign('expense_item_id')
                ->references('id')
                ->on('expense_items')
                ->onDelete('set null');
        });

        // Add CHECK constraints for rating ranges
        DB::statement('ALTER TABLE product_ratings ADD CONSTRAINT product_ratings_quality_check CHECK (quality_rating BETWEEN 1 AND 10)');
        DB::statement('ALTER TABLE product_ratings ADD CONSTRAINT product_ratings_value_check CHECK (value_rating BETWEEN 1 AND 10)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_ratings');
    }
};
