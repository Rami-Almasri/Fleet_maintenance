<?php

namespace App\Console\Commands;

use App\Models\Maintenance;
use App\Services\DiagnosticGateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One-off / re-runnable cleanup for the "System flagged — tap to confirm" row on OPEN inspection tickets.
 *
 * Background: the Proactive Diagnostic Monitor used to seed a car's post-downtime SAFETY CHECKLIST
 * (Battery / Fluids / Brakes) into `suggested_findings` — the same list an inspector taps to CONFIRM a
 * finding. That produced a contradiction: the app offered "confirm Battery Replacement", then the live
 * reality-check immediately said "…but the battery isn't due". The monitor no longer does this (see
 * InspectionsGenerateTasks::suggestedFindings, which now drops the post_downtime directive), but tickets
 * raised BEFORE that change still carry the misleading chips in their stored snapshot.
 *
 * This command rewrites those snapshots. For each open ticket it reads the ticket's OWN `trigger_detail`
 * (the immutable record of WHY it was raised) and removes only the keywords that originated SOLELY from a
 * post_downtime condition — every genuinely data-driven suggestion (an oil change / battery / tyre service
 * the car's data actually said was due) is preserved. A keyword produced by BOTH a downtime and a
 * data-driven condition is kept (it's still legitimately due). Tickets with no trigger_detail are left
 * untouched: without the origin record we can't tell a checklist chip from a real one, so we never guess.
 *
 * Safe by construction: it only ever REMOVES checklist-origin chips from suggested_findings, never adds,
 * never touches findings/tasks/status, and re-running it is idempotent.
 *
 *   php artisan inspections:clean-suggested-findings --dry-run   # preview, write nothing
 *   php artisan inspections:clean-suggested-findings             # rewrite the snapshots
 */
class InspectionsCleanSuggestedFindings extends Command
{
    protected $signature = 'inspections:clean-suggested-findings
        {--dry-run : Preview the tickets that would change, without writing anything}
        {--vehicle= : Restrict to a single vehicle id}
        {--ticket= : Restrict to a single ticket (maintenance) id}';

    protected $description = 'Strip post-downtime safety-checklist chips (not actually due) from the suggested_findings snapshot of open inspection tickets, keeping every data-driven suggestion';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $query = Maintenance::query()
            ->openWorkflow()
            ->whereNotNull('trigger_detail')
            ->whereNotNull('suggested_findings');

        if ($this->option('vehicle') !== null) {
            $query->where('vehicle_id', (int) $this->option('vehicle'));
        }
        if ($this->option('ticket') !== null) {
            $query->where('id', (int) $this->option('ticket'));
        }

        $scanned = 0;
        $changed = 0;
        $rows    = [];

        $query->orderBy('id')->chunkById(200, function ($tickets) use ($dry, &$scanned, &$changed, &$rows) {
            foreach ($tickets as $ticket) {
                $scanned++;

                $current = array_values(array_filter((array) ($ticket->suggested_findings ?? [])));
                if ($current === []) {
                    continue;
                }

                $cleaned = $this->stripChecklistOnly($current, (array) ($ticket->trigger_detail ?? []));

                // Nothing to remove (order-insensitive comparison of the two keyword sets).
                if ($this->sameSet($current, $cleaned)) {
                    continue;
                }

                $removed = array_values(array_diff($current, $cleaned));
                $rows[]  = [
                    $ticket->id,
                    $ticket->vehicle_id,
                    $ticket->workflow_status,
                    implode(', ', $removed) ?: '—',
                    implode(', ', $cleaned) ?: '(none)',
                ];
                $changed++;

                if (! $dry) {
                    // Writes only the one column; no model events / status side effects.
                    $ticket->suggested_findings = $cleaned;
                    $ticket->save();
                }
            }
        });

        if ($rows) {
            $this->table(['Ticket', 'Vehicle', 'Status', 'Removed (checklist-only)', 'Kept (data-driven)'], $rows);
        }

        $verb = $dry ? 'would be cleaned' : 'cleaned';
        $this->info("Scanned {$scanned} open ticket(s); {$changed} {$verb}.");

        Log::info('inspections:clean-suggested-findings ran', [
            'dry_run' => $dry,
            'scanned' => $scanned,
            'changed' => $changed,
        ]);

        return self::SUCCESS;
    }

    /**
     * Remove from $suggested every keyword whose ONLY origin in this ticket's trigger_detail is a
     * post_downtime condition. A keyword that any non-downtime (data-driven) condition also produced is
     * kept, as is any keyword the trigger_detail doesn't explain at all (we only strip what we can prove
     * came from the checklist). Case-insensitive throughout; original casing/order of kept items preserved.
     *
     * @param array<int,string>        $suggested
     * @param array<string,mixed>      $triggerDetail
     * @return array<int,string>
     */
    private function stripChecklistOnly(array $suggested, array $triggerDetail): array
    {
        $conditions = array_values(array_filter($triggerDetail['rules'] ?? [], 'is_array'));

        $downtime = $this->keywordSet($conditions, true);
        $data     = $this->keywordSet($conditions, false);

        // Checklist-only = flagged by a downtime condition and by NO data-driven condition.
        $checklistOnly = array_diff_key($downtime, $data);

        return array_values(array_filter(
            $suggested,
            fn ($k) => ! isset($checklistOnly[mb_strtolower((string) $k)]),
        ));
    }

    /**
     * The lowercased keyword set contributed by the conditions of the requested kind (downtime vs.
     * data-driven), as a lookup map [lower => true].
     *
     * @param array<int,array<string,mixed>> $conditions
     * @return array<string,bool>
     */
    private function keywordSet(array $conditions, bool $downtime): array
    {
        $set = [];
        foreach ($conditions as $c) {
            $isDowntime = ($c['directive'] ?? null) === DiagnosticGateService::DIRECTIVE_DOWNTIME;
            if ($isDowntime !== $downtime) {
                continue;
            }
            foreach ((array) ($c['finding_keywords'] ?? []) as $k) {
                if ($k !== null && $k !== '') {
                    $set[mb_strtolower((string) $k)] = true;
                }
            }
        }
        return $set;
    }

    /** Order-insensitive, case-insensitive equality of two keyword lists. */
    private function sameSet(array $a, array $b): bool
    {
        $norm = static function (array $x) {
            $x = array_map(fn ($k) => mb_strtolower((string) $k), $x);
            sort($x);
            return $x;
        };
        return $norm($a) === $norm($b);
    }
}
