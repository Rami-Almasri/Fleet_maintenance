<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Contract;
use App\Services\ReconciliationService;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    /**
     * Financial Reconciliation (read-only MVP). Resolve ONE contract — by ?contract_no= (the
     * human-facing number) or ?contract_id= (internal id) — and bridge it to the accounting
     * system: Fleet ledger vs. real Cash Collected vs. Booked Vouchers, with a tolerance-aware
     * verdict. Hits the live OfficeManager API; nothing is persisted.
     *
     * Optional ?tolerance= overrides the cash-gap tolerance (percent; default 2).
     */
    public function show(Request $request, ReconciliationService $svc)
    {
        try {
            $contract = $this->resolve($request);
            if (! $contract) {
                return ResponseHelper::FailureResponse(null, 'Contract not found — enter a valid contract number.', 404);
            }

            $tolerance = max(0.0, (float) $request->query('tolerance', 2.0));
            $contract->loadMissing(['customer', 'vehicle']);

            return ResponseHelper::SuccessResponse(
                $svc->forContract($contract, $tolerance),
                'Reconciliation computed',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Fleet-wide Net Profit for one month (cash basis): Σ net collected on rentals returned in the
     * month − Σ maintenance cost on maintenance contracts closed in the month. Computed from synced
     * data (no live API calls), so it's fast and fleet-scale safe. ?month= & ?year= default to now.
     */
    public function fleet(Request $request, ReconciliationService $svc)
    {
        try {
            $year  = (int) $request->query('year', now()->year);
            $month = (int) $request->query('month', now()->month);
            if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
                return ResponseHelper::FailureResponse(null, 'Invalid month or year.', 422);
            }

            return ResponseHelper::SuccessResponse(
                $svc->fleetMonth($year, $month),
                'Fleet Net Profit computed',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /** Resolve the target contract from ?contract_id= (internal) or ?contract_no= (OM number). */
    private function resolve(Request $request): ?Contract
    {
        if ($id = $request->query('contract_id')) {
            return Contract::find($id);
        }
        if (($no = $request->query('contract_no')) !== null && $no !== '') {
            // contract_no isn't unique across types/history — take the most recent match.
            return Contract::where('contract_no', $no)->orderByDesc('id')->first();
        }

        return null;
    }
}
