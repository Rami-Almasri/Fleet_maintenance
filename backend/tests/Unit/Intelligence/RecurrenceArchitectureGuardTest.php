<?php

namespace Tests\Unit\Intelligence;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * THE GUARD THAT MAKES DIVERGENCE IMPOSSIBLE RATHER THAN UNLIKELY.
 *
 * ── WHY A STATIC TEST AND NOT A CONVENTION ───────────────────────────────────────────────────────
 * The platform did not end up with six recurrence implementations through carelessness. Every one of
 * them carried a comment asking the next person to keep it in step with the others — the convention
 * existed, was written down, and was followed by nobody, because a comment cannot fail a build.
 *
 * So the rule is now executable: a seventh implementation fails CI on the day it is written, not a
 * year later when two dashboards disagree in front of the owner.
 *
 * ── WHAT IS FORBIDDEN ────────────────────────────────────────────────────────────────────────────
 * Deriving recurrence from raw `maintenance_signatures` — a self-join, an EXISTS over `occurred_at`,
 * or a DATE_ADD window on it. That is the fingerprint of "did this fault come back?", and it belongs
 * in exactly one place.
 *
 * ── WHAT IS NOT FORBIDDEN ────────────────────────────────────────────────────────────────────────
 * Reading `maintenance_signatures` at all. It is the capture layer and plenty of code legitimately
 * lists it, joins it for vocabulary, or retrieves a car's history from it. The distinction the guard
 * enforces is RETRIEVAL versus MEASUREMENT: retrieving what was recorded is fine; deriving a rate
 * from it is not.
 *
 * @see docs/Recurrence-Convergence-Matrix.md
 * @see docs/Metric-Specification-Recurrence.md
 */
class RecurrenceArchitectureGuardTest extends TestCase
{
    /**
     * The ONLY files permitted to compute recurrence from raw signatures.
     *
     * Every entry needs a reason and an exit condition. An allowlist without them grows quietly
     * until it is the architecture.
     */
    private const ALLOWED = [
        // The canonical builder — it is what turns capture into the intelligence dataset.
        'app/Intelligence/Support/RecurrencePairBuilder.php' => 'builds the canonical dataset (C6)',
        'app/Console/Commands/IntelligenceRebuildRecurrence.php' => 'runs the canonical build (C6)',

        // Compares the two definitions on purpose — that is its entire job, and it is deletable
        // once Phase 2 lands.
        'app/Console/Commands/IntelligenceRecurrenceCompare.php' => 'the legacy-vs-canonical gate; retire after Phase 2',

        // QUARANTINED BY DECISION — Phase 2. These feed routing, so repointing them changes where
        // cars are physically sent and needs its own impact proposal.
        'app/Services/Garage/GarageOutcomeForecaster.php' => 'C3 — routing; Phase 2',
        'app/Services/Garage/ForecastCalibration.php' => 'C4 — calibrates C3, must move with it; Phase 2',
    ];

    /** Exactly the two consumers deferred to Phase 2. This number must fall to zero, never rise. */
    private const QUARANTINED_COUNT = 2;

    /** The fingerprint of a recurrence calculation over the capture table. */
    private const FORBIDDEN_PATTERNS = [
        '/FROM\s+maintenance_signatures\s+\w+\s/i'          => 'a self-join alias over maintenance_signatures',
        '/maintenance_signatures\s+b\b/i'                   => 'the classic "b" self-join alias',
        '/DATE_ADD\(\s*a\.occurred_at/i'                    => 'a comeback window over raw signatures',
        '/EXISTS\s*\(\s*SELECT[^)]*maintenance_signatures/is' => 'an EXISTS recurrence probe over raw signatures',
    ];

