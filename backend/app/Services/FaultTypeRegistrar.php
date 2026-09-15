<?php

namespace App\Services;

use App\Models\FaultCatalog;
use App\Models\FindingKeyword;
use App\Models\MaintenanceTask;
use App\Support\TextNormalizer;
use Illuminate\Support\Str;

/**
 * Adding a fault is ONE act, wherever it is performed.
 *
 * ── THE GAP THIS CLOSES ──────────────────────────────────────────────────────────────────────────
 * A fault type is two rows in two tables, and until now each admin page wrote only its own:
 *
 *   finding_keywords   what the word MEANS and how serious it is — risk grade, Arabic, the canonical
 *                      terms the matcher searches. Written by the Keyword Risk Library.
 *   fault_catalog      whether the word can be TAPPED, and what a task typed from it is — kind=fault,
 *                      severity prefill, the thing recurrence groups on. Written by Fault Types, and
 *                      the only table SelectableFindings unions onto the authored config.
 *
 * So "Add Keyword" produced a fault the engine could recognise in a sentence and nobody could select:
 * the library counted 111, the picker offered 109, and searching the new word in the findings picker
 * returned "0 matching issues" beside a suggestion card explaining that the garage records this one
 * during the repair — which was not true, it was simply half-added. The mirror hole is just as real:
 * a fault added on the Fault Types page was tappable and carried no risk grade, so it was invisible in
 * the library that grades severity.
 *
 * `findings:vocabulary-check` has always called the first of those a DEAD END and failed on it. That
 * check was right and the form that created them was wrong, so the fix belongs at the write, not at
 * the report: each door now writes BOTH rows, and there is one place that decides what the twin looks
 * like rather than two forms that will drift.
 *
 * ── WHAT IT REFUSES TO DO ────────────────────────────────────────────────────────────────────────
 * It will not give a SERVICE wording a fault row. "Coolant service" is planned work; typing it as a
 * fault would put every oil change into Top Faults, recurrence and the reliability score — the exact
 * confusion docs/Service-vs-Fault-Domain-Separation.md exists to prevent. The caller is told, and says
 * so, rather than silently creating either the wrong row or another dead end.
 *
 * It never invents vocabulary. A twin carries the word's own name and Arabic and nothing more; the
 * synonyms and workshop slang are authored in `database/seeders/ontology/*.php`, and `has_vocabulary`
 * keeps reporting the gap until somebody writes them.
 *
 * It never renames a word the CONFIG authors, and never retires one. Those files re-assert themselves
 * on the next deploy, so a twin write that contradicts them would silently revert.
 */
class FaultTypeRegistrar
{
    public function __construct(
        private SelectableFindings $selectable,
        private FaultLocationService $locations,
    ) {
    }

    /**
     * Is this wording planned work rather than a defect?
     *
     * Answered by the same classifier intake uses, so the picker and this gate cannot disagree about
     * which lane a word belongs to.
     */
    public function isServiceWording(?string $text): bool
    {
        return $this->locations->isServiceText($text);
    }

    /**
     * The fault type behind a library keyword — created if the picker does not already offer the word.
     *
     * Returns null when nothing needed doing (the config already offers it) and throws nothing when it
     * cannot act; a service wording is refused by the CALLER, which has a user to tell.
     */
    public function ensureFaultType(FindingKeyword $keyword): ?FaultCatalog
    {
        $normalised = TextNormalizer::key($keyword->keyword);

        if ($normalised === '' || $this->isServiceWording($keyword->keyword)) {
            return null;
        }

        // A retired row for this exact word comes BACK rather than a second row for one concept being
        // created beside it — two rows for one fault is what splits its recurrence history in half.
        if ($row = $this->catalogRowNamed($normalised)) {
            if (! $row->is_active) {
                $row->update(['is_active' => true, 'edited_in_app' => true]);
            }

            return $row;
        }

        // Already tappable because the config authors the word. The picker is what matters and it is
        // satisfied; adding a DB row here would only duplicate the chip.
        if (isset($this->selectable->keywords()[$normalised])) {
            return null;
        }

        $fault = FaultCatalog::create([
            'slug'             => $this->uniqueSlug($keyword->keyword),
            'name'             => trim($keyword->keyword),
            'name_ar'          => filled($keyword->keyword_ar) ? trim($keyword->keyword_ar) : null,
            'category_key'     => $keyword->category_key,
            // The two vocabularies are the same three words (routine / moderate / critical), which is
            // why this is a copy and not a mapping table nobody would maintain.
            'default_severity' => in_array($keyword->risk, FindingKeyword::RISKS, true)
                ? $keyword->risk
                : FindingKeyword::RISK_MODERATE,
            'on_site'          => $this->categoryIsOnSite($keyword->category_key),
            'is_active'        => (bool) $keyword->is_active,
            'edited_in_app'    => true,
            'sort_order'       => (int) (FaultCatalog::max('sort_order') ?? 0) + 10,
        ]);

        // The memoised menu is invalidated by FaultCatalog's saved/deleted hook, not from here — every
        // writer must invalidate it and only the model sees all of them.
        return $fault;
    }

