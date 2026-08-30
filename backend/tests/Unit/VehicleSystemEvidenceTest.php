<?php

namespace Tests\Unit;

use App\Services\Reports\VehicleSystemEvidenceService;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * THE EVIDENCE MODEL BEHIND THE PER-VEHICLE SYSTEM REPORT.
 *
 * These are the cases that made the old report misleading, written as tests so they cannot come back.
 * The report counted database rows and called the total a failure count; on a real Camaro that turned
 * three workshop incidents into "5 events, 1 repeated fault, 3 major-work records, risk 50/100" — a
 * confident-looking number describing the paperwork rather than the car.
 *
 * Each test below pins one half of the same principle: **weak evidence must not be inflated, and real
 * evidence must not be discarded.** Both failures are equally bad. A report that under-reports a
 * genuine cylinder-coil failure is no more honest than one that counts the word "Engine" four times.
 *
 * No database: the evidence service takes already-shaped event arrays, so these run on the vocabulary
 * and the rules alone.
 */
class VehicleSystemEvidenceTest extends TestCase
{
    private function service(): VehicleSystemEvidenceService
    {
        return app(VehicleSystemEvidenceService::class);
    }

    /** One collapsed source record, as VehicleSystemDashboardService hands them over. */
    private function ev(array $overrides = []): array
    {
        return array_merge([
            'source'              => 'workshop log',
            'ref'                 => 1,
            'refs'                => [1],
            'date'                => '2026-01-01',
            'garage'              => 'GARAGE ONE',
            'severity'            => null,
            'finding'             => 'Engine',
            'finding_specificity' => 'generic',
            'detail'              => 'No notes recorded',
            'outcome'             => 'Car returned',
            'odometer'            => null,
            'major_work'          => false,
            'recurred'            => false,
        ], $overrides);
    }

    private function analyse(array $events, string $system = 'engine'): array
    {
        return $this->service()->analyse(new Collection($events), $system);
    }

    // ── Case 1 ──────────────────────────────────────────────────────────────────────────────────

    /**
     * Two rows a day apart at the same garage are the stages of one workshop case, not two failures.
     * This is the single biggest source of the old inflation: the sheet writes OUT, the write-up and
     * the return as separate rows.
     */
    public function test_consecutive_rows_at_one_garage_are_one_incident(): void
    {
        $a = $this->analyse([
            $this->ev(['ref' => 1, 'date' => '2026-05-10']),
            $this->ev(['ref' => 2, 'date' => '2026-05-11']),
        ]);

        $this->assertCount(1, $a['incidents'], 'two follow-up rows must group into one incident');
        $this->assertSame(2, $a['incidents'][0]['row_count']);
        $this->assertSame(2, $a['facts']['records']);
        $this->assertSame(1, $a['facts']['incidents']);
        $this->assertStringContainsString(
            'same garage',
            $a['incidents'][0]['records'][1]['join_reason'],
            'the grouping must state WHY the row was joined, so it can be audited'
        );
    }

    /** Far apart, different garages, different text — two incidents, and the report must not merge them. */
    public function test_distant_records_stay_separate_incidents(): void
    {
        $a = $this->analyse([
            $this->ev(['ref' => 1, 'date' => '2026-01-01', 'garage' => 'GARAGE ONE']),
            $this->ev(['ref' => 2, 'date' => '2026-06-01', 'garage' => 'GARAGE TWO']),
        ]);

        $this->assertCount(2, $a['incidents']);
    }

    // ── Case 2 ──────────────────────────────────────────────────────────────────────────────────

    /**
     * THE HEADLINE BUG. "Engine" is the sheet's category word for the visit. Four rows carrying it and
     * nothing else matched each other exactly and scored as a recurring engine fault.
     */
    public function test_repeated_generic_category_word_is_not_a_repeated_fault(): void
    {
        $a = $this->analyse([
            $this->ev(['ref' => 1, 'date' => '2026-01-01', 'garage' => 'A']),
            $this->ev(['ref' => 2, 'date' => '2026-03-01', 'garage' => 'B']),
            $this->ev(['ref' => 3, 'date' => '2026-05-01', 'garage' => 'C']),
            $this->ev(['ref' => 4, 'date' => '2026-07-01', 'garage' => 'D']),
        ]);

        $this->assertCount(4, $a['incidents']);
        $this->assertSame([], $a['recurrence']['repeated_faults'], 'a repeated category label is not a repeated fault');
        $this->assertSame(0, $a['facts']['confirmed_faults']);
        $this->assertSame(
            'insufficient_data',
            $a['recurrence']['status'],
            'repeated visits with no named fault must read as undecidable, not as recurrence'
        );
        $this->assertSame('low', $a['confidence']['level']);
    }

    // ── Case 3 ──────────────────────────────────────────────────────────────────────────────────

