<?php

namespace App\Services\Odoo;

use App\Models\OdooMapping;
use App\Models\OdooReferenceRecord;
use Illuminate\Support\Facades\DB;

/**
 * Pulls Odoo's master data down so the mappings screen has real records to pick from (§26, §42).
 *
 * Direction matters here. Operational financial transactions go FleetView → Odoo; reference data comes
 * Odoo → FleetView, and Odoo is authoritative for all of it. Nothing in this class writes to Odoo — in
 * particular it never CREATES a product or a partner there. §26 is explicit, and the reason is that an
 * integration that auto-creates products turns every typo in a garage's invoice into a permanent entry
 * in someone else's product catalogue.
 *
 * ── STALENESS IS DETECTED, NOT ASSUMED ─────────────────────────────────────────────────────────────
 *
 * Each pull stamps `seen_at` on every record it saw. Afterwards {@see markStaleMappings()} finds
 * mappings pointing at ids this pull did NOT return — a product archived in Odoo, a partner merged
 * away — and flags them STALE. That is the difference between a mapping that quietly posts to a
 * deleted account and one that says so on the dashboard before anybody presses Send.
 */
class OdooMasterDataService
{
    /**
     * What we pull, and the fields each model is recognised by.
     *
     * `code` is the stable human reference per model — it is what autoMapByReference() matches on
     * exactly, and what a finance team recognises an account by.
     */
    public const MODELS = [
        'account.account' => [
            'fields' => ['id', 'name', 'code', 'account_type'],
            'code'   => 'code',
            'domain' => [],
        ],
        'account.analytic.account' => [
            'fields' => ['id', 'name', 'code'],
            'code'   => 'code',
            'domain' => [],
        ],
        'product.product' => [
            'fields' => ['id', 'name', 'default_code', 'uom_id'],
            'code'   => 'default_code',
            // Only things that can appear on a purchase. Pulling the whole catalogue would fill the
            // picker with items nobody can bill against.
            'domain' => [['purchase_ok', '=', true]],
        ],
        'res.partner' => [
            'fields' => ['id', 'name', 'ref', 'vat'],
            'code'   => 'ref',
            'domain' => [['supplier_rank', '>', 0]],
        ],
    ];

    public function __construct(
        private OdooClient $client,
    ) {
    }

    /**
     * Pull every configured model.
     *
     * @return array<string, int>  model => records seen
     */
    public function pullAll(): array
    {
        $counts = [];

        foreach (array_keys(self::MODELS) as $model) {
            $counts[$model] = $this->pull($model);
        }

        return $counts;
    }

    /**
     * Pull one model, in pages, and return how many records were seen.
     *
     * Upsert rather than truncate-and-rewrite: a truncate would empty the picker for as long as the
     * pull takes, and would lose every record if the pull failed halfway. Records that vanish from Odoo
     * are handled by {@see markStaleMappings()} instead, which is a decision rather than a side effect.
     */
    public function pull(string $model): int
    {
        if (! isset(self::MODELS[$model])) {
            throw new \InvalidArgumentException("Odoo model {$model} is not configured for master-data pull.");
        }

        $spec      = self::MODELS[$model];
        $batch     = max(50, (int) config('odoo.master_data.batch_size', 500));
        $offset    = 0;
        $seen      = 0;
        $seenIds   = [];
        $pulledAt  = now();

        do {
            $rows = $this->client->searchRead($model, $spec['domain'], $spec['fields'], $batch, $offset);

            foreach ($rows as $row) {
                $odooId = (int) ($row['id'] ?? 0);
                if ($odooId <= 0) {
                    continue;
                }

                OdooReferenceRecord::updateOrCreate(
                    ['odoo_model' => $model, 'odoo_id' => $odooId],
                    [
                        'name'    => (string) ($row['name'] ?? ''),
                        'code'    => $this->scalar($row[$spec['code']] ?? null),
                        'payload' => $this->displayPayload($row, $spec['fields']),
                        'active'  => true,
                        'seen_at' => $pulledAt,
                    ]
                );

                $seenIds[] = $odooId;
                $seen++;
            }

            $offset += $batch;
        } while (count($rows) === $batch);

        $this->markStaleMappings($model, $seenIds);

        $this->client->log('master data pulled', ['odoo_model' => $model, 'records' => $seen]);

        return $seen;
    }

    /**
     * Flag mappings that point at records this pull did not return.
     *
     * A mapping goes STALE, never deleted. Deleting it would erase the decision somebody made and the
     * evidence of what a previously-posted document was coded against; STALE keeps both while stopping
     * the validator from accepting it (OdooMapping::isUsable() requires STATUS_MAPPED).
     *
     * A pull that returned NOTHING marks nothing. An empty result is far more likely to mean the RPC
     * filter was wrong or Odoo was mid-restore than that every product in the catalogue vanished, and
     * mass-invalidating every mapping on that evidence would take the whole integration down.
     */
    public function markStaleMappings(string $model, array $seenIds): int
    {
        $mappedModel = $this->mappingModelFor($model);

        if ($mappedModel === null || $seenIds === []) {
            return 0;
        }

        return DB::transaction(function () use ($mappedModel, $seenIds) {
            // Everything still present is confirmed as of now — which is what makes a stale flag
            // meaningful rather than merely old.
            OdooMapping::where('odoo_model', $mappedModel)
                ->whereIn('odoo_id', $seenIds)
                ->where('status', OdooMapping::STATUS_MAPPED)
                ->update(['last_synced_at' => now()]);

            return OdooMapping::where('odoo_model', $mappedModel)
                ->whereNotNull('odoo_id')
                ->whereNotIn('odoo_id', $seenIds)
                ->where('status', OdooMapping::STATUS_MAPPED)
                ->update(['status' => OdooMapping::STATUS_STALE]);
        });
    }

    /**
     * Which mappable Odoo model a pulled model corresponds to.
     *
     * account.account is absent on purpose: expense accounts are mapped on expense_type_mappings, not in
     * odoo_mappings, so there is nothing there to mark stale.
     */
    private function mappingModelFor(string $model): ?string
    {
        return match ($model) {
            'account.analytic.account' => OdooMapping::MODEL_ANALYTIC,
            'product.product'          => OdooMapping::MODEL_PRODUCT,
            'res.partner'              => OdooMapping::MODEL_PARTNER,
            default                    => null,
        };
    }

    /** Everything except id/name/code, kept for the picker to show. */
    private function displayPayload(array $row, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (in_array($field, ['id', 'name'], true)) {
                continue;
            }
            $out[$field] = $row[$field] ?? null;
        }

        return $out;
    }

    /**
     * Odoo returns `false` for an empty field, and a many2one as [id, "Display Name"]. Neither is a
     * string, and storing either as one is how "false" ends up displayed as a product code.
     */
    private function scalar(mixed $value): ?string
    {
        if (is_array($value)) {
            return isset($value[1]) ? (string) $value[1] : null;
        }

        return ($value === false || $value === null || $value === '') ? null : (string) $value;
    }
}
