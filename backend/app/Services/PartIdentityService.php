<?php

namespace App\Services;

use App\Models\ComponentCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * WHEN ARE TWO RECORDS THE SAME PART? This service is the single answer, and everything that warns
 * "you are buying this again" reads it.
 *
 * Evidence class: D (derived). Produces: the identity predicate + `matched_via` on every repeat
 * verdict. Consumes: component_catalog (name, name_ar, slug, identity_aliases) and the
 * component_catalog_id / part_name_key columns on part_requests and part_purchases.
 *
 * THE PROBLEM IT EXISTS FOR. Duplicate detection used to compare the typed string. That made
 * "Alternator" and "دينامو" two unrelated parts; it made "Shock Absorber — KYB" and
 * "Shock Absorber — Monroe" two unrelated parts, which on this database is most of the ledger; and it
 * made the alerts quietly useless while looking like they worked. A warning that fires only when two
 * people happen to type the same characters is not a control, it is a coincidence detector.
 *
 * THE LADDER, strongest first. A record matches if ANY rung matches — they are alternatives, not
 * requirements, because each covers a case the others cannot see.
 *
 *   1. catalog   — same component_catalog_id. Both sides were PICKED FROM THE LIST, so the wording
 *                  is irrelevant: a human already said which part this is. This is the rung the
 *                  whole feature is built to reach, and the reason the buying forms now use the
 *                  picker instead of a text box.
 *   2. name      — the stored `part_name_key` is one of the chosen part's known namings: its English
 *                  name, its Arabic name, its slug, or a curated other-name from
 *                  `identity_aliases` ('dynamo', 'فحمات', 'self'). This is what rescues the history
 *                  written before the picker existed, and it is why identity_aliases had to be split
 *                  out of `aliases` — see the config header.
 *   3. part number — the same SKU, collapsed. ONLY consulted when rung 1 is unavailable (no catalog
 *                  row known). Deliberate: part numbers here are hand-typed, differ per supplier for
 *                  the same component, and are sometimes placeholders — '1111' and '123' both appear
 *                  in this database. As a last resort for an unidentified part it is better than
 *                  nothing; added on TOP of a known catalog identity it would drag unrelated parts in
 *                  on a junk SKU, which is a false "you already bought this" — the one answer that
 *                  destroys trust in the warning fastest.
 *
 * WHAT IS NEVER IDENTITY. `aliases` — the search list — is ignored here completely, and so is any
 * fuzziness. There is no edit distance, no similarity score, no "closest" answer, for the same reason
 * PartCatalogMatcher refuses them: a symptom ('brake noise') is as true of the discs and the caliper
 * as of the pads, and a wrong match is worse than no match. No match means a buyer sees no warning
 * and buys a part — the status quo. A WRONG match means the app tells someone a repair failed when it
 * did not, and points at an innocent purchase and an innocent approver.
 *
 * AMBIGUITY IS REFUSED, NOT ARBITRATED. Identity surfaces are indexed across the WHOLE catalog first,
 * and any wording that turns up under two different rows is dropped from both. 'fan motor' is the
 * radiator fan and the A/C blower; 'bumper' is the front one and the rear one. The config already
 * keeps those out of identity_aliases by hand, but a hand can slip, and the cost of the slip would be
 * silent. So the runtime enforces it independently: a surface that cannot decide is not used by
 * anyone.
 */
class PartIdentityService
{
    /** How a stored record was recognised as the same part. */
    public const VIA_CATALOG     = 'catalog';
    public const VIA_NAME        = 'name';
    public const VIA_PART_NUMBER = 'part_number';
    public const VIA_NONE        = 'none';

    /** Separators that introduce a BRAND, not a different part. See {@see nameKey()}. */
    private const BRAND_SEPARATORS = ['—', '–', '|', ' - '];

    /** @var Collection<string,array<int,int>>|null normalised surface → catalog ids carrying it */
    private ?Collection $surfaceIndex = null;

    public function __construct(private PartCatalogMatcher $matcher) {}

    // ── the identity of the part in hand ────────────────────────────────────────────────────────

