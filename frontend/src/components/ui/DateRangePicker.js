// DateRangePicker — a self-contained, premium window picker for time-scoped lists.
// One trigger button opens a popover with quick presets (left) and a live two-month
// calendar (right) that supports click-to-pick range selection with hover preview.
//
// Controlled by three values that mirror the API's window params:
//   days  — trailing-window preset in days (0 = all time). Active when from/to are empty.
//   from  — explicit range start 'YYYY-MM-DD' (overrides days when set)
//   to    — explicit range end   'YYYY-MM-DD'
// Emits the full next state via onChange({ days, from, to }).

import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import Icon from './Icon';
import { useI18n } from '../../i18n/I18nContext';
import { activeLang } from '../../i18n/translate';

// Arabic must render Gregorian dates with Latin digits — the default 'ar' locale resolves to the
// Hijri calendar and Arabic-Indic numerals, neither of which belongs in a fleet's date window.
const calLocale = (lang) => (lang === 'ar' ? 'ar-AE-u-ca-gregory-nu-latn' : undefined);

// ── date helpers (no external lib; all local-time, date-only) ───────────────────
const pad = (n) => String(n).padStart(2, '0');
const toISO = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const parseISO = (s) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
const startOfMonth = (d) => new Date(d.getFullYear(), d.getMonth(), 1);
const addMonths = (d, n) => new Date(d.getFullYear(), d.getMonth() + n, 1);
const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
const isoOf = (d) => (d ? toISO(d) : null);
const fmtShort = (d) => d.toLocaleDateString(calLocale(activeLang()), { day: 'numeric', month: 'short', year: 'numeric' });
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
// Weekday initials come from Intl rather than a hardcoded list: English still reads S M T W T F S,
// and Arabic gets its own letters instead of seven ambiguous Latin ones. 2024-09-01 was a Sunday.
const dowInitials = (lang) => {
  const loc = calLocale(lang);
  return Array.from({ length: 7 }, (_, i) =>
    new Date(2024, 8, 1 + i).toLocaleDateString(loc, { weekday: 'narrow' }));
};

// English source text for the trailing-window presets; resolved through the catalog at render.
const TRAILING = [
  { days: 0, label: 'All time' },
  { days: 30, label: 'Last 30 days' },
  { days: 90, label: 'Last 90 days' },
  { days: 180, label: 'Last 6 months' },
  { days: 365, label: 'Last year' },
];

