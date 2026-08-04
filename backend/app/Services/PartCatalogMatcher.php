<?php

namespace App\Services;

use App\Models\ComponentCatalog;
use Illuminate\Support\Collection;

/**
 * Resolves free-typed part wording to a catalog row — the bridge from "what someone typed" to
 * "which part that is".
 *
 * Evidence class: D (derived). Produces: component_catalog_id on maintenance_required_parts.
 * Consumes: component_catalog (name, name_ar, aliases).
 *
 * ONE RULE ABOVE ALL: THIS MATCHER NEVER GUESSES. Every strategy below is exact on a normalised
 * string; there is no edit distance, no similarity score, no "closest" answer. A line it cannot
 * resolve stays NULL and is reported, because a required part silently attached to the wrong
 * catalog entry is worse than one attached to nothing: it would spend real money on the wrong part
 * and corrupt every count built on the catalog afterwards. Unmatched is a question for a human;
 * mismatched is a defect nobody notices.
 *
 * ALIASES ARE NOT USED FOR LINKING. This is the important asymmetry in the system. The catalog's
 * aliases deliberately mix other trade names ("dynamo") with SYMPTOM wording ("brake noise", "ac not
 * cooling"), because the search box wants both — a human types either and picks the right row from a
 * list. Linking has no human in the loop, and a symptom does not identify a part: "brake noise" is
 * as true of the discs and the caliper as of the pads. Matching on it would attach a specific part
 * to a complaint that never named one. So linking sees names, Arabic names and slugs only, while
 * ComponentCatalog::scopeSearch() (the picker) sees aliases too. Generous where a human decides,
 * strict where nobody does.
 *
 * The strategies, strongest first:
 *
 *   1. exact      — normalised text equals a catalog name, Arabic name or slug.
 *                   "brake pads (set)" → Brake Pads (set).
 *   2. core       — normalised text equals the catalog name with its parenthetical stripped.
 *                   "brake pads" → Brake Pads (set), because "(set)" is a bookkeeping convention of
 *                   ours, not part of what the part is called.
 *   3. phrase     — the typed text CONTAINS a catalog core as a whole phrase, and that core is the
 *                   longest such match. "front brake pads" → Brake Pads (set); the position word is
 *                   the inspector saying which end of the car, not a different part.
 *
 * Strategy 3 is the only one that tolerates extra words, and it is deliberately anchored on whole
 * words and resolved by longest match so "front brake discs" cannot land on "Brake Pads" merely
 * because both contain "brake". Where two catalog entries tie, the match is ABANDONED rather than
 * arbitrated — a tie means the wording genuinely does not distinguish them.
 */
class PartCatalogMatcher
{
    /** @var Collection<int,array>|null lazily built index of normalised needles → catalog id */
    private ?Collection $index = null;

    public const MATCH_EXACT  = 'exact';
    public const MATCH_CORE   = 'core';
    public const MATCH_PHRASE = 'phrase';
    public const MATCH_NONE   = 'none';

    /**
     * @return array{catalog_id:int|null, matched_by:string, candidate:string|null}
     */
    public function resolve(?string $text): array
    {
        $needle = $this->normalize($text);

        if ($needle === '') {
            return ['catalog_id' => null, 'matched_by' => self::MATCH_NONE, 'candidate' => null];
        }

        $index = $this->index();

        // 1 + 2: exact hit on any surface form (name / Arabic / alias / slug / parenthetical-free core).
        $exact = $index->firstWhere('needle', $needle);
        if ($exact) {
            return [
                'catalog_id' => $exact['catalog_id'],
                'matched_by' => $exact['kind'],
                'candidate'  => $exact['name'],
            ];
        }

        // 3: longest whole-phrase containment, over CORE forms only.
        $hits = $index
            ->where('kind', self::MATCH_CORE)
            ->filter(fn (array $row) => $this->containsPhrase($needle, $row['needle']))
            ->sortByDesc(fn (array $row) => mb_strlen($row['needle']))
            ->values();

        if ($hits->isEmpty()) {
            return ['catalog_id' => null, 'matched_by' => self::MATCH_NONE, 'candidate' => null];
        }

        $best = $hits->first();

        // A tie on length between DIFFERENT catalog entries means the wording does not tell them
        // apart. Refuse rather than pick one.
        $tied = $hits->filter(fn (array $r) => mb_strlen($r['needle']) === mb_strlen($best['needle']))
            ->pluck('catalog_id')
            ->unique();

        if ($tied->count() > 1) {
            return ['catalog_id' => null, 'matched_by' => self::MATCH_NONE, 'candidate' => null];
        }

        return [
            'catalog_id' => $best['catalog_id'],
            'matched_by' => self::MATCH_PHRASE,
            'candidate'  => $best['name'],
        ];
    }

