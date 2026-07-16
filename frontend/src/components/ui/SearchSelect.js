import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

/**
 * Searchable single-select combobox.
 * options: [{ id, label, sub? }]
 *
 * The dropdown is rendered through a portal to <body> and positioned with
 * `fixed` coords measured from the input, so it never gets clipped by an
 * ancestor with `overflow-hidden` (e.g. our Card wrapper).
 */
export default function SearchSelect({ value, onChange, options = [], placeholder = 'Search…', loading = false }) {
  const [query, setQuery] = useState('');
  const [open, setOpen] = useState(false);
  const [rect, setRect] = useState(null);   // { top, left, width } of the input, in viewport coords
  const ref = useRef(null);
  const inputRef = useRef(null);

  const selected = options.find((o) => String(o.id) === String(value));

  // Measure the input so the portal'd menu can sit flush under it.
  const measure = useCallback(() => {
    if (!inputRef.current) return;
    const r = inputRef.current.getBoundingClientRect();
    setRect({ top: r.bottom, left: r.left, width: r.width });
  }, []);

  useLayoutEffect(() => { if (open) measure(); }, [open, measure]);

  useEffect(() => {
    if (!open) return undefined;
    const onScrollOrResize = () => measure();
    // capture:true so we also catch scrolls on inner scroll containers
    window.addEventListener('scroll', onScrollOrResize, true);
    window.addEventListener('resize', onScrollOrResize);
    return () => {
      window.removeEventListener('scroll', onScrollOrResize, true);
      window.removeEventListener('resize', onScrollOrResize);
    };
  }, [open, measure]);

  useEffect(() => {
    const onDoc = (e) => {
      // ignore clicks inside the input wrapper or the portal'd menu
      if (ref.current && ref.current.contains(e.target)) return;
      if (e.target.closest && e.target.closest('[data-searchselect-menu]')) return;
      setOpen(false);
    };
    document.addEventListener('mousedown', onDoc);
    return () => document.removeEventListener('mousedown', onDoc);
  }, []);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    const base = q
      ? options.filter((o) => o.label.toLowerCase().includes(q) || (o.sub || '').toLowerCase().includes(q))
      : options;
    return base.slice(0, 40);
  }, [query, options]);

  const menu = open && rect ? createPortal(
    <div
      data-searchselect-menu
      style={{ position: 'fixed', top: rect.top + 4, left: rect.left, width: rect.width }}
      className="z-50 max-h-64 overflow-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg"
    >
      {filtered.length === 0 && <div className="px-3 py-2 text-sm text-slate-400">{loading ? 'Loading…' : 'No matches'}</div>}
      {filtered.map((o) => (
        <button
          key={o.id}
          type="button"
          onClick={() => { onChange(o.id); setOpen(false); setQuery(''); }}
          className={`block w-full px-3 py-2 text-left hover:bg-slate-50 ${String(o.id) === String(value) ? 'bg-indigo-50' : ''}`}
        >
          <div className="text-sm font-medium text-slate-900">{o.label}</div>
          {o.sub && <div className="text-xs text-slate-400">{o.sub}</div>}
        </button>
      ))}
    </div>,
    document.body,
  ) : null;

  return (
    <div className="relative" ref={ref}>
      <input
        ref={inputRef}
        value={open ? query : (selected ? selected.label : '')}
        onChange={(e) => { setQuery(e.target.value); setOpen(true); }}
        onFocus={() => { setOpen(true); setQuery(''); }}
        placeholder={loading ? 'Loading…' : placeholder}
        className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
      />
      {value && !open && (
        <button type="button" onClick={() => onChange('')} className="absolute right-2 top-1.5 rounded p-1 text-slate-400 hover:text-slate-600" title="Clear">
          <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2"><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
      )}
      {menu}
    </div>
  );
}
