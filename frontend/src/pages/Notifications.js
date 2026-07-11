import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api/client';
import { useNotifications } from '../hooks/useNotifications';
import {
  INBOX_CATEGORIES,
  inboxCategoryOf,
  severityRank,
  severityTheme,
  iconPath,
  timeAgo,
  actionLabel,
  metaChips,
} from '../lib/notifications';

// The fixed tab set for the Action Center: an "All" tab, the three focused inbox
// categories, then an "Other" catch-all (only shown when it actually has rows, so
// nothing is hidden yet the tab bar stays clean).
const OTHER_TAB = { key: 'other', label: 'Other', icon: 'bell', empty: 'No other notifications' };
const BASE_TABS = [
  { key: 'all', label: 'All', icon: 'bell', empty: 'No notifications found' },
  ...INBOX_CATEGORIES,
];

// ─────────────────────────────────────────────────────────────────────────────
// Action Center — "v0 / shadcn"-flavoured redesign.
//
// Built on the existing Tailwind + Aurora theme (no shadcn dependencies). The
// look is the v0 language: lots of whitespace, muted slate borders, soft tinted
// icon tiles, pill tabs and crisp dark CTAs. All data/theming still flows from
// lib/notifications.js so the bell dropdown and this page stay in lock-step.
// ─────────────────────────────────────────────────────────────────────────────

