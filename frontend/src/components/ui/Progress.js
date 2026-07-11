// Command-center building blocks — turn "spreadsheet rows" into glanceable,
// real-time visuals. All theme-aware (light Platinum / dark Cockpit) and
// dependency-free.
//
//   • ProgressBar    — a single labelled fill bar (a metric vs. its target)
//   • ProgressCard   — a titled card wrapping a value + ProgressBar
//   • TimelineBar    — a stacked, segmented bar (e.g. rented / shop / idle days)
//   • StatusTimeline — a horizontal stepper for a lifecycle (pending → … → done)

import { useEffect, useState } from 'react';

// Tone → solid fill colour (vivid, reads on both themes).
const TONE = {
  brand:   '#3b82f6',
  blue:    '#3b82f6',
  success: '#10b981',
  emerald: '#10b981',
  green:   '#10b981',
  alert:   '#f97316',
  orange:  '#f97316',
  amber:   '#f59e0b',
  red:     '#ef4444',
  rose:    '#f43f5e',
  cyan:    '#22d3ee',
  violet:  '#06b6d4',
  slate:   '#94a3b8',
};
const color = (t) => TONE[t] || TONE.brand;

/**
 * A single horizontal progress bar that animates its fill on mount.
 * @param value/max  fill ratio; @param tone palette key; @param label/right captions
 */
export function ProgressBar({ value = 0, max = 100, tone = 'brand', label, right, height = 8, showPct = false, className = '' }) {
  const ratio = Math.max(0, Math.min(1, (value || 0) / (max || 1)));
  const [grow, setGrow] = useState(0);
  useEffect(() => {
    const id = requestAnimationFrame(() => setGrow(ratio));
    return () => cancelAnimationFrame(id);
  }, [ratio]);

  return (
    <div className={className}>
      {(label || right || showPct) && (
        <div className="mb-1.5 flex items-center justify-between gap-2 text-xs">
          <span className="font-medium text-slate-500">{label}</span>
          <span className="font-semibold tabular-nums text-slate-700">
            {right != null ? right : showPct ? `${Math.round(ratio * 100)}%` : null}
          </span>
        </div>
      )}
      <div className="overflow-hidden rounded-full bg-slate-100" style={{ height }}>
        <div
          className="h-full rounded-full"
          style={{
            width: `${grow * 100}%`,
            background: `linear-gradient(90deg, ${color(tone)}, ${color(tone)}cc)`,
            boxShadow: `0 0 12px ${color(tone)}66`,
            transition: 'width 1s cubic-bezier(0.22,1,0.36,1)',
          }}
        />
      </div>
    </div>
  );
}

/**
 * A titled card surfacing one number against a target, with a progress bar.
 */
export function ProgressCard({ label, value, sub, tone = 'brand', percent = 0, footer, icon, className = '' }) {
  return (
    <div className={`rounded-2xl border border-slate-200/60 bg-white p-5 shadow-soft ${className}`}>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="truncate text-xs font-medium text-slate-500">{label}</p>
          <p className="mt-1 font-display text-2xl font-bold tracking-tight tabular-nums text-slate-900">{value}</p>
          {sub && <p className="mt-0.5 text-xs text-slate-400">{sub}</p>}
        </div>
        {icon && (
          <span
            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl"
            style={{ background: `${color(tone)}1f`, color: color(tone) }}
          >
            {icon}
          </span>
        )}
      </div>
      <ProgressBar className="mt-4" value={percent} max={100} tone={tone} />
      {footer && <p className="mt-2 text-xs font-medium text-slate-400">{footer}</p>}
    </div>
  );
}

/**
 * A stacked, segmented bar — perfect for a time-split (rented / in shop / idle)
 * or any composition shown inline. @param segments [{ label, value, tone }]
 */
