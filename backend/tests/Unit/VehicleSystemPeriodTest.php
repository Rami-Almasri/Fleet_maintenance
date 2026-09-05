<?php

namespace Tests\Unit;

use App\Http\Controllers\FleetIntelligenceReportController;
use App\Services\Reports\ReportDateRange;
use App\Services\Reports\VehicleSystemEvidenceService;
use App\Services\Reports\VehicleSystemPeriodService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * "BETWEEN THESE TWO DATES, WHAT WENT WRONG WITH THIS CAR?"
 *
 * The date filter on the system dashboard is the sort of feature that is easy to ship and easy to
 * ship WRONG, in ways nobody notices until a decision has been made on it. The three ways it goes
 * wrong are all pinned below:
 *
 *   1. THE BOUNDARY. A range that quietly drops its own last day understates a period by exactly the
 *      records a person was looking for when they typed that date.
 *   2. THE CONFLATION. Three engine visits are not three engine faults, and two engine faults are not
 *      one fault twice. A report that blurs those tells a fleet to replace a part that never failed.
 *   3. THE SILENT SWAP. An inverted range must be refused. Reordering it answers a question nobody
 *      asked and prints a header the reader did not choose.
 *
 * No database: the period layer is a regrouping of already-analysed incidents, and the evidence layer
 * takes shaped arrays, so the whole pipeline under test runs on the vocabulary and the rules alone.
 * @see VehicleSystemEvidenceTest, whose event fixture shape this mirrors.
 */
class VehicleSystemPeriodTest extends TestCase
{
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

    /**
     * The pipeline exactly as the dashboard runs it: narrow to the period, analyse what is left,
     * regroup it into problems. The narrowing here is ReportDateRange::contains — the same call the
     * service makes after its SQL, and the authoritative one.
     *
     * @param  array<int, array>  $events
     */
    private function report(array $events, ?string $from = null, ?string $to = null, string $system = 'engine'): array
    {
        $range = ReportDateRange::of($from, $to);

        $scoped = collect($events)
            ->filter(fn (array $e) => $range->contains($e['date']))
            ->sortBy('date')
            ->values();

        $analysis = app(VehicleSystemEvidenceService::class)->analyse($scoped, $system);

        return app(VehicleSystemPeriodService::class)
            ->build($analysis['incidents'], $analysis['durability'], $scoped)
            + ['range' => $range, 'analysis' => $analysis];
    }

    /**
     * The Camaro's engine record, near enough to the shape the sheet actually produces: a fastening
     * job in May, a radiator note in July, and an engine-noise complaint logged twice three weeks
     * apart at two different garages.
     */
    private function camaroEngine(): array
    {
        return [
            $this->ev([
                'ref' => 101, 'refs' => [101], 'date' => '2026-05-10', 'garage' => 'Deals On Wheels auto',
                'detail' => 'Engine cover fasteners/clips have been secured.',
            ]),
            $this->ev([
                'ref' => 102, 'refs' => [102], 'date' => '2026-07-04', 'garage' => '7 CYLINDER',
                'finding' => 'Engine Noise', 'finding_specificity' => 'specific',
                'detail' => 'Engine noise / abnormal sound reported by the driver. The radiator was checked.',
            ]),
            $this->ev([
                'ref' => 103, 'refs' => [103], 'date' => '2026-07-28', 'garage' => 'AL Muharik AL hakiki',
                'finding' => 'engine noise', 'finding_specificity' => 'specific',
                'detail' => 'Engine noise continued. The engine mounting was repaired.',
            ]),
            // Outside every period this test selects — the guard against a filter that does nothing.
            $this->ev([
                'ref' => 104, 'refs' => [104], 'date' => '2025-11-02', 'garage' => 'OLD GARAGE',
                'finding' => 'Engine Oil Leak', 'finding_specificity' => 'specific',
                'detail' => 'Engine oil leak from the valve cover.',
            ]),
        ];
    }

    // ── 1. The range itself ─────────────────────────────────────────────────────────────────────

