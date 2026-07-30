<?php

namespace App\Services\RepairIntelligence\Query;

/**
 * The intelligence platform's public API for historical knowledge.
 *
 * ARCHITECTURAL RULE — capabilities ask questions; they never compose SQL.
 *
 *     Historical projections  →  THIS LAYER  →  capabilities  →  Decision Engine
 *
 * A capability that reaches past this interface into a model, a table or a query builder has become
 * a data-access layer, and the moment five of them have done so the storage model is frozen: no
 * table can be renamed, no aggregate materialised and no cache introduced without touching every
 * capability that learned to read around it. Everything below this line is an implementation
 * detail — one table or ten, live SQL or a materialised view, a cache, or an analytics service that
 * does not exist yet.
 *
 * IT IS ALSO A PROVENANCE BOUNDARY. A capability must never know where history came from. Today the
 * answers come from the `maintenance_signatures` projection over eleven years of imported tickets.
 * Tomorrow they may come from tickets this platform wrote itself, an ERP import, a supplier feed or
 * a warranty system. From a capability's side of this interface there is only "historical
 * evidence", which is what lets a new source be added without reopening a single capability.
 *
 * Every method returns a [[HistoricalAnswer]] — data together with sample size, reconstruction tier,
 * completeness and proxy status — so confidence is computed once, here, by the only layer that
 * knows how the answer was assembled.
 *
 * IMPLEMENTED TODAY: the three questions the projection can answer honestly.
 *
 * DELIBERATELY ABSENT until the data supports them, each with the reason:
 *   · garagePerformance()          needs fault-mix standardisation — a raw return rate ranks every
 *                                  body shop last (BODY recurs 78.4%, BATTERY 16.1%) and would be a
 *                                  confident-looking, wrong answer.
 *   · historicalCost()             the money lives in `vehicle_expenses`, unjoined; the measured
 *                                  Tier-A join covers 3,658 lines, not the fleet.
 *   · commonReplacementParts()     the sheet's "Spare Part" column is 100% empty.
 *   · repairDurationDistribution() needs in/out timestamps that only the live workflow produces.
 *   · seasonality()                computable, but no capability needs it yet.
 * Adding one is an additive change here and in the implementation, and nothing else moves.
 */
interface RepairHistoryQuery
{
    /**
     * The query layer's version — the semantics of the ANSWERS, not the storage behind them.
     *
     * Bump it when a method's meaning changes: a different comeback window, a new exclusion rule, a
     * changed base-rate denominator. Do NOT bump it for a storage change that leaves answers
     * identical — moving to a materialised view is invisible by design, and versioning it would
     * falsely suggest the recommendation would come out differently today.
     */
    public function version(): string;

    /**
     * "Has this fault happened on this car before, recently?"
     *
     * The single highest-value question the corpus can answer, because the fleet currently has no
     * way to ask it at all.
     *
     * Exposure damage (BODY, RIM) is excluded: those recur because customers damage cars, not
     * because repairs fail, and counting them would fire on nearly every rental return.
     *
     * @param  string[]    $signatures canonical signatures in play
     * @param  string|null $asOf       judge history as at this date, not today (replay-safe)
     * @return HistoricalAnswer value: array<string, \Illuminate\Support\Collection> signature => prior rows, newest first
     */
    public function findPreviousEpisodes(
        int $vehicleId,
        array $signatures,
        ?int $excludeTicketId = null,
        ?string $asOf = null,
        ?int $windowDays = null,
    ): HistoricalAnswer;

    /**
     * The fleet-wide rate at which a signature comes back inside the window — the base rate any
     * single case is judged against.
     *
     * PROXY. A return is not a verified repair failure; eleven years of history contain no outcome
     * verdict. The answer says so, and every card built on it inherits that.
     *
     * @return HistoricalAnswer value: array{n:int, returned:int, rate:float}
     */
    public function signatureReturnRate(string $signature, ?int $windowDays = null): HistoricalAnswer;

    /**
     * Everything known to have happened to one vehicle, newest first — the raw material for chronic
     * vehicle detection and repair-vs-replace.
     *
     * @return HistoricalAnswer value: \Illuminate\Support\Collection of signature rows
     */
    public function vehicleHistory(int $vehicleId, ?string $asOf = null, int $limit = 100): HistoricalAnswer;
}
