import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api/client';
import { useNotifications } from '../hooks/useNotifications';
import { usePermissions } from '../hooks/usePermissions';
import { PageHeader, EmptyState, Card } from '../components/ui/Misc';
import Button from '../components/ui/Button';
import Badge from '../components/ui/Badge';
import { useToast } from '../components/ui/Toast';
import CheckpointModal from '../components/maintenance/CheckpointModal';
import {
  visibleLanes,
  laneOf,
  severityRank,
  severityTheme,
  iconPath,
  relativeTime,
  exactTime,
  typeLabel,
  actionLabel,
  actionTarget,
  metaHighlights,
  metaEntities,
  dateBucket,
  LANE_GROUPS,
  hasAction,
  worstSeverity,
  clusterByEntity,
  clusterLabel,
  PRIORITY_ORDER,
  PRIORITY_SECTION,
} from '../lib/notifications';

// The "All" tab always leads; a per-role set of lanes follows (see visibleLanes),
// then an "Other" catch-all shown only when it actually has rows — so nothing is
// hidden yet the tab bar stays clean and tailored to the operator's job.
const ALL_TAB = { key: 'all', label: 'All', icon: 'bell', empty: 'No notifications found' };
const OTHER_TAB = { key: 'other', label: 'Other', icon: 'bell', empty: 'No other notifications' };

// Severity → Badge tone (Aurora semantic tones).
const SEVERITY_TONE = { critical: 'red', warning: 'amber', info: 'blue', success: 'green' };

// Calendar buckets, rendered in this fixed order as day-group sections.
const BUCKET_ORDER = ['Today', 'Yesterday', 'This week', 'This month', 'Earlier'];

// ─────────────────────────────────────────────────────────────────────────────
// Action Center — Aurora design system.
//
// Built on the shared ui/ primitives (PageHeader, Badge, Button,
// EmptyState). All data/theming still flows from lib/notifications.js so the
// bell dropdown and this page stay in lock-step.
// ─────────────────────────────────────────────────────────────────────────────

