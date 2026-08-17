<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleGarageLocation;
use App\Models\Vendor;
use Carbon\Carbon;

/**
 * IN THE GARAGE — the one-line-per-car answer to "which of our cars is at a garage right now, and
 * which garage is it at".
 *
 * This is the board the Controllers were keeping by hand in a WhatsApp list: car on the left,
 * garage on the right. Two things put a car on it, and they are NOT the same fact:
 *
 *   1. A WORKFLOW TICKET at a garage (under repair / with the garage / signed off awaiting pickup,
 *      plus the ones on the way). The Supervisor picked the garage when the car was dispatched, so
 *      the system already KNOWS where the car is — `maintenances.vendor_id`. Nothing to type.
 *
 *   2. An OPEN OFFICEMANAGER MAINTENANCE CONTRACT (type 'U') — the same fact DiagnosticGateService
 *      calls a shop stay, and the only thing that parks a car for the test countdown. OM records
 *      that the car went out for maintenance but has NO field for which garage.
 *
 * For those OM cars the garage comes from the maintenance sheet's N-LOCATION TAB ("Car | Garage"),
 * mirrored by `import:garage-locations` — the list the Controllers already keep by hand, read
 * rather than retyped. Where the tab has nothing either, recordGarage() lets a person write it onto
 * the contract with their name and the time. The order is: the ticket, then a person's entry here,
 * then the sheet.
 *
 * WE NEVER GUESS THE GARAGE. A car whose last workshop-log trip in July was to 7 CYLINDER is not
 * thereby at 7 CYLINDER today; only the current N-Location row counts. A car nothing names reads
 * "Not recorded yet" and asks to be filled in. Every row carries `garage.origin` and `stay.source`
 * so the page can say where each half of the line came from, plus `garage.disagrees` when the sheet
 * says something different from what is shown — a conflict is surfaced, never quietly resolved.
 *
 * Evidence class: F (Fact) — Produces E-in-garage. Consumes maintenances (F), contracts (F),
 * vehicle_garage_locations (F), vendors (F). No judgement, no inference: each cell is either
 * something the system recorded, something the sheet says, or something a named person recorded.
 */
class InGarageService
{
    /**
     * Ticket states where the car is PHYSICALLY standing at a garage.
     *
     * Deliberately not Maintenance::WF_AT_GARAGE — that constant includes WF_CLOSED (it exists to
     * answer "may the Fix-All gate still run", which stays true after the car leaves). A closed
     * ticket's car is back at base and has no business on this board. ready_for_reinspection is out
     * for the same reason: by then the car has driven back to our park and is waiting on the
     * Inspector's QA sign-off, not on a garage.
     */
    public const AT_GARAGE_STATES = [
        Maintenance::WF_UNDER_REPAIR,
        Maintenance::WF_REPAIR_REVIEW,
        Maintenance::WF_READY_FOR_PICKUP,
    ];

    /** On the way to the garage — shown on the board, but marked as not there yet. */
    public const EN_ROUTE_STATES = [
        Maintenance::WF_IN_TRANSIT,
    ];

    /** Vendor types that can be picked as a garage. */
    public const GARAGE_VENDOR_TYPES = ['garage', 'service_center'];

