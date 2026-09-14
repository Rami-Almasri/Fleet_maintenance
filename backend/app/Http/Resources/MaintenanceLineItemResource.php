<?php

namespace App\Http\Resources;

use App\Models\MaintenanceLineItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One structured Parts/Labor line on a maintenance ticket. Carries the raw inputs (qty, unit price)
 * plus the derived `line_total`, and — for a part — the durability/warranty fields the lifespan
 * reports read. Shaped to be a clean source for the future Odoo export (kind, category, qty, price).
 */
class MaintenanceLineItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var MaintenanceLineItem $li */
        $li = $this->resource;

        return [
            'id'                 => $li->id,
            // Was this line done under warranty, and by whom? Classification only — the amounts below
            // are untouched by it. This is what the timeline renders as "Done under warranty".
            'under_warranty'     => (bool) $li->under_warranty,
            'warranty_provider'  => $li->warranty_provider,
            'kind'               => $li->kind,                 // 'part' | 'labor' | 'vat' | 'discount' | 'adjustment'
            'description'        => $li->description,
            'part_number'        => $li->part_number,
            // WHICH WORK ITEM this charge belongs to. `finding_text` is the wording the line was
            // attributed to (Diagnosis-First); this is the fault / service row on the ticket it resolved
            // to, which is what lets a bill be read back as "this service cost this much" rather than as
            // a list of prices under a heading.
            'maintenance_task_id' => $li->maintenance_task_id,
            // The catalog part's own name, when the relation was loaded — the identity, as opposed to the
            // billed wording. Null both when no part is referenced and when the caller didn't load it.
            'catalog_part_name'  => $li->relationLoaded('catalogPart') ? $li->catalogPart?->name : null,
            // The part's IDENTITY. The editor re-opens on this, not on `description` — a line whose
            // id is null is history typed before the picker and is shown as still needing a part.
            'component_catalog_id' => $li->component_catalog_id,
            'catalog_matched_by'   => $li->catalog_matched_by,
            // WHERE the part came from. The editor sends these back unchanged on an edit, so a saved
            // line keeps its origin instead of re-entering as an unbacked price.
            'part_source'        => $li->part_source,
            'part_source_id'     => $li->part_source_id,
            // Lightweight tire tracking (only populated when the part's category is 'tyres').
            'tire_brand'         => $li->tire_brand,
            'tire_dot'           => $li->tire_dot,
            'tire_tread_mm'      => $li->tire_tread_mm !== null ? (float) $li->tire_tread_mm : null,
            'finding_text'       => $li->finding_text,
            'category_key'       => $li->category_key,
            'quantity'           => (float) $li->quantity,
            'uom'                => $li->uom,
            'unit_price'         => (float) $li->unit_price,
            'line_total'         => (float) $li->line_total,
            // Durability / warranty (parts only).
            'installed_on'       => optional($li->installed_on)->toDateString(),
            'installed_odometer' => $li->installed_odometer,
            'warranty_months'    => $li->warranty_months,
            'warranty_until'     => optional($li->warranty_until)->toDateString(),
            // How the line was captured — 'manual' today; 'ocr'/'import' when those pipelines land.
            'entry_source'       => $li->entry_source,
        ];
    }
}
