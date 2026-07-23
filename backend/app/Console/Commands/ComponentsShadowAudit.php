<?php

namespace App\Console\Commands;

use App\Models\PartPurchase;
use App\Models\VehicleComponent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Asset Layer — the shadow validation scorecard (docs/Asset-Layer-Shadow-Launch-Plan.md §7.3).
 *
 * Read-only by default: runs the M-checks scoped to the shadow population and prints totals,
 * mismatches and a recommended action. The ONLY writes it can ever perform are the two explicit,
 * audited trust-marker transitions:
 *   --promote            provisional rows passing ALL checks → validated (the gate-day step)
 *   --quarantine=1,2,3   known-bad rows → quarantined (requires --reason), kept for audit,
 *                        excluded from every future read surface.
 *
 * It NEVER touches status/location/removal legs — corrections go through ComponentService.
 */
class ComponentsShadowAudit extends Command
{
    protected $signature = 'components:shadow-audit
        {--since= : Only consider rows created at/after this datetime (default: first shadow row)}
        {--promote : Mark every shadow row that passes all checks as validated}
        {--quarantine= : Comma-separated component ids to quarantine (requires --reason)}
        {--reason= : Mandatory justification when quarantining}
        {--json : Machine-readable output}';

    protected $description = 'Audit shadow-mode component writes against the integrity checks; optionally promote clean rows or quarantine bad ones.';

    public function handle(): int
    {
        // Explicit quarantine is its own, self-contained action.
        if ($ids = $this->option('quarantine')) {
            return $this->quarantine($ids);
        }

        $since = $this->option('since')
            ? Carbon::parse($this->option('since'))
            : VehicleComponent::where('write_mode', 'shadow')->min('created_at');

        if (! $since) {
            $this->info('No shadow component writes found. Nothing to audit.');

            return self::SUCCESS;
        }

        $shadow = VehicleComponent::with('catalog')
            ->where('write_mode', 'shadow')
            ->where('created_at', '>=', $since)
            ->get();

        // ── The checks (per row + the one cross-ledger gap check) ─────────────────────────────
        $failures = [];   // component_id => [reasons]
        $flag = function (int $id, string $reason) use (&$failures) {
            $failures[$id][] = $reason;
        };

        foreach ($shadow as $c) {
            if ($c->source === VehicleComponent::SOURCE_WORKFLOW && ! $c->source_part_purchase_id) {
                $flag($c->id, 'M2: workflow row without source purchase');
            }
            if ($c->removed_at && (! $c->disposition || ! $c->removal_reason)) {
                $flag($c->id, 'M4: removed without reason+disposition');
            }
            $validLocations = VehicleComponent::VALID_STATUS_LOCATIONS[$c->status] ?? [];
            if (! in_array($c->location, $validLocations, true)) {
                $flag($c->id, "M7: invalid state pair [{$c->status}/{$c->location}]");
            }
            if ($c->status === VehicleComponent::STATUS_ACTIVE && ! $c->vehicle_id) {
                $flag($c->id, 'M7: active without vehicle');
            }
            if ($c->catalog?->isSerialized() && blank($c->serial_no)) {
                $flag($c->id, 'serialized without serial_no');
            }
        }

        // M3: slot conflicts (any active involvement, not only shadow rows — a shadow row can conflict with an older one).
        $conflicts = VehicleComponent::query()
            ->selectRaw('vehicle_id, component_catalog_id, position, COUNT(*) as n, GROUP_CONCAT(id) as ids')
            ->where('status', VehicleComponent::STATUS_ACTIVE)
            ->groupBy('vehicle_id', 'component_catalog_id', 'position')
            ->having('n', '>', 1)
            ->get();
        foreach ($conflicts as $row) {
            foreach (explode(',', $row->ids) as $id) {
                if ($shadow->contains('id', (int) $id)) {
                    $flag((int) $id, "M3: slot conflict (components #{$row->ids})");
                }
            }
        }

        // M1: installs in the window with NO component row — the silent-miss detector.
        $gaps = PartPurchase::query()
            ->whereNotNull('installed_at')
            ->where('installed_at', '>=', $since)
            ->whereDoesntHave('component')
            ->get(['id', 'part_name', 'category_key', 'vehicle_id', 'installed_at']);

        // ── Scorecard ──────────────────────────────────────────────────────────────────────────
        $failingIds  = array_keys($failures);
        $passing     = $shadow->whereNotIn('id', $failingIds);
        $provisional = $shadow->where('validation_status', VehicleComponent::VALIDATION_PROVISIONAL);

        $promoted = 0;
        if ($this->option('promote')) {
            foreach ($passing as $c) {
                if ($c->validation_status === VehicleComponent::VALIDATION_PROVISIONAL) {
                    $c->validation_status = VehicleComponent::VALIDATION_VALIDATED;
                    $c->validated_at      = now();
                    $c->validated_by      = null; // CLI promotion; the shadow log records the human decision
                    $c->save();
                    $promoted++;
                }
            }
        }

        $summary = [
            'since'              => (string) $since,
            'shadow_writes'      => $shadow->count(),
            'pass_all_checks'    => $passing->count(),
            'mismatches'         => count($failures),
            'm1_install_gaps'    => $gaps->count(),
            'unresolved_provisional' => $provisional->count() - $promoted,
            'promoted_now'       => $promoted,
            'failures'           => $failures,
            'gaps'               => $gaps->toArray(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT));

            return count($failures) || $gaps->count() ? self::FAILURE : self::SUCCESS;
        }

