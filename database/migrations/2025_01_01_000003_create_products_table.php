<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->string('brand', 100)->nullable();
            $table->string('variant', 100)->nullable();
            $table->uuid('category_id');
            $table->string('unit_of_measure', 20);
            $table->boolean('is_consumable')->default(true);
            $table->decimal('current_quantity', 12, 3)->default(0.0);
            $table->decimal('daily_consumption_rate', 10, 5)->nullable();
            $table->decimal('target_price', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampTz('last_updated_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('category_id')
                ->references('id')
                ->on('product_categories');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
