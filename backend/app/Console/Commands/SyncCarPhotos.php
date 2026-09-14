<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Pull the hero photograph of every car model we operate from the public rental catalogue
 * (fastercars.ae), so the Fleet Registry shows the ACTUAL car rather than a stock picture.
 *
 *     php artisan cars:sync-photos [--dry-run] [--force]
 *
 * WHY AN IMPORT AND NOT A HAND-PICKED FOLDER
 * The first version of this shipped 31 files chosen by eye out of eight thousand, which meant the
 * set was only correct until someone repainted a car or added a model. The catalogue API already
 * answers the question properly: each car carries an ordered `attachments` list whose FIRST entry
 * is the hero shot the website itself leads with — a front-three-quarter view, shot consistently.
 * Taking that one, by sort order, is why the photographs now look like a set instead of a scrapbook.
 *
 * MATCHING IS CONSERVATIVE ON PURPOSE
 * A picture is a claim about which vehicle a row is. So a fleet model is matched to a catalogue car
 * only when every word of the model name appears in the catalogue title; ties break toward the
 * SHORTEST title, so "NISSAN PATROL" lands on "Nissan Patrol" and not on "Nissan Patrol Nismo
 * Silver Blue". Anything unmatched is left out of the manifest and falls back, in the browser, to
 * the marque logo and then a silhouette. A missing picture is a small gap; a wrong one is a lie.
 *
 * Writes:
 *   frontend/public/cars/<slug>.webp      — one hero image per matched model
 *   frontend/src/lib/carPhotoManifest.json — { "<normalised fleet model>": "<slug>" }
 *
 * @see \App\Services\FleetMixService for the other half of this page's data
 */
class SyncCarPhotos extends Command
{
    protected $signature = 'cars:sync-photos {--dry-run : Report the matches without downloading anything}
                                             {--force : Re-download images that are already on disk}';

    protected $description = 'Import each fleet model’s hero photograph from the fastercars.ae catalogue';

    private const API = 'https://www.fastercars.ae/api/cars';
    private const CDN = 'https://www.fastercars.ae/uploads/';

    /** Words that say nothing about which model a car is, and so must not earn a match on their own. */
    private const NOISE = ['rent', 'rental', 'dubai', 'car', 'cars', 'new', 'old', 'version', 'faster'];

    /**
     * The fleet sheet's abbreviations, spelled out to the catalogue's wording. Only expansions that
     * are unambiguous belong here: a "Conv" in the sheet is a convertible and nothing else, so
     * "MUSTANG Conv" can safely reach "Ford Mustang Convertible". This does NOT loosen the match —
     * a Camaro Conv still finds nothing, because the catalogue carries no convertible Camaro, and
     * putting one on a coupé photograph would be the wrong car.
     */
    private const SYNONYMS = ['conv' => 'convertible', 'coup' => 'coupe', 'cabrio' => 'convertible'];

