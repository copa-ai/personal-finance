<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lineas de gasto. item_type, recurrence y movement_type son los enums PHP ExpenseItemType, Recurrence y MovementType guardados
     * como texto. El importe de la linea no es columna: lo calcula App\Models\ExpenseItem (cantidad x precio unitario).
     */
    public function up(): void
    {
        Schema::create('expense_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('expense_id')->index();
            $table->uuid('category_id')->nullable()->index();
            $table->string('concept')->nullable();
            $table->decimal('quantity', 10, 3)->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->jsonb('tags')->default('[]');
            $table->string('item_type', 32)->default('FIXED');
            $table->string('recurrence', 16)->default('NONE');
            $table->string('movement_type', 16)->default('PURCHASE');
            $table->boolean('is_consumable')->default(true);
            $table->date('end_date')->nullable();
            $table->date('projected_start_date')->nullable();
            $table->date('actual_start_date')->nullable();
            $table->timestamps(0);

            $table->foreign('expense_id')->references('id')->on('expenses')->cascadeOnDelete();
            $table->foreign('category_id')->references('id')->on('categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_items');
    }
};