    /** The same NAMED fault in two separate incidents is a real, confirmed recurrence. */
    public function test_same_explicit_fault_twice_is_confirmed_recurrence(): void
    {
        $a = $this->analyse([
            $this->ev(['ref' => 1, 'date' => '2026-01-01', 'garage' => 'A',
                'finding' => 'Engine Oil Leak', 'finding_specificity' => 'specific']),
            $this->ev(['ref' => 2, 'date' => '2026-06-01', 'garage' => 'B',
                'finding' => 'Engine oil leak', 'finding_specificity' => 'specific']),
        ]);

        $this->assertSame('confirmed_recurring_fault', $a['recurrence']['status']);
        $this->assertCount(1, $a['recurrence']['repeated_faults']);
        $this->assertSame(2, $a['recurrence']['repeated_faults'][0]['count'], 'casing must not fork one fault into two');
    }

    // ── Case 4 ──────────────────────────────────────────────────────────────────────────────────

    /** Different faults in one system is repeated ACTIVITY, not a repeat of one fault. */
    public function test_different_faults_are_recurring_visits_not_recurring_fault(): void
    {
        $a = $this->analyse([
            $this->ev(['ref' => 1, 'date' => '2026-01-01', 'garage' => 'A',
                'finding' => 'Coolant Leak', 'finding_specificity' => 'specific']),
            $this->ev(['ref' => 2, 'date' => '2026-06-01', 'garage' => 'B',
                'finding' => 'Engine Noise', 'finding_specificity' => 'specific']),
        ]);

        $this->assertSame('recurring_system_visits', $a['recurrence']['status']);
        $this->assertSame([], $a['recurrence']['repeated_faults']);
        $this->assertSame(2, $a['facts']['confirmed_faults']);
    }

    // ── Case 5 ──────────────────────────────────────────────────────────────────────────────────

    /** A completed replacement followed by the same named fault is the strongest bad news here. */
    public function test_replacement_then_same_fault_is_post_repair_recurrence(): void
    {
        $a = $this->analyse([
            $this->ev([
                'ref' => 1, 'date' => '2026-01-01', 'garage' => 'A',
                'finding' => 'Radiator Leak', 'finding_specificity' => 'specific',
                'detail'  => 'Radiator leak found. The radiator was replaced.',
            ]),
            $this->ev([
                'ref' => 2, 'date' => '2026-06-01', 'garage' => 'B',
                'finding' => 'Radiator Leak', 'finding_specificity' => 'specific',
                'detail'  => 'Radiator leak found again.',
            ]),
        ]);

        $replacements = collect($a['work'])->where('action', 'replacement');
        $this->assertTrue($replacements->isNotEmpty(), 'an explicit "was replaced" must be read as a replacement');

        $durability = collect($a['durability'])->where('work.action', 'replacement')->first();
        $this->assertNotNull($durability);
        $this->assertSame('same_fault_returned', $durability['verdict']);
        $this->assertSame(151, $durability['next_confirmed']['days']);
    }

    // ── Case 6 ──────────────────────────────────────────────────────────────────────────────────

    /**
     * Silence after a repair is silence. A car sold, or one whose later visits nobody wrote up,
     * produces exactly the same absence as a car that was genuinely fixed.
     */
    public function test_replacement_with_nothing_after_is_not_reported_as_held(): void
    {
        $a = $this->analyse([
            $this->ev([
                'ref' => 1, 'date' => '2026-01-01',
                'finding' => 'Radiator Leak', 'finding_specificity' => 'specific',
                'detail'  => 'The radiator was replaced.',
            ]),
        ]);

        $d = collect($a['durability'])->first();
        $this->assertNotNull($d);
        $this->assertSame('nothing_recorded_after', $d['verdict']);
        $this->assertNull($d['next_incident']);
        $this->assertNull($d['next_confirmed']);
    }

    // ── Case 7 ──────────────────────────────────────────────────────────────────────────────────

    /** A generic row whose note is all bodywork carries no engine evidence at all. */
    public function test_generic_row_with_unrelated_note_is_weak_evidence(): void
    {
        $a = $this->analyse([
            $this->ev([
                'detail' => 'Scratches on all four rims. The front lip is missing. Upholstery damage on the rear seat.',
            ]),
        ]);

        $incident = $a['incidents'][0];
        $this->assertSame('weak', $incident['strength']);
        $this->assertSame('workshop_mention', $incident['kind']);
        $this->assertFalse($incident['is_confirmed']);
        $this->assertSame([], $incident['records'][0]['system_lines'], 'bodywork lines must not be attributed to the engine');
    }

    /**
     * The mirror of case 7, and just as important: a generic CATEGORY with a note that names a
     * component failure is a real fault. Refusing to read it would under-report a live problem.
     */
    public function test_fault_named_only_in_the_note_is_still_a_confirmed_fault(): void
    {
        $a = $this->analyse([
            $this->ev([
                'detail' => 'Cylinder 4 ignition coil is not functioning. Scratches on all four rims.',
            ]),
        ]);

        $incident = $a['incidents'][0];
        $this->assertTrue($incident['is_confirmed']);
        $this->assertSame('strong', $incident['strength'], 'a named component plus a failure is strong evidence');
        // Verbatim, punctuation and all — the report quotes the record, it does not tidy it.
        $this->assertSame('Cylinder 4 ignition coil is not functioning.', $incident['fault']);
        $this->assertSame('derived', $incident['records'][0]['specificity'], 'the fault came from the note, not the finding column');
    }

