<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stock integrity triggers — ported from supabase/schema.sql (plpgsql) to
 * MariaDB/MySQL triggers. Same six behaviours, same error messages:
 *
 *   1. normalize_model_stock   phone_models.available is derived on insert
 *   2. enforce_item_shop_match a line item may never reference another shop
 *   3. enforce_adjustment_shop_match  same guard for stock_adjustments
 *   4. enforce_request_shop_match     same guard for stock_requests
 *   5. apply_item_stock_change transaction_items move available (with guard)
 *   6. apply_stock_adjustment  stock_adjustments move available + bought_in
 *
 * Concurrency: the stock UPDATEs are written as guarded single statements
 * (UPDATE ... WHERE available >= qty, then ROW_COUNT()) so read-modify-write is
 * atomic on its own. The app additionally takes SELECT ... FOR UPDATE locks
 * before writing line items (see TransactionRecorder), matching the `for
 * update` reads in the original RPCs; CHECK (available >= 0) is the last line
 * of defence.
 *
 * @mrjeff_no_stock_effects: session flag set by BackupRestorer while it loads
 * a backup (MySQL has no per-session "disable trigger", and DDL would implicit-
 * commit and break atomicity). While set, triggers skip stock maths and the
 * insufficient-stock guard; `available` is reconciled against the invariant at
 * the end of the restore, exactly as the Postgres version did after re-enabling
 * its triggers.
 *
 * Every statement is DROP-then-CREATE so the migration stays re-runnable: DDL
 * implicit-commits in MySQL, so a half-applied migration cannot roll back.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private array $triggers = [];

    public function up(): void
    {
        $this->define('model_stock_normalize', 'BEFORE INSERT', 'phone_models', <<<'SQL'
            SET NEW.bought_in = GREATEST(COALESCE(NEW.bought_in, 0), 0);
            SET NEW.opening_stock = GREATEST(COALESCE(NEW.opening_stock, 0), 0);
            IF COALESCE(@mrjeff_no_stock_effects, 0) = 1 THEN
                -- backup load: the file's own `available` is authoritative for now
                SET NEW.available = GREATEST(COALESCE(NEW.available, 0), 0);
            ELSE
                -- available is derived, never client-supplied
                SET NEW.available = NEW.opening_stock + NEW.bought_in;
            END IF;
        SQL);

        $this->define('item_shop_match', 'BEFORE INSERT', 'transaction_items', <<<SQL
            DECLARE v_tx_shop CHAR(36);
            DECLARE v_model_shop CHAR(36);
            SELECT shop_id INTO v_tx_shop FROM transactions WHERE id = NEW.transaction_id;
            SELECT shop_id INTO v_model_shop FROM phone_models WHERE id = NEW.phone_model_id;
            IF v_tx_shop IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unknown transaction';
            END IF;
            IF v_model_shop IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unknown phone model';
            END IF;
            IF v_tx_shop <> v_model_shop THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phone model does not belong to this transaction''s shop';
            END IF;
        SQL);

        $this->define('item_shop_match_upd', 'BEFORE UPDATE', 'transaction_items', <<<SQL
            DECLARE v_tx_shop CHAR(36);
            DECLARE v_model_shop CHAR(36);
            SELECT shop_id INTO v_tx_shop FROM transactions WHERE id = NEW.transaction_id;
            SELECT shop_id INTO v_model_shop FROM phone_models WHERE id = NEW.phone_model_id;
            IF v_tx_shop IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unknown transaction';
            END IF;
            IF v_model_shop IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unknown phone model';
            END IF;
            IF v_tx_shop <> v_model_shop THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phone model does not belong to this transaction''s shop';
            END IF;
        SQL);

        $adjustMatch = static fn (): string => <<<SQL
            DECLARE v_model_shop CHAR(36);
            SELECT shop_id INTO v_model_shop FROM phone_models WHERE id = NEW.phone_model_id;
            IF v_model_shop IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unknown phone model';
            END IF;
            IF v_model_shop <> NEW.shop_id THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phone model does not belong to this shop';
            END IF;
        SQL;

        $this->define('adjustment_shop_match', 'BEFORE INSERT', 'stock_adjustments', $adjustMatch());
        $this->define('adjustment_shop_match_upd', 'BEFORE UPDATE', 'stock_adjustments', $adjustMatch());

        $this->define('request_shop_match', 'BEFORE INSERT', 'stock_requests', <<<SQL
            DECLARE v_model_shop CHAR(36);
            IF NEW.phone_model_id IS NOT NULL THEN
                SELECT shop_id INTO v_model_shop FROM phone_models WHERE id = NEW.phone_model_id;
                IF v_model_shop IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unknown phone model';
                END IF;
                IF v_model_shop <> NEW.shop_id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phone model does not belong to this shop';
                END IF;
            END IF;
        SQL);

        // --- 5. transaction_items -> available --------------------------------
        $this->define('item_stock_change_ai', 'AFTER INSERT', 'transaction_items', <<<SQL
            DECLARE v_avail INT;
            DECLARE v_msg VARCHAR(191);
            IF COALESCE(@mrjeff_no_stock_effects, 0) = 0 THEN
                IF NEW.direction = 'out' THEN
                    UPDATE phone_models
                       SET available = available - NEW.qty
                     WHERE id = NEW.phone_model_id AND available >= NEW.qty;
                    IF ROW_COUNT() = 0 THEN
                        SELECT available INTO v_avail FROM phone_models WHERE id = NEW.phone_model_id;
                        SET v_msg = CONCAT('Insufficient stock: only ', COALESCE(v_avail, 0), ' available for this model');
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
                    END IF;
                ELSE
                    UPDATE phone_models SET available = available + NEW.qty WHERE id = NEW.phone_model_id;
                END IF;
            END IF;
        SQL);

        $this->define('item_stock_change_au', 'AFTER UPDATE', 'transaction_items', <<<SQL
            DECLARE v_avail INT;
            DECLARE v_msg VARCHAR(191);
            IF COALESCE(@mrjeff_no_stock_effects, 0) = 0 THEN
                -- reverse the old effect
                IF OLD.direction = 'out' THEN
                    UPDATE phone_models SET available = available + OLD.qty WHERE id = OLD.phone_model_id;
                ELSE
                    UPDATE phone_models SET available = available - OLD.qty WHERE id = OLD.phone_model_id;
                END IF;
                -- apply the new effect
                IF NEW.direction = 'out' THEN
                    UPDATE phone_models
                       SET available = available - NEW.qty
                     WHERE id = NEW.phone_model_id AND available >= NEW.qty;
                    IF ROW_COUNT() = 0 THEN
                        SELECT available INTO v_avail FROM phone_models WHERE id = NEW.phone_model_id;
                        SET v_msg = CONCAT('Insufficient stock: only ', COALESCE(v_avail, 0), ' available for this model');
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
                    END IF;
                ELSE
                    UPDATE phone_models SET available = available + NEW.qty WHERE id = NEW.phone_model_id;
                END IF;
            END IF;
        SQL);

        $this->define('item_stock_change_ad', 'AFTER DELETE', 'transaction_items', <<<SQL
            IF COALESCE(@mrjeff_no_stock_effects, 0) = 0 THEN
                -- deleting an 'out' line credits stock back (void / reject path)
                IF OLD.direction = 'out' THEN
                    UPDATE phone_models SET available = available + OLD.qty WHERE id = OLD.phone_model_id;
                ELSE
                    UPDATE phone_models SET available = available - OLD.qty WHERE id = OLD.phone_model_id;
                END IF;
            END IF;
        SQL);

        // --- 6. stock_adjustments -> available + bought_in --------------------
        $this->define('stock_adjustment_change_ai', 'AFTER INSERT', 'stock_adjustments', <<<SQL
            DECLARE v_avail INT;
            DECLARE v_msg VARCHAR(191);
            IF COALESCE(@mrjeff_no_stock_effects, 0) = 0 THEN
                IF NEW.delta < 0 THEN
                    UPDATE phone_models
                       SET available = available + NEW.delta
                     WHERE id = NEW.phone_model_id AND available >= ABS(NEW.delta);
                    IF ROW_COUNT() = 0 THEN
                        SELECT available INTO v_avail FROM phone_models WHERE id = NEW.phone_model_id;
                        SET v_msg = CONCAT('Insufficient stock to correct: only ', COALESCE(v_avail, 0), ' available');
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
                    END IF;
                ELSE
                    UPDATE phone_models
                       SET available = available + NEW.delta,
                           bought_in = bought_in + NEW.delta
                     WHERE id = NEW.phone_model_id;
                END IF;
            END IF;
        SQL);

        $this->define('stock_adjustment_change_au', 'AFTER UPDATE', 'stock_adjustments', <<<SQL
            DECLARE v_avail INT;
            DECLARE v_msg VARCHAR(191);
            IF COALESCE(@mrjeff_no_stock_effects, 0) = 0 THEN
                -- reverse old.delta
                UPDATE phone_models
                   SET available = available - OLD.delta,
                       bought_in = GREATEST(bought_in - IF(OLD.delta > 0, OLD.delta, 0), 0)
                 WHERE id = OLD.phone_model_id;
                -- apply new.delta
                IF NEW.delta < 0 THEN
                    UPDATE phone_models
                       SET available = available + NEW.delta
                     WHERE id = NEW.phone_model_id AND available >= ABS(NEW.delta);
                    IF ROW_COUNT() = 0 THEN
                        SELECT available INTO v_avail FROM phone_models WHERE id = NEW.phone_model_id;
                        SET v_msg = CONCAT('Insufficient stock to correct: only ', COALESCE(v_avail, 0), ' available');
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
                    END IF;
                ELSE
                    UPDATE phone_models
                       SET available = available + NEW.delta,
                           bought_in = bought_in + NEW.delta
                     WHERE id = NEW.phone_model_id;
                END IF;
            END IF;
        SQL);

        $this->define('stock_adjustment_change_ad', 'AFTER DELETE', 'stock_adjustments', <<<SQL
            IF COALESCE(@mrjeff_no_stock_effects, 0) = 0 THEN
                UPDATE phone_models
                   SET available = available - OLD.delta,
                       bought_in = GREATEST(bought_in - IF(OLD.delta > 0, OLD.delta, 0), 0)
                 WHERE id = OLD.phone_model_id;
            END IF;
        SQL);
    }

    private function define(string $name, string $event, string $table, string $body): void
    {
        $this->triggers[] = $name;
        DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
        DB::unprepared("CREATE TRIGGER {$name} {$event} ON {$table} FOR EACH ROW BEGIN {$body} END");
    }

    public function down(): void
    {
        foreach (array_reverse($this->triggers ?: [
            'model_stock_normalize', 'item_shop_match', 'item_shop_match_upd',
            'adjustment_shop_match', 'adjustment_shop_match_upd', 'request_shop_match',
            'item_stock_change_ai', 'item_stock_change_au', 'item_stock_change_ad',
            'stock_adjustment_change_ai', 'stock_adjustment_change_au', 'stock_adjustment_change_ad',
        ]) as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }
};
