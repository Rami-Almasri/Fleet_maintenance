<?php

namespace App\Services;

use App\Models\VehicleLogEvent;
use App\Models\Vendor;
use Illuminate\Support\Collection;

/**
 * Formats vehicle_log_events into flat spreadsheet rows for the Vehicle Timeline export.
 *
 * Pure presentation: given a batch of events (already eager-loaded), it emits one row of
 * [Date, Time, Vehicle, Plate, Ticket, Stage, Event, Description, Garage, Odometer, User] each.
 * The incremental high-water-mark + the actual Google push live in the SyncVehicleLogSheet command;
 * this class owns only the row shape so the two concerns stay independent and testable.
 */
class VehicleLogSheetExporter
{
    /** The sheet's header row — order is the contract with every appended data row below it. */
    public const HEADER = [
        'Date', 'Time', 'Vehicle', 'Plate', 'Ticket',
        'Stage', 'Event', 'Description', 'Garage', 'Odometer', 'User',
    ];

    /** vendor_id → name, loaded once (some events carry only a vendor_id, not a garage name). */
    protected ?array $vendorNames = null;

    /** Human labels for the workflow_status column ("Stage"). Anything unmapped is humanised. */
    protected const STAGE_LABELS = [
        'complaint_triage'           => 'Complaint Triage',
        'inspection_requested'       => 'Needs Test Drive',
        'inspection_diagnostic'      => 'Being Inspected',
        'inspection_pending'         => 'Needs Dispatch',
        'on_site_pending'            => 'On-Site Service',
        'awaiting_dispatch'          => 'Awaiting Pickup',
        'in_transit'                 => 'En Route to Garage',
        'under_repair'               => 'In Workshop',
        'repair_review'              => 'Video Review',
        'ready_for_pickup'           => 'Ready for Pickup',
        'in_our_park'                => 'In Our Park',
        'ready_for_reinspection'     => 'Final QA',
        'reinspection_failed'        => 'Came Back Broken',
        'paused_returned_to_service' => 'Paused — Returned to Service',
        'completed'                  => 'Completed',
        'closed'                     => 'Closed',
        'cancelled'                  => 'Cancelled',
    ];

    /** Human labels for the event_type column ("Event"). Anything unmapped is humanised. */
    protected const EVENT_LABELS = [
        'inspection_requested'      => 'Inspection Requested',
        'review_approved'           => 'Review Approved',
        'review_rejected'           => 'Review Rejected',
        'diagnostic_started'        => 'Test Drive Started',
        'report_filed'              => 'Report Filed',
        'diagnostic_cleared'        => 'Cleared — No Work',
        'garage_assigned'           => 'Dispatch Assigned',
        'dispatched'                => 'Picked Up',
        'under_repair'              => 'Arrived at Garage',
        'ready'                     => 'Repair Finished',
        'closed'                    => 'Ticket Closed',
        'reopened'                  => 'Re-opened',
        'type_changed'              => 'Type Changed',
        'reassigned'                => 'Driver Reassigned',
        'status_update'             => 'Status Update',
        'delegated'                 => 'Driver Delegated',
        'cost_recorded'             => 'Cost Recorded',
        'invoice_requested'         => 'Invoice Requested',
        'transport_assigned'        => 'Transport Assigned',
        'returned_to_service'       => 'Returned to Service',
        'resumed'                   => 'Resumed',
        'vehicle_returned'          => 'Vehicle Returned',
        'handover_incident'         => 'Handover Incident',
        'incident_acknowledged'     => 'Incident Cleared',
        'temp_released'             => 'Temporarily Released',
        'temp_returned'             => 'Returned to Workshop',
        'readiness_confirmed'       => 'Readiness Confirmed',
        'readiness_override'        => 'Readiness Override',
        'condition_graded'          => 'Condition Graded',
        'cleaning_updated'          => 'Cleaning Updated',
        'odometer_corrected'        => 'Odometer Corrected',
        'task_identified'           => 'Fault Identified',
        'task_assigned'             => 'Fault Assigned',
        'task_transferred'          => 'Fault Transferred',
        'task_resolved'             => 'Fault Resolved',
        'service_logged'            => 'Service Logged',
        'task_reinspection_failed'  => 'Failed Re-inspection',
        'task_marked_incorrect'     => 'Marked Mis-diagnosis',
        'awaiting_invoice'          => 'Awaiting Invoice',
        'invoice_received'          => 'Invoice Received',
        'garage_invoice_submitted'  => 'Garage Invoice Submitted',
        'garage_invoice_accepted'   => 'Garage Invoice Accepted',
        'garage_invoice_rejected'   => 'Garage Invoice Rejected',
        'recommendation_approved'   => 'Recommendation Approved',
        'recommendation_dismissed'  => 'Recommendation Dismissed',
        'recommendation_scheduled'  => 'Recommendation Scheduled',
        'parts_ordered'             => 'Parts Ordered',
        'parts_ready'               => 'Parts Ready',
        'part_requested'            => 'Part Requested',
        'part_approved'             => 'Part Approved',
        'part_rejected'             => 'Part Rejected',
        'part_purchased'            => 'Part Purchased',
        'part_installed'            => 'Part Installed',
        'part_completed'            => 'Part Completed',
        'part_duplicate_flagged'    => 'Duplicate Part Flagged',
        'part_recurrence_flagged'   => 'Fault Recurrence Flagged',
    ];

