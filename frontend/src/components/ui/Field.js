// Form field primitives with label + error support.
//
// Two things beyond plain inputs:
//
//   hint      a short "what goes in here" sentence, revealed by an (i) beside the
//             label and repeated as the control's native title.
//   required  besides the red *, stamps the control with its own label so the
//             form's Save button can name what is still empty on hover — see
//             lib/formGuide.js.

import { InfoTip } from './Tooltip';
import { LABEL_ATTR, REQUIRED_ATTR, VALUE_ATTR } from '../../lib/formGuide';

const baseInput =
  'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 disabled:bg-slate-50 disabled:text-slate-400';

function Wrap({ label, error, required, hint, children }) {
  return (
    <label className="block">
      {label && (
        <span className="mb-1 flex items-center gap-1 text-sm font-medium text-slate-700">
          <span>
            {label}
            {required && <span className="ms-0.5 text-red-500">*</span>}
          </span>
          {hint && <InfoTip content={hint} />}
        </span>
      )}
      {children}
      {error && <span className="mt-1 block text-xs text-red-600">{error}</span>}
    </label>
  );
}

// The guidance attributes only carry a plain-text label; a label passed as JSX
// has no sentence to quote, so such a field simply stays unnamed rather than
// being announced as "[object Object]".
function guideProps(label, required, hint) {
  const text = typeof label === 'string' ? label : null;
  return {
    [REQUIRED_ATTR]: required ? '1' : undefined,
    [LABEL_ATTR]: required && text ? text : undefined,
    'aria-required': required || undefined,
    title: hint || undefined,
  };
}

/**
 * Declares a mandatory answer that no <Input> holds — a photo tile, a signature
 * pad, a picker built out of cards. Without this, such a requirement is
 * invisible to the Save button and the form goes quiet about the one thing the
 * user is actually missing.
 *
 *   <Requirement label={t('Odometer photo')} value={photo} />
 *
 * Renders nothing. `value` is judged the same way a field's is: empty string,
 * null, undefined and false are unanswered; anything else is answered.
 */
export function Requirement({ label, value }) {
  const answered = value !== null && value !== undefined && value !== false && String(value).trim() !== '';
  return (
    <span
      aria-hidden="true"
      style={{ display: 'none' }}
      {...{ [REQUIRED_ATTR]: '1', [LABEL_ATTR]: label, [VALUE_ATTR]: answered ? '1' : '' }}
    />
  );
}

// A number input accepts scientific notation, so 'e'/'E'/'+' are legal keystrokes —
// and while the value is unparseable the browser reports it as '', silently wiping a
// reading the user already typed. No field here means an exponent, so refuse the keys.
const EXPONENT_KEYS = ['e', 'E', '+'];

export function Input({ label, error, required, hint, className = '', onKeyDown, ...props }) {
  const guardExponent =
    props.type === 'number'
      ? (e) => {
          if (EXPONENT_KEYS.includes(e.key)) e.preventDefault();
          onKeyDown?.(e);
        }
      : onKeyDown;
  return (
    <Wrap label={label} error={error} required={required} hint={hint}>
      <input
        className={`${baseInput} ${error ? 'border-red-400' : ''} ${className}`}
        onKeyDown={guardExponent}
        {...guideProps(label, required, hint)}
        {...props}
      />
    </Wrap>
  );
}

export function Textarea({ label, error, required, hint, className = '', rows = 3, ...props }) {
  return (
    <Wrap label={label} error={error} required={required} hint={hint}>
      <textarea
        rows={rows}
        className={`${baseInput} ${error ? 'border-red-400' : ''} ${className}`}
        {...guideProps(label, required, hint)}
        {...props}
      />
    </Wrap>
  );
}

export function Select({ label, error, required, hint, children, className = '', ...props }) {
  return (
    <Wrap label={label} error={error} required={required} hint={hint}>
      <select
        className={`${baseInput} capitalize ${error ? 'border-red-400' : ''} ${className}`}
        {...guideProps(label, required, hint)}
        {...props}
      >
        {children}
      </select>
    </Wrap>
  );
}
