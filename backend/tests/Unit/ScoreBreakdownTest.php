<?php

namespace Tests\Unit;

use App\Services\Garage\ScoreBreakdown;
use PHPUnit\Framework\TestCase;

/**
 * The 0–100 score, flattened into the rows a card renders.
 *
 * What this pins is the SUM: the maxima add to 100 and the awarded points add to the headline, because
 * the card does no arithmetic of its own — a presenter that silently dropped a component would leave a
 * score on screen that its own breakdown does not explain.
 *
 * The file used to also cover compare(), which subtracted two of these for the per-fault "Why {garage}
 * ranked higher" block. That block and the method are gone; both garages' derivations still render side
 * by side, which is where the auditability lives now.
 */
class ScoreBreakdownTest extends TestCase
{
    /** @return array<string, mixed> */
    private function bd(int $total, array $awarded, array $details = []): array
    {
        $max = ['fault_matching' => 40, 'vehicle_similarity' => 20, 'historical_success' => 20, 'specialization' => 10, 'confidence' => 10];
        $components = [];
        foreach ($awarded as $key => $pts) {
            $components[$key] = [
                'label'      => ucfirst(str_replace('_', ' ', $key)),
                'question'   => "What about {$key}?",
                'awarded'    => $pts,
                'max'        => $pts === null ? 0 : $max[$key],
                'applicable' => $pts !== null,
                'detail'     => $details[$key] ?? "facts for {$key}",
            ];
        }

        return ['total' => $total, 'components' => $components, 'redistributed' => false, 'budget_note' => null];
    }

    public function test_present_flattens_components_in_scoring_order_with_their_facts(): void
    {
        $out = ScoreBreakdown::present($this->bd(83, [
            'fault_matching' => 34, 'vehicle_similarity' => 15, 'historical_success' => 14,
            'specialization' => 10, 'confidence' => 10,
        ]));

        $this->assertSame(83, $out['total']);
        $this->assertSame(
            ['fault_matching', 'vehicle_similarity', 'historical_success', 'specialization', 'confidence'],
            array_column($out['components'], 'key'),
        );
        $this->assertSame(34, $out['components'][0]['awarded']);
        $this->assertSame(40, $out['components'][0]['max']);
        $this->assertSame('facts for fault_matching', $out['components'][0]['detail']);

        // The maxima must still sum to 100 and the awarded to the headline — the card renders both with
        // no arithmetic of its own, so a presenter that dropped a row would silently break the sum.
        $this->assertSame(100, array_sum(array_column($out['components'], 'max')));
        $this->assertSame(83, array_sum(array_column($out['components'], 'awarded')));
    }
}
