<?php

namespace App\Http\Resources\Intelligence;

use App\Intelligence\Evidence\EvidenceQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Claim → method → rows, in that order, because that is the order a sceptical reader needs them.
 *
 * The claim restates what was asserted so the drawer stands alone when someone forwards a link. The
 * method comes before the data because a table of fifty rows means nothing until you know what was
 * counted. The technical note is separated so the operator-facing explanation is not diluted by
 * engine vocabulary — the platform's operational-language rule.
 */
class EvidenceResource extends JsonResource
{
    public function __construct(
        private EvidenceQuery $evidence,
        private int $page = 1,
        private int $perPage = 50,
    ) {
        parent::__construct($evidence);
    }

    public function toArray(Request $request): array
    {
        $result = $this->evidence->rows($this->page, $this->perPage);

        return [
            'evidence_query_id' => $this->evidence->id(),
            'claim'             => $this->evidence->claim(),
            'method'            => $this->evidence->method(),
            'technical_note'    => $this->evidence->technicalNote(),
            'columns'           => $this->evidence->columns(),
            'rows'              => $result['rows'],
            'meta'              => [
                'total'     => $result['total'],
                'page'      => $this->page,
                'per_page'  => $this->perPage,
                'last_page' => (int) max(1, ceil($result['total'] / max(1, $this->perPage))),
            ],
            // Every evidence set is exportable. If we do not provide it people screenshot the drawer,
            // and a screenshot loses the method and the sample size — the two things that make the
            // number defensible in the first place.
            'exportable'        => true,
        ];
    }
}
