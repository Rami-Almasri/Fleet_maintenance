import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api/client';
import { useI18n } from '../i18n/I18nContext';

// ⌘K / Ctrl+K quick navigator + action runner.
//   • type to fuzzily filter pages and quick actions
//   • type a PLATE to find the car itself — see "plate lookup" below
//   • when empty, shows Quick Actions and your Recent pages first
//   • ↑/↓ to move, ↵ to run, Esc to close

// Icon paths for the plate-lookup rows (same 24×24 stroked family as the nav icons).
const ICON_CAR = 'M3 13l1.6-4.8A2 2 0 0 1 6.5 7h11a2 2 0 0 1 1.9 1.2L21 13v5h-2v-2H5v2H3v-5zM7 16h.01M17 16h.01';
const ICON_WRENCH = 'M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.8-3.8a6 6 0 0 1-7.9 7.9l-6.9 6.9a2.1 2.1 0 0 1-3-3l6.9-6.9a6 6 0 0 1 7.9-7.9l-3.8 3.8z';
const ICON_GATE = 'M9 11l3 3 8-8M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11';

// A plate as the app matches plates: digits only, leading zeros dropped — so "K 19397", "19397"
// and "0019397" are one plate. Mirrors PlateResolver::plateDigits() on the server.
const plateDigits = (s) => String(s || '').replace(/\D/g, '').replace(/^0+/, '');