export default function Notifications() {
  const navigate = useNavigate();
  const { unreadCount, refresh, markAllRead, clearAll } = useNotifications();

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

  // Clear the whole feed, or just the active category tab. Backend keeps it
  // strictly scoped to the current user either way.
  const onClearAll = async () => {
    const scope = activeTab !== 'all' ? activeTab : undefined;
    if (scope) {
      setItems((list) => list.filter((n) => inboxCategoryOf(n) !== scope));
    } else {
      setItems([]);
      setMeta({ last_page: 1, total: 0 });
    }
    await clearAll(scope);
  };

  // Bucket loaded items by inbox category once (memoized), each sorted by urgency
  // then recency. Switching tabs is then a constant-time lookup — no refetch.
  const byTab = useMemo(() => {
    const buckets = { all: [] };
    for (const n of items) {
      const c = inboxCategoryOf(n);
      buckets.all.push(n);
      (buckets[c] || (buckets[c] = [])).push(n);
    }
    const sortRows = (rows) => rows.sort((a, b) =>
      severityRank(a.severity) - severityRank(b.severity) ||
      new Date(b.created_at) - new Date(a.created_at));
    Object.values(buckets).forEach(sortRows);
    return buckets;
  }, [items]);

  // Fixed tabs: All + the three categories, plus "Other" only when it has rows.
  const tabs = useMemo(() => (
    (byTab.other && byTab.other.length) ? [...BASE_TABS, OTHER_TAB] : BASE_TABS
  ), [byTab]);

  // Per-tab counts feed the badge on each pill ("Routine · 3").
  const countsByTab = useMemo(() => {
    const counts = { all: 0 };
    const unread = { all: 0 };
    for (const n of items) {
      const c = inboxCategoryOf(n);
      counts.all += 1;
      counts[c] = (counts[c] || 0) + 1;
      if (!n.read) {
        unread.all += 1;
        unread[c] = (unread[c] || 0) + 1;
      }
    }
    return { counts, unread };
  }, [items]);

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

  return (
    <div className="py-10">
      <div className="mx-auto max-w-4xl space-y-8 px-4 sm:px-6">

        {/* ── Header ─────────────────────────────────────────────────────── */}
        <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div className="space-y-1.5">
            <h1 className="text-2xl font-bold tracking-tight text-slate-900">Action Center</h1>
            <p className="max-w-xl text-sm leading-relaxed text-slate-500">
              Your fleet to-do list — overdue rentals, expiring documents and service-due
              cars, sorted into categories and ready to act on.
            </p>
            <div className="flex items-center gap-2 pt-1 text-xs font-medium text-slate-400">
              <span>{meta.total} total</span>
              <span className="text-slate-300">·</span>
              <span className={unreadCount ? 'text-indigo-600' : ''}>{unreadCount} unread</span>
              {criticalCount > 0 && (
                <>
                  <span className="text-slate-300">·</span>
                  <span className="inline-flex items-center gap-1 text-red-600">
                    <span className="h-1.5 w-1.5 rounded-full bg-red-500" />
                    {criticalCount} critical
                  </span>
                </>
              )}
            </div>
          </div>

          <div className="flex shrink-0 items-center gap-2">
            <button
              onClick={onMarkAll}
              disabled={!unreadCount}
              className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-600 shadow-sm transition-all duration-150 hover:bg-slate-50 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-50"
            >
              Mark all read
            </button>
            <button
              onClick={onClearAll}
              disabled={visible.length === 0}
              className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-600 shadow-sm transition-all duration-150 hover:border-red-200 hover:bg-red-50 hover:text-red-600 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {activeTab === 'all' ? 'Clear all' : `Clear ${activeTabDef.label}`}
            </button>
          </div>
        </header>

        {/* ── Controls: pill tabs (client filter) + unread toggle (server) ── */}
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <nav
            className="-mx-1 flex items-center gap-1 overflow-x-auto px-1 pb-1"
            aria-label="Notification categories"
          >
            {tabs.map((t) => {
              const count = countsByTab.counts[t.key] || 0;
              const unread = countsByTab.unread[t.key] || 0;
              const active = activeTab === t.key;
              return (
                <button
                  key={t.key}
                  onClick={() => setActiveTab(t.key)}
                  aria-current={active ? 'page' : undefined}
                  className={[
                    'group inline-flex shrink-0 items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium transition-all duration-200',
                    active
                      ? 'bg-indigo-50 text-indigo-700'
                      : 'text-slate-500 hover:bg-slate-100 hover:text-slate-800',
                  ].join(' ')}
                >
                  <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                    <path d={iconPath(t.icon)} />
                  </svg>
                  <span>{t.label}</span>
                  {count > 0 && (
                    <span
                      className={[
                        'inline-flex min-w-[1.25rem] items-center justify-center rounded-full px-1.5 py-0.5 text-[11px] font-bold leading-none transition-colors',
                        unread > 0
                          ? active ? 'bg-indigo-600 text-white' : 'bg-indigo-100 text-indigo-700'
                          : active ? 'bg-indigo-100 text-indigo-600' : 'bg-slate-200/70 text-slate-500',
                      ].join(' ')}
                    >
                      {unread > 0 ? unread : count}
                    </span>
                  )}
                </button>
              );
            })}
          </nav>

          {/* Unread-only toggle — server-side filter, kept subtle on the right. */}
          <button
            onClick={() => setFilter((f) => (f === 'unread' ? 'all' : 'unread'))}
            className={[
              'inline-flex shrink-0 items-center gap-2 self-start rounded-lg border px-3 py-2 text-sm font-medium transition-all duration-150 sm:self-auto',
              filter === 'unread'
                ? 'border-indigo-200 bg-indigo-50 text-indigo-700'
                : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50 hover:text-slate-800',
            ].join(' ')}
          >
            <span
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
          <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {error}
          </div>
        )}

        {/* ── List ───────────────────────────────────────────────────────── */}
        {loading ? (
          <SkeletonList />
        ) : items.length === 0 ? (
          <EmptyState
            title={filter === 'unread' ? 'No unread notifications' : "You're all caught up"}
            message="New fleet alerts will land here automatically as conditions change."
          />
        ) : visible.length === 0 ? (
          <EmptyState
            title={activeTabDef.empty}
            message={activeTab === 'all'
              ? 'New fleet alerts will land here automatically as conditions change.'
              : 'Switch to another tab — or check back as new alerts come in.'}
          />
        ) : (
          <div className="space-y-3">
            {visible.map((n) => (
              <NotificationRow
                key={n.id}
                n={n}
                onAction={openNotification}
                onMarkRead={markRead}
                onDismiss={dismiss}
              />
            ))}

            {page < meta.last_page && (
              <div className="flex justify-center pt-3">
                <button
                  onClick={loadMore}
                  disabled={loadingMore}
                  className="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-sm font-medium text-slate-600 shadow-sm transition-all duration-150 hover:bg-slate-50 hover:text-slate-900 disabled:opacity-60"
                >
                  {loadingMore && (
                    <svg className="h-4 w-4 animate-spin text-slate-400" viewBox="0 0 24 24" fill="none">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z" />
                    </svg>
                  )}
                  Load more
                </button>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Notification row — a clean, breathable "card" in the v0 language.
//
// Hierarchy: soft severity-tinted icon tile · bold dark title · muted body ·
// glanceable badges · crisp dark CTA. Unread is a quiet cue (a coloured dot +
// faint left tint), never a loud banner. Read rows recede to plain white.
// ─────────────────────────────────────────────────────────────────────────────

function NotificationRow({ n, onAction, onMarkRead, onDismiss }) {
  const theme = severityTheme(n.severity);
  const chips = metaChips(n);
  const label = actionLabel(n);

  return (
    <article
      className={[
        'group relative flex gap-4 rounded-xl border bg-white p-5 shadow-sm transition-all duration-200',
        'hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md',
        n.read ? 'border-slate-200' : 'border-slate-200',
      ].join(' ')}
    >
      {/* unread accent rail — faint, only when unread */}
      {!n.read && (
        <span className={`absolute inset-y-3 left-0 w-1 rounded-full ${theme.accent}`} aria-hidden="true" />
      )}

      {/* icon tile — soft tint, carries severity colour without shouting */}
      <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ${theme.chipBg} ${theme.chipText}`}>
        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
          <path d={iconPath(n.icon)} />
        </svg>
      </div>

      <div className="min-w-0 flex-1">
        {/* title row + timestamp + dismiss */}
        <div className="flex items-start gap-3">
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2">
              {!n.read && <span className={`h-2 w-2 shrink-0 rounded-full ${theme.dot}`} aria-label="unread" />}
              <h3 className={`text-sm leading-snug ${n.read ? 'font-semibold text-slate-700' : 'font-bold text-slate-900'}`}>
                {n.title}
              </h3>
            </div>
            {n.body && (
              <p className="mt-1 text-[13px] leading-relaxed text-slate-500">{n.body}</p>
            )}
          </div>

          <div className="flex shrink-0 items-center gap-1">
            <span className="whitespace-nowrap text-xs font-medium text-slate-400">{timeAgo(n.created_at)}</span>
            {onDismiss && (
              <button
                type="button"
                onClick={() => onDismiss(n.id)}
                className="rounded-md p-1 text-slate-300 opacity-0 transition hover:bg-slate-100 hover:text-slate-500 focus:opacity-100 group-hover:opacity-100"
                title="Dismiss"
                aria-label="Dismiss notification"
              >
                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            )}
          </div>
        </div>

        {/* badges: severity + glanceable meta chips */}
        <div className="mt-3 flex flex-wrap items-center gap-1.5">
          <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ${theme.chipBg} ${theme.chipText}`}>
            {theme.label}
          </span>
          {chips.map((c, i) => (
            <span
              key={i}
              className={[
                'inline-flex items-center rounded-md border px-2 py-0.5 text-[11px] font-semibold',
                c.plate
                  ? 'border-slate-200 bg-slate-50 font-mono text-slate-600'
                  : c.tone === 'strong'
                    ? `border-transparent ${theme.chipBg} ${theme.chipText}`
                    : 'border-slate-200 bg-slate-50 text-slate-600',
              ].join(' ')}
            >
              {c.text}
            </span>
          ))}
        </div>

        {/* actions: crisp dark CTA + ghost "mark read" */}
        {(label || (onMarkRead && !n.read)) && (
          <div className="mt-4 flex items-center gap-2">
            {label && (
              <button
                type="button"
                onClick={() => onAction?.(n)}
                className="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-3.5 py-2 text-xs font-semibold text-white shadow-sm transition-all duration-150 hover:bg-slate-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 active:scale-[0.97]"
              >
                {label}
                <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M9 5l7 7-7 7" />
                </svg>
              </button>
            )}
            {onMarkRead && !n.read && (
              <button
                type="button"
                onClick={() => onMarkRead(n)}
                className="rounded-lg px-3 py-2 text-xs font-medium text-slate-500 transition hover:bg-slate-100 hover:text-slate-700"
              >
                Mark read
              </button>
            )}
          </div>
        )}
      </div>
    </article>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Loading + empty states — minimal, on-theme.
// ─────────────────────────────────────────────────────────────────────────────

function SkeletonList() {
  return (
    <div className="space-y-3">
      {[0, 1, 2, 3].map((i) => (
        <div key={i} className="flex gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
          <div className="h-11 w-11 shrink-0 animate-pulse rounded-xl bg-slate-100" />
          <div className="flex-1 space-y-3 py-0.5">
            <div className="h-3.5 w-2/5 animate-pulse rounded bg-slate-100" />
            <div className="h-3 w-4/5 animate-pulse rounded bg-slate-100" />
            <div className="flex gap-2 pt-1">
              <div className="h-5 w-16 animate-pulse rounded-md bg-slate-100" />
              <div className="h-5 w-12 animate-pulse rounded-md bg-slate-100" />
            </div>
          </div>
        </div>
      ))}
    </div>
  );
}

function EmptyState({ title, message }) {
  return (
    <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-200 bg-white px-6 py-20 text-center">
      <div className="flex h-12 w-12 items-center justify-center rounded-full bg-slate-50 text-slate-400">
        <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
          <path d="M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
        </svg>
      </div>
      <h3 className="mt-4 text-sm font-semibold text-slate-700">{title}</h3>
      <p className="mt-1 max-w-xs text-sm text-slate-400">{message}</p>
    </div>
  );
}
