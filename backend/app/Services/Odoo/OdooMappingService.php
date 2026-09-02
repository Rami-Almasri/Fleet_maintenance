<?php

namespace App\Services\Odoo;

use App\Models\ComponentCatalog;
use App\Models\ExpenseTypeMapping;
use App\Models\OdooMapping;
use App\Models\OdooReferenceRecord;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The one place a mapping between a FleetView record and an Odoo record is DECIDED.
 *
 * Reading a mapping is cheap and lives on the models ({@see \App\Models\Concerns\HasOdooMapping}).
 * Writing one is not: it is a decision with a person and a moment attached, and posting real money
 * depends on it being right. So every write goes through here, and every write records provenance.
 *
 * ── THE MATCHING LADDER (§16) ──────────────────────────────────────────────────────────────────────
 *
 * For a vehicle, in strict order of trust:
 *
 *   1. explicit mapping        somebody chose it. Always wins, and is never overwritten by a guess.
 *   2. stable external id      the same identifier in both systems.
 *   3. chassis / VIN           unique to the car for its whole life. Strong.
 *   4. licence plate           LAST, and only as a suggestion — plates move between cars.
 *
 * Steps 2–4 produce SUGGESTIONS, not mappings. That is the point of {@see suggestVehicleMatches()}
 * returning candidates rather than writing them: a suggestion the validator would accept is just name
 * matching with extra steps, and §16/§18 rule it out. A human confirms, and confirmation is what
 * {@see map()} records.
 *
 * The one exception is an EXACT match on a stable external reference, which {@see autoMapByReference()}
 * may commit directly — because that is not a guess about identity, it is the same identifier appearing
 * in both systems. Even then it is recorded as matched_by = external_ref so it can be told apart from a
 * human decision and audited later.
 */
class OdooMappingService
{
    /** The three mappable things and the Odoo model each one maps into. */
    public const TARGETS = [
        Vehicle::class          => OdooMapping::MODEL_ANALYTIC,
        ComponentCatalog::class => OdooMapping::MODEL_PRODUCT,
        Vendor::class           => OdooMapping::MODEL_PARTNER,
    ];

    /**
     * Record a decided mapping. Idempotent: mapping the same pair twice updates provenance rather than
     * raising a second row (the unique index would refuse it anyway).
     */
    public function map(
        Model $record,
        int $odooId,
        ?string $odooRef = null,
        ?string $odooName = null,
        ?User $actor = null,
        string $matchedBy = OdooMapping::MATCHED_MANUAL,
        ?string $notes = null,
    ): OdooMapping {
        $odooModel = $this->odooModelFor($record);

        return DB::transaction(function () use ($record, $odooModel, $odooId, $odooRef, $odooName, $actor, $matchedBy, $notes) {
            $mapping = OdooMapping::firstOrNew([
                'mappable_type' => $record->getMorphClass(),
                'mappable_id'   => $record->getKey(),
                'odoo_model'    => $odooModel,
            ]);

            // RE-POINTING an already-decided mapping leaves a trace. It is the most consequential edit
            // on the mappings screen: documents already posted were coded against the OLD target, and
            // whoever audits them has to be able to see that the mapping moved. (Those documents are
            // themselves safe — each event froze its own snapshot of what it used — but the person
            // reading this row still needs telling.) Only a genuine change counts: re-confirming the
            // same target is not a change and must not inflate the counter.
            $wasPointingAt = $mapping->exists && $mapping->status === OdooMapping::STATUS_MAPPED
                ? $mapping->odoo_id
                : null;

            if ($wasPointingAt !== null && (int) $wasPointingAt !== (int) $odooId) {
                $mapping->previous_odoo_id   = $wasPointingAt;
                $mapping->previous_odoo_name = $mapping->odoo_name;
                $mapping->changed_at         = now();
                $mapping->change_count       = (int) $mapping->change_count + 1;
            }

            $mapping->fill([
                'odoo_id'    => $odooId,
                'odoo_ref'   => $odooRef,
                'odoo_name'  => $odooName,
                'status'     => OdooMapping::STATUS_MAPPED,
                'matched_by' => $matchedBy,
                'mapped_by'  => $actor?->id,
                'mapped_at'  => now(),
                'notes'      => $notes,
            ])->save();

            return $mapping;
        });
    }

