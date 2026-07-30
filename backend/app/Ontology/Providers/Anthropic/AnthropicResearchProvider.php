<?php

namespace App\Ontology\Providers\Anthropic;

use Anthropic\Client;
use App\Models\EvidenceLink;
use App\Ontology\Contracts\ResearchProvider;
use App\Ontology\DTO\ResearchResult;
use App\Ontology\DTO\RetrievedPassage;
use Throwable;

/**
 * Claude implementation of [[ResearchProvider]] — server-side web search and fetch, allow-listed.
 *
 * Everything vendor-specific about researching lives here and nowhere else: the tool definitions,
 * the `pause_turn` continuation loop that server-tool calls need, and the block shapes citations
 * arrive in. Swapping to another vendor means writing a sibling class; the engine is unaffected.
 *
 * THE ALLOWLIST IS ENFORCED AT THE TOOL, NOT IN THE PROMPT. `allowed_domains` is passed to the
 * search and fetch tools themselves, so the model physically cannot read a host outside it — a
 * prompt instruction saying "only use trusted sources" would be a suggestion, and suggestions are
 * not a licensing boundary. An empty allowlist means no research happens at all.
 */
class AnthropicResearchProvider implements ResearchProvider
{
    /** Server-tool loops hand back `pause_turn` at their internal cap; bound the resumes. */
    private const MAX_CONTINUATIONS = 4;

    public function __construct(private readonly ?Client $client = null)
    {
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function version(): string
    {
        return (string) config('knowledge_platform.anthropic.model');
    }

    public function isAvailable(): bool
    {
        return filled(config('knowledge_platform.anthropic.api_key'));
    }

    public function research(string $topic, string $context, array $allowedDomains): ResearchResult
    {
        if (! $this->isAvailable()) {
            return ResearchResult::empty($this->name(), 'no API key configured');
        }

        if ($allowedDomains === []) {
            // Refusing to search is the correct behaviour, not a degraded one — see class doc.
            return ResearchResult::empty($this->name(), 'no allow-listed domains registered');
        }

        $domains = array_slice(array_values(array_unique($allowedDomains)), 0, 100);

        $tools = [
            [
                'type'            => 'web_search_20260209',
                'name'            => 'web_search',
                'max_uses'        => 4,
                'allowed_domains' => $domains,
            ],
            [
                'type'               => 'web_fetch_20260209',
                'name'               => 'web_fetch',
                'max_uses'           => 4,
                'allowed_domains'    => $domains,
                'max_content_tokens' => 12000,
            ],
        ];

        $messages = [[
            'role'    => 'user',
            'content' => $this->prompt($topic, $context),
        ]];

        $findings = '';
        $passages = [];
        $inTokens = $outTokens = 0;

        try {
            $client = $this->client ?? new Client(apiKey: config('knowledge_platform.anthropic.api_key'));

            for ($i = 0; $i <= self::MAX_CONTINUATIONS; $i++) {
                $message = $client->messages->create(
                    model: $this->version(),
                    maxTokens: (int) config('knowledge_platform.anthropic.max_tokens'),
                    tools: $tools,
                    messages: $messages,
                    outputConfig: ['effort' => config('knowledge_platform.anthropic.effort')],
                );

                $inTokens  += (int) ($message->usage->inputTokens ?? 0);
                $outTokens += (int) ($message->usage->outputTokens ?? 0);

                foreach ($message->content as $block) {
                    if (($block->type ?? null) === 'text') {
                        $findings .= $block->text."\n";
                    }
                    $passages = array_merge($passages, $this->passagesFrom($block));
                }

                if (($message->stopReason ?? null) !== 'pause_turn') {
                    break;
                }

                $messages[] = ['role' => 'assistant', 'content' => $message->content];
            }
        } catch (Throwable $e) {
            return new ResearchResult(
                $this->name(), $this->version(),
                error: mb_substr($e->getMessage(), 0, 300),
                inputTokens: $inTokens, outputTokens: $outTokens,
            );
        }

        return new ResearchResult(
            provider: $this->name(),
            version: $this->version(),
            findings: trim($findings),
            passages: $this->dedupe($passages),
            inputTokens: $inTokens,
            outputTokens: $outTokens,
        );
    }

    private function prompt(string $topic, string $context): string
    {
        return <<<PROMPT
        Research this automotive fault using the available technical sources.

        Fault: {$topic}
        {$context}

        Find and summarise, citing what you actually read:
        1. The standard technical terminology, and the informal wording used in workshops and by
           service advisors.
        2. The components involved and the documented failure modes.
        3. The documented diagnostic procedure, and the order checks are performed in.
        4. The documented repair procedures, and any published labour-time guidance.
        5. Any manufacturer bulletins or recall notices relevant to it.

        Be concise and factual. If the sources do not cover something, say so explicitly rather than
        filling the gap from general knowledge — the next step needs to know which parts are
        documented and which are not.
        PROMPT;
    }

    /**
     * Pull citations out of a tool-result block and wrap them as passages.
     *
     * Defensive by design: the SDK exposes these as nested objects whose exact shape varies by tool
     * version, and an unreadable citation is worth skipping rather than failing a whole run over.
     *
     * @return array<int,RetrievedPassage>
     */
    private function passagesFrom(mixed $block): array
    {
        $type = is_object($block) ? ($block->type ?? null) : null;

        if ($type !== 'web_search_tool_result' && $type !== 'web_fetch_tool_result') {
            return [];
        }

        $content = $block->content ?? null;
        // An error result is a single object rather than a list — handle both shapes.
        $items = is_array($content) ? $content : (is_object($content) ? [$content] : []);
        $out = [];

        foreach ($items as $item) {
            $url = data_get($item, 'url') ?? data_get($item, 'document.source.url');
            if (blank($url)) {
                continue;
            }

            $title = data_get($item, 'title') ?? data_get($item, 'document.title') ?? $url;
            $text  = (string) (data_get($item, 'page_age') !== null
                ? (data_get($item, 'encrypted_content') ? '' : '')
                : '');
            // Search results carry a title and URL but not always readable body text; the citation
            // is the value here, and the extractor reads the narrative brief for substance.
            $snippet = (string) (data_get($item, 'snippet') ?? data_get($item, 'document.content') ?? $text);

            $out[] = new RetrievedPassage(
                text: $snippet !== '' ? mb_substr($snippet, 0, 1500) : (string) $title,
                source: 'web',
                retrievalMethod: EvidenceLink::METHOD_WEB_SEARCH,
                score: 0.7,
                title: mb_substr((string) $title, 0, 300),
                url: mb_substr((string) $url, 0, 1000),
                dimension: 'documentation',
            );
        }

        return $out;
    }

    /** @param array<int,RetrievedPassage> $passages @return array<int,RetrievedPassage> */
    private function dedupe(array $passages): array
    {
        return collect($passages)->unique(fn (RetrievedPassage $p) => $p->url)->take(12)->values()->all();
    }
}
