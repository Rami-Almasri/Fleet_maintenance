import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api/client';
import { useNotifications } from '../hooks/useNotifications';
import NotificationRow from '../components/NotificationRow';
import { Card, PageHeader, Spinner, EmptyState } from '../components/ui/Misc';
import { dateBucket } from '../lib/notifications';

const BUCKET_ORDER = ['Today', 'Yesterday', 'This week', 'This month', 'Earlier'];

export default function Notifications() {
  const navigate = useNavigate();
  const { unreadCount, refresh, markAllRead, clearAll } = useNotifications();

  const [filter, setFilter] = useState('all');     // all | unread
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

  // (Re)load from the top whenever the filter changes.
  useEffect(() => { setPage(1); fetchPage(1, true); }, [fetchPage]);

  const loadMore = () => {
    const next = page + 1;
    setPage(next);
    fetchPage(next, false);
  };

  const openNotification = async (n) => {
    if (!n.read) {
      setItems((list) => list.map((x) => (x.id === n.id ? { ...x, read: true } : x)));
      try { await api.post(`/notifications/${n.id}/read`); } catch (_) {}
      refresh();
    }
    if (n.url) navigate(n.url);
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

  const onClearAll = async () => {
    setItems([]);
    setMeta({ last_page: 1, total: 0 });
    await clearAll();
  };

  // Group the loaded items into calendar buckets, preserving server order.
  const groups = useMemo(() => {
    const map = new Map();
    for (const n of items) {
      const b = dateBucket(n.created_at);
      if (!map.has(b)) map.set(b, []);
      map.get(b).push(n);
    }
    return BUCKET_ORDER.filter((b) => map.has(b)).map((b) => [b, map.get(b)]);
  }, [items]);

  const criticalCount = items.filter((n) => n.severity === 'critical' && !n.read).length;

  return (
    <div className="py-8">
      <div className="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Notifications"
          subtitle="Live fleet alerts — overdue rentals, maintenance overruns, expiring documents, service-due cars and approvals."
        >
          <div className="flex items-center gap-2">
            <button
              onClick={onMarkAll}
              disabled={!unreadCount}
              className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-600 shadow-sm transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
            >
              Mark all read
            </button>
            <button
              onClick={onClearAll}
              disabled={!meta.total}
              className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-600 shadow-sm transition hover:bg-red-50 hover:text-red-600 disabled:cursor-not-allowed disabled:opacity-50"
            >
              Clear all
            </button>
          </div>
        </PageHeader>

        {/* summary tiles */}
        <div className="grid grid-cols-3 gap-4">
          <div className="rounded-2xl border border-gray-100 bg-white px-5 py-4 shadow-sm ring-1 ring-gray-900/5">
            <p className="text-xs font-medium text-gray-500">Total</p>
            <p className="mt-1 text-2xl font-bold tracking-tight text-gray-900">{meta.total}</p>
          </div>
          <div className="rounded-2xl border border-gray-100 bg-white px-5 py-4 shadow-sm ring-1 ring-gray-900/5">
            <p className="text-xs font-medium text-gray-500">Unread</p>
            <p className="mt-1 text-2xl font-bold tracking-tight text-indigo-600">{unreadCount}</p>
          </div>
          <div className="rounded-2xl border border-gray-100 bg-white px-5 py-4 shadow-sm ring-1 ring-gray-900/5">
            <p className="text-xs font-medium text-gray-500">Critical (unread)</p>
            <p className="mt-1 text-2xl font-bold tracking-tight text-red-600">{criticalCount}</p>
          </div>
        </div>

        {/* filter tabs */}
        <div className="inline-flex rounded-xl border border-slate-200 bg-white p-1 shadow-sm">
          {[['all', 'All'], ['unread', 'Unread']].map(([key, label]) => (
            <button
              key={key}
              onClick={() => setFilter(key)}
              className={`rounded-lg px-4 py-1.5 text-sm font-medium transition ${
                filter === key ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-800'
              }`}
            >
              {label}
              {key === 'unread' && unreadCount > 0 && (
                <span className={`ml-1.5 rounded-full px-1.5 text-[11px] font-bold ${filter === key ? 'bg-white/20' : 'bg-indigo-100 text-indigo-700'}`}>
                  {unreadCount}
                </span>
              )}
            </button>
          ))}
        </div>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {loading ? (
          <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>
        ) : items.length === 0 ? (
          <Card>
            <EmptyState
              title={filter === 'unread' ? 'No unread notifications' : "You're all caught up"}
              message="New fleet alerts will land here automatically as conditions change."
            />
          </Card>
        ) : (
          <div className="space-y-6">
            {groups.map(([bucket, rows]) => (
              <div key={bucket}>
                <p className="mb-2 px-1 text-xs font-semibold uppercase tracking-wide text-slate-400">{bucket}</p>
                <Card className="overflow-hidden p-0">
                  <div className="divide-y divide-slate-100">
                    {rows.map((n) => (
                      <NotificationRow key={n.id} n={n} onOpen={openNotification} onDismiss={dismiss} />
                    ))}
                  </div>
                </Card>
              </div>
            ))}

            {page < meta.last_page && (
              <div className="flex justify-center pt-2">
                <button
                  onClick={loadMore}
                  disabled={loadingMore}
                  className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-medium text-slate-600 shadow-sm transition hover:bg-slate-50 disabled:opacity-60"
                >
                  {loadingMore && <Spinner className="h-4 w-4" />}
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