    /**
     * The library keyword behind a fault type — created if no row already knows the word.
     *
     * This is what gives a fault added on the Fault Types page a risk grade and a searchable canonical
     * term, so it appears in the library that grades severity instead of only in the picker.
     */
    public function ensureKeyword(FaultCatalog $fault): ?FindingKeyword
    {
        $normalised = TextNormalizer::key($fault->name);

        if ($normalised === '') {
            return null;
        }

        if ($row = $this->keywordRowNamed($normalised)) {
            if (! $row->is_active && $fault->is_active) {
                $row->update(['is_active' => true]);
            }

            return $row;
        }

        $categories = collect(config('maintenance_findings.categories', []));
        $category   = $categories->firstWhere('key', $fault->category_key);

        $keyword = FindingKeyword::create([
            'category_key'      => $fault->category_key,
            'category_label'    => $category['label'] ?? $fault->category_key,
            'category_label_ar' => $category['label_ar'] ?? null,
            'keyword'           => trim($fault->name),
            'keyword_ar'        => filled($fault->name_ar) ? trim($fault->name_ar) : null,
            'risk'              => in_array($fault->default_severity, FindingKeyword::RISKS, true)
                ? $fault->default_severity
                : FindingKeyword::RISK_MODERATE,
            'is_active'         => (bool) $fault->is_active,
            'sort_order'        => (int) (FindingKeyword::where('category_key', $fault->category_key)->max('sort_order') ?? 0) + 10,
        ]);

        // Searchable immediately: its own EN/AR strings become terms before any enrichment runs.
        $keyword->syncCanonicalTerms();
        KeywordOntologyService::flushCache();

        return $keyword;
    }

    /**
     * The library half of a fault type, or null when no row knows the word.
     *
     * Exposed so a caller holding the catalog row can reach the fields only the library carries — the
     * detail line is the one a merged form edits — without re-implementing the normalised lookup and
     * eventually matching on raw text, which is how the second row gets created.
     */
    public function keywordFor(FaultCatalog $fault): ?FindingKeyword
    {
        return $this->keywordRowNamed(TextNormalizer::key($fault->name));
    }

    /**
     * Carry a rename / re-grade across to the twin, so the two tables cannot say different things about
     * one fault. A row the config authors is left alone: the file would assert its own wording back.
     */
    public function syncFaultTypeFrom(FindingKeyword $keyword, string $previousName): void
    {
        $row = $this->catalogRowNamed(TextNormalizer::key($previousName));

        if (! $row || $this->isAuthored($row->slug)) {
            return;
        }

        $row->update([
            'name'             => trim($keyword->keyword),
            'name_ar'          => filled($keyword->keyword_ar) ? trim($keyword->keyword_ar) : null,
            'category_key'     => $keyword->category_key,
            'default_severity' => $keyword->risk,
            'is_active'        => (bool) $keyword->is_active,
            'edited_in_app'    => true,
        ]);
    }

