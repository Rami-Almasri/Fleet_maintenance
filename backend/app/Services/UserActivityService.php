<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserActivityEvent;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The brain behind the Workforce Operations Center.
 *
 * The frontend emits two kinds of signal: a *navigation* (the user opened a
 * module) and a *heartbeat* (the tab is still open, fired ~every 60s). We store
 * navigations as append-only rows in `user_activity_events` and keep a cheap
 * denormalized snapshot (`users.last_seen_at`, `last_page`, …) so the account
 * list renders without scanning the log. Everything else — sessions, durations,
 * the daily timeline, the hourly heat-map, module usage — is *derived* here from
 * those raw events, so every number a manager sees is real, not fabricated.
 *
 * Nothing can be backfilled: metrics only accrue from deploy onward, and callers
 * render "—" for accounts with no recorded activity yet.
 */
class UserActivityService
{
    /** last_seen within this many seconds ⇒ the account is considered online. */
    private const ONLINE_WINDOW = 300;      // 5 min

    /** Online but no module change for this long ⇒ idle (present but not working). */
    private const IDLE_THRESHOLD = 900;     // 15 min

    /** A gap larger than this between events splits one session into two. */
    private const SESSION_GAP = 2700;       // 45 min

    /** Active-time credited for dwelling on a single page (caps "reading" time). */
    private const ACTIVE_CAP = 300;         // 5 min

    // ---------------------------------------------------------------------
    //  Ingest
    // ---------------------------------------------------------------------

    /**
     * Record one signal from the frontend. Heartbeats only refresh the snapshot;
     * genuine navigations (module changes) also append a log row.
     */
    public function record(User $user, ?string $path, ?string $page, bool $navigation, Request $request): void
    {
        $now = now();

        // Snapshot via a raw update so a 60s heartbeat never churns `updated_at`
        // (which would make every account look freshly "modified").
        $snapshot = ['last_seen_at' => $now];
        if ($ip = $request->ip()) { $snapshot['last_ip'] = $ip; }
        if ($ua = $request->userAgent()) { $snapshot['last_user_agent'] = $ua; }
        if ($path) { $snapshot['last_path'] = $path; }
        if ($page) { $snapshot['last_page'] = $page; }
        DB::table('users')->where('id', $user->id)->update($snapshot);

        if (! $navigation || ! $path) {
            return;
        }

        // De-dupe: consecutive hits on the same module are one visit, not many.
        $last = $user->activityEvents()->where('type', 'page')->latest('created_at')->first();
        if ($last && $last->path === $path) {
            return;
        }

        $user->activityEvents()->create([
            'type' => 'page',
            'page' => $page,
            'path' => $path,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => $now,
        ]);
    }

    public function stampLogin(User $user, Request $request): void
    {
        $now = now();
        DB::table('users')->where('id', $user->id)->update([
            'last_login_at' => $now,
            'last_seen_at' => $now,
            'last_ip' => $request->ip(),
            'last_user_agent' => $request->userAgent(),
            'failed_login_count' => 0,
        ]);
        $user->activityEvents()->create([
            'type' => 'login',
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => $now,
        ]);
    }

    public function stampLogout(?User $user, Request $request): void
    {
        if (! $user) {
            return;
        }
        $now = now();
        DB::table('users')->where('id', $user->id)->update(['last_logout_at' => $now]);
        $user->activityEvents()->create([
            'type' => 'logout',
            'ip' => $request->ip(),
            'created_at' => $now,
        ]);
    }

