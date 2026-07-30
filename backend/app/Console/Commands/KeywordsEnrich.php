<?php

namespace App\Console\Commands;

use App\Models\FindingKeyword;
use App\Models\KeywordEnrichmentRun;
use App\Services\KeywordAiEnrichmentService;
use Illuminate\Console\Command;

/**
 * Build (or refresh) the AI automotive knowledge base for the findings keyword library.
 *
 * This is the batch half of [[KeywordAiEnrichmentService]] — one model call per keyword, writing
 * surface forms and the engineering profile. Nothing on a request path calls it.
 *
 *   php artisan keywords:enrich --all                  # the whole library (skips already-enriched)
 *   php artisan keywords:enrich --keyword=12           # one concept, by id
 *   php artisan keywords:enrich --category=brakes      # one findings category
 *   php artisan keywords:enrich --all --force          # re-run everything from scratch
 *   php artisan keywords:enrich --stale=90             # only entries not refreshed in 90 days
 *   php artisan keywords:enrich --keyword=12 --dry-run # show what WOULD be written, write nothing
 *
 * Safe to re-run and safe to schedule: terms upsert on their normalised form, human-edited rows are
 * never overwritten, and `--stale` means a nightly job only pays for what has actually aged out.
 */
class KeywordsEnrich extends Command
{
    protected $signature = 'keywords:enrich
        {--keyword= : Enrich a single finding keyword by id}
        {--category= : Limit to one findings category key (engine, brakes, …)}
        {--all : Enrich the whole library}
        {--stale= : Only re-enrich entries whose last successful run is older than N days}
        {--limit= : Stop after this many keywords (useful for a costed first pass)}
        {--force : Re-enrich even entries that already have a profile}
        {--dry-run : Call the model and print the result without writing anything}';

    protected $description = 'Generate the AI keyword knowledge base (synonyms, workshop wording, Arabic, metadata)';

    public function handle(KeywordAiEnrichmentService $service): int
    {
        if (! KeywordAiEnrichmentService::isConfigured()) {
            $this->error('ANTHROPIC_API_KEY is not set. Add it to .env before running enrichment.');

            return self::FAILURE;
        }

        $keywords = $this->targets();

        if ($keywords->isEmpty()) {
            $this->info('Nothing to enrich — every matching keyword is already up to date.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            '%s %d keyword(s) with %s%s',
            $dryRun ? 'Previewing' : 'Enriching',
            $keywords->count(),
            config('keyword_ai.model'),
            $dryRun ? ' (dry run — nothing will be written)' : ''
        ));
        $this->newLine();

        $ok = $failed = $terms = 0;
        $inTokens = $outTokens = 0;

        foreach ($keywords as $keyword) {
            $this->output->write(str_pad("  {$keyword->category_label} › {$keyword->keyword}", 58, '.'));

            $run = $service->enrich($keyword, dryRun: $dryRun);

            $inTokens  += $run->input_tokens;
            $outTokens += $run->output_tokens;

            if ($run->status === KeywordEnrichmentRun::STATUS_SUCCESS) {
                $ok++;
                $terms += $run->terms_added;
                $this->line(sprintf(
                    ' <fg=green>ok</> %d new, %d updated (%.1fs)',
                    $run->terms_added,
                    $run->terms_updated,
                    $run->duration_ms / 1000
                ));

                if ($dryRun) {
                    $this->previewPayload($run->preview);
                }
            } else {
                $failed++;
                $this->line(' <fg=red>failed</> '.$run->error);
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Done: %d enriched, %d failed, %d new terms. Tokens: %s in / %s out.',
            $ok, $failed, $terms, number_format($inTokens), number_format($outTokens)
        ));

        if ($failed > 0) {
            $this->comment('Failed runs are logged in keyword_enrichment_runs — re-run to retry them.');
        }

        return $failed > 0 && $ok === 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Resolve the flags into the set of keywords to process. */
    private function targets()
    {
        $query = FindingKeyword::query()->orderBy('category_label')->orderBy('sort_order');

        if ($id = $this->option('keyword')) {
            $query->whereKey($id);
        } elseif ($category = $this->option('category')) {
            $query->where('category_key', $category);
        } elseif (! $this->option('all')) {
            $this->warn('No target given — defaulting to --all. Use --keyword= or --category= to narrow.');
        }

        // `--stale=N` re-runs entries whose knowledge has aged out; without --force, anything that
        // already has a profile is skipped so a repeated `--all` costs nothing.
        if ($days = $this->option('stale')) {
            $query->whereHas('profile', fn ($q) => $q->where('enriched_at', '<', now()->subDays((int) $days)));
        } elseif (! $this->option('force') && ! $this->option('keyword')) {
            $query->whereDoesntHave('profile');
        }

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        return $query->get();
    }

    /** Dry-run output: the terms and headline metadata the run would have written. */
    private function previewPayload(array $payload): void
    {
        if ($payload === []) {
            return;
        }

        $this->line(sprintf(
            '      <fg=gray>%s › %s · severity %s · confidence %d%%</>',
            $payload['vehicle_system'] ?? '?',
            $payload['subsystem'] ?? '?',
            $payload['severity_estimate'] ?? '?',
            $payload['confidence'] ?? 0
        ));

        foreach ($payload['terms'] ?? [] as $t) {
            $this->line(sprintf(
                '      <fg=gray>[%-16s %s]</> %s <fg=gray>(%d%%, %s)</>',
                $t['kind'] ?? '?',
                $t['lang'] ?? '?',
                $t['term'] ?? '',
                $t['confidence'] ?? 0,
                $t['workshop_frequency'] ?? '?'
            ));
        }
        $this->newLine();
    }
}
