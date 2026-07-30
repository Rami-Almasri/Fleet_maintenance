<?php

namespace App\Ontology\DTO;

/**
 * A schema-conforming payload from an [[ExtractionProvider]].
 *
 * `data` has already been validated against the schema by the provider — the engine treats it as
 * trustworthy in shape (though never in content, which is what the confidence model is for). A
 * provider that could not produce conforming data returns an error instead of partial data, so
 * callers never have to guess whether a missing key means "absent" or "extraction broke".
 */
final class ExtractionResult extends ProviderResult
{
    public function __construct(
        string $provider,
        string $version,
        /** @var array<string,mixed> */
        public readonly array $data = [],
        int $inputTokens = 0,
        int $outputTokens = 0,
        ?string $error = null,
    ) {
        parent::__construct($provider, $version, $inputTokens, $outputTokens, $error);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }
}
