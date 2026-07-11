<?php

namespace App\Http\Requests;

use App\Models\ContactReminder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates edits to a contact reminder. Status transitions (open/done/snoozed) also go
 * through here; the controller stamps completed_at/completed_by when moved to 'done'.
 * Route gated by permission:reminders.manage.
 */
class UpdateContactReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vendor_id'      => 'sometimes|required|exists:vendors,id',
            'subject'        => 'sometimes|required|string|max:160',
            'body'           => 'sometimes|nullable|string|max:2000',
            'due_at'         => 'sometimes|nullable|date',
            'status'         => ['sometimes', 'required', Rule::in(ContactReminder::STATUSES)],
            'invoice_id'     => 'sometimes|nullable|exists:invoices,id',
            'maintenance_id' => 'sometimes|nullable|exists:maintenances,id',
            'assigned_to'    => 'sometimes|nullable|exists:users,id',
        ];
    }
}
