<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Ya no se distingue lo consumible: la columna no la usa nada. Las bases que ya existen la conservaban con valor por defecto. */
    public function up(): void
    {
        if (Schema::hasColumn('expense_items', 'is_consumable')) {
            Schema::table('expense_items', function (Blueprint $table): void {
                $table->dropColumn('is_consumable');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('expense_items', 'is_consumable')) {
            Schema::table('expense_items', function (Blueprint $table): void {
                $table->boolean('is_consumable')->default(true);
            });
        }
    }
};
