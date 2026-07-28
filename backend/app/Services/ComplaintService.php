<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\ComplaintEvent;
use App\Models\Contract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Complaint Center — the READ layer over the first-class Complaint entity. A complaint is now its own row
 * (App\Models\Complaint), NOT a Maintenance ticket; this service assembles the two surfaces both read from:
 *
 *   • Complaints Center (/complaints)  — the management table: filter/search across every complaint, with
 *     its authoritative lifecycle STATUS and the KPI roll-up.
 *   • Complaint Timeline               — the per-complaint "record of truth": the chronological story from
 *     complaint_events (created → notified → contacted → decision → sent in / resolved / closed).
 *
 * The status is stamped explicitly by ComplaintWorkflowService (never derived here), so the analytics fall
 * straight out of the stored columns. See [[complaint-entity]].
 */
class ComplaintService
{
    // Human label + emoji per status — one place, so the table chip and the KPI cards never drift.
    public const STATUS_META = [
        Complaint::STATUS_NEW            => ['label' => 'New',            'emoji' => '🆕', 'tone' => 'slate'],
        Complaint::STATUS_NOTIFIED       => ['label' => 'Notified',       'emoji' => '🔔', 'tone' => 'amber'],
        Complaint::STATUS_CONTACTED      => ['label' => 'Contacted',      'emoji' => '📞', 'tone' => 'blue'],
        Complaint::STATUS_IN_MAINTENANCE => ['label' => 'In maintenance', 'emoji' => '🔧', 'tone' => 'violet'],
        Complaint::STATUS_RESOLVED       => ['label' => 'Resolved',       'emoji' => '✅', 'tone' => 'emerald'],
        Complaint::STATUS_CLOSED         => ['label' => 'Closed',         'emoji' => '🗂️', 'tone' => 'slate'],
    ];

    public const DECISION_META = [
        Complaint::DECISION_CONTINUE   => ['label' => 'Continue driving',    'emoji' => '🚗'],
        Complaint::DECISION_INSPECTION => ['label' => 'Bring for inspection','emoji' => '🔍'],
        Complaint::DECISION_REPLACE    => ['label' => 'Replace vehicle',     'emoji' => '🔀'],
        Complaint::DECISION_ROADSIDE   => ['label' => 'Roadside assistance', 'emoji' => '🛟'],
    ];

    // complaint_event type → timeline glyph + short title.
    private const EVENT_META = [
        ComplaintEvent::TYPE_CREATED              => ['emoji' => '📣', 'title' => 'Complaint logged'],
        ComplaintEvent::TYPE_NOTIFIED             => ['emoji' => '🔔', 'title' => 'Inspector notified'],
        ComplaintEvent::TYPE_CONTACTED            => ['emoji' => '📞', 'title' => 'Customer contacted'],
        ComplaintEvent::TYPE_DECISION             => ['emoji' => '🧭', 'title' => 'Decision made'],
        ComplaintEvent::TYPE_INSPECTION_REQUESTED => ['emoji' => '🚩', 'title' => 'Sent in for inspection'],
        ComplaintEvent::TYPE_MAINTENANCE_OPENED   => ['emoji' => '🔧', 'title' => 'Maintenance opened'],
        ComplaintEvent::TYPE_RESOLVED             => ['emoji' => '✅', 'title' => 'Resolved'],
        ComplaintEvent::TYPE_CLOSED               => ['emoji' => '🗂️', 'title' => 'Closed'],
        ComplaintEvent::TYPE_NOTE                 => ['emoji' => '•',  'title' => 'Note'],
    ];

