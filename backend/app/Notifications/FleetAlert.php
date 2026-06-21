<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * One operational alert (overdue rental, service due, expired document, …) delivered to a
 * single user. Carries a fully self-describing payload so the frontend needs no extra
 * lookups to render a rich card: title, body, severity, category, a deep-link URL and a
 * stable `key` the scanner uses to avoid raising the same live condition twice.
 *
 * Delivered on two channels:
 *   - `database`  — the durable store powering the bell, badge count and history page.
 *   - `broadcast` — a realtime push on the user's private channel. Harmless on the current
 *                   `log` driver; the moment BROADCAST_CONNECTION points at Reverb/Pusher the
 *                   same payload is pushed live with zero code changes.
 *
 * @phpstan-type AlertPayload array{type:string,category:string,severity:string,title:string,body:string,url:?string,key:string,icon:string,meta:array}
 */
class FleetAlert extends Notification
{
    use Queueable;

    /** Recognised severities, most → least urgent. Mirrored in the frontend theme map. */
    public const SEVERITIES = ['critical', 'warning', 'info', 'success'];

    public function __construct(public array $payload)
    {
        // Defensive defaults so a malformed payload can never break rendering.
        $this->payload += [
            'type'     => 'info',
            'category' => 'system',
            'severity' => 'info',
            'title'    => 'Notification',
            'body'     => '',
            'url'      => null,
            'key'      => 'info:' . md5(($this->payload['title'] ?? '') . ($this->payload['body'] ?? '')),
            'icon'     => 'bell',
            'meta'     => [],
        ];
    }

    /**
     * Always store durably (DB). Add the realtime `broadcast` channel only when a genuine
     * websocket driver is configured — so on the default `log`/unconfigured setup we never
     * attempt (or log-spam) a push, yet the moment BROADCAST_CONNECTION points at Reverb /
     * Pusher / Ably the very same payload starts pushing live with no further changes.
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (in_array(config('broadcasting.default'), ['reverb', 'pusher', 'ably'], true)) {
            $channels[] = 'broadcast';
        }

        return $channels;
    }

    /** Stored verbatim in `notifications.data`; the API hands it straight to the UI. */
    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }

    /** The realtime envelope — identical shape to the stored row, plus a fresh id/timestamp. */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'id'         => $this->id,
            'type'       => static::class,
            'data'       => $this->payload,
            'read_at'    => null,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    /** Frontend listens for a single, predictable event name on the private channel. */
    public function broadcastAs(): string
    {
        return 'fleet.alert';
    }
}
