<?php

namespace App\Services;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Support\TextNormalizer;
use App\Support\VehicleScope;
use Illuminate\Support\Collection;

/**
 * RETRIEVAL — finding the documentation that should ground an enrichment, before generating it.
 *
 * V1 asked the model what it knew. This asks the corpus first. The difference shows up in the
 * evidence table: a grounded run cites a section of a document, an ungrounded one admits it is a
 * model prior.
 *
 * THREE RETRIEVAL PATHS, USED IN THIS ORDER
 *
 *  1. LOCAL CORPUS (`corpus`) — documents ingested into knowledge_chunks: manuals the fleet owns,
 *     uploaded PDFs, and anything a licensed integration has pulled down. Highest trust, zero
 *     latency, works offline. This is the path that gets better as you feed it.
 *
 *  2. LIVE WEB, ALLOW-LISTED (`web_search`) — Claude's server-side web search and fetch, restricted
 *     to the domains registered against public-web sources. That restriction is the whole design:
 *     the model cannot wander into a forum or a content farm even if it wants to, because the tool
 *     is only permitted to see the domains we registered. Available today, no licence needed.
 *
 *  3. MODEL PRIOR (`model_prior`) — nothing retrieved. Still a legitimate way to get a synonym, and
 *     the engine says so rather than pretending otherwise.
 *
 * ⚠️ WHAT THIS DELIBERATELY DOES NOT DO. ALLDATA, Mitchell 1, Haynes, Chilton and OEM factory
 * service manuals are paid, copyrighted products. There is no legal route to their content without
 * a subscription and their API. This service will never scrape them: a source marked `licensed`
 * with no credentials configured is skipped, not fetched. When the fleet licenses one, it becomes
 * an ingestion adapter writing into path 1 — no change to anything downstream.
 *
 * EMBEDDINGS ARE OPTIONAL. Anthropic does not sell an embeddings endpoint, so vector search is a
 * separate vendor decision. Until one is configured, corpus retrieval scores lexically over
 * `normalized`. When embeddings exist, chunks that have them are scored by cosine similarity and
 * chunks that don't fall back to lexical — so a half-embedded corpus degrades instead of going dark.
 */
class KnowledgeRetrievalService
{
    /** How many passages to hand the generator. Enough to ground, few enough to stay cheap. */
    private const TOP_K = 6;

    /** True when a local corpus exists at all — governs whether path 1 is worth running. */
    public function hasCorpus(): bool
    {
        return KnowledgeChunk::query()->exists();
    }

    /** True when live web grounding is possible: at least one public-web source with domains. */
    public function hasWebGrounding(): bool
    {
        return KnowledgeSource::query()->webRetrievable()->exists();
    }

