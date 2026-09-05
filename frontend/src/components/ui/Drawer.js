import { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { useI18n } from '../../i18n/I18nContext';

// A right-side slide-over panel ("drawer"). Covers ~half the screen on desktop and goes
// full-width on small screens, so the surface behind it (e.g. the workflow board) stays
// visible for context. ESC + backdrop-click close it, and body scroll is locked while open.
//
//   <Drawer open={!!sel} onClose={() => setSel(null)} title="…" subtitle="…" footer={…}>
//     …scrollable body…
//   </Drawer>
//
// `width` controls the desktop span: 'half' (≈50%) is the default; 'lg' is a touch wider.
const WIDTHS = {
  half: 'sm:max-w-[640px] lg:w-1/2 lg:max-w-[760px]',
  lg:   'sm:max-w-[760px] lg:w-[58%] lg:max-w-[900px]',
};

export default function Drawer({ open, onClose, title, subtitle, eyebrow, width = 'half', children, footer }) {
  const { t } = useI18n();
  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e) => e.key === 'Escape' && onClose?.();
    document.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
    };
  }, [open, onClose]);

  if (!open) return null;

  return createPortal(
    <div className="fixed inset-0 z-50">
      {/* Backdrop — dims the board but keeps it readable underneath. */}
      <div className="absolute inset-0 bg-slate-900/40 backdrop-blur-[2px] animate-fade" onClick={onClose} />

      {/* The panel itself, anchored to the right edge. */}
      <div
        className={`absolute inset-y-0 end-0 flex w-full ${WIDTHS[width] || WIDTHS.half} flex-col bg-slate-50 shadow-2xl ring-1 ring-slate-900/10 animate-slide-in-right rtl:animate-slide-in-left`}
        role="dialog"
        aria-modal="true"
        // See lib/formGuide.js — binds the fields to the footer button that saves them.
        data-form-scope=""
      >
        <header className="flex shrink-0 items-start justify-between gap-3 border-b border-slate-200 bg-white px-5 py-4">
          <div className="min-w-0">
            {eyebrow && (
              <p className="mb-0.5 text-[11px] font-semibold uppercase tracking-wider text-slate-400">{eyebrow}</p>
            )}
            <h2 className="truncate font-display text-lg font-bold leading-tight text-slate-900">{title}</h2>
            {subtitle && <p className="mt-0.5 truncate text-sm text-slate-500">{subtitle}</p>}
          </div>
          <button
            onClick={onClose}
            className="-me-1 shrink-0 rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
            aria-label={t('Close')}
          >
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
              <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </header>

        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-5">{children}</div>

        {footer && (
          <footer className="shrink-0 border-t border-slate-200 bg-white px-5 py-3">{footer}</footer>
        )}
      </div>
    </div>,
    document.body
  );
}
