<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Contract;
use App\Models\InspectionSchedule;
use App\Models\ServiceReminder;
use App\Models\Vehicle;
use App\Services\NotificationScanner;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Notification Test Console (ADMIN-ONLY) — fire a realistic FleetAlert of a chosen type on demand,
 * so the whole realtime pipeline can be verified end-to-end without waiting for the 10-minute
 * scheduler or engineering a real fleet condition. Because it delivers through the normal
 * NotificationScanner → FleetAlert path, a test alert behaves EXACTLY like a real one:
 *   - it is written to the database (`notifications` table) — the audit log, and
 *   - it hits the in-app bell on the next poll (raise → badge → toast), and
 *   - it will automatically ride any channel FleetAlert::via() later adds (broadcast / mail / SMS).
 *
 * Two modes:
 *   - REAL  (force=false): find an ACTUAL matching record (a genuinely-overdue reminder, an open
 *     rental, a balance-owing contract, a due inspection) and fire its real payload. If none exists
 *     it refuses with a hint — so a "real" test never fabricates live data.
 *   - FORCE (force=true): synthesize the payload from the first available record (or plain sample
 *     text) even when the condition is NOT met — always fires. This is the "trigger it anyway" flag.
 *
 * Every test alert carries a unique `test:{trigger}:{ts}` key (repeated fires each create a fresh
 * card, for stress-testing) and `meta.simulated = true` (identifiable + purgeable, and never touched
 * by the scan's auto-resolve, which only manages live-condition keys). Titles are marked 🧪 so a
 * simulated card is never mistaken for a genuine live condition in the feed.
 */
class NotificationTestController extends Controller
{
    /** trigger => [alert type, the permission a user needs to RECEIVE it on broadcast fan-out]. */
    private const TRIGGERS = [
        'overdue_service' => ['service_reminder_due', 'reminders.view'],
        'rental_expiry'   => ['rental_expiring', 'contracts.view'],
        'invoice_overdue' => ['invoice_overdue', 'billing.view'],
        'inspection_due'  => ['inspection_due', 'inspections.view'],
    ];

    /**
     * Fire one test notification.
     *
     * Body: { trigger: overdue_service|rental_expiry|invoice_overdue|inspection_due,
     *         force?: bool, broadcast?: bool }
     */
    public function fire(Request $request, NotificationScanner $scanner)
    {
        $data = $request->validate([
            'trigger'   => ['required', 'string', Rule::in(array_keys(self::TRIGGERS))],
            'force'     => ['nullable', 'boolean'],
            'broadcast' => ['nullable', 'boolean'],
        ]);

        $trigger = $data['trigger'];
        $force   = (bool) ($data['force'] ?? false);

        try {
            $payload = $this->build($trigger, $force);
        } catch (\RuntimeException $e) {
            // REAL mode found no matching live condition — tell the user to use Force.
            return ResponseHelper::FailureResponse(['trigger' => $trigger], $e->getMessage(), 422);
        }

        // Deliver. Default: only the admin who clicked (a predictable single-bell test). broadcast=true
        // fans it out to every active user holding the alert's real permission — a true role-gated test.
        if (! empty($data['broadcast'])) {
            [, $permission] = self::TRIGGERS[$trigger];
            $delivered = $scanner->notifyByPermission($permission, $payload);
        } else {
            $scanner->notifyUser($request->user(), $payload);
            $delivered = 1;
        }

        return ResponseHelper::SuccessResponse([
            'trigger'      => $trigger,
            'mode'         => $force ? 'forced' : 'real',
            'delivered_to' => $delivered,
            'payload'      => $payload,
        ], 'Test notification fired — check the bell');
    }

    // ── Payload builders ─────────────────────────────────────────────────────────

    /** Wrap a type-specific payload with the shared test key + simulated meta. */
    private function build(string $trigger, bool $force): array
    {
        $p = match ($trigger) {
            'overdue_service' => $this->overdueService($force),
            'rental_expiry'   => $this->rentalExpiry($force),
            'invoice_overdue' => $this->invoiceOverdue($force),
            'inspection_due'  => $this->inspectionDue($force),
        };

        $p['key']  = 'test:' . $trigger . ':' . now()->timestamp . ':' . Str::random(4);
        $p['meta'] = array_merge($p['meta'] ?? [], ['simulated' => true, 'forced' => $force]);

        return $p;
    }

    /** overdue_service — a non-oil service reminder past its due point (e.g. brake pads). */
    private function overdueService(bool $force): array
    {
        if (! $force) {
            $r = ServiceReminder::with('vehicle')->where('active', true)->where('is_muted', false)
                ->where('service_type', '<>', 'oil_change')->get()
                ->first(fn (ServiceReminder $x) => $x->statusInfo()['status'] === 'overdue');
            if (! $r) {
                throw new \RuntimeException('No overdue (non-oil) service reminder exists right now — enable Force to simulate one.');
            }
            $s    = $r->statusInfo();
            $v    = $r->vehicle;
            $over = $s['km_remaining'] !== null && $s['km_remaining'] < 0
                ? number_format(abs($s['km_remaining'])) . ' km over' : 'overdue';
            return [
                'type' => 'service_reminder_due', 'category' => 'maintenance', 'severity' => 'warning', 'icon' => 'wrench',
                'title' => '🧪 ' . $r->displayName() . ' overdue · ' . $over,
                'body'  => trim(($v?->plate_no ? '(' . $v->plate_no . ') ' : '') . $r->displayName() . ' service is overdue'),
                'url'   => $v ? '/vehicles/' . $v->id : '/reminders/service',
            ];
        }

        $v = Vehicle::whereNotNull('plate_no')->first();
        return [
            'type' => 'service_reminder_due', 'category' => 'maintenance', 'severity' => 'warning', 'icon' => 'wrench',
            'title' => '🧪 Brake Pads overdue · 5,000 km over',
            'body'  => trim(($v?->plate_no ? '(' . $v->plate_no . ') ' : '(sample) ') . 'Brake Pads service is overdue'),
            'url'   => $v ? '/vehicles/' . $v->id : '/reminders/service',
        ];
    }

    /** rental_expiry — an open rental approaching its return date (simulated at 3 days out). */
    private function rentalExpiry(bool $force): array
    {
        $c = Contract::where('contract_type', 'C')->where('state', 'open')
            ->with(['customer', 'vehicle'])->latest('id')->first();
        if (! $c && ! $force) {
            throw new \RuntimeException('No open rental contract to sample — enable Force to simulate one.');
        }
        $car = $c?->vehicle ? trim($c->vehicle->make . ' ' . $c->vehicle->model) : 'Toyota Camry';
        $who = $c?->customer?->name_en ?: 'Ahmed K.';
        return [
            'type' => 'rental_expiring', 'category' => 'operations', 'severity' => 'warning', 'icon' => 'clock',
            'title' => '🧪 Rental expiring · 3d',
            'body'  => trim($car . ($c?->vehicle?->plate_no ? ' (' . $c->vehicle->plate_no . ')' : '') . ' · ' . $who . ' — due in 3 days'),
            'url'   => $c ? '/contracts/' . $c->id : '/contracts',
        ];
    }

    /** invoice_overdue — a customer with an outstanding balance (pending payment). */
    private function invoiceOverdue(bool $force): array
    {
        $c = Contract::where('contract_balance', '>', 0)->with(['customer'])->latest('id')->first();
        if (! $c && ! $force) {
            throw new \RuntimeException('No balance-owing contract found — enable Force to simulate one.');
        }
        $who = $c?->customer?->name_en ?: 'Ahmed K.';
        $amt = $c ? (float) $c->contract_balance : 1450;
        return [
            'type' => 'invoice_overdue', 'category' => 'finance', 'severity' => 'warning', 'icon' => 'dollar',
            'title' => '🧪 Payment overdue · AED ' . number_format($amt),
            'body'  => trim($who . ($c?->contract_no ? ' · ' . $c->contract_no : '') . ' — payment pending AED ' . number_format($amt)),
            'url'   => $c ? '/contracts/' . $c->id : '/contracts',
        ];
    }

    /** inspection_due — a scheduled inspection that is overdue or due soon. */
    private function inspectionDue(bool $force): array
    {
        if (! $force) {
            $sch = InspectionSchedule::with('vehicle')->get()
                ->first(fn ($x) => in_array($x->statusInfo()['status'] ?? 'ok', ['overdue', 'due_soon'], true));
            if (! $sch) {
                throw new \RuntimeException('No inspection is currently due or overdue — enable Force to simulate one.');
            }
            $v = $sch->vehicle;
            $s = $sch->statusInfo();
            return [
                'type' => 'inspection_due', 'category' => 'operations', 'icon' => 'clipboard',
                'severity' => $s['status'] === 'overdue' ? 'warning' : 'info',
                'title' => '🧪 Inspection ' . ($s['status'] === 'overdue' ? 'overdue' : 'due soon') . ' · ' . ($sch->name ?: 'Safety check'),
                'body'  => trim(($v?->plate_no ? '(' . $v->plate_no . ') ' : '') . 'inspection ' . strtolower($s['label'])),
                'url'   => $v ? '/vehicles/' . $v->id : '/inspections/schedules',
            ];
        }

        $v = Vehicle::whereNotNull('plate_no')->first();
        return [
            'type' => 'inspection_due', 'category' => 'operations', 'severity' => 'info', 'icon' => 'clipboard',
            'title' => '🧪 Inspection due soon · Safety check',
            'body'  => trim(($v?->plate_no ? '(' . $v->plate_no . ') ' : '(sample) ') . 'safety inspection due in 3 days'),
            'url'   => $v ? '/vehicles/' . $v->id : '/inspections/schedules',
        ];
    }
}
