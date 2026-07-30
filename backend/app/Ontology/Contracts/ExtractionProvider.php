<?php

namespace App\Ontology\Contracts;

use App\Ontology\DTO\ExtractionResult;

/**
 * Turns context into a validated structured payload.
 *
 * This is the mandatory capability — without it there is no enrichment. The contract is
 * deliberately narrow: given a system prompt, a user prompt and a JSON Schema, return data that
 * conforms to the schema. Nothing about how (native structured outputs, function calling,
 * constrained decoding, or retry-until-valid) is the engine's business.
 *
 * THE SCHEMA IS THE CONTRACT, NOT THE PROVIDER'S FEATURES. Anthropic and OpenAI both offer native
 * schema enforcement; Gemini has its own shape; a local model may have none and need a
 * parse-and-retry loop. All of those are legitimate implementations of this interface, because the
 * engine's requirement is "data matching this schema", not "use structured outputs". An
 * implementation that cannot guarantee the schema MUST validate and retry itself rather than
 * returning something malformed for the caller to discover.
 */
interface ExtractionProvider
{
    /**
     * @param  array<string,mixed>  $schema  JSON Schema the returned payload must satisfy
     * @param  array<string,mixed>  $options provider hints (effort, max_tokens, cache) — advisory
     */
    public function extract(string $systemPrompt, string $userPrompt, array $schema, array $options = []): ExtractionResult;

    public function name(): string;

    public function version(): string;

    public function isAvailable(): bool;
}
