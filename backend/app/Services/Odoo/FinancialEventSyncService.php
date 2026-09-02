<?php

namespace App\Services\Odoo;

use App\Exceptions\OdooException;
use App\Models\FinancialEvent;
use App\Models\FinancialEventAttempt;
use App\Models\User;
use App\Models\VehicleLogEvent;
use App\Services\VehicleLogService;
use App\Support\FinancialSyncStatus as Status;
use Illuminate\Support\Facades\DB;

/**
 * Moves a financial event through its life, and owns the one sequence that must never be got wrong.
 *
 * ── THE SEQUENCE (§35) ─────────────────────────────────────────────────────────────────────────────
 *
 *     DB   status = SENDING, mapping snapshot frozen     ← COMMITTED before anything leaves
 *      ↓
 *     ODOO create (or adopt) the document
 *      ↓
 *     DB   status = SYNCED + odoo_document_id            ← only ever written by a confirmed result
 *
 * The commit before the call is the whole design. If the process dies mid-push, the row on disk says
 * SENDING, which is the truthful statement "a document may exist and we do not know". Had SENDING been
 * held inside the same transaction as the result, a crash would roll it back to READY and the next
 * person would press Send on an event whose bill already exists. FinancialSyncStatus deliberately gives
 * SENDING no transition back to READY for the same reason: the only way out is to ASK Odoo, which is
 * {@see reconcile()}.
 *
 * SYNCED is never written speculatively. It requires an Odoo document id in hand.
 *
 * ── VALIDATION HAPPENS TWICE ───────────────────────────────────────────────────────────────────────
 *
 * Once when the event is built or a mapping changes (so the screen is honest), and again here in the
 * instant before the freeze. The second run is not redundant: a mapping can be withdrawn between a
 * person seeing READY and pressing Send, and the ledger is the wrong place to discover that.
 */
class FinancialEventSyncService
{
    public function __construct(
        private FinancialValidator $validator,
        private FinancialEventBuilder $builder,
        private OdooDocumentPusher $pusher,
        private OdooMappingService $mappings,
        private OdooClient $client,
        private VehicleLogService $log,
    ) {
    }

    /**
     * Send one event to Odoo.
     *
     * Returns the event in whatever state it ended up in — SYNCED, FAILED or BLOCKED. It does not throw
     * on an Odoo failure: a failure is a legitimate, recorded, retryable outcome of this operation, not
     * an exception in the caller's flow. Only a programming error (sending something not sendable)
     * throws.
     */
    public function sync(FinancialEvent $event, ?User $actor = null): FinancialEvent
    {
        if ($event->status === Status::SYNCED) {
            return $event;   // already done; sending again is a no-op, not an error
        }

        if (! $event->isSendable() && $event->status !== Status::SENDING) {
            throw new \RuntimeException(
                "Financial event #{$event->id} cannot be sent from status {$event->status}."
            );
        }

        // A stranded in-flight event is resolved by asking Odoo, never by re-sending blind.
        if ($event->status === Status::SENDING) {
            return $this->reconcile($event, $actor);
        }

        // Second validation — see the class docblock.
        $event = $this->builder->revalidate($event->fresh(['lines.catalogPart', 'vehicle', 'vendor']));

        if ($event->status !== Status::READY) {
            return $event;
        }

        $this->beginSending($event, $actor);

        return $this->attempt($event, $actor);
    }

    /**
     * Resolve an event stuck in SENDING by asking Odoo what actually happened.
     *
     * This is the recovery path for §50's "network timeout after Odoo success": the push runs again, the
     * idempotency search finds the document that was created, and the attempt is recorded as `linked`.
     * Nothing is created, and no duplicate can arise, because the search runs before any create on every
     * attempt — see OdooDocumentPusher.
     */
    public function reconcile(FinancialEvent $event, ?User $actor = null): FinancialEvent
    {
        if ($event->status !== Status::SENDING) {
            return $event;
        }

        return $this->attempt($event, $actor);
    }

