<?php

namespace Tests\Golden;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Base for the golden-number suite.
 *
 * NO RefreshDatabase, deliberately — these tests assert facts about the REAL corpus, so wiping the
 * schema before each test would remove the thing under test. The suite runs against `laravel_golden`,
 * a clone produced by run-golden-tests.cmd, and is read-only apart from the two rebuild commands,
 * which rewrite only their own derived tables.
 *
 * If the clone is missing or empty the whole suite SKIPS rather than fails: a developer who has not
 * cloned yet has not broken anything, and a red suite that means "you didn't run the setup script"
 * quickly teaches people to ignore red suites.
 */
abstract class GoldenTestCase extends TestCase
{
    /** Guard against ever pointing this suite at the live schema. */
    private const FORBIDDEN_DATABASES = ['laravel'];

    protected function setUp(): void
    {
        parent::setUp();

        $database = config('database.connections.mysql.database');

        if (in_array($database, self::FORBIDDEN_DATABASES, true)) {
            $this->fail(
                "The golden suite is pointed at [{$database}]. It must run against a clone "
                . '(laravel_golden) — it rebuilds derived tables and must never touch live data.'
            );
        }

        if (! $this->cloneIsReady()) {
            $this->markTestSkipped(
                "Golden clone [{$database}] is missing or empty. Run: run-golden-tests.cmd"
            );
        }
    }

    private function cloneIsReady(): bool
    {
        try {
            return DB::table('maintenances')->count() > 1000;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Assert a measured value against its expected golden value.
     *
     * The message is deliberately long. A golden failure is resolved one of exactly two ways, and
     * whoever hits it at 5pm on a Friday should not have to find the document that says so:
     * either the code regressed, or the data legitimately moved and the expectation is updated IN
     * THE SAME COMMIT as a written justification. Silently re-baselining turns this suite from a
     * safety net into decoration.
     */
    protected function assertGolden(string $id, float|int $expected, float|int $actual, float $tolerance = 0.0): void
    {
        $delta = abs($expected - $actual);

        $this->assertLessThanOrEqual(
            $tolerance,
            $delta,
            "GOLDEN {$id} moved: expected {$expected} (±{$tolerance}), got {$actual}.\n"
            . "Resolve this ONE of two ways:\n"
            . "  1. A regression — fix the code. The expectation was right.\n"
            . "  2. The data legitimately moved — update the expectation IN THIS COMMIT and add a\n"
            . "     one-line justification to the test docblock naming what changed and why.\n"
            . 'Never update a golden number without the justification line.'
        );
    }
}
