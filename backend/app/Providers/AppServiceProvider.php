<?php

namespace App\Providers;

use App\Contracts\VehicleExpenseProvider;
use App\Models\Maintenance;
use App\Services\Expenses\ExcelVehicleExpenseProvider;
use App\Services\Intelligence\Capabilities\ComebackCapability;
use App\Services\Intelligence\CapabilityPolicy;
use App\Services\Intelligence\DecisionEngine;
use App\Services\Intelligence\PolicyRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The single seam for vehicle expense. Excel today, Odoo later — swap the class here (driven by
        // config('expenses.provider')); every consumer keeps depending only on the interface.
        $this->app->bind(VehicleExpenseProvider::class, function ($app) {
            return match (config('expenses.provider', 'excel')) {
                // 'odoo' => $app->make(\App\Services\Expenses\OdooVehicleExpenseProvider::class),
                default => $app->make(ExcelVehicleExpenseProvider::class),
            };
        });

        // The override-learning analysis is a PURE class — it must never read config itself, or it
        // stops being unit-testable without a container. The taxonomy and the evidence thresholds are
        // injected here, at the one place that is allowed to know about config.
        $this->app->bind(\App\Services\Garage\DecisionLearning::class, fn () => new \App\Services\Garage\DecisionLearning(
            (array) config('garage_recommendation.override_reasons', []),
            (array) config('garage_recommendation.learning', []),
        ));

        // The intelligence platform's public API for historical knowledge. Capabilities depend on the
        // INTERFACE only, so swapping the projection for a materialised view, a warehouse or an
        // external analytics service is this one line and nothing else.
        $this->app->bind(
            \App\Services\RepairIntelligence\Query\RepairHistoryQuery::class,
            \App\Services\RepairIntelligence\Query\ProjectionRepairHistoryQuery::class,
        );

        // The parts vocabulary index, built once per process instead of once per lookup.
        //
        // Both services cache a scan of the whole component_catalog (132 rows) on first use. They are
        // consulted from a model hook that runs on EVERY part request and purchase save, so resolving
        // them fresh each time turned a seeding loop of 3,500 purchases into 3,500 catalog queries.
        // Singletons also give the process ONE view of the vocabulary, which is what makes flush()
        // (after a catalog edit) mean something.
        $this->app->singleton(\App\Services\PartCatalogMatcher::class);
        $this->app->singleton(\App\Services\PartIdentityService::class);

        // The maintenance intelligence pipeline. Capabilities are registered HERE and nowhere else:
        // a capability's only job is to answer "what does history tell us?", so the decision about
        // which ones exist — and therefore which can ever reach a user — stays in one place.
        //
        // Each capability is independently feature-flagged (config/features.php → intelligence.*)
        // and checks its own flag in appliesTo(), so the same workflow can be run with and without
        // a given recommendation and the two compared on real operations.
        $this->app->singleton(DecisionEngine::class, function ($app) {
            return new DecisionEngine(
                $app->make(\App\Services\Intelligence\CardArbitrator::class),
                [
                    $app->make(ComebackCapability::class),
                ],
            );
        });

        // WHEN, WHERE and HOW OFTEN each capability may speak — the platform's entire delivery
        // behaviour, readable in one place. A capability decides nothing about this: it answers only
        // "do I have useful historical evidence?". Adding one is implement evaluate(), register the
        // capability above, register its policy here. No orchestration code is written.
        $this->app->singleton(PolicyRegistry::class, function () {
            return new PolicyRegistry([
                new CapabilityPolicy(
                    capabilityId: 'comeback-warning',
                    enabled: config('features.intelligence.comeback_detection', false),
                    // ONLY where a human can still change the outcome. A comeback warning at
                    // `awaiting_invoice` is a fact nobody can act on — that is a dashboard, and the
                    // pipeline exists to not become one. These three are the moments where the car
                    // has not yet been committed to a garage.
                    workflowStates: [
                        Maintenance::WF_INSPECTION_PENDING,   // the Supervisor's dispatch decision
                        Maintenance::WF_REINSPECTION_FAILED,  // QC bounced it — re-dispatch decision
                        Maintenance::WF_COMPLAINT_TRIAGE,     // triage decides how to handle it at all
                    ],
                    roles: ['maintenance.delegate', 'maintenance.initiate', 'maintenance.manage'],
                    // A state change is a genuinely new decision, with a new possible outcome.
                    refresh: CapabilityPolicy::REFRESH_PER_STATE,
                    // Repair history does not go stale inside a shift; the ticket moving on is what
                    // ends the advice, and the state gate already handles that.
                    expiresAfterMinutes: null,
                    // Answered once, it stays answered. Re-asking someone who already decided is
                    // nagging, and nagging costs credibility across every other card too.
                    cooldownMinutes: null,
                ),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // super-admin and admin short-circuit every authorization check, so they
        // implicitly hold any current or future permission without being reseeded.
        Gate::before(function ($user, $ability) {
            return $user->hasAnyRole(['super-admin', 'admin']) ? true : null;
        });
    }
}
