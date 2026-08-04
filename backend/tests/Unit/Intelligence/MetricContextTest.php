<?php

namespace Tests\Unit\Intelligence;

use App\Intelligence\MetricContext;
use PHPUnit\Framework\TestCase;

/**
 * The context is the cache key. If its hash is unstable, the cache is an unbounded slow leak and
 * two identical questions get two different entries — so hash determinism is a correctness test,
 * not a tidiness one.
 */
class MetricContextTest extends TestCase
{
    public function test_hash_is_stable_under_key_reordering(): void
    {
        $a = MetricContext::fromArray(['from' => '2026-01-01', 'to' => '2026-06-30', 'signature' => 'BRAKES']);
        $b = MetricContext::fromArray(['signature' => 'BRAKES', 'to' => '2026-06-30', 'from' => '2026-01-01']);

        $this->assertSame($a->hash(), $b->hash());
    }

    public function test_hash_is_stable_under_id_list_reordering(): void
    {
        $a = MetricContext::fromArray(['vendor_id' => [331, 364, 234]]);
        $b = MetricContext::fromArray(['vendor_id' => [234, 331, 364]]);

        $this->assertSame($a->hash(), $b->hash());
    }

    public function test_duplicate_ids_do_not_change_the_hash(): void
    {
        $a = MetricContext::fromArray(['vendor_id' => [331, 331, 364]]);
        $b = MetricContext::fromArray(['vendor_id' => [331, 364]]);

        $this->assertSame($a->hash(), $b->hash());
    }

    public function test_different_filters_hash_differently(): void
    {
        $a = MetricContext::fromArray(['signature' => 'BRAKES']);
        $b = MetricContext::fromArray(['signature' => 'ELECTRICAL']);

        $this->assertNotSame($a->hash(), $b->hash());
    }

    public function test_unknown_keys_do_not_widen_the_key_space(): void
    {
        $a = MetricContext::fromArray(['signature' => 'BRAKES']);
        $b = MetricContext::fromArray(['signature' => 'BRAKES', 'utm_source' => 'email', 'page' => 4]);

        $this->assertSame($a->hash(), $b->hash());
    }

    public function test_min_sample_defaults_to_the_platform_floor(): void
    {
        $this->assertSame(30, MetricContext::MIN_SAMPLE);
        $this->assertSame(30, (new MetricContext())->minSample);
        $this->assertSame(30, MetricContext::fromArray([])->minSample);
    }

    public function test_min_sample_is_clamped(): void
    {
        $this->assertSame(1, MetricContext::fromArray(['min_sample' => 0])->minSample);
        $this->assertSame(1, MetricContext::fromArray(['min_sample' => -5])->minSample);
        $this->assertSame(500, MetricContext::fromArray(['min_sample' => 99999])->minSample);
    }

    public function test_non_garages_are_excluded_by_default(): void
    {
        $this->assertFalse((new MetricContext())->includeNonGarages);
        $this->assertTrue(MetricContext::fromArray(['include_non_garages' => 'true'])->includeNonGarages);
        $this->assertTrue(MetricContext::fromArray(['include_non_garages' => 1])->includeNonGarages);
        $this->assertFalse(MetricContext::fromArray(['include_non_garages' => '0'])->includeNonGarages);
    }

    public function test_cache_key_follows_the_project_convention(): void
    {
        $key = MetricContext::fromArray(['signature' => 'BRAKES'])->cacheKey('garage', 'leaderboard');

        $this->assertStringStartsWith('intelligence:garage:leaderboard:v1:', $key);
        $this->assertSame(1, preg_match('/^intelligence:[a-z_]+:[a-z_]+:v\d+:[0-9a-f]{16}$/', $key));
    }

    public function test_with_min_sample_returns_a_copy_and_leaves_the_original_untouched(): void
    {
        $original = MetricContext::fromArray(['signature' => 'BRAKES']);
        $relaxed  = $original->withMinSample(10);

        $this->assertSame(30, $original->minSample);
        $this->assertSame(10, $relaxed->minSample);
        $this->assertSame('BRAKES', $relaxed->signature);
        $this->assertNotSame($original->hash(), $relaxed->hash());
    }

    public function test_dates_normalise_to_day_boundaries(): void
    {
        $ctx = MetricContext::fromArray(['from' => '2026-03-15 13:45:00', 'to' => '2026-03-20 04:00:00']);

        $this->assertSame('00:00:00', $ctx->from->format('H:i:s'));
        $this->assertSame('23:59:59', $ctx->to->format('H:i:s'));
    }
}
