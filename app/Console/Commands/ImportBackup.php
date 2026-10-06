<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

/**
 * Port of the plan's "real data import" step (M5/M6).
 *
 * Consumes the exact JSON payload `GET /settings/backup/download` emits — the
 * same shape the old Supabase app exported — through the same
 * BackupService::restore() path the Settings screen uses, so UAT exercises the
 * importer for free.
 *
 * Requires an active owner to exist (run the /setup wizard first): the actor
 * is who the restore is attributed to in the audit trail.
 */
class ImportBackup extends Command
{
    protected $signature = 'mrjeff:import-backup
        {file : JSON backup file (as produced by Settings > Backup)}
        {--force : Proceed even when tables already contain rows (restore replaces them)}';

    protected $description = 'Import a JSON backup through BackupService::restore() and print a reconciliation report';

    public function handle(BackupService $backup): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("Backup file not found: {$path}");

            return self::FAILURE;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->error("Could not read {$path}");

            return self::FAILURE;
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            $this->error('Not valid JSON (or not an object/array): '.$path);

            return self::FAILURE;
        }

        $owner = User::where('role', 'owner')
            ->where('active', true)
            ->orderBy('created_at')
            ->first();
        if ($owner === null) {
            $this->error('No active owner account exists yet. Open /setup (with OWNER_SETUP_SECRET) and create the owner first — the restore is attributed to it.');

            return self::FAILURE;
        }

        $before = $this->snapshot();
        $nonEmpty = array_filter($before, static fn (int $n): bool => $n > 0);
        if ($nonEmpty !== [] && ! $this->option('force')) {
            $this->warn('The database already contains rows:');
            foreach ($nonEmpty as $table => $count) {
                $this->warn("  {$table}: {$count}");
            }
            $this->warn('A restore REPLACES that data. Re-run with --force to proceed.');

            return self::FAILURE;
        }

        $this->info('Importing '.$path.' …');
        $started = microtime(true);

        try {
            $result = $backup->restore($data, $owner);
        } catch (AccessDeniedHttpException) {
            $this->error('Restore refused (the restore is only allowed for the owner).');

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Restore failed — nothing was written (the import runs in one transaction):');
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $elapsed = round((microtime(true) - $started) * 1000);
        $after = $this->snapshot();

        $this->newLine();
        $this->info(sprintf('Imported in %d ms.', $elapsed));
        $this->table(
            ['table', 'before', 'after'],
            collect($after)
                ->map(static fn (int $n, string $table): array => [
                    $table,
                    (string) ($before[$table] ?? 0),
                    (string) $n,
                ])
                ->values()
                ->all()
        );

        $this->newLine();
        $this->line('Stock reconciliation (available must satisfy the invariant):');
        $this->line(sprintf(
            '  phone_models: %d rows, available sum = %d, bought_in sum = %d',
            (int) DB::table('phone_models')->count(),
            (int) DB::table('phone_models')->sum('available'),
            (int) DB::table('phone_models')->sum('bought_in'),
        ));
        $this->line(sprintf(
            '  transactions: %d rows, sales amount sum = %s',
            (int) DB::table('transactions')->count(),
            number_format((float) DB::table('transactions')->sum('amount'), 2),
        ));

        if (is_array($result) && $result !== []) {
            $this->newLine();
            $this->line('Restore result: '.json_encode($result, JSON_UNESCAPED_SLASHES));
        }

        $this->newLine();
        $this->info('Done. Compare these totals with the old app before switching DNS.');

        return self::SUCCESS;
    }

    /** @return array<string, int> row counts of every application table */
    private function snapshot(): array
    {
        $tables = DB::select(
            "SELECT table_name AS name
               FROM information_schema.tables
              WHERE table_schema = DATABASE()
                AND table_type = 'BASE TABLE'
                AND table_name NOT IN ('migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'sessions')
              ORDER BY table_name"
        );

        $counts = [];
        foreach ($tables as $table) {
            $counts[$table->name] = (int) DB::table($table->name)->count();
        }

        return $counts;
    }
}
