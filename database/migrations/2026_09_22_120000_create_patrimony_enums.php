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
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'asset_type_enum') THEN
                    CREATE TYPE asset_type_enum AS ENUM (
                        'CUENTA_CORRIENTE',
                        'CUENTA_AHORRO',
                        'DEPOSITO',
                        'FONDO_INVERSION',
                        'ACCIONES',
                        'PLAN_PENSIONES',
                        'OTRO'
                    );
                END IF;
            END
            $$;
        SQL);

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'crypto_tax_status_enum') THEN
                    CREATE TYPE crypto_tax_status_enum AS ENUM ('DETECTED', 'NOT_DETECTED', 'UNKNOWN');
                END IF;
            END
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TYPE IF EXISTS crypto_tax_status_enum');
        DB::statement('DROP TYPE IF EXISTS asset_type_enum');
    }
};