    public function handle(): int
    {
        $root = base_path('../frontend');
        $imageDir = $root . '/public/cars';
        $manifestPath = $root . '/src/lib/carPhotoManifest.json';

        $this->info('Fetching catalogue…');
        $response = Http::timeout(60)->get(self::API);
        if (! $response->successful()) {
            $this->error("Catalogue returned HTTP {$response->status()}.");

            return self::FAILURE;
        }

        $catalogue = collect($response->json('data.cars') ?? [])
            ->map(fn (array $c) => [
                'slug'   => $c['slug'] ?? null,
                'title'  => trim(($c['make'] ?? '') . ' ' . ($c['title'] ?? '')),
                'tokens' => $this->tokens(($c['make'] ?? '') . ' ' . ($c['title'] ?? '')),
                'image'  => $this->heroImage($c['attachments'] ?? []),
            ])
            ->filter(fn (array $c) => $c['slug'] && $c['image'] && $c['tokens'])
            ->values();

        $this->line("  {$catalogue->count()} catalogue cars with a hero image.");

        $models = $this->fleetModels();
        $this->line("  {$models->count()} distinct models in the fleet.");

        if (! is_dir($imageDir)) {
            mkdir($imageDir, 0775, true);
        }

        $manifest = [];
        $wanted = [];      // slug => remote image filename, deduplicated across models
        $unmatched = [];

        foreach ($models as $key => $label) {
            $hit = $this->bestMatch($this->tokens($label), $catalogue);
            if (! $hit) {
                $unmatched[] = $label;
                continue;
            }
            $manifest[$key] = $hit['slug'];
            $wanted[$hit['slug']] = $hit['image'];
        }

        ksort($manifest);
        $this->info(sprintf('Matched %d of %d models onto %d distinct photographs.',
            count($manifest), $models->count(), count($wanted)));

        if ($unmatched) {
            $this->warn('No photograph for: ' . implode(', ', array_slice($unmatched, 0, 25))
                . (count($unmatched) > 25 ? ' …' : ''));
        }

        if ($this->option('dry-run')) {
            foreach ($manifest as $key => $slug) {
                $this->line("  {$key}  →  {$slug}");
            }

            return self::SUCCESS;
        }

        $downloaded = 0;
        $skipped = 0;
        foreach ($wanted as $slug => $remote) {
            $dest = "{$imageDir}/{$slug}.webp";
            if (file_exists($dest) && ! $this->option('force')) {
                $skipped++;
                continue;
            }
            $img = Http::timeout(60)->get(self::CDN . rawurlencode($remote));
            if (! $img->successful()) {
                $this->warn("  ✗ {$slug} — HTTP {$img->status()}");
                // The manifest must not point at a file we failed to fetch, or the row shows a
                // broken image where a marque logo would have been perfectly readable.
                $manifest = array_filter($manifest, fn ($s) => $s !== $slug);
                continue;
            }
            file_put_contents($dest, $img->body());
            $downloaded++;
        }

        file_put_contents(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        $this->info("Downloaded {$downloaded}, already present {$skipped}.");
        $this->info('Manifest written to frontend/src/lib/carPhotoManifest.json');

        return self::SUCCESS;
    }

    /**
     * The catalogue's own lead image: the attachment the site sorts first. Falls back to array
     * order when `sort` is absent, which is how a handful of older records are stored.
     */
    private function heroImage(array $attachments): ?string
    {
        if (! $attachments) {
            return null;
        }
        usort($attachments, fn ($a, $b) => ($a['sort'] ?? PHP_INT_MAX) <=> ($b['sort'] ?? PHP_INT_MAX));

        return $attachments[0]['name'] ?? null;
    }

    /**
     * The distinct models we actually operate, as normalisedKey => readable label.
     *
     * Both columns are folded together because the fleet sheet routinely puts the model in the make
     * column ("PATROL" / "QX80 BLACK HAWK"). Sold and disposed cars are included: a car that left
     * the fleet last month is still shown when someone filters for it, and it should still have a
     * picture. @see frontend/src/lib/carAssets.js, which normalises identically.
     */
    private function fleetModels(): \Illuminate\Support\Collection
    {
        return \DB::table('vehicles')
            ->whereNull('deleted_at')
            ->selectRaw('DISTINCT make, model')
            ->get()
            ->mapWithKeys(function ($row) {
                $label = trim(($row->make ?? '') . ' ' . ($row->model ?? ''));
                $key = implode(' ', $this->tokens($label));

                return $key ? [$key => $label] : [];
            });
    }

    /**
     * Words worth matching on. Drops the sheet's trailing grade code ("RIO / M"), punctuation, the
     * noise words above, and anything that is only digits — a bare "300" in "Chrysler 300" is
     * meaningful, but a stray "2021" from a title is not, so digits survive only when short.
     */
    private function tokens(string $s): array
    {
        $s = strtolower($s);
        $s = preg_replace('#\s*/\s*[a-z]\b.*$#', ' ', $s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);

        $words = array_map(
            fn ($w) => self::SYNONYMS[$w] ?? $w,
            explode(' ', trim($s))
        );

        return array_values(array_unique(array_filter(
            $words,
            fn ($w) => $w !== ''
                && ! in_array($w, self::NOISE, true)
                && ! preg_match('/^(19|20)\d{2}$/', $w)
        )));
    }

    /**
     * The shortest catalogue title that contains EVERY word of the fleet model.
     *
     * Containment in that direction is the whole safety property: "nissan patrol" matching "Nissan
     * Patrol Nismo" is fine (it is a Patrol), while "nissan patrol nismo" can never match a plain
     * "Nissan Patrol", because the Nismo is a different car. Shortest-wins then keeps the generic
     * model on the generic photograph.
     *
     * A single-word model is rejected outright — "T"/"C" rows in the sheet are data faults, and one
     * letter is not enough to identify a car.
     */
    private function bestMatch(array $needle, \Illuminate\Support\Collection $catalogue): ?array
    {
        if (count($needle) < 2) {
            return null;
        }

        return $catalogue
            ->filter(fn (array $c) => ! array_diff($needle, $c['tokens']))
            ->sortBy(fn (array $c) => count($c['tokens']))
            ->first();
    }
}
