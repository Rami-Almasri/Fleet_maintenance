<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Contract;
use App\Models\ContractMileageReading;
use App\Services\OilChangeProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The ops surface for the mid-rental oil chase.
 *
 * `index()` is the queue Leen and Marwa work from — every car currently out, with where its
 * odometer has probably reached and whether we are still inside the limit. `reading()` is the
 * one write: the number the customer gave over the phone. Storing it re-anchors the projection
 * immediately, which is also what re-arms the alert for the next round.
 *
 * Everything here consumes OilChangeProjectionService; none of it recomputes the rule locally.
 */
class OilProjectionController extends Controller
{
    public function __construct(private OilChangeProjectionService $projection) {}

    /**
     * Every currently-open rental with its projection, most urgent first.
     *
     * `?status=chase_due` narrows to the cars actually needing a call — the rest are shown so the
     * page can be read as "here is the whole fleet position", not just a list of problems.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $only = (string) $request->query('status', '');

            $rows = Contract::query()
                ->currentlyOpen()
                ->where('contract_type', 'C')
                ->whereNotNull('vehicle_id')
                ->with(['vehicle:id,code,make,model,plate_no,last_service_odometer,service_interval_km,odometer', 'customer:id,name_en'])
                ->get()
                ->map(function (Contract $c) {
                    $p = $this->projection->project($c);

                    return [
                        'contract_id'   => $c->id,
                        'contract_no'   => $c->contract_no,
                        'customer'      => $c->customer?->name_en,
                        'vehicle_id'    => $c->vehicle?->id,
                        'plate'         => $c->vehicle?->plate_no,
                        'car'           => trim(($c->vehicle?->make ?? '') . ' ' . ($c->vehicle?->model ?? '')),
                        'out_date'      => $c->out_date?->toDateString(),
                        'projection'    => $p,
                    ];
                })
                ->when($only !== '', fn ($rows) => $rows->where('projection.status', $only))
                // Chases first, then whoever is closest to their limit; no-data cars last since
                // there is nothing to act on until someone captures a handover reading.
                ->sortBy(fn ($r) => match ($r['projection']['status']) {
                    'chase_due' => 0,
                    'ok'        => 1,
                    default     => 2,
                })
                ->values();

            $summary = [
                'chase_due' => $rows->where('projection.status', 'chase_due')->count(),
                'ok'        => $rows->where('projection.status', 'ok')->count(),
                'no_data'   => $rows->where('projection.status', 'no_data')->count(),
                'total'     => $rows->count(),
            ];

            return ResponseHelper::SuccessResponse([
                'contracts' => $rows,
                'summary'   => $summary,
                'model'     => [
                    'rate_km_per_day' => $this->projection->rate(),
                    'grace_km'        => $this->projection->grace(),
                    // Traceability: the page must be able to say where every number came from.
                    'basis'           => 'expected = anchor odometer + days since anchor × rate;'
                                       . ' limit = last service odometer + interval (Oil Change sheet) + grace',
                ],
            ]);
        } catch (Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** One contract's projection plus the readings behind it (the audit trail for the number). */
    public function show(Contract $contract): JsonResponse
    {
        try {
            return ResponseHelper::SuccessResponse([
                'contract_id' => $contract->id,
                'projection'  => $this->projection->project($contract),
                'readings'    => $contract->mileageReadings()
                    ->orderByDesc('reported_on')->orderByDesc('id')
                    ->get(['id', 'odometer', 'reported_on', 'source', 'reported_by', 'note', 'recorded_by', 'created_at']),
            ]);
        } catch (Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Record the mileage the customer reported. This becomes the new projection anchor.
     *
     * Guards worth their weight: the reading may not run backwards past the anchor it replaces
     * (a customer misreading 41,000 as 14,000 would otherwise push the next chase months out and
     * silently park the car's oil life), and it may not be dated before the car went out or in
     * the future. A number that fails these is a data-entry problem, not a mileage fact.
     */
    public function reading(Request $request, Contract $contract): JsonResponse
    {
        try {
            $data = $request->validate([
                'odometer'    => ['required', 'integer', 'min:2', 'max:9999999'],
                'reported_on' => ['nullable', 'date'],
                'reported_by' => ['nullable', 'string', 'max:120'],
                'source'      => ['nullable', 'in:' . ContractMileageReading::SOURCE_CUSTOMER . ',' . ContractMileageReading::SOURCE_STAFF],
                'note'        => ['nullable', 'string', 'max:1000'],
            ]);

            if (! $contract->vehicle_id) {
                throw ValidationException::withMessages(['odometer' => 'This contract has no vehicle.']);
            }

            $reportedOn = isset($data['reported_on'])
                ? Carbon::parse($data['reported_on'])->startOfDay()
                : Carbon::now()->startOfDay();

            if ($reportedOn->isFuture()) {
                throw ValidationException::withMessages(['reported_on' => 'A mileage reading cannot be dated in the future.']);
            }
            if ($contract->out_date && $reportedOn->lt(Carbon::parse($contract->out_date)->startOfDay())) {
                throw ValidationException::withMessages(['reported_on' => 'The reading predates the day the car went out.']);
            }

            $anchor = $this->projection->anchor($contract);
            if ($anchor && $data['odometer'] < $anchor['odometer']) {
                throw ValidationException::withMessages([
                    'odometer' => 'Mileage cannot go backwards — the last known reading is '
                                . number_format($anchor['odometer']) . ' km.',
                ]);
            }

            $reading = ContractMileageReading::create([
                'contract_id' => $contract->id,
                'vehicle_id'  => $contract->vehicle_id,
                'odometer'    => $data['odometer'],
                'reported_on' => $reportedOn->toDateString(),
                'recorded_by' => $request->user()?->id,
                'reported_by' => $data['reported_by'] ?? null,
                'source'      => $data['source'] ?? ContractMileageReading::SOURCE_CUSTOMER,
                'note'        => $data['note'] ?? null,
            ]);

            // Recalculate straight away: the caller sees the new verdict and the next chase date
            // in the same response, which is the whole point of entering the number.
            return ResponseHelper::SuccessResponse([
                'reading'    => $reading,
                'projection' => $this->projection->project($contract->fresh()),
            ]);
        } catch (Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