    public function test_no_recurrence_calculation_exists_outside_the_canonical_repository(): void
    {
        $offenders = [];

        foreach ($this->phpFiles() as $relative => $contents) {
            if (isset(self::ALLOWED[$relative])) {
                continue;
            }

            foreach (self::FORBIDDEN_PATTERNS as $pattern => $description) {
                if (preg_match($pattern, $contents)) {
                    $offenders[$relative][] = $description;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A recurrence calculation was found outside the canonical repository:\n\n"
            . collect($offenders)->map(fn ($why, $file) => "  {$file}\n    → " . implode("\n    → ", $why))->implode("\n")
            . "\n\nRecurrence has ONE definition (config/metrics/recurrence.php), ONE dataset\n"
            . "(fault_recurrence_pairs) and ONE reader (App\\Intelligence\\Recurrence\\RecurrenceRepository).\n"
            . "Ask the repository for the grain you need — it serves fleet, garage, garage×domain,\n"
            . "signature, vehicle and episode lookups. If none fits, ADD A METHOD THERE.\n\n"
            . "Adding your file to the allowlist is not the fix. The last time this rule lived in a\n"
            . "comment instead of a test, the platform published three different fleet comeback rates\n"
            . "and one of them routed cars. See docs/Metric-Specification-Recurrence.md.",
        );
    }

    /**
     * Only the canonical repository may query the canonical table.
     *
     * The dataset is the intelligence layer's private storage. A consumer reaching past the
     * repository would bypass the window, the horizon and the coverage reporting — arriving at a
     * number that looks canonical and is not.
     */
    public function test_only_the_repository_reads_the_canonical_table(): void
    {
        $allowed = [
            'app/Intelligence/Recurrence/RecurrenceRepository.php',
            'app/Console/Commands/IntelligenceRebuildRecurrence.php',   // builds it
            'app/Console/Commands/IntelligenceConvergenceAudit.php',    // audits it
            'app/Console/Commands/IntelligenceRecurrenceCompare.php',   // compares definitions
            'app/Models/FaultRecurrencePair.php',                       // the model itself
        ];

        $offenders = [];

        foreach ($this->phpFiles() as $relative => $contents) {
            if (in_array($relative, $allowed, true)) {
                continue;
            }
            // Match a QUERY against the table, not a mention of its name. RebuildLedger, for one,
            // passes 'fault_recurrence_pairs' as an identifier for a health row and never reads the
            // table — flagging that would teach people the guard cries wolf, which is how a guard
            // stops being read.
            $queryPatterns = [
                "/DB::table\(\s*['\"]fault_recurrence_pairs['\"]/i",
                "/->from\(\s*['\"]fault_recurrence_pairs['\"]/i",
                "/->join\(\s*['\"]fault_recurrence_pairs/i",
                '/\bFROM\s+fault_recurrence_pairs\b/i',
                '/\bJOIN\s+fault_recurrence_pairs\b/i',
            ];

            foreach ($queryPatterns as $pattern) {
                if (preg_match($pattern, $contents)) {
                    $offenders[] = $relative;
                    break;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These files query the canonical table directly, bypassing RecurrenceRepository:\n  "
            . implode("\n  ", $offenders)
            . "\n\nGoing straight to the table skips the window, the observation horizon and the\n"
            . 'coverage reporting — producing a number that looks canonical and is not.',
        );
    }

    /**
     * The quarantine must shrink, never grow.
     *
     * Two files are deliberately still on the legacy definition because they feed routing. That is a
     * declared, temporary exemption with a Phase 2 exit — not a permanent carve-out, and this
     * assertion is what stops it quietly becoming one.
     */
    public function test_exactly_two_consumers_remain_quarantined(): void
    {
        $quarantined = array_filter(
            self::ALLOWED,
            fn ($reason) => str_contains($reason, 'Phase 2') && ! str_contains($reason, 'retire after'),
        );

        $this->assertCount(
            self::QUARANTINED_COUNT,
            $quarantined,
            "The Phase 2 quarantine changed.\n"
            . "If a consumer was MIGRATED: remove it from ALLOWED and lower QUARANTINED_COUNT.\n"
            . "If one was ADDED: it should not have been. A new legacy implementation is the thing\n"
            . 'this whole convergence exists to prevent.',
        );

        foreach (array_keys($quarantined) as $file) {
            $this->assertFileExists($this->root($file), "Quarantined file is gone — remove it from the allowlist.");
        }
    }

    public function test_every_allowlist_entry_names_a_real_file_and_a_reason(): void
    {
        foreach (self::ALLOWED as $file => $reason) {
            $this->assertFileExists($this->root($file), "Allowlisted file no longer exists: {$file}");
            $this->assertNotEmpty($reason, "Allowlist entry {$file} has no reason. An unexplained exemption becomes permanent.");
        }
    }

    // ── Version-aware caching ───────────────────────────────────────────────────────────────────

    /**
     * Any cache holding a recurrence answer must key on the metric version.
     *
     * Without it, a deploy keeps serving answers computed under the retired definition for exactly
     * one TTL. That is the hardest divergence to diagnose, because it repairs itself before anyone
     * investigates — and while it lasts, two surfaces disagree for no visible reason.
     */
    public function test_recurrence_caches_are_version_aware(): void
    {
        $mustBeVersioned = [
            'app/Services/RepairIntelligence/Query/ProjectionRepairHistoryQuery.php',
        ];

        foreach ($mustBeVersioned as $file) {
            $source = file_get_contents($this->root($file));

            $this->assertStringContainsString(
                "config('metrics.recurrence.version'",
                $source,
                "{$file} caches a recurrence answer without keying on the metric version.",
            );
        }
    }

    // ── The contract is reachable from the code that implements it ──────────────────────────────

    public function test_the_repository_reads_the_contract_rather_than_hardcoding(): void
    {
        $source = file_get_contents($this->root('app/Intelligence/Recurrence/RecurrenceWindow.php'));

        $this->assertStringContainsString("config('metrics.recurrence", $source);

        $repo = file_get_contents($this->root('app/Intelligence/Recurrence/RecurrenceRepository.php'));

        // A hardcoded 90 in the repository would silently outrank the contract.
        $this->assertStringNotContainsString('INTERVAL 90 DAY', $repo);
        $this->assertStringNotContainsString('windowDays = 90', $repo);
    }

    /** The project root, derived from this file rather than from a booted container. */
    private function root(string $relative = ''): string
    {
        return dirname(__DIR__, 3) . ($relative === '' ? '' : DIRECTORY_SEPARATOR . $relative);
    }

    /** @return array<string, string> relative path => contents */
    private function phpFiles(): array
    {
        $root  = $this->root('app');
        $files = [];

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->root()) + 1));
            $files[$relative] = file_get_contents($file->getPathname());
        }

        return $files;
    }
}
