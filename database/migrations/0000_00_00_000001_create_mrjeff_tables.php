<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Core schema — a direct port of supabase/schema.sql (+ migrations 0001-0004)
 * from Postgres to MariaDB/MySQL.
 *
 * Raw DDL on purpose: the original file is the source of truth, and named CHECK
 * constraints, ENUM domains and FK delete rules have to survive the port
 * verbatim. UUIDs stay CHAR(36) (app-generated, readable in SQL), timestamps
 * stay UTC (Ghana is UTC+0 year round).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE TABLE shops (
            id          CHAR(36)     NOT NULL PRIMARY KEY,
            name        VARCHAR(191) NOT NULL,
            location    VARCHAR(191) NULL,
            phone       VARCHAR(191) NULL,
            created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // Supabase split auth.users (email/password) from public.users (profile).
        // Laravel has one table; profile columns keep their original meaning and
        // `role` can never be edited by a self-service path (only owner actions
        // touch it), mirroring the column-level GRANTs in schema.sql.
        DB::unprepared(<<<'SQL'
        CREATE TABLE users (
            id              CHAR(36)     NOT NULL PRIMARY KEY,
            name            VARCHAR(191) NOT NULL DEFAULT '',
            email           VARCHAR(191) NULL,
            password        VARCHAR(255) NULL,
            role            ENUM('owner','attendant') NOT NULL DEFAULT 'attendant',
            shop_id         CHAR(36)     NULL,
            active          TINYINT(1)   NOT NULL DEFAULT 1,
            deactivated_at  TIMESTAMP    NULL,
            deactivated_by  CHAR(36)     NULL,
            remember_token  VARCHAR(100) NULL,
            created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      TIMESTAMP    NULL,
            CONSTRAINT users_email_unique UNIQUE (email),
            CONSTRAINT users_shop_fk FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE SET NULL,
            CONSTRAINT users_deactivated_by_fk FOREIGN KEY (deactivated_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement('CREATE INDEX users_shop_idx ON users (shop_id)');

        DB::unprepared(<<<'SQL'
        CREATE TABLE phone_models (
            id                  CHAR(36)     NOT NULL PRIMARY KEY,
            shop_id             CHAR(36)     NOT NULL,
            model_name          VARCHAR(191) NOT NULL,
            `condition`         ENUM('new','used') NOT NULL DEFAULT 'new',
            cost_price          DECIMAL(12,2) NULL,
            sale_price          DECIMAL(12,2) NULL,
            opening_stock       INT NOT NULL DEFAULT 0,
            bought_in           INT NOT NULL DEFAULT 0,
            available           INT NOT NULL DEFAULT 0,
            low_stock_threshold INT NOT NULL DEFAULT 5,
            created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT pm_shop_fk FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE,
            CONSTRAINT pm_shop_model_condition_unique UNIQUE (shop_id, model_name, `condition`),
            CONSTRAINT pm_cost_price_ck CHECK (cost_price IS NULL OR cost_price >= 0),
            CONSTRAINT pm_sale_price_ck CHECK (sale_price IS NULL OR sale_price >= 0),
            CONSTRAINT pm_opening_ck CHECK (opening_stock >= 0),
            CONSTRAINT pm_bought_in_ck CHECK (bought_in >= 0),
            CONSTRAINT pm_available_ck CHECK (available >= 0),
            CONSTRAINT pm_threshold_ck CHECK (low_stock_threshold >= 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement('CREATE INDEX phone_models_shop_idx ON phone_models (shop_id)');

        DB::unprepared(<<<'SQL'
        CREATE TABLE transactions (
            id                CHAR(36) NOT NULL PRIMARY KEY,
            shop_id           CHAR(36) NOT NULL,
            staff_id          CHAR(36) NOT NULL,
            customer_name     VARCHAR(191) NULL,
            customer_phone    VARCHAR(191) NULL,
            `type`            ENUM('sale','swap','repair') NOT NULL,
            payment_method    ENUM('cash','mobile_money','card','bank_transfer','other') NOT NULL,
            amount            DECIMAL(12,2) NOT NULL DEFAULT 0,
            `date`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            idempotency_key   CHAR(36) NULL,
            `status`          ENUM('completed','pending_review','voided','rejected') NOT NULL DEFAULT 'completed',
            listed_amount     DECIMAL(12,2) NULL,
            review_reason     TEXT NULL,
            discount_reason   TEXT NULL,
            payment_reference VARCHAR(191) NULL,
            reviewed_by       CHAR(36) NULL,
            reviewed_at       TIMESTAMP NULL,
            voided_by         CHAR(36) NULL,
            voided_at         TIMESTAMP NULL,
            void_reason       TEXT NULL,
            CONSTRAINT tx_shop_fk FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE,
            CONSTRAINT tx_staff_fk FOREIGN KEY (staff_id) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT tx_reviewed_by_fk FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT tx_voided_by_fk FOREIGN KEY (voided_by) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT tx_amount_ck CHECK (amount >= 0),
            CONSTRAINT tx_idempotency_unique UNIQUE (idempotency_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement('CREATE INDEX transactions_shop_date_idx ON transactions (shop_id, `date` DESC)');
        DB::statement('CREATE INDEX transactions_type_idx ON transactions (`type`)');
        DB::statement('CREATE INDEX transactions_staff_idx ON transactions (staff_id)');
        DB::statement('CREATE INDEX transactions_status_date_idx ON transactions (`status`, `date` DESC)');

        DB::unprepared(<<<'SQL'
        CREATE TABLE transaction_items (
            id             CHAR(36) NOT NULL PRIMARY KEY,
            transaction_id CHAR(36) NOT NULL,
            phone_model_id CHAR(36) NOT NULL,
            `direction`    ENUM('out','in') NOT NULL,
            qty            INT NOT NULL DEFAULT 1,
            CONSTRAINT ti_tx_fk FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE CASCADE,
            CONSTRAINT ti_model_fk FOREIGN KEY (phone_model_id) REFERENCES phone_models (id) ON DELETE RESTRICT,
            CONSTRAINT ti_qty_ck CHECK (qty > 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement('CREATE INDEX transaction_items_tx_idx ON transaction_items (transaction_id)');
        DB::statement('CREATE INDEX transaction_items_model_idx ON transaction_items (phone_model_id)');

        DB::unprepared(<<<'SQL'
        CREATE TABLE stock_adjustments (
            id             CHAR(36) NOT NULL PRIMARY KEY,
            shop_id        CHAR(36) NOT NULL,
            phone_model_id CHAR(36) NOT NULL,
            staff_id       CHAR(36) NOT NULL,
            `type`         ENUM('restock','correction') NOT NULL DEFAULT 'restock',
            delta          INT NOT NULL,
            reason         TEXT NULL,
            `date`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT sa_shop_fk FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE,
            CONSTRAINT sa_model_fk FOREIGN KEY (phone_model_id) REFERENCES phone_models (id) ON DELETE CASCADE,
            CONSTRAINT sa_staff_fk FOREIGN KEY (staff_id) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT sa_delta_ck CHECK (delta <> 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement('CREATE INDEX stock_adjustments_model_idx ON stock_adjustments (phone_model_id)');
        DB::statement('CREATE INDEX stock_adjustments_shop_idx ON stock_adjustments (shop_id, `date` DESC)');
        DB::statement('CREATE INDEX stock_adjustments_staff_idx ON stock_adjustments (staff_id)');

        DB::unprepared(<<<'SQL'
        CREATE TABLE stock_requests (
            id                  CHAR(36) NOT NULL PRIMARY KEY,
            shop_id             CHAR(36) NOT NULL,
            staff_id            CHAR(36) NOT NULL,
            `type`              ENUM('create_model','adjust_stock') NOT NULL,
            `status`            ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            model_name          VARCHAR(191) NULL,
            `condition`         ENUM('new','used') NULL,
            cost_price          DECIMAL(12,2) NULL,
            sale_price          DECIMAL(12,2) NULL,
            low_stock_threshold INT NULL,
            opening_stock       INT NULL,
            phone_model_id      CHAR(36) NULL,
            delta               INT NULL,
            reason              TEXT NULL,
            created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            decided_at          TIMESTAMP NULL,
            decided_by          CHAR(36) NULL,
            error_note          TEXT NULL,
            CONSTRAINT sr_shop_fk FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE,
            CONSTRAINT sr_staff_fk FOREIGN KEY (staff_id) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT sr_model_fk FOREIGN KEY (phone_model_id) REFERENCES phone_models (id) ON DELETE CASCADE,
            CONSTRAINT sr_decided_by_fk FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT sr_cost_ck CHECK (cost_price IS NULL OR cost_price >= 0),
            CONSTRAINT sr_sale_ck CHECK (sale_price IS NULL OR sale_price >= 0),
            CONSTRAINT sr_threshold_ck CHECK (low_stock_threshold IS NULL OR low_stock_threshold >= 0),
            CONSTRAINT sr_opening_ck CHECK (opening_stock IS NULL OR opening_stock >= 0),
            CONSTRAINT sr_payload_ck CHECK (
                (`type` = 'create_model' AND model_name IS NOT NULL AND phone_model_id IS NULL AND delta IS NULL)
                OR
                (`type` = 'adjust_stock' AND phone_model_id IS NOT NULL AND delta IS NOT NULL AND delta <> 0)
            ),
            CONSTRAINT sr_cost_price_nonneg CHECK (cost_price IS NULL OR cost_price >= 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement('CREATE INDEX stock_requests_shop_status_idx ON stock_requests (shop_id, `status`)');
        DB::statement('CREATE INDEX stock_requests_status_idx ON stock_requests (`status`)');
        DB::statement('CREATE INDEX stock_requests_staff_idx ON stock_requests (staff_id)');
        DB::statement('CREATE INDEX stock_requests_model_idx ON stock_requests (phone_model_id)');

        DB::unprepared(<<<'SQL'
        CREATE TABLE swapped_phones (
            id             CHAR(36) NOT NULL PRIMARY KEY,
            shop_id        CHAR(36) NOT NULL,
            transaction_id CHAR(36) NULL,
            staff_id       CHAR(36) NULL,
            model_name     VARCHAR(191) NOT NULL,
            `condition`    ENUM('new','used') NOT NULL DEFAULT 'used',
            customer_name  VARCHAR(191) NULL,
            customer_phone VARCHAR(191) NULL,
            `status`       ENUM('in_stock','sold','returned') NOT NULL DEFAULT 'in_stock',
            notes          TEXT NULL,
            created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT sp_shop_fk FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE,
            CONSTRAINT sp_tx_fk FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE SET NULL,
            CONSTRAINT sp_staff_fk FOREIGN KEY (staff_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement('CREATE INDEX swapped_phones_shop_idx ON swapped_phones (shop_id, created_at DESC)');
        DB::statement('CREATE INDEX swapped_phones_tx_idx ON swapped_phones (transaction_id)');
        DB::statement('CREATE INDEX swapped_phones_staff_idx ON swapped_phones (staff_id)');

        DB::unprepared(<<<'SQL'
        CREATE TABLE login_logs (
            id         CHAR(36) NOT NULL PRIMARY KEY,
            user_id    CHAR(36) NOT NULL,
            email      VARCHAR(191) NULL,
            name       VARCHAR(191) NULL,
            ip         VARCHAR(45) NULL,
            user_agent VARCHAR(512) NULL,
            device     VARCHAR(191) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT ll_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement('CREATE INDEX login_logs_user_idx ON login_logs (user_id, created_at DESC)');
        DB::statement('CREATE INDEX login_logs_time_idx ON login_logs (created_at DESC)');

        DB::unprepared(<<<'SQL'
        CREATE TABLE stock_logs (
            id             CHAR(36) NOT NULL PRIMARY KEY,
            shop_id        CHAR(36) NOT NULL,
            phone_model_id CHAR(36) NULL,
            staff_id       CHAR(36) NOT NULL,
            `action`       VARCHAR(64) NOT NULL,
            model_name     VARCHAR(191) NULL,
            `condition`    VARCHAR(16) NULL,
            details        JSON NULL,
            created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT sl_shop_fk FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE,
            CONSTRAINT sl_model_fk FOREIGN KEY (phone_model_id) REFERENCES phone_models (id) ON DELETE SET NULL,
            CONSTRAINT sl_staff_fk FOREIGN KEY (staff_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement('CREATE INDEX stock_logs_shop_idx ON stock_logs (shop_id, created_at DESC)');
        DB::statement('CREATE INDEX stock_logs_time_idx ON stock_logs (created_at DESC)');
        DB::statement('CREATE INDEX stock_logs_model_idx ON stock_logs (phone_model_id)');
        DB::statement('CREATE INDEX stock_logs_staff_idx ON stock_logs (staff_id)');

        // --- fraud controls (migration 0004) ---------------------------------
        DB::unprepared(<<<'SQL'
        CREATE TABLE transaction_events (
            id             CHAR(36) NOT NULL PRIMARY KEY,
            transaction_id CHAR(36) NOT NULL,
            actor_id       CHAR(36) NOT NULL,
            `action`       ENUM('created','approved','rejected','voided') NOT NULL,
            details        JSON NULL,
            created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT te_tx_fk FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE CASCADE,
            CONSTRAINT te_actor_fk FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement('CREATE INDEX transaction_events_tx_idx ON transaction_events (transaction_id, created_at)');
        DB::statement('CREATE INDEX transaction_events_actor_idx ON transaction_events (actor_id, created_at DESC)');

        DB::unprepared(<<<'SQL'
        CREATE TABLE daily_closes (
            id                   CHAR(36) NOT NULL PRIMARY KEY,
            shop_id              CHAR(36) NOT NULL,
            close_date           DATE NOT NULL,
            `status`             ENUM('open','locked') NOT NULL DEFAULT 'open',
            expected_cash        DECIMAL(12,2) NOT NULL DEFAULT 0,
            expected_mobile_money DECIMAL(12,2) NOT NULL DEFAULT 0,
            expected_other       DECIMAL(12,2) NOT NULL DEFAULT 0,
            counted_cash         DECIMAL(12,2) NULL,
            counted_mobile_money DECIMAL(12,2) NULL,
            counted_other        DECIMAL(12,2) NULL,
            notes                TEXT NULL,
            submitted_by         CHAR(36) NOT NULL,
            submitted_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            locked_by            CHAR(36) NULL,
            locked_at            TIMESTAMP NULL,
            created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT dc_shop_fk FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE,
            CONSTRAINT dc_submitted_by_fk FOREIGN KEY (submitted_by) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT dc_locked_by_fk FOREIGN KEY (locked_by) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT dc_shop_date_unique UNIQUE (shop_id, close_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement('CREATE INDEX daily_closes_shop_date_idx ON daily_closes (shop_id, close_date DESC)');

        DB::unprepared(<<<'SQL'
        CREATE TABLE stock_counts (
            id           CHAR(36) NOT NULL PRIMARY KEY,
            shop_id      CHAR(36) NOT NULL,
            count_date   DATE NOT NULL,
            `status`     ENUM('submitted','approved','applied') NOT NULL DEFAULT 'submitted',
            submitted_by CHAR(36) NOT NULL,
            approved_by  CHAR(36) NULL,
            notes        TEXT NULL,
            created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT sc_shop_fk FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE,
            CONSTRAINT sc_submitted_by_fk FOREIGN KEY (submitted_by) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT sc_approved_by_fk FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement('CREATE INDEX stock_counts_shop_date_idx ON stock_counts (shop_id, count_date DESC)');

        DB::unprepared(<<<'SQL'
        CREATE TABLE stock_count_items (
            id             CHAR(36) NOT NULL PRIMARY KEY,
            count_id       CHAR(36) NOT NULL,
            phone_model_id CHAR(36) NOT NULL,
            expected_qty   INT NOT NULL,
            counted_qty    INT NOT NULL,
            CONSTRAINT sci_count_fk FOREIGN KEY (count_id) REFERENCES stock_counts (id) ON DELETE CASCADE,
            CONSTRAINT sci_model_fk FOREIGN KEY (phone_model_id) REFERENCES phone_models (id) ON DELETE RESTRICT,
            CONSTRAINT sci_expected_ck CHECK (expected_qty >= 0),
            CONSTRAINT sci_counted_ck CHECK (counted_qty >= 0),
            CONSTRAINT sci_count_model_unique UNIQUE (count_id, phone_model_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        foreach ([
            'stock_count_items', 'stock_counts', 'daily_closes', 'transaction_events',
            'stock_logs', 'login_logs', 'swapped_phones', 'stock_requests',
            'stock_adjustments', 'transaction_items', 'transactions',
            'phone_models', 'users', 'shops',
        ] as $table) {
            DB::unprepared("DROP TABLE IF EXISTS {$table}");
        }
    }
};
