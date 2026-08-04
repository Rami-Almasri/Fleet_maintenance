<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Requests\ResolveWarrantyClaimRequest;
use App\Http\Requests\StoreWarrantyClaimRequest;
use App\Http\Requests\StoreWarrantyRequest;
use App\Http\Requests\UpdateWarrantyRequest;
use App\Http\Resources\WarrantyClaimResource;
use App\Http\Resources\WarrantyResource;
use App\Models\Vehicle;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Services\WarrantyService;
use Illuminate\Http\Request;

/**
 * Warranties and the claims made against them.
 *
 * NO try/catch: the global handler in bootstrap/app.php shapes uncaught exceptions into the standard
 * envelope and deliberately lets ValidationException reach Laravel's native {message, errors}
 * renderer, which is the shape the forms read to highlight fields. Catching Throwable here would
 * flatten the service's business-rule failures into a single toast.
 *
 * Every listing returns each warranty WITH its computed verdict (see WarrantyResource) rather than
 * a bare expiry date, because a date alone overstates cover on exactly the cars driven hardest.
 */
class WarrantyController extends Controller
{
    public function __construct(private WarrantyService $service) {}

    /**
     * The warranty register, filterable.
     *
     * `expiring_days` filters on the DATE leg only — the distance leg cannot be range-scanned in
     * SQL because it depends on each car's current odometer. The response says so in `filters_note`
     * rather than letting a caller believe the list is complete on both legs.
     */
    public function index(Request $request)
    {
        $request->validate([
            'kind'          => ['nullable', 'in:part,repair'],
            'status'        => ['nullable', 'in:active,void'],
            'vehicle_id'    => ['nullable', 'exists:vehicles,id'],
            'provider_id'   => ['nullable', 'exists:vendors,id'],
            'expiring_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'per_page'      => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Warranty::with(['vehicle:id,plate_no,odometer', 'catalog:id,name,name_ar', 'provider:id,name'])
            ->withCount('claims')
            ->when($request->filled('kind'), fn ($q) => $q->ofKind($request->string('kind')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->integer('vehicle_id')))
            ->when($request->filled('provider_id'), fn ($q) => $q->where('provider_vendor_id', $request->integer('provider_id')))
            ->when($request->filled('expiring_days'), fn ($q) => $q->expiringWithinDays($request->integer('expiring_days')))
            ->orderByDesc('starts_on')
            ->orderByDesc('id');

        $page = $query->paginate($request->integer('per_page') ?: 25);

        return ResponseHelper::SuccessResponse([
            'warranties' => WarrantyResource::collection($page->items()),
            'meta' => [
                'total'        => $page->total(),
                'per_page'     => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
            ],
            'filters_note' => 'expiring_days matches the date leg only. A warranty can already be finished on distance while its date leg still looks healthy — read verdict.state on each row for the real answer.',
        ], 'Warranties retrieved');
    }

    public function show(Warranty $warranty)
    {
        $warranty->load(['vehicle:id,plate_no,odometer', 'catalog:id,name,name_ar', 'provider:id,name', 'claims']);

        return ResponseHelper::SuccessResponse(new WarrantyResource($warranty), 'Warranty retrieved');
    }

    public function store(StoreWarrantyRequest $request)
    {
        $warranty = $this->service->create($request->validated(), $request->user());
        $warranty->load(['vehicle:id,plate_no,odometer', 'catalog:id,name,name_ar', 'provider:id,name']);

        return ResponseHelper::SuccessResponse(new WarrantyResource($warranty), 'Warranty recorded', 201);
    }

    public function update(UpdateWarrantyRequest $request, Warranty $warranty)
    {
        $warranty = $this->service->update($warranty, $request->validated(), $request->user());
        $warranty->load(['vehicle:id,plate_no,odometer', 'catalog:id,name,name_ar', 'provider:id,name']);

        return ResponseHelper::SuccessResponse(new WarrantyResource($warranty), 'Warranty updated');
    }

    /**
     * Soft-delete: the row was created in error.
     *
     * This is NOT how a warranty ends. A warranty that ran out expires on its own (computed), and
     * one destroyed by misuse is VOIDED with a reason. Deleting is only for "this should never have
     * been recorded", and even then the row is kept because a warranty is evidence in a dispute.
     */
    public function destroy(Warranty $warranty)
    {
        $warranty->delete();

        return ResponseHelper::SuccessResponse(null, 'Warranty removed (kept in history)');
    }

    /** The promise was destroyed before it ran out. Reason mandatory — enforced in the service. */
    public function void(Request $request, Warranty $warranty)
    {
        $data = $request->validate(['void_reason' => ['required', 'string', 'max:2000']]);

        $warranty = $this->service->void($warranty, $data['void_reason'], $request->user());

        return ResponseHelper::SuccessResponse(new WarrantyResource($warranty), 'Warranty voided');
    }

    public function reinstate(Request $request, Warranty $warranty)
    {
        $warranty = $this->service->reinstate($warranty, $request->user());

        return ResponseHelper::SuccessResponse(new WarrantyResource($warranty), 'Warranty reinstated');
    }

    /** Everything covering one car, each judged against that car's current odometer. */
    public function forVehicle(Vehicle $vehicle)
    {
        $rows = $this->service->forVehicle($vehicle);

        return ResponseHelper::SuccessResponse([
            'vehicle' => ['id' => $vehicle->id, 'plate_no' => $vehicle->plate_no, 'odometer' => $vehicle->odometer],
            'warranties' => array_map(fn ($r) => [
                ...(new WarrantyResource($r['warranty']))->toArray(request()),
                'verdict' => $r['verdict'],
            ], $rows),
        ], 'Vehicle warranties retrieved');
    }

    // ── claims ───────────────────────────────────────────────────────────────────────────────────

    public function claims(Warranty $warranty)
    {
        return ResponseHelper::SuccessResponse(
            WarrantyClaimResource::collection($warranty->claims()->orderByDesc('claimed_on')->get()),
            'Claims retrieved'
        );
    }

    /**
     * File a claim. Allowed even when the warranty is already finished — the verdict is recorded
     * either way, and a claim we lost on distance is exactly the evidence needed when the next
     * supply contract is negotiated.
     */
    public function storeClaim(StoreWarrantyClaimRequest $request, Warranty $warranty)
    {
        $claim = $this->service->fileClaim($warranty, $request->validated(), $request->user());

        return ResponseHelper::SuccessResponse(new WarrantyClaimResource($claim), 'Claim filed', 201);
    }

    public function resolveClaim(ResolveWarrantyClaimRequest $request, WarrantyClaim $claim)
    {
        $claim = $this->service->resolveClaim($claim, $request->validated(), $request->user());

        return ResponseHelper::SuccessResponse(new WarrantyClaimResource($claim), 'Claim outcome recorded');
    }
}
