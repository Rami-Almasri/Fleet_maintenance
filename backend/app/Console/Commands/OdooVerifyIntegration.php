<?php

namespace App\Console\Commands;

use App\Models\ExpenseTypeMapping;
use App\Models\FinancialEvent;
use App\Models\OdooReferenceRecord;
use App\Services\Odoo\FinancialDashboardService;
use App\Services\Odoo\FinancialEventSyncService;
use App\Services\Odoo\OdooClient;
use App\Support\ExpenseType;
use App\Support\FinancialSyncStatus as Status;
use Illuminate\Console\Command;

/**
 * Walk the REAL integration path against the configured Odoo and report exactly how far it gets.
 *
 * This exists because "the tests pass" and "the integration works" are different claims. The test suite
 * proves the logic against an in-memory Odoo double; only this command proves that THIS environment can
 * actually reach THIS Odoo, that the master data is there, that the mappings resolve, and — with
 * --push — that a document is genuinely created.
 *
 * It is deliberately a LADDER, and it stops at the first rung that fails, because every later rung
 * would be meaningless: there is no point reporting on mappings when the credentials are wrong.
 *
 *   1. configured?          are credentials present at all
 *   2. reachable?           a real login against the real server
 *   3. master data?         have accounts / analytic accounts / products / partners been pulled
 *   4. expense accounts?    have all seven types been pointed at a real account
 *   5. events?              is there anything READY to send
 *   6. push (opt-in)        actually create ONE document, and prove the idempotency guard
 *
 * Step 6 requires --push and names the event it will send, because it writes to a live accounting
 * system. Running it twice on the same event is safe and is in fact the best demonstration this
 * integration has: the second run LINKS instead of creating, and says so.
 */
class OdooVerifyIntegration extends Command
{
    protected $signature = 'odoo:verify
        {--push : actually create a document in Odoo for one READY event (writes to live Odoo)}
        {--event= : the financial event id to push (defaults to the oldest READY one)}';

    protected $description = 'Verify the real Odoo integration path: connection → master data → mappings → event → document';

