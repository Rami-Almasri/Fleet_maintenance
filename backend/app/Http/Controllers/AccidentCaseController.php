<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Resources\AccidentCaseResource;
use App\Http\Resources\AccidentDamageItemResource;
use App\Http\Resources\AccidentFinancialEntryResource;
use App\Models\AccidentCase;
use App\Models\AccidentDamageItem;
use App\Models\AccidentFinancialEntry;
use App\Models\DamageCatalog;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use App\Services\Accident\AccidentCaseService;
use App\Services\Accident\AccidentContextResolver;
use App\Services\Accident\AccidentReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Accident cases — the file opened when a car is damaged in an incident.
 *
 * NO try/catch, matching WarrantyCaseController and the rest of this codebase's newer controllers:
 * the global handler in bootstrap/app.php shapes every uncaught exception into the standard
 * envelope and deliberately lets ValidationException reach Laravel's native {message, errors}
 * renderer — the shape the forms read to highlight fields. Catching Throwable here would flatten
 * the service's business-rule refusals ("the police report is still outstanding", "decide liability
 * before closing") into one anonymous toast, which is precisely the feedback that teaches people to
 * click past a gate rather than answer it.
 *
 * EVERY WRITE GOES THROUGH AccidentCaseService. Not one stage, verdict or amount is set here. A
 * change to a case is two writes — the row and the car's timeline — and a controller that set
 * `liability_status` directly would be a way for the most contested field in the system to move
 * with nobody's name on it.
 */
class AccidentCaseController extends Controller
{
    public function __construct(
        private AccidentCaseService $cases,
        private AccidentContextResolver $context,
    ) {}

