// KPI tile — the standard way to surface a single headline number across the app.
// Replaces the ad-hoc "label + big number" blocks each page used to hand-roll.
//
//   <MetricCard label="Fleet Net Profit" value={aed2(x)} icon={<Icon.Cash/>}
//               tone="emerald" hint="Cash basis" tooltip="Collected − cost"
//               delta="+4.2%" trend="up" />
//
// Wrap a row of them in <MetricGrid> for responsive stacking on mobile.

import { Link } from 'react-router-dom';
import { InfoTip } from './Tooltip';
import { MetricCardSkeleton } from './Skeleton';

// Semantic tones — calm, professional palette aligned with Badge/Gauge keys.
const TONES = {
  slate:   { icon: 'bg-slate-100 text-slate-600',     value: 'text-slate-900' },
  indigo:  { icon: 'bg-indigo-100 text-indigo-600',   value: 'text-slate-900' },
  emerald: { icon: 'bg-emerald-100 text-emerald-600', value: 'text-emerald-700' },
  green:   { icon: 'bg-emerald-100 text-emerald-600', value: 'text-emerald-700' },
  amber:   { icon: 'bg-amber-100 text-amber-600',     value: 'text-amber-700' },
  red:     { icon: 'bg-red-100 text-red-600',         value: 'text-red-700' },
  blue:    { icon: 'bg-blue-100 text-blue-600',       value: 'text-slate-900' },
  violet:  { icon: 'bg-violet-100 text-violet-600',   value: 'text-slate-900' },
  cyan:    { icon: 'bg-cyan-100 text-cyan-600',       value: 'text-slate-900' },
};

const DELTA_TONE = {
  up:   'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  down: 'bg-red-50 text-red-600 ring-red-600/20',
  flat: 'bg-slate-100 text-slate-500 ring-slate-500/20',
};

const ARROW = { up: 'M5 15l7-7 7 7', down: 'M19 9l-7 7-7-7', flat: 'M5 12h14' };

export default function MetricCard({
  label,
  value,
  icon,
  tone = 'slate',
  hint,
  tooltip,            // explanation shown via an (i) marker next to the label
  delta,              // e.g. "+4.2%" — a small trend pill
  trend = 'up',       // 'up' | 'down' | 'flat' — colour + arrow for `delta`
  big = false,        // larger value type for the hero metric
  loading = false,
  to,                 // makes the whole card a router link
  onClick,
  className = '',
}) {
  if (loading) return <MetricCardSkeleton />;

  const t = TONES[tone] || TONES.slate;
  const clickable = !!(to || onClick);

  const inner = (
    <>
      <div className="flex items-start justify-between gap-3">
        <div className="flex min-w-0 items-center gap-1.5">
          <p className="truncate text-xs font-medium text-slate-500">{label}</p>
          {tooltip && <InfoTip content={tooltip} />}
        </div>
        {icon && (
          <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ${t.icon}`}>
            {icon}
          </span>
        )}
      </div>

      <div className="mt-3 flex items-end gap-2">
        <p className={`font-display font-bold leading-none tracking-tight tabular-nums ${big ? 'text-3xl' : 'text-2xl'} ${t.value}`}>
          {value}
        </p>
        {delta != null && delta !== '' && (
          <span className={`mb-0.5 inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${DELTA_TONE[trend] || DELTA_TONE.flat}`}>
            <svg className="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
              <path d={ARROW[trend] || ARROW.flat} />
            </svg>
            {delta}
          </span>
        )}
      </div>

      {hint && <p className="mt-1.5 text-xs text-slate-400">{hint}</p>}
    </>
  );

  const cls = `block rounded-2xl border border-slate-200/60 bg-white p-5 shadow-soft ${clickable ? 'hover-lift cursor-pointer' : ''} ${className}`;

  if (to) return <Link to={to} className={cls}>{inner}</Link>;
  if (onClick) return <button type="button" onClick={onClick} className={`${cls} w-full text-left`}>{inner}</button>;
  return <div className={cls}>{inner}</div>;
}

// Responsive container: 1 column on mobile, then 2, then `cols` on large screens.
// Children animate in with the shared `.stagger` motion.
export function MetricGrid({ cols = 4, children, className = '' }) {
  const lg = { 2: 'lg:grid-cols-2', 3: 'lg:grid-cols-3', 4: 'lg:grid-cols-4', 5: 'lg:grid-cols-5', 6: 'lg:grid-cols-6' }[cols] || 'lg:grid-cols-4';
  return (
    <div className={`stagger grid grid-cols-1 gap-4 sm:grid-cols-2 ${lg} ${className}`}>
      {children}
    </div>
  );
}
