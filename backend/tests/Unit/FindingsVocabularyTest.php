<?php

namespace Tests\Unit;

use App\Support\TextNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The findings catalog and the fault ontology must stay one vocabulary.
 *
 * `findings:vocabulary-check` asserts this against the live database as an operational check. These
 * tests assert the same invariant against the CONFIG AND SEEDER FILES ALONE — no database, so they
 * run in CI on a checkout and fail on the pull request that introduces the drift rather than on the
 * machine that later seeds it.
 *
 * Every one of these corresponds to a bug that actually shipped:
 *   · 'Tire Rotation' vs 'Tyre rotation' — one letter forked a fault into an enriched-but-unselectable
 *     row and a selectable row with no vocabulary at all.
 *   · 34 ontology concepts with no catalog entry — the matcher could confidently propose a fault the
 *     inspector had no way to select.
 *   · 'Transmission fluid leak' filed under two different categories — the two seeders fought over the
 *     row and died on a unique index mid-run.
 *   · 'Brake-fluid leak' under an on_site category — a hydraulic failure on a safety-critical system
 *     offered as a repair to do where the car is parked.
 */
class FindingsVocabularyTest extends TestCase
{
    /** @return array<string,string> normalised key → label */
    private function catalog(): array
    {
        $out = [];

        foreach ((array) config('maintenance_findings.categories', []) as $category) {
            foreach ((array) ($category['keywords'] ?? []) as $keyword) {
                $out[TextNormalizer::key($keyword)] = $keyword;
            }
        }

        return $out;
    }

    /** @return array<string,string> normalised key → label */
    private function understandingOnly(): array
    {
        $out = [];

        foreach ((array) config('maintenance_findings.understanding_only', []) as $keyword) {
            $out[TextNormalizer::key($keyword)] = $keyword;
        }

        return $out;
    }

    /** @return array<string,array{name:string,category:string}> normalised key → concept */
    private function ontology(): array
    {
        $out = [];

        foreach (glob(database_path('seeders/ontology/*.php')) ?: [] as $path) {
            foreach ((array) require $path as $concept) {
                if (isset($concept['name'])) {
                    $out[TextNormalizer::key($concept['name'])] = [
                        'name'     => $concept['name'],
                        'category' => $concept['category'] ?? '',
                    ];
                }
            }
        }

        return $out;
    }

    #[Test]
    public function every_selectable_keyword_has_an_ontology_concept(): void
    {
        $missing = array_values(array_diff_key($this->catalog(), $this->ontology()));

        $this->assertSame([], $missing, 'Selectable in the picker but no ontology concept describes it, '
            .'so it can only ever match its own name: '.implode(', ', $missing));
    }

    #[Test]
    public function every_ontology_concept_is_either_selectable_or_declared_understanding_only(): void
    {
        $ontology = $this->ontology();
        $known    = $this->catalog() + $this->understandingOnly();

        $orphans = array_values(array_map(
            fn (array $c) => $c['name'],
            array_diff_key($ontology, $known),
        ));

        $this->assertSame([], $orphans, 'Matchable by the engine but not offered by the picker and not '
            .'declared understanding-only — the AI can suggest these and the inspector cannot act on '
            .'them: '.implode(', ', $orphans));
    }

    #[Test]
    public function no_keyword_is_both_selectable_and_understanding_only(): void
    {
        $both = array_values(array_intersect_key($this->catalog(), $this->understandingOnly()));

        $this->assertSame([], $both, 'Declared both offerable and withheld: '.implode(', ', $both));
    }

    #[Test]
    public function the_catalog_and_the_ontology_agree_on_each_faults_category(): void
    {
        $catalog  = $this->catalog();
        $ontology = $this->ontology();
        $mismatch = [];

        foreach ((array) config('maintenance_findings.categories', []) as $category) {
            foreach ((array) ($category['keywords'] ?? []) as $keyword) {
                $key = TextNormalizer::key($keyword);

                if (isset($ontology[$key]) && $ontology[$key]['category'] !== ($category['key'] ?? '')) {
                    $mismatch[] = sprintf(
                        '%s (catalog=%s, ontology=%s)',
                        $catalog[$key], $category['key'] ?? '?', $ontology[$key]['category'],
                    );
                }
            }
        }

        // Not cosmetic: FindingKeywordSeeder and FaultOntologySeeder both write category_key, so a
        // disagreement makes them fight over the same row — and the category carries `on_site`, which
        // decides whether the fault may be offered as a mobile repair.
        $this->assertSame([], $mismatch, 'Filed under different categories: '.implode(' · ', $mismatch));
    }

    #[Test]
    public function every_routine_service_keyword_is_actually_selectable(): void
    {
        $catalog = $this->catalog();
        $missing = [];

        foreach (array_keys((array) config('maintenance_findings.routine_service_types', [])) as $keyword) {
            if (! isset($catalog[TextNormalizer::key($keyword)])) {
                $missing[] = $keyword;
            }
        }

        // The routine bridge rolls a Service Reminder forward when its finding is marked fixed. A key
        // here that nobody can select is a reminder loop that silently never closes — which is exactly
        // what 'tire rotation' was while the catalog and ontology disagreed on how to spell it.
        $this->assertSame([], $missing, 'Mapped to a Service Reminder but not selectable: '.implode(', ', $missing));
    }
}