    public function stampFailedLogin(User $user): void
    {
        DB::table('users')->where('id', $user->id)->update([
            'failed_login_count' => (int) ($user->failed_login_count ?? 0) + 1,
            'last_failed_login_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------------
    //  Sessionization (pure, timestamp-based to avoid Carbon sign quirks)
    // ---------------------------------------------------------------------

    /**
     * Fold a time-ordered event collection into sessions. A new session starts at
     * the first event, at any login, or after a > SESSION_GAP quiet period; a
     * logout closes one. $closeAt (a still-online user's last_seen) extends the
     * final open session so "current session" reflects real wall-clock presence.
     *
     * @return array<int,array{start:Carbon,end:Carbon,open:bool,events:Collection}>
     */
    private function sessionize(Collection $events, ?Carbon $closeAt): array
    {
        $sessions = [];
        $cur = null;
        $prevTs = null;

        foreach ($events as $e) {
            $ts = $e->created_at->getTimestamp();
            $startNew = $cur === null
                || $e->type === 'login'
                || ($prevTs !== null && ($ts - $prevTs) > self::SESSION_GAP);

            if ($startNew) {
                if ($cur) { $sessions[] = $cur; }
                $cur = ['start' => $e->created_at, 'end' => $e->created_at, 'open' => true, 'events' => collect()];
            }

            $cur['events']->push($e);
            $cur['end'] = $e->created_at;

            if ($e->type === 'logout') {
                $cur['open'] = false;
                $sessions[] = $cur;
                $cur = null;
                $prevTs = null;
                continue;
            }
            $prevTs = $ts;
        }

        if ($cur) {
            if ($closeAt && $closeAt->getTimestamp() > $cur['end']->getTimestamp()
                && ($closeAt->getTimestamp() - $cur['end']->getTimestamp()) <= self::SESSION_GAP) {
                $cur['end'] = $closeAt;
            }
            $sessions[] = $cur;
        }

        return $sessions;
    }

    /** Working / active / idle seconds for one session (active-time is an estimate). */
    private function sessionTiming(array $session): array
    {
        $start = $session['start']->getTimestamp();
        $end = $session['end']->getTimestamp();
        $duration = max(0, $end - $start);

        $active = 0;
        $prev = $start;
        foreach ($session['events'] as $e) {
            $active += min(max(0, $e->created_at->getTimestamp() - $prev), self::ACTIVE_CAP);
            $prev = $e->created_at->getTimestamp();
        }
        $active += min(max(0, $end - $prev), self::ACTIVE_CAP);
        $active = min($active, $duration);

        return ['duration' => $duration, 'active' => $active, 'idle' => max(0, $duration - $active)];
    }

    private function isOnline(?Carbon $lastSeen): bool
    {
        return $lastSeen !== null && $lastSeen->getTimestamp() >= now()->getTimestamp() - self::ONLINE_WINDOW;
    }

    // ---------------------------------------------------------------------
    //  Account list with live activity
    // ---------------------------------------------------------------------

    /**
     * The enriched account directory: every user plus a real activity snapshot
     * (online state, current session, today/week time, idle, pending tasks).
     * One events query for the whole team, sessionized per-user in memory.
     */
    public function directory(): array
    {
        $users = User::with('roles', 'creator')->orderBy('name')->get();
        $ids = $users->pluck('id');

        $weekStart = now()->startOfWeek();
        $dayStart = now()->startOfDay();

        $eventsByUser = UserActivityEvent::whereIn('user_id', $ids)
            ->where('created_at', '>=', $weekStart)
            ->orderBy('created_at')
            ->get()
            ->groupBy('user_id');

        $pending = $this->pendingTaskCounts($ids);

        $rows = [];
        $todaySessionDurations = [];   // for fleet avg-session KPI

        foreach ($users as $u) {
            $events = $eventsByUser->get($u->id, collect());
            $online = $this->isOnline($u->last_seen_at);
            $closeAt = $online ? $u->last_seen_at : null;

            $todayEvents = $events->filter(fn ($e) => $e->created_at->getTimestamp() >= $dayStart->getTimestamp())->values();
            $todaySessions = $this->sessionize($todayEvents, $closeAt);
            $weekSessions = $this->sessionize($events, $closeAt);

            $todaySeconds = 0;
            foreach ($todaySessions as $s) {
                $d = $this->sessionTiming($s)['duration'];
                $todaySeconds += $d;
                $todaySessionDurations[] = $d;
            }
            $weekSeconds = array_sum(array_map(fn ($s) => $this->sessionTiming($s)['duration'], $weekSessions));

            // Current session + idle: measured from the live open session.
            $currentSession = 0;
            $idleSeconds = null;
            if ($online) {
                $openSession = end($todaySessions) ?: (end($weekSessions) ?: null);
                if ($openSession) {
                    $currentSession = max(0, now()->getTimestamp() - $openSession['start']->getTimestamp());
                }
                $lastNav = $events->last(fn ($e) => in_array($e->type, ['page', 'login'], true));
                $idleSeconds = $lastNav ? max(0, now()->getTimestamp() - $lastNav->created_at->getTimestamp()) : null;
            }

            $status = ! $online ? 'offline'
                : (($idleSeconds !== null && $idleSeconds > self::IDLE_THRESHOLD) ? 'idle' : 'online');

            $rows[] = [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'account_status' => $u->status,
                'roles' => $u->getRoleNames(),
                'department' => $this->departmentOf($u->getRoleNames()->first()),
                'status' => $status,
                'online' => $online,
                'last_seen_at' => optional($u->last_seen_at)->toIso8601String(),
                'last_login_at' => optional($u->last_login_at)->toIso8601String(),
                'last_logout_at' => optional($u->last_logout_at)->toIso8601String(),
                'last_page' => $u->last_page,
                'current_session_seconds' => $currentSession,
                'today_seconds' => $todaySeconds,
                'week_seconds' => $weekSeconds,
                'idle_seconds' => $idleSeconds,
                'pending_tasks' => (int) ($pending[$u->id] ?? 0),
                'never_logged_in' => $u->last_login_at === null,
                'created_at' => optional($u->created_at)->toIso8601String(),
            ];
        }

        return ['rows' => $rows, 'today_session_durations' => $todaySessionDurations];
    }

    /** KPI headline row + charts for the top of the dashboard, for a given date. */
    public function overview(?string $date = null): array
    {
        $directory = $this->directory();
        $rows = $directory['rows'];
        $date = $date ?: now()->toDateString();

        $online = array_filter($rows, fn ($r) => $r['online']);
        $activeToday = array_filter($rows, fn ($r) => $r['today_seconds'] > 0);
        $loggedInToday = array_filter($rows, fn ($r) => $r['last_login_at'] && Carbon::parse($r['last_login_at'])->isToday());

        $durations = $directory['today_session_durations'];
        $avgSession = count($durations) ? (int) round(array_sum($durations) / count($durations)) : 0;

        $mostActive = collect($rows)->sortByDesc('today_seconds')->first();
        $mostActive = ($mostActive && $mostActive['today_seconds'] > 0)
            ? ['name' => $mostActive['name'], 'seconds' => $mostActive['today_seconds']]
            : null;

        return [
            'kpis' => [
                'total_users' => count($rows),
                'active_today' => count($activeToday),
                'online_now' => count($online),
                'logged_in_today' => count($loggedInToday),
                'avg_session_seconds' => $avgSession,
                'most_active_user' => $mostActive,
                'pending_tasks' => array_sum(array_column($rows, 'pending_tasks')),
                'disabled' => count(array_filter($rows, fn ($r) => $r['account_status'] !== 'active')),
                'never_logged_in' => count(array_filter($rows, fn ($r) => $r['never_logged_in'])),
                'idle_now' => count(array_filter($rows, fn ($r) => $r['status'] === 'idle')),
            ],
            'users' => $rows,
            'charts' => $this->charts($date),
        ];
    }

    /** Fleet-wide operational charts for the selected day. */
    private function charts(string $date): array
    {
        $dayStart = Carbon::parse($date)->startOfDay();
        $dayEnd = Carbon::parse($date)->endOfDay();

        $dayEvents = UserActivityEvent::whereBetween('created_at', [$dayStart, $dayEnd])->get();

        // Activity by hour + online (distinct users) by hour.
        $byHour = array_fill(0, 24, 0);
        $onlineByHour = [];
        foreach ($dayEvents as $e) {
            $h = (int) $e->created_at->format('G');
            $byHour[$h]++;
            $onlineByHour[$h][$e->user_id] = true;
        }
        $activityByHour = [];
        $onlineThroughDay = [];
        for ($h = 0; $h < 24; $h++) {
            $label = str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00';
            $activityByHour[] = ['hour' => $label, 'count' => $byHour[$h]];
            $onlineThroughDay[] = ['hour' => $label, 'count' => count($onlineByHour[$h] ?? [])];
        }

        // Login trend — last 7 days ending on the selected date.
        $trendStart = Carbon::parse($date)->subDays(6)->startOfDay();
        $logins = UserActivityEvent::where('type', 'login')
            ->whereBetween('created_at', [$trendStart, $dayEnd])
            ->get()
            ->groupBy(fn ($e) => $e->created_at->toDateString());
        $loginTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = Carbon::parse($date)->subDays($i);
            $loginTrend[] = [
                'date' => $d->toDateString(),
                'label' => $d->format('D'),
                'count' => count($logins->get($d->toDateString(), [])),
            ];
        }

        // Module usage across the last 7 days (fleet-wide).
        $moduleWindow = UserActivityEvent::where('type', 'page')
            ->where('created_at', '>=', $trendStart)
            ->get();
        $moduleUsage = $this->summarizeModules($moduleWindow);

        // Department activity for the selected day (events per department/role).
        $userRole = User::with('roles')->get()->mapWithKeys(fn ($u) => [$u->id => $u->getRoleNames()->first()]);
        $deptCounts = [];
        foreach ($dayEvents as $e) {
            $dept = $this->departmentOf($userRole->get($e->user_id));
            $deptCounts[$dept] = ($deptCounts[$dept] ?? 0) + 1;
        }
        arsort($deptCounts);
        $departmentActivity = collect($deptCounts)->map(fn ($c, $d) => ['department' => $d, 'count' => $c])->values()->all();

        return [
            'activity_by_hour' => $activityByHour,
            'online_through_day' => $onlineThroughDay,
            'login_trend' => $loginTrend,
            'module_usage' => $moduleUsage,
            'department_activity' => $departmentActivity,
        ];
    }

    // ---------------------------------------------------------------------
    //  Per-user detail (drawer)
    // ---------------------------------------------------------------------

    public function userDetail(User $user, ?string $date = null): array
    {
        $date = $date ?: now()->toDateString();
        $dayStart = Carbon::parse($date)->startOfDay();
        $dayEnd = Carbon::parse($date)->endOfDay();

        $events = $user->activityEvents()
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->orderBy('created_at')
            ->get();

        $online = $this->isOnline($user->last_seen_at);
        $isToday = Carbon::parse($date)->isToday();
        $closeAt = ($isToday && $online) ? $user->last_seen_at : null;

        $sessions = $this->sessionize($events, $closeAt);

        $totalWorking = 0; $totalActive = 0; $totalIdle = 0;
        foreach ($sessions as $s) {
            $t = $this->sessionTiming($s);
            $totalWorking += $t['duration'];
            $totalActive += $t['active'];
            $totalIdle += $t['idle'];
        }

        $firstStart = $sessions ? $sessions[0]['start'] : null;
        $lastSession = $sessions ? end($sessions) : null;
        $logoutTime = ($lastSession && ! $lastSession['open']) ? $lastSession['end'] : null;

        // Flat timeline for the drawer (login → pages → logout).
        $timeline = $events->map(fn ($e) => [
            'time' => $e->created_at->format('H:i'),
            'iso' => $e->created_at->toIso8601String(),
            'type' => $e->type,
            'label' => $e->type === 'login' ? 'Login'
                : ($e->type === 'logout' ? 'Logout' : ($e->page ?: $this->labelFromPath($e->path))),
        ])->values()->all();

        return [
            'date' => $date,
            'daily' => [
                'login_time' => optional($firstStart)->format('H:i'),
                'logout_time' => optional($logoutTime)->format('H:i'),
                'total_working_seconds' => $totalWorking,
                'active_seconds' => $totalActive,
                'idle_seconds' => $totalIdle,
                'session_count' => count($sessions),
            ],
            'timeline' => $timeline,
            'module_usage' => $this->summarizeModules($events->where('type', 'page')),
            'productivity' => $this->productivity($user),
            'comparison' => $this->comparison($user),
            'audit' => $this->audit($user),
        ];
    }

    /**
     * Genuine operational productivity — real output attributed to this account,
     * pulled straight from the workflow tables (no fabricated numbers). Each metric
     * carries a `unit` (count | duration | percent) so the UI formats it correctly,
     * and a group so the drawer can lay them out. Averages/percentages return null
     * (rendered "—") when there is no basis to compute them yet.
     */
    private function productivity(User $user): array
    {
        $uid = $user->id;

        // ── Throughput (counts of completed work) ───────────────────────────
        $ticketsOpened = DB::table('maintenances')->where('inspected_by', $uid)->count();

        $approvals = DB::table('maintenances')->where('reviewed_by', $uid)->count()
            + DB::table('maintenances')->where('recommendation_reviewed_by', $uid)->count()
            + DB::table('part_requests')->where('approved_by', $uid)->count();

        $carsInspected = DB::table('maintenances')->where('inspected_by', $uid)
            ->whereNotNull('vehicle_id')->distinct()->count('vehicle_id');

        $qcReinspections = DB::table('repair_inspections')->where('inspector_id', $uid)->count();

        $faultsClosed = DB::table('maintenance_tasks')->whereNotNull('resolved_by')
            ->where('resolved_by', $uid)->count();

        $carsDelivered = DB::table('logistics_tasks')->where('assigned_to_id', $uid)
            ->whereIn('status', ['delivered', 'returned'])->count();

        $partsPurchased = DB::table('part_purchases')->where('purchased_by', $uid)->count();

        // ── Quality / speed (derived, cross-DB safe — computed in PHP) ───────
        [$avgTaskSeconds, $latePct, $lateHint] = $this->taskTimings($uid);

        // Rework: faults this user fixed that later failed re-inspection (came back).
        $reworkReturned = (int) DB::table('maintenance_tasks')
            ->where('resolved_by', $uid)->sum('reinspection_failures');

        return [
            ['label' => 'Tickets opened', 'value' => $ticketsOpened, 'unit' => 'count', 'group' => 'throughput'],
            ['label' => 'Approvals', 'value' => $approvals, 'unit' => 'count', 'group' => 'throughput'],
            ['label' => 'Cars inspected', 'value' => $carsInspected, 'unit' => 'count', 'group' => 'throughput'],
            ['label' => 'Faults closed', 'value' => $faultsClosed, 'unit' => 'count', 'group' => 'throughput'],
            ['label' => 'QC re-inspections', 'value' => $qcReinspections, 'unit' => 'count', 'group' => 'throughput'],
            ['label' => 'Cars delivered', 'value' => $carsDelivered, 'unit' => 'count', 'group' => 'throughput'],
            ['label' => 'Parts purchased', 'value' => $partsPurchased, 'unit' => 'count', 'group' => 'throughput'],
            ['label' => 'Avg task time', 'value' => $avgTaskSeconds, 'unit' => 'duration', 'group' => 'quality'],
            ['label' => 'Late tasks', 'value' => $latePct, 'unit' => 'percent', 'group' => 'quality', 'hint' => $lateHint, 'tone' => 'amber'],
            ['label' => 'Rework returned', 'value' => $reworkReturned, 'unit' => 'count', 'group' => 'quality', 'tone' => 'red'],
        ];
    }

    /**
     * The timestamped "unit of output" sources — one completed action each. Used to
     * build windowed productivity for ranking / trends / week-over-week, all from
     * real workflow timestamps. Each: table, user column, timestamp column, and an
     * optional extra constraint.
     *
     * @return array<int,array{table:string,user:string,ts:string,where:?callable}>
     */
    private function outputSources(): array
    {
        return [
            ['table' => 'maintenance_tasks', 'user' => 'resolved_by', 'ts' => 'resolved_at', 'where' => null],           // faults closed
            ['table' => 'maintenances', 'user' => 'inspected_by', 'ts' => 'inspected_at', 'where' => null],             // tickets opened
            ['table' => 'maintenances', 'user' => 'reviewed_by', 'ts' => 'reviewed_at', 'where' => null],               // inspection-review approvals
            ['table' => 'maintenances', 'user' => 'recommendation_reviewed_by', 'ts' => 'recommendation_reviewed_at', 'where' => null], // recommendation approvals
            ['table' => 'repair_inspections', 'user' => 'inspector_id', 'ts' => 'created_at', 'where' => null],         // QC re-inspections
            ['table' => 'part_purchases', 'user' => 'purchased_by', 'ts' => 'purchased_at', 'where' => null],          // parts purchased
            ['table' => 'logistics_tasks', 'user' => 'assigned_to_id', 'ts' => 'completed_at',
                'where' => fn ($q) => $q->whereIn('status', ['delivered', 'returned'])],                                // deliveries
        ];
    }

    /**
     * Total completed actions per user within a window (fleet-wide). One grouped
     * query per source, merged. @return array<int,int> user_id => action count.
     */
    private function scoreboard(Carbon $start, Carbon $end): array
    {
        $totals = [];
        foreach ($this->outputSources() as $src) {
            $q = DB::table($src['table'])
                ->whereNotNull($src['user'])
                ->whereNotNull($src['ts'])
                ->whereBetween($src['ts'], [$start, $end]);
            if ($src['where']) { $src['where']($q); }
            $rows = $q->groupBy($src['user'])->selectRaw("{$src['user']} as uid, count(*) as c")->pluck('c', 'uid');
            foreach ($rows as $uid => $c) { $totals[$uid] = ($totals[$uid] ?? 0) + (int) $c; }
        }
        return $totals;
    }

    /** One user's daily completed-action counts over the last N days (oldest→newest). */
    private function userDailyOutput(int $uid, int $days): array
    {
        $start = now()->subDays($days - 1)->startOfDay();
        $buckets = [];
        foreach ($this->outputSources() as $src) {
            $q = DB::table($src['table'])->where($src['user'], $uid)->whereNotNull($src['ts'])->where($src['ts'], '>=', $start);
            if ($src['where']) { $src['where']($q); }
            foreach ($q->pluck($src['ts']) as $ts) {
                $d = Carbon::parse($ts)->toDateString();
                $buckets[$d] = ($buckets[$d] ?? 0) + 1;
            }
        }
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now()->subDays($i);
            $out[] = ['date' => $d->toDateString(), 'label' => $d->format('j M'), 'count' => $buckets[$d->toDateString()] ?? 0];
        }
        return $out;
    }

    /**
     * Comparative performance intelligence for one user — rank among peers, team
     * average, week-over-week and today-vs-average deltas, a 30-day trend and a
     * DATA-DRIVEN rating (Excellent / Good / Needs attention). Everything is derived
     * from the real output scoreboard; nothing is hard-coded. Fields are null (UI
     * shows "—") when there is no basis yet (e.g. too few active peers to rank).
     */
    private function comparison(User $user): array
    {
        $uid = $user->id;
        $now = now();
        $board = $this->scoreboard($now->copy()->subDays(29)->startOfDay(), $now);
        $series = $this->userDailyOutput($uid, 30);

        $output = $board[$uid] ?? 0;
        $ids = User::pluck('id');
        $totalUsers = $ids->count();

        $sum = 0; $greater = 0; $below = 0; $activePeers = 0;
        foreach ($ids as $id) {
            $v = $board[$id] ?? 0;
            $sum += $v;
            if ($v > 0) { $activePeers++; }
            if ($id === $uid) { continue; }
            if ($v > $output) { $greater++; }
            if ($v < $output) { $below++; }
        }

        $teamAvg = $totalUsers ? round($sum / $totalUsers, 1) : 0;
        $percentile = $totalUsers > 1 ? round($below / ($totalUsers - 1), 2) : null;

        // Rating needs a real distribution to be meaningful.
        $rating = null;
        if ($activePeers >= 3 && $percentile !== null && !($output === 0 && $sum === 0)) {
            $rating = $percentile >= 0.75 ? 'excellent' : ($percentile >= 0.4 ? 'good' : 'needs_attention');
        }

        $counts = array_column($series, 'count');
        $thisWeek = array_sum(array_slice($counts, -7));
        $lastWeek = array_sum(array_slice($counts, -14, 7));
        $today = $counts ? $counts[count($counts) - 1] : 0;
        $dailyAvg = round($output / 30, 1);

        return [
            'output_30d' => $output,
            'rank' => $greater + 1,
            'total_users' => $totalUsers,
            'active_peers' => $activePeers,
            'team_avg_30d' => $teamAvg,
            'percentile' => $percentile,
            'rating' => $rating,
            'this_week' => $thisWeek,
            'last_week' => $lastWeek,
            'week_delta_pct' => $lastWeek > 0 ? (int) round(($thisWeek - $lastWeek) / $lastWeek * 100) : null,
            'today' => $today,
            'daily_avg' => $dailyAvg,
            'today_vs_avg_pct' => $dailyAvg > 0 ? (int) round(($today - $dailyAvg) / $dailyAvg * 100) : null,
            'trend' => $series,
        ];
    }

    /**
     * Average fault-resolution time + late-completion rate for one user, computed
     * in PHP so it works on MySQL and the sqlite test DB alike.
     *   • avg time  = mean(resolved_at − started_at|identified_at) over closed faults
     *   • late rate = share of closed faults whose fix landed after the ticket's
     *                 expected_return_date (only faults with a known due date count)
     *
     * @return array{0:?int,1:?int,2:?string}  [avgSeconds, latePercent, lateHint]
     */
    private function taskTimings(int $uid): array
    {
        $rows = DB::table('maintenance_tasks as t')
            ->leftJoin('maintenances as m', 'm.id', '=', 't.maintenance_id')
            ->where('t.resolved_by', $uid)
            ->whereNotNull('t.resolved_at')
            ->get(['t.identified_at', 't.started_at', 't.resolved_at', 'm.expected_return_date']);

        if ($rows->isEmpty()) {
            return [null, null, null];
        }

        $durations = [];
        $withDue = 0;
        $late = 0;
        foreach ($rows as $r) {
            $resolved = Carbon::parse($r->resolved_at);
            $begin = $r->started_at ?: $r->identified_at;
            if ($begin) {
                $secs = $resolved->getTimestamp() - Carbon::parse($begin)->getTimestamp();
                if ($secs >= 0) { $durations[] = $secs; }
            }
            if ($r->expected_return_date) {
                $withDue++;
                if ($resolved->gt(Carbon::parse($r->expected_return_date)->endOfDay())) {
                    $late++;
                }
            }
        }

        $avg = count($durations) ? (int) round(array_sum($durations) / count($durations)) : null;
        $latePct = $withDue > 0 ? (int) round($late / $withDue * 100) : null;
        $hint = $withDue > 0 ? "{$late} of {$withDue} with a due date" : null;

        return [$avg, $latePct, $hint];
    }

    private function audit(User $user): array
    {
        $ua = $user->last_user_agent;
        return [
            'account_created' => optional($user->created_at)->toIso8601String(),
            'created_by' => optional($user->creator)->name,
            'last_password_change' => optional($user->last_password_change_at)->toIso8601String(),
            'last_role_change' => optional($user->last_role_change_at)->toIso8601String(),
            'failed_login_count' => (int) ($user->failed_login_count ?? 0),
            'last_failed_login' => optional($user->last_failed_login_at)->toIso8601String(),
            'last_ip' => $user->last_ip,
            'device' => $this->deviceFromAgent($ua),
            'browser' => $this->browserFromAgent($ua),
        ];
    }

    // ---------------------------------------------------------------------
    //  Helpers
    // ---------------------------------------------------------------------

    /** @param \Illuminate\Support\Collection $ids */
    private function pendingTaskCounts(Collection $ids): array
    {
        // Pending work = logistics moves assigned to the user that aren't finished.
        // Clear, real semantics; no guessing at maintenance enum states.
        return DB::table('logistics_tasks')
            ->whereIn('assigned_to_id', $ids)
            ->whereNotIn('status', ['delivered', 'returned', 'cancelled'])
            ->groupBy('assigned_to_id')
            ->selectRaw('assigned_to_id, count(*) as c')
            ->pluck('c', 'assigned_to_id')
            ->all();
    }

    private function summarizeModules(Collection $pageEvents): array
    {
        $total = $pageEvents->count();
        if ($total === 0) {
            return [];
        }
        $grouped = $pageEvents
            ->groupBy(fn ($e) => $e->page ?: $this->labelFromPath($e->path))
            ->map->count()
            ->sortDesc();

        $top = $grouped->take(5);
        $out = $top->map(fn ($c, $name) => [
            'module' => $name,
            'count' => $c,
            'pct' => (int) round($c / $total * 100),
        ])->values()->all();

        $otherCount = $total - $top->sum();
        if ($otherCount > 0) {
            $out[] = ['module' => 'Other', 'count' => $otherCount, 'pct' => (int) round($otherCount / $total * 100)];
        }
        return $out;
    }

    /** Map a primary role to a coarse department label for grouping. */
    private function departmentOf(?string $role): string
    {
        return match ($role) {
            'super-admin', 'admin' => 'Administration',
            'manager' => 'Management',
            'operations' => 'Operations',
            'supervisor', 'maintenance' => 'Maintenance',
            'inspector' => 'Inspection',
            'logistics' => 'Logistics',
            'finance' => 'Finance',
            default => 'Unassigned',
        };
    }

    private function labelFromPath(?string $path): string
    {
        if (! $path || $path === '/') {
            return 'Dashboard';
        }
        $seg = trim(explode('/', ltrim($path, '/'))[0] ?? '', '/');
        return ucwords(str_replace('-', ' ', $seg)) ?: 'Dashboard';
    }

    private function deviceFromAgent(?string $ua): ?string
    {
        if (! $ua) { return null; }
        if (preg_match('/iPhone|Android.*Mobile|Windows Phone/i', $ua)) { return 'Mobile'; }
        if (preg_match('/iPad|Tablet/i', $ua)) { return 'Tablet'; }
        return 'Desktop';
    }

    private function browserFromAgent(?string $ua): ?string
    {
        if (! $ua) { return null; }
        return match (true) {
            (bool) preg_match('/Edg\//i', $ua) => 'Edge',
            (bool) preg_match('/OPR\/|Opera/i', $ua) => 'Opera',
            (bool) preg_match('/Chrome\//i', $ua) => 'Chrome',
            (bool) preg_match('/Firefox\//i', $ua) => 'Firefox',
            (bool) preg_match('/Safari\//i', $ua) => 'Safari',
            default => 'Other',
        };
    }
}