        $this->info("Shadow audit since {$since}");
        $this->table(['metric', 'value'], [
            ['shadow component writes', $shadow->count()],
            ['pass all checks', $passing->count()],
            ['mismatched rows', count($failures)],
            ['M1 install gaps (no component)', $gaps->count()],
            ['still provisional', $summary['unresolved_provisional']],
            ['promoted this run', $promoted],
        ]);

        foreach ($failures as $id => $reasons) {
            $this->warn("  component #{$id}: " . implode(' · ', $reasons));
        }
        foreach ($gaps as $gap) {
            $this->warn("  M1 gap: purchase #{$gap->id} ({$gap->part_name}) installed {$gap->installed_at} — no component. Reconcile against laravel.log.");
        }

        $this->line($this->recommendation(count($failures), $gaps->count(), $summary['unresolved_provisional']));

        return count($failures) || $gaps->count() ? self::FAILURE : self::SUCCESS;
    }

    private function quarantine(string $ids): int
    {
        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->error('--quarantine requires --reason="why these rows are known-bad".');

            return self::FAILURE;
        }

        $rows = VehicleComponent::whereIn('id', array_filter(array_map('intval', explode(',', $ids))))->get();
        foreach ($rows as $c) {
            $c->validation_status = VehicleComponent::VALIDATION_QUARANTINED;
            $c->save();
            $this->warn("  quarantined component #{$c->id} — {$reason}");
        }
        $this->info($rows->count() . ' row(s) quarantined (kept for audit, excluded from reads). Record the reason in the shadow log.');

        return self::SUCCESS;
    }

    private function recommendation(int $failures, int $gaps, int $provisional): string
    {
        if (! $failures && ! $gaps && ! $provisional) {
            return 'Recommended action: CLEAN — nothing outstanding.';
        }
        if (! $failures && ! $gaps) {
            return "Recommended action: {$provisional} clean provisional row(s) awaiting promotion — run with --promote at the gate.";
        }

        return 'Recommended action: '
            . ($gaps ? "reconcile {$gaps} M1 gap(s) against laravel.log; " : '')
            . ($failures ? "fix via ComponentService or quarantine {$failures} mismatched row(s); " : '')
            . 'then re-run.';
    }
}
