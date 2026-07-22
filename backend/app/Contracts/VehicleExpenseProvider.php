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
     * @return array<int,array{date:?string,remarks:?string,amount:float,account_type:?string}>
     */
    public function history(int $vehicleId, ?string $from = null, ?string $to = null): array;

    /**
     * Provenance for the surface UI: which source, as-of when, and whether any data is loaded.
     *
     * @return array{label:string,available:bool,as_of:?string,lines:int}
     */
    public function source(): array;
}
