import { severityTheme, iconPath, timeAgo, CATEGORY_LABEL } from '../lib/notifications';

// One notification line. Shared by the bell dropdown (compact) and the full page.
// Clicking the body navigates (and marks read); the trailing × dismisses without navigating.
export default function NotificationRow({ n, onOpen, onDismiss, compact = false }) {
  const theme = severityTheme(n.severity);

  return (
    <div
      className={[
        'group relative flex gap-3 transition-colors',
        compact ? 'px-3 py-3' : 'px-4 py-4 sm:px-5',
        n.read ? 'bg-white' : 'bg-indigo-50/40',
        'hover:bg-slate-50',
      ].join(' ')}
    >
      {/* unread accent rail */}
      {!n.read && <span className={`absolute inset-y-0 left-0 w-[3px] ${theme.accent}`} />}

      {/* severity icon tile */}
      <div
        className={`mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-white shadow-sm ${theme.iconBg}`}
      >
        <svg className="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
          <path d={iconPath(n.icon)} />
        </svg>
      </div>

      {/* text — the click target */}
      <button
        type="button"
        onClick={() => onOpen?.(n)}
        className="min-w-0 flex-1 text-left"
      >
        <div className="flex items-start gap-2">
          <p className={`truncate text-sm ${n.read ? 'font-medium text-slate-700' : 'font-semibold text-slate-900'}`}>
            {n.title}
          </p>
          {!n.read && <span className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${theme.dot}`} />}
        </div>
        <p className={`mt-0.5 ${compact ? 'line-clamp-2' : ''} text-[13px] leading-snug text-slate-500`}>{n.body}</p>
        <div className="mt-1.5 flex items-center gap-2">
          <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${theme.chipBg} ${theme.chipText}`}>
            {theme.label}
          </span>
          {!compact && n.category && (
            <span className="text-[11px] font-medium text-slate-400">{CATEGORY_LABEL[n.category] || n.category}</span>
          )}
          <span className="text-[11px] text-slate-400">· {timeAgo(n.created_at)}</span>
        </div>
      </button>

      {/* dismiss */}
      {onDismiss && (
        <button
          type="button"
          onClick={(e) => { e.stopPropagation(); onDismiss(n.id); }}
          className="absolute right-2 top-2 rounded-lg p-1 text-slate-300 opacity-0 transition hover:bg-slate-100 hover:text-slate-500 group-hover:opacity-100"
          title="Dismiss"
          aria-label="Dismiss notification"
        >
          <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      )}
    </div>
  );
}
