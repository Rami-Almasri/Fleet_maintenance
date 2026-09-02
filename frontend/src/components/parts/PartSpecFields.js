// The spec inputs for a part — "which battery?", "which oil?" — rendered from the shared dictionary.
//
// ONE COMPONENT, EVERY SURFACE. Drop it into any form that records a part (the install step, a part
// request, a purchase, an oil change) and it renders the right fields for whatever part type was
// picked, because the fields come from the API rather than from this file. A form that needs specs
// never has to know what a battery has and an oil does not.
//
// When a vehicle is passed it also does the thing that makes the feature worth having: it shows
// what THAT CAR takes above the inputs, offers to fill them in with one click, and says so plainly
// when what is being entered disagrees with it.
//
// The mismatch is a WARNING and never a block. The spec sheet it compares against may itself be an
// unconfirmed observation, and the person filling this in is holding the part while we are not.

import { useEffect, useMemo, useState } from 'react';
import { Input, Select } from '../ui/Field';
import Icon from '../ui/Icon';
import { useI18n } from '../../i18n/I18nContext';
import {
  fieldsForPartType,
  loadSpecDictionary,
  specConflicts,
  specSummary,
} from '../../lib/partSpecs';

/**
 * @param {number|null} catalogId    the chosen part type; nothing renders without one
 * @param {object}      value        current spec map — { voltage: '12v', capacity_ah: '60' }
 * @param {function}    onChange     receives the whole updated map
 * @param {object|null} expected     what this car takes: { specs, summary, source, provenance }
 * @param {function}    onConflicts  optional — notified whenever the mismatch list changes, so a
 *                                   parent can require a confirmation before saving
 * @param {boolean}     disabled
 */
