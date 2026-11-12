<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tablas de kirschbaum-development/commentions con ids UUID: los comentarios (App\Models\Comment, config/commentions.php) y el
     * autor y quien reacciona o se suscribe son usuarios (uuid); lo comentado (commentable_id) es un UUID guardado como texto.
     * Los ids de comentarios y sus reacciones son uuidv7() por defecto para que tambien se creen bien sin pasar por el modelo propio.
     */
    public function up(): void
    {
        Schema::create(config('commentions.tables.comments', 'comments'), function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuidMorphs('author');
            $table->string('commentable_type');
            $table->string('commentable_id', 36);
            $table->text('body');
            $table->timestamps(0);

            $table->index(['commentable_type', 'commentable_id']);
        });

        // El paquete usa el modelo de reaccion tal cual (clave numerica): sus filas no se fusionan entre bases.
        Schema::create(config('commentions.tables.comment_reactions', 'comment_reactions'), function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('comment_id')->constrained(config('commentions.tables.comments', 'comments'))->cascadeOnDelete();
            $table->uuidMorphs('reactor');
            $table->string('reaction', 50);
            $table->timestamps(0);
        });

        Schema::create(config('commentions.tables.comment_subscriptions', 'comment_subscriptions'), function (Blueprint $table): void {
            $table->id();
            $table->string('subscribable_type');
            $table->string('subscribable_id', 36);
            $table->uuidMorphs('subscriber');
            $table->timestamps(0);

            $table->index(['subscribable_type', 'subscribable_id']);
            $table->unique(['subscribable_type', 'subscribable_id', 'subscriber_type', 'subscriber_id'], 'commentions_subscriptions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('commentions.tables.comment_subscriptions', 'comment_subscriptions'));
        Schema::dropIfExists(config('commentions.tables.comment_reactions', 'comment_reactions'));
        Schema::dropIfExists(config('commentions.tables.comments', 'comments'));
    }
};
