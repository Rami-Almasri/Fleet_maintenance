<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A deleted sheet-synced workshop event, kept by its importer `row_hash` so the
 * sheet sync never re-creates it. Carries a lossless `payload` (to recreate the
 * `maintenances` row on Restore) and a `display` snapshot (to render the greyed
 * ghost in the Manage-Events list). See [[workshop-events-crud]] in project memory.
 */
class MaintenanceTombstone extends Model
{
    protected $table = 'maintenance_tombstones';

    protected $fillable = [
        'row_hash',
        'vehicle_id',
        'origin',
        'out_date',
        'payload',
        'display',
        'note',
        'created_by',
    ];

    protected $casts = [
        'out_date' => 'date',
        'payload'  => 'array',
        'display'  => 'array',
    ];
}
