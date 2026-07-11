<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Contract;
use App\Models\Maintenance;
use App\Services\OdooExportService;

/**
 * Manual-to-Odoo bridge endpoints (read-only, downstream).
 *
 * These do NOT sync anything — they return the finalised Parts + Labor data in Odoo-ready shape so
 * the team can review and push on demand (and so a future Odoo module has a single, stable payload to
 * pull). Gated to maintenance.manage (the controllers who own the money). Deliberately a SEPARATE
 * controller from the workflow state-machine controller: export is a downstream concern, not a
 * lifecycle transition, and keeping it standalone means the bridge stands on its own.
 */
class OdooExportController extends Controller
{
    public function __construct(private OdooExportService $export)
    {
    }

    /** The Odoo-ready payload for ONE maintenance ticket. */
    public function ticket(Maintenance $ticket)
    {
        try {
            return ResponseHelper::SuccessResponse(
                $this->export->forTicket($ticket),
                'Odoo export payload ready (ticket)',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** The Odoo-ready payload for a whole OM contract — every linked ticket's finalised line items. */
    public function contract(Contract $contract)
    {
        try {
            return ResponseHelper::SuccessResponse(
                $this->export->forContract($contract),
                'Odoo export payload ready (contract)',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