export default function DateRangePicker({ days, from, to, onChange }) {
  const { t, lang } = useI18n();
  const [open, setOpen] = useState(false);
  const rootRef = useRef(null);
  const panelRef = useRef(null);
  // The panel is anchored to the trigger's end edge, which puts it off-screen whenever the trigger sits
  // near the start of a narrow container (a picker in a half-width card header). Measured once per open
  // and nudged back inside the viewport, so no preset or date is ever cut off.
  const [shift, setShift] = useState(0);

  const usingRange = Boolean(from || to);
  const fromD = from ? parseISO(from) : null;
  const toD = to ? parseISO(to) : null;

  // Anchor "today" once per open so all presets compute against a stable day.
  const today = useMemo(() => { const t = new Date(); return new Date(t.getFullYear(), t.getMonth(), t.getDate()); }, []);

  // Calendar shows two months; `view` is the left month. Anchor on the current selection.
  const [view, setView] = useState(() => startOfMonth(fromD || toD || today));
  // Draft selection while picking; committed to onChange when both ends are chosen.
  const [draftFrom, setDraftFrom] = useState(fromD);
  const [draftTo, setDraftTo] = useState(toD);
  const [hover, setHover] = useState(null);

  // Re-sync internal draft/view whenever the popover opens or external value changes.
  useEffect(() => {
    if (open) {
      setDraftFrom(fromD);
      setDraftTo(toD);
      setView(startOfMonth(fromD || toD || today));
      setHover(null);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, from, to]);

  // Keep the open panel inside the viewport. Runs before paint so it never renders in the wrong place;
  // measured with the shift reset to 0 (the effect only re-runs on open) so it can't drift on reopen.
  useLayoutEffect(() => {
    if (!open) { setShift(0); return; }
    const el = panelRef.current;
    if (!el) return;
    const pad = 8;
    const r = el.getBoundingClientRect();
    if (r.left < pad) setShift(pad - r.left);
    else if (r.right > window.innerWidth - pad) setShift(window.innerWidth - pad - r.right);
    else setShift(0);
  }, [open]);

  // Close on outside click / Escape.
  useEffect(() => {
    if (!open) return undefined;
    const onDown = (e) => { if (rootRef.current && !rootRef.current.contains(e.target)) setOpen(false); };
    const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => { document.removeEventListener('mousedown', onDown); document.removeEventListener('keydown', onKey); };
  }, [open]);

  const activeTrailing = !usingRange ? TRAILING.find((w) => w.days === days) : null;

  const label = usingRange
    ? (fromD && toD
      ? `${fmtShort(fromD)} – ${fmtShort(toD)}`
      : fromD ? t('From {date}', { date: fmtShort(fromD) }) : t('Until {date}', { date: fmtShort(toD) }))
    : (activeTrailing ? t(activeTrailing.label) : t('Custom'));

  const applyTrailing = (d) => { onChange({ days: d, from: '', to: '' }); setOpen(false); };
  const applyRange = (a, b) => { onChange({ days: 0, from: isoOf(a) || '', to: isoOf(b) || '' }); };

  // Range presets (computed against today).
  const rangePresets = [
    { key: 'wtd', label: t('Last 7 days'), range: () => [addDays(today, -6), today] },
    { key: 'mtd', label: t('This month'), range: () => [startOfMonth(today), today] },
    { key: 'lm', label: t('Last month'), range: () => [startOfMonth(addMonths(today, -1)), addDays(startOfMonth(today), -1)] },
    { key: 'ytd', label: t('Year to date'), range: () => [new Date(today.getFullYear(), 0, 1), today] },
  ];

  const pickDay = (d) => {
    // First click (or restart after a full range) → new start.
    if (!draftFrom || (draftFrom && draftTo)) {
      setDraftFrom(d); setDraftTo(null); setHover(null);
      return;
    }
    // Second click before the start → treat as a new start instead.
    if (d < draftFrom) { setDraftFrom(d); setDraftTo(null); return; }
    // Second click on/after start → complete the range and commit.
    setDraftTo(d); setHover(null);
    applyRange(draftFrom, d);
  };

  const clearAll = () => { setDraftFrom(null); setDraftTo(null); setHover(null); onChange({ days: 0, from: '', to: '' }); };

  return (
    <div ref={rootRef} className="relative">
      {/* Trigger */}
      <button
        onClick={() => setOpen((o) => !o)}
        className={`group inline-flex items-center gap-2.5 rounded-xl border px-3.5 py-2.5 text-sm font-medium shadow-soft transition
          ${open ? 'border-indigo-300 ring-2 ring-indigo-100' : 'border-slate-200 hover:border-indigo-200'}
          ${usingRange ? 'bg-gradient-to-r from-indigo-50 to-white text-indigo-700' : 'bg-white text-slate-700'}`}
        aria-haspopup="dialog"
        aria-expanded={open}
      >
        <span className={`grid h-7 w-7 place-items-center rounded-lg ${usingRange ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-500 group-hover:bg-indigo-100 group-hover:text-indigo-600'}`}>
          <Icon.Calendar className="h-4 w-4" />
        </span>
        <span className="flex flex-col items-start leading-tight">
          <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{t('Time window')}</span>
          <span className="tabular-nums">{label}</span>
        </span>
        <Icon.ChevronDown className={`h-4 w-4 text-slate-400 transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>

      {/* Popover */}
      {open && (
        <div
          ref={panelRef}
          role="dialog"
          style={shift ? { transform: `translateX(${shift}px)` } : undefined}
          className="absolute end-0 z-30 mt-2 flex w-[min(92vw,640px)] origin-top-right flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl ring-1 ring-black/5 sm:flex-row"
        >
          {/* Presets */}
          <div className="flex shrink-0 flex-col gap-0.5 border-b border-slate-100 bg-slate-50/70 p-2 sm:w-44 sm:border-b-0 sm:border-e">
            <p className="px-2 pb-1 pt-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400">{t('Quick ranges')}</p>
            {TRAILING.map((w) => (
              <PresetRow key={w.days} active={!usingRange && days === w.days} onClick={() => applyTrailing(w.days)}>{t(w.label)}</PresetRow>
            ))}
            <div className="my-1 h-px bg-slate-200/70" />
            {rangePresets.map((p) => {
              const [a, b] = p.range();
              const active = usingRange && from === isoOf(a) && to === isoOf(b);
              return (
                <PresetRow key={p.key} active={active} onClick={() => { setDraftFrom(a); setDraftTo(b); applyRange(a, b); setView(startOfMonth(a)); }}>
                  {p.label}
                </PresetRow>
              );
            })}
          </div>

          {/* Calendar */}
          <div className="flex-1 p-3.5">
            <div className="mb-2 flex items-center justify-between">
              <button onClick={() => setView((v) => addMonths(v, -1))} className="grid h-8 w-8 place-items-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-700" aria-label={t('Previous month')}>
                <Icon.ChevronDown className="h-4 w-4 rotate-90 rtl:-scale-x-100" />
              </button>
              <div className="flex flex-1 justify-around px-2 text-sm font-semibold text-slate-800">
                <span>{t(MONTHS[view.getMonth()])} {view.getFullYear()}</span>
                <span className="hidden sm:inline">{t(MONTHS[addMonths(view, 1).getMonth()])} {addMonths(view, 1).getFullYear()}</span>
              </div>
              <button onClick={() => setView((v) => addMonths(v, 1))} className="grid h-8 w-8 place-items-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-700" aria-label={t('Next month')}>
                <Icon.ChevronDown className="h-4 w-4 -rotate-90 rtl:-scale-x-100" />
              </button>
            </div>

            <div className="flex gap-6">
              <MonthGrid month={view} today={today} lang={lang} draftFrom={draftFrom} draftTo={draftTo} hover={hover} onPick={pickDay} onHover={setHover} />
              <div className="hidden sm:block">
                <MonthGrid month={addMonths(view, 1)} today={today} lang={lang} draftFrom={draftFrom} draftTo={draftTo} hover={hover} onPick={pickDay} onHover={setHover} />
              </div>
            </div>

            {/* Footer */}
            <div className="mt-3 flex items-center justify-between border-t border-slate-100 pt-3">
              <span className="text-xs text-slate-500">
                {draftFrom ? (
                  <span className="tabular-nums">
                    <span className="font-semibold text-slate-700">{fmtShort(draftFrom)}</span>
                    {draftTo
                      ? <> <span className="inline-block text-slate-400 rtl:-scale-x-100">→</span> <span className="font-semibold text-slate-700">{fmtShort(draftTo)}</span></>
                      : <span className="text-slate-400"> <span className="inline-block rtl:-scale-x-100">→</span> {t('pick an end date')}</span>}
                  </span>
                ) : <span className="text-slate-400">{t('Select a start date')}</span>}
              </span>
              <div className="flex items-center gap-1.5">
                <button onClick={clearAll} className="rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-500 transition hover:bg-slate-100 hover:text-slate-700">{t('Clear')}</button>
                <button onClick={() => setOpen(false)} className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-indigo-700">{t('Done')}</button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function PresetRow({ active, onClick, children }) {
  return (
    <button
      onClick={onClick}
      className={`flex items-center justify-between rounded-lg px-2.5 py-1.5 text-start text-sm transition
        ${active ? 'bg-indigo-600 font-semibold text-white shadow-sm' : 'text-slate-600 hover:bg-white hover:text-indigo-600'}`}
    >
      {children}
      {active && <Icon.Check className="h-3.5 w-3.5" />}
    </button>
  );
}

function MonthGrid({ month, today, lang, draftFrom, draftTo, hover, onPick, onHover }) {
  const dow = useMemo(() => dowInitials(lang), [lang]);
  const first = startOfMonth(month);
  const gridStart = addDays(first, -first.getDay()); // back up to Sunday
  const cells = Array.from({ length: 42 }, (_, i) => addDays(gridStart, i));
  // End used for range shading: committed end, or hovered day while picking.
  const previewEnd = draftTo || (draftFrom && hover && hover > draftFrom ? hover : null);
  const lo = draftFrom;
  const hi = previewEnd;

  return (
    <div className="w-[15.75rem]">
      <div className="mb-1 grid grid-cols-7">
        {dow.map((d, i) => <div key={i} className="grid h-7 place-items-center text-[11px] font-semibold text-slate-400">{d}</div>)}
      </div>
      <div className="grid grid-cols-7 gap-y-1">
        {cells.map((d, i) => {
          const inMonth = d.getMonth() === month.getMonth();
          const isStart = lo && toISO(d) === toISO(lo);
          const isEnd = hi && toISO(d) === toISO(hi);
          const inRange = lo && hi && d > lo && d < hi;
          const isToday = toISO(d) === toISO(today);
          const isEdge = isStart || isEnd;

          // Connected-bar background on the cell wrapper; rounded on the range ends.
          let wrap = 'relative h-9';
          if (inRange || isEdge) wrap += ' bg-indigo-100/70';
          if (isStart || (isEnd && !lo)) wrap += ' rounded-s-full';
          if (isEnd) wrap += ' rounded-e-full';
          if (isStart && (!hi || isEnd)) wrap += ' rounded-e-full'; // single-day selection

          let inner = 'relative z-10 grid h-9 w-9 place-items-center rounded-full text-sm transition';
          if (isEdge) inner += ' bg-indigo-600 font-semibold text-white shadow';
          else if (inRange) inner += ' text-indigo-700';
          else if (!inMonth) inner += ' text-slate-300 hover:bg-slate-100';
          else inner += ' text-slate-700 hover:bg-indigo-50';
          if (isToday && !isEdge) inner += ' ring-1 ring-inset ring-indigo-300 font-semibold';

          return (
            <div key={i} className="flex justify-center">
              <div className={wrap} style={{ width: '2.25rem' }}>
                <button
                  onClick={() => onPick(d)}
                  onMouseEnter={() => onHover(d)}
                  className={inner}
                  tabIndex={inMonth ? 0 : -1}
                >
                  {d.getDate()}
                </button>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
