<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;

/**
 * Data-integrity guardrail for closing a workshop event.
 *
 * A car can only be marked BACK from the garage (event_status = 'IN') once we know what the
 * visit actually cost, who did the work, and when it returned — without those three we cannot
 * track profit or hold a garage accountable. The check runs against the FINAL (merged) state:
 * on a partial edit a field the request didn't send falls back to the value already on the row,
 * so re-saving an already-complete event never trips the guard.
 *
 * Shared by Store + Update so the rule is defined once and can never drift between them.
 */
trait GuardsWorkshopEventClose
{
    /** Fields that MUST be present before an event can be closed → human label for the error. */
    private const CLOSE_REQUIRED = [
        'cost'           => 'Cost',
        'vendor_id'      => 'Vendor',
        'actual_in_date' => 'Actual In Date',
    ];

    protected function guardWorkshopClose(Validator $validator): void
    {
        // The model being edited (null on create) — its current values back-fill anything
        // this request didn't send, mirroring the service's array_key_exists merge.
        $existing = $this->route('workshopEvent');

        $all   = $this->all();
        $final = function (string $key) use ($all, $existing) {
            if (array_key_exists($key, $all)) {
                $v = $all[$key];
                return $v === '' ? null : $v;
            }
            return $existing->{$key} ?? null;
        };

        // Guard only applies when the event is being CLOSED.
        if ($final('event_status') !== 'IN') {
            return;
        }

        foreach (self::CLOSE_REQUIRED as $field => $label) {
            if ($final($field) === null) {
                $validator->errors()->add($field, "Cannot close event. Missing {$label}.");
            }
        }
    }
}
