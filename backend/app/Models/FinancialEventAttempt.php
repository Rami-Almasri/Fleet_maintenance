<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One conversation with Odoo about one event, and what came back.
 *
 * `outcome` distinguishes CREATED from LINKED for the reason given in the migration: a retry that finds
 * an already-created document and adopts it is the proof that a duplicate was avoided. Collapsing the
 * two would make the integration's central guarantee invisible in its own audit trail.
 */
class FinancialEventAttempt extends Model
{
    protected $table = 'financial_event_attempts';

    /** We created the document on this attempt. */
    public const OUTCOME_CREATED = 'created';
    /** The document already existed under our ref — adopted, not duplicated. */
    public const OUTCOME_LINKED = 'linked';
    /** Odoo refused, timed out, or could not be reached. */
    public const OUTCOME_FAILED = 'failed';

    // Stable machine codes so failures group on the dashboard instead of being unique strings.
    public const ERROR_VALIDATION     = 'odoo_validation';
    public const ERROR_AUTHENTICATION = 'odoo_authentication';
    public const ERROR_TIMEOUT        = 'odoo_timeout';
    public const ERROR_NETWORK        = 'odoo_network';
    public const ERROR_NOT_CONFIGURED = 'odoo_not_configured';
    public const ERROR_UNEXPECTED     = 'unexpected';

    protected $fillable = [
        'financial_event_id', 'attempt', 'outcome',
        'odoo_model', 'odoo_document_id',
        'error_code', 'error_message', 'request_payload',
        'duration_ms', 'triggered_by', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'attempt'          => 'integer',
        'odoo_document_id' => 'integer',
        'request_payload'  => 'array',
        'duration_ms'      => 'integer',
        'started_at'       => 'datetime',
        'finished_at'      => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(FinancialEvent::class, 'financial_event_id');
    }

    public function succeeded(): bool
    {
        return in_array($this->outcome, [self::OUTCOME_CREATED, self::OUTCOME_LINKED], true);
    }
}
