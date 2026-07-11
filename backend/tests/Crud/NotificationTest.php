<?php

namespace Tests\Crud;

use App\Models\User;
use App\Services\NotificationScanner;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * Deep coverage of the in-app notification centre (the bell):
 *   - the user-scoped CRUD (index / poll / mark-read / mark-all / dismiss / clear)
 *   - the demo self-alert pipeline
 *   - the admin Notification Test Console (every trigger, real + forced)
 *   - per-user isolation (you never see another user's notifications)
 *   - the notifications:scan detector command runs clean
 */
class NotificationTest extends CrudTestCase
{
    // ── The bell: empty state ────────────────────────────────────────────────
    public function test_bell_starts_empty(): void
    {
        $this->getJson('/api/notifications')->assertSuccessful()
            ->assertJsonPath('data.unread_count', 0)
            ->assertJsonPath('data.total', 0);

        $this->getJson('/api/notifications/poll')->assertSuccessful()
            ->assertJsonPath('data.unread_count', 0);
    }

    // ── Demo self-alert: raise → poll → badge ────────────────────────────────
    public function test_demo_alert_reaches_the_bell(): void
    {
        $this->postJson('/api/notifications/demo')->assertSuccessful();

        $poll = $this->getJson('/api/notifications/poll')->assertSuccessful();
        $this->assertGreaterThanOrEqual(1, $poll->json('data.unread_count'));
        $this->assertNotEmpty($poll->json('data.latest'));

        $this->getJson('/api/notifications')->assertSuccessful()
            ->assertJsonPath('data.total', 1);
    }

    // ── Mark one read, then dismiss it ───────────────────────────────────────
    public function test_mark_read_and_dismiss_single(): void
    {
        $this->postJson('/api/notifications/demo')->assertSuccessful();
        $id = $this->getJson('/api/notifications')->json('data.items.0.id');
        $this->assertNotNull($id);

        $this->postJson("/api/notifications/$id/read")->assertSuccessful()
            ->assertJsonPath('data.unread_count', 0);

        // Still present (read, not deleted) until dismissed.
        $this->getJson('/api/notifications?filter=unread')->assertJsonPath('data.total', 0);

        $this->deleteJson("/api/notifications/$id")->assertSuccessful();
        $this->getJson('/api/notifications')->assertJsonPath('data.total', 0);
    }

    // ── Mark all read ────────────────────────────────────────────────────────
    public function test_mark_all_read(): void
    {
        $this->postJson('/api/notifications/demo');
        $this->postJson('/api/notifications/demo');

        $this->postJson('/api/notifications/read-all')->assertSuccessful()
            ->assertJsonPath('data.unread_count', 0);
    }

    // ── Clear the whole history ──────────────────────────────────────────────
    public function test_clear_history(): void
    {
        $this->postJson('/api/notifications/demo');
        $this->postJson('/api/notifications/clear')->assertSuccessful();
        $this->getJson('/api/notifications')->assertJsonPath('data.total', 0);
    }

    // ── Isolation: a notification for me is invisible to another user ────────
    public function test_notifications_are_user_scoped(): void
    {
        $this->postJson('/api/notifications/demo')->assertSuccessful();

        $other = User::create([
            'name' => 'Other', 'email' => 'other.' . uniqid() . '@fleet.test',
            'password' => Hash::make('password'), 'status' => 'active',
        ]);
        $other->assignRole('super-admin');
        Sanctum::actingAs($other, ['*']);

        $this->getJson('/api/notifications')->assertSuccessful()
            ->assertJsonPath('data.total', 0);
    }

    // ── Admin Test Console: forced fire of every trigger ─────────────────────
    public static function triggerProvider(): array
    {
        return [
            ['overdue_service'],
            ['rental_expiry'],
            ['invoice_overdue'],
            ['inspection_due'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('triggerProvider')]
    public function test_admin_console_forced_fire(string $trigger): void
    {
        $res = $this->postJson('/api/admin/notifications/test', ['trigger' => $trigger, 'force' => true]);
        $res->assertSuccessful()
            ->assertJsonPath('data.trigger', $trigger)
            ->assertJsonPath('data.mode', 'forced');

        // It really landed on the bell.
        $this->getJson('/api/notifications/poll')->assertJsonPath('data.unread_count', 1);
    }

    // ── Admin Test Console: REAL mode refuses to fabricate on a clean fleet ──
    public function test_admin_console_real_mode_refuses_without_live_condition(): void
    {
        // Clean test DB → no overdue reminder exists → REAL mode must 422 with a hint.
        $this->postJson('/api/admin/notifications/test', ['trigger' => 'overdue_service', 'force' => false])
            ->assertStatus(422);
    }

    public function test_admin_console_rejects_unknown_trigger(): void
    {
        $this->postJson('/api/admin/notifications/test', ['trigger' => 'bogus', 'force' => true])
            ->assertStatus(422);
    }

    // ── Admin console broadcast fan-out counts recipients ────────────────────
    public function test_admin_console_broadcast_delivers_to_holders(): void
    {
        $res = $this->postJson('/api/admin/notifications/test', [
            'trigger' => 'rental_expiry', 'force' => true, 'broadcast' => true,
        ]);
        $res->assertSuccessful();
        // At least the acting super-admin holds contracts.view, so >=1 recipient.
        $this->assertGreaterThanOrEqual(1, $res->json('data.delivered_to'));
    }

    // ── The scheduled detector sweep runs clean ──────────────────────────────
    public function test_notifications_scan_command_runs(): void
    {
        $this->artisan('notifications:scan')->assertExitCode(0);
    }

    public function test_scanner_service_scan_returns_array(): void
    {
        $summary = app(NotificationScanner::class)->scan();
        $this->assertIsArray($summary);
    }
}
