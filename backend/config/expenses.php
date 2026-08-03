<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Vehicle expense source
    |--------------------------------------------------------------------------
    |
    | FleetView reads EVERY per-vehicle expense value (total, history, and any
    | derived cost summary) from ONE pluggable provider — never from OfficeManager,
    | Power BI, vouchers, GL accounts, depreciation accounts, or the maintenance
    | tables. Today that provider is the imported Excel sheet; later it becomes
    | Odoo. Swapping the source is this one line + one class — the UI and the
    | profitability calculations never change.
    |
    |   Excel → VehicleExpenseProvider → FleetView   (today)
    |   Odoo  → VehicleExpenseProvider → FleetView   (later)
    |
    */
    'provider' => env('VEHICLE_EXPENSE_PROVIDER', 'excel'),

    // Human label shown wherever the expense source is surfaced (drawer footer, etc.).
    'source_label' => env('VEHICLE_EXPENSE_SOURCE_LABEL', 'Expenses sheet'),

    /*
    |--------------------------------------------------------------------------
    | Categories excluded from vehicle COST
    |--------------------------------------------------------------------------
    |
    | Buckets (keys from App\Services\Expenses\ExpenseCategoryClassifier) that sit
    | in the ledger but are not spend ON this vehicle, so they must not reach the
    | maintenance total, cost/km, or net profit.
    |
    |   sub_rental — cars hired IN from other companies and recharged through the
    |                same ledger. It is a rental transaction, not maintenance of
    |                the asset.
    |
    | EXCLUDED, NOT DELETED. The lines stay in the table and stay visible in the
    | expense drawer under their own heading with the reason below — a number that
    | got smaller must always be able to say what came out of it.
    |
    | Keyed by category; the value is the reason shown to the user.
    |
    */
    'excluded_categories' => [
        'sub_rental' => 'A car hired in from another company and recharged through this ledger — a rental transaction, not maintenance of this vehicle.',
    ],
];
