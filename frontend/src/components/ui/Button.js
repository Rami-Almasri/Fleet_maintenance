// Solid, flat enterprise fills — no gradients. Each variant carries a clear
// hover (one step darker) and its own focus ring colour. Restrained by design.
import { useCallback, useRef } from 'react';
import { useI18n } from '../../i18n/I18nContext';
import { Tooltip } from './Tooltip';
import { SCOPE_SELECTOR, joinNames, missingFields } from '../../lib/formGuide';

const VARIANTS = {
  primary:
    'bg-indigo-600 text-white shadow-sm hover:bg-indigo-700 focus-visible:outline-indigo-600',
  secondary:
    'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 shadow-sm hover:bg-slate-50 hover:ring-slate-400 focus-visible:outline-slate-400',
  ghost: 'text-slate-600 hover:bg-slate-100',
  danger:
    'bg-red-600 text-white shadow-sm hover:bg-red-700 focus-visible:outline-red-600',
  success:
    'bg-emerald-600 text-white shadow-sm hover:bg-emerald-700 focus-visible:outline-emerald-600',
  warning:
    'bg-amber-500 text-white shadow-sm hover:bg-amber-600 focus-visible:outline-amber-500',
};

const SIZES = {
  sm: 'px-3 py-1.5 text-xs gap-1.5',
  md: 'px-4 py-2.5 text-sm gap-2',
  lg: 'px-5 py-3 text-sm gap-2',
};

// Which buttons answer "what do I still have to fill in?" on hover. The button
// that FINISHES a form does; Cancel and the quiet ghost icons alongside it do
// not — being told what is missing while reaching for Cancel is just noise.
// Pass guide={false} to opt a primary button out (e.g. an "Add row" inside a
// half-filled form), or guide to opt one in.
const GUIDED = new Set(['primary', 'success', 'warning', 'danger']);

// The tooltip must live in a wrapper element to survive the button being
// disabled — a disabled <button> fires no mouse events of its own. That wrapper
// becomes the flex/grid item the caller was styling, so the classes that place a
// button WITHIN its parent have to travel up to it or the layout shifts. The
// button keeps them too, which is harmless: it now fills the wrapper.
const LAYOUT_CLASSES = new Set([
  'w-full', 'flex-1', 'grow', 'shrink-0',
  'ms-auto', 'me-auto', 'ml-auto', 'mr-auto',
  'self-start', 'self-end', 'self-center', 'self-stretch',
]);
const wrapperLayout = (className) =>
  className.split(/\s+/).filter((c) => LAYOUT_CLASSES.has(c)).join(' ');

export default function Button({
  variant = 'primary',
  size = 'md',
  loading = false,
  disabled = false,
  hint,
  guide,
  className = '',
  children,
  ...props
}) {
  const { t } = useI18n();
  const ref = useRef(null);
  const guided = guide === undefined ? GUIDED.has(variant) : guide;

  // Resolved on hover, not on render: the answer changes with every keystroke in
  // the form above, and no render of this button is triggered by those.
  const describe = useCallback(() => {
    const lines = [];
    if (hint) lines.push({ key: 'hint', text: hint, tone: 'text-white' });
    if (guided) {
      const missing = missingFields(ref.current?.closest(SCOPE_SELECTOR));
      if (missing.length) {
        lines.push({
          key: 'missing',
          tone: 'text-amber-200',
          text: t('Still needed: {fields}', { fields: joinNames(missing, t('and')) }),
        });
      }
    }
    if (!lines.length) return null;
    return (
      <span className="block space-y-1">
        {lines.map((l) => (
          <span key={l.key} className={`block ${l.tone}`}>
            {l.text}
          </span>
        ))}
      </span>
    );
  }, [hint, guided, t]);

  const button = (
    <button
      ref={ref}
      disabled={disabled || loading}
      className={`focus-ring-self inline-flex items-center justify-center rounded-lg font-semibold transition-colors duration-150 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 active:translate-y-px disabled:cursor-not-allowed disabled:opacity-60 disabled:active:translate-y-0 ${VARIANTS[variant] || VARIANTS.primary} ${SIZES[size] || SIZES.md} ${className}`}
      {...props}
    >
      {loading && (
        <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
          <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
          <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z" />
        </svg>
      )}
      {children}
    </button>
  );

  if (!hint && !guided) return button;

  return (
    <Tooltip content={describe} tabIndex={-1} className={wrapperLayout(className)}>
      {button}
    </Tooltip>
  );
}
