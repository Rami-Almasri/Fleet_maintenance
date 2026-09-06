<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Maintenance;
use App\Models\RequestReason;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * THE REASON LISTS, EDITABLE — "why is this car going in?" as data the office maintains itself.
 *
 * Both doors of the Send a Car In form offer a reason list. Those lists used to be PHP constants, so
 * adding "the customer is picking it up tomorrow" meant a deploy, and counting how often a reason was
 * used meant matching strings against an array. They are rows now, and this is the only way in.
 *
 * NOTHING HERE DELETES. destroy() retires: the row stays, `retired_at` is stamped, the reason vanishes
 * from the picker and every ticket ever filed under it still resolves to its words. That is the point —
 * a list you can prune without losing the history of what you pruned. restore() puts one back.
 *
 * A CODE IS FOREVER. It is derived from the label once, at creation, and never changes afterwards:
 * `request_reason_code` on live tickets points at it, and rewriting it would silently orphan them.
 * The LABEL is free to be reworded at any time — that is what update() is for.
 *
 * Gated on `maintenance.manage` throughout (the Controllers — Lin & Marwa). Reading the list is gated
 * the same way rather than on `view`: the picker's own copy rides on /request-options, so the only
 * reader here is the editing screen.
 */
class RequestReasonController extends Controller
{
    /**
     * Every reason on both doors, retired ones included and flagged as such — the editing screen needs
     * to show what has been withdrawn in order to offer putting it back.
     */
    public function index(Request $request)
    {
        return $this->run(function () use ($request) {
            $rows = RequestReason::query()->ordered()->get();

            $shape = fn (RequestReason $r) => [
                'id'         => $r->id,
                'door'       => $r->door,
                'code'       => $r->code,
                'label'      => $r->label,
                'label_ar'   => $r->label_ar,
                'sort_order' => $r->sort_order,
                'retired'    => $r->isRetired(),
                'retired_at' => $r->retired_at?->toIso8601String(),
                // A seeded reason has no author; one the office added does. Shown so "who put this on the
                // list?" is answerable without opening the audit trail.
                'created_by' => $r->created_by,
            ];

            return ResponseHelper::SuccessResponse([
                'inspection' => $rows->where('door', RequestReason::DOOR_INSPECTION)->map($shape)->values(),
                'dispatch'   => $rows->where('door', RequestReason::DOOR_DISPATCH)->map($shape)->values(),
            ], 'Request reasons retrieved', 200);
        });
    }

    /**
     * Add a reason to one door's list.
     *
     * The code is derived from the label and is never asked for: a person adding "the garage called" is
     * naming a reason, not minting a machine key, and letting them type one is how you end up with two
     * codes meaning the same thing. Re-adding a label that was retired REVIVES the original row rather
     * than creating a second one carrying the same code — that keeps the old tickets and the new ones
     * counting as the same reason, which is the whole reason retiring beats deleting.
     */
    public function store(Request $request)
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'door'     => ['required', 'string', Rule::in(RequestReason::DOORS)],
                'label'    => ['required', 'string', 'max:191'],
                'label_ar' => ['nullable', 'string', 'max:191'],
            ]);

            $code = $this->codeFor($data['label']);

            // A label that folds to nothing usable (all punctuation, or Arabic only) still needs a stable
            // key, and an invented one beats a blank.
            if ($code === '') {
                $code = 'reason_' . (RequestReason::query()->forDoor($data['door'])->count() + 1);
            }

            $existing = RequestReason::query()
                ->forDoor($data['door'])->where('code', $code)->first();

            if ($existing && ! $existing->isRetired()) {
                return ResponseHelper::FailureResponse(null, 'That reason is already on this list.', 422);
            }

            $reason = $existing ?: new RequestReason(['door' => $data['door'], 'code' => $code]);

            $reason->fill([
                'label'      => $data['label'],
                'label_ar'   => $data['label_ar'] ?? null,
                'retired_at' => null,
                'retired_by' => null,
                'sort_order' => $reason->sort_order
                    ?: ((int) RequestReason::query()->forDoor($data['door'])->max('sort_order') + 10),
            ]);
            $reason->created_by ??= $request->user()?->id;
            $reason->save();

            RequestReason::flushCache();

            return ResponseHelper::SuccessResponse(
                ['id' => $reason->id, 'door' => $reason->door, 'code' => $reason->code, 'label' => $reason->label],
                $existing ? 'Reason put back on the list' : 'Reason added',
                201
            );
        });
    }

    /**
     * Reword a reason, or move it in the list. The CODE is untouchable here for the reason spelled out
     * at the top of this file, and the door is too: moving a reason between doors would retroactively
     * change which question the tickets already filed under it were answering.
     */
    public function update(Request $request, RequestReason $requestReason)
    {
        return $this->run(function () use ($request, $requestReason) {
            $data = $request->validate([
                'label'      => ['sometimes', 'required', 'string', 'max:191'],
                'label_ar'   => ['sometimes', 'nullable', 'string', 'max:191'],
                'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            ]);

            $requestReason->fill($data)->save();
            RequestReason::flushCache();

            return ResponseHelper::SuccessResponse(
                ['id' => $requestReason->id, 'code' => $requestReason->code, 'label' => $requestReason->label],
                'Reason updated',
                200
            );
        });
    }

    /**
     * RETIRE a reason — take it off the picker, keep the record.
     *
     * Deliberately not a delete. Tickets carry the code, reports group by it, and the question this
     * whole table exists to answer ("what was this car sent in for?") must keep having an answer after
     * the office decides it no longer wants to OFFER that answer. The row stays; only its availability
     * ends, from this moment forward.
     */
    public function destroy(Request $request, RequestReason $requestReason)
    {
        return $this->run(function () use ($request, $requestReason) {
            if ($requestReason->isRetired()) {
                return ResponseHelper::SuccessResponse(
                    ['id' => $requestReason->id, 'retired' => true],
                    'That reason was already off the list',
                    200
                );
            }

            $requestReason->forceFill([
                'retired_at' => now(),
                'retired_by' => $request->user()?->id,
            ])->save();

            RequestReason::flushCache();

            // How many tickets keep pointing at it — said out loud, so retiring a reason that 300 tickets
            // were filed under does not feel like deleting 300 tickets' worth of meaning.
            $used = Maintenance::query()->where('request_reason_code', $requestReason->code)->count();

            return ResponseHelper::SuccessResponse(
                ['id' => $requestReason->id, 'retired' => true, 'tickets_using_it' => $used],
                $used > 0
                    ? "Taken off the list. {$used} ticket(s) were filed under it and still read as \"{$requestReason->label}\"."
                    : 'Taken off the list.',
                200
            );
        });
    }

    /** Put a retired reason back on its door's picker. The code it always had comes back with it. */
    public function restore(Request $request, RequestReason $requestReason)
    {
        return $this->run(function () use ($requestReason) {
            $requestReason->forceFill(['retired_at' => null, 'retired_by' => null])->save();
            RequestReason::flushCache();

            return ResponseHelper::SuccessResponse(
                ['id' => $requestReason->id, 'retired' => false],
                'Reason put back on the list',
                200
            );
        });
    }

    /**
     * Label → stable machine key. Latin words become snake_case; anything else (Arabic, symbols) is
     * dropped, and the caller falls back to a positional key. Capped so the column can hold it.
     */
    private function codeFor(string $label): string
    {
        return Str::limit(Str::snake(Str::slug($label, '_')), 60, '');
    }

    private function run(callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
