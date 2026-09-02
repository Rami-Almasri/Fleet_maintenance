<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Resources\FinancialEventResource;
use App\Jobs\SyncFinancialEventToOdoo;
use App\Models\FinancialEvent;
use App\Services\Odoo\FinancialDashboardService;
use App\Services\Odoo\FinancialEventBuilder;
use App\Services\Odoo\FinancialEventSyncService;
use App\Support\FinancialSyncStatus as Status;
use Illuminate\Http\Request;

/**
 * The financial obligations queue and the actions on one obligation.
 *
 * Thin by design: every route resolves the event, calls ONE service method and returns the resource.
 * No Odoo call is made from here (§24) — the client lives behind the sync service, and the sync itself
 * is queued so a slow accounting server can never hold a web request open.
 *
 * Permissions are enforced by route middleware AND reflected in the resource's `actions`, so a button
 * is never offered that the route would then refuse.
 */
class FinancialEventController extends Controller
{
    public function __construct(
        private FinancialEventBuilder $builder,
        private FinancialEventSyncService $sync,
        private FinancialDashboardService $dashboard,
    ) {
    }

    /**
     * The queue, filtered.
     *
     * `status`, `expense_type`, `vehicle_id` and `maintenance_id` narrow it; `outstanding=1` is the
     * common case (everything still owed to Odoo) and exists as its own flag because asking for it by
     * listing five statuses is the kind of thing every caller would get subtly wrong.
     */
    public function index(Request $request)
    {
        try {
            $query = FinancialEvent::query()
                ->with(['vehicle:id,plate_no,vin', 'vendor:id,name', 'lines'])
                ->latest('id');

            if ($request->boolean('outstanding')) {
                $query->outstanding();
            }
            if ($status = $request->query('status')) {
                $query->whereIn('status', array_filter((array) explode(',', (string) $status)));
            }
            if ($type = $request->query('expense_type')) {
                $query->whereIn('expense_type', array_filter((array) explode(',', (string) $type)));
            }
            if ($vehicleId = $request->query('vehicle_id')) {
                $query->where('vehicle_id', (int) $vehicleId);
            }
            if ($ticketId = $request->query('maintenance_id')) {
                $query->where('maintenance_id', (int) $ticketId);
            }
            // The dashboard's drill-down: "the 2 blocked by a missing product mapping". Matching inside
            // the stored JSON is what makes a reason-code count clickable.
            if ($code = $request->query('block_reason')) {
                $query->where('block_reasons', 'like', '%"' . $code . '"%');
            }

            $events = $query->paginate(min(100, (int) $request->query('per_page', 25)));

            return ResponseHelper::SuccessResponse([
                'items' => FinancialEventResource::collection($events->items()),
                'meta'  => [
                    'total'        => $events->total(),
                    'per_page'     => $events->perPage(),
                    'current_page' => $events->currentPage(),
                    'last_page'    => $events->lastPage(),
                ],
            ], 'Financial events');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function show(FinancialEvent $event)
    {
        try {
            $event->load(['vehicle:id,plate_no,vin', 'vendor:id,name', 'lines.catalogPart', 'maintenance:id']);

            return ResponseHelper::SuccessResponse(new FinancialEventResource($event), 'Financial event');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Re-check the event against the mappings as they stand now.
     *
     * The action somebody presses after mapping the product that was blocking it. Safe to call at any
     * time; on a frozen event it is a no-op that simply returns the event.
     */
    public function validateEvent(FinancialEvent $event)
    {
        try {
            $fresh = $this->builder->revalidate(
                $event->load(['lines.catalogPart', 'vehicle', 'vendor'])
            );

            return ResponseHelper::SuccessResponse(
                new FinancialEventResource($fresh->load(['vehicle:id,plate_no,vin', 'vendor:id,name', 'lines'])),
                $fresh->status === Status::READY
                    ? 'Ready for Odoo'
                    : 'Validation found ' . count($fresh->blockReasons()) . ' item(s) to resolve'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Send it.
     *
     * Queued unless `?now=1`. The synchronous path exists for operators and tests — somebody debugging a
     * mapping wants the answer in the response, not in a worker log — and is the same code path either
     * way, so it cannot behave differently.
     */
    public function syncEvent(Request $request, FinancialEvent $event)
    {
        try {
            if ($event->status === Status::SYNCED) {
                return ResponseHelper::SuccessResponse(
                    new FinancialEventResource($event),
                    'Already synced to Odoo'
                );
            }

            if (! $event->isSendable()) {
                return ResponseHelper::FailureResponse(
                    new FinancialEventResource($event),
                    'This event is ' . Status::label($event->status) . ' and cannot be sent.',
                    422
                );
            }

            if ($request->boolean('now')) {
                $result = $this->sync->sync(
                    $event->load(['lines.catalogPart', 'vehicle', 'vendor', 'maintenance']),
                    $request->user()
                );

                return ResponseHelper::SuccessResponse(
                    new FinancialEventResource($result->load(['vehicle:id,plate_no,vin', 'vendor:id,name', 'lines'])),
                    $result->status === Status::SYNCED ? 'Synced to Odoo' : 'Sync did not complete'
                );
            }

            SyncFinancialEventToOdoo::dispatch($event->id, $request->user()?->id);

            return ResponseHelper::SuccessResponse(
                new FinancialEventResource($event),
                'Queued for Odoo'
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Try again after a failure — or, for an event stranded mid-flight, ask Odoo what happened.
     *
     * Both go through the same idempotency search, so neither can create a duplicate document. The
     * SENDING case is routed to reconcile() rather than to a fresh send precisely because we do not
     * know whether the first attempt landed.
     */
    public function retry(Request $request, FinancialEvent $event)
    {
        try {
            if ($event->status === Status::SENDING) {
                $result = $this->sync->reconcile(
                    $event->load(['lines.catalogPart', 'vehicle', 'vendor', 'maintenance']),
                    $request->user()
                );

                return ResponseHelper::SuccessResponse(
                    new FinancialEventResource($result->load(['vehicle:id,plate_no,vin', 'vendor:id,name', 'lines'])),
                    $result->status === Status::SYNCED
                        ? 'Odoo already had this document — linked, not duplicated'
                        : 'Odoo could not confirm this document'
                );
            }

            if ($event->status !== Status::FAILED) {
                return ResponseHelper::FailureResponse(
                    new FinancialEventResource($event),
                    'Only a failed or in-flight event can be retried.',
                    422
                );
            }

            SyncFinancialEventToOdoo::dispatch($event->id, $request->user()?->id);

            return ResponseHelper::SuccessResponse(new FinancialEventResource($event), 'Retry queued');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function approve(Request $request, FinancialEvent $event)
    {
        try {
            $result = $this->sync->approve($event, $request->user());

            return ResponseHelper::SuccessResponse(new FinancialEventResource($result), 'Approved');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function cancel(Request $request, FinancialEvent $event)
    {
        try {
            $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

            $result = $this->sync->cancel($event, $request->user(), $data['reason']);

            return ResponseHelper::SuccessResponse(new FinancialEventResource($result), 'Cancelled');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** The sync dashboard (§40) — counts, money, and the reasons behind the blocked ones. */
    public function summary()
    {
        try {
            return ResponseHelper::SuccessResponse($this->dashboard->summary(), 'Financial integration status');
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
