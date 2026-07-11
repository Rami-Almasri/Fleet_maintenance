import { severityTheme, iconPath, timeAgo, actionLabel, metaChips } from '../lib/notifications';

// A single, self-contained alert card for the notifications "to-do board".
//
// Colour-coded by URGENCY (severity): a left accent rail + icon tile + action
// button all inherit the severity theme, so Critical (red) > Warning (orange) >
// Info (blue) reads at a glance. Each card carries a direct, outcome-oriented
// action button (View Contract / Renew Insurance / Open Ticket …) plus a dismiss.
export default function NotificationCard({ n, onAction, onMarkRead, onDismiss }) {
  const theme = severityTheme(n.severity);
  const chips = metaChips(n);
  const label = actionLabel(n);

  return (
    <article
      className={[
        'group relative flex flex-col overflow-hidden rounded-2xl border border-slate-200/70',
        'border-l-4 shadow-soft transition-all duration-150 hover:-translate-y-0.5 hover:shadow-lg',
        theme.border,
        n.read ? 'bg-white' : theme.cardTint,
      ].join(' ')}
    >
      {/* header: icon · severity chip · time */}
      <div className={`flex items-start gap-3 px-4 pt-4 ${n.read ? '' : theme.headTint}`}>
        <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-white shadow-sm ${theme.iconBg}`}>
          <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
            <path d={iconPath(n.icon)} />
          </svg>
        </div>

        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ${theme.chipBg} ${theme.chipText}`}>
              {theme.label}
            </span>
            {!n.read && <span className={`h-2 w-2 rounded-full ${theme.dot}`} aria-label="unread" />}
            <span className="ml-auto whitespace-nowrap text-[11px] font-medium text-slate-400">{timeAgo(n.created_at)}</span>
          </div>
          <h3 className={`mt-1.5 text-sm leading-snug ${n.read ? 'font-semibold text-slate-700' : 'font-bold text-slate-900'}`}>
            {n.title}
          </h3>
        </div>

        {/* dismiss */}
        {onDismiss && (
          <button
            type="button"
            onClick={() => onDismiss(n.id)}
            className="-mr-1 -mt-1 rounded-lg p-1 text-slate-300 opacity-0 transition hover:bg-slate-100 hover:text-slate-500 focus:opacity-100 group-hover:opacity-100"
            title="Dismiss"
            aria-label="Dismiss notification"
          >
            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        )}
      </div>

      {/* body */}
      <p className="px-4 pt-2 text-[13px] leading-relaxed text-slate-500">{n.body}</p>

      {/* meta chips */}
      {chips.length > 0 && (
        <div className="flex flex-wrap items-center gap-1.5 px-4 pt-3">
          {chips.map((c, i) => (
            <span
              key={i}
              className={[
                'inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold',
                c.plate ? 'bg-slate-100 font-mono text-slate-600'
                  : c.tone === 'strong' ? `${theme.chipBg} ${theme.chipText}`
                  : 'bg-slate-100 text-slate-600',
              ].join(' ')}
            >
              {c.text}
            </span>
          ))}
        </div>
      )}

      {/* footer actions */}
      <div className="mt-auto flex items-center gap-2 px-4 pb-4 pt-4">
        {label && (
          <button
            type="button"
            onClick={() => onAction?.(n)}
            className={`inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-semibold transition-all duration-150 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 active:scale-[0.97] ${theme.btn}`}
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
            className="rounded-xl px-3 py-2 text-xs font-medium text-slate-500 transition hover:bg-slate-100 hover:text-slate-700"
          >
            Mark read
          </button>
        )}
      </div>
    </article>
  );
}
