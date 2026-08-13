import { severityTheme, iconPath, timeAgo, actionLabel, metaChips } from '../lib/notifications';
import Button from './ui/Button';
import { useI18n } from '../i18n/I18nContext';

// Severity → Button variant, so the CTA carries the card's urgency colour while
// still rendering through the shared Button primitive.
const SEVERITY_BUTTON_VARIANT = {
  critical: 'danger',
  warning: 'warning',
  info: 'primary',
  success: 'success',
};

// A single, self-contained alert card for the notifications "to-do board".
//
// Colour-coded by URGENCY (severity): a left accent rail + icon tile + action
// button all inherit the severity theme, so Critical (red) > Warning (orange) >
// Info (blue) reads at a glance. Each card carries a direct, outcome-oriented
// action button (View Contract / Renew Insurance / Open Ticket …) plus a dismiss.
export default function NotificationCard({ n, onAction, onMarkRead, onDismiss }) {
  const { t } = useI18n();
  const theme = severityTheme(n.severity);
  const chips = metaChips(n);
  // A resolved card is history, not a to-do: the condition cleared on its own (car sold, papers
  // renewed). It keeps its text so the record stays readable, but loses the urgency colour and
  // the action button — asking someone to renew the insurance on a car we no longer own is the
  // exact noise this suppresses.
  const settled = !!n.resolved;
  const label = settled ? null : actionLabel(n);

  return (
    <article
      className={[
        'group relative flex flex-col overflow-hidden rounded-2xl border border-slate-200/60',
        'border-s-4 shadow-soft hover-lift',
        settled ? 'border-s-slate-200' : theme.border,
        settled ? 'bg-white opacity-70' : n.read ? 'bg-white' : theme.cardTint,
      ].join(' ')}
    >
      {/* header: icon · severity chip · time */}
      <div className={`flex items-start gap-3 px-4 pt-4 ${n.read || settled ? '' : theme.headTint}`}>
        <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-white shadow-sm ${settled ? 'bg-slate-300' : theme.iconBg}`}>
          <svg aria-hidden="true" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
            <path d={iconPath(n.icon)} />
          </svg>
        </div>

        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <span className={[
              'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide',
              settled ? 'bg-slate-100 text-slate-500' : `${theme.chipBg} ${theme.chipText}`,
            ].join(' ')}>
              {settled ? t('No longer applies') : t(theme.label)}
            </span>
            {!n.read && !settled && <span className={`h-2 w-2 rounded-full ${theme.dot}`} aria-label={t('unread')} />}
            <span className="ms-auto whitespace-nowrap text-[11px] font-medium text-slate-400">{timeAgo(n.created_at)}</span>
          </div>
          <h3 className={`mt-1.5 text-sm leading-snug ${settled ? 'font-semibold text-slate-500' : n.read ? 'font-semibold text-slate-700' : 'font-bold text-slate-900'}`}>
            {n.title}
          </h3>
        </div>

        {/* dismiss */}
        {onDismiss && (
          <button
            type="button"
            onClick={() => onDismiss(n.id)}
            className="-me-1 -mt-1 rounded-lg p-1 text-slate-300 opacity-0 transition-colors duration-150 hover:bg-slate-100 hover:text-slate-500 focus-visible:opacity-100 group-hover:opacity-100"
            title={t('Dismiss')}
            aria-label={t('Dismiss notification')}
          >
            <svg aria-hidden="true" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
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
                'inline-flex items-center rounded-lg px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset',
                c.plate ? 'bg-slate-50 font-mono tabular-nums text-slate-600 ring-slate-200'
                  : c.tone === 'strong' ? `${theme.chipBg} ${theme.chipText} ring-transparent`
                  : 'bg-slate-50 text-slate-600 ring-slate-200',
              ].join(' ')}
            >
              {c.text}
            </span>
          ))}
        </div>
      )}

      {/* footer actions — a settled card has none, so it collapses to just its text */}
      <div className={`mt-auto flex items-center gap-2 px-4 ${label || (onMarkRead && !n.read) ? 'pb-4 pt-4' : 'pb-4'}`}>
        {label && (
          <button
            type="button"
            onClick={() => onAction?.(n)}
            className={`inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-semibold transition-all duration-150 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 active:scale-[0.97] ${theme.btn}`}
          >
            {t(label)}
            <svg className="h-3.5 w-3.5 rtl:-scale-x-100" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
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
            {t('Mark read')}
          </button>
        )}
      </div>
    </article>
  );
}
