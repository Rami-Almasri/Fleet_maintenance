<?php

namespace App\Services\Reports;

use App\Models\Contract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "WHICH CONTRACT WAS THE CAR OUT ON WHEN THIS HAPPENED?"
 *
 * A repeat fault is only actionable when the reader can name the trips it happened on, and on this
 * fleet a workshop trip is a Type-U maintenance contract. So every occurrence the vehicle report
 * prints carries its contract number, and a manager can take the paperwork off the page.
 *
 * IT IS RESOLVED BY DATE, NOT BY THE FOREIGN KEY, and that is not a shortcut: `maintenances.contract_id`
 * is populated on 6 of 28,594 rows, so joining on it would leave the column blank on effectively every
 * visit and read as "no contract" — a claim the record does not make. The window is the contract's own
 * out/in dates, with a two-day buffer before departure because the log frequently dates a visit from
 * the day the fault was reported rather than the day the car left. The buffer matches
 * VehicleFaultRecurrenceService, which answers the same question for the recurrence engine; the two
 * must never disagree about which trip a date belongs to.
 *
 * A NULL IS NOT A RENTAL AND NOT AN ERROR. Type-C contracts are never consulted here, so "no contract"
 * means only that no maintenance contract covers the day — which is the true state of a visit logged
 * outside the contract system, and is printed as "not on a contract" rather than as a blank.
 */
class VisitContractResolver
{
    /** Days before out_date that still count as this contract's trip. @see the class note. */
    private const BUFFER_DAYS = 2;

    /** @var Collection<int, object> */
    private Collection $contracts;

    private function __construct(Collection $contracts)
    {
        $this->contracts = $contracts;
    }

    /** Load one car's maintenance contracts, oldest first. One query, then every lookup is in memory. */
    public static function forVehicle(int $vehicleId): self
    {
        return new self(
            Contract::query()
                ->where('contract_type', 'U')
                ->where('vehicle_id', $vehicleId)
                ->whereNotNull('out_date')
                ->orderBy('out_date')
                ->get(['id', 'contract_no', 'out_date', 'in_date'])
        );
    }

    /** An empty resolver — for callers that have no vehicle context and must still answer "none". */
    public static function none(): self
    {
        return new self(collect());
    }

    /**
     * The contract covering $date — the LATEST one whose window contains it, because a car that goes
     * back out on a new contract is on the new one — or null when none does.
     *
     * @return array{id:int, no:?string, out_date:?string, in_date:?string}|null
     */
    public function for(?string $date): ?array
    {
        if (! $date || $this->contracts->isEmpty()) {
            return null;
        }

        $day  = Carbon::parse($date);
        $best = null;

        foreach ($this->contracts as $c) {
            if ($day->lt(Carbon::parse($c->out_date)->subDays(self::BUFFER_DAYS))) {
                continue;                                                   // before this trip began
            }
            if ($c->in_date && $day->gt(Carbon::parse($c->in_date)->endOfDay())) {
                continue;                                                   // after this closed trip ended
            }
            if ($best === null || $c->out_date > $best->out_date) {
                $best = $c;
            }
        }

        return $best ? $this->describe($best) : null;
    }

    /** Every maintenance contract this car has, for the report's contract ledger. */
    public function all(): array
    {
        return $this->contracts->map(fn ($c) => $this->describe($c))->values()->all();
    }

    /** @return array{id:int, no:?string, out_date:?string, in_date:?string} */
    private function describe(object $c): array
    {
        return [
            'id'        => (int) $c->id,
            // The number a human quotes. Falls back to the id so a link is never label-less.
            'no'        => $c->contract_no !== null && $c->contract_no !== '' ? (string) $c->contract_no : null,
            'out_date'  => $c->out_date ? Carbon::parse($c->out_date)->toDateString() : null,
            'in_date'   => $c->in_date ? Carbon::parse($c->in_date)->toDateString() : null,
        ];
    }
}
