// Accessible hover/focus tooltip. Rendered in a portal with fixed positioning so
// it never gets clipped by `overflow-hidden` cards or `overflow-x-auto` tables.
//
//   <Tooltip content="Net cash collected minus maintenance cost">
//     <span className="underline decoration-dotted">Net Profit</span>
//   </Tooltip>
//
//   <InfoTip content="…" />   // a small (i) marker that explains a technical term
//
// Opens on hover AND keyboard focus; closes on leave/blur/Escape. Mobile users
// get it on tap (the trigger is focusable).

import { useCallback, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

export function Tooltip({ content, children, side = 'top', className = '', maxWidth = 260 }) {
  const [open, setOpen] = useState(false);
  const [pos, setPos] = useState({ top: 0, left: 0 });
  const ref = useRef(null);
  const tipId = useId();

  const show = useCallback(() => {
    const el = ref.current;
    if (!el) return;
    const r = el.getBoundingClientRect();
    const gap = 8;
    if (side === 'bottom') setPos({ top: r.bottom + gap, left: r.left + r.width / 2 });
    else setPos({ top: r.top - gap, left: r.left + r.width / 2 });
    setOpen(true);
  }, [side]);

  const hide = useCallback(() => setOpen(false), []);

  if (content == null || content === '') return children;

  const translate = side === 'bottom' ? 'translate(-50%, 0)' : 'translate(-50%, -100%)';

  return (
    <span
      ref={ref}
      className={`inline-flex items-center ${className}`}
      tabIndex={0}
      aria-describedby={open ? tipId : undefined}
      onMouseEnter={show}
      onMouseLeave={hide}
      onFocus={show}
      onBlur={hide}
      onKeyDown={(e) => e.key === 'Escape' && hide()}
    >
      {children}
      {open &&
        createPortal(
          <div
            id={tipId}
            role="tooltip"
            style={{ position: 'fixed', top: pos.top, left: pos.left, transform: translate, maxWidth }}
            className="pointer-events-none z-[80] rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs font-medium leading-snug text-white shadow-xl shadow-slate-900/25 ring-1 ring-white/10 animate-fade-in-up"
          >
            {content}
            <span
              className="absolute left-1/2 h-2 w-2 -translate-x-1/2 rotate-45 bg-slate-900"
              style={side === 'bottom' ? { top: -3 } : { bottom: -3 }}
            />
          </div>,
          document.body
        )}
    </span>
  );
}

// A small muted "(i)" marker that reveals an explanation on hover — for technical
// terms / metric definitions, so the user never leaves the page to understand a KPI.
export function InfoTip({ content, side = 'top', className = '' }) {
  return (
    <Tooltip content={content} side={side} className={className}>
      <span className="inline-flex h-3.5 w-3.5 cursor-help items-center justify-center rounded-full bg-slate-200 text-[9px] font-bold text-slate-500 transition hover:bg-slate-300 hover:text-slate-700">
        i
      </span>
    </Tooltip>
  );
}

export default Tooltip;