    public function handle(
        OdooClient $client,
        FinancialDashboardService $dashboard,
        FinancialEventSyncService $sync,
    ): int {
        // ── 1. Configured ──────────────────────────────────────────────────────────────────────────
        $this->components->info('1/6  Configuration');

        if (! $client->isConfigured()) {
            $this->components->error('Odoo is NOT configured in this environment.');
            $this->line('     Set ODOO_URL, ODOO_DATABASE, ODOO_USERNAME and ODOO_PASSWORD in .env.');
            $this->newLine();
            $this->warn('     Nothing below this point can be verified. The integration is BUILT but UNVERIFIED.');

            return self::FAILURE;
        }
        $this->line('     credentials present · ' . config('odoo.database') . ' @ ' . config('odoo.url'));

        // ── 2. Reachable ───────────────────────────────────────────────────────────────────────────
        $this->components->info('2/6  Connection');
        $health = $client->health();

        if (! $health['connected']) {
            $this->components->error('Could not connect: ' . ($health['error'] ?: 'unknown error'));

            return self::FAILURE;
        }
        $this->line('     connected · uid ' . $health['uid'] . ' · server ' . ($health['version'] ?: 'unknown'));

        // ── 3. Master data ─────────────────────────────────────────────────────────────────────────
        $this->components->info('3/6  Master data');
        $counts = [];
        foreach (['account.account', 'account.analytic.account', 'product.product', 'res.partner'] as $model) {
            $counts[$model] = OdooReferenceRecord::forModel($model)->count();
            $this->line(sprintf('     %-28s %d cached', $model, $counts[$model]));
        }

        if (array_sum($counts) === 0) {
            $this->components->error('No master data has been pulled. Run `php artisan odoo:pull-master-data` first.');

            return self::FAILURE;
        }

        // ── 4. Expense accounts ────────────────────────────────────────────────────────────────────
        $this->components->info('4/6  Expense type → Odoo account');
        $unresolved = [];
        foreach (ExpenseType::ALL as $type) {
            $mapping = ExpenseTypeMapping::where('expense_type', $type)->first();
            $ok      = $mapping?->isUsable() ?? false;

            if (! $ok) {
                $unresolved[] = $type;
            }

            $this->line(sprintf(
                '     %-14s %s  %s',
                $type,
                $ok ? 'OK ' : '── ',
                $mapping?->odoo_account_name ?: '(no account configured)'
            ));
        }

        if ($unresolved !== []) {
            $this->components->warn(
                count($unresolved) . ' expense type(s) have no usable account: ' . implode(', ', $unresolved)
            );
            $this->line('     Resolve them on /odoo-mappings. Events of those types will block.');
        }

        // ── 5. Events ──────────────────────────────────────────────────────────────────────────────
        $this->components->info('5/6  Financial events');
        $summary = $dashboard->countsByStatus();
        foreach ($summary as $status => $n) {
            if ($n > 0) {
                $this->line(sprintf('     %-14s %d', $status, $n));
            }
        }

        $backlog = $dashboard->mappingBacklog();
        $this->line(sprintf(
            '     unmapped: %d vehicle(s), %d part(s), %d supplier(s)',
            $backlog['vehicles'], $backlog['parts'], $backlog['suppliers']
        ));

        // ── 6. Push ────────────────────────────────────────────────────────────────────────────────
        $this->components->info('6/6  Document creation');

        if (! $this->option('push')) {
            $this->line('     skipped — re-run with --push to create a real document in Odoo.');
            $this->newLine();
            $this->components->info('Connection, master data and mappings verified. No document was created.');

            return self::SUCCESS;
        }

        $event = $this->option('event')
            ? FinancialEvent::find((int) $this->option('event'))
            : FinancialEvent::where('status', Status::READY)->orderBy('id')->first();

        if (! $event) {
            $this->components->warn('No READY event to send. Nothing was created.');

            return self::SUCCESS;
        }

        if (! $event->isSendable()) {
            $this->components->error("Event #{$event->id} is {$event->status} and cannot be sent.");

            return self::FAILURE;
        }

        $this->line(sprintf(
            '     sending #%d · %s · %s %s · %s',
            $event->id,
            $event->expense_type,
            number_format((float) $event->amount, 2),
            $event->currency,
            $event->description
        ));

        $result = $sync->sync($event->load(['lines.catalogPart', 'vehicle', 'vendor', 'maintenance']));

        if ($result->status !== Status::SYNCED) {
            $this->components->error(
                'Not synced: ' . ($result->failure_code ?: $result->status) . ' — ' . ($result->failure_reason ?: '')
            );

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Created in Odoo: %s #%d%s',
            $result->odoo_document_model,
            $result->odoo_document_id,
            $result->odoo_document_reference ? ' (' . $result->odoo_document_reference . ')' : ''
        ));

        // The demonstration that matters: running the same push again must NOT create a second
        // document. The attempt is recorded as `linked`, which is the proof rather than the promise.
        $this->line('     re-running the same push to prove idempotency …');
        $again = $sync->reconcile(
            tap($result)->update(['status' => Status::SENDING])->fresh(['lines.catalogPart', 'vehicle', 'vendor', 'maintenance'])
        );

        $lastAttempt = $again->attempts()->latest('id')->first();

        if ($again->odoo_document_id === $result->odoo_document_id
            && $lastAttempt?->outcome === \App\Models\FinancialEventAttempt::OUTCOME_LINKED) {
            $this->components->info('Idempotency confirmed: the retry LINKED the existing document. No duplicate.');

            return self::SUCCESS;
        }

        $this->components->error('IDEMPOTENCY FAILED — the retry did not link the existing document. Investigate before using this in production.');

        return self::FAILURE;
    }
}
