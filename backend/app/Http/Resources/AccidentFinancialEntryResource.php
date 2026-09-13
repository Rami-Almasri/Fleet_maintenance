<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One figure on the accident ledger, served WITH its supersession state.
 *
 * Superseded rows are not hidden from the API. The revision history is the argument with the insurer
 * — "you quoted 12,000 and approved 7,400" — and a client that only ever sees live rows cannot show
 * it. `is_live` is what the breakdown reads; `superseded_by_entry_id` is what the history renders.
 */
class AccidentFinancialEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'accident_case_id'       => $this->accident_case_id,
            'phase'                  => $this->phase,
            'party'                  => $this->party,
            'amount'                 => $this->amount,
            'currency'               => $this->currency,
            'note'                   => $this->note,
            'maintenance_id'         => $this->maintenance_id,
            'vehicle_document_id'    => $this->vehicle_document_id,
            'external_ref'           => $this->external_ref,
            'is_live'                => $this->resource->isLive(),
            'superseded_at'          => $this->superseded_at?->toIso8601String(),
            'superseded_by_entry_id' => $this->superseded_by_entry_id,
            'recorded_by_name'       => $this->recorded_by_name,
            'recorded_at'            => $this->recorded_at?->toIso8601String(),
        ];
    }
}
