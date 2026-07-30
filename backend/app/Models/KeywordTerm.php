<?php

namespace App\Models;

use App\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One surface form of a findings keyword — a way a human might write the fault.
 *
 * The concept is [[FindingKeyword]]; this is everything else people call it. See the
 * create_keyword_terms_table migration for the full design. The one rule worth repeating here:
 * `normalized` is always derived, never supplied — the model writes it on save so a term inserted
 * by the AI service, a seeder, an admin form or tinker can never drift from the matcher's key.
 */
class KeywordTerm extends Model
{
    /** What kind of surface form this is. Drives match weighting and how the UI groups the chips. */
    public const KIND_CANONICAL        = 'canonical';        // the keyword itself
    public const KIND_SYNONYM          = 'synonym';          // "brake squeal" for "brake noise"
    public const KIND_WORKSHOP_PHRASE  = 'workshop_phrase';  // how a mechanic actually says it
    public const KIND_ABBREVIATION     = 'abbreviation';     // "A/C", "ABS", "TPMS"
    public const KIND_SPELLING_VARIANT = 'spelling_variant'; // tyre / tire, windscreen / windshield
    public const KIND_MISSPELLING      = 'misspelling';      // "radiater", "break noise"
    public const KIND_TRANSLATION      = 'translation';      // the Arabic wording

    /**
     * How a CUSTOMER describes it — "the car feels heavy", "it doesn't pull", "الموتر يسحب".
     *
     * Kept apart from `workshop_phrase` because the two vocabularies are genuinely different, not
     * two registers of one. A technician names the component; a customer describes a sensation, and
     * usually one that maps to several unrelated faults ("feels heavy" is steering, brakes, or
     * tyres). Tagging them separately is what lets a complaint be treated as a symptom to be
     * narrowed down rather than a diagnosis to be trusted.
     */
    public const KIND_CUSTOMER_PHRASE  = 'customer_phrase';

    public const KINDS = [
        self::KIND_CANONICAL, self::KIND_SYNONYM, self::KIND_WORKSHOP_PHRASE,
        self::KIND_ABBREVIATION, self::KIND_SPELLING_VARIANT, self::KIND_MISSPELLING,
        self::KIND_TRANSLATION, self::KIND_CUSTOMER_PHRASE,
    ];

    /** Presentation per kind — label + chip tone, mirrored by the frontend's KIND_META. */
    public const KIND_META = [
        self::KIND_CANONICAL        => ['label' => 'Canonical',  'tone' => 'indigo'],
        self::KIND_SYNONYM          => ['label' => 'Synonym',    'tone' => 'blue'],
        self::KIND_WORKSHOP_PHRASE  => ['label' => 'Workshop',   'tone' => 'violet'],
        self::KIND_ABBREVIATION     => ['label' => 'Abbrev.',    'tone' => 'cyan'],
        self::KIND_SPELLING_VARIANT => ['label' => 'Spelling',   'tone' => 'green'],
        self::KIND_MISSPELLING      => ['label' => 'Misspelling','tone' => 'amber'],
        self::KIND_TRANSLATION      => ['label' => 'Arabic',     'tone' => 'emerald'],
        self::KIND_CUSTOMER_PHRASE  => ['label' => 'Customer',   'tone' => 'rose'],
    ];

    /** Provenance. `human` is protective: enrichment refuses to overwrite a row an admin owns. */
    public const SOURCE_SEED  = 'seed';
    public const SOURCE_AI    = 'ai';
    public const SOURCE_HUMAN = 'human';
    public const SOURCES      = [self::SOURCE_SEED, self::SOURCE_AI, self::SOURCE_HUMAN];

    /** How often the wording is genuinely heard in a workshop — the strongest ranking signal. */
    public const FREQUENCIES = ['very_high', 'high', 'medium', 'low', 'rare'];

    /** Which class of documentation backs the term. */
    public const SOURCE_QUALITIES = ['oem', 'ase', 'manual', 'workshop', 'general'];

    protected $fillable = [
        'finding_keyword_id', 'term', 'normalized', 'lang', 'kind', 'confidence',
        'source', 'source_quality', 'workshop_frequency', 'search_rank', 'is_active',
    ];

    protected $casts = [
        'confidence'  => 'integer',
        'search_rank' => 'integer',
        'is_active'   => 'boolean',
    ];

    /**
     * Derive `normalized` and `search_rank` on every save. Doing it here rather than in the callers
     * is what guarantees the matcher and the stored key can never disagree — there is exactly one
     * place a term becomes a comparison key, no matter who inserted it.
     */
    protected static function booted(): void
    {
        static::saving(function (self $term) {
            $term->normalized = TextNormalizer::key($term->term);

            if ($term->lang === null || $term->lang === '') {
                $term->lang = TextNormalizer::isArabic($term->term) ? 'ar' : 'en';
            }

            $term->search_rank = $term->computeRank();
        });
    }

    /**
     * Blend confidence, workshop frequency and kind into one 0–100 ordering hint, so listing and
     * ranking never re-derive it per query. A very common workshop phrase the model is sure about
     * outranks a rare textbook synonym even if both scored 90 confidence.
     */
    public function computeRank(): int
    {
        $freq = match ($this->workshop_frequency) {
            'very_high' => 20,
            'high'      => 14,
            'medium'    => 8,
            'low'       => 3,
            default     => 0,
        };

        $quality = match ($this->source_quality) {
            'oem', 'ase' => 8,
            'manual'     => 5,
            'workshop'   => 4,
            default      => 0,
        };

        $kindWeight = (float) (config('keyword_ai.matching.kind_weights.'.$this->kind) ?? 0.9);

        return (int) max(0, min(100, round(((int) $this->confidence * 0.72 + $freq + $quality) * $kindWeight)));
    }

    public function findingKeyword(): BelongsTo
    {
        return $this->belongsTo(FindingKeyword::class);
    }

    /** Table-qualified: the matcher joins finding_keywords, which has an `is_active` of its own. */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where($q->getModel()->getTable().'.is_active', true);
    }

    /** Rows an AI run is allowed to rewrite — a human-owned term is never touched. */
    public function scopeMachineOwned(Builder $q): Builder
    {
        return $q->where('source', '!=', self::SOURCE_HUMAN);
    }

    public static function kindMeta(?string $kind): array
    {
        return self::KIND_META[$kind] ?? ['label' => ucfirst((string) $kind), 'tone' => 'gray'];
    }
}
