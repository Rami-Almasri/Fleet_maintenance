<?php

namespace Tests\Golden;

use App\Intelligence\Health\RebuildLedger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * The freshness watchdog.
 *
 * ── WHY THIS IS TESTED AT ALL ────────────────────────────────────────────────────────────────────
 * An alert nobody has watched fire is a guess. This one guards a failure with no symptom: if the
 * nightly rebuild stops, every page keeps rendering and every figure keeps looking authoritative
 * while the whole platform answers out of a corpus that stopped growing. The only thing standing
 * between that and a month of quietly wrong decisions is this command actually going off.
 *
 * So the tests age the ledger deliberately and assert the alarm sounds — and, just as importantly,
 * that it stays silent when the data is fine. A watchdog that cries wolf gets muted.
 */
#[Group('golden')]
class RebuildHealthAlertTest extends GoldenTestCase
{
    private RebuildLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = app(RebuildLedger::class);

        // A known-good starting point for every test.
        Artisan::call('intelligence:rebuild-visits');
        Artisan::call('intelligence:rebuild-recurrence');
        DB::table('notifications')->where('data', 'like', '%intel_rebuild_stale%')->delete();
    }

    private function ageLedgerBy(int $hours): void
    {
        DB::table('intelligence_rebuild_runs')->update([
            'started_at'  => now()->subHours($hours),
            'finished_at' => now()->subHours($hours),
        ]);
    }

    private function alertsSent(): int
    {
        return DB::table('notifications')->where('data', 'like', '%intel_rebuild_stale%')->count();
    }

    // ── Silence when healthy ────────────────────────────────────────────────────────────────────

    public function test_a_fresh_rebuild_raises_nothing_and_exits_clean(): void
    {
        $exit = Artisan::call('intelligence:rebuild-health', ['--alert' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(0, $this->alertsSent(), 'a healthy platform must not page anybody');
    }

    // ── Noise when it matters ───────────────────────────────────────────────────────────────────

    public function test_stale_data_alerts_the_managers_and_fails_the_command(): void
    {
        $this->ageLedgerBy(40);   // past the 36h contract limit

        $exit = Artisan::call('intelligence:rebuild-health', ['--alert' => true]);

        $this->assertSame(1, $exit, 'a monitor watching the exit code must see the failure');
        $this->assertGreaterThan(0, $this->alertsSent(), 'a human must be told, not just a log file');
    }

    /**
     * The exit code alone is not enough.
     *
     * It only helps if something is watching for it, and the failure mode being guarded against is
     * precisely that nothing is. The in-app notification is the belt to the exit code's braces.
     */
    public function test_the_alert_reaches_people_who_can_act_on_it(): void
    {
        $this->ageLedgerBy(40);
        Artisan::call('intelligence:rebuild-health', ['--alert' => true]);

        $managers = DB::table('users')->count();
        $this->assertGreaterThan(0, $managers);

        $row = DB::table('notifications')->where('data', 'like', '%intel_rebuild_stale%')->first();
        $this->assertNotNull($row);

        $data = json_decode($row->data, true);
        $this->assertSame('critical', $data['severity']);
        $this->assertSame('/data-health', $data['url'], 'the alert must land somewhere actionable');

        // The message says what it MEANS, not what broke. "fault_recurrence_pairs is stale" is a
        // sentence for us; a manager needs to know their garage scores are showing old numbers.
        $this->assertStringContainsString('garage score', $data['body']);
        $this->assertStringNotContainsString('fault_recurrence_pairs', $data['body']);
    }

    public function test_a_persistent_outage_re_raises_daily_rather_than_hourly(): void
    {
        $this->ageLedgerBy(40);

        Artisan::call('intelligence:rebuild-health', ['--alert' => true]);
        $first = $this->alertsSent();

        // Same day, same condition — must not spam. The key is dated, so tomorrow it speaks again.
        Artisan::call('intelligence:rebuild-health', ['--alert' => true]);

        $this->assertSame($first, $this->alertsSent(), 'a persistent outage must not page hourly');
    }

    // ── Never-built is not healthy ──────────────────────────────────────────────────────────────

    /**
     * An absent record is the most dangerous state, not the safest.
     *
     * Treating "no successful run on file" as healthy is exactly how a scheduler that never ran once
     * stays invisible forever.
     */
    public function test_never_having_run_counts_as_stale(): void
    {
        DB::table('intelligence_rebuild_runs')->delete();

        $health = $this->ledger->health('fault_recurrence_pairs');

        $this->assertTrue($health['is_stale']);
        $this->assertTrue($health['never_rebuilt']);

        $exit = Artisan::call('intelligence:rebuild-health', ['--alert' => true]);
        $this->assertSame(1, $exit);

        $data = json_decode(
            DB::table('notifications')->where('data', 'like', '%intel_rebuild_stale%')->value('data'),
            true,
        );
        $this->assertStringContainsString('never', strtolower($data['title']));
    }

    // ── Without --alert it observes and says nothing ────────────────────────────────────────────

    public function test_without_the_alert_flag_it_reports_but_never_pages(): void
    {
        $this->ageLedgerBy(40);

        $exit = Artisan::call('intelligence:rebuild-health');

        // Inspecting health must be safe to run at any time — it is a diagnostic, not an incident.
        $this->assertSame(0, $exit);
        $this->assertSame(0, $this->alertsSent());
    }

    public function test_the_threshold_comes_from_the_metric_contract(): void
    {
        $limit = (int) config('metrics.recurrence.freshness.stale_after_hours');
        $this->assertSame(36, $limit);

        $this->ageLedgerBy($limit - 2);
        $this->assertFalse($this->ledger->health('fault_recurrence_pairs')['is_stale'], 'inside the limit is healthy');

        $this->ageLedgerBy($limit + 2);
        $this->assertTrue($this->ledger->health('fault_recurrence_pairs')['is_stale'], 'past the limit is stale');
    }

    protected function tearDown(): void
    {
        // Leave the clone as we found it — the other golden suites read these tables.
        DB::table('notifications')->where('data', 'like', '%intel_rebuild_stale%')->delete();
        Artisan::call('intelligence:rebuild-visits');
        Artisan::call('intelligence:rebuild-recurrence');

        parent::tearDown();
    }
}
