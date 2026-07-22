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
];
