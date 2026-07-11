import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

/**
 * Vehicle picker for the maintenance workflow, grouped by the car's current operational
 * state so the user can see availability at a glance instead of scanning a flat list.
 *
 * Each car is sorted into one of three buckets — Available / In Maintenance / Rented —
 * each with its own colour-coded header, a status dot, and a small status badge beside
 * the plate. A search box still filters across plate / make / model / id. When the
 * picked car is in maintenance or out on rent, a non-blocking warning appears under the
 * field (the move is still allowed — the server re-guards it).
 *
 * Like SearchSelect, the menu is portal'd to <body> with `fixed` coords so it can't be
 * clipped by an ancestor's `overflow-hidden`.
 *
 * vehicles: raw Vehicle resources (need operational_status / under_maintenance / rented).
 */

// Order matters: groups render top-to-bottom in this sequence.
const GROUPS = ['available', 'maintenance', 'rented'];

const STATUS = {
  available: {
    label: 'Available',
    header: 'Available',
    dot: 'bg-emerald-500',
    badge: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    headText: 'text-emerald-600',
  },
  maintenance: {
    label: 'In Maintenance',
    header: 'In Maintenance',
    dot: 'bg-amber-500',
    badge: 'bg-amber-50 text-amber-700 ring-amber-600/20',
    headText: 'text-amber-600',
    warning: 'This vehicle is currently in maintenance. Proceeding will trigger a new diagnostic entry.',
  },
  rented: {
    label: 'Rented',
    header: 'Rented',
    dot: 'bg-blue-500',
    badge: 'bg-blue-50 text-blue-700 ring-blue-600/20',
    headText: 'text-blue-600',
    warning: 'This vehicle is currently rented out to a customer. Make sure it has been returned before starting a test drive.',
  },
};

// Where a car sits in the picker. Maintenance wins over rental (a "dead capacity" car —
// on rent yet sitting in the shop — is what the inspector most needs the warning for).
function groupOf(v) {
  if (v.under_maintenance || v.operational_status === 'maintenance') return 'maintenance';
  if (v.rented || v.operational_status === 'rented') return 'rented';
  return 'available';
}