    /**
     * A conditional recommendation is not a finding. "If the engine noise is still present, the
     * engine may need to be replaced" contains a fault word but reports nothing — listing it under
     * "what was found" would tell a manager a fault was identified when none was.
     */
    public function test_conditional_recommendation_is_not_read_as_a_fault(): void
    {
        $a = $this->analyse([
            $this->ev([
                'detail' => 'If the engine noise still present, the engine may need to be replaced.',
            ]),
        ]);

        $incident = $a['incidents'][0];
        $this->assertFalse($incident['is_confirmed'], 'a conditional proposal is not an identified fault');
        $this->assertNull($incident['fault']);
    }

    // ── Case 8 ──────────────────────────────────────────────────────────────────────────────────

    /** A structured ticket with a severity is the strongest thing this fleet records. */
    public function test_fault_ticket_with_severity_is_strong_evidence(): void
    {
        $a = $this->analyse([
            $this->ev([
                'source' => 'ticket', 'severity' => 'critical',
                'finding' => 'Engine Noise', 'finding_specificity' => 'specific',
                'detail'  => 'Loud knocking from the engine.',
            ]),
        ]);

        $incident = $a['incidents'][0];
        $this->assertSame('strong', $incident['strength']);
        $this->assertTrue($incident['is_confirmed']);
        $this->assertSame('critical', $incident['severity']);
        $this->assertSame(1, $a['facts']['from_tickets']);
    }

    // ── Case 9 ──────────────────────────────────────────────────────────────────────────────────

    /** No odometer means no distance. Never the vehicle's current reading, never an estimate. */
    public function test_missing_mileage_is_never_fabricated(): void
    {
        $a = $this->analyse([
            $this->ev(['ref' => 1, 'date' => '2026-01-01',
                'finding' => 'Radiator Leak', 'finding_specificity' => 'specific',
                'detail' => 'The radiator was replaced.']),
            $this->ev(['ref' => 2, 'date' => '2026-06-01', 'garage' => 'B',
                'finding' => 'Radiator Leak', 'finding_specificity' => 'specific']),
        ]);

        $d = collect($a['durability'])->first();
        $this->assertNull($d['odometer']);
        $this->assertNull($d['next_confirmed']['km'], 'no reading on either side means no distance, not zero');
        $this->assertNotNull($d['next_confirmed']['days'], 'dates are present, so days must still be computed');
        $this->assertSame(2, collect($a['data_quality'])->firstWhere('code', 'no_odometer')['n']);
    }

    /** With readings on both sides the distance IS computed — the absence above is the data, not the rule. */
    public function test_mileage_is_used_when_both_readings_exist(): void
    {
        $a = $this->analyse([
            $this->ev(['ref' => 1, 'date' => '2026-01-01', 'odometer' => 90000,
                'finding' => 'Radiator Leak', 'finding_specificity' => 'specific',
                'detail' => 'The radiator was replaced.']),
            $this->ev(['ref' => 2, 'date' => '2026-06-01', 'garage' => 'B', 'odometer' => 94500,
                'finding' => 'Radiator Leak', 'finding_specificity' => 'specific']),
        ]);

        $d = collect($a['durability'])->first();
        $this->assertSame(4500, $d['next_confirmed']['km']);
    }

    // ── Case 10 ─────────────────────────────────────────────────────────────────────────────────

    /**
     * A recommendation is not a repair. "Requires replacement" records what someone thinks SHOULD
     * happen; counting it as work done tells a fleet that parts were fitted which never were.
     */
    public function test_recommended_work_is_never_counted_as_work_done(): void
    {
        $a = $this->analyse([
            $this->ev([
                'finding' => 'Coolant Leak', 'finding_specificity' => 'specific',
                'detail'  => 'The radiator requires replacement. Coolant hose needs to be replaced.',
            ]),
        ]);

        $this->assertSame([], collect($a['work'])->where('action', 'replacement')->all());
        $this->assertNotEmpty(collect($a['work'])->where('action', 'recommended')->all());
        $this->assertSame([], $a['durability'], 'a recommendation gives nothing to measure durability against');
    }

    /** Work belonging to another system must never be counted as this system's major work. */
    public function test_other_system_replacement_is_not_counted_as_this_systems_work(): void
    {
        $a = $this->analyse([
            $this->ev([
                'finding' => 'Engine Noise', 'finding_specificity' => 'specific',
                'detail'  => 'The transmission housing was replaced. The front bumper was repainted.',
            ]),
        ]);

        $this->assertSame(
            [],
            collect($a['work'])->where('action', 'replacement')->all(),
            'a transmission replacement inside an engine visit is not engine work'
        );
    }

    /** Parts and labour are not structured on this fleet, and the report has to say so. */
    public function test_absent_structured_parts_is_reported_not_hidden(): void
    {
        $a = $this->analyse([$this->ev()]);

        $this->assertNotNull(
            collect($a['data_quality'])->firstWhere('code', 'no_structured_parts'),
            'the report must state that parts are unstructured rather than showing an empty table'
        );
    }
}