    /**
     * Turn a batch of events into spreadsheet rows.
     *
     * @param  Collection<int, VehicleLogEvent> $events
     * @return array<int, array<int, string>>
     */
    public function rows(Collection $events): array
    {
        return $events->map(fn (VehicleLogEvent $e) => $this->row($e))->all();
    }

    /** @return array<int, string> one row, aligned to self::HEADER. */
    public function row(VehicleLogEvent $e): array
    {
        $when = $e->occurred_at ?? $e->created_at;
        $vehicle = $e->vehicle;

        return [
            $when?->format('Y-m-d') ?? '',
            $when?->format('H:i') ?? '',
            $vehicle ? ($vehicle->code ?: trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? ''))) : '',
            $vehicle->plate_no ?? '',
            $e->maintenance_id ? (string) $e->maintenance_id : '',
            $this->label(self::STAGE_LABELS, $e->workflow_status),
            $this->label(self::EVENT_LABELS, $e->event_type),
            (string) ($e->description ?? ''),
            $this->garage($e),
            $this->odometer($e),
            $e->actor?->name ?? 'System',
        ];
    }

    /** Look up a label map, falling back to a humanised version of the raw key. */
    protected function label(array $map, ?string $key): string
    {
        if ($key === null || $key === '') {
            return '';
        }

        return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
    }

    /** Garage name from meta, else the resolved vendor_id, else blank. */
    protected function garage(VehicleLogEvent $e): string
    {
        $meta = (array) ($e->meta ?? []);

        foreach (['garage', 'from_garage', 'to_garage'] as $key) {
            if (! empty($meta[$key]) && is_string($meta[$key])) {
                return $meta[$key];
            }
        }

        if (! empty($meta['vendor_id'])) {
            return $this->vendorName((int) $meta['vendor_id']);
        }

        return '';
    }

    /**
     * Best odometer reading carried on the event. Events stamp the reading under various keys
     * (receive_odometer, test_odometer, dispatch_odometer, …); we take the first present one and
     * deliberately skip next_due_odometer (a future target, not a reading taken at this event).
     */
    protected function odometer(VehicleLogEvent $e): string
    {
        $meta = (array) ($e->meta ?? []);

        foreach ($meta as $key => $value) {
            if ($key === 'next_due_odometer') {
                continue;
            }
            if (($key === 'odometer' || str_ends_with((string) $key, '_odometer'))
                && is_numeric($value)) {
                return number_format((float) $value) . ' km';
            }
        }

        return '';
    }

    protected function vendorName(int $id): string
    {
        if ($this->vendorNames === null) {
            $this->vendorNames = Vendor::pluck('name', 'id')->all();
        }

        return (string) ($this->vendorNames[$id] ?? '');
    }
}
