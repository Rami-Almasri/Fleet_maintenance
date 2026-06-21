<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Contract;
use App\Services\ContractExchangeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Contract Exchange — the HTTP surface for reviewing and linking car swaps.
 * Detection lives in ContractExchangeService; this just validates input, resolves the
 * pair, and returns the refreshed chain so the UI can re-render in place.
 */
class ExchangeController extends Controller
{
    public function __construct(private ContractExchangeService $exchanges)
    {
    }

    /** Pending (un-linked) swap suggestions — the standalone feed (also embedded in /Anomalies). */
    public function pending(Request $request)
    {
        try {
            $data = $this->exchanges->pendingLinks(
                (int) $request->integer('days', 90),
                (int) $request->integer('cap', 100),
            );

            return ResponseHelper::SuccessResponse($data, 'Pending exchange links retrieved', 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /** The exchange context for one contract: its chain + suggested parent/children. */
    public function show(Contract $contract)
    {
        try {
            return ResponseHelper::SuccessResponse([
                'chain'      => $this->exchanges->chain($contract),
                'candidates' => $this->exchanges->candidatesFor($contract),
            ], 'Exchange context retrieved', 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Link {contract} (the new rental / child) to its parent, optionally recording the
     * deposit/credit to carry across. Returns the refreshed chain.
     *   body: { parent_id: int, carry: 'none'|'deposit'|'credit'|'both' }
     */
    public function link(Request $request, Contract $contract)
    {
        try {
            $validated = $request->validate([
                'parent_id' => ['required', 'integer', Rule::exists('contracts', 'id')],
                'carry'     => ['nullable', Rule::in(['none', 'deposit', 'credit', 'both'])],
            ]);

            $parent = Contract::findOrFail($validated['parent_id']);
            $carry  = $validated['carry'] ?? 'none';
            $by     = $request->user()?->name ?? $request->user()?->email;

            $child = $carry === 'none'
                ? $this->exchanges->link($parent, $contract, $by)
                : $this->exchanges->linkAndCarry($parent, $contract, $carry, $by);

            return ResponseHelper::SuccessResponse([
                'chain'           => $this->exchanges->chain($child),
                'carried_balance' => $child->carried_balance,
            ], 'Contracts linked', 200);
        } catch (\InvalidArgumentException $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 422);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /** Detach {contract} from its parent (undo a link). Returns the refreshed chain. */
    public function unlink(Contract $contract)
    {
        try {
            $child = $this->exchanges->unlink($contract);

            return ResponseHelper::SuccessResponse([
                'chain' => $this->exchanges->chain($child),
            ], 'Exchange link removed', 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }
}
