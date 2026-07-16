<?php

namespace App\Services;

use App\Exceptions\WorkflowTransitionException;
use App\Models\GarageInvoiceSubmission;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\VehicleLogEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Garage Invoice Portal — the lifecycle of an outside garage self-submitting an itemised invoice through
 * a tokenised public link, landing in a REVIEW QUEUE (never touching the ticket cost until the team
 * accepts it). See [[maintenance-line-items-feature]] for the shared line shape + variance gate.
 *
 *   issueLink → the team hands the garage a short-lived secure link (one live link per ticket).
 *   submit    → the garage posts parts/labor + receipt total (+ photo). Variance gate runs; a snapshot
 *               is stored as 'submitted' and the ticket reads "Awaiting Audit"; the team is notified.
 *   accept    → the team's audit pushes the snapshot through the normal line-items pipeline (which owns
 *               the cost + reconciliation flag), tagging the lines entry_source='garage'.
 *   reject    → the team declines; nothing is applied to the ticket.
 */
class GarageInvoiceService
{
    /** Controllers/managers who audit garage invoices (mirrors MaintenanceWorkflowService). */
    private const NOTIFY_CONTROLLERS = 'maintenance.manage';

    /** How long a freshly issued link stays openable. */
    private const LINK_TTL_DAYS = 7;

    /** Receipt vs itemised agree within a cent (mirror of the internal invoice form). */
    private const VARIANCE_TOLERANCE = 0.01;

    public function __construct(
        private MaintenanceWorkflowService $workflow,
        private MaintenanceInvoiceService $invoices,
        private NotificationScanner $notifier,
        private VehicleLogService $log,
    ) {}

    /**
     * Issue (or re-issue) a secure link for a ticket, optionally SCOPED TO ONE GARAGE. A car can pass
     * through several garages over a ticket's life (each fault is routed + transferred between garages —
     * see maintenance_task_assignments), and each garage should invoice only its own work. Passing
     * $vendorId scopes the link to that garage: it presents only the faults that garage now owns — resolved
     * there or currently being worked there (current_vendor_id), never a fault it handed off unresolved —
     * and can bill only those. $vendorId = null keeps the legacy whole-ticket link (a ticket that only ever sat at
     * one garage, or whose faults aren't exploded into tasks yet).
     *
     * Any still-pending link FOR THE SAME SCOPE is retired first, so one garage only ever holds one live
     * invitation — while a different garage's link stays untouched (both can be live at once).
     */
    public function issueLink(Maintenance $ticket, User $actor, ?int $vendorId = null): GarageInvoiceSubmission
    {
        if ($vendorId !== null) {
            $this->assertGarageOnTicket($ticket, $vendorId);
        }

        return DB::transaction(function () use ($ticket, $actor, $vendorId) {
            $ticket->garageInvoices()
                ->where('status', GarageInvoiceSubmission::STATUS_PENDING)
                // Retire only the same scope's pending link (null-safe: whole-ticket vs a specific garage).
                ->when($vendorId === null, fn ($q) => $q->whereNull('vendor_id'))
                ->when($vendorId !== null, fn ($q) => $q->where('vendor_id', $vendorId))
                ->update(['status' => GarageInvoiceSubmission::STATUS_CANCELLED]);

            return $ticket->garageInvoices()->create([
                'vendor_id'  => $vendorId,
                'token'      => $this->freshToken(),
                'expires_at' => Carbon::now()->addDays(self::LINK_TTL_DAYS),
                'status'     => GarageInvoiceSubmission::STATUS_PENDING,
                'created_by' => $actor->id,
            ]);
        });
    }