    /**
     * Describe the part someone is about to buy, so the rest of the system can ask "has this
     * happened before?" without knowing anything about wording.
     *
     * The catalog row is taken from `$catalogId` when the caller has one (the picker supplies it).
     * When it does not — an older record, or a part typed as free text — the wording is put through
     * PartCatalogMatcher, which is strict and returns nothing rather than guessing. A part that
     * resolves to no catalog row still gets an identity: its own name key, plus its SKU. It simply
     * cannot see the other spellings of itself, which is precisely the limitation the picker removes.
     *
     * @return array{
     *   catalog_id:int|null, catalog_name:string|null, catalog_slug:string|null,
     *   name_keys:array<int,string>, sku:string|null, resolved_via:string,
     *   other_names:array<int,string>, part_name:string|null
     * }
     */
    public function identityFor(?int $catalogId, ?string $partName, ?string $partNumber = null): array
    {
        $catalog = $catalogId ? ComponentCatalog::find($catalogId) : null;
        $via     = $catalog ? self::VIA_CATALOG : self::VIA_NONE;

        if (! $catalog && trim((string) $partName) !== '') {
            $hit = $this->matcher->resolve($partName);
            if ($hit['catalog_id']) {
                $catalog = ComponentCatalog::find($hit['catalog_id']);
                $via     = $catalog ? self::VIA_NAME : self::VIA_NONE;
            }
        }

        $ownKey   = $this->nameKey($partName);
        $nameKeys = $catalog ? $this->identityKeysFor($catalog) : [];

        // The record's own wording always counts, even when it is not one of the catalog row's known
        // namings. Someone wrote it down; it identifies at least itself.
        if ($ownKey !== null && ! in_array($ownKey, $nameKeys, true)) {
            $nameKeys[] = $ownKey;
        }

        return [
            'catalog_id'   => $catalog?->id,
            'catalog_name' => $catalog?->name,
            'catalog_slug' => $catalog?->slug,
            'name_keys'    => $nameKeys,
            'sku'          => $this->skuKey($partNumber),
            'resolved_via' => $via,
            // The other spellings this identity covers, for the UI to say so out loud rather than
            // leave the user wondering why a differently-named purchase was called the same part.
            'other_names'  => $catalog ? $this->displayOtherNames($catalog) : [],
            'part_name'    => $partName !== null ? trim($partName) : null,
        ];
    }

    // ── asking the database ─────────────────────────────────────────────────────────────────────