export default function PartSpecFields({
  catalogId,
  value = {},
  onChange,
  expected = null,
  onConflicts,
  disabled = false,
}) {
  const { t, lang } = useI18n();
  const [dictionary, setDictionary] = useState(null);

  useEffect(() => {
    let alive = true;
    loadSpecDictionary(lang).then((d) => alive && setDictionary(d));
    return () => {
      alive = false;
    };
  }, [lang]);

  const fields = useMemo(
    () => (dictionary && catalogId ? fieldsForPartType(dictionary, catalogId) : []),
    [dictionary, catalogId]
  );

  const conflicts = useMemo(
    () => (dictionary ? specConflicts(dictionary, catalogId, expected?.specs, value) : []),
    [dictionary, catalogId, expected, value]
  );

  // Reported upward rather than acted on here: whether a mismatch needs a confirmation before
  // saving is the form's decision, not this control's.
  useEffect(() => {
    if (onConflicts) onConflicts(conflicts);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [JSON.stringify(conflicts)]);

  // A part type with no spec fields renders nothing at all — no empty heading, no "N/A" row.
  if (!catalogId || fields.length === 0) return null;

  const set = (key, raw) => {
    const next = { ...value };
    // Cleared means UNANSWERED, so the key is removed rather than set to ''. The distinction is the
    // difference between "nobody recorded the terminal side" and "the terminal side is blank".
    if (raw === '' || raw === null || raw === undefined) delete next[key];
    else next[key] = raw;
    onChange(next);
  };

  const applyExpected = () => onChange({ ...value, ...(expected?.specs || {}) });

  const expectedSummary = expected?.summary || (dictionary ? specSummary(dictionary, catalogId, expected?.specs) : '');

  return (
    <div className="space-y-3">
      <div className="flex items-center gap-2">
        <Icon.Wrench className="h-4 w-4 text-slate-400" />
        <span className="text-sm font-medium text-slate-700">{t('Specification')}</span>
      </div>

      {/* What this car takes — the answer before the question is asked. */}
      {expectedSummary && (
        <div className="rounded-xl bg-slate-50 px-4 py-3 ring-1 ring-inset ring-slate-200">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <div className="text-xs uppercase tracking-wide text-slate-500">
                {t('This vehicle takes')}
              </div>
              <div className="text-sm font-semibold text-slate-900">{expectedSummary}</div>
            </div>
            <button
              type="button"
              onClick={applyExpected}
              disabled={disabled}
              className="rounded-lg bg-white px-3 py-1.5 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-300 transition hover:bg-slate-100 disabled:opacity-50"
            >
              {t('Use this')}
            </button>
          </div>
          {/* WHERE the figure came from. A number copied from the last fitting and a number read
              out of the handbook look identical on screen, and only one is a reason to argue. */}
          {expected?.provenance && (
            <p className="mt-1 text-xs text-slate-500">{expected.provenance}</p>
          )}
        </div>
      )}

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        {fields.map((field) => {
          const current = value[field.key] ?? '';

          if (field.kind === 'enum') {
            return (
              <Select
                key={field.key}
                label={field.label}
                value={current}
                disabled={disabled}
                onChange={(e) => set(field.key, e.target.value)}
              >
                <option value="">{t('Not recorded')}</option>
                {(field.options || []).map((o) => (
                  <option key={o.key} value={o.key}>
                    {o.label}
                  </option>
                ))}
              </Select>
            );
          }

          if (field.kind === 'number') {
            return (
              <Input
                key={field.key}
                type="number"
                label={field.unit ? `${field.label} (${field.unit})` : field.label}
                value={current}
                min={field.min ?? undefined}
                max={field.max ?? undefined}
                step={field.step ?? 'any'}
                disabled={disabled}
                placeholder={field.placeholder || ''}
                onChange={(e) => set(field.key, e.target.value)}
              />
            );
          }

          return (
            <Input
              key={field.key}
              label={field.label}
              value={current}
              disabled={disabled}
              placeholder={field.placeholder || ''}
              onChange={(e) => set(field.key, e.target.value)}
            />
          );
        })}
      </div>

      {/* The disagreement, stated as a sentence rather than as an error. Amber, not red: nothing is
          being refused, and colouring it as a failure would train people to click past it. */}
      {conflicts.length > 0 && (
        <div className="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-500/25">
          <div className="flex items-start gap-2">
            <Icon.Alert className="mt-0.5 h-4 w-4 shrink-0" />
            <div>
              <p className="font-medium">{t('This is not what the vehicle takes')}</p>
              <ul className="mt-1 space-y-0.5">
                {conflicts.map((c) => (
                  <li key={c.key}>
                    {c.label}: <strong>{c.actual}</strong> — {t('the vehicle takes')}{' '}
                    <strong>{c.expected}</strong>
                  </li>
                ))}
              </ul>
              <p className="mt-1 text-xs text-amber-800">
                {t('You can still save this — check the part before you do.')}
              </p>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

/**
 * The read-only sibling: one line of spec next to a part name, wherever a part is listed.
 *
 * Renders NOTHING when there is nothing recorded, so it can be dropped into a table cell without
 * leaving a stray separator behind on the rows nobody has specced.
 */
export function PartSpecLine({ summary, className = '' }) {
  if (!summary) return null;

  return (
    <span className={`text-xs text-slate-500 ${className}`} title={summary}>
      {summary}
    </span>
  );
}

/** The full label/value list for a dossier — including what was NOT recorded, which is the prompt. */
export function PartSpecDetail({ detail = [], emptyLabel = 'Not recorded' }) {
  const { t } = useI18n();

  if (!detail.length) return null;

  return (
    <dl className="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
      {detail.map((row) => (
        <div key={row.key} className="flex items-baseline justify-between gap-3 border-b border-slate-100 pb-1">
          <dt className="text-xs text-slate-500">
            {row.label}
            {/* The dot marks a field where the WRONG value damages the car, as opposed to merely
                costing money — the same `critical` flag the mismatch warning is raised on. */}
            {row.critical && (
              <span className="ms-1 text-amber-500" title={t('Getting this wrong damages the car')}>
                •
              </span>
            )}
          </dt>
          <dd className={row.recorded ? 'text-sm font-medium text-slate-900' : 'text-sm text-slate-400'}>
            {row.recorded ? row.value : emptyLabel}
          </dd>
        </div>
      ))}
    </dl>
  );
}
