<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * UX telemetry for the capture flow — opened when the form opens, closed when it resolves.
 *
 * WHY THE START MATTERS. Rows used to be written only on success, so the people who gave up left no
 * trace, and the first attempt at an abandonment rate reported 100% by counting a skipped optional
 * field as a walk-out. A friction measure that can only see the users who finished is not measuring
 * friction; it is measuring the survivors.
 *
 * With a start row, three questions become answerable honestly: how many attempts were abandoned,
 * how long the completed ones took, and — through `last_step` — where people stop.
 *
 * DELIBERATELY OUTSIDE `domain_events`. A technician taking four minutes says nothing about the car.
 * This is product analytics, and mixing it into the canonical log would put UX data in the table
 * future models treat as automotive fact. It is also mutable, which the canonical log is not, and
 * that difference alone is reason enough to keep them apart.
 *
 * READ PER STEP, NEVER PER PERSON. `user_id` is kept because a step only one person struggles with
 * is a training question rather than a design one. Anyone ranking technicians with this is using it
 * wrong — the owner's rule for the pilot is explicit: improve the product, do not punish users.
 */
class CaptureFrictionService
{
    public const STATUS_STARTED   = 'started';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ABANDONED = 'abandoned';

    /** Opens a session when the form opens. Returns the id the client echoes back on resolve. */
    public function start(string $step, ?int $taskId, ?int $maintenanceId, User $actor, int $fieldsOffered): ?string
    {
        try {
            $sessionId = (string) Str::uuid();

            DB::table('capture_friction')->insert([
                'step'                => $step,
                'session_id'          => $sessionId,
                'status'              => self::STATUS_STARTED,
                'maintenance_id'      => $maintenanceId,
                'maintenance_task_id' => $taskId,
                'user_id'             => $actor->id,
                'fields_offered'      => $fieldsOffered,
                'fields_filled'       => 0,
                'occurred_at'         => now(),
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            return $sessionId;
        } catch (Throwable $e) {
            // Telemetry must never block the work it measures. A null session id simply means this
            // attempt goes unmeasured.
            Log::warning('Capture friction: could not open session', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Close a session.
     *
     * Updates the start row rather than inserting a second one, so one attempt is one row and the
     * completed/abandoned split is a simple count instead of a join.
     *
     * @param  array<string,mixed>  $data
     */
    public function resolve(?string $sessionId, string $status, array $data = []): void
    {
        if (! $sessionId) {
            return;
        }

        try {
            DB::table('capture_friction')
                ->where('session_id', $sessionId)
                // Only a session still open may be closed — a late abandon beacon arriving after a
                // successful save must not overwrite the completion. Browsers fire those out of
                // order often enough that this is a real case, not a theoretical one.
                ->where('status', self::STATUS_STARTED)
                ->update(array_filter([
                    'status'         => $status,
                    'duration_ms'    => $data['duration_ms'] ?? null,
                    'fields_filled'  => $data['fields_filled'] ?? null,
                    'skipped_fields' => isset($data['skipped_fields']) ? json_encode($data['skipped_fields']) : null,
                    'last_step'      => $data['last_step'] ?? null,
                    'was_corrected'  => $data['was_corrected'] ?? null,
                    'updated_at'     => now(),
                ], fn ($v) => $v !== null));
        } catch (Throwable $e) {
            Log::warning('Capture friction: could not close session', ['error' => $e->getMessage()]);
        }
    }
}
