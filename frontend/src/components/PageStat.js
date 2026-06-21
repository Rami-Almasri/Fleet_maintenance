// Per-page percentage gauge — the circular indicator (like the Dashboard's
// "Utilization" ring) that floats on every page and shows one headline percent
// for whatever page you're on.
//
//   • PageStatProvider — holds the current page's stat (put once, high in the tree)
//   • usePageStat({ percent, label, color, hint }) — a page calls this to publish
//     its headline percentage; it auto-clears when you navigate away
//   • PageStatGauge — the floating circle itself (rendered once in AppLayout)
//
// Pure SVG + requestAnimationFrame, matching components/ui/Gauge.js — no chart lib.

import { createContext, useContext, useEffect, useState } from 'react';
import { useCountUp } from './ui/Gauge';

const Ctx = createContext(null);

export function PageStatProvider({ children }) {
  const [stat, setStat] = useState(null);
  return <Ctx.Provider value={{ stat, setStat }}>{children}</Ctx.Provider>;
}

// [from, to] gradient stops per palette key + a soft track tint.
const COLORS = {
  indigo:  ['#6366f1', '#8b5cf6', '#eef2ff'],
  emerald: ['#10b981', '#34d399', '#ecfdf5'],
  blue:    ['#3b82f6', '#60a5fa', '#eff6ff'],
  amber:   ['#f59e0b', '#fbbf24', '#fffbeb'],
  red:     ['#ef4444', '#f87171', '#fef2f2'],
  violet:  ['#8b5cf6', '#a78bfa', '#f5f3ff'],
  cyan:    ['#06b6d4', '#22d3ee', '#ecfeff'],
};

/**
 * Publish this page's headline percentage to the floating gauge.
 * Pass `percent: null` (or nothing) while loading to keep the gauge hidden.
 *
 * @param percent  0–100 (clamped). null/undefined → gauge hidden on this page
 * @param label    short caption under the number (e.g. "Available")
 * @param color    palette key: indigo | emerald | blue | amber | red | violet | cyan
 * @param hint     longer tooltip explaining what the percent means
 */
export function usePageStat({ percent, label, color = 'indigo', hint } = {}) {
  const ctx = useContext(Ctx);
  const set = ctx?.setStat;
  const ready = percent != null && Number.isFinite(Number(percent));
  const value = ready ? Math.max(0, Math.min(100, Number(percent))) : null;
  useEffect(() => {
    if (!set) return undefined;
    set(value == null ? null : { percent: value, label, color, hint });
    return () => set(null);
  }, [set, value, label, color, hint]);
}

let UID = 0;

// The circular progress ring with the percent in the middle.
function Ring({ percent, color, size = 88, stroke = 9 }) {
  const [gid] = useState(() => `pgs${++UID}`);
  const [from, to, track] = COLORS[color] || COLORS.indigo;
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  const ratio = Math.max(0, Math.min(1, (percent || 0) / 100));

  const [shown, setShown] = useState(0);
  useEffect(() => {
    const id = requestAnimationFrame(() => setShown(ratio));
    return () => cancelAnimationFrame(id);
  }, [ratio]);
  const display = useCountUp(percent || 0);

  return (
    <div className="relative inline-flex items-center justify-center" style={{ width: size, height: size }}>
      <svg width={size} height={size} className="-rotate-90">
        <defs>
          <linearGradient id={gid} x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stopColor={from} />
            <stop offset="100%" stopColor={to} />
          </linearGradient>
        </defs>
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke={track} strokeWidth={stroke} />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          stroke={`url(#${gid})`}
          strokeWidth={stroke}
          strokeLinecap="round"
          strokeDasharray={c}
          strokeDashoffset={c * (1 - shown)}
          style={{ transition: 'stroke-dashoffset 1.1s cubic-bezier(0.22,1,0.36,1)' }}
        />
      </svg>
      <span className="absolute text-lg font-bold tabular-nums text-slate-900">{Math.round(display)}%</span>
    </div>
  );
}

// The floating gauge, rendered once in AppLayout. Hidden when no page publishes
// a stat. Can be collapsed to a small pill and re-opened.
export function PageStatGauge() {
  const ctx = useContext(Ctx);
  const stat = ctx?.stat;
  const [collapsed, setCollapsed] = useState(false);
  if (!stat) return null;

  if (collapsed) {
    return (
      <button
        onClick={() => setCollapsed(false)}
        title={stat.hint || stat.label}
        aria-label="Show page metric"
        className="fixed bottom-6 right-6 z-40 flex h-12 w-12 items-center justify-center rounded-full bg-white text-sm font-bold text-slate-900 shadow-2xl ring-1 ring-slate-200 transition hover:ring-indigo-300 active:scale-90"
      >
        {Math.round(stat.percent)}%
      </button>
    );
  }

  return (
    <div
      className="fixed bottom-6 right-6 z-40 flex w-32 flex-col items-center rounded-2xl border border-slate-200 bg-white/95 px-3 pb-2.5 pt-3 shadow-2xl ring-1 ring-black/5 backdrop-blur animate-fade-in-up"
      title={stat.hint}
    >
      <button
        onClick={() => setCollapsed(true)}
        title="Minimize"
        aria-label="Minimize page metric"
        className="absolute right-1.5 top-1.5 flex h-5 w-5 items-center justify-center rounded-full text-slate-300 transition hover:bg-slate-100 hover:text-slate-500"
      >
        <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round"><path d="M5 12h14" /></svg>
      </button>
      <Ring percent={stat.percent} color={stat.color} />
      <span className="mt-1.5 max-w-full truncate text-center text-[11px] font-semibold text-slate-600">
        {stat.label}
      </span>
    </div>
  );
}
