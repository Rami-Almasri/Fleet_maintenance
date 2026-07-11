<?php

namespace App\Http\Requests;

use App\Models\ContactReminder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates creation of a contact reminder ("call {vendor} about {subject}"). vendor_id
 * is required here even though it's nullable in the DB (a contact reminder needs a
 * contact). Optional invoice_id / maintenance_id give the row a click-through target.
 * Route gated by permission:reminders.manage.
 */
class StoreContactReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vendor_id'      => 'required|exists:vendors,id',
            'subject'        => 'required|string|max:160',
            'body'           => 'nullable|string|max:2000',
            'due_at'         => 'nullable|date',
            'status'         => ['nullable', Rule::in(ContactReminder::STATUSES)],
            'invoice_id'     => 'nullable|exists:invoices,id',
            'maintenance_id' => 'nullable|exists:maintenances,id',
            'assigned_to'    => 'nullable|exists:users,id',
        ];
    }
}
