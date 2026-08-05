<?php

namespace App\Intelligence\Recurrence;

use InvalidArgumentException;

/**
 * The parameters that decide what "came back" means — carried as one object so they cannot drift.
 *
 * ── WHY THIS IS A CLASS AND NOT THREE ARGUMENTS ──────────────────────────────────────────────────
 * The platform previously carried six recurrence implementations with three different observation
 * horizons (none, `CURDATE() − 90d`, `MAX(occurred_at) − 90d`) and the same window value copied into
 * four config files, each with a comment asking the next person to keep it in step with the others.
 * They did not stay in step. Bundling window + horizon + exposure into one object means a caller
 * cannot supply two of the three and silently inherit a default for the last.
 *
 * Every RecurrenceRepository method takes one of these. {@see fromContract()} is how you get the
 * governed one; the named constructors exist only so the parity tests can reproduce a legacy
 * definition and prove the repository is capable of expressing it.
 *
 * @see config/metrics/recurrence.php  the governed values
 * @see docs/Metric-Specification-Recurrence.md
 */
final class RecurrenceWindow
{
    /** Exclude events too recent to have failed — `days_observed >= windowDays`. */
    public const HORIZON_CORPUS_MAX = 'corpus_max';

    /** Count every event regardless of how long it has been observed. Legacy shape; biased. */
    public const HORIZON_NONE = 'none';

    public function __construct(
        public readonly int $windowDays,
        public readonly string $horizonMode = self::HORIZON_CORPUS_MAX,
        public readonly bool $excludeExposure = true,
        public readonly string $version = 'ad-hoc',
        /**
         * Scope rates to kind='fault'. Contract v2.1.0 — a recurring oil change is the service
         * working, not the repair failing. Carried here rather than assumed in the repository so a
         * legacy parity window can reproduce v2.0.0's numbers by turning it off.
         */
        public readonly bool $excludeServices = true,
    ) {
        if ($windowDays < 1) {
            throw new InvalidArgumentException('A recurrence window must be at least one day.');
        }

        if (! in_array($horizonMode, [self::HORIZON_CORPUS_MAX, self::HORIZON_NONE], true)) {
            throw new InvalidArgumentException("Unknown observation horizon [{$horizonMode}].");
        }
    }

    /**
     * The governed window. This is what every production caller uses.
     *
     * Reads config/metrics/recurrence.php rather than hardcoding, so a version bump in the contract
     * reaches every consumer at once — which is the entire point of governing the metric.
     */
    public static function fromContract(?int $windowDaysOverride = null): self
    {
        $c = (array) config('metrics.recurrence', []);

        return new self(
            windowDays:      $windowDaysOverride ?? (int) ($c['window_days'] ?? 90),
            horizonMode:     (string) data_get($c, 'observation_horizon.mode', self::HORIZON_CORPUS_MAX),
            excludeExposure: (bool) data_get($c, 'filters.exclude_exposure', true),
            version:         (string) ($c['version'] ?? 'unknown'),
            excludeServices: (bool) data_get($c, 'filters.exclude_services', true),
        );
    }

    /**
     * One of the contract's reported sub-windows (30 / 60 / 90).
     *
     * Same governed horizon and exposure rule — only the window narrows, so "came back within a
     * month" and "came back within three" remain the same measurement at two resolutions.
     */
    public static function reported(int $windowDays): self
    {
        $allowed = (array) config('metrics.recurrence.reported_windows_days', [30, 60, 90]);

        if (! in_array($windowDays, $allowed, true)) {
            throw new InvalidArgumentException(
                "Window {$windowDays}d is not a reported window of the recurrence contract ("
                . implode(', ', $allowed) . '). Add it to the contract with a version bump.'
            );
        }

        return self::fromContract($windowDays);
    }

    /**
     * A legacy definition, for parity testing ONLY.
     *
     * Production code must never call this. It exists so the convergence can PROVE the repository
     * reproduces each retired implementation before that implementation is deleted — a claim that
     * would otherwise rest on reading the SQL and hoping.
     */
    public static function legacy(int $windowDays, string $horizonMode): self
    {
        // Services IN — every retired implementation counted them, so a parity window that excluded
        // them would not be reproducing the definition it claims to prove.
        return new self($windowDays, $horizonMode, true, 'legacy-parity', excludeServices: false);
    }

    public function appliesHorizon(): bool
    {
        return $this->horizonMode === self::HORIZON_CORPUS_MAX;
    }

    /** Do rates built on this window measure repair quality only? Contract v2.1.0. */
    public function excludesServices(): bool
    {
        return $this->excludeServices;
    }

    /** Part of the cache key, so two different definitions cannot share a cache entry. */
    public function fingerprint(): string
    {
        return sprintf(
            '%dd:%s:%s:%s:v%s',
            $this->windowDays,
            $this->horizonMode,
            $this->excludeExposure ? 'noexp' : 'allexp',
            // In the key, not just the object: v2.0.0 and v2.1.0 answer the same question over
            // different populations, and a shared cache entry would serve one as the other.
            $this->excludeServices ? 'nosvc' : 'allsvc',
            $this->version,
        );
    }

    public function toArray(): array
    {
        return [
            'window_days'      => $this->windowDays,
            'horizon_mode'     => $this->horizonMode,
            'exclude_exposure' => $this->excludeExposure,
            'exclude_services' => $this->excludeServices,
            'metric_version'   => $this->version,
        ];
    }
}