    /**
     * Freeze what will be sent and commit SENDING before anything leaves the process.
     *
     * The snapshot is taken here, not in the pusher, so that the document is built from exactly the
     * mappings that were validated a moment ago — a mapping edited mid-push cannot change the coding of
     * a document already in flight.
     */
    private function beginSending(FinancialEvent $event, ?User $actor): void
    {
        DB::transaction(function () use ($event, $actor) {
            $mapping = $this->mappings->expenseTypeMapping((string) $event->expense_type);

            $event->fill([
                'status'                   => Status::SENDING,
                'odoo_account_id'          => $mapping?->odoo_account_id,
                'odoo_journal_id'          => $mapping?->odoo_journal_id,
                'odoo_document_type'       => $mapping?->odoo_document_type ?: $event->odoo_document_type,
                'odoo_analytic_account_id' => $this->mappings->analyticAccountIdFor($event->vehicle),
                'odoo_partner_id'          => $this->mappings->partnerIdFor($event->vendor),
                'attempts'                 => (int) $event->attempts + 1,
                'last_attempt_at'          => now(),
                'failure_code'             => null,
                'failure_reason'           => null,
            ])->save();

            // Freeze each part line's product id alongside, for the same reason.
            foreach ($event->lines as $line) {
                if (! $line->requiresProductMapping()) {
                    continue;
                }
                $line->odoo_product_id = $this->mappings->productIdFor($line->catalogPart);
                $line->save();
            }
        });

        $this->audit($event, VehicleLogEvent::EVENT_FINANCIAL_SYNC_STARTED, $actor, [
            'attempt' => $event->attempts,
        ]);
    }