export default function Notifications() {
  const navigate = useNavigate();
  const { unreadCount, refresh, markAllRead, clearAll } = useNotifications();
  const { can } = usePermissions();
  const toast = useToast();

  // Maintenance Progress checkpoints (Waleed & Abdullah's lane): a `maint_checkpoint` reminder is
  // answered by FILING the update, not by reading a page — so its action opens the very same
  // CheckpointModal /maintenance-progress uses, right here on the card. The alert carries the ticket
  // in `meta.ticket_id`; without it (or without the permission to file) we fall back to the deep link.
  const [checkpointTicket, setCheckpointTicket] = useState(null);
  const canCheckpoint = can('maintenance.checkpoint.create');

  // The lanes this operator is allowed to see (permission-gated), plus a Set of
  // their keys for fast lane assignment. Recomputed only when the permission set
  // changes — `can` is derived from the (stable) logged-in user.
  const lanes = useMemo(() => visibleLanes(can), [can]);
  const laneKeys = useMemo(() => new Set(lanes.map((l) => l.key)), [lanes]);

  const [filter, setFilter] = useState('all');       // all | unread (server-side)
  const [activeTab, setActiveTab] = useState('all');  // 'all' or a specific notification type (client-side)
  const [viewMode, setViewMode] = useState('priority'); // priority | time — how the board groups
  const [focus, setFocus] = useState('all');          // all | critical | action — the quick workload filter
  const [items, setItems] = useState([]);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ last_page: 1, total: 0 });
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState('');

  // The types the ACTIVE lane is made of, sent to the server so the lane filters the whole feed rather
  // than just the page that happens to be loaded.
  //
  // This was the bug: lanes used to be a purely client-side bucketing of the 15 rows fetched so far, so
  // on a busy account (a thousand-plus unread alerts, fifteen per page) opening a lane to find one
  // specific alert showed "nothing here" — the row existed, six pages down, and the lane never saw it.
  // A quiet lane and an unfetched lane looked identical. `all` sends nothing and keeps the full feed.
  const activeLaneTypes = useMemo(() => {
    if (activeTab === 'all' || activeTab === 'other') return null;
    const lane = lanes.find((l) => l.key === activeTab);
    return lane?.types?.length ? lane.types.join(',') : null;
  }, [activeTab, lanes]);

  const fetchPage = useCallback(async (p, replace) => {
    p === 1 ? setLoading(true) : setLoadingMore(true);
    setError('');
    try {
      const params = { filter, page: p };
      if (activeLaneTypes) params.types = activeLaneTypes;
      const { data } = await api.get('/notifications', { params });
      const payload = data.data || {};
      setMeta({ last_page: payload.last_page || 1, total: payload.total || 0 });
      setItems((prev) => (replace ? payload.items : [...prev, ...(payload.items || [])]));
    } catch (err) {
      setError(err.response?.data?.message || err.message || 'Could not load notifications');
    } finally {
      setLoading(false);
      setLoadingMore(false);
    }
  }, [filter, activeLaneTypes]);

  // (Re)load from the top whenever the read filter OR the selected lane changes — switching lane is now
  // a new server query, not a re-slice of what is already in memory.
  useEffect(() => { setPage(1); fetchPage(1, true); }, [fetchPage]);

  const loadMore = () => {
    const next = page + 1;
    setPage(next);
    fetchPage(next, false);
  };

  // Action button → mark read, then navigate to the relevant page.
  const openNotification = async (n) => {
    if (!n.read) {
      setItems((list) => list.map((x) => (x.id === n.id ? { ...x, read: true } : x)));
      try { await api.post(`/notifications/${n.id}/read`); } catch (_) {}
      refresh();
    }
    // Checkpoint reminders are actioned in place — open the form instead of leaving the board.
    if (n.type === 'maint_checkpoint' && canCheckpoint && n.meta?.ticket_id) {
      setCheckpointTicket({
        ticketId: n.meta.ticket_id,
        label: n.meta.plate || `Ticket #${n.meta.ticket_id}`,
        sub: n.meta.garage || undefined,
      });
      return;
    }
    const to = actionTarget(n);
    if (to) navigate(to);
  };

  // Mark read in place — clears the unread cue without leaving the page.
  const markRead = async (n) => {
    setItems((list) => list.map((x) => (x.id === n.id ? { ...x, read: true } : x)));
    try { await api.post(`/notifications/${n.id}/read`); } catch (_) {}
    refresh();
  };

  const dismiss = async (id) => {
    setItems((list) => list.filter((n) => n.id !== id));
    setMeta((m) => ({ ...m, total: Math.max(0, m.total - 1) }));
    try { await api.delete(`/notifications/${id}`); } catch (_) {}
    refresh();
  };

  const onMarkAll = async () => {
    setItems((list) => list.map((n) => ({ ...n, read: true })));
    await markAllRead();
    if (filter === 'unread') fetchPage(1, true);
  };

  // Clear the whole feed, or just the active lane. "All" uses the backend's
  // scoped clear; a single lane (whose keys don't map to the backend category
  // taxonomy) is cleared by deleting its loaded rows directly by id.
  const onClearAll = async () => {
    if (activeTab === 'all') {
      setItems([]);
      setMeta({ last_page: 1, total: 0 });
      await clearAll();
      return;
    }
    const rows = items.filter((n) => laneOf(n, laneKeys) === activeTab);
    const ids = new Set(rows.map((n) => n.id));
    setItems((list) => list.filter((n) => !ids.has(n.id)));
    setMeta((m) => ({ ...m, total: Math.max(0, m.total - ids.size) }));
    await Promise.all(rows.map((n) => api.delete(`/notifications/${n.id}`).catch(() => {})));
    refresh();
  };

  // Bucket loaded items into the visible lanes once (memoized), each sorted by
  // urgency then recency. Switching tabs is then a constant-time lookup — no refetch.
  const byTab = useMemo(() => {
    const buckets = { all: [] };
    for (const n of items) {
      const c = laneOf(n, laneKeys);
      buckets.all.push(n);
      (buckets[c] || (buckets[c] = [])).push(n);
    }
    const sortRows = (rows) => rows.sort((a, b) =>
      severityRank(a.severity) - severityRank(b.severity) ||
      new Date(b.created_at) - new Date(a.created_at));
    Object.values(buckets).forEach(sortRows);
    return buckets;
  }, [items, laneKeys]);

  // Tab bar: All + this role's lanes, plus an "Other" catch-all only when it has rows.
  const tabs = useMemo(() => {
    const base = [ALL_TAB, ...lanes];
    return (byTab.other && byTab.other.length) ? [...base, OTHER_TAB] : base;
  }, [lanes, byTab]);

  // The picker's layout: an "overview" row (All + Other) on top, then one row per
  // stage of the job (Inspection → Workshop & Moves → Control). A group that this
  // role can't see is dropped entirely, so the picker only ever shows real work.
  const laneSections = useMemo(() => {
    const overview = tabs.filter((t) => t.key === 'all' || t.key === 'other');
    const groups = LANE_GROUPS
      .map((g) => ({ ...g, items: lanes.filter((l) => l.group === g.key) }))
      .filter((g) => g.items.length > 0);
    const ungrouped = lanes.filter((l) => !LANE_GROUPS.some((g) => g.key === l.group));
    if (ungrouped.length) groups.push({ key: 'more', label: 'More', hint: '', items: ungrouped });
    return { overview, groups };
  }, [tabs, lanes]);

  // Per-tab counts feed the badge on each pill ("Complaints · 3").
  //
  // They are counted from the LOADED feed, which is only ever the pages fetched so far — so they have
  // always been "at least this many", not a total. What changed is that a lane is now a server-side
  // filter, so while one lane is selected the feed holds only that lane's rows and every other chip
  // would count to zero. A chip reading 0 is a claim ("that lane is empty"), and it would be a false
  // one, so the badges are frozen at the last unfiltered snapshot instead of being recomputed from a
  // feed that cannot see the other lanes. Selecting `all` refreshes them.
  const [laneCounts, setLaneCounts] = useState({ counts: { all: 0 }, unread: { all: 0 } });

  const countsByTab = useMemo(() => {
    const counts = { all: 0 };
    const unread = { all: 0 };
    for (const n of items) {
      const c = laneOf(n, laneKeys);
      counts.all += 1;
      counts[c] = (counts[c] || 0) + 1;
      if (!n.read) {
        unread.all += 1;
        unread[c] = (unread[c] || 0) + 1;
      }
    }
    return { counts, unread };
  }, [items, laneKeys]);

  // Only an unfiltered feed can speak for every lane.
  useEffect(() => {
    if (!activeLaneTypes) setLaneCounts(countsByTab);
  }, [activeLaneTypes, countsByTab]);

  const chipCounts = activeLaneTypes ? laneCounts : countsByTab;

  // Fall back to "All" if the active type-tab no longer exists (e.g. all its
  // notifications were dismissed or the filter changed).
  useEffect(() => {
    if (activeTab !== 'all' && !tabs.some((t) => t.key === activeTab)) {
      setActiveTab('all');
    }
  }, [tabs, activeTab]);

  const visible = useMemo(() => byTab[activeTab] || [], [byTab, activeTab]);
  const activeTabDef = tabs.find((t) => t.key === activeTab) || tabs[0];

  // Workload stats for the ACTIVE lane — the numbers an operator reads first. Computed
  // before the focus filter so the tiles always show the true lane totals.
  const stats = useMemo(() => {
    let critical = 0; let unread = 0; let action = 0;
    for (const n of visible) {
      if (n.severity === 'critical') critical += 1;
      if (!n.read) unread += 1;
      if (!n.read && hasAction(n)) action += 1;
    }
    return { total: visible.length, critical, unread, action };
  }, [visible]);

  // Apply the quick workload focus (Critical / Action required) on top of the lane.
  const focused = useMemo(() => {
    if (focus === 'critical') return visible.filter((n) => n.severity === 'critical');
    if (focus === 'action') return visible.filter((n) => !n.read && hasAction(n));
    return visible;
  }, [visible, focus]);

  // Reset focus when it would show nothing (e.g. after clearing the last critical item).
  useEffect(() => {
    if (focus === 'critical' && stats.critical === 0) setFocus('all');
    if (focus === 'action' && stats.action === 0) setFocus('all');
  }, [focus, stats.critical, stats.action]);

  // Cluster a section's rows into groups/singles and render each.
  const renderNodes = (rows) => clusterByEntity(rows).map((node) => (
    node.type === 'group'
      ? <VehicleGroup key={node.key} node={node} onAction={openNotification} onMarkRead={markRead} onDismiss={dismiss} />
      : <NotificationRow key={node.item.id} n={node.item} onAction={openNotification} onMarkRead={markRead} onDismiss={dismiss} />
  ));

  return (
    <div className="py-8">
      <div className="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">

        {/* ── Header ─────────────────────────────────────────────────────── */}
        <PageHeader
          title="Action Center"
          subtitle="Your to-do list — the tasks that need you, sorted into lanes for your role and ready to act on."
        >
          <Button variant="secondary" size="sm" onClick={onMarkAll} disabled={!unreadCount}>
            Mark all read
          </Button>
          <Button variant="secondary" size="sm" onClick={onClearAll} disabled={visible.length === 0}>
            {activeTab === 'all' ? 'Clear all' : `Clear ${activeTabDef.label}`}
          </Button>
        </PageHeader>

        {/* ── Workload summary — the numbers an operator reads first. Critical and ──
            Action-required tiles double as one-click focus filters. */}
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <StatTile
            label="Critical"
            value={stats.critical}
            tone="red"
            icon="alert"
            active={focus === 'critical'}
            disabled={stats.critical === 0}
            onClick={() => setFocus((f) => (f === 'critical' ? 'all' : 'critical'))}
          />
          <StatTile
            label="Action required"
            value={stats.action}
            tone="indigo"
            icon="check"
            active={focus === 'action'}
            disabled={stats.action === 0}
            onClick={() => setFocus((f) => (f === 'action' ? 'all' : 'action'))}
          />
          <StatTile label="Unread" value={stats.unread} tone="blue" icon="bell" />
          <StatTile label="In this lane" value={stats.total} tone="slate" icon={activeTabDef.icon || 'bell'} />
        </div>

        {/* ── Control panel: lane picker + how the board is grouped ──────────
            One framed panel so the twelve lanes read as a labelled toolbar
            instead of a cramped strip of grey text. */}
        <div className="overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-soft">

          {/* Toolbar row: section label · group-by · unread-only */}
          <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 bg-slate-50/60 px-4 py-2.5">
            <span className="text-[11px] font-bold uppercase tracking-[0.09em] text-slate-500">
              Lanes
            </span>
            <span className="hidden text-xs text-slate-400 sm:inline">Pick the work you own</span>

            <div className="ms-auto flex flex-wrap items-center gap-2">
              {/* Group-by: priority vs time — the two ways an ops manager scans the board. */}
              <div className="inline-flex items-center gap-2">
                <span className="hidden text-[11px] font-semibold uppercase tracking-wide text-slate-400 sm:inline">Group by</span>
                <div className="inline-flex items-center rounded-xl bg-slate-200/60 p-1" role="group" aria-label="Group by">
                  {[['priority', 'Priority'], ['time', 'Time']].map(([key, lbl]) => (
                    <button
                      key={key}
                      type="button"
                      onClick={() => setViewMode(key)}
                      aria-pressed={viewMode === key}
                      className={[
                        'rounded-lg px-3 py-1.5 text-xs font-bold transition-all duration-150',
                        viewMode === key
                          ? 'bg-white text-indigo-600 shadow-sm ring-1 ring-inset ring-indigo-100'
                          : 'text-slate-600 hover:text-slate-900',
                      ].join(' ')}
                    >
                      {lbl}
                    </button>
                  ))}
                </div>
              </div>

              {/* Unread-only toggle — server-side filter. */}
              <button
                type="button"
                onClick={() => setFilter((f) => (f === 'unread' ? 'all' : 'unread'))}
                aria-pressed={filter === 'unread'}
                className={[
                  'inline-flex shrink-0 items-center gap-2 rounded-xl px-3 py-2 text-xs font-bold ring-1 ring-inset transition-all duration-150',
                  filter === 'unread'
                    ? 'bg-indigo-600 text-white ring-indigo-600 shadow-sm'
                    : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50 hover:text-slate-900 hover:ring-slate-300',
                ].join(' ')}
              >
                <span
                  aria-hidden="true"
                  className={['h-2 w-2 rounded-full transition-colors', filter === 'unread' ? 'bg-white' : 'bg-slate-300'].join(' ')}
                />
                Unread only
              </button>
            </div>
          </div>

          {/* Lane picker — overview row on top, then one labelled row per stage of the
              job. Chips wrap onto as many lines as they need; nothing hides off-screen. */}
          <nav aria-label="Notification lanes" className="divide-y divide-slate-100">
            {(() => {
              const chip = (t) => (
                <LaneChip
                  key={t.key}
                  tab={t}
                  active={activeTab === t.key}
                  count={chipCounts.counts[t.key] || 0}
                  unread={chipCounts.unread[t.key] || 0}
                  onClick={() => setActiveTab(t.key)}
                />
              );
              return (
                <>
                  <div className="flex flex-wrap gap-2 px-3 py-3">
                    {laneSections.overview.map(chip)}
                  </div>
                  {laneSections.groups.map((g) => {
                    const total = g.items.reduce((s, l) => s + (countsByTab.counts[l.key] || 0), 0);
                    const unread = g.items.reduce((s, l) => s + (countsByTab.unread[l.key] || 0), 0);
                    return (
                      <div key={g.key} className="px-3 py-3 sm:flex sm:items-start sm:gap-3">
                        <div className="mb-2 flex w-40 shrink-0 items-center gap-1.5 sm:mb-0 sm:mt-1.5">
                          <span className="text-[11px] font-bold uppercase tracking-[0.07em] text-slate-500" title={g.hint}>
                            {g.label}
                          </span>
                          {unread > 0
                            ? <span className="h-1.5 w-1.5 rounded-full bg-indigo-500" aria-hidden="true" />
                            : total === 0 && <span className="text-[11px] font-medium text-slate-300">clear</span>}
                        </div>
                        <div className="flex min-w-0 flex-1 flex-wrap gap-2">
                          {g.items.map(chip)}
                        </div>
                      </div>
                    );
                  })}
                </>
              );
            })()}
          </nav>

          {/* Active-lane context — what this lane is for, so the board always has a purpose. */}
          {activeTab !== 'all' && activeTabDef.blurb && (
            <p className="flex items-start gap-2 border-t border-slate-100 bg-slate-50/60 px-4 py-2.5 text-xs leading-relaxed text-slate-600">
              <svg className="mt-px h-4 w-4 shrink-0 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d="M12 8v4m0 4h.01M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z" /></svg>
              <span><span className="font-semibold text-slate-700">{activeTabDef.label}:</span> {activeTabDef.blurb}</span>
            </p>
          )}
        </div>

        {/* ── Error ──────────────────────────────────────────────────────── */}
        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">
            {error}
          </div>
        )}

        {/* ── List ───────────────────────────────────────────────────────── */}
        {loading ? (
          <SkeletonList />
        ) : items.length === 0 ? (
          <Card>
            <EmptyState
              title={filter === 'unread' ? 'No unread notifications' : "You're all caught up"}
              message="New fleet alerts will land here automatically as conditions change."
            />
          </Card>
        ) : visible.length === 0 ? (
          <LaneEmptyState tab={activeTabDef} isAll={activeTab === 'all'} />
        ) : focused.length === 0 ? (
          <Card>
            <EmptyState
              icon={(
                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
                </svg>
              )}
              title={focus === 'critical' ? 'No critical items' : 'Nothing needs action'}
              message={focus === 'critical'
                ? 'Nothing in this lane is critical right now — the highest-priority work is clear.'
                : 'Every item here has already been actioned or read. Nice work.'}
              action={<Button variant="secondary" size="sm" onClick={() => setFocus('all')}>Show everything</Button>}
            />
          </Card>
        ) : (
          <div className="stagger space-y-5">
            {viewMode === 'priority'
              ? PRIORITY_ORDER.map((sev) => {
                  const rows = focused.filter((n) => n.severity === sev);
                  if (rows.length === 0) return null;
                  return (
                    <PrioritySection key={sev} severity={sev} rows={rows}>
                      {renderNodes(rows)}
                    </PrioritySection>
                  );
                })
              : BUCKET_ORDER.map((bucket) => {
                  const rows = focused.filter((n) => dateBucket(n.created_at) === bucket);
                  if (rows.length === 0) return null;
                  const unread = rows.filter((r) => !r.read).length;
                  return (
                    <section key={bucket} className="space-y-3">
                      <SectionHeader accent="bg-slate-300" label={bucket} count={rows.length} unread={unread} />
                      {renderNodes(rows)}
                    </section>
                  );
                })}

            {page < meta.last_page && (
              <div className="flex justify-center pt-1">
                <Button variant="secondary" size="md" loading={loadingMore} onClick={loadMore}>
                  Load more
                </Button>
              </div>
            )}
          </div>
        )}
      </div>

      {/* Maintenance Progress — file the owed checkpoint without leaving the Action Center. Filing one
          clears the reminder chain server-side, so we reload the feed on success. */}
      {checkpointTicket && (
        <CheckpointModal
          open
          ticketId={checkpointTicket.ticketId}
          title={`Checkpoint · ${checkpointTicket.label}`}
          subtitle={checkpointTicket.sub}
          onClose={() => setCheckpointTicket(null)}
          onDone={(msg) => {
            if (msg) toast.success(msg);
            setPage(1);
            fetchPage(1, true);
            refresh();
          }}
        />
      )}
    </div>
  );
}

