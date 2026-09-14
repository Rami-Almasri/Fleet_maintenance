// The overflow menu behind a table row's "⋮".
//
// A row has one action that matters and several that occasionally do. Rendering all of them side by side
// costs the reader the same glance for the rare one as for the important one, and the widest row sets the
// column width for every other row on the page. So the important action stays a button and the rest live
// here, one click away and out of the way until asked for.
//
// It is deliberately a plain button + list rather than a native <select>: these are commands, not a value
// being chosen, and a select would report the last one as the row's "state".

import { useEffect, useRef, useState } from 'react';

/**
 * @param items  [{ key, label, onSelect, danger }] — already filtered by the caller. An empty list
 *               renders nothing at all, so a row with no secondary actions has no dangling control.
 * @param label  accessible name for the trigger, e.g. "More actions for invoice 33333"
 */
export default function ActionMenu({ items = [], label = 'More actions', align = 'end', glyph = '⋮' }) {
  const [open, setOpen] = useState(false);
  const wrap = useRef(null);

  // Close on anything that means "I'm done here": a click elsewhere, or Escape. Escape also returns
  // focus to the trigger, so a keyboard reader is not dropped at the top of the document.
  useEffect(() => {
    if (!open) return undefined;
    const onDown = (e) => { if (wrap.current && !wrap.current.contains(e.target)) setOpen(false); };
    const onKey = (e) => {
      if (e.key === 'Escape') {
        setOpen(false);
        wrap.current?.querySelector('button')?.focus();
      }
    };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  if (!items.length) return null;

  return (
    <div className="relative inline-block" ref={wrap}>
      <button
        type="button"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={label}
        onClick={() => setOpen((o) => !o)}
        className="inline-flex h-7 w-7 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400"
      >
        <span aria-hidden="true" className="text-lg leading-none">{glyph}</span>
      </button>

      {open && (
        <div
          role="menu"
          className={`absolute z-30 mt-1 min-w-[11rem] overflow-hidden rounded-lg border border-slate-200 bg-white py-1 shadow-lg ${
            align === 'end' ? 'end-0' : 'start-0'
          }`}
        >
          {items.map((item) => (
            <button
              key={item.key}
              type="button"
              role="menuitem"
              disabled={item.disabled}
              onClick={() => { setOpen(false); item.onSelect?.(); }}
              className={`block w-full px-3 py-1.5 text-start text-sm disabled:opacity-40 ${
                item.danger ? 'text-red-600 hover:bg-red-50' : 'text-slate-700 hover:bg-slate-50'
              }`}
            >
              {item.label}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
