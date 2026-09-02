<?php

namespace App\Jobs;

use App\Models\FinancialEvent;
use App\Models\User;
use App\Services\Odoo\FinancialEventSyncService;
use App\Support\FinancialSyncStatus as Status;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Push one financial event to Odoo off the request thread (§36).
 *
 * The application already runs the database queue driver with a jobs table, so this uses it rather than
 * introducing anything new. A push is an RPC round trip to an external accounting system — occasionally
 * a slow one, and the app is served single-threaded in development — so making a user wait on it is how
 * the whole UI freezes when Odoo has a bad afternoon.
 *
 * ── WHY tries = 1 ──────────────────────────────────────────────────────────────────────────────────
 *
 * This looks wrong for an integration and is deliberate. The failure is not lost: the SERVICE catches
 * it, records the attempt, writes FAILED with the reason and leaves the event retryable — so a job that
 * "fails" has already done its job of recording what happened. Letting the queue ALSO retry would push
 * the same event again on a schedule nobody chose, multiplying attempt rows and hammering an Odoo that
 * may be refusing us for a good reason.
 *
 * Retrying is a decision, not a reflex: a person presses Retry, or an operator runs `odoo:retry-failed`.
 * Both go back through the same idempotency search, so neither can duplicate a document.
 *
 * The exception to that is a genuinely unexpected crash (the process dies, the DB drops) — which leaves
 * the event at SENDING, and {@see FinancialEventSyncService::reconcile()} is the path that resolves it
 * by asking Odoo rather than by guessing.
 */
class SyncFinancialEventToOdoo implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /**
     * How long the uniqueness lock is held if the job never reports back. Longer than $timeout, so a
     * job killed mid-flight cannot have a second copy start while the first may still be talking to
     * Odoo — but bounded, so a lost lock cannot block the event forever.
     */
    public int $uniqueFor = 300;

    /** See the class docblock — retrying is an explicit decision, never the queue's reflex. */
    public int $tries = 1;

    /** Comfortably longer than the client's own timeout, so the job never dies mid-RPC. */
    public int $timeout = 120;

    public function __construct(
        public int $financialEventId,
        public ?int $actorId = null,
    ) {
    }

    public function handle(FinancialEventSyncService $sync): void
    {
        $event = FinancialEvent::with(['lines.catalogPart', 'vehicle', 'vendor', 'maintenance'])
            ->find($this->financialEventId);

        // The event was deleted, or somebody sent it by hand while this sat in the queue. Both are
        // ordinary; neither is a failure worth raising.
        if (! $event || $event->status === Status::SYNCED || $event->status === Status::CANCELLED) {
            return;
        }

        $sync->sync($event, $this->actorId ? User::find($this->actorId) : null);
    }

    /**
     * A unique job identity, so queueing the same event twice does not run two pushes at once.
     *
     * Two concurrent pushes would both search, both find nothing, and both create — the one race the
     * idempotency ref cannot close on its own, because the guard and the create are not atomic in Odoo.
     * Serialising per event is what closes it.
     */
    public function uniqueId(): string
    {
        return 'financial-event-' . $this->financialEventId;
    }
}
