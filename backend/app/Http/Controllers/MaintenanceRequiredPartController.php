<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Resources\MaintenanceRequiredPartResource;
use App\Http\Resources\PartRequestResource;
use App\Models\Maintenance;
use App\Models\MaintenanceRequiredPart;
use App\Services\MaintenanceRequiredPartService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The inspection's REQUIRED PARTS — what the inspector said the repair would need.
 *
 * These rows exist for TRACEABILITY: they are the technical requirement in the inspector's own words,
 * kept separate from the procurement that follows. Raising the actual {@see \App\Models\PartRequest} rows
 * is automatic and happens inside submitReport ({@see MaintenanceRequiredPartService::raiseRequests()}) —
 * there is no approval step and no manual conversion, so this controller has NO convert/dismiss actions.
 * A request that shouldn't be bought is rejected in the part-request lifecycle, where sourcing decisions
 * belong. See [[inspection-required-parts-split]].
 *
 * `store` stays for the case where a requirement is spotted AFTER the report was filed (mid-repair): it
 * records the line and hands it straight to procurement, exactly as the report path does.
 */
class MaintenanceRequiredPartController extends Controller
{
    public function __construct(private MaintenanceRequiredPartService $service) {}

    /** Every required part on a ticket — the read-only traceability panel in the ticket drawer. */
    public function index(Request $request, Maintenance $ticket)
    {
        try {
            $rows = $ticket->requiredParts()->with(['task:id,symptom', 'requests:id,status,part_name'])
                ->orderBy('id')->get();

            return ResponseHelper::SuccessResponse(
                MaintenanceRequiredPartResource::collection($rows),
                'Required parts retrieved'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Add required parts to an existing ticket — the mid-repair case, where a need surfaces after the
     * report is in. Records the technical line AND raises its part request in the same call, so this path
     * behaves identically to filing them with the report.
     */
    public function store(Request $request, Maintenance $ticket)
    {
        try {
            $data = $request->validate([
                'required_parts'             => ['required', 'array', 'min:1'],
                'required_parts.*.part_name' => ['required', 'string', 'max:255'],
                'required_parts.*.quantity'  => ['nullable', 'numeric', 'min:0.01', 'max:9999'],
                'required_parts.*.priority'  => ['nullable', Rule::in(MaintenanceRequiredPart::PRIORITIES)],
                'required_parts.*.notes'     => ['nullable', 'string', 'max:1000'],
                // The finding (symptom text) this part serves — how the line binds to its fault.
                'required_parts.*.finding'   => ['nullable', 'string', 'max:500'],
            ]);

            $created  = $this->service->record($ticket, $data['required_parts'], $request->user());
            $requests = $this->service->raiseRequests($ticket, $request->user());

            return ResponseHelper::SuccessResponse([
                'created'        => $created,
                'requests'       => PartRequestResource::collection(collect($requests)),
                'required_parts' => MaintenanceRequiredPartResource::collection(
                    $ticket->requiredParts()->with(['task:id,symptom', 'requests:id,status,part_name'])->orderBy('id')->get()
                ),
            ], count($requests) . ' part request(s) sent to procurement');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
