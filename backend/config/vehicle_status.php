<?php

/*
|--------------------------------------------------------------------------
| Fleet "Status" sheet overlay
|--------------------------------------------------------------------------
|
| The OfficeManager API keeps a car under our owner number even after it is
| sold/exported/etc., so om:sync alone can leave a departed car looking active.
| This sheet is the human-maintained source of truth for a car's REAL status
| (Active / Sold / For sale / Personal / Office / ...). We overlay it AFTER the
| API+sheet sync (see FleetRefreshCommand + the schedule) so it always wins for
| cars that have left service — but never for Active cars.
|
| Core rule: 'active' maps to null → the car is LEFT UNCHANGED, so whatever live
| status om:sync set (ready / rented / under_maintenance) stands. This step only
| ever REMOVES a car from the active pool; it never flattens a live car to "active".
|
*/

return [

    // The workbook + tab holding the per-car Status column (Code | Plate | Chassis | Status | …).
    // NOTE: the Google service account (storage/app/google/credentials.json) must be shared on
    // this workbook (Viewer is enough) or the read will 403.
    'sheet_id' => env('GOOGLE_SHEETS_VEHICLE_STATUS_ID', '1z1SoOSy9On0dwU2nMiIS1MNpp6j1fztAhoh_snFr3Hk'),
    'gid'      => (int) env('GOOGLE_SHEETS_VEHICLE_STATUS_GID', 723314813),

    /*
    | Sheet Status text (lower-cased, whitespace collapsed) => what to do to OUR car:
    |   - a status slug ('sold','office_use','out_of_order','disposed',…) => set vehicles.status
    |   - the literal 'for_sale'  => leave status alone, set the for_sale flag = true
    |   - null                    => LEAVE THE CAR UNCHANGED (defer to om:sync's live status)
    |
    | Valid status slugs are the values of Vehicle::OM_STATUS: office_use, ready, rented,
    | out_of_order, under_maintenance, suspended, disposed, sold, returned.
    |
    | The three "leave as-is for now" entries below are intentional — flip them to a status slug
    | whenever you want this step to act on them (no code change needed, just edit this map):
    |     'exported'        => 'sold',          // or 'disposed'
    |     'insurance claim' => 'out_of_order',
    |     'under process'   => 'suspended',
    */
    'status_map' => [
        'active'          => null,        // leave as-is — om:sync's live status wins
        'sold'            => 'sold',       // out of fleet
        'for sale'        => 'for_sale',   // still rentable, just flagged
        'personal'        => 'office_use', // not in the rental pool
        'office'          => 'office_use', // not in the rental pool
        'exported'        => null,         // leave as-is (flip to 'sold'/'disposed' when ready)
        'insurance claim' => null,         // leave as-is (flip to 'out_of_order' when ready)
        'under process'   => null,         // leave as-is
    ],
];