    /**
     * Lowercase, strip punctuation to spaces, collapse whitespace.
     *
     * Arabic is left alone beyond that: it has no case, and stripping its diacritics here would
     * need a normaliser this codebase does not have. Latin punctuation ("A/C", "brake-pads") is
     * flattened because it is the main reason two spellings of one part fail to meet.
     */
    public function normalize(?string $text): string
    {
        $t = mb_strtolower(trim((string) $text));

        // A slash BETWEEN TWO SINGLE LETTERS is an abbreviation, not a word break: "A/C" is one
        // word and must normalise to "ac" so it meets the catalog's "AC Compressor". Treating it
        // like other punctuation yields "a c", which matches nothing. Done before the general
        // strip, and deliberately narrow — "front/rear" keeps its break because those are two words.
        $t = preg_replace('/(?<=^|\s)(\p{L})\/(\p{L})(?=\s|$|\p{L})/u', '$1$2', $t) ?? $t;

        $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t) ?? '';

        return trim(preg_replace('/\s+/', ' ', $t) ?? '');
    }

    /** Whole-word containment: "brake pads" is in "front brake pads", but "rake" is not. */
    private function containsPhrase(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }

        return (bool) preg_match('/(?:^|\s)'.preg_quote($needle, '/').'(?:\s|$)/u', $haystack);
    }

    /** Every searchable surface form of every ACTIVE catalog row, normalised once. */
    private function index(): Collection
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $rows = collect();

        ComponentCatalog::active()
            ->get(['id', 'name', 'name_ar', 'slug', 'aliases'])
            ->each(function (ComponentCatalog $c) use ($rows) {
                $add = function (?string $text, string $kind) use ($rows, $c) {
                    $n = $this->normalize($text);
                    if ($n !== '') {
                        $rows->push(['needle' => $n, 'catalog_id' => $c->id, 'name' => $c->name, 'kind' => $kind]);
                    }
                };

                // Names and slugs only. Aliases are excluded from linking entirely — see the class
                // docblock: they carry symptom wording, which names a complaint, not a part.
                $add($c->name, self::MATCH_EXACT);
                $add($c->name_ar, self::MATCH_EXACT);
                $add($c->slug, self::MATCH_EXACT);

                // The core: the name with any parenthetical removed. "Brake Pads (set)" → "brake pads".
                $core = preg_replace('/\([^)]*\)/u', ' ', (string) $c->name) ?? '';
                if ($this->normalize($core) !== $this->normalize($c->name)) {
                    $add($core, self::MATCH_CORE);
                } else {
                    // No parenthetical — the name itself is its own core, and is eligible for
                    // phrase containment ("front wheel bearing" → Wheel Bearing).
                    $add($c->name, self::MATCH_CORE);
                }
            });

        // Exact forms first so firstWhere() prefers a true exact over a core of the same string.
        return $this->index = $rows->sortBy(fn ($r) => $r['kind'] === self::MATCH_EXACT ? 0 : 1)->values();
    }

    /** Drop the cached index — call after the catalog is edited within the same process. */
    public function flush(): void
    {
        $this->index = null;
    }
}