    /**
     * The whole board: one row per car, newest arrivals last (longest in the shop first).
     *
     * @return array{rows:array<int,array<string,mixed>>,summary:array<string,mixed>,garages:array<int,array<string,mixed>>}
     */
    public function board(): array
    {
        $tickets  = $this->ticketStays();
        $visits   = $this->contractStays();
        $sheet    = $this->sheetLocations();

        $rows = [];
        foreach ($tickets as $vehicleId => $ticket) {
            $rows[$vehicleId] = $this->rowFromTicket($ticket, $visits[$vehicleId] ?? null, $sheet[$vehicleId] ?? null);
        }
        foreach ($visits as $vehicleId => $contract) {
            if (isset($rows[$vehicleId])) {
                continue;   // the ticket already names the garage — it is the operational truth
            }
            $rows[$vehicleId] = $this->rowFromContract($contract, $sheet[$vehicleId] ?? null);
        }

        $rows = array_values($rows);

        // Longest at the garage first — that is the car worth asking about. Unknown days sink to
        // the bottom rather than pretending to be zero.
        usort($rows, function ($a, $b) {
            $da = $a['stay']['days'] ?? -1;
            $db = $b['stay']['days'] ?? -1;

            return ($db <=> $da) ?: strcmp((string) $a['plate_no'], (string) $b['plate_no']);
        });

        return [
            'rows'       => $rows,
            'summary'    => $this->summarise($rows),
            'garages'    => $this->garageOptions(),
            // Sheet rows the board could NOT put on a line above — reported, never swallowed.
            'sheet_only' => $this->sheetOnly($rows, $sheet),
            'sheet'      => $this->sheetFreshness(),
        ];
    }

    /**
     * Record (or clear) which garage the car on this maintenance visit is at.
     *
     * Only an OPEN type-'U' contract can carry a placement: a closed visit is history and a rental
     * contract has nothing to do with garages. Passing a null vendor clears the entry — an honest
     * "we don't know" beats a wrong name, and the row goes back to asking.
     */
    public function recordGarage(Contract $contract, ?int $vendorId, User $actor): Contract
    {
        if ($contract->contract_type !== 'U') {
            abort(422, 'Only a maintenance contract can carry a garage.');
        }
        if ($contract->state !== 'open' || $contract->in_date !== null) {
            abort(422, 'That maintenance visit is already closed — its car is not at a garage.');
        }

        $contract->forceFill([
            'garage_vendor_id'   => $vendorId,
            'garage_recorded_by' => $vendorId ? $actor->id : null,
            'garage_recorded_at' => $vendorId ? Carbon::now() : null,
        ])->save();

        return $contract->fresh(['garageVendor', 'garageRecorder', 'vehicle']);
    }

    /** The row a single contract now reads as — what the page swaps in after a save. */
    public function rowForContract(Contract $contract): ?array
    {
        $contract->loadMissing(['vehicle', 'garageVendor', 'garageRecorder']);
        if (! $contract->vehicle) {
            return null;
        }

        $vehicleId = (int) $contract->vehicle_id;
        $ticket = $this->ticketStays([$vehicleId])[$vehicleId] ?? null;
        $sheet  = $this->sheetLocations([$vehicleId])[$vehicleId] ?? null;

        return $ticket
            ? $this->rowFromTicket($ticket, $contract, $sheet)
            : $this->rowFromContract($contract, $sheet);
    }

    // ── Sources ──────────────────────────────────────────────────────────────

    /**
     * The newest workflow ticket per car that has the car at (or heading to) a garage.
     *
     * LIVE VIEW — the Maintenance model's SoftDeletes scope applies, so a deleted ticket takes its
     * car off the board.
     *
     * @param  int[]|null  $vehicleIds  limit to these cars (null = the whole fleet)
     * @return array<int,Maintenance>
     */
    private function ticketStays(?array $vehicleIds = null): array
    {
        if ($vehicleIds === []) {
            return [];
        }

        $tickets = Maintenance::query()
            ->with(['vendor:id,name', 'vehicle'])
            ->whereNotNull('vehicle_id')
            ->whereIn('workflow_status', array_merge(self::AT_GARAGE_STATES, self::EN_ROUTE_STATES))
            ->when($vehicleIds !== null, fn ($q) => $q->whereIn('vehicle_id', $vehicleIds))
            ->orderBy('id')   // ascending → the last write per car wins, as everywhere else
            ->get();

        $byVehicle = [];
        foreach ($tickets as $t) {
            $byVehicle[(int) $t->vehicle_id] = $t;
        }

        return $byVehicle;
    }

