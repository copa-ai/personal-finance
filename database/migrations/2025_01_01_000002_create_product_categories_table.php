<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->smallInteger('priority')->default(5);
            $table->timestampTz('created_at')->useCurrent();
        });

        // Add CHECK constraint via raw SQL (Blueprint doesn't support CHECK natively)
        \DB::statement('ALTER TABLE product_categories ADD CONSTRAINT product_categories_priority_check CHECK (priority BETWEEN 1 AND 10)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