    /** The mirror: a rename on the Fault Types page reaches the library row. */
    public function syncKeywordFrom(FaultCatalog $fault, string $previousName): void
    {
        $row = $this->keywordRowNamed(TextNormalizer::key($previousName));

        if (! $row) {
            return;
        }

        $row->update([
            'keyword'      => trim($fault->name),
            'keyword_ar'   => filled($fault->name_ar) ? trim($fault->name_ar) : null,
            'category_key' => $fault->category_key,
            'risk'         => in_array($fault->default_severity, FindingKeyword::RISKS, true)
                ? $fault->default_severity
                : $row->risk,
            'is_active'    => (bool) $fault->is_active,
        ]);

        $row->syncCanonicalTerms();
        KeywordOntologyService::flushCache();
    }

    /**
     * A word leaving the library leaves the picker with it — otherwise deleting a keyword strands a
     * tappable chip with no grade behind it, which is the dead end in the other direction.
     *
     * Deleted only when nothing was ever typed from it (the "added by mistake" case). A type tasks
     * point at is retired instead: it is HOW those tickets were typed and the history has to keep
     * answering. An authored row is left entirely alone.
     */
    public function withdrawFaultTypeFor(FindingKeyword $keyword): void
    {
        $row = $this->catalogRowNamed(TextNormalizer::key($keyword->keyword));

        if (! $row || $this->isAuthored($row->slug)) {
            return;
        }

        if (MaintenanceTask::where('fault_catalog_id', $row->id)->exists()) {
            $row->update(['is_active' => false, 'edited_in_app' => true]);

            return;
        }

        $row->delete();
    }

    /**
     * A fault type leaving the picker takes its library row out of MATCHING with it — deactivated, not
     * deleted, because the row may carry hand-written terms and human feedback that explain why it
     * exists. `findings:vocabulary-check` reads active rows only, so a deactivated twin is not a dead end.
     */
    public function withdrawKeywordFor(FaultCatalog $fault): void
    {
        $row = $this->keywordRowNamed(TextNormalizer::key($fault->name));

        if (! $row || ! $row->is_active) {
            return;
        }

        $row->update(['is_active' => false]);
        KeywordOntologyService::flushCache();
    }

    /** Retire / restore both halves together. */
    public function syncActiveState(FaultCatalog $fault): void
    {
        $row = $this->keywordRowNamed(TextNormalizer::key($fault->name));

        if ($row && (bool) $row->is_active !== (bool) $fault->is_active) {
            $row->update(['is_active' => (bool) $fault->is_active]);
            KeywordOntologyService::flushCache();
        }
    }

    /**
     * Matched on the NORMALISED name, not the raw string: "A/C compressor fault" and "AC compressor
     * fault" are one concept to every other part of this system, and matching raw text here is how the
     * second row gets created.
     */
    private function catalogRowNamed(string $normalised): ?FaultCatalog
    {
        if ($normalised === '') {
            return null;
        }

        return FaultCatalog::query()
            ->get()
            ->first(fn (FaultCatalog $f) => TextNormalizer::key($f->name) === $normalised);
    }

    private function keywordRowNamed(string $normalised): ?FindingKeyword
    {
        if ($normalised === '') {
            return null;
        }

        return FindingKeyword::query()
            ->get()
            ->first(fn (FindingKeyword $k) => TextNormalizer::key($k->keyword) === $normalised);
    }

    /** Does config/fault_catalog.php still author this slug? Then the file, not this page, owns it. */
    private function isAuthored(?string $slug): bool
    {
        return $slug !== null
            && collect((array) config('fault_catalog', []))->pluck('slug')->contains($slug);
    }

    /**
     * Can this category's work be done where the car is parked? Read from the category rather than
     * asked, because the findings picker has always drawn that line per category, not per word.
     */
    private function categoryIsOnSite(?string $categoryKey): bool
    {
        foreach ($this->selectable->categories() as $category) {
            if (($category['key'] ?? null) === $categoryKey) {
                return (bool) ($category['on_site'] ?? false);
            }
        }

        return false;
    }

    /** A slug nothing else holds — derived, never typed, so one concept cannot become two. */
    private function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug(trim($name), '_') ?: 'fault', 60, '');
        $slug = $base;
        $n    = 2;

        while (FaultCatalog::where('slug', $slug)->exists()) {
            $slug = "{$base}_{$n}";
            $n++;
        }

        return $slug;
    }
}