    /** No filter is the old report: every dated record, nothing narrowed. */
    public function test_all_history_is_the_default_and_selects_everything(): void
    {
        $range = ReportDateRange::allHistory();

        $this->assertFalse($range->isActive());
        $this->assertTrue($range->contains('1999-01-01'));
        $this->assertTrue($range->contains('2099-01-01'));
        $this->assertSame('all_history', $range->toArray()['shape']);

        $r = $this->report($this->camaroEngine());
        $this->assertSame(4, $r['summary']['system_visits'], 'no filter must not narrow anything');
    }

    /** BOTH ENDS ARE INCLUSIVE. The day you typed is a day you get. */
    public function test_the_range_is_inclusive_at_both_boundaries(): void
    {
        $range = ReportDateRange::of('2026-05-01', '2026-07-31');

        $this->assertTrue($range->contains('2026-05-01'), 'the first day of the range is inside it');
        $this->assertTrue($range->contains('2026-07-31'), 'the last day of the range is inside it');
        $this->assertFalse($range->contains('2026-04-30'));
        $this->assertFalse($range->contains('2026-08-01'));
        // A datetime is placed by its DAY — 23:59 on the last day is not the day after.
        $this->assertTrue($range->contains('2026-07-31 23:59:59'));
    }

    /** A single day is a legitimate question, and it selects that day only. */
    public function test_a_same_day_range_selects_exactly_that_day(): void
    {
        $range = ReportDateRange::of('2026-07-28', '2026-07-28');

        $this->assertTrue($range->contains('2026-07-28'));
        $this->assertFalse($range->contains('2026-07-27'));
        $this->assertFalse($range->contains('2026-07-29'));
        $this->assertSame('single_day', $range->toArray()['shape']);

        $r = $this->report($this->camaroEngine(), '2026-07-28', '2026-07-28');
        $this->assertSame(1, $r['summary']['system_visits']);
        $this->assertSame(1, $r['summary']['named_faults']);
        $this->assertFalse($r['problems'][0]['repeated'], 'one occurrence in the window is not a recurrence');
    }

    /** One end only is open-ended in the other direction, not an error. */
    public function test_an_open_ended_range_is_accepted(): void
    {
        $since = ReportDateRange::of('2026-07-01', null);
        $this->assertTrue($since->isActive());
        $this->assertSame('since', $since->toArray()['shape']);
        $this->assertTrue($since->contains('2099-01-01'));
        $this->assertFalse($since->contains('2026-06-30'));

        $until = ReportDateRange::of(null, '2026-07-01');
        $this->assertSame('until', $until->toArray()['shape']);
        $this->assertTrue($until->contains('1999-01-01'));
    }

    /** An INVERTED range is refused. It is never reordered behind the reader's back. */
    public function test_an_inverted_range_is_rejected_and_never_swapped(): void
    {
        $this->expectException(ValidationException::class);

        ReportDateRange::of('2026-07-31', '2026-05-01');
    }

