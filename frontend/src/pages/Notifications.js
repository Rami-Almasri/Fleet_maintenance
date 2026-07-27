import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api/client';
import { useNotifications } from '../hooks/useNotifications';
import { usePermissions } from '../hooks/usePermissions';
import { PageHeader, EmptyState, Card } from '../components/ui/Misc';
import Button from '../components/ui/Button';
import Badge from '../components/ui/Badge';
import FilterChips from '../components/ui/FilterChips';
import {
  visibleLanes,
  laneOf,
  severityRank,
  severityTheme,
  iconPath,
  timeAgo,
  actionLabel,
  metaChips,
  dateBucket,
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
// Built on the shared ui/ primitives (PageHeader, FilterChips, Badge, Button,
// EmptyState). All data/theming still flows from lib/notifications.js so the
// bell dropdown and this page stay in lock-step.
// ─────────────────────────────────────────────────────────────────────────────

export default function Notifications() {
  const navigate = useNavigate();
  const { unreadCount, refresh, markAllRead, clearAll } = useNotifications();
  const { can } = usePermissions();

  // The lanes this operator is allowed to see (permission-gated), plus a Set of
  // their keys for fast lane assignment. Recomputed only when the permission set
  // changes — `can` is derived from the (stable) logged-in user.
  const lanes = useMemo(() => visibleLanes(can), [can]);
  const laneKeys = useMemo(() => new Set(lanes.map((l) => l.key)), [lanes]);

  const [filter, setFilter] = useState('all');       // all | unread (server-side)
  const [activeTab, setActiveTab] = useState('all');  // 'all' or a specific notification type (client-side)
  const [items, setItems] = useState([]);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ last_page: 1, total: 0 });
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState('');

  const fetchPage = useCallback(async (p, replace) => {
    p === 1 ? setLoading(true) : setLoadingMore(true);
    setError('');
    try {
      const { data } = await api.get('/notifications', { params: { filter, page: p } });
      const payload = data.data || {};
      setMeta({ last_page: payload.last_page || 1, total: payload.total || 0 });
      setItems((prev) => (replace ? payload.items : [...prev, ...(payload.items || [])]));
    } catch (err) {
      setError(err.response?.data?.message || err.message || 'Could not load notifications');
    } finally {
      setLoading(false);
      setLoadingMore(false);
    }
  }, [filter]);

  // (Re)load from the top whenever the read filter changes.
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
    if (n.url) navigate(n.url);
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

  // Per-tab counts feed the badge on each pill ("Complaints · 3").
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

  // Fall back to "All" if the active type-tab no longer exists (e.g. all its
  // notifications were dismissed or the filter changed).
  useEffect(() => {
    if (activeTab !== 'all' && !tabs.some((t) => t.key === activeTab)) {
      setActiveTab('all');
    }
  }, [tabs, activeTab]);

  const visible = byTab[activeTab] || [];
  const activeTabDef = tabs.find((t) => t.key === activeTab) || tabs[0];
  const criticalCount = items.filter((n) => n.severity === 'critical' && !n.read).length;

  // FilterChips options: unread count when present (indigo), else total (slate).
  const chipOptions = tabs.map((t) => {
    const count = countsByTab.counts[t.key] || 0;
    const unread = countsByTab.unread[t.key] || 0;
    return {
      key: t.key,
      label: t.label,
      count: count > 0 ? (unread > 0 ? unread : count) : undefined,
      tone: unread > 0 ? 'indigo' : 'slate',
    };
  });

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

        {/* glanceable totals */}
        <div className="flex items-center gap-2 text-xs font-medium text-slate-400 sm:ps-3.5">
          <span className="tabular-nums">{meta.total} total</span>
          <span className="text-slate-300">·</span>
          <span className={`tabular-nums ${unreadCount ? 'text-indigo-600' : ''}`}>{unreadCount} unread</span>
          {criticalCount > 0 && (
            <>
              <span className="text-slate-300">·</span>
              <span className="inline-flex items-center gap-1 tabular-nums text-red-600">
                <span className="h-1.5 w-1.5 rounded-full bg-red-500" aria-hidden="true" />
                {criticalCount} critical
              </span>
            </>
          )}
        </div>

        {/* ── Controls: category chips (client filter) + unread toggle (server) ── */}
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <nav aria-label="Notification categories" className="min-w-0 overflow-x-auto pb-1">
            <FilterChips value={activeTab} onChange={setActiveTab} options={chipOptions} />
          </nav>

          {/* Unread-only toggle — server-side filter, kept subtle on the right. */}
          <button
            type="button"
            onClick={() => setFilter((f) => (f === 'unread' ? 'all' : 'unread'))}
            aria-pressed={filter === 'unread'}
            className={[
              'inline-flex shrink-0 items-center gap-2 self-start rounded-lg px-3 py-2 text-xs font-semibold ring-1 ring-inset transition-colors duration-150 sm:self-auto',
              filter === 'unread'
                ? 'bg-indigo-50 text-indigo-700 ring-indigo-200'
                : 'bg-white text-slate-500 ring-slate-200 hover:bg-slate-50 hover:text-slate-700',
            ].join(' ')}
          >
            <span
              aria-hidden="true"
              className={[
                'h-2 w-2 rounded-full transition-colors',
                filter === 'unread' ? 'bg-indigo-500' : 'bg-slate-300',
              ].join(' ')}
            />
            Unread only
          </button>
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
          <Card>
            <EmptyState
              title={activeTabDef.empty}
              message={activeTab === 'all'
                ? 'New fleet alerts will land here automatically as conditions change.'
                : 'Switch to another tab — or check back as new alerts come in.'}
            />
          </Card>
        ) : (
          <div className="stagger space-y-6">
            {BUCKET_ORDER.map((bucket) => {
              const rows = visible.filter((n) => dateBucket(n.created_at) === bucket);
              if (rows.length === 0) return null;
              return (
                <section key={bucket} className="space-y-3">
                  <h2 className="px-1 text-xs font-semibold uppercase tracking-wide text-slate-400">
                    {bucket}
                  </h2>
                  {rows.map((n) => (
                    <NotificationRow
                      key={n.id}
                      n={n}
                      onAction={openNotification}
                      onMarkRead={markRead}
                      onDismiss={dismiss}
                    />
                  ))}
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
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Notification row — a clean, breathable Aurora card.
//
// Hierarchy: soft severity-tinted icon tile · bold dark title · muted body ·
// glanceable badges · primary CTA. Unread is a quiet cue (a coloured dot +
// faint accent rail), never a loud banner. Read rows recede to plain white.
// ─────────────────────────────────────────────────────────────────────────────

function NotificationRow({ n, onAction, onMarkRead, onDismiss }) {
  const theme = severityTheme(n.severity);
  const chips = metaChips(n);
  const label = actionLabel(n);

  return (
    <article
      className={[
        'group relative flex gap-4 rounded-2xl border border-slate-200/60 bg-white p-5 shadow-soft hover-lift',
        n.read ? '' : 'bg-indigo-50/20',
      ].join(' ')}
    >
      {/* unread accent rail — faint, only when unread */}
      {!n.read && (
        <span className={`absolute inset-y-3 left-0 w-1 rounded-full ${theme.accent}`} aria-hidden="true" />
      )}

      {/* icon tile — soft tint, carries severity colour without shouting */}
      <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ${theme.chipBg} ${theme.chipText}`}>
        <svg aria-hidden="true" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
          <path d={iconPath(n.icon)} />
        </svg>
      </div>

      <div className="min-w-0 flex-1">
        {/* title row + timestamp + dismiss */}
        <div className="flex items-start gap-3">
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2">
              {!n.read && <span className={`h-2 w-2 shrink-0 rounded-full ${theme.dot}`} aria-label="unread" />}
              <h3 className={`text-sm leading-snug ${n.read ? 'font-medium text-slate-600' : 'font-bold text-slate-900'}`}>
                {n.title}
              </h3>
            </div>
            {n.body && (
              <p className="mt-1 text-[13px] leading-relaxed text-slate-500">{n.body}</p>
            )}
          </div>

          <div className="flex shrink-0 items-center gap-1">
            <span className="whitespace-nowrap text-xs font-medium tabular-nums text-slate-400">{timeAgo(n.created_at)}</span>
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
        </div>

        {/* badges: severity + glanceable meta chips */}
        <div className="mt-3 flex flex-wrap items-center gap-1.5">
          <Badge tone={SEVERITY_TONE[n.severity] || 'blue'} dot>{theme.label}</Badge>
          {chips.map((c, i) => (
            <span
              key={i}
              className={[
                'inline-flex items-center rounded-lg px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset',
                c.plate
                  ? 'bg-slate-50 font-mono tabular-nums text-slate-600 ring-slate-200'
                  : c.tone === 'strong'
                    ? `${theme.chipBg} ${theme.chipText} ring-transparent`
                    : 'bg-slate-50 text-slate-600 ring-slate-200',
              ].join(' ')}
            >
              {c.text}
            </span>
          ))}
        </div>

        {/* actions: primary CTA + ghost "mark read" */}
        {(label || (onMarkRead && !n.read)) && (
          <div className="mt-4 flex items-center gap-2">
            {label && (
              <Button variant="primary" size="sm" onClick={() => onAction?.(n)}>
                {label}
                <svg aria-hidden="true" className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M9 5l7 7-7 7" />
                </svg>
              </Button>
            )}
            {onMarkRead && !n.read && (
              <Button variant="ghost" size="sm" onClick={() => onMarkRead(n)}>
                Mark read
              </Button>
            )}
          </div>
        )}
      </div>
    </article>
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