    /**
     * Narrow a part_purchases / part_requests query to THE SAME PART, by the ladder above.
     *
     * The rungs are OR-ed inside one nested group so the caller's other constraints (vehicle, date,
     * lock) keep their AND semantics — a bare chain of orWhere() here would leak past the vehicle
     * filter and match the whole fleet, which is the classic version of this bug.
     *
     * An identity with nothing to match on yields a query that matches NOTHING (1=0), never one that
     * matches everything. A part we cannot identify has no history we are entitled to claim.
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query
     */
    public function apply($query, array $identity)
    {
        $catalogId = $identity['catalog_id'] ?? null;
        $keys      = $identity['name_keys'] ?? [];
        $sku       = $identity['sku'] ?? null;

        // Rung 3 is a fallback, not an addition: consulted only when we have no catalog identity.
        $useSku = $sku !== null && $catalogId === null;

        if (! $catalogId && ! $keys && ! $useSku) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($w) use ($catalogId, $keys, $useSku, $sku) {
            if ($catalogId) {
                $w->orWhere('component_catalog_id', $catalogId);
            }
            if ($keys) {
                $w->orWhereIn('part_name_key', $keys);
            }
            if ($useSku) {
                $w->orWhereRaw("REPLACE(LOWER(part_number), ' ', '') = ?", [$sku]);
            }
        });
    }

    /**
     * Which rung recognised this particular row — so a warning can SAY why it fired.
     *
     * "You already bought this — it was logged as «دينامو» on 12 July" is something a buyer can
     * check and argue with. An unexplained warning gets clicked through, and then ignored.
     *
     * @param  object  $row  anything carrying component_catalog_id / part_name_key / part_number
     */
    public function matchedVia(object $row, array $identity): string
    {
        if (! empty($identity['catalog_id']) && (int) ($row->component_catalog_id ?? 0) === (int) $identity['catalog_id']) {
            return self::VIA_CATALOG;
        }

        $rowKey = $row->part_name_key ?? $this->nameKey($row->part_name ?? null);
        if ($rowKey !== null && in_array($rowKey, $identity['name_keys'] ?? [], true)) {
            return self::VIA_NAME;
        }

        $sku = $identity['sku'] ?? null;
        if ($sku !== null && $this->skuKey($row->part_number ?? null) === $sku) {
            return self::VIA_PART_NUMBER;
        }

        return self::VIA_NONE;
    }

    // ── normalisation ───────────────────────────────────────────────────────────────────────────

    /**
     * The stored form of a part's wording — what `part_name_key` holds and what identity compares.
     *
     * Three things are removed, each because it varies while the part does not:
     *
     *   the brand      "Shock Absorber — KYB" → shock absorber. A KYB shock and a Monroe shock are
     *                  the same part from two makers, and the fleet's own records are written in
     *                  exactly this "part — brand" shape: 3,542 of 3,552 purchase rows carry it.
     *                  Keeping the suffix is what made the repeat warning miss almost everything.
     *   the parenthesis "Brake Pads (set)" → brake pads, "A/C compressor (reman)" → ac compressor.
     *                  '(set)' is our own bookkeeping convention and '(reman)' is how the part was
     *                  sourced; neither makes it a different component. Buying a remanufactured
     *                  compressor a week after a new one is a repeat — arguably the most important
     *                  repeat there is.
     *   the punctuation via PartCatalogMatcher::normalize(), which also folds "A/C" to "ac" so the
     *                  abbreviation meets the catalog's spelling.
     *
     * NOT removed: position words. "Front Brake Pads" keeps its "front", so its key does not equal
     * "brake pads". That gap is closed by the catalog rung instead — the matcher resolves the phrase
     * to Brake Pads and the row carries the id — because stripping words that sometimes matter
     * (front/rear brake pads are bought separately) would trade a missed warning for a wrong one.
     */
    public function nameKey(?string $partName): ?string
    {
        $text = trim((string) $partName);
        if ($text === '') {
            return null;
        }

        $text = $this->stripBrandSuffix($text);
        $text = preg_replace('/\([^)]*\)/u', ' ', $text) ?? $text;

        $key = $this->matcher->normalize($text);

        // A name that was NOTHING BUT a brand suffix or a parenthetical ("(reman)") would normalise
        // to empty; fall back to the whole original rather than losing the row's identity entirely.
        return $key !== '' ? $key : ($this->matcher->normalize($partName) ?: null);
    }

    /**
     * Were two records SPELLED the same? Compares the wording as written, brand and all.
     *
     * Deliberately NOT nameKey(): that is the identity key, and it exists precisely to make
     * "Shock Absorber — KYB" and "Shock Absorber — Monroe" equal. Asked whether the two records LOOK
     * alike, it would answer yes to every brand pair and the "wording differed" count built on it
     * would be permanently zero — a metric that reports success by construction.
     */
    public function sameWording(?string $a, ?string $b): bool
    {
        return $this->matcher->normalize($a) === $this->matcher->normalize($b);
    }

    /** The collapsed SKU, or null when there is nothing usable. */
    public function skuKey(?string $partNumber): ?string
    {
        $sku = strtolower(preg_replace('/\s+/', '', (string) $partNumber) ?? '');

        return $sku !== '' ? $sku : null;
    }

    /**
     * Every identity key a catalog row answers to, ambiguous wordings already removed.
     *
     * @return array<int,string>
     */
    public function identityKeysFor(ComponentCatalog $catalog): array
    {
        $index = $this->index();
        $keys  = [];

        foreach ($catalog->identitySurfaces() as $surface) {
            $key = $this->nameKey($surface);
            if ($key === null || in_array($key, $keys, true)) {
                continue;
            }
            // The ambiguity refusal: a wording carried by two catalog rows identifies neither.
            if (count($index->get($key, [])) > 1) {
                continue;
            }
            $keys[] = $key;
        }

        return $keys;
    }

    /** Drop the cached surface index — call after the catalog is edited within the same process. */
    public function flush(): void
    {
        $this->surfaceIndex = null;
        $this->matcher->flush();
    }

    // ── internals ───────────────────────────────────────────────────────────────────────────────

    /**
     * Cut a trailing brand off a part name.
     *
     * Only separators that MEAN "and here is the maker" are honoured: an em or en dash, a pipe, or a
     * hyphen with spaces around it. A bare hyphen is left alone, because it lives inside real part
     * names ("anti-roll bar", "rack-and-pinion") where cutting at it would destroy the name instead
     * of trimming it.
     */
    private function stripBrandSuffix(string $text): string
    {
        foreach (self::BRAND_SEPARATORS as $sep) {
            $pos = mb_strpos($text, $sep);
            if ($pos !== false && $pos > 0) {
                $text = trim(mb_substr($text, 0, $pos));
            }
        }

        return $text;
    }

    /**
     * The other namings of a part, in the language-agnostic form the UI shows ("also known as").
     *
     * Only the ones that actually count — an ambiguous alias is not listed, because listing wording
     * the system then refuses to act on is how a feature earns a reputation for lying.
     *
     * @return array<int,string>
     */
    private function displayOtherNames(ComponentCatalog $catalog): array
    {
        $usable = $this->identityKeysFor($catalog);
        $own    = $this->nameKey($catalog->name);

        return collect($catalog->identitySurfaces())
            ->reject(fn ($s) => $this->nameKey($s) === $own)
            ->filter(fn ($s) => in_array($this->nameKey($s), $usable, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * normalised identity surface → every catalog id that claims it. Built once per request.
     *
     * Retired rows are INCLUDED on purpose. A retired part type is still what its history was, and a
     * name shared with a live row is still ambiguous — retiring one of two rows does not make the
     * wording decisive, it just hides one of the answers.
     *
     * @return Collection<string,array<int,int>>
     */
    private function index(): Collection
    {
        if ($this->surfaceIndex !== null) {
            return $this->surfaceIndex;
        }

        $index = collect();

        ComponentCatalog::query()
            ->get(['id', 'name', 'name_ar', 'slug', 'identity_aliases'])
            ->each(function (ComponentCatalog $c) use ($index) {
                foreach ($c->identitySurfaces() as $surface) {
                    // nameKey() is deliberately reused rather than a plainer normaliser: a surface
                    // and a stored part name must collapse the same way or they can never meet.
                    $key = $this->nameKey($surface);
                    if ($key === null) {
                        continue;
                    }
                    $ids = $index->get($key, []);
                    if (! in_array($c->id, $ids, true)) {
                        $ids[] = $c->id;
                    }
                    $index->put($key, $ids);
                }
            });

        return $this->surfaceIndex = $index;
    }
}