    /** One push, with its attempt row and its outcome written whatever happens. */
    private function attempt(FinancialEvent $event, ?User $actor): FinancialEvent
    {
        $startedAt = now();
        $started   = microtime(true);

        try {
            $result = $this->pusher->push($event->fresh(['lines.catalogPart', 'vehicle', 'vendor']));

            // The document is confirmed. Record it FIRST — if anything below fails, the event must
            // already know the id, or a retry would go hunting for a bill we forgot we made.
            $event->fill([
                'status'                  => Status::SYNCED,
                'odoo_document_id'        => $result['odoo_id'],
                'odoo_document_model'     => $result['odoo_model'],
                'odoo_document_reference' => $result['reference'],
                'synced_at'               => now(),
                'block_reasons'           => null,
                'failure_code'            => null,
                'failure_reason'          => null,
            ])->save();

            $this->recordAttempt($event, $result['outcome'], $actor, $startedAt, $started, [
                'odoo_model'       => $result['odoo_model'],
                'odoo_document_id' => $result['odoo_id'],
                'request_payload'  => $result['payload'] ?: null,
            ]);

            $this->audit($event, VehicleLogEvent::EVENT_FINANCIAL_SYNCED, $actor, [
                'odoo_document_id'        => $result['odoo_id'],
                'odoo_document_model'     => $result['odoo_model'],
                'odoo_document_reference' => $result['reference'],
                // 'linked' means a document already existed under our reference and was adopted —
                // the proof that a duplicate was avoided rather than never attempted.
                'outcome'                 => $result['outcome'],
            ]);

            $this->client->log('financial event synced', [
                'financial_event_id' => $event->id,
                'outcome'            => $result['outcome'],
                'odoo_model'         => $result['odoo_model'],
                'odoo_document_id'   => $result['odoo_id'],
                'attempt'            => $event->attempts,
            ]);

            return $event;
        } catch (OdooException $e) {
            return $this->fail($event, $actor, $startedAt, $started, $e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            // An unexpected fault is still a failed attempt, never a silent one. report() keeps the
            // stack trace where the app already sends them; the event records only the message.
            report($e);

            return $this->fail(
                $event,
                $actor,
                $startedAt,
                $started,
                FinancialEventAttempt::ERROR_UNEXPECTED,
                $e->getMessage()
            );
        }
    }

    private function fail(
        FinancialEvent $event,
        ?User $actor,
        $startedAt,
        float $started,
        string $code,
        string $message,
    ): FinancialEvent {
        $event->fill([
            'status'         => Status::FAILED,
            'failure_code'   => $code,
            'failure_reason' => $message,
        ])->save();

        $this->recordAttempt($event, FinancialEventAttempt::OUTCOME_FAILED, $actor, $startedAt, $started, [
            'error_code'    => $code,
            'error_message' => $message,
        ]);

        $this->audit($event, VehicleLogEvent::EVENT_FINANCIAL_SYNC_FAILED, $actor, [
            'error_code' => $code,
            'attempt'    => $event->attempts,
        ]);

        $this->client->log('financial event sync failed', [
            'financial_event_id' => $event->id,
            'error_code'         => $code,
            'attempt'            => $event->attempts,
        ]);

        return $event;
    }

    // ── The other lifecycle moves ─────────────────────────────────────────────────────────────────

    /**
     * A person accepts the obligation as real and payable.
     *
     * Approval is recorded on the event but is NOT a gate on sending — the gate is the `financial.sync`
     * permission plus a clean validation. Making approval a hard precondition would be a policy
     * decision, and the deployments that want it can enforce it by withholding `financial.sync` from
     * everyone who does not also approve. What approval buys is the audit answer to "who accepted this".
     */
    public function approve(FinancialEvent $event, User $actor): FinancialEvent
    {
        $event->fill(['approved_by' => $actor->id, 'approved_at' => now()])->save();

        $this->audit($event, VehicleLogEvent::EVENT_FINANCIAL_APPROVED, $actor, [
            'amount' => (float) $event->amount,
        ]);

        return $event;
    }

    /** Withdraw an obligation before it reaches Odoo. */
    public function cancel(FinancialEvent $event, User $actor, string $reason): FinancialEvent
    {
        if (! Status::canMove($event->status, Status::CANCELLED)) {
            throw new \RuntimeException(
                "A financial event in status {$event->status} cannot be cancelled."
            );
        }

        $event->fill([
            'status'              => Status::CANCELLED,
            'cancelled_at'        => now(),
            'cancellation_reason' => $reason,
        ])->save();

        $this->audit($event, VehicleLogEvent::EVENT_FINANCIAL_CANCELLED, $actor, ['reason' => $reason]);

        return $event;
    }

    // ── Audit (§33) ───────────────────────────────────────────────────────────────────────────────

    /**
     * Write the financial act into the CAR's own timeline, using the existing audit infrastructure.
     *
     * Only possible when the event hangs off a maintenance ticket — VehicleLogService is ticket-scoped.
     * An event with no ticket (a registration renewal, a taxi claim) still has its complete trail in
     * financial_event_attempts, which is where the technical detail lives for every event regardless.
     */
    private function audit(FinancialEvent $event, string $type, ?User $actor, array $meta = []): void
    {
        $ticket = $event->maintenance;

        if (! $ticket) {
            return;
        }

        $this->log->record($ticket, $type, $actor, [
            'description' => $event->expenseTypeLabel() . ' — ' . number_format((float) $event->amount, 2)
                . ' ' . $event->currency,
            'meta' => array_merge([
                'financial_event_id' => $event->id,
                'expense_type'       => $event->expense_type,
                'status'             => $event->status,
            ], $meta),
        ]);
    }

    private function recordAttempt(
        FinancialEvent $event,
        string $outcome,
        ?User $actor,
        $startedAt,
        float $started,
        array $extra = [],
    ): void {
        FinancialEventAttempt::create(array_merge([
            'financial_event_id' => $event->id,
            'attempt'            => (int) $event->attempts,
            'outcome'            => $outcome,
            'duration_ms'        => (int) round((microtime(true) - $started) * 1000),
            'triggered_by'       => $actor?->id,
            'started_at'         => $startedAt,
            'finished_at'        => now(),
        ], $extra));
    }
}
