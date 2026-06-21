<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Driver extends Model
{
    /** @use HasFactory<\Database\Factories\DriverFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'license_no',
        'license_expiry',
        'phone',
        'status',
        'external_id',
        'synced_at',
        'origin',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
