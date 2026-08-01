<?php

namespace Tests\Unit;

use App\Services\Garage\DecisionLearning;
use PHPUnit\Framework\TestCase;

/**
 * The feedback loop's central claim is that an override is not an error. These tests exist mostly to
 * stop that claim quietly eroding — the easiest way to build a "learning" system is one that treats
 * every human disagreement as a miss, and it would be wrong here in a way nobody notices until the
 * engine has been retuned toward whatever supervisors happen to do most often.
 */
class DecisionLearningTest extends TestCase
{
    private function taxonomy(): array
    {
        return [
            'lower_cost'         => ['label' => 'Lower cost priority', 'axis' => 'cost'],
            'faster_turnaround'  => ['label' => 'Faster turnaround expected', 'axis' => 'speed'],
            'customer_requested' => ['label' => 'Customer requested this garage', 'axis' => 'external'],
            'existing_relation'  => ['label' => 'Existing relationship', 'axis' => 'relationship'],
            'special_expertise'  => ['label' => 'Special expertise', 'axis' => 'evidence'],
            'other'              => ['label' => 'Other reason', 'axis' => 'other'],
        ];
    }

    private function learn(array $thresholds = []): DecisionLearning
    {
        return new DecisionLearning($this->taxonomy(), $thresholds + [
            'min_decisions_for_signal' => 20, 'min_overrides_per_reason' => 5, 'near_tie_gap' => 10,
        ]);
    }

    private function decision(bool $followed, ?string $reason = null, ?int $gap = null, array $advantages = [], int $chosen = 2): array
    {
        return [
            'recommended_vendor_id' => 1,
            'chosen_vendor_id'      => $followed ? 1 : $chosen,
            'followed'              => $followed,
            'override_reason'       => $reason,
            'score_gap'             => $gap,
            'chosen_advantages'     => $advantages,
        ];
    }

    public function test_readiness_reports_progress_rather_than_a_bare_refusal(): void
    {
        // "12 of 20" reads as a system collecting evidence. "Not enough data" reads as one that is
        // broken, and an operator who believes it is broken stops feeding it.
        $r = $this->learn()->report(array_fill(0, 12, $this->decision(true)));

        $this->assertSame('not_ready', $r['readiness']['level']);
        $this->assertSame(12, $r['readiness']['have']);
        $this->assertSame(20, $r['readiness']['need']);
        $this->assertSame(60, $r['readiness']['pct']);
        $this->assertStringContainsString('12 decisions recorded, 20+ needed', $r['readiness']['message']);
    }

    public function test_the_two_conclusions_are_gated_separately(): void
    {
        // An acceptance rate is readable long before any per-reason pattern is. One blanket "not ready"
        // would hide that the headline figure is already usable.
        $rows = array_merge(
            array_fill(0, 22, $this->decision(true)),
            array_fill(0, 2, $this->decision(false, 'lower_cost', 12, ['cost'])),
        );
        $r = $this->learn()->report($rows);
        $gates = collect($r['readiness']['gates'])->keyBy('key');

        $this->assertTrue($gates['acceptance_rate']['met']);
        $this->assertFalse($gates['pattern_analysis']['met'], 'two overrides is not a pattern');
        $this->assertSame('emerging', $r['readiness']['level']);
        // And with the pattern gate unmet, no weights proposal may appear.
        $this->assertNotContains('weights', array_column($r['suggestions'], 'kind'));
    }

    public function test_the_same_substitution_repeating_is_surfaced_on_its_own(): void
    {
        // Eight "faster availability" overrides look like a general preference for speed. If all eight
        // swapped the SAME pair of garages it is one specific fact about one garage — a different
        // problem with a different fix, and invisible in the per-reason totals.
        $rows = array_merge(
            array_fill(0, 14, $this->decision(true)),
            array_fill(0, 8, $this->decision(false, 'faster_turnaround', 6, ['speed'], 77)),
            [$this->decision(false, 'lower_cost', 20, ['cost'], 88)],
        );
        $r = $this->learn()->report($rows);

        $this->assertCount(1, $r['repeat_pairs'], 'a one-off swap is not a pattern');
        $this->assertSame(77, $r['repeat_pairs'][0]['chosen_vendor_id']);
        $this->assertSame(8, $r['repeat_pairs'][0]['count']);
        $this->assertSame('faster_turnaround', $r['repeat_pairs'][0]['top_reason']);
        $this->assertTrue($r['repeat_pairs'][0]['in_scope']);
    }

    public function test_a_customer_request_is_not_counted_against_the_engine(): void
    {
        // The engine has no business modelling who the customer likes. Counting these as misses makes
        // it look worse the better the operation is at serving its customers — a metric that punishes
        // correct behaviour is worse than no metric.
        $rows = array_merge(
            array_fill(0, 10, $this->decision(true)),
            array_fill(0, 10, $this->decision(false, 'customer_requested', 30)),
        );
        $r = $this->learn()->report($rows);

        $this->assertSame(50.0, $r['acceptance_pct'], 'raw rate should still show every override');
        $this->assertSame(100.0, $r['adjusted_pct'], 'out-of-scope overrides must not count as engine misses');
        $this->assertSame(10, $r['out_of_scope']);
    }

