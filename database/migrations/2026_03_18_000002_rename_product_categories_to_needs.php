<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::rename('product_categories', 'needs');

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->foreign('category_id')
                ->references('id')
                ->on('categories');

            $table->uuid('need_id')->nullable()->after('category_id');
            $table->foreign('need_id')
                ->references('id')
                ->on('needs');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['need_id']);
            $table->dropColumn('need_id');

            $table->dropForeign(['category_id']);
            $table->foreign('category_id')
                ->references('id')
                ->on('product_categories');
        });

        Schema::rename('needs', 'product_categories');
    }
};