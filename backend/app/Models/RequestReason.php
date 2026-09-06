<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ONE REASON a car can be sent in, on one door.
 *
 * This is the list that used to be Maintenance::REQUEST_REASONS_INSPECTION / _DISPATCH. It became a
 * table for two reasons: the office can add a reason without a deploy, and `request_reason_code` is now
 * joinable — "which reasons are actually being used, and on which cars?" is a query rather than a
 * constant pasted into a report.
 *
 * THE CODE IS THE STORED FACT. `maintenances.request_reason_code` holds `code` and nothing else (see
 * [[reason-code-contract]]); `label` / `label_ar` are presentation and may be reworded at any time
 * without rewriting a single ticket.
 *
 * RETIRED ≠ DELETED. `retired_at` takes a reason out of the picker and leaves it readable forever, so a
 * ticket filed under a withdrawn reason still says what it was filed under. Nothing in this model ever
 * hard-deletes a row, and `labels()` deliberately reads retired rows too — that is the entire point.
 */
class RequestReason extends Model
{
    /** Ask for a test — the requester cannot say what is wrong, so an inspector drives it. */
    public const DOOR_INSPECTION = 'inspection';

    /** Straight to the garage — nothing to diagnose, the car has somewhere to be. */
    public const DOOR_DISPATCH = 'dispatch';

    public const DOORS = [self::DOOR_INSPECTION, self::DOOR_DISPATCH];

    protected $fillable = [
        'door', 'code', 'label', 'label_ar', 'sort_order',
        'retired_at', 'retired_by', 'created_by',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'retired_at' => 'datetime',
    ];

    /** Still offered in the picker. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    /** Withdrawn — kept only so the tickets filed under it still read. */
    public function scopeRetired(Builder $query): Builder
    {
        return $query->whereNotNull('retired_at');
    }

    public function scopeForDoor(Builder $query, string $door): Builder
    {
        return $query->where('door', $door);
    }

    /** The picker's order: what the office arranged, then alphabetical as a tiebreak. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('label');
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /**
     * Per-request memo. The intake form asks for both doors and the validators ask again on submit, and
     * this list changes about twice a year — but it DOES change inside the request that adds or retires
     * one, which is why the writes call flushCache() rather than trusting a `static` local.
     *
     * @var array<string,array<string,string>>
     */
    protected static array $memo = [];

    /**
     * The LIVE list for one door as `code => label` — the shape the form and the validator both want.
     */
    public static function listFor(string $door): array
    {
        return static::$memo["live:$door"] ??= static::query()
            ->forDoor($door)->live()->ordered()
            ->pluck('label', 'code')
            ->all();
    }

    /**
     * EVERY code that has ever existed → its label, retired ones included, so a stored code always
     * resolves to words. Codes are unique per door but may repeat ACROSS doors; where they do, the two
     * carry the same meaning by construction (they were one constant before this table), so a flat map
     * is the right shape for "what does this ticket's code mean?".
     */
    public static function labels(): array
    {
        return static::$memo['all'] ??= static::query()->ordered()->pluck('label', 'code')->all();
    }

    /** Forget the memo — called after every write, so one request never serves a picker from before it. */
    public static function flushCache(): void
    {
        static::$memo = [];
    }
}