    /**
     * Record that this record deliberately has NO Odoo counterpart.
     *
     * Not the same as deleting the mapping. A deleted mapping means "nobody has looked at this yet" and
     * the record reappears in the mapping backlog forever; STATUS_UNMAPPED means "somebody looked, and
     * the answer is none". Both block posting — but only one of them is a question still open.
     */
    public function markUnmapped(Model $record, ?User $actor = null, ?string $notes = null): OdooMapping
    {
        $mapping = OdooMapping::firstOrNew([
            'mappable_type' => $record->getMorphClass(),
            'mappable_id'   => $record->getKey(),
            'odoo_model'    => $this->odooModelFor($record),
        ]);

        $mapping->fill([
            'odoo_id'    => null,
            'status'     => OdooMapping::STATUS_UNMAPPED,
            'matched_by' => OdooMapping::MATCHED_MANUAL,
            'mapped_by'  => $actor?->id,
            'mapped_at'  => now(),
            'notes'      => $notes,
        ])->save();

        return $mapping;
    }

    /** Withdraw a mapping entirely — it goes back to being an open question. */
    public function unmap(Model $record): void
    {
        OdooMapping::where('mappable_type', $record->getMorphClass())
            ->where('mappable_id', $record->getKey())
            ->where('odoo_model', $this->odooModelFor($record))
            ->delete();
    }

    // ── Resolution, as the validator and the pusher use it ────────────────────────────────────────

    /** The analytic account id to post this vehicle's cost against, or null when not confirmed. */
    public function analyticAccountIdFor(?Vehicle $vehicle): ?int
    {
        return $vehicle?->odooIdFor(OdooMapping::MODEL_ANALYTIC);
    }

    /** The Odoo product id for a catalogued part, or null when not confirmed. */
    public function productIdFor(?ComponentCatalog $part): ?int
    {
        return $part?->odooIdFor(OdooMapping::MODEL_PRODUCT);
    }

    /** The Odoo partner id for a supplier, or null when not confirmed. */
    public function partnerIdFor(?Vendor $vendor): ?int
    {
        return $vendor?->odooIdFor(OdooMapping::MODEL_PARTNER);
    }

    /** The expense-type row, which carries both the account and the document type. */
    public function expenseTypeMapping(string $expenseType): ?ExpenseTypeMapping
    {
        return ExpenseTypeMapping::where('expense_type', $expenseType)->first();
    }

    // ── Suggestions — proposals only, never committed ─────────────────────────────────────────────

    /**
     * Candidate analytic accounts for a vehicle, best first.
     *
     * Matches against the cached master data by the identifiers a fleet analytic account is normally
     * named after: the VIN (the example in §16 is `DEFENDER-SALE27RU9N2080952`), then the plate. Each
     * candidate says WHY it matched so the person confirming can judge it rather than trust a rank.
     *
     * @return list<array{odoo_id:int, name:string, code:?string, matched_by:string}>
     */
    public function suggestVehicleMatches(Vehicle $vehicle, int $limit = 5): array
    {
        $candidates = [];

        foreach ([
            OdooMapping::MATCHED_VIN   => $vehicle->vin,
            OdooMapping::MATCHED_PLATE => $vehicle->plate_no,
        ] as $matchedBy => $needle) {
            $needle = trim((string) $needle);
            if ($needle === '') {
                continue;
            }

            $rows = OdooReferenceRecord::forModel(OdooMapping::MODEL_ANALYTIC)
                ->where('active', true)
                ->search($needle)
                ->limit($limit)
                ->get();

            foreach ($rows as $row) {
                // First reason wins — VIN is checked before plate, so a record matching both is
                // reported as a VIN match, which is the stronger claim.
                $candidates[$row->odoo_id] ??= [
                    'odoo_id'    => (int) $row->odoo_id,
                    'name'       => (string) $row->name,
                    'code'       => $row->code,
                    'matched_by' => $matchedBy,
                ];
            }
        }

        return array_slice(array_values($candidates), 0, $limit);
    }

