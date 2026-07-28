<?php

namespace App\Console\Commands;

use App\Models\Maintenance;
use App\Services\GarageRecommendationService;
use Illuminate\Console\Command;

/**
 * Audit the free-text → fault-category extractor (config/fault_extraction.php) against live data. Prints
 * how much of the historical backlog now carries a fault category, the per-category distribution, and a
 * sample of raw service_main/service_sup → assigned categories so the conservative map can be sanity-
 * checked and tuned before trusting the fault-based recommendations.
 *
 *   php artisan maintenance:fault-extraction-audit                 # coverage + 25 samples
 *   php artisan maintenance:fault-extraction-audit --samples=60    # more samples
 */
class MaintenanceFaultExtractionAudit extends Command
{
    protected $signature = 'maintenance:fault-extraction-audit {--samples=25 : How many raw→category samples to print}';

    protected $description = 'Audit the free-text fault-category extractor on live maintenance history';

    public function handle(GarageRecommendationService $svc): int
    {
        $total = 0;
        $withCat = 0;
        $dist = [];

        Maintenance::query()
            ->whereNotNull('vendor_id')
            ->whereNotNull('vehicle_id')
            ->select(['id', 'findings', 'service_main', 'service_sup'])
            ->chunk(1000, function ($chunk) use ($svc, &$total, &$withCat, &$dist) {
                foreach ($chunk as $m) {
                    $total++;
                    $cats = $svc->extractCategories($m->service_main, $m->service_sup, $m->findings);
                    if ($cats) {
                        $withCat++;
                        foreach ($cats as $c) {
                            $dist[$c] = ($dist[$c] ?? 0) + 1;
                        }
                    }
                }
            });

        $pct = $total ? round($withCat / $total * 100, 1) : 0;
        $this->info("Coverage: {$withCat} / {$total} maintenances got ≥1 category ({$pct}%)");
        $this->newLine();

        arsort($dist);
        $this->table(['Category', 'Records'], collect($dist)->map(fn ($c, $k) => [$k, $c])->values()->all());

        $this->newLine();
        $this->info('Sample raw → extracted categories:');
        foreach ($svc->previewExtraction((int) $this->option('samples')) as $s) {
            $raw = trim(trim((string) $s['service_main']) . '  /  ' . trim((string) $s['service_sup']), ' /');
            $cats = $s['categories'] ? implode(', ', $s['categories']) : '—';
            $this->line('  ' . str_pad('[' . $cats . ']', 34) . ' ' . mb_substr($raw, 0, 70));
        }

        return self::SUCCESS;
    }
}
