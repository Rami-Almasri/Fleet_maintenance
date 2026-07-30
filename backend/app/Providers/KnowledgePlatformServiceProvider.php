<?php

namespace App\Providers;

use App\Ontology\Contracts\EmbeddingProvider;
use App\Ontology\Contracts\ExtractionProvider;
use App\Ontology\Contracts\RerankerProvider;
use App\Ontology\Contracts\ResearchProvider;
use App\Ontology\Providers\NullDriver\NullEmbeddingProvider;
use App\Ontology\Providers\NullDriver\NullRerankerProvider;
use App\Ontology\Providers\NullDriver\NullResearchProvider;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Binds the Automotive Knowledge Platform's contracts to their configured implementations.
 *
 * This is the ONLY place in the application that maps a capability to a vendor. Every other class
 * type-hints an interface and receives whatever is configured — which is what makes "swap Claude
 * for Gemini" a config change rather than a refactor.
 *
 * Resolution rules:
 *  - an unset provider resolves to the null driver (documented no-op, honest `isAvailable()`);
 *  - an unknown provider name throws at boot rather than at 3am inside a batch — a typo in
 *    KNOWLEDGE_EXTRACTION_PROVIDER should fail loudly and immediately;
 *  - extraction has no null driver on purpose: it is the one capability the engine cannot run
 *    without, so its absence must surface as a clear error, not a silent empty enrichment.
 */
class KnowledgePlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->bindCapability('research', ResearchProvider::class, NullResearchProvider::class);
        $this->bindCapability('extraction', ExtractionProvider::class, null);
        $this->bindCapability('embedding', EmbeddingProvider::class, NullEmbeddingProvider::class);
        $this->bindCapability('reranker', RerankerProvider::class, NullRerankerProvider::class);

        // The matcher and its pipeline are singletons for two reasons. The obvious one: the
        // pipeline builds six stages and the term index on construction, and rebuilding that per
        // injection would be wasteful in a request that matches several strings.
        //
        // The load-bearing one: there is a legitimate cycle in the graph — matching consults fleet
        // knowledge (semantic recall), and fleet knowledge resolves text through matching. The
        // stage breaks it by resolving lazily, and singletons ensure that lazy resolution returns
        // the instance already under construction rather than starting a second one.
        $this->app->singleton(\App\Services\KeywordOntologyService::class);
        $this->app->singleton(\App\Ontology\Matching\MatchPipeline::class);
        $this->app->singleton(\App\Ontology\Matching\TermIndex::class);
        $this->app->singleton(\App\Ontology\Retrieval\RetrievalManager::class);
        $this->app->singleton(\App\Ontology\Retrieval\FleetRetriever::class);
    }

    /**
     * Bind one capability's interface to the class named in config.
     *
     * @param  class-string|null  $nullDriver  fallback when unconfigured; null = capability is required
     */
    private function bindCapability(string $capability, string $interface, ?string $nullDriver): void
    {
        $this->app->singleton($interface, function () use ($capability, $interface, $nullDriver) {
            $selected = config("knowledge_platform.providers.{$capability}");

            if (blank($selected)) {
                if ($nullDriver === null) {
                    throw new RuntimeException(
                        "Knowledge platform: no '{$capability}' provider configured, and this "
                        ."capability has no null driver. Set KNOWLEDGE_".strtoupper($capability)."_PROVIDER."
                    );
                }

                return $this->app->make($nullDriver);
            }

            $class = config("knowledge_platform.implementations.{$capability}.{$selected}");

            if (! $class || ! class_exists($class)) {
                throw new RuntimeException(
                    "Knowledge platform: '{$selected}' is not a registered {$capability} provider. "
                    ."Add it to config/knowledge_platform.php under implementations.{$capability}."
                );
            }

            $instance = $this->app->make($class);

            if (! $instance instanceof $interface) {
                throw new RuntimeException("{$class} must implement {$interface}.");
            }

            return $instance;
        });
    }
}