export default function CommandPalette({ open, onClose, items, actions = [], recents = [] }) {
  const navigate = useNavigate();
  const { t, tf } = useI18n();
  const [q, setQ] = useState('');
  const [active, setActive] = useState(0);
  const inputRef = useRef(null);
  const listRef = useRef(null);

  // PLATE LOOKUP — typing a plate asks the server where that car actually is, and each answer is a
  // deep link to the exact card (a ticket's own page / the review queue scrolled to that request),
  // not just to the page it lives on. Three digits is the floor: fewer matches half the fleet, which
  // is a filter, not an answer.
  const digits = plateDigits(q);
  const isPlateQuery = digits.length >= 3;
  const [plate, setPlate] = useState({ digits: '', loading: false, matches: [] });
  const plateSeq = useRef(0);

  useEffect(() => {
    if (!open || !isPlateQuery) {
      setPlate({ digits: '', loading: false, matches: [] });
      return undefined;
    }
    const seq = ++plateSeq.current;
    setPlate((p) => ({ ...p, loading: true }));
    // Debounced: this runs per keystroke and the review half of the answer is a real query.
    const timer = setTimeout(() => {
      api.get('/maintenance-tickets/plate-locator', { params: { plate: digits } })
        .then((r) => {
          if (seq !== plateSeq.current) return; // a later keystroke already owns the answer
          setPlate({ digits, loading: false, matches: r.data?.data?.matches || [] });
        })
        .catch(() => {
          if (seq !== plateSeq.current) return;
          setPlate({ digits, loading: false, matches: [] });
        });
    }, 300);
    return () => clearTimeout(timer);
  }, [open, isPlateQuery, digits]);

  // The plate answers, flattened into palette rows: one row per place the car is, plus a
  // "not in the shop" row that still offers its profile — because "nowhere" is also an answer.
  const plateRows = useMemo(() => {
    if (!isPlateQuery || plate.digits !== digits) return [];
    return plate.matches.flatMap((m) => {
      const v = m.vehicle;
      const car = [v.make, v.model].filter(Boolean).join(' ');
      if (!m.hits.length) {
        return [{
          kind: 'plate',
          id: `v:${v.id}`,
          to: v.to,
          icon: ICON_CAR,
          title: v.plate_no,
          subtitle: [car, tf('palette.plate.nowhere', 'Not in the shop — no open ticket or pending request')]
            .filter(Boolean).join(' · '),
          tag: tf('palette.plate.profile', 'Vehicle'),
        }];
      }
      return m.hits.map((h) => ({
        kind: 'plate',
        id: `h:${v.id}:${h.page}:${h.ticket_id}`,
        to: h.to,
        icon: h.page === 'inspection-review' ? ICON_GATE : ICON_WRENCH,
        title: `${v.plate_no} — ${tf(`palette.plate.status.${h.status}`, h.status_label)}`,
        subtitle: [car, h.garage, `#${h.ticket_id}`].filter(Boolean).join(' · '),
        tag: h.page === 'inspection-review'
          ? tf('palette.plate.reviewPage', 'Inspection Review')
          : tf('palette.plate.workflowPage', 'Maintenance Cycle'),
      }));
    });
  }, [isPlateQuery, plate, digits, tf]);

  // Build the grouped result set. Each group has a title + rows; we also keep a
  // flat list of rows so arrow-key navigation can run across group boundaries.
  const { groups, flat } = useMemo(() => {
    const s = q.trim().toLowerCase();
    const pageRows = items.map((i) => ({ kind: 'page', ...i }));
    const actionRows = actions.map((a) => ({ kind: 'action', ...a }));

    if (!s) {
      const recentRows = recents
        .map((to) => pageRows.find((p) => p.to === to))
        .filter(Boolean)
        .slice(0, 4);

      // Group remaining pages by their nav section.
      const bySection = {};
      pageRows.forEach((p) => {
        (bySection[p.section] = bySection[p.section] || []).push(p);
      });
      // `key` doubles as the React key AND the i18n lookup; a nav section has no palette.group.* entry,
      // so it carries its own already-translated `title` and skips that lookup (this is what used to
      // render the literal "PALETTE.GROUP.UNDEFINED" heading under the Recent block).
      const sectionGroups = Object.entries(bySection).map(([title, rows]) => ({ key: title, title, rows }));

      const g = [];
      if (actionRows.length) g.push({ key: 'quickActions', rows: actionRows });
      if (recentRows.length) g.push({ key: 'recent', rows: recentRows });
      g.push(...sectionGroups);
      return { groups: g, flat: g.flatMap((x) => x.rows) };
    }

    const matchPage = (p) => p.name.toLowerCase().includes(s) || (p.section || '').toLowerCase().includes(s);
    const matchAction = (a) =>
      a.label.toLowerCase().includes(s) || (a.keywords || '').toLowerCase().includes(s);
    const ap = actionRows.filter(matchAction);
    const pp = pageRows.filter(matchPage);
    const g = [];
    // Cars first when the query is a plate: someone typing digits is asking about a car, and the
    // page list would otherwise push the answer below the fold.
    if (plateRows.length) g.push({ key: 'vehicle', rows: plateRows });
    if (ap.length) g.push({ key: 'actions', rows: ap });
    if (pp.length) g.push({ key: 'pages', rows: pp });
    return { groups: g, flat: g.flatMap((x) => x.rows) };
  }, [q, items, actions, recents, plateRows]);

  useEffect(() => {
    if (open) {
      setQ('');
      setActive(0);
      const t = setTimeout(() => inputRef.current?.focus(), 30);
      return () => clearTimeout(t);
    }
  }, [open]);

  useEffect(() => setActive(0), [q]);

  // keep the highlighted row in view
  useEffect(() => {
    const el = listRef.current?.querySelector(`[data-i="${active}"]`);
    el?.scrollIntoView({ block: 'nearest' });
  }, [active]);

  if (!open) return null;

  const go = (row) => {
    if (!row) return;
    onClose();
    if (row.kind === 'action') row.run?.();
    else navigate(row.to);
  };

  const onKey = (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => Math.min(a + 1, flat.length - 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(a - 1, 0)); }
    else if (e.key === 'Enter') { e.preventDefault(); go(flat[active]); }
    else if (e.key === 'Escape') { e.preventDefault(); onClose(); }
  };

  let idx = -1; // running index across all groups, matched to `flat`

  return (
    <div
      className="fixed inset-0 z-[60] flex items-start justify-center bg-slate-900/40 px-4 pt-[12vh] backdrop-blur-sm"
      onClick={onClose}
    >
      <div
        className="w-full max-w-xl animate-fade-in-up overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-slate-900/10"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center gap-3 border-b border-slate-100 px-4">
          <svg className="h-5 w-5 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8"><path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-4.3-4.3M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16z" /></svg>
          <input
            ref={inputRef}
            value={q}
            onChange={(e) => setQ(e.target.value)}
            onKeyDown={onKey}
            placeholder={t('palette.placeholder')}
            className="w-full bg-transparent py-3.5 text-sm text-slate-800 outline-none placeholder:text-slate-400"
          />
          <kbd className="rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-[10px] font-semibold text-slate-400">ESC</kbd>
        </div>

        <ul ref={listRef} className="max-h-[24rem] overflow-y-auto p-2">
          {/* A plate lookup in flight must never read as "no such car" — that is a different answer. */}
          {isPlateQuery && plate.loading && plateRows.length === 0 && (
            <li className="px-3 py-6 text-center text-sm text-slate-400">
              {tf('palette.plate.searching', 'Looking for plate {q}…', { q: digits })}
            </li>
          )}
          {flat.length === 0 && !(isPlateQuery && plate.loading) && (
            <li className="px-3 py-10 text-center text-sm text-slate-400">
              {isPlateQuery
                ? tf('palette.plate.noCar', 'No car in the fleet carries plate {q}.', { q: digits })
                : t('palette.noMatches', { q })}
            </li>
          )}
          {groups.map((group) => (
            <li key={group.key} className="mb-1">
              <p className="px-3 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-300">
                {group.title || t(`palette.group.${group.key}`)}
              </p>
              <ul>
                {group.rows.map((row) => {
                  idx += 1;
                  const i = idx;
                  const isAction = row.kind === 'action';
                  const isPlate = row.kind === 'plate';
                  return (
                    <li key={(isAction ? 'a:' + row.id : isPlate ? 'v:' + row.id : 'p:' + row.to)} data-i={i}>
                      <button
                        onMouseEnter={() => setActive(i)}
                        onClick={() => go(row)}
                        className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-start text-sm transition ${
                          i === active ? 'bg-indigo-50 text-indigo-700' : 'text-slate-700 hover:bg-slate-50'
                        }`}
                      >
                        <svg className={`h-[18px] w-[18px] shrink-0 ${i === active ? 'text-indigo-500' : 'text-slate-400'}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"><path d={row.icon} /></svg>
                        {isPlate ? (
                          <span className="min-w-0 flex-1">
                            <span className="block truncate font-medium">{row.title}</span>
                            {row.subtitle && (
                              <span className={`block truncate text-[11px] ${i === active ? 'text-indigo-400' : 'text-slate-400'}`}>{row.subtitle}</span>
                            )}
                          </span>
                        ) : (
                          <span className="flex-1 font-medium">{isAction ? row.label : row.name}</span>
                        )}
                        {isAction && <span className="text-[10px] font-semibold uppercase tracking-wide text-violet-300">{t('palette.action')}</span>}
                        {isPlate && <span className="shrink-0 text-[10px] font-semibold uppercase tracking-wide text-slate-300">{row.tag}</span>}
                        {!isAction && !isPlate && <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-300">{row.section}</span>}
                        {i === active && (
                          <kbd className="rounded border border-indigo-200 bg-white px-1 text-[10px] font-semibold text-indigo-400">↵</kbd>
                        )}
                      </button>
                    </li>
                  );
                })}
              </ul>
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}
