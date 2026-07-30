<?php

namespace App\Ontology\Providers\Anthropic;

use Anthropic\Client;
use App\Ontology\Contracts\ExtractionProvider;
use App\Ontology\DTO\ExtractionResult;
use JsonException;
use Throwable;

/**
 * Claude implementation of [[ExtractionProvider]] — schema-enforced structured output.
 *
 * Uses native structured outputs, so the returned payload is guaranteed to satisfy the schema and
 * there is no prose parsing anywhere. A vendor without that feature would implement this same
 * interface with a parse-and-retry loop; the engine cannot tell the difference, which is the point.
 *
 * NO TOOLS ON THIS CALL, DELIBERATELY. Structured outputs are incompatible with the citation blocks
 * the search tools emit, so research and extraction are separate requests (see
 * [[AnthropicResearchProvider]]). That separation is also just better architecture: the expensive,
 * variable-latency retrieval step becomes independently skippable and cacheable.
 */
class AnthropicExtractionProvider implements ExtractionProvider
{
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

    public function extract(string $systemPrompt, string $userPrompt, array $schema, array $options = []): ExtractionResult
    {
        if (! $this->isAvailable()) {
            return new ExtractionResult($this->name(), $this->version(), error: 'no API key configured');
        }

        try {
            $client = $this->client ?? new Client(apiKey: config('knowledge_platform.anthropic.api_key'));

            $message = $client->messages->create(
                model: $this->version(),
                maxTokens: (int) ($options['max_tokens'] ?? config('knowledge_platform.anthropic.max_tokens')),
                system: [[
                    'type' => 'text',
                    'text' => $systemPrompt,
                    // The system prompt is byte-identical across a batch, so it caches once and
                    // every later call in the run reads it back at a fraction of the cost.
                    'cacheControl' => ['type' => 'ephemeral'],
                ]],
                messages: [['role' => 'user', 'content' => $userPrompt]],
                outputConfig: [
                    'effort' => $options['effort'] ?? config('knowledge_platform.anthropic.effort'),
                    'format' => ['type' => 'json_schema', 'schema' => $schema],
                ],
            );

            $json = null;
            foreach ($message->content as $block) {
                if (($block->type ?? null) === 'text') {
                    $json = $block->text;
                    break;
                }
            }

            if ($json === null) {
                return new ExtractionResult(
                    $this->name(), $this->version(),
                    error: 'no text block returned (stop reason: '.($message->stopReason ?? '?').')',
                    inputTokens: (int) ($message->usage->inputTokens ?? 0),
                    outputTokens: (int) ($message->usage->outputTokens ?? 0),
                );
            }

            return new ExtractionResult(
                provider: $this->name(),
                version: $this->version(),
                data: json_decode($json, true, 512, JSON_THROW_ON_ERROR),
                inputTokens: (int) ($message->usage->inputTokens ?? 0),
                outputTokens: (int) ($message->usage->outputTokens ?? 0),
            );
        } catch (JsonException $e) {
            // Schema enforcement should make this unreachable; if it happens, the contract was
            // broken and the caller must be told rather than handed half-parsed data.
            return new ExtractionResult($this->name(), $this->version(), error: 'malformed JSON: '.$e->getMessage());
        } catch (Throwable $e) {
            return new ExtractionResult($this->name(), $this->version(), error: mb_substr($e->getMessage(), 0, 300));
        }
    }
}