    /**
     * The garages that own work on this ticket, each with the faults it may invoice — the menu the team
     * picks from to hand out a per-garage link. A fault is attributed to the garage that RESOLVED it or is
     * CURRENTLY working it (maintenance_tasks.current_vendor_id), NOT to every garage its car ever passed
     * through: when the car is transferred on, an unresolved fault's ownership moves to the destination
     * (transfer() re-points current_vendor_id) while a fault already fixed keeps its resolving garage. So a
     * garage bills only what it actually did, and unresolved faults it handed off never stay attached to it.
     * Each row carries its current live link, if one is issued.
     *
     * @return array<int,array{vendor_id:int, name:string, finding_count:int, findings:array<int,string>, link:?array}>
     */
    public function garagesForTicket(Maintenance $ticket): array
    {
        $tasks = $ticket->tasks()->with('currentVendor')->get();

        // vendor_id => ['name' => …, 'findings' => [canonicalKey => originalText]]
        $byVendor = [];
        foreach ($tasks as $task) {
            $vendorId = $task->current_vendor_id;
            if (! $vendorId) {
                continue; // fault owned by no garage (unassigned / bounced at re-inspection / on-site) — nothing to bill
            }
            $symptom = trim((string) $task->symptom);
            if ($symptom === '') {
                continue;
            }
            $byVendor[$vendorId]['name'] ??= $task->currentVendor?->name ?: ('Garage #' . $vendorId);
            $byVendor[$vendorId]['findings'][mb_strtolower($symptom)] = $symptom;
        }

        // The live pending link per garage, so the team sees an already-issued link instead of re-minting one.
        $links = $ticket->garageInvoices()
            ->where('status', GarageInvoiceSubmission::STATUS_PENDING)
            ->whereNotNull('vendor_id')
            ->get()
            ->keyBy('vendor_id');

        $out = [];
        foreach ($byVendor as $vendorId => $info) {
            $link = $links->get($vendorId);
            $out[] = [
                'vendor_id'     => (int) $vendorId,
                'name'          => $info['name'],
                'finding_count' => count($info['findings']),
                'findings'      => array_values($info['findings']),
                'link'          => ($link && $link->isOpenForSubmission()) ? [
                    'path'       => '/garage-invoice/' . $link->token,
                    'expires_at' => optional($link->expires_at)->toIso8601String(),
                ] : null,
            ];
        }

        // Stable, human-friendly order (by garage name).
        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * The faults a link may bill — the whole ticket's findings for a legacy (null-vendor) link, or just the
     * scoped garage's faults for a per-garage link. Returns the ORIGINAL finding rows from the ticket JSON,
     * filtered by the garage's task symptoms (matched case-insensitively). Used by both show() and submit().
     *
     * @return array<int,array> the subset of $ticket->findings this link is allowed to touch
     */
    public function findingsForLink(GarageInvoiceSubmission $link): array
    {
        $findings = collect($link->maintenance->findings ?? [])
            ->filter(fn ($f) => trim((string) ($f['text'] ?? '')) !== '')
            ->values();

        if ($link->vendor_id === null) {
            return $findings->all();
        }

        $allowed = $this->vendorSymptomKeys($link->maintenance, (int) $link->vendor_id);

        return $findings
            ->filter(fn ($f) => $allowed->has(mb_strtolower(trim((string) $f['text']))))
            ->values()
            ->all();
    }

    /** The lowercased fault symptoms this garage OWNS on the ticket (resolved or currently working) — its billable finding set. */
    private function vendorSymptomKeys(Maintenance $ticket, int $vendorId): \Illuminate\Support\Collection
    {
        return $ticket->tasks()
            ->where('current_vendor_id', $vendorId)
            ->pluck('symptom')
            ->map(fn ($s) => mb_strtolower(trim((string) $s)))
            ->filter()
            ->flip();
    }

    /** Guard: a link can only be scoped to a garage that currently owns (or resolved) at least one fault on the ticket. */
    private function assertGarageOnTicket(Maintenance $ticket, int $vendorId): void
    {
        $onTicket = $ticket->tasks()
            ->where('current_vendor_id', $vendorId)
            ->exists();

        if (! $onTicket) {
            throw new WorkflowTransitionException(
                'That garage has not worked on this ticket, so it cannot be issued an invoice link.',
                ['field' => 'vendor_id'],
            );
        }
    }

    private function freshToken(): string
    {
        do {
            $token = Str::random(48);
        } while (GarageInvoiceSubmission::where('token', $token)->exists());

        return $token;
    }

    /**
     * Record a garage's submission against an open link. Runs the same variance gate as the internal
     * form (a receipt/itemised mismatch needs an explanation), stores a self-contained snapshot as
     * 'submitted', flips the ticket to "Awaiting Audit", logs it, and alerts the team.
     *
     * @param array<int,array> $lines   normalised line rows (kind/description/finding_text/qty/price/…)
     * @param array{receipt_total:?float, variance_explanation:?string, garage_note:?string} $meta
     */
    public function submit(
        GarageInvoiceSubmission $link,
        array $lines,
        array $meta,
        ?UploadedFile $photo,
        ?string $ip,
    ): GarageInvoiceSubmission {
        [$parts, $labor] = $this->totals($lines);
        $itemized = round($parts + $labor, 2);

        $receiptTotal = $meta['receipt_total'] ?? null;
        $explanation  = $this->cleanText($meta['variance_explanation'] ?? null);
        $variance     = $receiptTotal !== null ? round($itemized - (float) $receiptTotal, 2) : 0.0;

        if ($receiptTotal !== null && abs($variance) > self::VARIANCE_TOLERANCE && $explanation === null) {
            throw new WorkflowTransitionException(
                'The itemised total (AED ' . number_format($itemized, 2) . ') does not match the receipt total '
                . '(AED ' . number_format((float) $receiptTotal, 2) . '). Add a variance explanation to submit.',
                ['field' => 'variance_explanation', 'variance' => $variance],
            );
        }

        return DB::transaction(function () use ($link, $lines, $meta, $photo, $ip, $parts, $labor, $itemized, $receiptTotal, $variance, $explanation) {
            $photoStored = $photo ? $this->storePhoto($link, $photo) : [null, null];

            $link->fill([
                'status'               => GarageInvoiceSubmission::STATUS_SUBMITTED,
                // Bake provenance into every stored line so an accepted invoice is tagged garage-originated.
                'line_items'           => array_map(fn ($l) => $l + ['entry_source' => 'garage'], $lines),
                'parts_total'          => $parts,
                'labor_total'          => $labor,
                'itemized_total'       => $itemized,
                'receipt_total'        => $receiptTotal,
                'variance'             => $variance,
                'variance_explanation' => (abs($variance) > self::VARIANCE_TOLERANCE) ? $explanation : null,
                'garage_note'          => $this->cleanText($meta['garage_note'] ?? null),
                'receipt_photo_disk'   => $photoStored[0],
                'receipt_photo_key'    => $photoStored[1],
                'submitted_at'         => Carbon::now(),
                'submitted_ip'         => $ip,
            ])->save();

            $ticket  = $link->maintenance()->with('vehicle')->first();
            $count   = count($lines);
            $hasVar  = abs($variance) > self::VARIANCE_TOLERANCE;

            // No authenticated actor — the garage has no login; logged as a garage-side event.
            $this->log->record($ticket, VehicleLogEvent::EVENT_GARAGE_INVOICE_SUBMITTED, null, [
                'description' => 'Garage submitted an invoice via the portal — ' . $count . ' '
                    . ($count === 1 ? 'line' : 'lines') . ' · AED ' . number_format($itemized, 2)
                    . ($receiptTotal !== null ? ' vs receipt AED ' . number_format((float) $receiptTotal, 2) : '')
                    . ' · awaiting audit',
                'meta' => [
                    'submission_id' => $link->id,
                    'itemized'      => $itemized,
                    'receipt_total' => $receiptTotal,
                    'variance'      => $variance,
                    'lines'         => $count,
                ],
            ]);

            $this->notifier->notifyByPermission(self::NOTIFY_CONTROLLERS, [
                'type'     => 'garage_invoice_submitted',
                'category' => 'maintenance',
                'severity' => $hasVar ? 'warning' : 'info',
                'title'    => 'Garage invoice to audit · ' . $this->label($ticket),
                'body'     => trim($this->label($ticket) . ' — the garage submitted ' . $count . ' '
                    . ($count === 1 ? 'line' : 'lines') . ' totalling AED ' . number_format($itemized, 2)
                    . ($hasVar ? ' (variance AED ' . number_format($variance, 2) . ')' : '')
                    . '. Review & accept.'),
                'url'  => '/maintenance-workflow/' . $ticket->id,
                'key'  => 'garage_invoice:' . $link->id . ':submitted',
                'icon' => 'invoice',
                'meta' => ['ticket_id' => $ticket->id, 'submission_id' => $link->id, 'plate' => $ticket->vehicle?->plate_no],
            ]);

            return $link->fresh();
        });
    }

    /**
     * Team audit — ACCEPT. Applies the garage's snapshot to the ticket.
     *
     *   Per-garage link (vendor scoped)  → create a SEPARATE garage bill (MaintenanceInvoice) covering only
     *       this garage's faults, with its own receipt total + reconciliation. This is what lets a car that
     *       passed through several garages carry one bill per garage — accepting garage B never touches
     *       garage A's already-accepted lines ([[ticket-many-invoices]]).
     *   Whole-ticket link (legacy null) → the original path: push the snapshot through the ticket-level
     *       line-items pipeline (wholesale replace), unchanged.
     */
    public function accept(GarageInvoiceSubmission $submission, User $actor): GarageInvoiceSubmission
    {
        if ($submission->status !== GarageInvoiceSubmission::STATUS_SUBMITTED) {
            throw new WorkflowTransitionException('This garage invoice is not awaiting audit.', ['field' => 'status']);
        }

        return DB::transaction(function () use ($submission, $actor) {
            // Concurrency guard: lock the submission row and RE-ASSERT its status inside the
            // transaction. Without this, two simultaneous "Accept" clicks both pass the check above
            // and both create a MaintenanceInvoice — double-billing the ticket. The second now waits,
            // sees 'accepted', and is rejected (no duplicate bill).
            $submission = GarageInvoiceSubmission::whereKey($submission->getKey())->lockForUpdate()->first();
            if (! $submission || $submission->status !== GarageInvoiceSubmission::STATUS_SUBMITTED) {
                throw new WorkflowTransitionException('This garage invoice is not awaiting audit.', ['field' => 'status']);
            }

            $ticket = $submission->maintenance;

            if ($submission->vendor_id !== null) {
                $invoice = $this->invoices->create($ticket, [
                    'vendor_id'            => $submission->vendor_id,
                    'task_ids'             => $this->coveredTaskIds($submission),
                    'line_items'           => $submission->line_items ?? [],
                    'receipt_total'        => $submission->receipt_total !== null ? (float) $submission->receipt_total : null,
                    'variance_explanation' => $submission->variance_explanation,
                    'notes'                => $submission->garage_note,
                ], $actor);

                // Carry the garage's receipt photo onto the bill (same disk, best-effort — no re-upload).
                if ($submission->receipt_photo_key) {
                    $invoice->forceFill([
                        'receipt_photo_disk' => $submission->receipt_photo_disk,
                        'receipt_photo_key'  => $submission->receipt_photo_key,
                    ])->saveQuietly();
                }
            } else {
                $this->workflow->syncLineItems(
                    $ticket,
                    $submission->line_items ?? [],
                    $actor,
                    $submission->receipt_total !== null ? (float) $submission->receipt_total : null,
                    $submission->variance_explanation,
                );
            }

            $submission->fill([
                'status'      => GarageInvoiceSubmission::STATUS_ACCEPTED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => Carbon::now(),
            ])->save();

            $this->log->record($ticket, VehicleLogEvent::EVENT_GARAGE_INVOICE_ACCEPTED, $actor, [
                'description' => 'Garage invoice accepted — applied to the ticket (by ' . $actor->name . ')',
                'meta'        => ['submission_id' => $submission->id, 'vendor_id' => $submission->vendor_id, 'cost' => (float) $ticket->fresh()->cost],
            ]);

            return $submission->fresh();
        });
    }

    /**
     * The ticket faults a per-garage submission bills — the scoped garage's tasks whose symptom one of the
     * submitted lines names. These become the invoice's covered faults (fault → one invoice).
     *
     * @return array<int,int>
     */
    private function coveredTaskIds(GarageInvoiceSubmission $submission): array
    {
        $findingKeys = collect($submission->line_items ?? [])
            ->pluck('finding_text')->filter()
            ->map(fn ($t) => mb_strtolower(trim((string) $t)))
            ->unique();

        return $submission->maintenance->tasks()
            ->where('current_vendor_id', $submission->vendor_id)
            ->get()
            ->filter(fn ($task) => $findingKeys->contains(mb_strtolower(trim((string) $task->symptom))))
            ->pluck('id')
            ->all();
    }

    /** Team audit — REJECT. Nothing is applied to the ticket; the reason is recorded. */
    public function reject(GarageInvoiceSubmission $submission, User $actor, ?string $note): GarageInvoiceSubmission
    {
        if ($submission->status !== GarageInvoiceSubmission::STATUS_SUBMITTED) {
            throw new WorkflowTransitionException('This garage invoice is not awaiting audit.', ['field' => 'status']);
        }

        return DB::transaction(function () use ($submission, $actor, $note) {
            $submission->fill([
                'status'      => GarageInvoiceSubmission::STATUS_REJECTED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => Carbon::now(),
                'review_note' => $this->cleanText($note),
            ])->save();

            $this->log->record($submission->maintenance, VehicleLogEvent::EVENT_GARAGE_INVOICE_REJECTED, $actor, [
                'description' => 'Garage invoice rejected' . ($note ? ' — ' . trim($note) : '') . ' (by ' . $actor->name . ')',
                'meta'        => ['submission_id' => $submission->id],
            ]);

            return $submission->fresh();
        });
    }

    /** Sum a raw line array into [parts_total, labor_total]. Mirrors the model's qty × unit_price. */
    private function totals(array $lines): array
    {
        $parts = 0.0;
        $labor = 0.0;
        foreach ($lines as $l) {
            $amt = max(0, (float) ($l['quantity'] ?? 0)) * max(0, (float) ($l['unit_price'] ?? 0));
            if (($l['kind'] ?? null) === 'labor') {
                $labor += $amt;
            } else {
                $parts += $amt;
            }
        }

        return [round($parts, 2), round($labor, 2)];
    }

    /** Store the receipt photo on the same disk the workflow uses, returning [disk, key]. */
    private function storePhoto(GarageInvoiceSubmission $link, UploadedFile $file): array
    {
        $disk = config('filesystems.disks.s3.bucket') ? 's3' : 'public';
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $key  = $file->storeAs("garage-invoices/ticket-{$link->maintenance_id}", (string) Str::uuid() . '.' . $ext, $disk);

        return $key ? [$disk, $key] : [null, null];
    }

    private function cleanText($value): ?string
    {
        $v = is_string($value) ? trim($value) : '';

        return $v === '' ? null : $v;
    }

    private function label(?Maintenance $ticket): string
    {
        $v = $ticket?->vehicle;

        return $v?->plate_no ?: trim(($v?->make ?? '') . ' ' . ($v?->model ?? '')) ?: ('Ticket #' . $ticket?->id);
    }
}
