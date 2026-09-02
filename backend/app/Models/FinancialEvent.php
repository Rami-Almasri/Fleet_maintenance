<?php

namespace App\Models;

use App\Contracts\FinancialEventSource;
use App\Support\ExpenseType;
use App\Support\FinancialSyncStatus as Status;
use App\Support\OdooDocumentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * "This operation generated a cost that may need to reach Odoo."
 *
 * The event is a VIEW ONTO an operation plus the accounting-specific facts the operation does not hold.
 * It owns no parts, no prices and no supplier of its own — those belong to the maintenance ticket, its
 * invoice and its line items, and this row points at them. See the create_financial_events migration for
 * the full account of what is referenced and what is stored.
 *
 * The three things worth knowing before touching this class:
 *
 *  1. FROZEN AFTER SENDING. Once an event reaches SENDING, its money and its mapping snapshot are
 *     history — {@see isFrozen()} guards them. A later edit to a line item changes the ticket's cost; it
 *     does NOT rewrite what Odoo was told. Correcting a posted document is a credit note raised in Odoo.
 *
 *  2. THE IDEMPOTENCY KEY IS IMMUTABLE. It is generated once, written into Odoo's `ref`, and searched
 *     for before any document is created. Regenerating it would turn every retry into a duplicate bill.
 *
 *  3. INVOICE PAPERWORK PREFERS THE SOURCE. resolvedInvoiceNumber/Date/Attachment read the operational
 *     record first and fall back to this row's own columns. Nobody re-keys a number the system has.
 */
class FinancialEvent extends Model
{
    protected $table = 'financial_events';

    protected $fillable = [
        'idempotency_key',
        'source_type', 'source_id',
        'vehicle_id', 'maintenance_id', 'vendor_id',
        'expense_type', 'amount', 'currency', 'description',
        'status', 'block_reasons', 'validated_at',
        'invoice_number', 'invoice_date', 'attachment_disk', 'attachment_key',
        'odoo_account_id', 'odoo_analytic_account_id', 'odoo_partner_id', 'odoo_journal_id',
        'odoo_document_type',
        'odoo_document_id', 'odoo_document_model', 'odoo_document_reference', 'synced_at',
        'failure_code', 'failure_reason', 'attempts', 'last_attempt_at',
        'created_by', 'approved_by', 'approved_at', 'cancelled_at', 'cancellation_reason',
    ];

    protected $casts = [
        'amount'                   => 'decimal:2',
        'block_reasons'            => 'array',
        'validated_at'             => 'datetime',
        'invoice_date'             => 'date',
        'odoo_account_id'          => 'integer',
        'odoo_analytic_account_id' => 'integer',
        'odoo_partner_id'          => 'integer',
        'odoo_journal_id'          => 'integer',
        'odoo_document_id'         => 'integer',
        'synced_at'                => 'datetime',
        'attempts'                 => 'integer',
        'last_attempt_at'          => 'datetime',
        'approved_at'              => 'datetime',
        'cancelled_at'             => 'datetime',
    ];

    protected static function booted(): void
    {
        // The external identity has to exist before anything can be sent, and it must never be
        // regenerated — so it is stamped exactly once, at creation, and nothing else writes it.
        static::creating(function (self $event) {
            if (! $event->idempotency_key) {
                $event->idempotency_key = (string) Str::uuid();
            }
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────────────────────────

    /** The operation that caused the cost — a MaintenanceInvoice, a ticket, a registration. */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FinancialEventLine::class, 'financial_event_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(FinancialEventAttempt::class, 'financial_event_id');
    }

    // ── Identity in Odoo ──────────────────────────────────────────────────────────────────────────

    /**
     * The reference written into Odoo's own `ref` field and searched for before creating anything.
     * This string IS the idempotency guarantee; see §23 and OdooDocumentPusher.
     */
    public function odooRef(): string
    {
        return ((string) config('odoo.external_ref_prefix', 'FLEETVIEW-FE-')) . $this->idempotency_key;
    }

    /**
     * A link a user can click to open the document in Odoo, or null.
     *
     * Null when no template is configured or nothing has been posted — §41: we never invent a URL.
     */
    public function odooDocumentUrl(): ?string
    {
        $template = config('odoo.document_url_template');
        if (! $template || ! $this->odoo_document_id || ! $this->odoo_document_model) {
            return null;
        }

        return str_replace(
            ['{id}', '{model}'],
            [(string) $this->odoo_document_id, $this->odoo_document_model],
            (string) $template
        );
    }

    // ── Paperwork: the source answers first (§31) ─────────────────────────────────────────────────

    /** The operational source, when it is one that can speak for itself. */
    public function sourceRecord(): ?FinancialEventSource
    {
        $record = $this->source;

        return $record instanceof FinancialEventSource ? $record : null;
    }

    public function resolvedInvoiceNumber(): ?string
    {
        $fromSource = $this->sourceRecord()?->financialInvoiceNumber();

        return $this->blankToNull($fromSource) ?? $this->blankToNull($this->invoice_number);
    }

    public function resolvedInvoiceDate(): ?string
    {
        $fromSource = $this->sourceRecord()?->financialInvoiceDate();

        return $this->blankToNull($fromSource)
            ?? ($this->invoice_date ? $this->invoice_date->toDateString() : null);
    }

    /** @return array{disk:?string, key:?string}|null */
    public function resolvedAttachment(): ?array
    {
        $fromSource = $this->sourceRecord()?->financialAttachment();
        if ($fromSource && ($fromSource['key'] ?? null)) {
            return $fromSource;
        }

        return $this->attachment_key
            ? ['disk' => $this->attachment_disk, 'key' => $this->attachment_key]
            : null;
    }

    private function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    // ── State ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * Has this event been committed to Odoo's keeping?
     *
     * SENDING counts, not just SYNCED. From the moment a push starts we can no longer be sure the
     * document does not exist, so the figures that would be posted must stop moving — otherwise a retry
     * could link a document created from one set of numbers to an event now holding another.
     */
    public function isFrozen(): bool
    {
        return in_array($this->status, [Status::SENDING, Status::SYNCED], true);
    }

    public function isSendable(): bool
    {
        return Status::isSendable($this->status);
    }

    public function isTerminal(): bool
    {
        return Status::isTerminal($this->status);
    }

    /** The blocking reasons as stored — code + params + frozen English. */
    public function blockReasons(): array
    {
        return is_array($this->block_reasons) ? $this->block_reasons : [];
    }

    /**
     * What this event must carry before it may be sent.
     *
     * Resolved the same way the validator resolves it — the document type's defaults with the expense
     * type's attachment override applied — so the checklist a user sees can never disagree with the
     * rule that actually blocks them.
     */
    public function documentRequirements(): array
    {
        $mapping = ExpenseTypeMapping::where('expense_type', $this->expense_type)->first();

        return $mapping ? $mapping->requirements() : OdooDocumentType::requirements($this->odoo_document_type);
    }

    public function expenseTypeLabel(): string
    {
        return ExpenseType::label($this->expense_type);
    }

    public function statusLabel(): string
    {
        return Status::label($this->status);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────────────────────────

    public function scopeOutstanding(Builder $q): Builder
    {
        return $q->whereIn('status', Status::OUTSTANDING);
    }

    public function scopeForVehicle(Builder $q, int $vehicleId): Builder
    {
        return $q->where('vehicle_id', $vehicleId);
    }

    public function scopeForSource(Builder $q, string $type, int $id): Builder
    {
        return $q->where('source_type', $type)->where('source_id', $id);
    }
}
