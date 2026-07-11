<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\MaintenanceLineItem;
use Illuminate\Support\Carbon;

/**
 * Manual-to-Odoo bridge (DOWNSTREAM, read-only).
 *
 * Odoo is treated as a downstream destination, NOT a live integration. Nothing here pushes or writes
 * anything: it assembles the finalised maintenance Parts + Labor data into a clean, Odoo-shaped
 * payload that our team (or a future Odoo module) can pull and push on demand — "open the contract,
 * review, push". When the Odoo module lands, it consumes exactly this shape; until then it's simply
 * the export-ready view, so the data is always sitting ready.
 *
 * Mapping intent (documented, not enforced here):
 *   - a 'part' line   → an Odoo vendor-bill line / BOM component (product, qty, unit price)
 *   - a 'labor' line  → an Odoo expense / service product (hours × rate)
 *   - category_key    → an Odoo product category / analytic tag
 *   - vehicle         → the per-asset analytic account (drives total cost of ownership)
 *   - finding/root_cause → the diagnostic justification carried alongside each line (traceability)
 *   - the odoo_* columns on each line are the sync bookkeeping the future push job fills in.
 */
class OdooExportService
{
    /** Eager set for a fully-populated export (lines + the vehicle/contract headers). */
    private const EAGER = ['lineItems', 'vehicle:id,plate_no,make,model', 'linkedContract:id,contract_no', 'vendor:id,name'];

    /**
     * Build the export payload for ONE ticket: header + every part/labor line in Odoo shape, each
     * carrying its diagnostic justification (finding + root cause) so no cost is unexplained.
     */
    public function forTicket(Maintenance $ticket): array
    {
        $ticket->loadMissing(self::EAGER);

        $lines = $ticket->lineItems->map(fn (MaintenanceLineItem $li) => $this->line($li))->values()->all();

        return [
            'source'      => 'maintenance_ticket',
            'ticket_id'   => $ticket->id,
            'vehicle_id'  => $ticket->vehicle_id,
            'plate'       => $ticket->vehicle?->plate_no ?: $ticket->plate,
            'car'         => $ticket->vehicle ? trim($ticket->vehicle->make . ' ' . $ticket->vehicle->model) : $ticket->car_label,
            // OM contract is our source of truth — carried through for the analytic reference in Odoo.
            'contract_id' => $ticket->linked_contract_id,
            'contract_no' => $ticket->linkedContract?->contract_no,
            'garage'      => $ticket->vendor?->name ?: $ticket->garage,
            'status'      => $ticket->workflow_status,
            'currency'    => 'AED',
            'totals'      => [
                'parts' => (float) ($ticket->parts_total ?? 0),
                'labor' => (float) ($ticket->labor_total ?? 0),
                'grand' => (float) ($ticket->cost ?? 0),
            ],
            'is_itemized' => (bool) $ticket->cost_is_itemized,
            'lines'       => $lines,
            // Push bookkeeping for the future module — all lines un-synced until it runs.
            'sync'        => [
                'synced'           => $lines !== [] && collect($lines)->every(fn ($l) => $l['odoo']['synced_at'] !== null),
                'unsynced_lines'   => collect($lines)->where('odoo.synced_at', null)->count(),
                'ready_to_push'    => $lines !== [],
            ],
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Build the export for a whole OM CONTRACT — the "open the contract and push" view. Aggregates the
     * finalised line items of every maintenance ticket linked to the contract, with a grand total.
     */
    public function forContract(Contract $contract): array
    {
        $tickets = Maintenance::query()
            ->where('linked_contract_id', $contract->id)
            ->whereNotNull('workflow_status')
            ->with(self::EAGER)
            ->orderBy('id')
            ->get()
            ->map(fn (Maintenance $t) => $this->forTicket($t))
            // Only tickets that actually carry cost lines are worth pushing.
            ->filter(fn (array $e) => $e['lines'] !== [])
            ->values();

        return [
            'source'      => 'contract',
            'contract_id' => $contract->id,
            'contract_no' => $contract->contract_no,
            'currency'    => 'AED',
            'totals'      => [
                'parts' => round((float) $tickets->sum(fn ($e) => $e['totals']['parts']), 2),
                'labor' => round((float) $tickets->sum(fn ($e) => $e['totals']['labor']), 2),
                'grand' => round((float) $tickets->sum(fn ($e) => $e['totals']['grand']), 2),
            ],
            'ticket_count' => $tickets->count(),
            'tickets'      => $tickets->all(),
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /** One line in Odoo shape — the raw money + the diagnostic justification + sync bookkeeping. */
    private function line(MaintenanceLineItem $li): array
    {
        return [
            'id'           => $li->id,
            'kind'         => $li->kind,                                  // part → BOM/bill line · labor → expense
            'description'  => $li->description,
            'part_number'  => $li->part_number,
            'category_key' => $li->category_key,                         // → Odoo product category / analytic tag
            // Diagnosis-First traceability — every line maps back to the fault it was spent on.
            'finding_text' => $li->finding_text,
            'task_id'      => $li->maintenance_task_id,
            'quantity'     => (float) $li->quantity,
            'uom'          => $li->uom,                                   // unit | hour | litre …
            'unit_price'   => (float) $li->unit_price,
            'line_total'   => (float) $li->line_total,
            // Durability (parts) — useful as Odoo lot/warranty metadata.
            'installed_on'    => optional($li->installed_on)->toDateString(),
            'warranty_until'  => optional($li->warranty_until)->toDateString(),
            'entry_source'    => $li->entry_source,                      // manual | ocr | import (provenance)
            'odoo'         => [
                'product_ref' => $li->odoo_product_ref,
                'external_id' => $li->odoo_external_id,
                'synced_at'   => optional($li->odoo_synced_at)->toIso8601String(),
            ],
        ];
    }
}
