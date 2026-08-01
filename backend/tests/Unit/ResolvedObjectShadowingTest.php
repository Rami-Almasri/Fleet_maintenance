<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * STATIC guard against re-using a resolver-object variable name for an unrelated scalar.
 *
 * THE REGRESSION. `CarStatusService::ticketRow()` assigned the resolver's DelayStatus object to
 * `$delay`, then ~27 lines later reused the same name for an SLA tone string
 * (`'overdue' | 'at_risk' | 'on_track'`). Every `$delay->isDelayed` read below it became a property
 * access on a string, so `GET /api/car-status` returned HTTP 500 for every user and every role — the
 * entire Car Status board was dead. 384 tests passed the whole time, because nothing exercised it.
 *
 * Why this test is STATIC rather than a call into the service: the collaborators are `final`
 * (`MaintenanceDelayResolver`, `WorkflowStateResolver`) so they cannot be mocked, and the failing code
 * only runs when at least one open ticket exists — an empty-database smoke test would pass against the
 * broken code. Reading the file catches it in milliseconds with no database at all, which is the same
 * trade-off already made in [[MigrationSchemaGuardTest]].
 *
 * The rule enforced: within one service, a variable holding a `->resolve(...)` result must not be
 * reassigned. It is not a general-purpose shadowing checker — it locks the specific, recurring shape.
 */
class ResolvedObjectShadowingTest extends TestCase
{
    /** Services that compose resolver objects and are therefore exposed to this defect. */
    private const GUARDED = [
        'app/Services/CarStatusService.php',
        'app/Services/MaintenanceOpsCardService.php',
        'app/Services/NotificationScanner.php',
    ];

    private function source(string $relative): string
    {
        $path = __DIR__ . '/../../' . $relative;
        $this->assertFileExists($path, "guarded file is missing: {$relative}");

        return (string) file_get_contents($path);
    }

    public function test_the_guarded_files_are_actually_being_read(): void
    {
        // Guards the guard: a bad path would make every assertion below pass vacuously.
        foreach (self::GUARDED as $file) {
            $this->assertStringContainsString('resolve(', $this->source($file), "{$file} no longer composes a resolver — remove it from GUARDED or fix the path");
        }
    }

    /**
     * THE REGRESSION TEST. Any variable assigned from a `->resolve(...)` call must be assigned exactly
     * once in that file. A second assignment is the exact bug that killed the board.
     */
    public function test_a_resolved_object_variable_is_never_reassigned(): void
    {
        foreach (self::GUARDED as $file) {
            $src = $this->source($file);

            // Variables that receive a resolver result, e.g.  $delay = $this->delayResolver->resolve($t);
            preg_match_all('/\$(\w+)\s*=\s*\$this->\w*[Rr]esolver->resolve\(/', $src, $resolved);

            foreach (array_unique($resolved[1]) as $name) {
                // Count every assignment to that name (ignoring ==, ===, >=, <=, !=).
                preg_match_all('/\$' . preg_quote($name, '/') . '\s*=(?![=>])/', $src, $assignments);

                $this->assertCount(
                    1,
                    $assignments[0],
                    "{$file}: \${$name} holds a resolved object but is assigned "
                    . count($assignments[0]) . ' times. Reusing the name turns every property read on it '
                    . 'into a fatal error at runtime. Give the second value its own name.',
                );
            }
        }
    }

    /**
     * Belt and braces on the specific field that broke: the card's SLA tone must not be named `$delay`,
     * because that is the resolver object's name in the same method.
     */
    public function test_car_status_sla_tone_has_its_own_name(): void
    {
        $src = $this->source('app/Services/CarStatusService.php');

        $this->assertMatchesRegularExpression(
            "/\\\$delayTone\s*=\s*\\\$isOverdue\s*\?/",
            $src,
            'the SLA tone ternary should assign $delayTone — not $delay, which holds the DelayStatus object',
        );

        $this->assertStringContainsString(
            "'delay_status'       => \$delayTone,",
            $src,
            'delay_status must serialise the tone string, not the resolver object',
        );
    }
}
