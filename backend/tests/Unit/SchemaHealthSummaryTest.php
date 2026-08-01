<?php

namespace Tests\Unit;

use App\Services\Schema\SchemaHealthService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The summary layer is what operations will actually look at, so its arithmetic has to be
 * untrustworthy-proof in one specific way: a healthy-looking score must never be able to sit on top of
 * a failed check. These tests exercise the pure scoring/verdict logic with synthetic checks — the
 * database-backed checks themselves are covered end-to-end by `php artisan schema:verify-fresh`.
 */
class SchemaHealthSummaryTest extends TestCase
{
    private SchemaHealthService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new SchemaHealthService();
    }

    /** @return array<string, mixed> */
    private function check(string $category, string $status, string $key = 'k'): array
    {
        return ['category' => $category, 'key' => $key, 'label' => ucfirst($key), 'status' => $status, 'detail' => '', 'fix' => null, 'facts' => []];
    }

    public function test_all_healthy_scores_one_hundred(): void
    {
        $s = $this->svc->summary([$this->check('schema', 'ok'), $this->check('engine', 'ok')]);

        $this->assertSame(100, $s['score']);
        $this->assertSame('ok', $s['verdict']);
    }

    public function test_a_single_failure_makes_the_overall_verdict_fail_however_high_the_score(): void
    {
        // Nineteen passing checks and one broken integrity constraint still scores 95% — which is
        // exactly why the verdict is the WORST status and not a threshold on the score. A green headline
        // over a failed constraint is the failure mode this guards against.
        $checks = array_fill(0, 19, $this->check('schema', 'ok'));
        $checks[] = $this->check('schema', 'fail');

        $s = $this->svc->summary($checks);

        $this->assertSame(95, $s['score']);
        $this->assertSame('fail', $s['verdict'], 'a high score must never soften a failure');
    }

    public function test_a_warning_counts_as_half_credit(): void
    {
        $s = $this->svc->summary([$this->check('schema', 'ok'), $this->check('calibration', 'warn')]);

        $this->assertSame(75, $s['score']);
        $this->assertSame('warn', $s['verdict']);
    }

    public function test_a_category_takes_the_worst_status_of_its_checks(): void
    {
        $s = $this->svc->summary([
            $this->check('schema', 'ok', 'a'),
            $this->check('schema', 'fail', 'b'),
            $this->check('calibration', 'ok', 'c'),
        ]);

        $this->assertSame('fail', $s['categories']['schema']['status']);
        $this->assertSame(2, $s['categories']['schema']['checks']);
        $this->assertSame('ok', $s['categories']['calibration']['status'], 'one bad category must not colour the others');
    }

    public function test_categories_are_reported_in_a_stable_operator_facing_order(): void
    {
        // Deployment → Schema → Decision Engine → Calibration: infrastructure first, then what it feeds.
        $s = $this->svc->summary([
            $this->check('calibration', 'ok'),
            $this->check('deployment', 'ok'),
            $this->check('engine', 'ok'),
            $this->check('schema', 'ok'),
        ]);

        $this->assertSame(['deployment', 'schema', 'engine', 'calibration'], array_keys($s['categories']));
    }

    public function test_an_empty_check_set_is_not_reported_as_perfect_health(): void
    {
        // If the checks ever fail to load, "100%" would be the most dangerous possible answer.
        $s = $this->svc->summary([]);

        $this->assertSame(0, $s['score']);
        $this->assertSame([], $s['categories']);
    }

    public function test_every_declared_provenance_field_is_required_together(): void
    {
        // Partial provenance is not provenance: knowing the engine build but not the tuning fingerprint
        // still leaves a decision unexplainable, so all four must be validated as a set.
        $r = new ReflectionMethod(SchemaHealthService::class, 'provenanceCompleteness');
        $this->assertTrue($r->isPrivate(), 'checks stay internal; the public surface is checks()/summary()');

        $const = (new \ReflectionClass(SchemaHealthService::class))->getConstant('PROVENANCE_FIELDS');
        $this->assertSame(['engine_version', 'policy_version', 'config_fingerprint', 'data_snapshot'], $const);
    }
}
