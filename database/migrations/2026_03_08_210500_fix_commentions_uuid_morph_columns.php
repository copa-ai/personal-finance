<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $commentsTable = config('commentions.tables.comments', 'comments');
        $subscriptionsTable = config('commentions.tables.comment_subscriptions', 'comment_subscriptions');
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE \"{$commentsTable}\" ALTER COLUMN \"commentable_id\" TYPE VARCHAR(36) USING \"commentable_id\"::text");
            DB::statement("ALTER TABLE \"{$subscriptionsTable}\" ALTER COLUMN \"subscribable_id\" TYPE VARCHAR(36) USING \"subscribable_id\"::text");

            return;
        }

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE `{$commentsTable}` MODIFY `commentable_id` VARCHAR(36) NOT NULL");
            DB::statement("ALTER TABLE `{$subscriptionsTable}` MODIFY `subscribable_id` VARCHAR(36) NOT NULL");
        }
    }

    public function down(): void
    {
        // No reversible down migration: converting string UUIDs back to bigint is unsafe.
    }
};