    public function test_a_corroborated_cost_pattern_becomes_a_weights_proposal(): void
    {
        // Supervisors keep choosing cheaper garages AND our own figures agree those garages were
        // cheaper. That is the strong case: we measured the advantage and scored it too lightly.
        $rows = array_merge(
            array_fill(0, 14, $this->decision(true)),
            array_fill(0, 8, $this->decision(false, 'lower_cost', 12, ['cost'])),
        );
        $r = $this->learn()->report($rows);

        $weights = array_values(array_filter($r['suggestions'], fn ($s) => $s['kind'] === 'weights'));
        $this->assertNotEmpty($weights);
        $this->assertSame('cost', $weights[0]['axis']);
        $this->assertStringContainsString('business.weights.cost', $weights[0]['action']);
    }

    public function test_an_uncorroborated_pattern_is_a_data_question_not_a_weights_change(): void
    {
        // They say cheaper; our data says the chosen garage was not cheaper. Re-weighting cost here
        // would tune the engine toward a belief we cannot verify — the right move is to go and find
        // out whether the supervisors or the ledger are wrong.
        $rows = array_merge(
            array_fill(0, 14, $this->decision(true)),
            array_fill(0, 8, $this->decision(false, 'lower_cost', 12, [])),
        );
        $r = $this->learn()->report($rows);

        $kinds = array_column($r['suggestions'], 'kind');
        $this->assertContains('data', $kinds);
        $this->assertNotContains('weights', $kinds, 'weights were changed on an unverified claim');
    }

    public function test_nothing_is_concluded_from_too_few_decisions(): void
    {
        // Two overrides by one supervisor in one week is not a pattern, and retuning on it is how a
        // model starts chasing noise.
        $rows = array_merge(
            array_fill(0, 3, $this->decision(true)),
            array_fill(0, 6, $this->decision(false, 'lower_cost', 12, ['cost'])),
        );
        $r = $this->learn()->report($rows);

        $this->assertFalse($r['sufficient']);
        $this->assertSame([], $r['suggestions']);
        $this->assertStringContainsString('below the 20 needed', $r['note']);
    }

    public function test_unexplained_overrides_are_reported_as_a_capture_gap(): void
    {
        $rows = array_merge(
            array_fill(0, 14, $this->decision(true)),
            array_fill(0, 8, $this->decision(false, null, 20)),
        );
        $r = $this->learn()->report($rows);

        $this->assertSame(8, $r['unexplained']);
        $this->assertContains('capture', array_column($r['suggestions'], 'kind'));
    }

    public function test_a_cluster_of_near_ties_is_flagged_as_a_tie_break_question(): void
    {
        // Overrides at a 3-point gap mean the engine nearly agreed — the one case where it plausibly
        // just got the ordering wrong, as opposed to being overruled on grounds it never saw.
        $rows = array_merge(
            array_fill(0, 14, $this->decision(true)),
            array_fill(0, 8, $this->decision(false, 'faster_turnaround', 3, ['speed'])),
        );
        $r = $this->learn()->report($rows);

        $this->assertContains('tie_break', array_column($r['suggestions'], 'kind'));
        $reason = collect($r['by_reason'])->firstWhere('reason', 'faster_turnaround');
        $this->assertSame(8, $reason['near_ties']);
        $this->assertSame(3.0, $reason['median_gap']);
    }

    public function test_relationship_overrides_are_never_reported_as_uncorroborated(): void
    {
        // Nobody can corroborate "we have always used them" from a repair history. Reporting these as
        // unsupported would imply the supervisor was making it up.
        $rows = array_merge(
            array_fill(0, 14, $this->decision(true)),
            array_fill(0, 8, $this->decision(false, 'existing_relation', 25)),
        );
        $r = $this->learn()->report($rows);

        $rel = collect($r['by_reason'])->firstWhere('reason', 'existing_relation');
        $this->assertFalse($rel['in_scope']);
        $this->assertSame(0, $rel['corroborated']);
        // And it produces no proposal at all — there is nothing here for the engine to fix.
        $this->assertEmpty(array_filter($r['suggestions'], fn ($s) => $s['axis'] === 'relationship'));
    }

    public function test_decisions_made_without_a_recommendation_are_excluded_entirely(): void
    {
        // A manual assign with no engine suggestion on screen cannot be an acceptance or a rejection.
        $rows = array_merge(
            array_fill(0, 5, $this->decision(true)),
            array_fill(0, 5, ['recommended_vendor_id' => null, 'followed' => null, 'override_reason' => null, 'score_gap' => null, 'chosen_advantages' => []]),
        );
        $r = $this->learn()->report($rows);

        $this->assertSame(5, $r['total']);
        $this->assertSame(100.0, $r['acceptance_pct']);
    }
}
