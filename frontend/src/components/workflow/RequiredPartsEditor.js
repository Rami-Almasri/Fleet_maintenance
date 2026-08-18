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

import { useEffect, useRef, useState } from 'react';
import Icon from '../ui/Icon';
import { useI18n } from '../../i18n/I18nContext';
import CatalogPartPicker from '../parts/CatalogPartPicker';
import api from '../../api/client';

// Urgency of the PART for this repair. Deliberately its own scale rather than reusing fault severity: a
// routine fault can still need a part urgently (and a critical fault may need a part that is easy to get).
const PRIORITIES = [
  { key: 'urgent', label: 'Urgent', dot: 'bg-red-500' },
  { key: 'high', label: 'High', dot: 'bg-amber-500' },
  { key: 'normal', label: 'Normal', dot: 'bg-slate-400' },
  { key: 'low', label: 'Low', dot: 'bg-slate-300' },
];

// A line now carries the catalog REFERENCE. `part_name` remains, but as a label copied from the
// chosen part rather than as the identity — the id is what everything downstream joins on.
const emptyLine = () => ({
  component_catalog_id: null, part_name: '', part_number: null,
  quantity: 1, priority: 'normal', notes: '', finding: '',
});

export default function RequiredPartsEditor({ enabled, onToggle, value = [], onChange, findings = [] }) {
  const { t } = useI18n();
  const lines = value.length ? value : [];

  // The catalog is fetched ONCE for the whole editor, not per line: every picker filters the same
  // list locally, so adding a tenth row costs nothing. Only fetched when the section is actually
  // switched on — most inspections never open it.
  const [catalog, setCatalog] = useState([]);
  const [catalogLoading, setCatalogLoading] = useState(false);
  const [catalogError, setCatalogError] = useState('');

  // Guarded by a REF, not by the loading state: `catalogLoading` as a dependency made the effect
  // cancel its own request — setting it ran the cleanup (alive = false) before the response landed,
  // so the list was thrown away and the picker never stopped saying "loading".
  const fetched = useRef(false);

  useEffect(() => {
    if (!enabled || fetched.current) return undefined;
    fetched.current = true;

    let alive = true;
    setCatalogLoading(true);
    api.get('/parts-catalog')
      .then(({ data }) => {
        if (!alive) return;
        // Retired parts are not offered: they are things the fleet has stopped fitting.
        setCatalog((data?.data?.parts || []).filter((p) => p.is_active));
      })
      // A failure is worth retrying the next time the section is switched on.
      .catch(() => { fetched.current = false; if (alive) setCatalogError(t('workflow.requiredParts.catalogError')); })
      // Unconditional: switching the section off mid-fetch must not leave the picker stuck.
      .finally(() => setCatalogLoading(false));

    return () => { alive = false; };
  }, [enabled, t]);

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

      {enabled && catalogError && (
        <div className="mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 ring-1 ring-inset ring-red-600/20">
          {catalogError}
        </div>
      )}

      {enabled && (
        <div className="mt-3 space-y-2">
          {lines.map((line, i) => (
            <div key={i} className="rounded-lg bg-white p-2.5 ring-1 ring-slate-200">
              <div className="flex items-start gap-2">
                <div className="flex-1 space-y-2">
                  {/* The part is CHOSEN, never typed. What is stored is the catalog id; the name
                      beside it is a label. A line carried over from the free-text era keeps its
                      words on screen (they are the inspector's evidence) and is marked as still
                      needing a part picked for it. */}
                  <CatalogPartPicker
                    catalog={catalog}
                    loading={catalogLoading}
                    legacyText={!line.component_catalog_id ? line.part_name : ''}
                    value={line.component_catalog_id ? line : null}
                    onChange={(picked) =>
                      patch(i, picked
                        ? { component_catalog_id: picked.component_catalog_id, part_name: picked.part_name, part_number: picked.part_number }
                        : { component_catalog_id: null, part_number: null })
                    }
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

/**
 * Drop blank rows and coerce quantities — what actually gets sent as `required_parts[]`.
 *
 * A row survives if it names a catalog part OR still carries legacy text. The second case exists so
 * an in-flight report written before the picker is not silently emptied on save; the backend
 * resolves what it can and reports the rest through `parts:link-required`. Once
 * PARTS_REQUIRE_CATALOG_LINK is on, the backend refuses the unlinked ones outright and the picker
 * is the only way through.
 */
export function cleanRequiredParts(lines = []) {
  return lines
    .filter((l) => l.component_catalog_id || (l.part_name || '').trim() !== '')
    .map((l) => ({
      component_catalog_id: l.component_catalog_id || null,
      part_name: (l.part_name || '').trim(),
      quantity: Number(l.quantity) > 0 ? Number(l.quantity) : 1,
      priority: l.priority || 'normal',
      notes: (l.notes || '').trim() || null,
      finding: (l.finding || '').trim() || null,
    }));
}

export { PRIORITIES as REQUIRED_PART_PRIORITIES };