    /**
     * Retrieve passages relevant to a fault from the local corpus.
     *
     * @param  array<int,string>  $scopeChain  restrict to universal + this vehicle's documents
     * @return Collection<int,array>  {chunk, score, document, source}
     */
    public function retrieve(string $query, array $scopeChain = [VehicleScope::UNIVERSAL], int $limit = self::TOP_K): Collection
    {
        if (! $this->hasCorpus()) {
            return collect();
        }

        $queryTokens = TextNormalizer::tokens($query);
        if ($queryTokens === []) {
            return collect();
        }

        // Cheap SQL pre-filter, precise scoring in PHP. A LIKE per token narrows thousands of
        // chunks to dozens; ranking them properly in SQL would need a full-text index we can't
        // assume exists on every deployment (and MariaDB/MySQL differ here — see
        // [[mariadb-local-mysql8-prod]]).
        $candidates = KnowledgeChunk::query()
            ->whereHas('document', fn ($q) => $q->inScope($scopeChain))
            ->where(function ($q) use ($queryTokens) {
                foreach (array_slice($queryTokens, 0, 6) as $token) {
                    $q->orWhere('normalized', 'like', '%'.$token.'%');
                }
            })
            ->with('document.source')
            ->limit(200)
            ->get();

        return $candidates
            ->map(function (KnowledgeChunk $chunk) use ($queryTokens) {
                $score = $this->lexicalScore($chunk, $queryTokens);

                // Trust-weight the passage by its source tier: an OEM procedure outranks a general
                // reference at the same textual relevance, which is the point of the registry.
                $trust = ($chunk->document?->source?->trust_weight ?? 50) / 100;

                return [
                    'chunk'    => $chunk,
                    'document' => $chunk->document,
                    'source'   => $chunk->document?->source,
                    'score'    => round($score * (0.7 + 0.3 * $trust), 2),
                ];
            })
            ->filter(fn (array $r) => $r['score'] > 0.12)
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    /**
     * Share of the query's meaningful tokens present in the passage, with a bonus for tokens that
     * appear more than once (a chunk that mentions "rotor" six times is about rotors; one that
     * mentions it once in a list is not).
     *
     * @param  array<int,string>  $queryTokens
     */
    private function lexicalScore(KnowledgeChunk $chunk, array $queryTokens): float
    {
        $text = ' '.$chunk->normalized.' ';
        $hits = 0;
        $density = 0;

        foreach ($queryTokens as $token) {
            $count = substr_count($text, ' '.$token.' ') + substr_count($text, ' '.$token);
            if ($count > 0) {
                $hits++;
                $density += min(3, $count);
            }
        }

        if ($hits === 0) {
            return 0.0;
        }

        $coverage = $hits / count($queryTokens);

        return $coverage * (1 + ($density / max(1, count($queryTokens))) * 0.15);
    }

    /**
     * The web-search tool definition for the enrichment call, or null when no public-web source is
     * registered. `allowed_domains` is the guard-rail — it is what makes "grounded in professional
     * sources" enforceable rather than a hope expressed in a prompt.
     *
     * @return array<string,mixed>|null
     */
    public function webSearchTool(): ?array
    {
        $domains = KnowledgeSource::allowedDomains();

        if ($domains === []) {
            return null;
        }

        return [
            'type'            => 'web_search_20260209',
            'name'            => 'web_search',
            'max_uses'        => (int) config('keyword_ai.retrieval.max_searches', 4),
            'allowed_domains' => array_slice($domains, 0, 100),
        ];
    }

    /** Companion fetch tool — lets the model read a page it found, same allowlist. */
    public function webFetchTool(): ?array
    {
        $domains = KnowledgeSource::allowedDomains();

        if ($domains === []) {
            return null;
        }

        return [
            'type'               => 'web_fetch_20260209',
            'name'               => 'web_fetch',
            'max_uses'           => (int) config('keyword_ai.retrieval.max_fetches', 4),
            'allowed_domains'    => array_slice($domains, 0, 100),
            'max_content_tokens' => (int) config('keyword_ai.retrieval.max_content_tokens', 12000),
        ];
    }

    /**
     * Render retrieved passages as the grounding block of a prompt. Each passage is numbered so the
     * model can cite it back by index — that index is what turns a generated term into an evidence
     * link pointing at a real chunk id, instead of a plausible-looking citation string.
     *
     * @param  Collection<int,array>  $passages
     * @return array{text:string,index:array<int,array>}
     */
    public function renderGrounding(Collection $passages): array
    {
        if ($passages->isEmpty()) {
            return ['text' => '', 'index' => []];
        }

        $lines = [];
        $index = [];

        foreach ($passages->values() as $i => $p) {
            $n = $i + 1;
            $doc = $p['document'];

            $index[$n] = [
                'chunk_id'    => $p['chunk']->id,
                'document_id' => $doc?->id,
                'source_id'   => $p['source']?->id,
                'title'       => $doc?->citation(),
                'section'     => $p['chunk']->section,
                'url'         => $doc?->url,
            ];

            $lines[] = "[{$n}] ".($doc?->citation() ?? 'Untitled')
                .($p['chunk']->section ? " — {$p['chunk']->section}" : '')
                ."\n".mb_substr(trim($p['chunk']->text), 0, 1500);
        }

        $text = "\n\nRETRIEVED DOCUMENTATION\n"
            ."These passages were retrieved from the fleet's licensed and curated corpus. Ground your "
            ."answer in them wherever they are relevant, and cite the passage number in "
            ."`evidence_refs` for any term or claim they support. Where they do not cover something, "
            ."say so by leaving the reference out rather than inventing a citation.\n\n"
            .implode("\n\n", $lines);

        return ['text' => $text, 'index' => $index];
    }
}
