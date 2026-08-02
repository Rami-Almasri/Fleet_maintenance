<?php

namespace App\Services\Garage;

/**
 * The 0–100 match score, as something a supervisor can audit without reading PHP.
 *
 * present() turns the scorer's internal component map into the ordered rows a card renders — every
 * component with the points it earned, the points it could have earned, and the countable facts behind
 * them. It lived as a private method on GarageRecommendationService until the per-fault cards needed
 * the same rows; a second implementation of "how do we show the score" is exactly how a panel ends up
 * explaining a number differently from the engine that produced it.
 *
 * There was a second job here, compare(), which subtracted two present() results component by component
 * for the per-fault "Why {garage} ranked higher" block. That block was removed from the card — both
 * derivations are on screen already and the subtraction was a third telling of the same comparison — so
 * the method went with its only consumer rather than being kept warm for nobody.
 * See [[garage-recommendation-engine]], [[evidence-layer-governance]].
 */
class ScoreBreakdown
{
    /**
     * The card's payload: component rows in scoring order, plus the headline they sum to.
     *
     * @param  array<string, mixed>  $bd  a breakdown() result
     * @return array<string, mixed>
     */
    public static function present(array $bd): array
    {
        $rows = [];
        foreach ((array) ($bd['components'] ?? []) as $key => $c) {
            $rows[] = [
                'key'        => $key,
                'label'      => $c['label'],
                'question'   => $c['question'],
                'awarded'    => $c['awarded'],
                'max'        => $c['max'],
                'applicable' => $c['applicable'],
                'detail'     => $c['detail'],
                'facts'      => $c['facts'] ?? null,
            ];
        }

        return [
            'total'         => $bd['total'] ?? 0,
            'components'    => $rows,
            'redistributed' => $bd['redistributed'] ?? false,
            'note'          => $bd['budget_note'] ?? null,
        ];
    }
}
