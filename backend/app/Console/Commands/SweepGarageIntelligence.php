<?php

namespace App\Console\Commands;

use App\Services\Garage\GarageIntelligenceService;
use App\Support\GarageSeverity;
use Illuminate\Console\Command;

/**
 * The rolling-window safety net behind the real-time Garage Intelligence evaluation.
 *
 * The live path (OperationsService::reconcileVehicleOperationalStatus) fires when a car's garage
 * occupancy CHANGES. But the 30-day window rolls whether or not anything happens, and two things
 * move a car's grade with no event to hang off:
 *
 *   • a car still sitting in a garage accrues downtime every hour, so it can climb into `high` or
 *     `critical` purely because time passed;
 *   • an old visit ages past the window, so a car can fall from `high` back to `warning` — or out of
 *     the alerting bands altogether — with nobody touching it.
 *
 * This sweep re-reads every car and lets the SAME escalation rule decide, so it corrects stale states
 * without ever re-announcing a level already announced. It does not replace the event-driven path; it
 * exists because time is a change the event-driven path cannot see.
 *
 * `--quiet-notifications` refreshes every stored reading while telling nobody — the safe way to
 * backfill on first install, when every already-abnormal car would otherwise alert at once.
 */
class SweepGarageIntelligence extends Command
{
    protected $signature = 'garage:intelligence-sweep
                            {--quiet-notifications : refresh the stored readings without raising any alert}
                            {--vehicle=* : limit to these vehicle ids}';

    protected $description = "Re-read every car's garage visit-frequency and downtime, escalating only where the level has genuinely risen.";

    public function handle(GarageIntelligenceService $intel): int
    {
        if (! $intel->enabled()) {
            $this->warn('Garage Intelligence is disabled (garage_intelligence.enabled) — nothing to do.');

            return self::SUCCESS;
        }

        $notify = ! $this->option('quiet-notifications');
        $ids    = array_values(array_filter(array_map('intval', (array) $this->option('vehicle'))));

        $this->info("Re-reading garage behaviour over the last {$intel->windowDays()} days"
            . ($notify ? '' : ' (silent — no alerts will be raised)') . '…');

        $r = $intel->sweep($ids ?: null, $notify);

        $this->line("  evaluated: {$r['evaluated']} · needing attention: {$r['attention']} · alerts raised: {$r['notified']}");

        if ($notify && $r['notified'] > 0 && $intel->recipients()->isEmpty()) {
            $this->warn('  …but no recipient is configured — see config/garage_intelligence.php.');
        }

        // A quick look at who is worst, so the nightly log is readable without opening the app.
        $worst = \App\Models\VehicleGarageAlertState::needingAttention()
            ->with('vehicle:id,plate_no,make,model')
            ->orderByDesc('downtime_seconds')
            ->limit(5)
            ->get();

        foreach ($worst as $s) {
            $this->line(sprintf(
                '  %-10s %-28s %s · %d visits · %.1f days (%s%%)',
                $s->vehicle?->plate_no ?: '—',
                trim(($s->vehicle?->make ?? '') . ' ' . ($s->vehicle?->model ?? '')) ?: '—',
                str_pad(strtoupper(GarageSeverity::normalise($s->severity)), 8),
                $s->visits,
                $s->downtime_seconds / 86400,
                $s->downtime_pct
            ));
        }

        return self::SUCCESS;
    }
}
