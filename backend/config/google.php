<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Service Account Credentials
    |--------------------------------------------------------------------------
    |
    | Path to the Google service-account JSON key. Relative paths are resolved
    | from the project root (base_path). The file lives outside version control
    | (see .gitignore -> /storage/app/google).
    |
    */
    'credentials' => env('GOOGLE_SHEETS_CREDENTIALS', 'storage/app/google/credentials.json'),

    /*
    |--------------------------------------------------------------------------
    | Known Spreadsheets
    |--------------------------------------------------------------------------
    |
    | Each source sheet we sync from. id = the spreadsheet id from its URL,
    | gid = the specific tab id. Add more entries as we map more tabs.
    |
    */
    'sheets' => [

        // Cars master: the "Faster" tab in the Database Warehouse.
        // NOTE: header_row = 3 because rows 1-2 are group titles.
        'cars' => [
            'id'         => env('GOOGLE_SHEETS_CARS_ID'),
            'gid'        => env('GOOGLE_SHEETS_CARS_GID'),
            'header_row' => (int) env('GOOGLE_SHEETS_CARS_HEADER_ROW', 1),
        ],

        // FASTER Asset: purchase price / date (joined to cars by VIN).
        'asset' => [
            'id'  => env('GOOGLE_SHEETS_ASSET_ID'),
            'gid' => env('GOOGLE_SHEETS_ASSET_GID'),
        ],

        // FASTER Maintenance: work orders, garages, oil change (later phases).
        // reasons_gid = the "Main reason" tab: the controlled reason->status vocabulary.
        'maintenance' => [
            'id'          => env('GOOGLE_SHEETS_MAINTENANCE_ID'),
            'reasons_gid' => (int) env('GOOGLE_SHEETS_MAINTENANCE_REASONS_GID', 1314115859),
            // "N-Maintenance & Repair" log tab (gid 400222171) — the EXCLUSIVE, single
            // source of truth for ALL maintenance data (status / garage / issues /
            // maintenance type / MAIN+SUP). Maintenance is never fetched or inferred from
            // the API or anywhere else; every maintenances row has origin='sheet'.
            'log_gid'     => (int) env('GOOGLE_SHEETS_MAINTENANCE_LOG_GID', 400222171),
            // "Customer Cases" tab (gid 37444190) — a customer-charge maintenance log with
            // slightly different columns (Main Issue / Main Cause / Customer Charge / Contract
            // No. / Bill Receive). Imported with origin='customer-sheet' so it stays separate
            // from the fleet maintenance board.
            'customer_cases_gid' => (int) env('GOOGLE_SHEETS_MAINTENANCE_CUSTOMER_CASES_GID', 37444190),
            // "Oil Change" tab — the ONLY source of truth for the per-car service interval
            // (VALIDITY) + last-service odometer (LAST CHANGE). Current mileage stays API-owned.
            'oil_change_gid' => (int) env('GOOGLE_SHEETS_OIL_CHANGE_GID', 560587794),
        ],

        // Customers source ("New OM" tab): names/nationality/mobile. Header row 1, row 2 is a dashes separator.
        'customers' => [
            'id'  => env('GOOGLE_SHEETS_CUSTOMERS_ID'),
            'gid' => env('GOOGLE_SHEETS_CUSTOMERS_GID'),
        ],

        // Contracts source (rich RA "Contracts" tab, 119 cols). Header row 1, data from row 2.
        'contracts' => [
            'id'  => env('GOOGLE_SHEETS_CONTRACTS_ID'),
            'gid' => env('GOOGLE_SHEETS_CONTRACTS_GID'),
        ],

        // Vehicle registrations (RTA) source ("F RTA" tab). Header row 1.
        'registrations' => [
            'id'  => env('GOOGLE_SHEETS_REGISTRATIONS_ID'),
            'gid' => env('GOOGLE_SHEETS_REGISTRATIONS_GID'),
        ],

        // Insurance expiry source ("F Insurance" tab). Header row 1.
        'insurance' => [
            'id'  => env('GOOGLE_SHEETS_INSURANCE_ID'),
            'gid' => env('GOOGLE_SHEETS_INSURANCE_GID'),
        ],

        // Vehicle Timeline EXPORT (write target, not a source). The whole fleet's
        // maintenance-workflow audit trail (vehicle_log_events) is pushed here, one row per event,
        // by `php artisan events:sync-sheet`. `tab` is created on first run if missing.
        'events_export' => [
            'id'  => env('GOOGLE_SHEETS_EVENTS_ID', '1SD7KP0S6p0sjIH0ptIuMAcwh2c4z2MQ71oFTYJh2Ru8'),
            'tab' => env('GOOGLE_SHEETS_EVENTS_TAB', 'Vehicle Timeline'),
        ],

        // "Main Trip Dashboard" tab — the live pickup/drop-off trip log that powers
        // the Delivery Command dashboard + Orders board. Banner rows 1-2, header row 3,
        // data from row 4. Defaults point at the shared trips workbook.
        'trips' => [
            'id'  => env('GOOGLE_SHEETS_TRIPS_ID', '13IZwMw6Ih91cuyjKQWABzDj95X0cCicvL7OujEKqxX4'),
            'gid' => (int) env('GOOGLE_SHEETS_TRIPS_GID', 221506513),
        ],
    ],
];