// Highlight-chip tone → classes. Highlights are the "why it matters" metrics
// (days late, cost, km over) so they carry weight; danger/warn read hot.
const HIGHLIGHT_TONE = {
  danger: 'bg-red-50 text-red-700 ring-red-600/20',
  warn: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  strong: 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
  neutral: 'bg-slate-100 text-slate-600 ring-slate-500/15',
};

// One entity fact (Vehicle · D-58213). Icon-led, label above the value, so the
// card reads as a structured record instead of a sentence. Actionable when `href`.
function EntityField({ label, value, icon, mono, href }) {
  const body = (
    <>
      <span className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-400 ring-1 ring-inset ring-slate-200/70 group-hover/ent:bg-indigo-50 group-hover/ent:text-indigo-500">
        <svg aria-hidden="true" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
          <path d={iconPath(icon)} />
        </svg>
      </span>
      <span className="min-w-0">
        <span className="block text-[10px] font-semibold uppercase tracking-wide text-slate-400">{label}</span>
        <span className={`block truncate text-[13px] font-semibold text-slate-700 ${mono ? 'font-mono tabular-nums' : ''} ${href ? 'text-indigo-600 group-hover/ent:underline' : ''}`}>
          {value}
        </span>
      </span>
    </>
  );

  const cls = 'group/ent flex items-start gap-2 rounded-xl bg-slate-50/70 px-2.5 py-2 ring-1 ring-inset ring-slate-200/50 transition-colors';
  return href
    ? <a href={href} className={`${cls} hover:bg-indigo-50/60`} onClick={(e) => e.stopPropagation()}>{body}</a>
    : <div className={cls}>{body}</div>;
}