    /**
     * The board.
     *
     * Defaults to OPEN cases, because this page answers "what is waiting on somebody?". `stage=all`
     * opens the archive. The named queues (`queue=police|liability|insurer|rental`) are the same
     * scopes the dashboard tiles count, so a tile and the list it links to can never disagree.
     */
    public function index(Request $request)
    {
        $request->validate([
            'stage'       => ['nullable', 'string'],
            'vehicle_id'  => ['nullable', 'exists:vehicles,id'],
            'customer_id' => ['nullable', 'integer'],
            'queue'       => ['nullable', Rule::in(['police', 'liability', 'insurer', 'rental', 'repair'])],
            'liability'   => ['nullable', Rule::in(AccidentCase::LIABILITY_STATUSES)],
            'q'           => ['nullable', 'string', 'max:120'],
            'open'        => ['nullable', 'boolean'],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = AccidentCase::query()
            ->with(['vehicle:id,plate_no,make,model,year,odometer,operational_status'])
            ->withCount(['damageItems', 'repairs', 'documents'])
            ->when($request->filled('stage') && $request->string('stage') !== 'all',
                fn ($q) => $q->whereIn('stage', explode(',', (string) $request->string('stage'))))
            ->when($request->filled('vehicle_id'), fn ($q) => $q->forVehicle($request->integer('vehicle_id')))
            // Matched on the FROZEN reference, not the live FK: a case must stay findable by the
            // customer who was driving even after the customer row is merged or removed.
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_ref', $request->integer('customer_id')))
            ->when($request->filled('liability'), fn ($q) => $q->where('liability_status', $request->string('liability')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $needle = '%' . $request->string('q') . '%';
                $q->where(function ($w) use ($needle) {
                    $w->where('reference', 'like', $needle)
                        ->orWhere('police_report_no', 'like', $needle)
                        ->orWhere('claim_no', 'like', $needle)
                        ->orWhere('customer_name_snapshot', 'like', $needle)
                        ->orWhere('contract_no_snapshot', 'like', $needle)
                        ->orWhere('location', 'like', $needle);
                });
            })
            ->when($request->filled('queue'), fn ($q) => match ($request->string('queue')->toString()) {
                'police'    => $q->awaitingPolice(),
                'liability' => $q->awaitingLiability(),
                'insurer'   => $q->awaitingInsurer(),
                'rental'    => $q->where('responsible_party_type', AccidentCase::PARTY_RENTAL_CUSTOMER)->openCases(),
                'repair'    => $q->where('stage', AccidentCase::STAGE_REPAIR),
                default     => $q,
            })
            ->when(! $request->has('open') || $request->boolean('open'),
                fn ($q) => $request->filled('stage') || $request->filled('queue') ? $q : $q->openCases())
            // Cases with an unanswered documentation question float, then newest first: the police
            // report is the step that silently stalls a case for weeks, so it leads the board.
            ->orderByRaw('CASE WHEN police_status = ? THEN 0 ELSE 1 END', [AccidentCase::POLICE_MISSING])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        $page = $query->paginate($request->integer('per_page') ?: 25);

        return ResponseHelper::SuccessResponse([
            'cases'  => AccidentCaseResource::collection($page->items()),
            'meta'   => [
                'total' => $page->total(), 'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            ],
            // The ladder ships with the list so the board's columns are defined server-side and
            // cannot drift from what the service will actually accept.
            'stages' => AccidentCase::STAGES,
        ], 'Accident cases retrieved');
    }

    public function show(AccidentCase $case)
    {
        $case->load([
            'vehicle:id,plate_no,make,model,year,odometer,operational_status',
            'contract:id,contract_no,state,in_date,contract_balance',
            'damageItems.catalog:id,name,name_ar', 'damageItems.location:id,name',
            'financialEntries', 'liveFinancials', 'documents',
            'repairs:id,accident_case_id,workflow_status,vendor_id,cost,created_at', 'repairs.vendor:id,name',
            'insurerVendor:id,name',
        ]);

        return ResponseHelper::SuccessResponse(new AccidentCaseResource($case), 'Accident case retrieved');
    }

    /**
     * REPORT AN ACCIDENT.
     *
     * The validation is deliberately thin. The only hard requirements are the car and a time that is
     * not in the future; everything else arrives through the workflow. A form that demands a police
     * report number from somebody standing at the roadside produces no report at all, which is worse
     * than a thin one.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'vehicle_id'   => ['required', 'exists:vehicles,id'],
            'occurred_at'  => ['nullable', 'date'],
            'location'     => ['nullable', 'string', 'max:255'],
            'description'  => ['nullable', 'string', 'max:5000'],
            'odometer'     => ['nullable', 'integer', 'min:0', 'max:5000000'],
            'accident_type' => ['nullable', Rule::in(AccidentCase::ACCIDENT_TYPES)],
            // Optional, and it OVERRULES detection when given — the person reporting was there and
            // the database was not. @see AccidentContextResolver
            'responsible_party_type' => ['nullable', Rule::in(AccidentCase::RESPONSIBLE_PARTY_TYPES)],
            'driver_name'   => ['nullable', 'string', 'max:255'],
            'driver_phone'  => ['nullable', 'string', 'max:64'],
            'driver_user_id' => ['nullable', 'exists:users,id'],
            'responsible_party_note' => ['nullable', 'string', 'max:2000'],
            'other_party_involved'  => ['nullable', 'boolean'],
            'other_party_name'      => ['nullable', 'string', 'max:255'],
            'other_party_phone'     => ['nullable', 'string', 'max:64'],
            'other_party_plate'     => ['nullable', 'string', 'max:64'],
            'other_party_insurer'   => ['nullable', 'string', 'max:255'],
            'other_party_policy_no' => ['nullable', 'string', 'max:64'],
            'other_party_note'      => ['nullable', 'string', 'max:2000'],
            'drivable'        => ['nullable', 'boolean'],
            'towing_required' => ['nullable', 'boolean'],
            'safety_concerns' => ['nullable', 'string', 'max:2000'],
            'damage_items'                        => ['nullable', 'array', 'max:30'],
            'damage_items.*.area_label'           => ['required', 'string', 'max:255'],
            'damage_items.*.severity'             => ['nullable', Rule::in(AccidentDamageItem::SEVERITIES)],
            'damage_items.*.description'          => ['nullable', 'string', 'max:2000'],
            'damage_items.*.damage_catalog_id'    => ['nullable', 'exists:damage_catalog,id'],
            'damage_items.*.vehicle_location_id'  => ['nullable', 'exists:vehicle_locations,id'],
            'damage_items.*.estimated_cost'       => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'damage_items.*.requires_replacement' => ['nullable', 'boolean'],
        ]);

        $case = $this->cases->report($data, $request->user());
        $case->load(['vehicle:id,plate_no,make,model,year,odometer,operational_status', 'damageItems']);

        return ResponseHelper::SuccessResponse(new AccidentCaseResource($case), 'Accident case opened', 201);
    }

    /** Correct or expand the narrative. Diffed and audited field by field. */
    public function update(Request $request, AccidentCase $case)
    {
        $data = $request->validate([
            'occurred_at'   => ['nullable', 'date'],
            'location'      => ['nullable', 'string', 'max:255'],
            'description'   => ['nullable', 'string', 'max:5000'],
            'odometer'      => ['nullable', 'integer', 'min:0', 'max:5000000'],
            'accident_type' => ['nullable', Rule::in(AccidentCase::ACCIDENT_TYPES)],
            'other_party_involved'  => ['nullable', 'boolean'],
            'other_party_name'      => ['nullable', 'string', 'max:255'],
            'other_party_phone'     => ['nullable', 'string', 'max:64'],
            'other_party_plate'     => ['nullable', 'string', 'max:64'],
            'other_party_insurer'   => ['nullable', 'string', 'max:255'],
            'other_party_policy_no' => ['nullable', 'string', 'max:64'],
            'other_party_note'      => ['nullable', 'string', 'max:2000'],
            'drivable'        => ['nullable', 'boolean'],
            'towing_required' => ['nullable', 'boolean'],
            'safety_concerns' => ['nullable', 'string', 'max:2000'],
            'driver_name'     => ['nullable', 'string', 'max:255'],
            'driver_phone'    => ['nullable', 'string', 'max:64'],
            'responsible_party_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $case = $this->cases->updateDetails($case, $data, $request->user());

        return ResponseHelper::SuccessResponse(new AccidentCaseResource($case->load('vehicle')), 'Accident details updated');
    }

    // ── damage ─────────────────────────────────────────────────────────────────────────────────

    public function addDamage(Request $request, AccidentCase $case)
    {
        $data = $request->validate([
            'area_label'           => ['required', 'string', 'max:255'],
            'severity'             => ['nullable', Rule::in(AccidentDamageItem::SEVERITIES)],
            'description'          => ['nullable', 'string', 'max:2000'],
            'damage_catalog_id'    => ['nullable', 'exists:damage_catalog,id'],
            'vehicle_location_id'  => ['nullable', 'exists:vehicle_locations,id'],
            'estimated_cost'       => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'requires_replacement' => ['nullable', 'boolean'],
        ]);

        $item = $this->cases->addDamageItem($case, $data, $request->user());

        return ResponseHelper::SuccessResponse(new AccidentDamageItemResource($item), 'Damage recorded', 201);
    }

    public function removeDamage(Request $request, AccidentCase $case, AccidentDamageItem $item)
    {
        $this->cases->removeDamageItem($case, $item, $request->user());

        return ResponseHelper::SuccessResponse(null, 'Damage item removed');
    }

    /** Assessment complete — refused with nothing recorded. @see AccidentCaseService::completeAssessment() */
    public function assess(Request $request, AccidentCase $case)
    {
        $data = $request->validate([
            'drivable'        => ['nullable', 'boolean'],
            'towing_required' => ['nullable', 'boolean'],
            'safety_concerns' => ['nullable', 'string', 'max:2000'],
        ]);

        $case = $this->cases->completeAssessment($case, $data, $request->user());

        return ResponseHelper::SuccessResponse(new AccidentCaseResource($case->load(['vehicle', 'damageItems'])), 'Damage assessment completed');
    }

    // ── police ─────────────────────────────────────────────────────────────────────────────────

    public function recordPolice(Request $request, AccidentCase $case)
    {
        $data = $request->validate([
            'police_report_no'   => ['required', 'string', 'max:64'],
            'police_report_date' => ['nullable', 'date'],
            'police_authority'   => ['nullable', 'string', 'max:255'],
            'police_note'        => ['nullable', 'string', 'max:2000'],
        ]);

        $case = $this->cases->recordPoliceReport($case, $data, $request->user());

        return ResponseHelper::SuccessResponse(new AccidentCaseResource($case->load('vehicle')), 'Police report recorded');
    }

    /**
     * Verify it. A different permission from recording it, on purpose: an uploader verifying their
     * own upload is not a check, it is a formality with a name attached.
     */
    public function verifyPolice(Request $request, AccidentCase $case)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $case = $this->cases->verifyPoliceReport($case, $request->user(), $data['note'] ?? null);

        return ResponseHelper::SuccessResponse(new AccidentCaseResource($case->load('vehicle')), 'Police report verified');
    }

    /** Waive it. Reason mandatory — the service refuses an empty one and says why. */
    public function bypassPolice(Request $request, AccidentCase $case)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        $case = $this->cases->bypassPoliceReport($case, $data['reason'], $request->user());

        return ResponseHelper::SuccessResponse(new AccidentCaseResource($case->load('vehicle')), 'Police report requirement waived');
    }

    // ── liability ──────────────────────────────────────────────────────────────────────────────

    /**
     * Whose fault. `liability_source` is required and constrained — a verdict with no stated basis
     * is an opinion, and `pending` is excluded because "decide it is undecided" is not a decision.
     */
    public function setLiability(Request $request, AccidentCase $case)
    {
        $data = $request->validate([
            'liability_status' => ['required', Rule::in(array_values(array_diff(
                AccidentCase::LIABILITY_STATUSES, [AccidentCase::LIABILITY_PENDING],
            )))],
            'liability_share_pct' => ['nullable', 'integer', 'min:0', 'max:100'],
            'liability_source'    => ['required', Rule::in(AccidentCase::LIABILITY_SOURCES)],
            'liability_note'      => ['nullable', 'string', 'max:4000'],
        ]);

        $case = $this->cases->setLiability($case, $data, $request->user());

        return ResponseHelper::SuccessResponse(new AccidentCaseResource($case->load('vehicle')), 'Liability recorded');
    }

    // ── insurance ──────────────────────────────────────────────────────────────────────────────

    public function updateInsurance(Request $request, AccidentCase $case)
    {
        $data = $request->validate([
            'insurer_vendor_id'       => ['nullable', 'exists:vendors,id'],
            'insurer_name'            => ['nullable', 'string', 'max:255'],
            'policy_no'               => ['nullable', 'string', 'max:64'],
            'claim_no'                => ['nullable', 'string', 'max:64'],
            'claim_status'            => ['nullable', Rule::in(AccidentCase::CLAIM_STATUSES)],
            'claim_response_due_on'   => ['nullable', 'date'],
            'insurance_contact_name'  => ['nullable', 'string', 'max:255'],
            'insurance_contact_phone' => ['nullable', 'string', 'max:64'],
            'insurance_contact_email' => ['nullable', 'email', 'max:255'],
            'insurance_note'          => ['nullable', 'string', 'max:4000'],
        ]);

        $case = $this->cases->updateInsurance($case, $data, $request->user());

        return ResponseHelper::SuccessResponse(new AccidentCaseResource($case->load('vehicle')), 'Insurance updated');
    }

    // ── money ──────────────────────────────────────────────────────────────────────────────────

    /**
     * Write down one figure. Appends; a figure for a (phase, party) that already exists supersedes
     * its predecessor rather than replacing it. @see AccidentCaseService::recordFinancial()
     */
    public function recordFinancial(Request $request, AccidentCase $case)
    {
        $data = $request->validate([
            'phase'               => ['required', Rule::in(AccidentFinancialEntry::PHASES)],
            'party'               => ['required', Rule::in(AccidentFinancialEntry::PARTIES)],
            'amount'              => ['required', 'numeric', 'min:0', 'max:99999999'],
            'currency'            => ['nullable', 'string', 'size:3'],
            'note'                => ['nullable', 'string', 'max:2000'],
            'maintenance_id'      => ['nullable', 'exists:maintenances,id'],
            'vehicle_document_id' => ['nullable', 'exists:vehicle_documents,id'],
            'external_ref'        => ['nullable', 'string', 'max:120'],
        ]);

        $entry = $this->cases->recordFinancial($case, $data, $request->user());

        return ResponseHelper::SuccessResponse([
            'entry'      => new AccidentFinancialEntryResource($entry),
            // The whole picture back, so the page never has to re-derive the totals it just changed.
            'financials' => $case->fresh()->financialBreakdown(),
        ], 'Amount recorded', 201);
    }

    // ── repairs ────────────────────────────────────────────────────────────────────────────────

    /**
     * Send the car in, or attach a repair that already exists.
     *
     * One endpoint with two shapes because it is one decision — "this repair belongs to this
     * accident" — and which of the two applies depends only on whether the ticket has been raised
     * yet. The workshop's own refusals (a car already in the pipeline) propagate untouched.
     */
    public function repair(Request $request, AccidentCase $case)
    {
        $data = $request->validate([
            'maintenance_id' => ['nullable', 'exists:maintenances,id'],
            'note'           => ['nullable', 'string', 'max:2000'],
            'transport'      => ['nullable', 'string', 'max:32'],
        ]);

        $ticket = ! empty($data['maintenance_id'])
            ? $this->cases->linkRepair($case, Maintenance::findOrFail($data['maintenance_id']), $request->user())
            : $this->cases->raiseRepair($case, $data, $request->user());

        return ResponseHelper::SuccessResponse([
            'maintenance_id'  => $ticket->id,
            'workflow_status' => $ticket->workflow_status,
            'url'             => '/maintenance-workflow/' . $ticket->id,
        ], 'Repair linked to the accident case', 201);
    }

    // ── the ladder ─────────────────────────────────────────────────────────────────────────────

    public function advance(Request $request, AccidentCase $case)
    {
        $data = $request->validate([
            'stage' => ['required', Rule::in(AccidentCase::STAGES)],
            'note'  => ['nullable', 'string', 'max:2000'],
        ]);

        $case = $this->cases->advance($case, $data['stage'], $request->user(), $data['note'] ?? null);

        return ResponseHelper::SuccessResponse(new AccidentCaseResource($case->load('vehicle')), 'Accident case updated');
    }

    public function close(Request $request, AccidentCase $case)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:4000']]);

        $case = $this->cases->close($case, $request->user(), $data['note'] ?? null);

        return ResponseHelper::SuccessResponse(new AccidentCaseResource($case->load('vehicle')), 'Accident case closed');
    }

    public function reopen(Request $request, AccidentCase $case)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        $case = $this->cases->reopen($case, $data['reason'], $request->user());

        return ResponseHelper::SuccessResponse(new AccidentCaseResource($case->load('vehicle')), 'Accident case reopened');
    }

    // ── reads ──────────────────────────────────────────────────────────────────────────────────

    /**
     * THE CASE'S OWN TIMELINE — the same append-only rows the car's history is built from, filtered
     * to this case, oldest first (a case reads forwards; a fleet feed reads backwards).
     *
     * Each event carries a `link` when there is something to open: the police report scan, the
     * insurer's letter, the repair ticket. That is what turns "Police report uploaded" from a note
     * into a door, which was the whole requirement.
     */
    public function timeline(AccidentCase $case)
    {
        $rows = $case->timeline()->with('actor:id,name')->get();

        return ResponseHelper::SuccessResponse([
            'reference' => $case->reference,
            'events'    => $rows->map(function ($e) use ($case) {
                $meta = $e->meta ?? [];

                return [
                    'id'          => $e->id,
                    'event_type'  => $e->event_type,
                    'description' => $e->description,
                    'actor_name'  => $e->actor?->name ?? 'System',
                    'occurred_at' => $e->occurred_at?->toIso8601String(),
                    'meta'        => $meta,
                    // The door. Derived here rather than in the client so one authority decides what
                    // an event opens onto.
                    'link'       => $meta['document_url']
                        ?? (isset($meta['maintenance_id']) ? '/maintenance-workflow/' . $meta['maintenance_id'] : null),
                    'link_label' => isset($meta['document_url'])
                        ? ('Open ' . ($meta['kind_label'] ?? 'document'))
                        : (isset($meta['maintenance_id']) ? 'Open repair ticket' : null),
                    'contract_link' => $case->contract_id ? '/contracts/' . $case->contract_id : null,
                ];
            })->values(),
        ], 'Accident timeline retrieved');
    }

    /** Every accident this car has ever had — the Accidents tab on the vehicle profile. */
    public function forVehicle(Vehicle $vehicle)
    {
        $cases = AccidentCase::forVehicle($vehicle->id)
            ->withCount(['damageItems', 'repairs'])
            ->orderByDesc('occurred_at')->orderByDesc('id')->get();

        return ResponseHelper::SuccessResponse([
            'cases' => AccidentCaseResource::collection($cases),
            // The one thing the vehicle page needs a straight answer to: may this car be let?
            'restricted' => $cases->contains(fn ($c) => $c->restrictsRental()),
        ], 'Vehicle accidents retrieved');
    }

    /**
     * WHAT THE INTAKE FORM NEEDS — the vocabularies, plus a preview of who currently has the car.
     *
     * The preview is READ-ONLY and changes nothing: it is there so the person reporting sees "this
     * car is on hire to Mr Khan on contract 41207" while they are still typing, rather than
     * discovering it after they submit. A gate that only speaks at submit time teaches people to
     * resent it. What gets FROZEN onto the case is resolved again at report() time against the
     * accident's own timestamp — this is a courtesy, not the record.
     */
    public function intakeOptions(Request $request, AccidentReportService $report)
    {
        $request->validate([
            'vehicle_id'  => ['nullable', 'exists:vehicles,id'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $preview = null;
        if ($request->filled('vehicle_id')) {
            $vehicle = Vehicle::find($request->integer('vehicle_id'));
            $at = $request->filled('occurred_at') ? Carbon::parse($request->string('occurred_at')) : Carbon::now();
            $resolved = $this->context->resolve($vehicle, $at);
            $preview = [
                'responsible_party_type' => $resolved['responsible_party_type'],
                'detected'               => $resolved['context_detected'],
                'customer_name'          => $resolved['customer_name_snapshot'],
                'customer_phone'         => $resolved['customer_phone_snapshot'],
                'contract_no'            => $resolved['contract_no_snapshot'],
                'contract_id'            => $resolved['contract_id'],
                'contract_state'         => $resolved['contract_state_snapshot'],
                'out_date'               => optional($resolved['contract_out_date_snapshot'])->toDateString(),
                'in_date'                => optional($resolved['contract_in_date_snapshot'])->toDateString(),
            ];
        }

        return ResponseHelper::SuccessResponse([
            'accident_types'  => AccidentCase::ACCIDENT_TYPES,
            'party_types'     => AccidentCase::RESPONSIBLE_PARTY_TYPES,
            'severities'      => AccidentDamageItem::SEVERITIES,
            'liability'       => AccidentCase::LIABILITY_STATUSES,
            'liability_sources' => AccidentCase::LIABILITY_SOURCES,
            'claim_statuses'  => AccidentCase::CLAIM_STATUSES,
            'phases'          => AccidentFinancialEntry::PHASES,
            'parties'         => AccidentFinancialEntry::PARTIES,
            'document_kinds'  => collect(\App\Models\VehicleDocument::ACCIDENT_KINDS)
                ->mapWithKeys(fn ($k) => [$k => \App\Models\VehicleDocument::KINDS[$k] ?? $k])->all(),
            // The damage vocabulary the rest of the app already uses — reused, never re-invented.
            'damage_catalog'  => DamageCatalog::where('is_active', true)
                ->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name', 'name_ar', 'category_key', 'area_key'])->all(),
            'locations'       => VehicleLocation::query()->orderBy('name')->get(['id', 'name'])->all(),
            'context_preview' => $preview,
        ], 'Accident intake options retrieved');
    }

    /** The dashboard's tiles and queues. @see AccidentReportService for why the order is what it is. */
    public function dashboard(AccidentReportService $report)
    {
        return ResponseHelper::SuccessResponse($report->dashboard(), 'Accident dashboard retrieved');
    }
}
