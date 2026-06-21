<?php

return [
    /*
    | Maintenance jobs whose cost (total or any single item) exceeds this amount are
    | flagged "pending" and must be approved before they're treated as final.
    */
    'maintenance_approval_threshold' => (float) env('MAINTENANCE_APPROVAL_THRESHOLD', 500),
];