// Primary CTA colour = the card's urgency, so the next step on a critical item reads red.
const CTA_VARIANT = { critical: 'danger', warning: 'warning', info: 'primary', success: 'success' };

// ─────────────────────────────────────────────────────────────────────────────
// Notification card — the operations "work item".
//
// Reads top-to-bottom as: WHAT (icon tile · severity · type · title),
// WHY (highlight metrics), WHO/WHAT (entity grid: vehicle · customer · contract ·
// ticket · garage · driver), and WHAT NEXT (severity-coloured CTA + mark-read).
// A severity rail + tint carry urgency; unread criticals dominate, read cards recede.
// `grouped` strips the outer frame + redundant vehicle chip when nested in a VehicleGroup.
// ─────────────────────────────────────────────────────────────────────────────

function NotificationRow({ n, onAction, onMarkRead, onDismiss, grouped = false }) {
  const theme = severityTheme(n.severity);
  const highlights = metaHighlights(n);
  let entities = metaEntities(n);
  if (grouped) entities = entities.filter((e) => e.key !== 'plate' && e.key !== 'ticket');
  const kind = typeLabel(n.type);
  // Resolved = the condition cleared on its own, so this row is a record, not a demand. A sold
  // car's expired insurance is the case that forced this: the scanner had already stopped
  // raising it, but the row still wore a Critical badge and a "Renew Insurance" button. Settled
  // rows keep their text and drop the urgency + the CTA.
  const settled = !!n.resolved;
  const label = settled ? null : actionLabel(n);
  const isCritical = !settled && n.severity === 'critical';
  const emphasize = !settled && !n.read && (isCritical || n.severity === 'warning');

  // Grouped members sit inside a shared frame → flat, divided rows. Standalone cards
  // carry their own frame, severity rail and (for hot unread items) a tinted body.
  const shell = grouped
    ? 'group relative flex gap-4 p-4 transition-colors duration-150 hover:bg-slate-50'
    : [
        'group relative overflow-hidden rounded-2xl border shadow-soft transition-all duration-150 hover:-translate-y-px hover:shadow-card',
        isCritical && !n.read ? 'border-red-200' : 'border-slate-200/70',
        emphasize ? theme.cardTint : 'bg-white',
        n.read ? '' : `ring-1 ring-inset ${theme.ring}`,
      ].join(' ');

  const inner = (
    <>
      {/* icon tile — critical unread gets the solid gradient tile so it pops hardest */}
      <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ${isCritical && !n.read ? `${theme.iconBg} text-white shadow-sm` : `${theme.chipBg} ${theme.chipText}`}`}>
        <svg aria-hidden="true" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
          <path d={iconPath(n.icon)} />
        </svg>
      </div>

      <div className="min-w-0 flex-1">
        <div className="flex items-start gap-3">
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
              {settled
                ? <Badge tone="slate">No longer applies</Badge>
                : <Badge tone={SEVERITY_TONE[n.severity] || 'blue'} dot>{theme.label}</Badge>}
              <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{kind}</span>
              {!n.read && !settled && <span className={`h-2 w-2 shrink-0 rounded-full ${theme.dot}`} aria-label="unread" />}
            </div>
            <h3 className={`mt-1.5 text-sm leading-snug ${n.read ? 'font-semibold text-slate-700' : 'font-bold text-slate-900'}`}>
              {n.title}
            </h3>
            {n.body && <p className="mt-1 text-[13px] leading-relaxed text-slate-500">{n.body}</p>}
          </div>

          {/* timestamp (exact on hover) + dismiss */}
          <div className="flex shrink-0 flex-col items-end gap-1">
            <div className="flex items-center gap-1">
              <time dateTime={n.created_at} title={exactTime(n.created_at)} className="whitespace-nowrap text-xs font-medium text-slate-400">
                {relativeTime(n.created_at)}
              </time>
              {onDismiss && (
                <button
                  type="button"
                  onClick={() => onDismiss(n.id)}
                  className="rounded-lg p-1 text-slate-300 opacity-0 transition-colors duration-150 hover:bg-slate-100 hover:text-slate-500 focus-visible:opacity-100 group-hover:opacity-100"
                  title="Dismiss"
                  aria-label="Dismiss notification"
                >
                  <svg aria-hidden="true" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              )}
            </div>
            {!grouped && <span className="hidden whitespace-nowrap text-[10px] tabular-nums text-slate-300 sm:block">{exactTime(n.created_at)}</span>}
          </div>
        </div>

        {/* highlights — the urgency metrics */}
        {highlights.length > 0 && (
          <div className="mt-3 flex flex-wrap items-center gap-1.5">
            {highlights.map((h, i) => (
              <span key={i} className={`inline-flex items-center rounded-lg px-2 py-0.5 text-[11px] font-bold capitalize ring-1 ring-inset ${HIGHLIGHT_TONE[h.tone] || HIGHLIGHT_TONE.neutral}`}>
                {h.text}
              </span>
            ))}
          </div>
        )}

        {/* entities — the structured who/what grid */}
        {entities.length > 0 && (
          <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
            {entities.map((e) => <EntityField key={e.key} {...e} />)}
          </div>
        )}

        {/* actions: severity-coloured primary CTA + ghost "mark read" */}
        {(label || (onMarkRead && !n.read)) && (
          <div className="mt-4 flex items-center gap-2">
            {label && (
              <Button variant={CTA_VARIANT[n.severity] || 'primary'} size={grouped ? 'sm' : 'md'} onClick={() => onAction?.(n)} className="shadow-sm">
                {label}
                <svg aria-hidden="true" className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M9 5l7 7-7 7" />
                </svg>
              </Button>
            )}
            {onMarkRead && !n.read && (
              <Button variant="ghost" size={grouped ? 'sm' : 'md'} onClick={() => onMarkRead(n)}>
                Mark read
              </Button>
            )}
          </div>
        )}
      </div>
    </>
  );

  if (grouped) return <article className={shell}>{inner}</article>;

  return (
    <article className={shell}>
      <span className={`absolute inset-y-0 start-0 ${isCritical ? 'w-1.5' : 'w-1'} ${theme.accent} ${n.read ? 'opacity-40' : ''}`} aria-hidden="true" />
      <div className="flex gap-4 p-5 ps-6">{inner}</div>
    </article>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Lane chip — one lane in the picker. Icon-led and full-size so a dozen lanes stay
// scannable: the label carries the weight, the pill is the total, and a small
// indigo dot marks "there's something new in here". Empty lanes recede but stay
// clickable, so the operator can always see the full shape of their board.
// ─────────────────────────────────────────────────────────────────────────────

function LaneChip({ tab, active, count, unread, onClick }) {
  const empty = count === 0;
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      title={tab.blurb || tab.label}
      className={[
        'group inline-flex items-center gap-2 rounded-xl px-3 py-2 text-[13px] font-bold ring-1 ring-inset transition-all duration-150',
        'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500',
        active
          ? 'bg-indigo-600 text-white ring-indigo-600 shadow-sm'
          : empty
            ? 'bg-white text-slate-400 ring-slate-200/70 hover:text-slate-700 hover:ring-slate-300'
            : 'bg-white text-slate-700 ring-slate-200 hover:-translate-y-px hover:bg-slate-50 hover:text-slate-900 hover:shadow-soft hover:ring-slate-300',
      ].join(' ')}
    >
      <svg
        aria-hidden="true"
        className={[
          'h-4 w-4 shrink-0 transition-colors',
          active ? 'text-white/90' : empty ? 'text-slate-300' : 'text-slate-400 group-hover:text-indigo-500',
        ].join(' ')}
        fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"
      >
        <path d={iconPath(tab.icon || 'bell')} />
      </svg>

      <span className="whitespace-nowrap">{tab.label}</span>

      {count > 0 && (
        <span
          className={[
            'inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full px-1.5 text-[11px] font-bold tabular-nums',
            active ? 'bg-white/25 text-white' : unread > 0 ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-500',
          ].join(' ')}
        >
          {count}
        </span>
      )}

      {unread > 0 && !active && (
        <span aria-label={`${unread} unread`} className="h-1.5 w-1.5 shrink-0 rounded-full bg-indigo-500" />
      )}
    </button>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Workload stat tile — the glanceable counts across the top. Critical & Action
// tiles are buttons that toggle a focus filter; the rest are plain read-outs.
// ─────────────────────────────────────────────────────────────────────────────

const STAT_TONE = {
  red: { ring: 'ring-red-200', activeRing: 'ring-red-400', bg: 'bg-red-50', text: 'text-red-600', icon: 'bg-red-100 text-red-600' },
  indigo: { ring: 'ring-indigo-200', activeRing: 'ring-indigo-400', bg: 'bg-indigo-50', text: 'text-indigo-600', icon: 'bg-indigo-100 text-indigo-600' },
  blue: { ring: 'ring-blue-200', activeRing: 'ring-blue-400', bg: 'bg-blue-50', text: 'text-blue-600', icon: 'bg-blue-100 text-blue-600' },
  slate: { ring: 'ring-slate-200', activeRing: 'ring-slate-400', bg: 'bg-slate-50', text: 'text-slate-700', icon: 'bg-slate-100 text-slate-500' },
};

function StatTile({ label, value, tone = 'slate', icon = 'bell', active = false, disabled = false, onClick }) {
  const t = STAT_TONE[tone] || STAT_TONE.slate;
  const clickable = !!onClick && !disabled;
  const zero = !value;
  return (
    <button
      type="button"
      onClick={clickable ? onClick : undefined}
      aria-pressed={onClick ? active : undefined}
      disabled={!clickable}
      className={[
        'flex items-center gap-3 rounded-2xl border bg-white px-4 py-3 text-start shadow-soft transition-all duration-150',
        active ? `${t.bg} ring-2 ${t.activeRing} border-transparent` : 'border-slate-200/70',
        clickable ? 'hover:-translate-y-px hover:shadow-card focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2' : 'cursor-default',
        zero && !active ? 'opacity-70' : '',
      ].join(' ')}
    >
      <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ${zero ? 'bg-slate-100 text-slate-400' : t.icon}`}>
        <svg aria-hidden="true" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
          <path d={iconPath(icon)} />
        </svg>
      </span>
      <span className="min-w-0">
        <span className={`block text-xl font-bold leading-none tabular-nums ${zero ? 'text-slate-400' : t.text}`}>{value}</span>
        <span className="mt-1 block truncate text-[11px] font-semibold uppercase tracking-wide text-slate-400">{label}</span>
      </span>
    </button>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Section header — a coloured spine + label + count. Shared by priority & time views.
// ─────────────────────────────────────────────────────────────────────────────

function SectionHeader({ accent, label, sub, count, unread }) {
  return (
    <div className="flex items-center gap-2.5 px-1">
      <span className={`h-5 w-1.5 rounded-full ${accent}`} aria-hidden="true" />
      <h2 className="text-sm font-bold text-slate-800">{label}</h2>
      <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold tabular-nums text-slate-500">{count}</span>
      {sub && <span className="hidden text-xs text-slate-400 sm:inline">· {sub}</span>}
      {unread > 0 && (
        <span className="ms-auto rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-bold tabular-nums text-indigo-600">{unread} unread</span>
      )}
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Priority section — Critical gets a dominant framed panel; the rest are plain
// sections under a coloured header so the eye falls to the top of the page first.
// ─────────────────────────────────────────────────────────────────────────────

function PrioritySection({ severity, rows, children }) {
  const theme = severityTheme(severity);
  const def = PRIORITY_SECTION[severity];
  const unread = rows.filter((r) => !r.read).length;

  if (severity === 'critical') {
    return (
      <section className="overflow-hidden rounded-2xl border-2 border-red-200 bg-red-50/40 shadow-card">
        <header className="flex items-center gap-3 bg-gradient-to-r from-red-500 to-rose-600 px-4 py-3 text-white">
          <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-white/20">
            <svg aria-hidden="true" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round">
              <path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" />
            </svg>
          </span>
          <div className="min-w-0">
            <p className="text-sm font-bold">Critical · {rows.length} need{rows.length === 1 ? 's' : ''} immediate attention</p>
            <p className="text-xs text-red-50/90">Start here — highest-priority work in your fleet right now.</p>
          </div>
          {unread > 0 && <span className="ms-auto rounded-full bg-white/20 px-2.5 py-1 text-[11px] font-bold tabular-nums">{unread} unread</span>}
        </header>
        <div className="space-y-3 p-3">{children}</div>
      </section>
    );
  }

  return (
    <section className="space-y-3">
      <SectionHeader accent={theme.accent} label={def.label} sub={def.sub} count={rows.length} unread={unread} />
      {children}
    </section>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// VehicleGroup — the several alerts piled on one car / one repair, collapsed into a
// single work item with a shared header so the board reads as vehicles, not rows.
// ─────────────────────────────────────────────────────────────────────────────

function VehicleGroup({ node, onAction, onMarkRead, onDismiss }) {
  const worst = worstSeverity(node.items);
  const theme = severityTheme(worst);
  const heading = clusterLabel(node);
  const unread = node.items.filter((r) => !r.read).length;
  const isPlate = node.key.startsWith('plate:');

  return (
    <section className={`overflow-hidden rounded-2xl border shadow-soft transition-shadow hover:shadow-card ${worst === 'critical' ? 'border-red-200' : 'border-slate-200/70'}`}>
      <header className="flex items-center gap-3 border-b border-slate-100 bg-slate-50/80 px-4 py-2.5">
        <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${theme.chipBg} ${theme.chipText}`}>
          <svg aria-hidden="true" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
            <path d={iconPath(isPlate ? 'car' : 'wrench')} />
          </svg>
        </span>
        <div className="min-w-0">
          <p className={`truncate text-sm font-bold text-slate-800 ${isPlate ? 'font-mono' : ''}`}>{heading}</p>
          <p className="text-[11px] font-medium text-slate-400">{node.items.length} related alerts on this {isPlate ? 'vehicle' : 'ticket'}</p>
        </div>
        <div className="ms-auto flex shrink-0 items-center gap-2">
          <Badge tone={SEVERITY_TONE[worst] || 'blue'} dot>{theme.label}</Badge>
          {unread > 0 && <span className="rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-bold tabular-nums text-indigo-600">{unread} new</span>}
        </div>
      </header>
      <div className="divide-y divide-slate-100">
        {node.items.map((n) => (
          <NotificationRow key={n.id} n={n} grouped onAction={onAction} onMarkRead={onMarkRead} onDismiss={onDismiss} />
        ))}
      </div>
    </section>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Lane empty state — never a dead end. Explains what belongs in the lane and what
// the operator can expect to land here, so an empty lane still reads as "on top of it".
// ─────────────────────────────────────────────────────────────────────────────

function LaneEmptyState({ tab, isAll }) {
  const blurb = tab.blurb
    ? `${tab.blurb.charAt(0).toUpperCase()}${tab.blurb.slice(1)}`
    : 'Operational alerts for this lane';
  return (
    <Card>
      <div className="flex flex-col items-center justify-center px-6 py-16 text-center">
        <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-500 ring-1 ring-inset ring-emerald-200/70">
          <svg className="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
            <path d="M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
          </svg>
        </div>
        <p className="text-sm font-bold text-slate-900">{isAll ? "You're all caught up" : tab.empty}</p>
        <p className="mt-1.5 max-w-md text-sm text-slate-500">
          {isAll
            ? 'Nothing needs you right now. New fleet alerts land here automatically the moment conditions change.'
            : `Nothing needs you in this lane right now.`}
        </p>
        {!isAll && (
          <div className="mt-4 flex items-start gap-2 rounded-xl bg-slate-50 px-3.5 py-2.5 text-start ring-1 ring-inset ring-slate-200/60">
            <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-white text-slate-400 ring-1 ring-inset ring-slate-200">
              <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d={iconPath(tab.icon || 'bell')} /></svg>
            </span>
            <span className="text-xs text-slate-500"><span className="font-semibold text-slate-600">What lands here:</span> {blurb}.</span>
          </div>
        )}
      </div>
    </Card>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Loading skeleton — shimmer blocks in the same card silhouette as the rows.
// ─────────────────────────────────────────────────────────────────────────────

function SkeletonList() {
  return (
    <div className="space-y-3">
      {[0, 1, 2, 3].map((i) => (
        <div key={i} className="flex gap-4 rounded-2xl border border-slate-200/60 bg-white p-5 shadow-soft">
          <div className="shimmer h-11 w-11 shrink-0 rounded-xl bg-slate-100" />
          <div className="flex-1 space-y-3 py-0.5">
            <div className="shimmer h-3.5 w-2/5 rounded-full bg-slate-100" />
            <div className="shimmer h-3 w-4/5 rounded-full bg-slate-100" />
            <div className="flex gap-2 pt-1">
              <div className="shimmer h-5 w-16 rounded-full bg-slate-100" />
              <div className="shimmer h-5 w-12 rounded-full bg-slate-100" />
            </div>
          </div>
        </div>
      ))}
    </div>
  );
}
