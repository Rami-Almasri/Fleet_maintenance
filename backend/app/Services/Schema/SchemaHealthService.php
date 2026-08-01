<?php

namespace App\Services\Schema;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Is the database actually shaped the way the intelligence layer assumes — and is this environment even
 * the deployment we think it is?
 *
 * The recommendation engine now rests on schema guarantees, not just on code: a unique constraint that
 * stops a fault→action link being counted twice, provenance columns that make an old decision
 * explainable, indexes that keep the comeback query from crawling. None of those announce themselves
 * when missing — the product keeps working and quietly returns worse answers. `fault_concept_actions`
 * ran for weeks with no unique index and nobody noticed, because nothing checked.
 *
 * So schema correctness is an operational signal, reported like one: grouped into CATEGORIES an operator
 * can scan, scored for an at-a-glance verdict, and expanded into named checks an engineer can act on.
 * Every check answers "what breaks if this is wrong?", because a warning nobody can act on is noise.
 *
 * Statuses: `ok` (verified), `warn` (degraded but functioning), `fail` (a guarantee the code relies on
 * is absent — treat as a bug). READ-ONLY: it diagnoses, never repairs. Repair belongs in a migration,
 * where it is versioned and reviewable.
 *
 * See [[migrations-cannot-run-from-empty]] and [[garage-recommendation-engine]].
 */
class SchemaHealthService
{
    public const CATEGORIES = ['deployment' => 'Deployment', 'schema' => 'Schema', 'engine' => 'Decision Engine', 'calibration' => 'Calibration'];

    /** Score contribution per status. A warning is genuinely half-credit: working, but not verified. */
    private const WEIGHT = ['ok' => 1.0, 'warn' => 0.5, 'fail' => 0.0];

    /**
     * Indexes the intelligence layer depends on, and what goes wrong without each. Declared here rather
     * than inferred from the migrations, so that a migration silently failing to apply one is caught —
     * inferring the expectation from the same source that produced the defect would check nothing.
     *
     * @var array<string, array<string, string>>
     */
    private const CRITICAL_INDEXES = [
        'fault_concept_actions' => [
            'fca_keyword_action_unique' => 'Without it the same fault→action link can be stored twice and double-counted in the picker and in analytics.',
        ],
        'maintenance_signatures' => [
            'maint_sig_unique'         => 'Stops one repair contributing the same signature twice, which would inflate comeback rates.',
            'maint_sig_vehicle_lookup' => 'Backs the per-vehicle recurrence lookup; without it the comeback proxy degrades to a table scan.',
            'maint_sig_cohort'         => 'Backs the per-garage comeback aggregation used by the forecaster.',
        ],
    ];

    /** Columns whose absence silently degrades a feature rather than erroring. */
    private const REQUIRED_COLUMNS = [
        'garage_recommendation_decisions' => [
            'match_score'        => 'The score the operator actually saw',
            'breakdown'          => 'The factor breakdown behind that score',
            'expected_outcomes'  => 'The forecast the decision was made on',
            'actual_outcomes'    => 'What actually happened (calibration input)',
            'forecast_accuracy'  => 'Predicted vs actual, per measure',
            'engine_version'     => 'Which engine build decided',
            'config_fingerprint' => 'Which tuning actually ran',
            'data_snapshot'      => 'Which day of data it read',
        ],
    ];

    /**
     * A decision's provenance is only useful if it is COMPLETE — knowing the engine build but not the
     * tuning fingerprint still leaves "why did it decide that?" unanswerable.
     */
    private const PROVENANCE_FIELDS = ['engine_version', 'policy_version', 'config_fingerprint', 'data_snapshot'];

    /**
     * Every check, in report order.
     *
     * @param  bool  $withDrift  run the expensive schema-drift comparison (builds a scratch database)
     * @return array<int, array<string, mixed>>
     */
    public function checks(bool $withDrift = false): array
    {
        $checks = array_merge(
            [$this->deploymentVersion()],
            [$this->requiredColumns()],
            $this->criticalIndexes(),
            [$this->decisionProvenance()],
            [$this->provenanceCompleteness()],
            [$this->forecastCoverage()],
            [$this->calibrationData()],
            [$this->costSourceFreshness()],
            [$this->decisionCapture()],
        );

        if ($withDrift) {
            $checks[] = $this->driftCheck();
        }

        return $checks;
    }

