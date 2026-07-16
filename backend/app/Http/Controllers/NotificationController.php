<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\User;
use App\Services\NotificationScanner;
use App\Support\NotificationCategories;
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
            // Inbox tab this alert belongs to (routine | complaints | test_drive | other),
            // derived from its type via the single-source NotificationCategories map.
            'group'      => NotificationCategories::categoryOf($data['type'] ?? null),
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
     * Hide LEGACY notifications a user is no longer allowed to see. Existing rows were written
     * before the role-based gate (NotificationScanner) existed, so we re-apply that gate here on
     * READ: any notification whose alert `type` maps to a permission the user lacks is excluded in
     * SQL — the denied rows never leave the database. Rows with no/ungated type stay visible.
     *
     * @template T of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
     * @param  T  $query
     * @return T
     */
    private function visibleTo($query, User $user)
    {
        $denied = NotificationScanner::deniedTypesFor($user);

        if (! empty($denied)) {
            $query->where(function ($q) use ($denied) {
                // Keep a row when its type is null/missing OR not in the denied set. (A plain
                // whereNotIn would also drop null-type rows, since `null NOT IN (...)` is unknown.)
                $q->whereNull('data->type')->orWhereNotIn('data->type', $denied);
            });
        }

        return $query;
    }

    /**
     * Narrow a notifications query to a single inbox category (routine | complaints | test_drive)
     * by its member alert `type`s. Runs over the user's already-tiny, index-scoped row set, so a
     * `whereIn` on the JSON `data->type` is cheap — no dedicated column needed. Unknown/empty
     * category is a no-op (returns everything).
     *
     * @template T of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
     * @param  T  $query
     * @return T
     */
    private function inCategory($query, ?string $category)
    {
        // No category → everything (used by "clear all").
        if (! $category) {
            return $query;
        }

        // The derived catch-all `other` tab = rows whose type isn't claimed by any real category.
        // It has NO member types, so it must be scoped to "uncategorized", NEVER left unfiltered —
        // otherwise clear('other') would delete the user's ENTIRE feed (routine + complaints + …).
        if ($category === NotificationCategories::OTHER) {
            $mapped = NotificationCategories::allTypes();
            return $query->where(function ($q) use ($mapped) {
                $q->whereNull('data->type')->orWhereNotIn('data->type', $mapped);
            });
        }

        $types = NotificationCategories::typesFor($category);
        if (! empty($types)) {
            return $query->whereIn('data->type', $types);
        }

        // A specified-but-unknown category matches NOTHING — a bad param must never fall through to
        // "all rows" (which for clear() is destructive).
        return $query->whereRaw('1 = 0');
    }

    /**
     * Paginated history for the full notifications page.
     * Query: ?filter=all|unread  &  ?category=routine|complaints|test_drive  &  ?page=N  (15 per page).
     */
    public function index(Request $request)
    {
        $user  = $request->user();
        $query = $this->visibleTo($user->notifications(), $user);

        if ($request->query('filter') === 'unread') {
            $query->whereNull('read_at');
        }

        $this->inCategory($query, $request->query('category'));

        $page = $query->paginate(15);

        return ResponseHelper::SuccessResponse([
            'items'        => collect($page->items())->map(fn ($n) => $this->present($n))->all(),
            'unread_count' => $this->visibleTo($user->unreadNotifications(), $user)->count(),
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

        $latest = $this->visibleTo($user->notifications(), $user)->limit(8)->get()->map(fn ($n) => $this->present($n));

        $hasNew = false;
        if ($after = $request->query('after')) {
            $newest = $this->visibleTo($user->notifications(), $user)->first();
            $hasNew = $newest && $newest->id !== $after;
        }

        return ResponseHelper::SuccessResponse([
            'unread_count' => $this->visibleTo($user->unreadNotifications(), $user)->count(),
            'latest'       => $latest->all(),
            'has_new'      => $hasNew,
        ], 'OK');
    }

    /** The unread badge as the frontend sees it — legacy denied-type rows excluded (matches index/poll). */
    private function unreadCount(User $user): int
    {
        return $this->visibleTo($user->unreadNotifications(), $user)->count();
    }

    /** Mark a single notification read. */
    public function markRead(Request $request, string $id)
    {
        $n = $request->user()->notifications()->where('id', $id)->firstOrFail();
        $n->markAsRead();

        return ResponseHelper::SuccessResponse(
            ['unread_count' => $this->unreadCount($request->user())],
            'Notification marked read'
        );
    }

    /** Mark every unread notification read in one shot. */
    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return ResponseHelper::SuccessResponse(['unread_count' => 0], 'All notifications marked read');
    }

    /** Dismiss (delete) a single notification. 404s if it isn't the caller's — never report a phantom success. */
    public function destroy(Request $request, string $id)
    {
        $n = $request->user()->notifications()->where('id', $id)->firstOrFail();
        $n->delete();

        return ResponseHelper::SuccessResponse(
            ['unread_count' => $this->unreadCount($request->user())],
            'Notification dismissed'
        );
    }

    /**
     * Clear this user's notification history. Always strictly scoped to the authenticated user —
     * never touches another staff member's feed. Pass ?category=routine|complaints|test_drive to
     * clear just one tab; omit it to clear everything.
     */
    public function clear(Request $request)
    {
        $category = $request->input('category');
        $this->inCategory($request->user()->notifications(), $category)->delete();

        return ResponseHelper::SuccessResponse(
            ['unread_count' => $this->unreadCount($request->user())],
            $category ? 'Notifications cleared for this category' : 'Notifications cleared'
        );
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
            ['unread_count' => $this->unreadCount($request->user())],
            'Demo notification sent'
        );
    }
}
