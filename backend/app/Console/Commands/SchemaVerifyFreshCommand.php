<?php

namespace App\Console\Commands;

use App\Services\Schema\SchemaHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * THE DEPLOYMENT REGRESSION GATE: can a brand-new environment build this schema from nothing?
 *
 * The migration set had been drifting for months without anyone noticing, because every environment was
 * grown incrementally and none was ever built from zero. `migrate` passing on a database that already
 * has the tables proves nothing about a fresh deploy. This proves it, on a throwaway database, without
 * touching anything real.
 *
 * What it verifies, in the order a deploy would hit them:
 *   1. every migration applies to an empty database, in filename order, with no flags
 *   2. no migration is left Pending afterwards
 *   3. the seeders run against that fresh schema (optional, --seed)
 *   4. the schema guarantees the intelligence layer depends on are actually present
 *
 * Safe by construction: it creates its own database, works only there, and drops it again. It refuses to
 * run against the configured application database, because a regression gate that can destroy production
 * is worse than no gate at all.
 *
 * Exit code is non-zero on any failure, so CI can block a release on it.
 */
class SchemaVerifyFreshCommand extends Command
{
    protected $signature = 'schema:verify-fresh
                            {--database=schema_verify_scratch : Throwaway database name to build in}
                            {--seed : Also run the seeders against the fresh schema}
                            {--keep : Leave the scratch database behind for inspection}';

    protected $description = 'Prove the whole migration set builds a correct schema on an empty database';

    public function handle(SchemaHealthService $health): int
    {
        $scratch = (string) $this->option('database');
        $live = (string) config('database.connections.mysql.database');

        // A verification tool must never be able to destroy the thing it verifies.
        if ($scratch === $live || in_array($scratch, ['laravel', 'mysql', 'information_schema'], true)) {
            $this->error("Refusing to use '{$scratch}': it is the application database or a system schema.");
            return self::FAILURE;
        }

        $this->info("Building the schema from empty in `{$scratch}`…");

        try {
            DB::statement("DROP DATABASE IF EXISTS `{$scratch}`");
            DB::statement("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            $this->error('Could not create the scratch database: ' . $e->getMessage());
            return self::FAILURE;
        }

        // Point a dedicated connection at the scratch database, leaving the default untouched.
        Config::set('database.connections.schema_verify', array_merge(
            config('database.connections.mysql'),
            ['database' => $scratch],
        ));
        DB::purge('schema_verify');

        $failed = false;
        try {
            $this->line('');
            $exit = $this->call('migrate', ['--database' => 'schema_verify', '--force' => true, '--no-interaction' => true]);
            if ($exit !== 0) {
                $this->error('FAILED: the migration set does not apply to an empty database.');
                $failed = true;
            }

            if (! $failed) {
                $pending = $this->call('migrate:status', ['--database' => 'schema_verify', '--pending' => true]);
                // migrate:status exits non-zero when anything is pending.
                if ($pending !== 0) {
                    $this->error('FAILED: migrations remain Pending after a clean run.');
                    $failed = true;
                }
            }

            if (! $failed && $this->option('seed')) {
                $this->line('');
                $this->info('Seeding the fresh schema…');
                if ($this->call('db:seed', ['--database' => 'schema_verify', '--force' => true, '--no-interaction' => true]) !== 0) {
                    $this->error('FAILED: the seeders do not run against a fresh schema.');
                    $failed = true;
                }
            }

            if (! $failed) {
                $this->line('');
                $this->info('Verifying schema guarantees on the fresh build…');
                // Run the health checks AGAINST THE SCRATCH DATABASE, so this reports on what a new
                // environment would get — not on the developer's long-lived local one.
                $previous = config('database.default');
                Config::set('database.default', 'schema_verify');
                try {
                    $checks = $health->checks();
                } finally {
                    Config::set('database.default', $previous);
                }

                foreach ($checks as $c) {
                    // Only STRUCTURAL guarantees are meaningful on an empty database — a fresh install has
                    // no decisions to stamp and nothing to calibrate, so grading those would report a
                    // warning for a perfectly correct new environment. Filtered by category rather than by
                    // key so adding a check cannot silently reintroduce that noise.
                    if (! in_array($c['category'], ['deployment', 'schema'], true)) {
                        continue;
                    }
                    $icon = $c['status'] === 'ok' ? '<fg=green>✓</>' : ($c['status'] === 'warn' ? '<fg=yellow>⚠</>' : '<fg=red>✗</>');
                    $this->line("  {$icon} {$c['label']}: {$c['detail']}");
                    if ($c['status'] === 'fail') {
                        $failed = true;
                    }
                }
            }
        } finally {
            if ($this->option('keep')) {
                $this->line('');
                $this->comment("Scratch database `{$scratch}` kept for inspection.");
            } else {
                try {
                    DB::purge('schema_verify');
                    DB::statement("DROP DATABASE IF EXISTS `{$scratch}`");
                } catch (\Throwable $e) {
                    $this->warn("Could not drop `{$scratch}`: " . $e->getMessage());
                }
            }
        }

        $this->line('');
        if ($failed) {
            $this->error('A fresh environment could NOT reproduce this schema. Fix before deploying.');
            return self::FAILURE;
        }
        $this->info('A fresh environment reproduces this schema correctly.');

        return self::SUCCESS;
    }
}