    /**
     * The at-a-glance summary: an overall percentage plus a per-category verdict.
     *
     * The score is deliberately NOT the headline on its own — "98%" is meaningless if the missing 2% is
     * a failed integrity constraint. It sits beside the category verdicts, and the overall verdict is
     * always the WORST status present, so a high score can never paint over a failure.
     *
     * @param  array<int, array<string, mixed>>  $checks
     * @return array<string, mixed>
     */
    public function summary(array $checks): array
    {
        $score = 0.0;
        $categories = [];

        foreach ($checks as $c) {
            $score += self::WEIGHT[$c['status']] ?? 0.0;
            $cat = $c['category'];
            $categories[$cat]['checks'][] = $c['key'];
            $categories[$cat]['status'] = $this->worst($categories[$cat]['status'] ?? 'ok', $c['status']);
        }

        $out = [];
        foreach (self::CATEGORIES as $key => $label) {
            if (! isset($categories[$key])) {
                continue;
            }
            $out[$key] = [
                'label'  => $label,
                'status' => $categories[$key]['status'],
                'checks' => count($categories[$key]['checks']),
            ];
        }

        return [
            'score'      => empty($checks) ? 0 : (int) round($score / count($checks) * 100),
            'verdict'    => $this->verdict($checks),
            'categories' => $out,
        ];
    }

    /** Overall verdict — the worst status present, since a green summary over a red check is a lie. */
    public function verdict(array $checks): string
    {
        $worst = 'ok';
        foreach ($checks as $c) {
            $worst = $this->worst($worst, $c['status']);
        }
        return $worst;
    }

    private function worst(string $a, string $b): string
    {
        $rank = ['ok' => 0, 'warn' => 1, 'fail' => 2];
        return ($rank[$b] ?? 0) > ($rank[$a] ?? 0) ? $b : $a;
    }

    // ── Deployment ────────────────────────────────────────────────────────────────────────────────

    /**
     * WHICH deployment is this? Batch, newest migration, totals and anything pending — enough to tell a
     * current environment from a half-migrated one at a glance. A pending migration means the running
     * code expects a schema this database does not have; that is a failure, not a note.
     */
    private function deploymentVersion(): array
    {
        try {
            $files = collect(glob(database_path('migrations/*.php')))->map(fn ($p) => basename($p, '.php'))->all();
            $ran = DB::table('migrations')->pluck('migration')->all();
            $pending = array_values(array_diff($files, $ran));
            $batch = (int) DB::table('migrations')->max('batch');
            $latest = DB::table('migrations')->orderByDesc('id')->value('migration');
        } catch (\Throwable $e) {
            return $this->row('deployment', 'deployment.migrations', 'Migrations', 'fail',
                'Could not read the migrations table: ' . $e->getMessage(), 'Check the database connection.');
        }

        $facts = sprintf(
            'Batch %d · %d of %d applied · latest: %s',
            $batch, count($ran), count($files), $latest ?: '—',
        );

        return empty($pending)
            ? $this->row('deployment', 'deployment.migrations', 'Migrations', 'ok', $facts . ' · none pending.', null, [
                'batch' => $batch, 'applied' => count($ran), 'total' => count($files), 'latest' => $latest, 'pending' => [],
            ])
            : $this->row('deployment', 'deployment.migrations', 'Migrations', 'fail',
                $facts . ' · ' . count($pending) . ' PENDING: ' . implode(', ', array_slice($pending, 0, 3)) . (count($pending) > 3 ? ' …' : ''),
                'Run php artisan migrate. If it fails because the object already exists, the schema drifted — write a corrective migration rather than forcing the row.',
                ['batch' => $batch, 'applied' => count($ran), 'total' => count($files), 'latest' => $latest, 'pending' => $pending]);
    }

    // ── Schema ────────────────────────────────────────────────────────────────────────────────────

    private function requiredColumns(): array
    {
        $missing = [];
        foreach (self::REQUIRED_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $missing[] = "{$table} (table missing)";
                continue;
            }
            foreach ($columns as $column => $why) {
                if (! Schema::hasColumn($table, $column)) {
                    $missing[] = "{$table}.{$column} — {$why}";
                }
            }
        }

