<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create(config('filament-quick-notes.table_name', 'filament_quick_notes'), function (Blueprint $table) {
            $table->id();
            $table->morphs('user');
            $table->string('title')->nullable();
            $table->text('content')->nullable();
            $table->string('color');
            $table->integer('order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists(config('filament-quick-notes.table_name', 'filament_quick_notes'));
    }
};
