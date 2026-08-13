// Recent Activity — a compact feed of the latest notifications the app already
// polls (via useNotifications), so it costs no extra request. Each row deep-links
// to the notification's target. The section title + "View all" live on the
// enclosing Band in Workspace.js, so this renders just the card body.

import { memo } from 'react';
import { Link } from 'react-router-dom';
import { Skeleton } from '../ui/Skeleton';
import { fmtAgo } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

const MAX_ROWS = 6;

function RecentActivity({ items = [], loading = false }) {
  const { t } = useI18n();
  const rows = (items || []).slice(0, MAX_ROWS);

  return (
    <div className="rounded-2xl border border-slate-200/60 bg-white p-2 shadow-soft">
      {loading ? (
        <div className="space-y-2 p-3">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-8 w-full" />
          ))}
        </div>
      ) : rows.length === 0 ? (
        <p className="px-3 py-8 text-center text-sm text-slate-400">{t('No recent activity yet.')}</p>
      ) : (
        rows.map((n) => (
          <Link
            key={n.id}
            to={n.url || '/notifications'}
            className="group flex items-center gap-3 rounded-xl px-3 py-2 outline-none transition hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-indigo-500/50"
          >
            <span className={`h-2 w-2 shrink-0 rounded-full ${n.read ? 'bg-slate-300' : 'bg-indigo-500'}`} />
            <span className="min-w-0 flex-1">
              <span className="block truncate text-sm font-medium text-slate-700">{n.title}</span>
              {n.body && <span className="block truncate text-xs text-slate-400">{n.body}</span>}
            </span>
            <span className="shrink-0 text-xs tabular-nums text-slate-400">{fmtAgo(n.created_at)}</span>
          </Link>
        ))
      )}
    </div>
  );
}

export default memo(RecentActivity);
