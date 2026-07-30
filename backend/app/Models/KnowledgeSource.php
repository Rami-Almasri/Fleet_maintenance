<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One body of automotive knowledge the engine may draw on — and, critically, HOW it may draw on it.
 *
 * `access` is the field that keeps this system on the right side of a content licence:
 *
 *  - `licensed`   ALLDATA, Mitchell 1, Haynes, Chilton, OEM factory service manuals. Paid,
 *                 copyrighted, and reachable ONLY through a subscription the fleet holds. The
 *                 retriever skips a licensed source that has no credentials configured — it never
 *                 falls back to scraping the public site, which is what a licence forbids.
 *  - `public_web` Government TSB/recall databases, manufacturer public technical pages, supplier
 *                 technical libraries (Bosch, Denso, NGK), SAE abstracts. Retrievable today, via
 *                 web search constrained to this source's `domains` allowlist.
 *  - `uploaded`   PDFs and manuals the fleet itself owns and has ingested into the corpus.
 *  - `derived`    Our own maintenance history — see [[FleetEvidenceService]].
 *
 * `tier` and `trust_weight` drive retrieval ranking and the confidence a generated claim inherits:
 * an OEM procedure outranks a supplier catalogue outranks a general reference.
 */
class KnowledgeSource extends Model
{
    public const TIER_OEM          = 'tier1_oem';
    public const TIER_PROFESSIONAL = 'tier2_professional';
    public const TIER_REFERENCE    = 'tier3_reference';
    public const TIER_PUBLIC       = 'tier4_public';
    public const TIER_FLEET        = 'fleet';

    public const ACCESS_LICENSED   = 'licensed';
    public const ACCESS_PUBLIC_WEB = 'public_web';
    public const ACCESS_UPLOADED   = 'uploaded';
    public const ACCESS_DERIVED    = 'derived';

    protected $fillable = [
        'key', 'name', 'publisher', 'tier', 'trust_weight', 'access',
        'domains', 'base_url', 'notes', 'is_active',
    ];

    protected $casts = [
        'domains'      => 'array',
        'trust_weight' => 'integer',
        'is_active'    => 'boolean',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(KnowledgeDocument::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where($q->getModel()->getTable().'.is_active', true);
    }

    /**
     * Sources the live web retriever is allowed to search right now: public-web only, active, and
     * carrying at least one domain. A licensed source is deliberately excluded from this scope —
     * its content comes through its own API integration or an ingested corpus, never a web fetch.
     */
    public function scopeWebRetrievable(Builder $q): Builder
    {
        return $q->active()
            ->where('access', self::ACCESS_PUBLIC_WEB)
            ->whereNotNull('domains');
    }

    /** Every allow-listed domain across the retrievable sources, flattened for the search tool. */
    public static function allowedDomains(): array
    {
        return self::query()
            ->webRetrievable()
            ->pluck('domains')
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** True when this source is licensed but no credentials are configured — i.e. unusable, legally. */
    public function isLicensedButUnconfigured(): bool
    {
        return $this->access === self::ACCESS_LICENSED
            && blank(config("keyword_ai.licensed_sources.{$this->key}.api_key"));
    }
}
