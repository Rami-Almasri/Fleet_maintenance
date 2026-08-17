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
            'kind'               => $li->kind,                 // 'part' | 'labor'
            'description'        => $li->description,
            'part_number'        => $li->part_number,
            // The part's IDENTITY. The editor re-opens on this, not on `description` — a line whose
            // id is null is history typed before the picker and is shown as still needing a part.
            'component_catalog_id' => $li->component_catalog_id,
            'catalog_matched_by'   => $li->catalog_matched_by,
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