    /**
     * The open OfficeManager maintenance visit per car — the same fact
     * DiagnosticGateService::shopStay() calls a shop stay, read here in one query for the whole
     * fleet instead of per car.
     *
     * @return array<int,Contract>
     */
    private function contractStays(): array
    {
        $contracts = Contract::query()
            ->with(['vehicle', 'garageVendor:id,name', 'garageRecorder:id,name'])
            ->where('contract_type', 'U')
            ->currentlyOpen()
            ->whereNotNull('vehicle_id')
            ->orderBy('id')
            ->get();

        $byVehicle = [];
        foreach ($contracts as $c) {
            $byVehicle[(int) $c->vehicle_id] = $c;   // newest open visit wins
        }

        return $byVehicle;
    }

    /**
     * The maintenance sheet's N-Location tab, mirrored into `vehicle_garage_locations` by
     * `import:garage-locations` — the Controllers' own "Car | Garage" list, and the only place the
     * garage is written down for a car OM sent out on a maintenance contract.
     *
     * Rows whose car could not be resolved carry a null vehicle_id; they are excluded here and
     * surfaced separately by sheetOnly(), so an unplaceable plate is a visible question.
     *
     * @param  int[]|null  $vehicleIds  limit to these cars (null = every mirrored row)
     * @return array<int,VehicleGarageLocation>
     */
    private function sheetLocations(?array $vehicleIds = null): array
    {
        if ($vehicleIds === []) {
            return [];
        }

        $locations = VehicleGarageLocation::query()
            ->with('vendor:id,name')
            ->whereNotNull('vehicle_id')
            ->when($vehicleIds !== null, fn ($q) => $q->whereIn('vehicle_id', $vehicleIds))
            ->orderBy('sheet_row')
            ->get();

        $byVehicle = [];
        foreach ($locations as $l) {
            // A car listed twice on the tab keeps its FIRST row — the tab is a list of cars, not of
            // visits, so a duplicate is a sheet mistake rather than two truths.
            $byVehicle[(int) $l->vehicle_id] ??= $l;
        }

        return $byVehicle;
    }

    /**
     * Sheet rows that did not become a line on the board: either the plate matched no car, or the
     * car it names is not in a shop as far as OM and the workflow are concerned.
     *
     * These are the two ways the hand-kept list and the system can drift apart, and naming them is
     * the point — a car the sheet still shows at a garage after it came back is exactly the stale
     * entry that made the old list untrustworthy.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<int,VehicleGarageLocation>  $sheet
     * @return array<int,array<string,mixed>>
     */
    private function sheetOnly(array $rows, array $sheet): array
    {
        $onBoard = array_flip(array_map(fn ($r) => (int) $r['vehicle_id'], $rows));

        $out = [];
        foreach ($sheet as $vehicleId => $l) {
            if (isset($onBoard[$vehicleId])) {
                continue;
            }
            $out[] = [
                'car_label'   => $l->car_label,
                'garage_name' => $l->garage_name,
                'sheet_row'   => $l->sheet_row,
                'vehicle_id'  => $vehicleId,
                'reason'      => 'not_in_shop',
            ];
        }

        foreach (VehicleGarageLocation::whereNull('vehicle_id')->orderBy('sheet_row')->get() as $l) {
            $out[] = [
                'car_label'   => $l->car_label,
                'garage_name' => $l->garage_name,
                'sheet_row'   => $l->sheet_row,
                'vehicle_id'  => null,
                'reason'      => 'car_not_matched',
            ];
        }

        return $out;
    }

    /**
     * When the tab was last read, and how many rows it held. Shown on the page because a mirror is
     * only as good as its last import — a board that cannot say how old its sheet data is would be
     * the same black box the hand-kept list was.
     */
    private function sheetFreshness(): array
    {
        $rows = VehicleGarageLocation::count();

        return [
            'rows'        => $rows,
            'imported_at' => optional(VehicleGarageLocation::max('imported_at'))
                ? Carbon::parse(VehicleGarageLocation::max('imported_at'))->toIso8601String()
                : null,
        ];
    }