export function TimelineBar({ segments = [], height = 12, showLegend = true, className = '' }) {
  const data = segments.filter((s) => (s.value || 0) > 0);
  const total = data.reduce((a, s) => a + (s.value || 0), 0) || 1;
  const [grow, setGrow] = useState(0);
  useEffect(() => {
    const id = requestAnimationFrame(() => setGrow(1));
    return () => cancelAnimationFrame(id);
  }, [total]);

  return (
    <div className={className}>
      <div className="flex w-full overflow-hidden rounded-full bg-slate-100" style={{ height }}>
        {data.map((s, i) => (
          <div
            key={i}
            title={`${s.label}: ${s.value}`}
            style={{
              width: `${(grow * (s.value / total)) * 100}%`,
              background: color(s.tone),
              transition: 'width 1s cubic-bezier(0.22,1,0.36,1)',
            }}
          />
        ))}
      </div>
      {showLegend && (
        <div className="mt-3 flex flex-wrap gap-x-5 gap-y-2">
          {data.map((s, i) => (
            <div key={i} className="flex items-center gap-2 text-xs">
              <span className="h-2.5 w-2.5 rounded-sm" style={{ background: color(s.tone) }} />
              <span className="font-medium text-slate-500">{s.label}</span>
              <span className="font-semibold tabular-nums text-slate-700">
                {s.value}
                <span className="ml-1 text-slate-400">{Math.round((s.value / total) * 100)}%</span>
              </span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

/**
 * A COMPACT lifecycle timeline for cards — a row of segment bars with a node on
 * each, plus tiny labels. Completed steps = success green, the current step =
 * green (active) or alert orange (SLA breached), upcoming = muted track.
 *
 * @param steps    ['Inspection','Transit','Repair','Ready']
 * @param current  0-based index of the stage the vehicle is in
 * @param breached when true, the current step glows alert-orange (over SLA)
 * @param caption  small line under the bar (e.g. "3d in Repair")
 */
export function LifecycleTimeline({ steps = [], current = 0, breached = false, caption, className = '' }) {
  const DONE = '#10b981';
  const ACTIVE = breached ? '#f97316' : '#10b981';
  const TRACK = 'rgb(var(--line))';
  const [grow, setGrow] = useState(0);
  useEffect(() => {
    const id = requestAnimationFrame(() => setGrow(1));
    return () => cancelAnimationFrame(id);
  }, [current]);

  return (
    <div className={className}>
      <div className="flex items-center gap-1.5">
        {steps.map((s, i) => {
          const done = i < current;
          const active = i === current;
          const fill = done ? DONE : active ? ACTIVE : TRACK;
          return (
            <div key={i} className="flex flex-1 items-center gap-1.5">
              <span className="relative flex h-2.5 w-2.5 shrink-0 items-center justify-center">
                {active && <span className="absolute inline-flex h-full w-full animate-ping rounded-full opacity-60" style={{ background: ACTIVE }} />}
                <span className="relative h-2.5 w-2.5 rounded-full" style={{ background: fill, boxShadow: active ? `0 0 8px ${ACTIVE}` : 'none' }} />
              </span>
              {i < steps.length - 1 && (
                <span className="h-1 flex-1 overflow-hidden rounded-full" style={{ background: TRACK }}>
                  <span className="block h-full rounded-full" style={{ width: done ? `${grow * 100}%` : '0%', background: DONE, transition: 'width .8s cubic-bezier(.22,1,.36,1)' }} />
                </span>
              )}
            </div>
          );
        })}
      </div>
      <div className="mt-1.5 flex justify-between">
        {steps.map((s, i) => (
          <span
            key={i}
            className={`text-[10px] font-semibold ${i === current ? (breached ? 'text-orange-500' : 'text-emerald-500') : i < current ? 'text-slate-500' : 'text-slate-300'}`}
          >
            {s}
          </span>
        ))}
      </div>
      {caption && <p className="mt-1 text-[11px] font-medium text-slate-400">{caption}</p>}
    </div>
  );
}

/**
 * A horizontal lifecycle stepper. @param steps [{ label }] @param current index
 * of the active step (0-based). Past = filled, current = pulsing, future = muted.
 */
export function StatusTimeline({ steps = [], current = 0, tone = 'brand', className = '' }) {
  const c = color(tone);
  return (
    <div className={`flex items-center ${className}`}>
      {steps.map((s, i) => {
        const done = i < current;
        const active = i === current;
        const fill = done || active ? c : 'rgb(var(--line))';
        return (
          <div key={i} className="flex flex-1 items-center last:flex-none">
            <div className="flex flex-col items-center">
              <span
                className="relative flex h-7 w-7 items-center justify-center rounded-full text-[11px] font-bold text-white"
                style={{ background: fill, color: done || active ? '#fff' : 'rgb(var(--ink-3))' }}
              >
                {active && (
                  <span className="absolute inline-flex h-full w-full animate-ping rounded-full opacity-50" style={{ background: c }} />
                )}
                {done ? (
                  <svg className="relative h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round"><path d="M5 12l5 5 9-9" /></svg>
                ) : (
                  <span className="relative">{i + 1}</span>
                )}
              </span>
              <span className={`mt-1.5 whitespace-nowrap text-[11px] font-semibold ${active ? 'text-slate-900' : 'text-slate-400'}`}>{s.label}</span>
            </div>
            {i < steps.length - 1 && (
              <div className="mx-1 mb-5 h-0.5 flex-1 rounded-full" style={{ background: i < current ? c : 'rgb(var(--line))' }} />
            )}
          </div>
        );
      })}
    </div>
  );
}