    /** The API says the same thing, as a 422 with a field error rather than an exception page. */
    public function test_the_endpoint_rules_reject_an_inverted_range(): void
    {
        $validator = Validator::make(
            ['from' => '2026-07-31', 'to' => '2026-05-01'],
            FleetIntelligenceReportController::vehicleSystemRules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('to', $validator->errors()->toArray());

        $ok = Validator::make(
            ['system' => 'engine', 'from' => '2026-05-01', 'to' => '2026-07-31'],
            FleetIntelligenceReportController::vehicleSystemRules(),
        );
        $this->assertFalse($ok->fails(), 'a well-formed range must pass');

        $garbage = Validator::make(
            ['from' => '31/07/2026'],
            FleetIntelligenceReportController::vehicleSystemRules(),
        );
        $this->assertTrue($garbage->fails(), 'a filter the report cannot read must be refused, not ignored');
    }

    /** A record with no date belongs to no period, and is never swept in to fill a count. */
    public function test_an_undated_record_is_outside_every_active_period(): void
    {
        $range = ReportDateRange::of('2026-05-01', '2026-07-31');

        $this->assertFalse($range->contains(null));
        $this->assertFalse($range->contains(''));
    }

    /** A future window is answered, not refused — and it answers "nothing is recorded". */
    public function test_a_future_period_returns_an_honest_empty_report(): void
    {
        $r = $this->report($this->camaroEngine(), '2030-01-01', '2030-12-31');

        $this->assertSame(0, $r['summary']['system_visits']);
        $this->assertSame(0, $r['summary']['named_faults']);
        $this->assertSame(0, $r['summary']['repeated_faults']);
        $this->assertSame([], $r['problems']);
        $this->assertSame([], $r['workshop_only']);
    }

    /** A period the car simply was not in the workshop for. Same answer, no invented reassurance. */
    public function test_a_period_with_no_records_is_empty_not_zero_risk(): void
    {
        $r = $this->report($this->camaroEngine(), '2026-01-01', '2026-04-30');

        $this->assertSame(0, $r['summary']['system_visits']);
        $this->assertSame([], $r['problems']);
        $this->assertSame(0, $r['summary']['repairs']);
        // An empty period establishes NOTHING about recurrence — not "no recurrence", which would
        // read as reassurance the record cannot give.
        $this->assertSame('no_evidence', $r['analysis']['recurrence']['status']);
    }

    // ── 2. The counters ─────────────────────────────────────────────────────────────────────────

    /**
     * THE HEADLINE NUMBERS, on the brief's own worked example: 01 May → 31 July gives three engine
     * visits, two distinct named faults, one of them repeated, one repair recorded and one visit that
     * named nothing at all.
     */
    public function test_the_period_summary_counts_visits_faults_and_repeats_separately(): void
    {
        $r = $this->report($this->camaroEngine(), '2026-05-01', '2026-07-31');
        $s = $r['summary'];

        $this->assertSame(3, $s['system_visits'], 'the November record is outside the period');
        $this->assertSame(1, $s['named_faults'], 'engine noise is ONE problem, logged twice');
        $this->assertSame(2, $s['named_fault_visits'], 'two of the three visits named that fault');
        $this->assertSame(1, $s['repeated_faults']);
        $this->assertSame(1, $s['workshop_only_visits'], 'the May fastening job named no fault');
        $this->assertSame(3, $s['source_records']);
        $this->assertSame('2026-05-10', $s['first_record']);
        $this->assertSame('2026-07-28', $s['last_record']);
    }

    /** Every counter moves with the filter. A narrower window is a smaller report, not the same one. */
    public function test_narrowing_the_period_changes_every_count(): void
    {
        $wide   = $this->report($this->camaroEngine(), '2025-01-01', '2026-12-31')['summary'];
        $narrow = $this->report($this->camaroEngine(), '2026-07-01', '2026-07-31')['summary'];

        $this->assertSame(4, $wide['system_visits']);
        $this->assertSame(2, $wide['named_faults'], 'engine noise and the oil leak');

        $this->assertSame(2, $narrow['system_visits']);
        $this->assertSame(1, $narrow['named_faults']);
        $this->assertSame(0, $narrow['workshop_only_visits'], 'May is outside July');
    }

    // ── 3. Same fault vs same system ────────────────────────────────────────────────────────────

    /** TWO OCCURRENCES OF ONE NAMED FAULT ARE A REPEAT — including across a casing difference. */
    public function test_the_same_named_fault_twice_is_grouped_as_one_repeated_problem(): void
    {
        $r = $this->report($this->camaroEngine(), '2026-05-01', '2026-07-31');

        $this->assertCount(1, $r['problems'], 'one named problem in this window');

        $p = $r['problems'][0];
        $this->assertSame('Engine Noise', $p['fault'], 'the fault keeps the spelling it was FIRST logged with');
        $this->assertSame(2, $p['occurrences']);
        $this->assertTrue($p['repeated']);
        $this->assertSame('2026-07-04', $p['first_seen']);
        $this->assertSame('2026-07-28', $p['last_seen']);
        $this->assertSame(24, $p['span_days']);
        $this->assertSame(['7 CYLINDER', 'AL Muharik AL hakiki'], $p['garages']);
    }

    /** TWO DIFFERENT FAULTS ARE TWO PROBLEMS. Neither is a repetition of the other. */
    public function test_two_different_faults_are_never_grouped_as_repeated(): void
    {
        $r = $this->report([
            $this->ev(['ref' => 1, 'date' => '2026-05-05', 'garage' => 'A',
                'finding' => 'Coolant Leak', 'finding_specificity' => 'specific']),
            $this->ev(['ref' => 2, 'date' => '2026-06-20', 'garage' => 'B',
                'finding' => 'Engine Noise', 'finding_specificity' => 'specific']),
        ], '2026-05-01', '2026-07-31');

        $this->assertCount(2, $r['problems']);
        $this->assertSame(0, $r['summary']['repeated_faults']);
        $this->assertSame(2, $r['summary']['named_faults']);

        foreach ($r['problems'] as $p) {
            $this->assertFalse($p['repeated']);
            $this->assertSame('no', $p['returned']['status']);
        }
    }

    /**
     * THE DISTINCTION THE BRIEF IS BUILT AROUND. Three engine visits where only one names a fault are
     * three visits, one named fault and two workshop-only visits — never three repetitions.
     */
    public function test_repeated_visits_without_a_named_fault_are_not_repeated_faults(): void
    {
        $r = $this->report([
            $this->ev(['ref' => 1, 'date' => '2026-05-10', 'garage' => 'A',
                'detail' => 'Engine cover fasteners/clips have been secured.']),
            $this->ev(['ref' => 2, 'date' => '2026-07-04', 'garage' => 'B',
                'detail' => 'The radiator was checked.']),
            $this->ev(['ref' => 3, 'date' => '2026-07-28', 'garage' => 'C',
                'finding' => 'Engine Noise', 'finding_specificity' => 'specific',
                'detail' => 'Engine noise reported.']),
        ], '2026-05-01', '2026-07-31');

        $this->assertSame(3, $r['summary']['system_visits']);
        $this->assertSame(1, $r['summary']['named_faults']);
        $this->assertSame(0, $r['summary']['repeated_faults']);
        $this->assertSame(2, $r['summary']['workshop_only_visits']);
        $this->assertCount(2, $r['workshop_only']);
        $this->assertCount(1, $r['problems']);
    }

    /** Workshop-only visits are SHOWN, with their notes — they are the gap between visits and faults. */
    public function test_workshop_only_visits_keep_their_notes_and_action(): void
    {
        $r = $this->report($this->camaroEngine(), '2026-05-01', '2026-07-31');

        $this->assertCount(1, $r['workshop_only']);
        $only = $r['workshop_only'][0];

        $this->assertSame('2026-05-10', $only['date']);
        $this->assertSame('Deals On Wheels auto', $only['garage']);
        $this->assertContains('Engine cover fasteners/clips have been secured.', $only['note_lines']);
        $this->assertSame('repair', $only['action'], '"have been secured" is completed work');
    }

    // ── 4. The record itself ────────────────────────────────────────────────────────────────────

    /** THE ORIGINAL WORKSHOP NOTE IS PRESERVED, alongside — never replaced by — the fault name. */
    public function test_the_original_workshop_note_is_preserved_verbatim(): void
    {
        $r = $this->report($this->camaroEngine(), '2026-05-01', '2026-07-31');
        $events = $r['problems'][0]['events'];

        $this->assertCount(2, $events);
        $this->assertSame('7 CYLINDER', $events[0]['garage']);
        $this->assertContains(
            'Engine noise / abnormal sound reported by the driver.',
            $events[0]['all_note_lines'],
            'the garage’s own words must survive into the report'
        );
        $this->assertContains('Engine noise continued.', $events[1]['all_note_lines']);
        // Both are still reported under the normalised fault name — the two coexist.
        $this->assertSame('Engine Noise', $r['problems'][0]['fault']);
    }

    /** Refs back to the source rows survive, so nothing on the page is a dead end. */
    public function test_every_occurrence_keeps_its_source_row_references(): void
    {
        $r = $this->report($this->camaroEngine(), '2026-05-01', '2026-07-31');

        $this->assertSame([102], $r['problems'][0]['events'][0]['refs']);
        $this->assertSame([103], $r['problems'][0]['events'][1]['refs']);
        $this->assertSame([101], $r['workshop_only'][0]['refs']);
    }

    // ── 5. What was done ────────────────────────────────────────────────────────────────────────

    /** The action for each occurrence, graded by how firmly the note supports it. */
    public function test_the_recorded_action_is_reported_with_its_evidence_grade(): void
    {
        $r = $this->report($this->camaroEngine(), '2026-05-01', '2026-07-31');
        $events = $r['problems'][0]['events'];

        $this->assertSame('inspection', $events[0]['action'], '"was checked" is an inspection');
        $this->assertSame('strong', $events[0]['action_evidence']);

        $this->assertSame('repair', $events[1]['action'], '"was repaired" is completed work');
        $this->assertSame('strong', $events[1]['action_evidence']);
        $this->assertSame('The engine mounting was repaired.', $events[1]['action_text']);

        // Two visits recorded completed work: the May fastening job and the July engine mounting.
        // The counter is over VISITS, not over named faults — work done during a visit that named no
        // fault is still work the fleet paid for, and dropping it would understate the period.
        $this->assertSame(2, $r['summary']['repairs']);
        $this->assertSame(1, $r['problems'][0]['repairs_recorded'], 'one of them belongs to the engine-noise problem');
    }

    /** A note with no verb records no action. The report says so rather than inventing one. */
    public function test_no_recorded_action_is_reported_as_such(): void
    {
        $r = $this->report([
            $this->ev(['ref' => 1, 'date' => '2026-06-01',
                'finding' => 'Engine Noise', 'finding_specificity' => 'specific',
                'detail' => 'Engine noise reported by the driver.']),
        ], '2026-05-01', '2026-07-31');

        $this->assertSame('not_recorded', $r['problems'][0]['events'][0]['action']);
        $this->assertSame('none', $r['problems'][0]['events'][0]['action_evidence']);
        $this->assertSame(0, $r['summary']['repairs']);
    }

    /** A recommendation is labelled a recommendation and never counted as a repair. */
    public function test_a_recommendation_is_labelled_mentioned_only(): void
    {
        $r = $this->report([
            $this->ev(['ref' => 1, 'date' => '2026-06-01',
                'finding' => 'Coolant Leak', 'finding_specificity' => 'specific',
                'detail' => 'The radiator requires replacement.']),
        ], '2026-05-01', '2026-07-31');

        $this->assertSame('recommended', $r['problems'][0]['events'][0]['action']);
        $this->assertSame('mentioned', $r['problems'][0]['events'][0]['action_evidence']);
        $this->assertSame(0, $r['summary']['repairs'], 'nobody wrote that it was done');
    }

    // ── 6. Did it come back? ────────────────────────────────────────────────────────────────────

    /** The recurrence sentence is built from this fault's own dates, and states the gap. */
    public function test_a_repeated_fault_reports_when_it_came_back(): void
    {
        $returned = $this->report($this->camaroEngine(), '2026-05-01', '2026-07-31')['problems'][0]['returned'];

        $this->assertSame('yes', $returned['status']);
        $this->assertSame(24, $returned['days'], '04 Jul → 28 Jul');
        $this->assertSame('2026-07-04', $returned['from']);
        $this->assertSame('2026-07-28', $returned['to']);
        $this->assertSame(2, $returned['times']);
    }

    /** "Returned AFTER a repair" is only claimed when work was recorded before the return. */
    public function test_returned_after_repair_is_only_claimed_when_work_preceded_it(): void
    {
        $noWorkFirst = $this->report($this->camaroEngine(), '2026-05-01', '2026-07-31')['problems'][0]['returned'];
        $this->assertFalse($noWorkFirst['after_repair'], 'the first visit only inspected — nothing was repaired');

        $repairedFirst = $this->report([
            $this->ev(['ref' => 1, 'date' => '2026-05-05', 'garage' => 'A',
                'finding' => 'Radiator Leak', 'finding_specificity' => 'specific',
                'detail' => 'Radiator leak found. The radiator was replaced.']),
            $this->ev(['ref' => 2, 'date' => '2026-07-10', 'garage' => 'B',
                'finding' => 'Radiator leak', 'finding_specificity' => 'specific',
                'detail' => 'Radiator leak found again.']),
        ], '2026-05-01', '2026-07-31')['problems'][0]['returned'];

        $this->assertSame('yes', $repairedFirst['status']);
        $this->assertTrue($repairedFirst['after_repair']);
        $this->assertSame(66, $repairedFirst['days']);
    }

    /** One occurrence is one occurrence. No recurrence is claimed and none is implied. */
    public function test_a_single_occurrence_reports_no_return(): void
    {
        $r = $this->report($this->camaroEngine(), '2026-07-20', '2026-07-31');

        $this->assertCount(1, $r['problems']);
        $this->assertSame('no', $r['problems'][0]['returned']['status']);
        $this->assertNull($r['problems'][0]['returned']['days']);
        $this->assertFalse($r['problems'][0]['repeated']);
    }

    /** A DIFFERENT fault afterwards is named as such — it is activity, not this fault returning. */
    public function test_a_different_fault_afterwards_is_reported_as_a_different_fault(): void
    {
        $r = $this->report([
            $this->ev(['ref' => 1, 'date' => '2026-05-05', 'garage' => 'A',
                'finding' => 'Coolant Leak', 'finding_specificity' => 'specific']),
            $this->ev(['ref' => 2, 'date' => '2026-06-20', 'garage' => 'B',
                'finding' => 'Engine Noise', 'finding_specificity' => 'specific']),
        ], '2026-05-01', '2026-07-31');

        $coolant = collect($r['problems'])->firstWhere('fault', 'Coolant Leak');

        $this->assertSame('no', $coolant['returned']['status']);
        $this->assertSame(
            ['date' => '2026-06-20', 'fault' => 'Engine Noise'],
            $coolant['followed_by'],
            'the car came back for something else, and the report must say which'
        );
    }

    // ── 7. Nothing outside the window leaks in ──────────────────────────────────────────────────

    /**
     * A fault that recurred ACROSS the boundary is not reported as recurring inside the window. The
     * period report describes the period; the all-history view is a different question with its own
     * clearly-labelled answer.
     */
    public function test_occurrences_outside_the_window_do_not_create_recurrence_inside_it(): void
    {
        $events = [
            $this->ev(['ref' => 1, 'date' => '2025-11-02', 'garage' => 'A',
                'finding' => 'Engine Oil Leak', 'finding_specificity' => 'specific']),
            $this->ev(['ref' => 2, 'date' => '2026-06-02', 'garage' => 'B',
                'finding' => 'Engine Oil Leak', 'finding_specificity' => 'specific']),
        ];

        $all = $this->report($events);
        $this->assertTrue($all['problems'][0]['repeated'], 'across all history it IS a repeat');

        $window = $this->report($events, '2026-05-01', '2026-07-31');
        $this->assertSame(1, $window['problems'][0]['occurrences']);
        $this->assertFalse($window['problems'][0]['repeated']);
        $this->assertSame('no', $window['problems'][0]['returned']['status']);
    }

    /** The evidence layer's own grouping is untouched by the filter — only its input set changes. */
    public function test_the_filter_changes_the_input_not_the_grouping_rules(): void
    {
        // Two rows a day apart at one garage are one incident, filtered or not.
        $events = [
            $this->ev(['ref' => 1, 'date' => '2026-06-10', 'garage' => 'A']),
            $this->ev(['ref' => 2, 'date' => '2026-06-11', 'garage' => 'A']),
        ];

        foreach ([[null, null], ['2026-06-01', '2026-06-30']] as [$from, $to]) {
            $r = $this->report($events, $from, $to);
            $this->assertSame(1, $r['summary']['system_visits'], 'one visit written up twice stays one visit');
            $this->assertSame(2, $r['summary']['source_records']);
        }
    }

    // ── 4. One record, several faults ───────────────────────────────────────────────────────────

    /**
     * THE COMPOSITE LABEL, which is how the sheet actually writes a visit that found two things.
     *
     * "Rim Scratch, Paint Peeling / Fading" is not a fault; it is two faults in one cell. Counted
     * whole, it becomes a third identity that shares nothing with either — so a rim scratch logged
     * once alone and once beside a paint fault reads as two unrelated one-offs, and the recurrence
     * the report exists to find is exactly the thing it hides.
     */
    public function test_a_record_naming_two_faults_is_two_problems(): void
    {
        $report = $this->report([
            $this->ev([
                'ref' => 201, 'refs' => [201], 'date' => '2026-03-02', 'garage' => 'GPT GARRAGE',
                'finding' => 'Engine Oil Leak, Engine Overheating', 'finding_specificity' => 'specific',
                'detail'  => 'Engine oil leak from the valve cover. Engine overheating under load.',
            ]),
        ]);

        $faults = collect($report['problems'])->pluck('fault')->all();

        $this->assertSame(2, $report['summary']['named_faults'], 'two faults were named, not one composite');
        $this->assertContains('Engine Oil Leak', $faults);
        $this->assertContains('Engine Overheating', $faults);
        // ONE visit, however many faults it found. The counts must not move together.
        $this->assertSame(1, $report['summary']['system_visits']);
    }

    /** A slash is part of a label, never a separator — cutting it would mint faults nobody recorded. */
    public function test_a_slash_inside_a_label_is_not_a_second_fault(): void
    {
        $report = $this->report([
            $this->ev([
                'ref' => 202, 'refs' => [202], 'date' => '2026-03-02',
                'finding' => 'Engine Oil / Filter Leak', 'finding_specificity' => 'specific',
                'detail'  => 'Engine oil leak from the filter housing.',
            ]),
        ]);

        $this->assertSame(1, $report['summary']['named_faults']);
        $this->assertSame('Engine Oil / Filter Leak', $report['problems'][0]['fault']);
    }

    /**
     * And the point of all of it: the same fault, written once alone and once inside a composite, is
     * ONE problem that happened twice — which is what makes it a repeat.
     */
    public function test_the_same_fault_groups_across_a_composite_and_a_bare_record(): void
    {
        $report = $this->report([
            $this->ev([
                'ref' => 203, 'refs' => [203], 'date' => '2026-03-02', 'garage' => 'GARAGE ONE',
                'finding' => 'Engine Oil Leak', 'finding_specificity' => 'specific',
                'detail'  => 'Engine oil leak from the valve cover.',
            ]),
            $this->ev([
                'ref' => 204, 'refs' => [204], 'date' => '2026-06-02', 'garage' => 'GARAGE TWO',
                'finding' => 'Engine Oil Leak, Engine Overheating', 'finding_specificity' => 'specific',
                'detail'  => 'Engine oil leak again. Engine overheating under load.',
            ]),
        ]);

        $leak = collect($report['problems'])->firstWhere('fault', 'Engine Oil Leak');

        $this->assertNotNull($leak);
        $this->assertSame(2, $leak['occurrences']);
        $this->assertTrue($leak['repeated']);
        $this->assertSame('yes', $leak['returned']['status']);
        $this->assertSame(['GARAGE ONE', 'GARAGE TWO'], $leak['garages']);
        $this->assertSame(1, $report['summary']['repeated_faults'], 'only the leak repeated');
    }

    /** The period service is a view over incidents, so it must not mutate what it was given. */
    public function test_the_period_view_does_not_alter_the_analysis_it_reads(): void
    {
        $scoped   = new Collection(collect($this->camaroEngine())->sortBy('date')->values()->all());
        $analysis = app(VehicleSystemEvidenceService::class)->analyse($scoped, 'engine');
        $before   = json_encode($analysis['incidents']);

        app(VehicleSystemPeriodService::class)->build($analysis['incidents'], $analysis['durability'], $scoped);

        $this->assertSame($before, json_encode($analysis['incidents']));
    }
}
