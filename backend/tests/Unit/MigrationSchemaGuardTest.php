<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * STATIC guards on the migration set — the defects that are cheap to catch by reading the files and
 * expensive to catch any other way.
 *
 * These exist because of a real incident: `fault_concept_actions` shipped with NO indexes, including the
 * unique constraint that was the only thing preventing duplicate fault→action links. The cause was that
 * Laravel's auto-generated index name came to 65 characters — one over MySQL's 64-char identifier limit —
 * so the migration failed on every database it ever touched, including empty ones. Nothing caught it:
 * the table existed, the app ran, and the numbers were quietly wrong.
 *
 * A full end-to-end proof (migrate an empty database, seed it, verify the guarantees) lives in
 * `php artisan schema:verify-fresh`, which needs a real MySQL server and belongs in CI. THESE tests need
 * nothing but the filesystem, so they run in the normal unit suite on every change and fail in
 * milliseconds — the point being that this class of defect never again reaches a database at all.
 */
class MigrationSchemaGuardTest extends TestCase
{
    /** MySQL / MariaDB hard limit on identifier length. */
    private const MAX_IDENTIFIER = 64;

    /** @return array<int, string> */
    private function migrationFiles(): array
    {
        return glob(__DIR__ . '/../../database/migrations/*.php') ?: [];
    }

    public function test_the_migration_directory_is_actually_being_scanned(): void
    {
        // Guards the guard: a broken glob would make every test below pass vacuously.
        $this->assertGreaterThan(100, count($this->migrationFiles()), 'expected the full migration set');
    }

    /**
     * THE REGRESSION TEST. Every index/unique declared without an explicit name must produce a generated
     * name that MySQL will accept. Laravel builds it as `{table}_{col1}_{col2}_{type}`, and silently
     * hands the over-long result to the driver, which rejects it at runtime.
     */
    public function test_no_generated_index_name_exceeds_the_identifier_limit(): void
    {
        $violations = [];

        foreach ($this->migrationFiles() as $file) {
            $table = null;
            foreach (file($file) as $lineNo => $line) {
                // Track which table the current builder block is operating on.
                if (preg_match("/Schema::(?:create|table)\(\s*'([^']+)'/", $line, $m)) {
                    $table = $m[1];
                }
                if ($table === null) {
                    continue;
                }

                // ->unique([...]) / ->index([...]) / ->unique('col') with NO second (name) argument.
                if (! preg_match('/->(unique|index)\(\s*(\[[^\]]*\]|\'[^\']*\')\s*(,|\))/', $line, $m)) {
                    continue;
                }
                [$type, $colsRaw, $terminator] = [$m[1], $m[2], $m[3]];
                if ($terminator === ',') {
                    continue;   // an explicit name was supplied — the whole point of the fix
                }

                preg_match_all("/'([^']+)'/", $colsRaw, $colMatches);
                $columns = $colMatches[1];
                if (empty($columns)) {
                    continue;
                }

                $generated = strtolower(implode('_', array_merge([$table], $columns, [$type])));
                if (strlen($generated) > self::MAX_IDENTIFIER) {
                    $violations[] = sprintf(
                        "%s:%d — %s (%d chars)",
                        basename($file), $lineNo + 1, $generated, strlen($generated),
                    );
                }
            }
        }

        $this->assertSame([], $violations, sprintf(
            "%d index name(s) would exceed MySQL's %d-character limit and fail at migrate time.\n"
            . "Give each an explicit short name: \$table->unique([...], 'short_name').\n%s",
            count($violations), self::MAX_IDENTIFIER, implode("\n", $violations),
        ));
    }

    /**
     * The same limit applies to table names themselves, and a long table name is what pushes generated
     * index names over the edge in the first place.
     */
    public function test_no_table_name_exceeds_the_identifier_limit(): void
    {
        $violations = [];
        foreach ($this->migrationFiles() as $file) {
            preg_match_all("/Schema::create\(\s*'([^']+)'/", file_get_contents($file), $m);
            foreach ($m[1] as $table) {
                if (strlen($table) > self::MAX_IDENTIFIER) {
                    $violations[] = basename($file) . " — {$table}";
                }
            }
        }
        $this->assertSame([], $violations, "Table name(s) over the identifier limit:\n" . implode("\n", $violations));
    }

    /**
     * Two migrations adding the SAME column to the SAME table is the duplicate-column defect that made a
     * fresh deploy fail. Renames and drops are excluded, since a column legitimately reappears after
     * being dropped — this looks only for two live additions of the same name.
     */
    public function test_no_column_is_added_twice_to_the_same_table(): void
    {
        $added = [];     // "table.column" => [migration, …]
        $dropped = [];   // "table.column" => true

        foreach ($this->migrationFiles() as $file) {
            $name = basename($file, '.php');
            $table = null;
            foreach (file($file) as $line) {
                // Only up() declares the forward schema. down() re-adds columns on rollback, which is
                // not a second addition, and counting it flagged half the migration set.
                if (preg_match('/function down\s*\(/', $line)) {
                    break;
                }
                if (preg_match("/Schema::(?:create|table)\(\s*'([^']+)'/", $line, $m)) {
                    $table = $m[1];
                }
                if ($table === null) {
                    continue;
                }
                // `->change()` MODIFIES an existing column — making one nullable is not adding it twice.
                if (str_contains($line, '->change()')) {
                    continue;
                }
                if (preg_match("/->dropColumn\(|->dropConstrainedForeignId\(|->renameColumn\(/", $line)) {
                    preg_match_all("/'([^']+)'/", $line, $dm);
                    foreach ($dm[1] as $col) {
                        $dropped["{$table}.{$col}"] = true;
                    }
                    continue;
                }
                // A column-adding builder call: ->string('x'), ->timestamp('x'), ->foreignId('x')…
                if (preg_match("/->(string|text|integer|bigInteger|unsignedBigInteger|unsignedInteger|unsignedTinyInteger|tinyInteger|smallInteger|boolean|date|dateTime|timestamp|decimal|float|double|json|enum|foreignId|char|year)\(\s*'([^']+)'/", $line, $m)) {
                    $key = "{$table}.{$m[2]}";
                    $added[$key][] = $name;
                }
            }
        }

        $duplicates = [];
        foreach ($added as $key => $migrations) {
            $migrations = array_values(array_unique($migrations));
            // More than one DISTINCT migration adding it, and never dropped in between.
            if (count($migrations) > 1 && ! isset($dropped[$key])) {
                $duplicates[] = $key . ' ← ' . implode(', ', $migrations);
            }
        }

        $this->assertSame([], $duplicates, sprintf(
            "%d column(s) are added by more than one migration without an intervening drop.\n"
            . "On an empty database the second one fails with 'Duplicate column name'.\n%s",
            count($duplicates), implode("\n", $duplicates),
        ));
    }

    /** Every migration file must actually be loadable and expose up() — a fatal here blocks a deploy. */
    public function test_every_migration_declares_an_up_method(): void
    {
        $bad = [];
        foreach ($this->migrationFiles() as $file) {
            $src = file_get_contents($file);
            if (! str_contains($src, 'function up(')) {
                $bad[] = basename($file);
            }
        }
        $this->assertSame([], $bad, "Migration(s) without an up() method:\n" . implode("\n", $bad));
    }
}