        return empty($missing)
            ? $this->row('schema', 'schema.columns', 'Decision provenance columns', 'ok', 'All decision, forecast and provenance columns present.', null)
            : $this->row('schema', 'schema.columns', 'Decision provenance columns', 'fail',
                count($missing) . ' missing: ' . implode('; ', array_slice($missing, 0, 3)),
                'Run the outstanding migrations — decisions recorded meanwhile cannot be explained later.');
    }

    /** @return array<int, array<string, mixed>> */
    private function criticalIndexes(): array
    {
        $out = [];
        foreach (self::CRITICAL_INDEXES as $table => $indexes) {
            $key = "schema.indexes.{$table}";
            if (! Schema::hasTable($table)) {
                $out[] = $this->row('schema', $key, "Indexes — {$table}", 'warn', 'Table does not exist yet.', 'Run php artisan migrate.');
                continue;
            }
            $present = $this->indexNames($table);
            $missing = [];
            foreach ($indexes as $name => $why) {
                if (! in_array($name, $present, true)) {
                    $missing[] = "{$name} — {$why}";
                }
            }
            $out[] = empty($missing)
                ? $this->row('schema', $key, "Indexes — {$table}", 'ok', count($indexes) . ' critical index(es) present.', null)
                : $this->row('schema', $key, "Indexes — {$table}", 'fail',
                    count($missing) . ' missing: ' . implode(' | ', $missing),
                    'Add a corrective migration with EXPLICIT short index names — auto-generated names over 64 characters fail silently at create time.');
        }
        return $out;
    }

    /**
     * SCHEMA DRIFT — has anyone changed this database outside a migration?
     *
     * Expected shape is obtained by actually RUNNING the migrations into a throwaway database, not by
     * parsing them. The migrations are the specification; materialising them is the only faithful way to
     * read it, and it costs one scratch schema. Anything the live database has that the fresh build does
     * not (or vice versa) went in by hand and will be lost or will collide on the next deploy.
     *
     * Expensive (~30s), hence opt-in.
     */
    private function driftCheck(): array
    {
        $scratch = 'schema_drift_probe';
        $live = DB::getDatabaseName();

        if ($scratch === $live) {
            return $this->row('schema', 'schema.drift', 'Schema drift', 'warn', 'Refusing to probe: scratch name collides with the live database.', null);
        }

        try {
            DB::statement("DROP DATABASE IF EXISTS `{$scratch}`");
            DB::statement("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            Config::set('database.connections.schema_drift', array_merge(config('database.connections.mysql'), ['database' => $scratch]));
            DB::purge('schema_drift');

            \Artisan::call('migrate', ['--database' => 'schema_drift', '--force' => true, '--no-interaction' => true]);

            $expected = $this->describe($scratch);
            $actual = $this->describe($live);
            $diff = $this->diff($expected, $actual);
        } catch (\Throwable $e) {
            return $this->row('schema', 'schema.drift', 'Schema drift', 'warn',
                'Drift probe could not run: ' . $e->getMessage(), 'Needs permission to CREATE DATABASE.');
        } finally {
            try {
                DB::purge('schema_drift');
                DB::statement("DROP DATABASE IF EXISTS `{$scratch}`");
            } catch (\Throwable) {
                // best effort — a leftover scratch schema is harmless
            }
        }

        if (empty($diff)) {
            return $this->row('schema', 'schema.drift', 'Schema drift', 'ok', 'Live schema matches a clean migration run exactly.', null);
        }

        return $this->row('schema', 'schema.drift', 'Schema drift', 'warn',
            count($diff) . ' difference(s) vs a clean migration run: ' . implode(' | ', array_slice($diff, 0, 5)) . (count($diff) > 5 ? ' …' : ''),
            'Each difference is a change that never went through a migration. Capture it in one, or the next fresh deploy will not have it.',
            ['differences' => $diff]);
    }

    /**
     * Tables → columns / indexes / foreign keys, from information_schema.
     *
     * @return array<string, array<string, array<int, string>>>
     */
    private function describe(string $database): array
    {
        $out = [];
        foreach (DB::select('SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = ?', [$database]) as $r) {
            $out[$r->t]['columns'][] = $r->c;
        }
        foreach (DB::select('SELECT table_name AS t, index_name AS i FROM information_schema.statistics WHERE table_schema = ? GROUP BY table_name, index_name', [$database]) as $r) {
            $out[$r->t]['indexes'][] = $r->i;
        }
        foreach (DB::select('SELECT table_name AS t, constraint_name AS k FROM information_schema.key_column_usage WHERE table_schema = ? AND referenced_table_name IS NOT NULL', [$database]) as $r) {
            $out[$r->t]['foreign_keys'][] = $r->k;
        }
        // `migrations` itself legitimately differs (row content aside, it exists in both) — keep it.
        return $out;
    }

    /** @return array<int, string> human-readable differences */
    private function diff(array $expected, array $actual): array
    {
        $diff = [];
        foreach (array_diff(array_keys($actual), array_keys($expected)) as $extra) {
            $diff[] = "table {$extra} exists live but not in a clean build";
        }
        foreach (array_diff(array_keys($expected), array_keys($actual)) as $absent) {
            $diff[] = "table {$absent} missing from live";
        }
        foreach ($expected as $table => $shape) {
            if (! isset($actual[$table])) {
                continue;
            }
            foreach (['columns', 'indexes', 'foreign_keys'] as $kind) {
                $e = $shape[$kind] ?? [];
                $a = $actual[$table][$kind] ?? [];
                foreach (array_diff($a, $e) as $x) {
                    $diff[] = "{$table}.{$x} ({$kind}) exists live but not in a clean build";
                }
                foreach (array_diff($e, $a) as $x) {
                    $diff[] = "{$table}.{$x} ({$kind}) missing from live";
                }
            }
        }
        return $diff;
    }

    // ── Decision engine ───────────────────────────────────────────────────────────────────────────

    private function decisionProvenance(): array
    {
        if (! Schema::hasTable('garage_recommendation_decisions') || ! Schema::hasColumn('garage_recommendation_decisions', 'engine_version')) {
            return $this->row('engine', 'engine.provenance', 'Decision provenance', 'fail', 'The provenance columns do not exist.', 'Run php artisan migrate.');
        }
        $total = DB::table('garage_recommendation_decisions')->count();
        if ($total === 0) {
            return $this->row('engine', 'engine.provenance', 'Decision provenance', 'ok', 'No decisions recorded yet — nothing to stamp.', null);
        }
        $stamped = DB::table('garage_recommendation_decisions')->whereNotNull('engine_version')->count();
        $pct = round($stamped / $total * 100, 1);

        // A calendar window is the wrong way to exclude pre-provenance decisions: on the day it ships,
        // every historical decision looks like a regression. The honest boundary is the FIRST stamped
        // decision — before that the feature was not in use, after it any gap is real.
        $firstStamped = DB::table('garage_recommendation_decisions')->whereNotNull('engine_version')->min('created_at');
        if ($firstStamped === null) {
            return $this->row('engine', 'engine.provenance', 'Decision provenance', 'ok',
                "No decision carries an engine version yet — all {$total} predate provenance. The next dispatch will be stamped.", null);
        }

        $gaps = DB::table('garage_recommendation_decisions')->where('created_at', '>=', $firstStamped)->whereNull('engine_version')->count();

        return $gaps > 0
            ? $this->row('engine', 'engine.provenance', 'Decision provenance', 'warn',
                "{$gaps} decision(s) recorded since provenance shipped carry no engine version ({$pct}% of all decisions stamped).",
                'The assign-dispatch payload is dropping `provenance` — those decisions cannot be explained once the engine changes.')
            : $this->row('engine', 'engine.provenance', 'Decision provenance', 'ok',
                "{$stamped} of {$total} decisions stamped ({$pct}%)" . ($pct < 100 ? '; the rest predate provenance.' : '.'), null);
    }

    /**
     * A PARTIALLY stamped decision is not provenance — it is the appearance of it. Knowing the engine
     * build but not the tuning fingerprint still leaves the decision unexplainable, so any row missing
     * one of the four fields is reported as invalid rather than counted as present.
     */
    private function provenanceCompleteness(): array
    {
        $table = 'garage_recommendation_decisions';
        if (! Schema::hasTable($table)) {
            return $this->row('engine', 'engine.provenance_valid', 'Provenance completeness', 'fail', 'The decisions table does not exist.', 'Run php artisan migrate.');
        }
        foreach (self::PROVENANCE_FIELDS as $f) {
            if (! Schema::hasColumn($table, $f)) {
                return $this->row('engine', 'engine.provenance_valid', 'Provenance completeness', 'fail',
                    "The `{$f}` column does not exist, so provenance can never be complete.", 'Run php artisan migrate.');
            }
        }

        // Only rows that CLAIM provenance are graded; untouched historical rows are not "invalid".
        $claimed = DB::table($table)->where(function ($q) {
            foreach (self::PROVENANCE_FIELDS as $f) {
                $q->orWhereNotNull($f);
            }
        })->count();

        if ($claimed === 0) {
            return $this->row('engine', 'engine.provenance_valid', 'Provenance completeness', 'ok', 'No stamped decisions yet — nothing to validate.', null);
        }

        $incomplete = DB::table($table)->where(function ($q) {
            foreach (self::PROVENANCE_FIELDS as $f) {
                $q->orWhereNotNull($f);
            }
        })->where(function ($q) {
            foreach (self::PROVENANCE_FIELDS as $f) {
                $q->orWhereNull($f);
            }
        })->count();

        return $incomplete === 0
            ? $this->row('engine', 'engine.provenance_valid', 'Provenance completeness', 'ok',
                "All {$claimed} stamped decision(s) carry the full set (" . implode(', ', self::PROVENANCE_FIELDS) . ').', null)
            : $this->row('engine', 'engine.provenance_valid', 'Provenance completeness', 'fail',
                "{$incomplete} of {$claimed} stamped decision(s) are PARTIALLY stamped — provenance present but incomplete.",
                'A partial stamp cannot explain a decision. Check that the assign-dispatch payload sends the whole `provenance` object.');
    }

    // ── Calibration ───────────────────────────────────────────────────────────────────────────────

    /** Are new decisions capturing a forecast at all? Without one, nothing can ever be scored. */
    private function forecastCoverage(): array
    {
        $table = 'garage_recommendation_decisions';
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'expected_outcomes')) {
            return $this->row('calibration', 'calibration.coverage', 'Forecast coverage', 'fail', 'The forecast column does not exist.', 'Run php artisan migrate.');
        }
        $total = DB::table($table)->count();
        $withForecast = DB::table($table)->whereNotNull('expected_outcomes')->count();
        if ($total === 0) {
            return $this->row('calibration', 'calibration.coverage', 'Forecast coverage', 'ok', 'No decisions recorded yet.', null);
        }
        $pct = round($withForecast / $total * 100, 1);

        return $withForecast === 0
            ? $this->row('calibration', 'calibration.coverage', 'Forecast coverage', 'warn',
                "None of {$total} decision(s) carry a forecast.",
                'Expected while decisions predate forecasting; if it persists, assign-dispatch is dropping `expected_outcomes`.')
            : $this->row('calibration', 'calibration.coverage', 'Forecast coverage', $pct >= 90 ? 'ok' : 'warn',
                "{$withForecast} of {$total} decision(s) carry a forecast ({$pct}%).",
                $pct >= 90 ? null : 'Decisions without a forecast can never be scored for accuracy.');
    }

    /** Has the loop actually closed — is anything scored against reality yet? */
    private function calibrationData(): array
    {
        $table = 'garage_recommendation_decisions';
        if (! Schema::hasTable($table)) {
            return $this->row('calibration', 'calibration.scored', 'Calibration', 'fail', 'The decisions table does not exist.', 'Run php artisan migrate.');
        }
        $withForecast = DB::table($table)->whereNotNull('expected_outcomes')->count();
        $scored = DB::table($table)->whereNotNull('scored_at')->count();
        $backtestable = DB::table('maintenances')->whereNotNull('vendor_id')->whereNotNull('out_date')->whereNotNull('actual_in_date')->count();

        if ($withForecast === 0) {
            return $this->row('calibration', 'calibration.scored', 'Calibration', 'warn',
                "Learning — the live loop has nothing to score yet; {$backtestable} completed repairs are available for backtesting.",
                'Expected: the loop only covers decisions made after forecasting shipped. Backtest meanwhile with php artisan intelligence:forecast-calibration.');
        }

        return $this->row('calibration', 'calibration.scored', 'Calibration', $scored > 0 ? 'ok' : 'warn',
            "{$scored} of {$withForecast} forecast(s) scored against reality.",
            $scored === 0 ? 'Run php artisan intelligence:forecast-calibration --score.' : null);
    }

    /**
     * Is the expense ledger every repair-cost figure rests on still being written to?
     *
     * This is monitored rather than merely displayed because it is the failure mode that produces no
     * symptom. A frozen source does not error, does not empty out and does not look wrong — the medians
     * keep computing over years of history and keep looking precise while describing a period that has
     * ended. Without a check, the first sign of trouble is somebody querying a price months later.
     */
    private function costSourceFreshness(): array
    {
        if (! Schema::hasTable('vehicle_expenses')) {
            return $this->row('calibration', 'cost.freshness', 'Cost source', 'warn',
                'No expense ledger table — repair cost estimation is unavailable.', 'Expected if the expense import has never run.');
        }

        try {
            $f = app(\App\Contracts\VehicleExpenseProvider::class)->freshness();
        } catch (\Throwable $e) {
            return $this->row('calibration', 'cost.freshness', 'Cost source', 'warn',
                'Could not read expense freshness: ' . $e->getMessage(), 'Check the expense provider binding.');
        }

        // `frozen` is a genuine failure: cost figures are being shown for a source that has stopped.
        // `declining` and `stale` are warnings — the data is still usable, just increasingly historical.
        $status = match ($f['status']) {
            'current'   => 'ok',
            'frozen'    => 'fail',
            default     => 'warn',
        };

        $fix = match ($f['status']) {
            'frozen'    => 'Confirm whether the expense sheet is still maintained. Until it resumes, treat every repair-cost figure as historical and do not quote it as current.',
            'declining' => 'Check whether the expense import is partially failing or the sheet is being filled in less completely than it was.',
            'stale'     => 'Confirm the expense import is still running.',
            'unknown'   => 'No dated expense lines — the import may never have completed.',
            default     => null,
        };

        return $this->row('calibration', 'cost.freshness', 'Cost source', $status, $f['message'], $fix);
    }

    /**
     * Is the operation actually feeding the decision loop?
     *
     * Two distinct failures, and the second is invisible without this check: dispatches happening with
     * no recommendation on screen at all (the panel is being bypassed), and overrides recorded with no
     * reason (the capture UI is being skipped). Either one silently starves the learning loop while
     * every acceptance figure still renders as though it were healthy.
     */
    private function decisionCapture(): array
    {
        $table = 'garage_recommendation_decisions';
        if (! Schema::hasTable($table)) {
            return $this->row('calibration', 'decision.capture', 'Decision capture', 'fail',
                'The decisions table does not exist.', 'Run php artisan migrate.');
        }
        if (! Schema::hasColumn($table, 'override_reason')) {
            return $this->row('calibration', 'decision.capture', 'Decision capture', 'fail',
                'The override-learning columns are missing.', 'Run php artisan migrate.');
        }

        $total = DB::table($table)->count();
        if ($total === 0) {
            return $this->row('calibration', 'decision.capture', 'Decision capture', 'warn',
                'No garage decisions recorded yet — the learning loop has no input.',
                'Expected until supervisors start assigning through the workflow. Verify with php artisan intelligence:decision-learning.');
        }

        $unadvised = DB::table($table)->whereNull('recommended_vendor_id')->count();
        $overrides = DB::table($table)->where('followed', false)->count();
        $unexplained = DB::table($table)->where('followed', false)->whereNull('override_reason')->count();

        $problems = [];
        if ($total >= 10 && $unadvised / $total > 0.5) {
            $problems[] = "{$unadvised} of {$total} assignments were made with no recommendation on screen";
        }
        if ($overrides >= 5 && $unexplained / $overrides > 0.5) {
            $problems[] = "{$unexplained} of {$overrides} overrides carry no reason";
        }

        return $problems
            ? $this->row('calibration', 'decision.capture', 'Decision capture', 'warn', implode('; ', $problems) . '.',
                'The learning loop only sees what is captured. Check the assign screen is showing the recommendation panel and asking for a reason on override.')
            : $this->row('calibration', 'decision.capture', 'Decision capture', 'ok',
                "{$total} decision(s) recorded; {$overrides} override(s), {$unexplained} without a stated reason.", null);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────────────────────────

    /** @return array<int, string> */
    private function indexNames(string $table): array
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->distinct()->pluck('index_name')->all();
    }

    private function row(string $category, string $key, string $label, string $status, string $detail, ?string $fix, array $facts = []): array
    {
        return compact('category', 'key', 'label', 'status', 'detail', 'fix', 'facts');
    }
}
