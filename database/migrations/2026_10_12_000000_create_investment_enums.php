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
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'platform_type_enum') THEN
                    CREATE TYPE platform_type_enum AS ENUM ('BROKER', 'EXCHANGE', 'BANK', 'WALLET', 'FUND_MANAGER', 'PENSION_PLAN', 'OTHER');
                END IF;
            END
            $$;
        SQL);
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'instrument_kind_enum') THEN
                    CREATE TYPE instrument_kind_enum AS ENUM ('STOCK', 'ETF', 'FUND', 'CRYPTO', 'BOND', 'DEPOSIT', 'CASH', 'COMMODITY', 'PENSION_PLAN', 'OTHER');
                END IF;
            END
            $$;
        SQL);
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'asset_class_enum') THEN
                    CREATE TYPE asset_class_enum AS ENUM ('EQUITY', 'FIXED_INCOME', 'MONEY_MARKET', 'CRYPTO', 'COMMODITY', 'REAL_ESTATE', 'CASH', 'MIXED', 'OTHER');
                END IF;
            END
            $$;
        SQL);
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'investment_transaction_type_enum') THEN
                    CREATE TYPE investment_transaction_type_enum AS ENUM ('BUY', 'SELL', 'DIVIDEND', 'INTEREST', 'FEE', 'TRANSFER_IN', 'TRANSFER_OUT', 'STAKING_REWARD', 'SPLIT', 'ADJUSTMENT');
                END IF;
            END
            $$;
        SQL);
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'contribution_frequency_enum') THEN
                    CREATE TYPE contribution_frequency_enum AS ENUM ('WEEKLY', 'MONTHLY', 'QUARTERLY', 'YEARLY');
                END IF;
            END
            $$;
        SQL);
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'reminder_kind_enum') THEN
                    CREATE TYPE reminder_kind_enum AS ENUM ('CONTRIBUTION', 'STALE_PRICE', 'DEPOSIT_MATURITY', 'REBALANCE', 'TAX', 'PRICE_ALERT', 'CUSTOM');
                END IF;
            END
            $$;
        SQL);
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'reminder_status_enum') THEN
                    CREATE TYPE reminder_status_enum AS ENUM ('PENDING', 'DONE', 'SKIPPED');
                END IF;
            END
            $$;
        SQL);
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'price_alert_condition_enum') THEN
                    CREATE TYPE price_alert_condition_enum AS ENUM ('ABOVE', 'BELOW', 'CHANGE_PCT');
                END IF;
            END
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TYPE IF EXISTS price_alert_condition_enum');
        DB::statement('DROP TYPE IF EXISTS reminder_status_enum');
        DB::statement('DROP TYPE IF EXISTS reminder_kind_enum');
        DB::statement('DROP TYPE IF EXISTS contribution_frequency_enum');
        DB::statement('DROP TYPE IF EXISTS investment_transaction_type_enum');
        DB::statement('DROP TYPE IF EXISTS asset_class_enum');
        DB::statement('DROP TYPE IF EXISTS instrument_kind_enum');
        DB::statement('DROP TYPE IF EXISTS platform_type_enum');
    }
};