    /**
     * Candidate Odoo products for a catalogue part, best first.
     *
     * Searches the part's own name and its configured aliases, because the catalogue already holds the
     * other names a part is known by and they are better search terms than the canonical one. Every
     * result is a SUGGESTION — §18's "Brake Pad Front" / "Front Brake Pads" case is exactly why nothing
     * here may be committed automatically.
     *
     * @return list<array{odoo_id:int, name:string, code:?string, matched_by:string}>
     */
    public function suggestProductMatches(ComponentCatalog $part, int $limit = 5): array
    {
        $terms = array_filter(array_merge(
            [(string) $part->name],
            $this->aliasesOf($part),
        ), static fn ($t) => trim((string) $t) !== '');

        $candidates = [];
        foreach ($terms as $term) {
            $rows = OdooReferenceRecord::forModel(OdooMapping::MODEL_PRODUCT)
                ->where('active', true)
                ->search($term)
                ->limit($limit)
                ->get();

            foreach ($rows as $row) {
                $candidates[$row->odoo_id] ??= [
                    'odoo_id'    => (int) $row->odoo_id,
                    'name'       => (string) $row->name,
                    'code'       => $row->code,
                    'matched_by' => OdooMapping::MATCHED_SUGGESTED,
                ];
            }

            if (count($candidates) >= $limit) {
                break;
            }
        }

        return array_slice(array_values($candidates), 0, $limit);
    }

    /**
     * Candidate Odoo partners for a supplier.
     *
     * @return list<array{odoo_id:int, name:string, code:?string, matched_by:string}>
     */
    public function suggestVendorMatches(Vendor $vendor, int $limit = 5): array
    {
        $rows = OdooReferenceRecord::forModel(OdooMapping::MODEL_PARTNER)
            ->where('active', true)
            ->search((string) $vendor->name)
            ->limit($limit)
            ->get();

        return $rows->map(fn (OdooReferenceRecord $row) => [
            'odoo_id'    => (int) $row->odoo_id,
            'name'       => (string) $row->name,
            'code'       => $row->code,
            'matched_by' => OdooMapping::MATCHED_SUGGESTED,
        ])->values()->all();
    }

    /**
     * Commit a mapping ONLY where a stable reference matches EXACTLY on both sides.
     *
     * This is the one automatic write, and it is not a guess: an exact match on a stable external
     * reference is the same identifier appearing in two systems, which is the second rung of §16's
     * ladder rather than a similarity judgement. Anything less than exact returns null and stays a
     * suggestion.
     */
    public function autoMapByReference(Model $record, string $reference, ?User $actor = null): ?OdooMapping
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        $row = OdooReferenceRecord::forModel($this->odooModelFor($record))
            ->where('active', true)
            ->where('code', $reference)
            ->first();

        if (! $row) {
            return null;
        }

        return $this->map(
            $record,
            (int) $row->odoo_id,
            $row->code,
            $row->name,
            $actor,
            OdooMapping::MATCHED_EXTERNAL_REF,
        );
    }

    // ── Internals ─────────────────────────────────────────────────────────────────────────────────

    private function odooModelFor(Model $record): string
    {
        foreach (self::TARGETS as $class => $odooModel) {
            if ($record instanceof $class) {
                return $odooModel;
            }
        }

        throw new \InvalidArgumentException(
            $record::class . ' has no Odoo counterpart — only vehicles, catalogue parts and vendors map.'
        );
    }

    /** The other names a catalogue part is known by, whatever shape the column is stored in. */
    private function aliasesOf(ComponentCatalog $part): array
    {
        $raw = $part->aliases;

        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : array_map('trim', explode(',', $raw));
        }

        return [];
    }
}
