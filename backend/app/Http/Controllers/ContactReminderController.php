<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Requests\StoreContactReminderRequest;
use App\Http\Requests\UpdateContactReminderRequest;
use App\Http\Resources\ContactReminderResource;
use App\Models\ContactReminder;
use Illuminate\Http\Request;

/**
 * Contact Reminders — "call {vendor} about {subject}", optionally linked to the exact
 * invoice / maintenance ticket for one-click drill-through. Moving a reminder to 'done'
 * stamps who closed it and when.
 */
class ContactReminderController extends Controller
{
    /** The relations every read hydrates (vendor to call + click-through targets). */
    private const WITH = ['vendor', 'invoice', 'maintenance', 'assignee'];

    /** List reminders, soonest-due first. Filter by ?vendor_id, ?status, ?assigned_to. */
    public function index(Request $request)
    {
        try {
            $reminders = ContactReminder::query()
                ->with(self::WITH)
                ->when($request->filled('vendor_id'), fn ($q) => $q->where('vendor_id', $request->integer('vendor_id')))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                ->when($request->filled('assigned_to'), fn ($q) => $q->where('assigned_to', $request->integer('assigned_to')))
                ->orderByRaw("FIELD(status, 'open', 'snoozed', 'done')")  // open work first
                ->orderByRaw('due_at IS NULL')
                ->orderBy('due_at')
                ->orderByDesc('id')
                ->get();

            return ResponseHelper::SuccessResponse(
                ContactReminderResource::collection($reminders),
                'Contact reminders retrieved successfully',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function store(StoreContactReminderRequest $request)
    {
        try {
            $reminder = new ContactReminder($request->validated());
            $reminder->status = $request->input('status', 'open');
            $reminder->created_by = $request->user()?->id;
            if ($reminder->status === 'done') {
                $reminder->completed_at = now();
                $reminder->completed_by = $request->user()?->id;
            }
            $reminder->save();

            return ResponseHelper::SuccessResponse(
                ContactReminderResource::make($reminder->load(self::WITH)),
                'Contact reminder created successfully',
                201
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function show(ContactReminder $contactReminder)
    {
        try {
            return ResponseHelper::SuccessResponse(
                ContactReminderResource::make($contactReminder->load(self::WITH)),
                'Contact reminder retrieved successfully',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function update(UpdateContactReminderRequest $request, ContactReminder $contactReminder)
    {
        try {
            $data = $request->validated();
            $movingToDone = array_key_exists('status', $data)
                && $data['status'] === 'done'
                && $contactReminder->status !== 'done';

            $contactReminder->fill($data);

            if ($movingToDone) {
                $contactReminder->completed_at = now();
                $contactReminder->completed_by = $request->user()?->id;
            } elseif (($data['status'] ?? null) !== null && $data['status'] !== 'done') {
                // Re-opened / snoozed → clear the completion stamp.
                $contactReminder->completed_at = null;
                $contactReminder->completed_by = null;
            }

            $contactReminder->save();

            return ResponseHelper::SuccessResponse(
                ContactReminderResource::make($contactReminder->load(self::WITH)),
                'Contact reminder updated successfully',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function destroy(ContactReminder $contactReminder)
    {
        try {
            $contactReminder->delete();

            return ResponseHelper::SuccessResponse(null, 'Contact reminder deleted successfully', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
