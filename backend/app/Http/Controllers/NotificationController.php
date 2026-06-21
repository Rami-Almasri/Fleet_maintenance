<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Services\NotificationScanner;
use Illuminate\Http\Request;

/**
 * The in-app notification centre API. Every endpoint is scoped to the authenticated user's
 * own notifications via `$request->user()->notifications()` — a user can only ever see or
 * touch their own rows.
 */
class NotificationController extends Controller
{
    /** Shape one stored notification into the flat envelope the frontend renders. */
    private function present($n): array
    {
        $data = is_array($n->data) ? $n->data : (array) $n->data;

        return [
            'id'         => $n->id,
            'type'       => $data['type'] ?? 'info',
            'category'   => $data['category'] ?? 'system',
            'severity'   => $data['severity'] ?? 'info',
            'title'      => $data['title'] ?? 'Notification',
            'body'       => $data['body'] ?? '',
            'url'        => $data['url'] ?? null,
            'icon'       => $data['icon'] ?? 'bell',
            'meta'       => $data['meta'] ?? [],
            'read'       => ! is_null($n->read_at),
            'read_at'    => optional($n->read_at)->toIso8601String(),
            'created_at' => optional($n->created_at)->toIso8601String(),
        ];
    }

    /**
     * Paginated history for the full notifications page.
     * Query: ?filter=all|unread  &  ?page=N  (15 per page).
     */
    public function index(Request $request)
    {
        $user  = $request->user();
        $query = $user->notifications();

        if ($request->query('filter') === 'unread') {
            $query->whereNull('read_at');
        }

        $page = $query->paginate(15);

        return ResponseHelper::SuccessResponse([
            'items'        => collect($page->items())->map(fn ($n) => $this->present($n))->all(),
            'unread_count' => $user->unreadNotifications()->count(),
            'total'        => $page->total(),
            'page'         => $page->currentPage(),
            'last_page'    => $page->lastPage(),
        ], 'Notifications retrieved successfully');
    }

    /**
     * Lightweight realtime poll for the bell. Returns the unread badge count plus the latest
     * few notifications — small enough to call every several seconds. Pass ?after=<id> to learn
     * whether anything newer than what the client already holds has arrived (drives the toast).
     */
    public function poll(Request $request)
    {
        $user = $request->user();

        $latest = $user->notifications()->limit(8)->get()->map(fn ($n) => $this->present($n));

        $hasNew = false;
        if ($after = $request->query('after')) {
            $newest = $user->notifications()->first();
            $hasNew = $newest && $newest->id !== $after;
        }

        return ResponseHelper::SuccessResponse([
            'unread_count' => $user->unreadNotifications()->count(),
            'latest'       => $latest->all(),
            'has_new'      => $hasNew,
        ], 'OK');
    }

    /** Mark a single notification read. */
    public function markRead(Request $request, string $id)
    {
        $n = $request->user()->notifications()->where('id', $id)->firstOrFail();
        $n->markAsRead();

        return ResponseHelper::SuccessResponse(
            ['unread_count' => $request->user()->unreadNotifications()->count()],
            'Notification marked read'
        );
    }

    /** Mark every unread notification read in one shot. */
    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return ResponseHelper::SuccessResponse(['unread_count' => 0], 'All notifications marked read');
    }

    /** Dismiss (delete) a single notification. */
    public function destroy(Request $request, string $id)
    {
        $request->user()->notifications()->where('id', $id)->delete();

        return ResponseHelper::SuccessResponse(
            ['unread_count' => $request->user()->unreadNotifications()->count()],
            'Notification dismissed'
        );
    }

    /** Clear the entire history for this user. */
    public function clear(Request $request)
    {
        $request->user()->notifications()->delete();

        return ResponseHelper::SuccessResponse(['unread_count' => 0], 'Notifications cleared');
    }

    /**
     * Send the current user a sample alert — lets anyone confirm the realtime pipeline end to
     * end (raise → poll → badge → toast) without waiting for a real fleet condition.
     */
    public function demo(Request $request, NotificationScanner $scanner)
    {
        $samples = [
            ['type' => 'overdue_rental', 'category' => 'operations', 'severity' => 'critical', 'icon' => 'clock',
             'title' => 'Overdue rental · 6d late', 'body' => 'Toyota Camry · Ahmed K. — due 2026-06-12 · balance AED 1,450', 'url' => '/overdue-rentals'],
            ['type' => 'service_due', 'category' => 'maintenance', 'severity' => 'warning', 'icon' => 'oil',
             'title' => 'Service due · 1,200 km over', 'body' => '#142 Nissan Sunny (D-58213) — 92,400 km, interval 10,000 km', 'url' => '/maintenance'],
            ['type' => 'document_expiry', 'category' => 'fleet', 'severity' => 'info', 'icon' => 'shield',
             'title' => 'Insurance expiring · 9d', 'body' => 'Hyundai Accent (A-11234) — insurance due 2026-06-27', 'url' => '/registrations'],
        ];

        // Vary by minute so repeated clicks each produce a fresh, non-deduped card.
        $sample = $samples[(int) now()->format('s') % count($samples)];
        $sample['key'] = 'demo:' . now()->timestamp;
        $sample['meta'] = ['demo' => true];

        $scanner->notifyUser($request->user(), $sample);

        return ResponseHelper::SuccessResponse(
            ['unread_count' => $request->user()->unreadNotifications()->count()],
            'Demo notification sent'
        );
    }
}
