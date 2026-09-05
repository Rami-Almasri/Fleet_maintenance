// Hover guidance for the button that finishes a form.
//
// A form's Save button knows nothing about the fields above it — in this app the
// two are wired only by an onClick handler, and the button usually lives in a
// Modal footer, outside any <form>. So rather than teach 77 footers what each
// form needs, the button ASKS THE DOM at hover time: every required field stamps
// its own label onto its control (see components/ui/Field.js), so whatever is
// still empty inside the enclosing form scope can be read back and named.
//
//   <Button>Save</Button>   inside a Modal   →   hover: "Still needed: Plate, Odometer"
//
// The scope is the dialog panel (Modal/Drawer/ConfirmDialog stamp `data-form-scope`)
// or a real <form>. No scope, or nothing missing, means no tooltip.

export const SCOPE_SELECTOR = '[data-form-scope], form';

// The attributes Field.js writes onto the native control.
export const LABEL_ATTR = 'data-guide-label';
export const REQUIRED_ATTR = 'data-guide-required';
// For a control whose visible input does NOT hold the answer — a combobox whose
// box shows the search text while the selection lives in state. It declares the
// value that actually counts, and that is what emptiness is judged on.
export const VALUE_ATTR = 'data-guide-value';

// Never name more than this many fields — a tooltip is a nudge, not a report.
const MAX_NAMED = 4;

function isBlank(el) {
  const declared = el.getAttribute(VALUE_ATTR);
  if (declared !== null) return declared.trim() === '';
  if (el.type === 'checkbox' || el.type === 'radio') return !el.checked;
  return String(el.value ?? '').trim() === '';
}

// A field the user cannot act on is not something they can be asked to fill.
// The branches of a conditional form are unmounted rather than CSS-hidden here,
// so an inapplicable field is simply absent — no layout measurement needed.
function isReachable(el) {
  return !el.disabled && el.type !== 'hidden' && !el.closest('[hidden]');
}

// The labels of every required-and-empty field inside `scope`, in document order.
export function missingFields(scope) {
  if (!scope) return [];
  const names = [];
  for (const el of scope.querySelectorAll(`[${REQUIRED_ATTR}="1"]`)) {
    if (!isReachable(el) || !isBlank(el)) continue;
    const name = el.getAttribute(LABEL_ATTR) || el.getAttribute('placeholder');
    if (name && !names.includes(name)) names.push(name);
  }
  return names;
}

// "Plate, Odometer and Garage" — `and` is supplied by the caller so this stays
// free of React and of the i18n hook.
export function joinNames(names, and = 'and') {
  const shown = names.slice(0, MAX_NAMED);
  const rest = names.length - shown.length;
  const tail = rest > 0 ? [`+${rest}`] : [];
  const all = [...shown, ...tail];
  if (all.length <= 1) return all.join('');
  return `${all.slice(0, -1).join(', ')} ${and} ${all[all.length - 1]}`;
}
