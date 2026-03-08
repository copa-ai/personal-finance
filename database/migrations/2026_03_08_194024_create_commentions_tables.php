<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create(config('commentions.tables.comments', 'comments'), function (Blueprint $table) {
            $table->id();
            $table->morphs('author');
            $table->string('commentable_type');
            $table->string('commentable_id', 36);
            $table->index(['commentable_type', 'commentable_id']);
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('commentions.tables.comments', 'comments'));
    }
};