    /**
     * The sheet's name for this car when it CONTRADICTS the one being shown — null when they agree,
     * when the sheet has nothing, or when the sheet IS the source being shown.
     */
    private function disagreement(?string $shown, ?VehicleGarageLocation $sheet, string $other): ?array
    {
        if (! $shown || ! $sheet || ! $sheet->garage_name) {
            return null;
        }
        if ($this->sameGarage($shown, $sheet->garage_name)) {
            return null;
        }

        return ['origin' => $other, 'name' => $sheet->garage_name];
    }

    /** Two garage names are the same garage if they normalise the same — the importer's rule. */
    private function sameGarage(string $a, string $b): bool
    {
        $norm = fn ($s) => strtolower(trim(preg_replace('/\s+/u', ' ', $s)));

        return $norm($a) === $norm($b);
    }

    // ── Row builders ─────────────────────────────────────────────────────────

    /** A car the workflow dispatched: the ticket names the garage, so nobody has to type it. */
    private function rowFromTicket(Maintenance $ticket, ?Contract $contract, ?VehicleGarageLocation $sheet): array
    {
        $name = $ticket->vendor?->name ?: (trim((string) $ticket->garage) ?: null);
        $enRoute = in_array($ticket->workflow_status, self::EN_ROUTE_STATES, true);
        $since = $ticket->repair_started_at ?? $ticket->dispatched_at ?? $ticket->last_state_change_at;

        // The sheet still gets read for these cars — not to override the ticket, but so a
        // disagreement is visible instead of silently resolved in the ticket's favour.
        $fallbackToSheet = ! $name && $sheet;

        return $this->row($ticket->vehicle, [
            'garage' => [
                'vendor_id'   => $fallbackToSheet ? $sheet->vendor_id : ($ticket->vendor_id ? (int) $ticket->vendor_id : null),
                'name'        => $name ?: $sheet?->garage_name,
                // 'workflow' = the Supervisor picked it at dispatch; 'garage_log' = only the free-text
                // name on the ticket survives, with no vendor behind it; 'sheet' = the N-Location tab.
                'origin'      => $name ? ($ticket->vendor_id ? 'workflow' : 'garage_log') : ($sheet ? 'sheet' : null),
                'recorded_by' => null,
                'recorded_at' => null,
                'disagrees'   => $this->disagreement($name, $sheet, 'sheet'),
            ],
            'stay' => [
                'source'          => 'workflow_ticket',
                'ticket_id'       => (int) $ticket->id,
                'workflow_status' => $ticket->workflow_status,
                'en_route'        => $enRoute,
                // A car dispatched by the workflow usually ALSO has an OM contract open; naming it
                // lets a Controller cross-check the two without leaving the page.
                'contract_id'     => $contract?->id,
                'contract_no'     => $contract?->contract_no,
                'since'           => $since ? Carbon::parse($since)->toDateString() : null,
                'days'            => $this->daysSince($since),
            ],
            // The ticket owns the garage — changing it means moving the car, which is a workflow
            // transfer, not a note on a board.
            'editable' => false,
        ]);
    }

    /**
     * A car OM sent out on a maintenance contract. OM names no garage, so the answer comes from the
     * sheet's N-Location tab — the list the Controllers already keep — and an in-app entry wins over
     * it, because someone typing it here is the newer, deliberate statement about the same car.
     */
    private function rowFromContract(Contract $contract, ?VehicleGarageLocation $sheet): array
    {
        $recorded = $contract->garageVendor?->name;
        $name     = $recorded ?: $sheet?->garage_name;

        return $this->row($contract->vehicle, [
            'garage' => [
                'vendor_id'   => $recorded
                    ? (int) $contract->garage_vendor_id
                    : ($sheet?->vendor_id ? (int) $sheet->vendor_id : null),
                'name'        => $name,
                'origin'      => $recorded ? 'recorded' : ($sheet ? 'sheet' : null),
                'recorded_by' => $recorded ? $contract->garageRecorder?->name : null,
                'recorded_at' => $recorded ? optional($contract->garage_recorded_at)->toIso8601String() : null,
                'disagrees'   => $this->disagreement($recorded, $sheet, 'sheet'),
            ],
            'stay' => [
                'source'          => 'om_contract',
                'ticket_id'       => null,
                'workflow_status' => null,
                'en_route'        => false,
                'contract_id'     => (int) $contract->id,
                'contract_no'     => $contract->contract_no,
                'since'           => optional($contract->out_date)->toDateString(),
                'days'            => $this->daysSince($contract->out_date),
            ],
            'editable' => true,
        ]);
    }

