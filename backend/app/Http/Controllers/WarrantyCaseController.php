<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Resources\WarrantyClaimResource;
use App\Models\Vehicle;
use App\Models\WarrantyClaim;
use App\Services\Warranty\CoverageSubject;
use App\Services\Warranty\WarrantyCaseService;
use App\Services\Warranty\WarrantyCoverageEngine;
use App\Services\Warranty\WarrantyReportService;
use App\Support\WarrantyCoverage;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Warranty cases — the file opened when somebody suspects this is not ours to pay for.
 *
 * NO try/catch, matching WarrantyController and the rest of this codebase's newer controllers: the
 * global handler in bootstrap/app.php shapes every uncaught exception into the standard envelope and
 * deliberately lets ValidationException reach Laravel's native {message, errors} renderer, which is
 * the shape the forms read to highlight fields. Catching Throwable here would flatten the service's
 * business-rule refusals — "a case moves forward only", "record the reference they gave you" — into
 * a single anonymous toast.
 *
 * EVERY WRITE GOES THROUGH WarrantyCaseService. Not one stage is set here. That is not ceremony: a
 * stage change is three writes (the row, the car's timeline, the people whose work just changed) and
 * a controller that set `stage` directly would be a fourth way for a case to move without anybody
 * being told — which is exactly how cases end up sitting at `authorization_requested` for five weeks.
 */
class WarrantyCaseController extends Controller
{
    public function __construct(
        private WarrantyCaseService $cases,
        private WarrantyCoverageEngine $engine,
    ) {}

    /**
     * The board.
     *
     * Defaults to OPEN cases only, because the question this page answers is "what is waiting on
     * somebody?" and a list dominated by closed history answers a different one. `stage=all` opts
     * into the archive.
     */
    public function index(Request $request)
    {
        $request->validate([
            'stage'      => ['nullable', 'string'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'origin'     => ['nullable', Rule::in(WarrantyClaim::ORIGINS)],
            'open'       => ['nullable', 'boolean'],
            'per_page'   => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = WarrantyClaim::query()
            ->with(['vehicle:id,plate_no,make,model,odometer', 'warranty', 'catalog:id,name,name_ar'])
            ->when($request->filled('stage') && $request->string('stage') !== 'all',
                fn ($q) => $q->whereIn('stage', explode(',', (string) $request->string('stage'))))
            ->when($request->filled('vehicle_id'), fn ($q) => $q->forVehicle($request->integer('vehicle_id')))
            ->when($request->filled('origin'), fn ($q) => $q->where('origin', $request->string('origin')))
            // The default. Explicitly opt out with open=0 to see everything.
            ->when(! $request->has('open') || $request->boolean('open'), fn ($q) => $q->openCases())
            ->orderByRaw('CASE WHEN stage = ? THEN 0 ELSE 1 END', [WarrantyClaim::STAGE_COVERAGE_REVIEW])
            ->orderBy('created_at');

        $page = $query->paginate($request->integer('per_page') ?: 25);

        return ResponseHelper::SuccessResponse([
            'cases' => WarrantyClaimResource::collection($page->items()),
            'meta'  => [
                'total' => $page->total(), 'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            ],
            // The ladder, shipped with the list so the board's columns are defined server-side and
            // cannot drift from the stages the service will actually accept.
            'stages' => WarrantyClaim::STAGES,
        ], 'Warranty cases retrieved');
    }

    public function show(WarrantyClaim $case)
    {
        $case->load([
            'vehicle:id,plate_no,make,model,odometer', 'warranty', 'catalog:id,name,name_ar',
            'component', 'task:id,symptom', 'partRequests', 'documents',
        ]);

        return ResponseHelper::SuccessResponse(new WarrantyClaimResource($case), 'Warranty case retrieved');
    }

    /**
     * Open a case by hand — a fault somebody suspects is claimable, before any purchase exists.
     *
     * The engine is consulted rather than trusted from the client: if it can already answer the
     * question from recorded cover, the case starts at COVERED instead of asking a human to review
     * something the data settles. Rubber-stamping is what kills a review queue.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'vehicle_id'           => ['required', 'exists:vehicles,id'],
            'component_catalog_id' => ['nullable', 'exists:component_catalog,id'],
            'vehicle_component_id' => ['nullable', 'exists:vehicle_components,id'],
            'maintenance_task_id'  => ['nullable', 'exists:maintenance_tasks,id'],
            'maintenance_id'       => ['nullable', 'exists:maintenances,id'],
            'part_name'            => ['nullable', 'string', 'max:255'],
            'failure_description'  => ['nullable', 'string', 'max:4000'],
            'diagnosis'            => ['nullable', 'string', 'max:4000'],
            'origin'               => ['nullable', Rule::in(WarrantyClaim::ORIGINS)],
        ]);

        $vehicle = Vehicle::findOrFail($data['vehicle_id']);
        $subject = CoverageSubject::fromRequestData($data);
        $assessment = $this->engine->assess($vehicle, $subject);

        $origin = $data['origin'] ?? WarrantyClaim::ORIGIN_MANUAL;
        $context = [
            'failure_description' => $data['failure_description'] ?? null,
            'diagnosis'           => $data['diagnosis'] ?? null,
        ];

        $case = $assessment->isCovered()
            ? $this->cases->openCoveredCase($vehicle, $assessment, $subject, $request->user(), $origin, $context)
            : $this->cases->openCoverageReview($vehicle, $assessment, $subject, $request->user(), $origin, $context);

        $case->load(['vehicle:id,plate_no,make,model,odometer', 'warranty']);

        return ResponseHelper::SuccessResponse(new WarrantyClaimResource($case), 'Warranty case opened', 201);
    }

    /**
     * The coverage decision. The money moment.
     *
     * `reason_code` is required and constrained to the vocabulary, never free text: it is what the
     * engine reads next time so the same question is not put to a human twice, and a typed sentence
     * cannot be matched against anything. The sentence still has somewhere to go — `note`.
     */
    public function decide(Request $request, WarrantyClaim $case)
    {
        $data = $request->validate([
            'verdict'     => ['required', Rule::in([WarrantyCoverage::COVERED, WarrantyCoverage::NOT_COVERED])],
            'reason_code' => ['required', Rule::in(WarrantyCoverage::REASONS)],
            'note'        => ['nullable', 'string', 'max:4000'],
        ]);

        $case = $this->cases->decideCoverage(
            $case, $data['verdict'], $data['reason_code'], $request->user(), $data['note'] ?? null,
        );

        $case->load(['vehicle:id,plate_no,make,model,odometer', 'warranty']);

        return ResponseHelper::SuccessResponse(new WarrantyClaimResource($case), 'Coverage decision recorded');
    }

    /** Move a covered case along its provider path. Forward only — see the service. */
    public function advance(Request $request, WarrantyClaim $case)
    {
        $data = $request->validate([
            'stage'                    => ['required', Rule::in(WarrantyClaim::STAGES)],
            'authorization_ref'        => ['nullable', 'string', 'max:120'],
            'claim_reference'          => ['nullable', 'string', 'max:120'],
            'provider_response_due_on' => ['nullable', 'date'],
            'diagnosis'                => ['nullable', 'string', 'max:4000'],
            'note'                     => ['nullable', 'string', 'max:2000'],
        ]);

        $case = $this->cases->advance($case, $data['stage'], $request->user(), $data);
        $case->load(['vehicle:id,plate_no,make,model,odometer', 'warranty']);

        return ResponseHelper::SuccessResponse(new WarrantyClaimResource($case), 'Warranty case updated');
    }

    /**
     * What the case was worth.
     *
     * Two figures, never one. `recovered` is money that came back; `avoided` is money we never spent
     * because they did the work. A free gearbox recovers nothing and avoids a great deal, and a
     * single field would have forced somebody to choose which lie to tell.
     */
    public function recovery(Request $request, WarrantyClaim $case)
    {
        $data = $request->validate([
            'recovered_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'avoided_amount'   => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'currency'         => ['nullable', 'string', 'size:3'],
            'remedy'           => ['nullable', Rule::in(WarrantyClaim::REMEDIES)],
        ]);

        $case = $this->cases->recordRecovery($case, $data, $request->user());
        $case->load(['vehicle:id,plate_no', 'warranty']);

        return ResponseHelper::SuccessResponse(new WarrantyClaimResource($case), 'Recovery recorded');
    }

    public function close(Request $request, WarrantyClaim $case)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        $case = $this->cases->close($case, $request->user(), $data['reason'] ?? null);

        return ResponseHelper::SuccessResponse(new WarrantyClaimResource($case), 'Warranty case closed');
    }

    /**
     * Ask the engine WITHOUT changing anything — "would this be covered?".
     *
     * Read-only on purpose, and used by the purchase-request form to show the warranty banner BEFORE
     * the user fills the whole thing in and gets refused. A gate that only speaks at submit time
     * teaches people to resent it; one that says "this looks like a dealer job" while they are still
     * typing gets them to make the phone call instead.
     */
    public function preview(Request $request)
    {
        $data = $request->validate([
            'vehicle_id'           => ['required', 'exists:vehicles,id'],
            'component_catalog_id' => ['nullable', 'exists:component_catalog,id'],
            'vehicle_component_id' => ['nullable', 'exists:vehicle_components,id'],
            'maintenance_task_id'  => ['nullable', 'exists:maintenance_tasks,id'],
            'part_name'            => ['nullable', 'string', 'max:255'],
        ]);

        $vehicle    = Vehicle::findOrFail($data['vehicle_id']);
        $assessment = $this->engine->assess($vehicle, CoverageSubject::fromRequestData($data));

        return ResponseHelper::SuccessResponse($assessment->toArray(), 'Coverage assessed');
    }

    /** The warranty dashboard's tiles. @see WarrantyReportService for why some are computed in PHP. */
    public function dashboard(WarrantyReportService $report)
    {
        return ResponseHelper::SuccessResponse($report->dashboard(), 'Warranty dashboard retrieved');
    }
}
