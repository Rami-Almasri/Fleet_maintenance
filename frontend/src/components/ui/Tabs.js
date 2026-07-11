// Top-level, accessible tab navigation for the design system.
//
// Presentational + CONTROLLED: the parent owns which tab is active (`active` + `onChange`) so
// URL-syncing and deep-link overrides live in one place on the page, not hidden in here. Only the
// active tab's panel should be rendered by the parent — this bar just switches the key.
//
// Accessibility: a proper `role="tablist"` with roving tabindex and ←/→/Home/End keyboard support.
// Overflows into a horizontal scroll on narrow screens instead of wrapping.

import { useRef } from 'react';

export default function Tabs({ tabs, active, onChange, className = '', ariaLabel = 'Sections' }) {
  const btnRefs = useRef([]);

  const focusTab = (i) => {
    const el = btnRefs.current[i];
    if (el) el.focus();
  };

  const onKeyDown = (e) => {
    const idx = tabs.findIndex((t) => t.key === active);
    if (idx < 0) return;
    let next = null;
    if (e.key === 'ArrowRight') next = (idx + 1) % tabs.length;
    else if (e.key === 'ArrowLeft') next = (idx - 1 + tabs.length) % tabs.length;
    else if (e.key === 'Home') next = 0;
    else if (e.key === 'End') next = tabs.length - 1;
    if (next === null) return;
    e.preventDefault();
    onChange(tabs[next].key);
    focusTab(next);
  };

  return (
    <div
      role="tablist"
      aria-label={ariaLabel}
      onKeyDown={onKeyDown}
      className={`flex gap-1 overflow-x-auto border-b border-slate-200 ${className}`}
    >
      {tabs.map((t, i) => {
        const selected = t.key === active;
        return (
          <button
            key={t.key}
            ref={(el) => { btnRefs.current[i] = el; }}
            type="button"
            role="tab"
            id={`tab-${t.key}`}
            aria-selected={selected}
            aria-controls={`panel-${t.key}`}
            tabIndex={selected ? 0 : -1}
            onClick={() => onChange(t.key)}
            className={`group relative -mb-px flex shrink-0 items-center gap-2 whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 focus-visible:ring-offset-1 ${
              selected
                ? 'border-indigo-500 text-indigo-600'
                : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800'
            }`}
          >
            {t.icon && <span className={selected ? 'text-indigo-500' : 'text-slate-400 group-hover:text-slate-500'}>{t.icon}</span>}
            {t.label}
            {t.badge != null && t.badge !== '' && (
              <span className={`ml-0.5 rounded-full px-1.5 py-0.5 text-[11px] font-semibold tabular-nums ${
                selected ? 'bg-indigo-50 text-indigo-600' : 'bg-slate-100 text-slate-500'
              }`}>
                {t.badge}
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
}