export default function VehicleStatusSelect({ value, onChange, vehicles = [], placeholder = 'Search plate / make / model…', loading = false }) {
  const [query, setQuery] = useState('');
  const [open, setOpen] = useState(false);
  const [rect, setRect] = useState(null);
  const ref = useRef(null);
  const inputRef = useRef(null);

  // Normalise each car into a display row once, tagged with its status group.
  const options = useMemo(
    () => vehicles.map((v) => ({
      id: v.id,
      label: v.plate_no || `#${v.id}`,
      sub: [v.make, v.model].filter(Boolean).join(' '),
      group: groupOf(v),
      search: `${v.plate_no || ''} ${v.make || ''} ${v.model || ''} ${v.id}`.toLowerCase(),
    })),
    [vehicles],
  );

  const selected = options.find((o) => String(o.id) === String(value));

  const measure = useCallback(() => {
    if (!inputRef.current) return;
    const r = inputRef.current.getBoundingClientRect();
    setRect({ top: r.bottom, left: r.left, width: r.width });
  }, []);

  useLayoutEffect(() => { if (open) measure(); }, [open, measure]);

  useEffect(() => {
    if (!open) return undefined;
    const onScrollOrResize = () => measure();
    window.addEventListener('scroll', onScrollOrResize, true);
    window.addEventListener('resize', onScrollOrResize);
    return () => {
      window.removeEventListener('scroll', onScrollOrResize, true);
      window.removeEventListener('resize', onScrollOrResize);
    };
  }, [open, measure]);

  useEffect(() => {
    const onDoc = (e) => {
      if (ref.current && ref.current.contains(e.target)) return;
      if (e.target.closest && e.target.closest('[data-vehiclestatus-menu]')) return;
      setOpen(false);
    };
    document.addEventListener('mousedown', onDoc);
    return () => document.removeEventListener('mousedown', onDoc);
  }, []);

  // Filter by query, then bucket into the three groups (preserving group order).
  const grouped = useMemo(() => {
    const q = query.trim().toLowerCase();
    const base = q ? options.filter((o) => o.search.includes(q)) : options;
    const buckets = { available: [], maintenance: [], rented: [] };
    base.forEach((o) => { buckets[o.group].push(o); });
    return GROUPS
      .map((g) => ({ group: g, items: buckets[g].slice(0, 30) }))
      .filter((b) => b.items.length > 0);
  }, [query, options]);

  const total = grouped.reduce((n, b) => n + b.items.length, 0);

  const pick = (o) => { onChange(o.id); setOpen(false); setQuery(''); };

  const menu = open && rect ? createPortal(
    <div
      data-vehiclestatus-menu
      style={{ position: 'fixed', top: rect.top + 4, left: rect.left, width: rect.width }}
      className="z-50 max-h-72 overflow-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg"
    >
      {total === 0 && <div className="px-3 py-2 text-sm text-gray-400">{loading ? 'Loading…' : 'No matches'}</div>}
      {grouped.map(({ group, items }) => {
        const meta = STATUS[group];
        return (
          <div key={group}>
            <div className="sticky top-0 flex items-center gap-1.5 bg-white/95 px-3 pb-1 pt-1.5 backdrop-blur">
              <span className={`h-1.5 w-1.5 rounded-full ${meta.dot}`} />
              <span className={`text-[11px] font-semibold uppercase tracking-wide ${meta.headText}`}>{meta.header}</span>
              <span className="text-[11px] font-medium text-gray-300">{items.length}</span>
            </div>
            {items.map((o) => (
              <button
                key={o.id}
                type="button"
                onClick={() => pick(o)}
                className={`flex w-full items-center gap-2.5 px-3 py-2 text-left hover:bg-gray-50 ${String(o.id) === String(value) ? 'bg-indigo-50' : ''}`}
              >
                <span className={`h-2 w-2 shrink-0 rounded-full ${meta.dot}`} />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium text-gray-900">{o.label}</span>
                  {o.sub && <span className="block truncate text-xs text-gray-400">{o.sub}</span>}
                </span>
                <span className={`shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 ring-inset ${meta.badge}`}>{meta.label}</span>
              </button>
            ))}
          </div>
        );
      })}
    </div>,
    document.body,
  ) : null;

  const selMeta = selected ? STATUS[selected.group] : null;

  return (
    <div ref={ref}>
      <div className="relative">
        <input
          ref={inputRef}
          value={open ? query : (selected ? selected.label : '')}
          onChange={(e) => { setQuery(e.target.value); setOpen(true); }}
          onFocus={() => { setOpen(true); setQuery(''); }}
          placeholder={loading ? 'Loading…' : placeholder}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
        />
        {/* Status badge of the picked car, shown inside the field while the menu is closed. */}
        {selected && !open && selMeta && (
          <span className={`absolute right-9 top-1/2 -translate-y-1/2 rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 ring-inset ${selMeta.badge}`}>
            {selMeta.label}
          </span>
        )}
        {value && !open && (
          <button type="button" onClick={() => onChange('')} className="absolute right-2 top-1.5 rounded p-1 text-gray-400 hover:text-gray-600" title="Clear">
            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2"><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
          </button>
        )}
        {menu}
      </div>

      {/* Non-blocking contextual helper: only when the picked car isn't freely available. */}
      {selected && selMeta?.warning && (
        <p className="mt-1.5 flex items-start gap-1.5 text-xs text-amber-600">
          <svg className="mt-0.5 h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2"><path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
          <span><span className="font-semibold">Warning:</span> {selMeta.warning}</span>
        </p>
      )}
    </div>
  );
}
