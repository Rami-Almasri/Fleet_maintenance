import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';

// ⌘K / Ctrl+K quick navigator + action runner.
//   • type to fuzzily filter pages and quick actions
//   • when empty, shows Quick Actions and your Recent pages first
//   • ↑/↓ to move, ↵ to run, Esc to close
export default function CommandPalette({ open, onClose, items, actions = [], recents = [] }) {
  const navigate = useNavigate();
  const [q, setQ] = useState('');
  const [active, setActive] = useState(0);
  const inputRef = useRef(null);
  const listRef = useRef(null);

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
      const sectionGroups = Object.entries(bySection).map(([title, rows]) => ({ title, rows }));

      const g = [];
      if (actionRows.length) g.push({ title: 'Quick actions', rows: actionRows });
      if (recentRows.length) g.push({ title: 'Recent', rows: recentRows });
      g.push(...sectionGroups);
      return { groups: g, flat: g.flatMap((x) => x.rows) };
    }

    const matchPage = (p) => p.name.toLowerCase().includes(s) || (p.section || '').toLowerCase().includes(s);
    const matchAction = (a) =>
      a.label.toLowerCase().includes(s) || (a.keywords || '').toLowerCase().includes(s);
    const ap = actionRows.filter(matchAction);
    const pp = pageRows.filter(matchPage);
    const g = [];
    if (ap.length) g.push({ title: 'Actions', rows: ap });
    if (pp.length) g.push({ title: 'Pages', rows: pp });
    return { groups: g, flat: g.flatMap((x) => x.rows) };
  }, [q, items, actions, recents]);

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
            placeholder="Search pages or run an action…"
            className="w-full bg-transparent py-3.5 text-sm text-slate-800 outline-none placeholder:text-slate-400"
          />
          <kbd className="rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-[10px] font-semibold text-slate-400">ESC</kbd>
        </div>

        <ul ref={listRef} className="max-h-[24rem] overflow-y-auto p-2">
          {flat.length === 0 && (
            <li className="px-3 py-10 text-center text-sm text-slate-400">No matches for “{q}”.</li>
          )}
          {groups.map((group) => (
            <li key={group.title} className="mb-1">
              <p className="px-3 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-300">
                {group.title}
              </p>
              <ul>
                {group.rows.map((row) => {
                  idx += 1;
                  const i = idx;
                  const isAction = row.kind === 'action';
                  return (
                    <li key={(isAction ? 'a:' + row.id : 'p:' + row.to)} data-i={i}>
                      <button
                        onMouseEnter={() => setActive(i)}
                        onClick={() => go(row)}
                        className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm transition ${
                          i === active ? 'bg-indigo-50 text-indigo-700' : 'text-slate-700 hover:bg-slate-50'
                        }`}
                      >
                        <svg className={`h-[18px] w-[18px] shrink-0 ${i === active ? 'text-indigo-500' : 'text-slate-400'}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"><path d={row.icon} /></svg>
                        <span className="flex-1 font-medium">{isAction ? row.label : row.name}</span>
                        {isAction
                          ? <span className="text-[10px] font-semibold uppercase tracking-wide text-violet-300">Action</span>
                          : <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-300">{row.section}</span>}
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
