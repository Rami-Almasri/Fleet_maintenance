<?php

namespace App\Services;

use App\Models\FaultCatalog;
use App\Support\TextNormalizer;

/**
 * THE one answer to "what can an inspector actually tap?".
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────────────────────────────
 * Selectability used to be `config/maintenance_findings.php` and nothing else. That made adding a
 * fault a DEPLOY: the Knowledge page could give a word meaning (terms, synonyms, risk) but could not
 * make it appear in the picker, so a fault added there was matchable, enrichable — and untappable.
 * The word looked added and was invisible at the only step that matters.
 *
 * A config file cannot be the answer for a thing the office needs to change, because a PHP file inside
 * a built image is not editable at runtime. So selectability moves to a UNION:
 *
 *     selectable = config categories  ∪  active fault_catalog rows, filed by category_key
 *
 * The union is what makes this additive rather than a migration. Nothing is taken away: every word the
 * config offers still ships, in its authored order, whether or not a catalog row exists behind it.
 * Rows added in the app land in the same category and simply follow. Turning a fault off in the admin
 * page removes only rows the DB owns — a config word is authored, and the file is where it is unmade.
 *
 * ── WHAT THIS IS NOT ─────────────────────────────────────────────────────────────────────────────
 * Not the vocabulary. This says a word may be OFFERED; `database/seeders/ontology/*.php` says what the
 * word MEANS, and `findings:vocabulary-check` still asserts that every offered word has meaning behind
 * it. A fault created in the app is selectable immediately and has no ontology concept until somebody
 * writes one — which is a real gap, reported by the check and surfaced on the page, not hidden.
 *
 * ── WHY A SERVICE AND NOT A HELPER ON EACH CALLER ────────────────────────────────────────────────
 * Three places decide selectability and they must never disagree: the picker that renders the chips
 * (MaintenanceWorkflowController::findingsCatalog), the resolve gate that decides whether the AI may
 * propose a word (FindingKeywordController::selectableKeywords), and the contract check that fails the
 * build (FindingsVocabularyCheckCommand). When those three drifted apart before, the symptom was an
 * engine confidently proposing a fault the inspector had nowhere to tap.
 */
class SelectableFindings
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $categories = null;

    /**
     * The category-grouped menu, in the shape the picker already consumes:
     * [{ key, label, label_ar, on_site, keywords: string[] }]
     *
     * Config keywords keep their authored ORDER and come first — that ordering is a curation decision
     * (most-reported first, not alphabetical) and re-sorting the whole list around DB additions would
     * quietly undo it. App-added rows follow, ordered by `sort_order` then name.
     */
    public function categories(): array
    {
        if ($this->categories !== null) {
            return $this->categories;
        }

        $categories = array_values((array) config('maintenance_findings.categories', []));

        // Where each category sits in the list, and which words it already offers. Normalised, so a
        // catalog row that differs from the config only in case or punctuation is recognised as the
        // SAME word rather than appended as a near-duplicate chip beside it.
        $indexByKey = [];
        $seen       = [];

        foreach ($categories as $i => $category) {
            $key = $category['key'] ?? null;
            if ($key === null) {
                continue;
            }

            $indexByKey[$key] = $i;

            foreach ((array) ($category['keywords'] ?? []) as $keyword) {
                $seen[TextNormalizer::key($keyword)] = true;
            }
        }

        foreach ($this->appOwnedRows() as $row) {
            $index = $indexByKey[$row->category_key] ?? null;

            // A row filed under a category the config does not define has nowhere to be drawn. It stays
            // out of the picker rather than inventing a heading, and `findings:vocabulary-check` is
            // where that shows up — silently dropping it here with no report is how words get lost.
            if ($index === null) {
                continue;
            }

            $normalised = TextNormalizer::key($row->name);

            if ($normalised === '' || isset($seen[$normalised])) {
                continue;
            }

            $seen[$normalised] = true;
            $categories[$index]['keywords'][] = $row->name;
        }

        return $this->categories = $categories;
    }

    /**
     * Forget the memoised menu.
     *
     * The union is computed once per instance, which is right for a request that only READS it. A
     * request that ADDS a fault type reads it before the write (to decide whether the word is already
     * offered) and must not answer the same question from that stale copy afterwards — the caller
     * would report the new word as still unselectable.
     */
    public function flush(): void
    {
        $this->categories = null;
    }

    /**
     * Every selectable word, normalised for comparison → its display label.
     *
     * @return array<string, string>
     */
    public function keywords(): array
    {
        $out = [];

        foreach ($this->categories() as $category) {
            foreach ((array) ($category['keywords'] ?? []) as $keyword) {
                $out[TextNormalizer::key($keyword)] = $keyword;
            }
        }

        return $out;
    }

    /**
     * The words the config WITHHOLDS on purpose — `understanding_only`.
     *
     * The engine recognises these ("Periodic Maintenance", "Sensor failure") and the picker deliberately
     * does not offer them. That makes them unselectable and CORRECT, which is a different fact from a
     * word that is unselectable because only half of it was ever added. Anything reporting the second
     * has to be able to tell them apart, or it flags four rows nobody should touch and buries the one
     * that is actually broken.
     *
     * @return array<string, string> normalised key → declared label
     */
    public function withheld(): array
    {
        $out = [];

        foreach ((array) config('maintenance_findings.understanding_only', []) as $keyword) {
            $out[TextNormalizer::key($keyword)] = $keyword;
        }

        return $out;
    }

    /**
     * Which category a selectable word sits in, or null when it is not selectable.
     */
    public function categoryOf(string $normalisedKeyword): ?string
    {
        foreach ($this->categories() as $category) {
            foreach ((array) ($category['keywords'] ?? []) as $keyword) {
                if (TextNormalizer::key($keyword) === $normalisedKeyword) {
                    return $category['key'] ?? null;
                }
            }
        }

        return null;
    }

    /**
     * Active fault rows, ordered as they should APPEAR.
     *
     * Guarded like the classifier's maps: on a database where `fault_catalog` has not been migrated
     * yet, the picker must still render the authored config rather than take a hard QueryException at
     * the one screen the whole workflow starts from.
     *
     * @return \Illuminate\Support\Collection<int, FaultCatalog>
     */
    private function appOwnedRows()
    {
        try {
            return FaultCatalog::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'category_key', 'sort_order']);
        } catch (\Throwable $e) {
            return collect();
        }
    }
}