    /**
     * The Complaints Center list. Filters (all optional): status, assignee, vehicle_id, q, from, to.
     * Returns ['rows' => [...], 'kpis' => [...]] — KPIs computed over the SAME filtered set.
     */
    public function list(array $filters = []): array
    {
        $query = Complaint::query()
            ->with(['vehicle:id,plate_no,make,model,year', 'assignee:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! empty($filters['vehicle_id'])) {
            $query->where('vehicle_id', (int) $filters['vehicle_id']);
        }
        if (! empty($filters['status']) && in_array($filters['status'], Complaint::STATUSES, true)) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['assignee'])) {
            $query->where('assigned_to', (int) $filters['assignee']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        $rows = $query->get()->map(fn (Complaint $c) => $this->rowFor($c));

        if (! empty($filters['q'])) {
            $needle = mb_strtolower(trim($filters['q']));
            $rows = $rows->filter(function ($r) use ($needle) {
                $hay = mb_strtolower(implode(' ', array_filter([
                    $r['plate'], $r['car'], $r['customer'], $r['complaint'],
                ])));
                return str_contains($hay, $needle);
            });
        }

        $rows = $rows->values();

        return ['rows' => $rows->all(), 'kpis' => $this->kpis($rows)];
    }

    /** The flat list row (+ the fields the detail view also needs) for one complaint. */
    private function rowFor(Complaint $c): array
    {
        return [
            'id'              => $c->id,
            'vehicle_id'      => $c->vehicle_id,
            'plate'           => $c->vehicle?->plate_no,
            'car'             => trim(($c->vehicle?->make ?? '') . ' ' . ($c->vehicle?->model ?? '')) ?: null,
            'year'            => $c->vehicle?->year,
            'customer'        => $c->customer_name,
            'customer_phone'  => $c->customer_phone,
            'contract_no'     => $c->contract_no,
            'contract_id'     => $c->contract_id,
            'complaint'       => $c->description,
            'severity'        => $c->severity,
            'source'          => $c->source,
            'status'          => $c->status,
            'decision'        => $c->decision,
            'maintenance_id'  => $c->maintenance_id,
            'assigned_id'     => $c->assigned_to,
            'assigned'        => $c->assignee?->name,
            'contacted'       => in_array($c->status, [Complaint::STATUS_CONTACTED, Complaint::STATUS_IN_MAINTENANCE, Complaint::STATUS_RESOLVED, Complaint::STATUS_CLOSED], true),
            'created_at'      => $c->created_at?->toIso8601String(),
            'resolved_at'     => $c->resolved_at?->toIso8601String(),
            'last_activity'   => ($c->updated_at ?? $c->created_at)?->toIso8601String(),
        ];
    }

    /** The per-complaint timeline — the "record of truth", oldest → newest. */
    public function timeline(Complaint $complaint): array
    {
        return $complaint->events()->with('creator:id,name')->get()->map(function (ComplaintEvent $e) {
            $meta = self::EVENT_META[$e->event_type] ?? ['emoji' => '•', 'title' => str_replace('_', ' ', ucfirst($e->event_type))];
            $title = $meta['title'];
            if ($e->event_type === ComplaintEvent::TYPE_DECISION) {
                $d = $e->meta['decision'] ?? null;
                $title = 'Decision · ' . (self::DECISION_META[$d]['label'] ?? $d);
            }
            return [
                'id'          => $e->id,
                'event_type'  => $e->event_type,
                'emoji'       => $meta['emoji'],
                'title'       => $title,
                'description' => $e->notes,
                'meta'        => $e->meta,
                'actor'       => $e->created_by_name ?: $e->creator?->name,
                'at'          => $e->created_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /** The full detail payload: row context, live customer contact, and the chronological timeline. */
    public function detail(Complaint $complaint): array
    {
        $complaint->loadMissing('vehicle:id,plate_no,make,model,year', 'assignee:id,name');
        $row = $this->rowFor($complaint);

        return array_merge($row, [
            'contact'  => $this->resolveContact($complaint),
            'timeline' => $this->timeline($complaint),
        ]);
    }

    /**
     * The customer's live contact details so the inspector can call. Prefers the denormalised snapshot
     * captured at intake, but re-resolves from the linked contract (or the car's current open rental) so
     * a corrected phone number surfaces.
     */
    public function resolveContact(Complaint $complaint): ?array
    {
        $contract = null;
        if ($complaint->contract_id) {
            $contract = Contract::with('customer')->find($complaint->contract_id);
        }
        if (! $contract && $complaint->vehicle_id) {
            $contract = Contract::with('customer')
                ->where('vehicle_id', $complaint->vehicle_id)
                ->where('contract_type', 'C')
                ->currentlyOpen()
                ->latest('id')
                ->first();
        }
        $customer = $contract?->customer;

        // Fall back to the intake snapshot when the contract is gone.
        $name   = ($customer?->name_en ?: $customer?->name_ar) ?: $complaint->customer_name;
        $mobile = ($customer?->mobile1 ?: $customer?->whatsapp) ?: $complaint->customer_phone;
        if (! $name && ! $mobile) {
            return null;
        }
        return [
            'name'        => $name ?: null,
            'mobile'      => $mobile ?: null,
            'whatsapp'    => $customer?->whatsapp ?: null,
            'contract_no' => $contract?->contract_no ?: $complaint->contract_no,
        ];
    }

    /** KPI roll-up over the (already-filtered) row set. */
    private function kpis(Collection $rows): array
    {
        $byStatus = [];
        foreach (Complaint::STATUSES as $s) {
            $byStatus[$s] = $rows->where('status', $s)->count();
        }

        $open      = $rows->whereIn('status', Complaint::OPEN_STATUSES)->count();
        // Converted to maintenance = the complaint spawned a real repair job (linked to a ticket).
        $converted = $rows->whereNotNull('maintenance_id')->count();

        // Median resolution time over finished complaints (resolved or closed).
        $durations = $rows
            ->whereIn('status', [Complaint::STATUS_RESOLVED, Complaint::STATUS_CLOSED])
            ->map(function ($r) {
                if (! $r['created_at'] || ! $r['last_activity']) {
                    return null;
                }
                return Carbon::parse($r['last_activity'])->diffInHours(Carbon::parse($r['created_at']));
            })
            ->filter(fn ($h) => $h !== null)
            ->sort()
            ->values();
        $medianHours = $durations->count() ? (int) round($durations->median()) : null;

        $topBy = function (string $key) use ($rows) {
            return $rows->filter(fn ($r) => ! empty($r[$key]))
                ->groupBy($key)
                ->map(fn ($g, $label) => ['label' => (string) $label, 'count' => $g->count()])
                ->sortByDesc('count')
                ->take(5)
                ->values()
                ->all();
        };

        return [
            'total'           => $rows->count(),
            'open'            => $open,
            'resolved'        => $byStatus[Complaint::STATUS_RESOLVED] + $byStatus[Complaint::STATUS_CLOSED],
            'converted'       => $converted,
            'conversion_rate' => $rows->count() ? round($converted / $rows->count() * 100) : 0,
            'median_hours'    => $medianHours,
            'by_status'       => $byStatus,
            'top_vehicles'    => $topBy('plate'),
            'top_customers'   => $topBy('customer'),
        ];
    }
}
