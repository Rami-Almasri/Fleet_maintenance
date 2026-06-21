<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceReason extends Model
{
    protected $fillable = [
        'sheet_ref', 'reason_en', 'reason_ar', 'status_raw', 'level', 'explanation',
    ];
}
