<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_type WHERE typname = 'recurrence_enum')
                   AND NOT EXISTS (
                       SELECT 1
                       FROM pg_enum e
                       JOIN pg_type t ON t.oid = e.enumtypid
                       WHERE t.typname = 'recurrence_enum'
                         AND e.enumlabel = 'WEEKLY'
                   )
                THEN
                    EXECUTE 'ALTER TYPE recurrence_enum ADD VALUE ''WEEKLY''';
                END IF;
            END
            $$;
        SQL);
    }

    public function down(): void
    {
        // Postgres does not support dropping a single enum value safely.
    }
};

