<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ContactReminder
 *
 * Surfaces the vendor to call (name + phone) plus optional click-through targets
 * (invoice / maintenance ticket) so the UI row can jump straight to the record.
 */
class ContactReminderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'subject'        => $this->subject,
            'body'           => $this->body,
            'due_at'         => optional($this->due_at)->toIso8601String(),
            'status'         => $this->status,          // open | done | snoozed
            'is_overdue'     => $this->isOverdue(),

            // WHO to call
            'vendor_id'      => $this->vendor_id,
            'vendor'         => $this->whenLoaded('vendor', fn () => $this->vendor ? [
                'id'    => $this->vendor->id,
                'name'  => $this->vendor->name,
                'phone' => $this->vendor->phone,
                'type'  => $this->vendor->type,
            ] : null),

            // Click-through targets (nullable) — jump straight to the exact record
            'invoice_id'     => $this->invoice_id,
            'invoice'        => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id'          => $this->invoice->id,
                'invoice_no'  => $this->invoice->invoice_no,
                'contract_id' => $this->invoice->contract_id, // jump target: the contract's billing panel
            ] : null),
            'maintenance_id' => $this->maintenance_id,
            'maintenance'    => $this->whenLoaded('maintenance', fn () => $this->maintenance ? [
                'id'        => $this->maintenance->id,
                'car_label' => $this->maintenance->car_label,
                'plate'     => $this->maintenance->plate,
            ] : null),

            // Ownership
            'created_by'     => $this->created_by,
            'assigned_to'    => $this->assigned_to,
            'assignee_name'  => $this->whenLoaded('assignee', fn () => $this->assignee?->name),
            'completed_at'   => optional($this->completed_at)->toIso8601String(),
            'completed_by'   => $this->completed_by,

            'created_at'     => optional($this->created_at)->toIso8601String(),
            'updated_at'     => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