    /** The shared half of a row: which car this line is about. */
    private function row(?Vehicle $vehicle, array $parts): array
    {
        return [
            'vehicle_id'  => $vehicle?->id,
            'plate_no'    => $vehicle?->plate_no,
            // The plate LETTER is deliberately absent. `vehicles.plate_code` is an OfficeManager
            // code id, and resolving it through plate_codes disagrees with the plates people
            // actually read off the cars — so the board prints the number it is sure of rather
            // than a letter it would sometimes get wrong.
            'make'        => $vehicle?->make,
            'model'       => $vehicle?->model,
            'year'        => $vehicle?->year,
            'color'       => $vehicle?->color,
            // Cars that are suspended or up for sale still sit in garages — they are shown, and
            // flagged, rather than dropped, so the board matches what is physically out there.
            'in_active_fleet' => $vehicle
                ? in_array($vehicle->status, Vehicle::ACTIVE_STATUSES, true) && ! $vehicle->for_sale
                : false,
            'vehicle_status' => $vehicle?->status,
            'for_sale'       => (bool) ($vehicle?->for_sale),
        ] + $parts;
    }

    /** Whole days from a date/timestamp to today. Null in, null out — never a bare 0. */
    private function daysSince($at): ?int
    {
        if (! $at) {
            return null;
        }

        return (int) Carbon::parse($at)->startOfDay()->diffInDays(Carbon::now()->startOfDay());
    }

    /**
     * The headline counts. `garage_missing` is the one that matters: it is exactly how many cars we
     * know are in a shop but cannot say which shop.
     *
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function summarise(array $rows): array
    {
        $byGarage = [];
        $missing = 0;
        $enRoute = 0;
        foreach ($rows as $r) {
            if ($r['stay']['en_route']) {
                $enRoute++;
            }
            $name = $r['garage']['name'];
            if (! $name) {
                $missing++;
                continue;
            }
            $byGarage[$name] = ($byGarage[$name] ?? 0) + 1;
        }
        arsort($byGarage);

        return [
            'total'          => count($rows),
            'garage_known'   => count($rows) - $missing,
            'garage_missing' => $missing,
            'en_route'       => $enRoute,
            'from_sheet'     => count(array_filter($rows, fn ($r) => $r['garage']['origin'] === 'sheet')),
            'disagreements'  => count(array_filter($rows, fn ($r) => $r['garage']['disagrees'] !== null)),
            'from_workflow'  => count(array_filter($rows, fn ($r) => $r['stay']['source'] === 'workflow_ticket')),
            'from_om'        => count(array_filter($rows, fn ($r) => $r['stay']['source'] === 'om_contract')),
            'by_garage'      => array_map(fn ($n, $c) => ['name' => $n, 'cars' => $c], array_keys($byGarage), $byGarage),
        ];
    }

    /**
     * The garages a person may pick from — our vendor list, so the board can never invent a garage
     * that the rest of the system (scorecards, invoices, dispatch) has never heard of.
     *
     * @return array<int,array<string,mixed>>
     */
    private function garageOptions(): array
    {
        return Vendor::query()
            ->whereIn('type', self::GARAGE_VENDOR_TYPES)
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'type'])
            ->map(fn ($v) => ['id' => (int) $v->id, 'name' => $v->name, 'type' => $v->type])
            ->all();
    }
}
