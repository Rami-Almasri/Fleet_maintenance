<?php

namespace Tests\Foundation;

use App\Services\Schema\SchemaHealthService;
use ReflectionMethod;

/**
 * The drift comparator must compare DEFINITIONS, not names.
 *
 * This exists because the check shipped comparing names only and reported "Live schema matches a
 * clean migration run exactly" for weeks while `invoices.discount` was decimal(12,2) live and
 * decimal(14,2) in a clean build. A schema guard that cannot see a narrowed column is worse than no
 * guard: it is a green light over a real defect.
 *
 * Drives `diff()` directly with synthetic shapes — no database, no scratch schema, runs in
 * milliseconds — so the comparison logic is pinned independently of whatever the live schema happens
 * to look like on the machine running the suite.
 */
class SchemaDriftComparatorTest extends FoundationTestCase
{
    private function diff(array $expected, array $actual): array
    {
        $m = new ReflectionMethod(SchemaHealthService::class, 'diff');
        $m->setAccessible(true);

        return $m->invoke(app(SchemaHealthService::class), $expected, $actual);
    }

    /** One column, identical on both sides — the shape every other case is a deviation from. */
    private function shape(array $columnAttrs = []): array
    {
        return ['invoices' => ['columns' => ['discount' => array_merge([
            'type' => 'decimal(14,2)', 'nullable' => 'YES', 'default' => '∅', 'extra' => '', 'collation' => '',
        ], $columnAttrs)]]];
    }

    public function test_identical_schemas_report_no_drift(): void
    {
        $this->assertSame([], $this->diff($this->shape(), $this->shape()));
    }

    /** THE REGRESSION. Same column name, narrower precision — previously invisible. */
    public function test_detects_a_narrowed_decimal_precision(): void
    {
        $diff = $this->diff($this->shape(), $this->shape(['type' => 'decimal(12,2)']));

        $this->assertCount(1, $diff);
        $this->assertSame(
            'invoices.discount (column) type: clean decimal(14,2), live decimal(12,2)',
            $diff[0],
            'The message is matched EXACTLY by config/schema.php accepted_drift — changing its wording silently voids every allowlist entry.'
        );
    }

    public function test_detects_nullability_default_unsigned_and_collation_changes(): void
    {
        $cases = [
            ['nullable', 'NO', 'nullable: clean YES, live NO'],
            ['default', '0.00', 'default: clean ∅, live 0.00'],
            ['type', 'decimal(14,2) unsigned', 'type: clean decimal(14,2), live decimal(14,2) unsigned'],
            ['collation', 'utf8mb4_bin', 'collation: clean , live utf8mb4_bin'],
        ];

        foreach ($cases as [$attr, $live, $expectedTail]) {
            $diff = $this->diff($this->shape(), $this->shape([$attr => $live]));
            $this->assertCount(1, $diff, "expected exactly one difference for {$attr}");
            $this->assertSame("invoices.discount (column) {$expectedTail}", $diff[0]);
        }
    }

    /** A foreign key silently downgraded from CASCADE to RESTRICT changes what a delete DOES. */
    public function test_detects_a_changed_foreign_key_rule(): void
    {
        $fk = fn (string $onDelete) => ['warranties' => ['foreign_keys' => ['warranties_vehicle_id_foreign' => [
            'columns' => 'vehicle_id', 'references' => 'vehicles(id)', 'on_update' => 'NO ACTION', 'on_delete' => $onDelete,
        ]]]];

        $diff = $this->diff($fk('CASCADE'), $fk('RESTRICT'));

        $this->assertSame(
            ['warranties.warranties_vehicle_id_foreign (foreign key) on_delete: clean CASCADE, live RESTRICT'],
            $diff
        );
    }

    /** An index on (a,b) is not the index on (b,a) — it stops covering the query it was made for. */
    public function test_detects_a_reordered_index(): void
    {
        $ix = fn (string $cols) => ['warranties' => ['indexes' => ['idx' => ['unique' => 'no', 'columns' => $cols]]]];

        $this->assertSame(
            ['warranties.idx (index) columns: clean vehicle_id,status, live status,vehicle_id'],
            $this->diff($ix('vehicle_id,status'), $ix('status,vehicle_id'))
        );
    }

    public function test_still_reports_wholly_added_and_missing_objects(): void
    {
        $empty = ['invoices' => ['columns' => []]];

        $this->assertSame(
            ['invoices.discount (column) exists live but not in a clean build'],
            $this->diff($empty, $this->shape())
        );
        $this->assertSame(
            ['invoices.discount (column) missing from live'],
            $this->diff($this->shape(), $empty)
        );
    }

    /**
     * The allowlist must be exact-match. An accepted difference that drifts FURTHER has to stop
     * matching and fail the build, or one entry silently excuses every future change to that column.
     */
    public function test_accepted_drift_entries_match_the_current_message_format(): void
    {
        $accepted = (array) config('schema.accepted_drift', []);

        $this->assertNotEmpty($accepted, 'Expected the known invoices drift to be listed.');

        foreach ($accepted as $entry) {
            $this->assertMatchesRegularExpression(
                '/^[a-z_]+\.[A-Za-z0-9_]+ \((column|index|foreign key)\) /',
                $entry,
                "Accepted-drift entry no longer matches what diff() emits, so it excuses nothing: {$entry}"
            );
        }

        // The exact entry the comparator must still produce for the known invoices drift.
        $this->assertContains(
            'invoices.discount (column) type: clean decimal(14,2), live decimal(12,2)',
            $accepted
        );
    }
}
