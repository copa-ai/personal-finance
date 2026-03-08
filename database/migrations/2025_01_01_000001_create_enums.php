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
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'expense_status_enum') THEN
                    CREATE TYPE expense_status_enum AS ENUM ('PAID', 'PENDING', 'REFUNDED', 'BLOCKED');
                END IF;
            END
            $$;
        SQL);

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'expense_item_type_enum') THEN
                    CREATE TYPE expense_item_type_enum AS ENUM ('FIXED', 'VARIABLE_REGULAR', 'VARIABLE_IRREGULAR');
                END IF;
            END
            $$;
        SQL);

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'recurrence_enum') THEN
                    CREATE TYPE recurrence_enum AS ENUM ('NONE', 'MONTHLY', 'QUARTERLY', 'YEARLY');
                END IF;
            END
            $$;
        SQL);

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'movement_type_enum') THEN
                    CREATE TYPE movement_type_enum AS ENUM ('PURCHASE', 'CONSUMPTION', 'ADJUSTMENT', 'LOSS');
                END IF;
            END
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TYPE IF EXISTS movement_type_enum');
        DB::statement('DROP TYPE IF EXISTS recurrence_enum');
        DB::statement('DROP TYPE IF EXISTS expense_item_type_enum');
        DB::statement('DROP TYPE IF EXISTS expense_status_enum');
    }
};
