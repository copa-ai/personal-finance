<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('value_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('valuable');
            $table->date('date');
            $table->decimal('value', 14, 2);
            $table->string('currency', 3)->default('EUR');
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('value_snapshots');
    }
};
