<?php

namespace App\Support;

/**
 * The inbox-tab taxonomy for the notification centre — a SINGLE source of truth that maps the many
 * granular alert `type`s (see NotificationScanner + MaintenanceWorkflowService) into the handful of
 * human-facing categories the UI renders as tabs.
 *
 * Why a derived map instead of a stored column: notification queries are always pre-scoped to one
 * user's rows (the table is indexed on notifiable_id), so filtering that tiny set by a `whereIn` on
 * `data->type` is effectively free — a dedicated indexed category column would optimise a scan that
 * never happens. Keeping the grouping here (not on the row) also means a new alert type can never
 * silently land in the wrong tab because someone forgot to stamp it.
 *
 * The frontend mirrors this exact mapping in `frontend/src/lib/notifications.js` (INBOX_CATEGORIES) —
 * keep the two in lock-step when adding a type.
 */
class NotificationCategories
{
    /**
     * category key => the alert `type`s that belong to it. A type not listed in any category falls
     * through to the catch-all `other` tab, so nothing is ever hidden from the user.
     */
    public const MAP = [
        // Routine / preventive — the scheduled work that lands in the Inspector's (Abu Maroof) queue:
        // oil changes, battery & service reminders, and inspection schedules coming due.
        'routine' => [
            'service_inspection',       // Service & Inspection task (oil) → the Inspector
            'service_reminder_due',     // Reminders section: oil / battery / tyre service reminders
            'contact_reminder_due',     // Reminders section: call-vendor / follow-up reminders
            'inspection_due',           // Inspection schedule overdue / due soon
            'battery_overdue',          // battery service overdue
            'service_due',              // legacy oil-alert keys, kept so old rows still bucket
            'service_due_soon',
            'service_overdue',
        ],

        // Customer complaints — the Complaint Intake + Triage path (Ops log a complaint → Abu Maroof
        // triages → resolve on-site / route to garage or diagnostic → Inspector). Every complaint
        // alert type the workflow emits must be listed here, or NotificationController stamps its
        // group as the catch-all `other` and it never reaches the Complaints tab.
        'complaints' => [
            'maint_complaint_new',             // first-class Complaint entity: logged → Inspector triages
            'maint_complaint_intake',          // (legacy) a complaint was logged (to Supervisors)
            'maint_complaint_headsup',         // (legacy) heads-up to the Inspector: this car needs a test drive
            'maint_complaint_triage',          // (legacy) landed in Abu Maroof's triage lane
            'maint_complaint_call',            // (legacy) triage: called the customer
            'maint_complaint_diagnostic',      // (legacy) triage: sent the car in for diagnosis
            'maint_complaint_onsite_resolved', // (legacy) triage: resolved on-site
            'maint_complaint_resolved',        // the complaint was closed / resolved
        ],

        // Maintenance Progress — the Checkpoint tracking system's reminders to the responsible follow-up
        // owners (Waleed/Abdullah): a car in the workshop needs a progress update before it goes overdue.
        'progress' => [
            'maint_checkpoint',
            'maint_invoice_missing', // the car already left the garage and the bill still hasn't landed
        ],

        // Test-drive / re-inspection events — the Inspector is asked to road-test a car.
        'test_drive' => [
            'maint_review_pending',       // Inspection Request Review Gate: awaiting Controller (Lin/Marwa) approval
            'maint_review_approved',      // review approved → sent to the Inspector
            'maint_review_rejected',      // review rejected → nothing sent
            'maint_inspection_requested', // "this car needs a test drive" (Driver request or periodic)
            'maint_ready_reinspect',      // repair finished → single-shot re-inspection (a test drive)
            'maint_reinspection_failed',  // a re-inspection failed → back for another look
        ],
    ];

    /** The catch-all bucket for any type not claimed by a category above. */
    public const OTHER = 'other';

    /** All category keys, in tab order (excludes the derived `other`). */
    public static function keys(): array
    {
        return array_keys(self::MAP);
    }

    /** The alert `type`s that belong to a category (empty array for an unknown category). */
    public static function typesFor(string $category): array
    {
        return self::MAP[$category] ?? [];
    }

    /** Every alert `type` claimed by ANY real category — used to scope the derived `other` bucket. */
    public static function allTypes(): array
    {
        return array_merge(...array_values(self::MAP));
    }

    /** Which category does an alert `type` live in? Falls back to `other` so nothing is dropped. */
    public static function categoryOf(?string $type): string
    {
        if ($type) {
            foreach (self::MAP as $category => $types) {
                if (in_array($type, $types, true)) {
                    return $category;
                }
            }
        }

        return self::OTHER;
    }
}
