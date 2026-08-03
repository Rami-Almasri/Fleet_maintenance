<?php

namespace App\Contracts;

/**
 * The SINGLE seam through which FleetView reads vehicle expenses.
 *
 * Everything financial-cost the app shows — the expense total on Profitability, the cost/km on Cost
 * Intelligence, the expense drawer's history/timeline, and any summary derived from it — is read
 * through this contract and NOTHING else. No OfficeManager, no Power BI, no vouchers/accounts, no
 * depreciation accounts, no maintenance tables ever contribute an expense figure.
 *
 * Today the implementation is Excel (ExcelVehicleExpenseProvider, reading the imported sheet). Later it
 * becomes Odoo (OdooVehicleExpenseProvider) — same contract, so the UI and the profit / cost-per-km
 * calculations that consume it never change:
 *
 *   Excel → VehicleExpenseProvider → FleetView   (today)
 *   Odoo  → VehicleExpenseProvider → FleetView   (later)
 *
 * A missing/unknown expense is null (or absent from the map) — never a fabricated 0.
 *
 * COST ≠ EVERY LINE. Some ledger lines are not spend on the vehicle at all (a car hired in from another
 * company, recharged through the same sheet). Every method that answers "what did this cost" —
 * {@see totalsByVehicle()}, {@see total()}, {@see totalsByMonth()}, {@see linesByVehicle()} — has those
 * removed; {@see exclusions()} names them and {@see history()} still returns them, flagged. Nothing is
 * dropped silently.
 */
interface VehicleExpenseProvider
{
    /**
     * Total expense per vehicle, keyed by vehicle_id, optionally bounded to [from, to] (Y-m-d).
     * Vehicles with no expense are simply absent from the map.
     *
     * @param  array<int>|null  $vehicleIds  limit to these vehicles (null = all)
     * @return array<int,float>  vehicle_id => total expense (AED)
     */
    public function totalsByVehicle(?array $vehicleIds = null, ?string $from = null, ?string $to = null): array;

    /** Total expense for ONE vehicle over an optional [from, to] window, or null when unknown. */
    public function total(int $vehicleId, ?string $from = null, ?string $to = null): ?float;

    /**
     * Fleet-wide expense totalled per calendar month, for the dashboard spend trend. Only dated,
     * vehicle-matched lines are counted (so it lines up with the per-vehicle totals). Months with no
     * expense are simply absent — the caller seeds the empty months.
     *
     * @param  array<int>|null  $vehicleIds  limit to these vehicles (null = all matched)
     * @return array<string,array{total:float,lines:int}>  keyed 'Y-m' => {total AED, line count}
     */
    public function totalsByMonth(?string $from = null, ?string $to = null, ?array $vehicleIds = null): array;

    /**
     * The vehicle's expense HISTORY — every source line, oldest first. Each entry is the raw record the
     * expense total is built from, so the UI can render it verbatim as the remarks / timeline list:
     *   ['date' => 'Y-m-d'|null, 'remarks' => string|null, 'amount' => float, 'account_type' => string|null]
     *
     * Each line also carries a `category` — the operational bucket (insurance / tyres / oil …) derived
     * from the remark by {@see \App\Services\Expenses\ExpenseCategoryClassifier}, plus the literal term
     * that decided it. This is presentation metadata for filtering; it never alters `amount`, and the
     * source columns above are still returned verbatim.
     *
     * UNLIKE the cost aggregates, this returns EVERY line — including the categories {@see exclusions()}
     * keeps OUT of the totals, each marked `excluded`. That is deliberate: the drawer has to be able to
     * show what was taken out of a number, so consumers must sum only the non-excluded lines to tie
     * back to {@see total()}.
     *
     * @return array<int,array{date:?string,remarks:?string,amount:float,account_type:?string,category:string,category_label:string,category_matched:?string,excluded:bool}>
     */
    public function history(int $vehicleId, ?string $from = null, ?string $to = null): array;

    /**
     * EVERY expense line, keyed by vehicle — the bulk form of {@see history()}.
     *
     * Repair-cost estimation has to read the whole ledger at once (tens of thousands of lines across
     * hundreds of vehicles) and calling history() per vehicle would mean hundreds of queries. This
     * exists so that work stays INSIDE the seam: nothing is allowed to reach past this contract to
     * `vehicle_expenses` directly, and a future Odoo provider swaps in without touching the consumer.
     *
     * @param  array<int>|null  $vehicleIds  limit to these vehicles (null = all)
     * @return array<int, array<int, array{date:?string, remarks:?string, amount:float}>>
     */
    public function linesByVehicle(?array $vehicleIds = null, ?string $from = null, ?string $to = null): array;

    /**
     * Provenance for the surface UI: which source, as-of when, and whether any data is loaded.
     *
     * @return array{label:string,available:bool,as_of:?string,lines:int}
     */
    public function source(): array;

    /**
     * Which categories are in the ledger but deliberately KEPT OUT of every cost figure, and why.
     *
     * Cost aggregates ({@see total()}, {@see totalsByVehicle()}, {@see totalsByMonth()},
     * {@see linesByVehicle()}) already have these removed. This exists so a UI can SAY SO: an expense
     * total that silently omits lines is exactly the black box the app is not allowed to be. Empty
     * array = nothing is excluded and every line counts.
     *
     * @return array<int,array{key:string,label:string,reason:string}>
     */
    public function exclusions(): array;

    /**
     * Is this source still being MAINTAINED?
     *
     * `source()` says where the data came from; this says whether anyone is still putting data in.
     * The distinction matters because a frozen ledger fails silently: every figure derived from it
     * keeps rendering, keeps looking precise, and quietly describes a fleet that stopped existing
     * months ago. Anything leaning on expense data — repair cost estimation above all — needs to be
     * able to say "this is current" or "this stopped in March" rather than just showing a number.
     *
     * @return array{
     *   last_import:?string, last_entry:?string, days_since_entry:?int,
     *   lines_30d:int, lines_90d:int, prior_90d:int, status:string, message:string
     * }
     */
    public function freshness(): array;
}
