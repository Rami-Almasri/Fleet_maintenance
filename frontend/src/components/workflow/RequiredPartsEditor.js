// "Requires Parts" — the INSPECTOR'S technical list of what a repair is expected to need.
//
// This is deliberately NOT procurement. Nothing here orders anything, asks for approval, or commits money.
// The inspector answers one technical question — "what will this job need?" — and stops there, because the
// facts a purchase turns on (which garage gets the work, whether that garage supplies its own parts, which
// supplier, what budget) are not known at inspection time and are not his call. The maintenance coordinator
// makes those decisions later, once the garage is chosen, and only then converts these lines into real Part
// Requests (see RequiredPartsPanel + MaintenanceRequiredPartService).
//
// So the fields here are the technical ones only — part, quantity, priority, note, and which finding the
// part is for. No price, no supplier, no part number: those belong to whoever does the buying.
//
// `findings` is the symptom list the inspector has already picked in the report; each line optionally binds
// to one, which is what gives every purchased part a traceable "this was for that fault" link. `value` is a
// flat array of line objects and `onChange` returns the next array — the parent owns the state and ships it
// as `required_parts[]` on the report submit.

import Icon from '../ui/Icon';
import { useI18n } from '../../i18n/I18nContext';

// Urgency of the PART for this repair. Deliberately its own scale rather than reusing fault severity: a
// routine fault can still need a part urgently (and a critical fault may need a part that is easy to get).
const PRIORITIES = [
  { key: 'urgent', label: 'Urgent', dot: 'bg-red-500' },
  { key: 'high', label: 'High', dot: 'bg-amber-500' },
  { key: 'normal', label: 'Normal', dot: 'bg-slate-400' },
  { key: 'low', label: 'Low', dot: 'bg-slate-300' },
];

const emptyLine = () => ({ part_name: '', quantity: 1, priority: 'normal', notes: '', finding: '' });

export default function RequiredPartsEditor({ enabled, onToggle, value = [], onChange, findings = [] }) {
  const { t } = useI18n();
  const lines = value.length ? value : [];

  const patch = (i, next) => onChange(lines.map((l, idx) => (idx === i ? { ...l, ...next } : l)));
  const add = () => onChange([...lines, emptyLine()]);
  const remove = (i) => onChange(lines.filter((_, idx) => idx !== i));

  // Turning the section on should never present an empty shell — start the inspector on one row.
  const toggle = (on) => {
    onToggle(on);
    if (on && lines.length === 0) onChange([emptyLine()]);
  };

  return (
    <div className="rounded-xl bg-slate-50 p-3 ring-1 ring-inset ring-slate-200">
      <label className="flex cursor-pointer items-start gap-2.5">
        <input
          type="checkbox"
          checked={enabled}
          onChange={(e) => toggle(e.target.checked)}
          className="mt-0.5 h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500"
        />
        <span>
          <span className="block text-sm font-medium text-slate-700">
            {t('workflow.requiredParts.toggle')}
          </span>
          {/* Say plainly what this does and does NOT do, so nobody reads it as "order these". */}
          <span className="mt-0.5 block text-xs text-slate-500">
            {t('workflow.requiredParts.toggleHint')}
          </span>
        </span>
      </label>

      {enabled && (
        <div className="mt-3 space-y-2">
          {lines.map((line, i) => (
            <div key={i} className="rounded-lg bg-white p-2.5 ring-1 ring-slate-200">
              <div className="flex items-start gap-2">
                <div className="flex-1 space-y-2">
                  <input
                    type="text"
                    value={line.part_name}
                    onChange={(e) => patch(i, { part_name: e.target.value })}
                    placeholder={t('workflow.requiredParts.namePlaceholder')}
                    className="w-full rounded-lg border-slate-200 text-sm placeholder:text-slate-400 focus:border-sky-400 focus:ring-sky-400"
                  />

                  <div className="flex flex-wrap items-center gap-2">
                    <label className="flex items-center gap-1.5 text-xs text-slate-500">
                      {t('workflow.requiredParts.qty')}
                      <input
                        type="number"
                        min="0.01"
                        step="any"
                        value={line.quantity}
                        onChange={(e) => patch(i, { quantity: e.target.value })}
                        className="w-16 rounded-lg border-slate-200 py-1 text-sm focus:border-sky-400 focus:ring-sky-400"
                      />
                    </label>

                    <select
                      value={line.priority}
                      onChange={(e) => patch(i, { priority: e.target.value })}
                      className="rounded-lg border-slate-200 py-1 text-xs text-slate-600 focus:border-sky-400 focus:ring-sky-400"
                    >
                      {PRIORITIES.map((p) => (
                        <option key={p.key} value={p.key}>
                          {t(`workflow.requiredParts.priority.${p.key}`)}
                        </option>
                      ))}
                    </select>

                    {/* Which fault the part is for — the link that makes every later purchase traceable.
                        Only offered once findings exist; optional, because a part can serve the job overall. */}
                    {findings.length > 0 && (
                      <select
                        value={line.finding || ''}
                        onChange={(e) => patch(i, { finding: e.target.value })}
                        className="min-w-0 flex-1 rounded-lg border-slate-200 py-1 text-xs text-slate-600 focus:border-sky-400 focus:ring-sky-400"
                      >
                        <option value="">{t('workflow.requiredParts.noFault')}</option>
                        {findings.map((f) => (
                          <option key={f} value={f}>
                            {f}
                          </option>
                        ))}
                      </select>
                    )}
                  </div>

                  <input
                    type="text"
                    value={line.notes}
                    onChange={(e) => patch(i, { notes: e.target.value })}
                    placeholder={t('workflow.requiredParts.notesPlaceholder')}
                    className="w-full rounded-lg border-slate-200 py-1 text-xs placeholder:text-slate-400 focus:border-sky-400 focus:ring-sky-400"
                  />
                </div>

                <button
                  type="button"
                  onClick={() => remove(i)}
                  title={t('common.remove')}
                  className="rounded-lg p-1.5 text-slate-400 transition hover:bg-red-50 hover:text-red-600"
                >
                  <Icon.X className="h-4 w-4" />
                </button>
              </div>
            </div>
          ))}

          <button
            type="button"
            onClick={add}
            className="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs font-medium text-sky-700 transition hover:bg-sky-50"
          >
            <Icon.Plus className="h-3.5 w-3.5" />
            {t('workflow.requiredParts.add')}
          </button>
        </div>
      )}
    </div>
  );
}

/** Drop blank rows and coerce quantities — what actually gets sent as `required_parts[]`. */
export function cleanRequiredParts(lines = []) {
  return lines
    .filter((l) => (l.part_name || '').trim() !== '')
    .map((l) => ({
      part_name: l.part_name.trim(),
      quantity: Number(l.quantity) > 0 ? Number(l.quantity) : 1,
      priority: l.priority || 'normal',
      notes: (l.notes || '').trim() || null,
      finding: (l.finding || '').trim() || null,
    }));
}

export { PRIORITIES as REQUIRED_PART_PRIORITIES };
