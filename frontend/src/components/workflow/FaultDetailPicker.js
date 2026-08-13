// "What is wrong, HOW MANY, and WHERE on the car" — the structured detail step that sits under the
// FindingsPicker, beside RootCausePicker, in the maintenance workflow.
//
// For each fault the inspector/mechanic tapped, this asks the two questions the fault record was
// missing: how many of them, and where on the vehicle. It renders a live preview of the sentence
// that will be filed ("2 scratches — rims and body") so the answer is checked before it is saved,
// not after.
//
// GENERIC BY CONSTRUCTION. This component knows nothing about scratches, dents, cracks or any other
// type. It is handed a `policy` map (keyword → required | optional | none, from the findings catalog)
// and a `groups` vocabulary, both derived server-side from config + the type catalogs. A fault type
// added to the catalog tomorrow gets this editor automatically; a type with nowhere to point at
// ("Overheating", "Wiper / washer fault") is skipped entirely and never shows an unanswerable box.
//
// Shape contract:
//   symptoms : string[]                       — the currently-selected finding tags (from FindingsPicker)
//   groups   : [{ key, label, label_ar, locations: [{ key, label, label_ar, ... }] }]
//   policy   : { [keyword]: 'required' | 'optional' | 'none' }
//   value    : { [symptom]: { quantity: number, locations: string[] } }
//   onChange : (nextValue) => void            — receives the full next value object

import { useMemo, useState } from 'react';
import Icon from '../ui/Icon';
import { useI18n } from '../../i18n/I18nContext';
import {
  DEFAULT_MAX_QUANTITY,
  locationIndex,
  policyFor,
  previewFor,
  requiresLocation,
  searchGroups,
  takesLocation,
} from '../../lib/faultLocations';

function LocationChip({ label, active, onClick }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={`rounded-full px-3 py-1 text-xs font-medium ring-1 transition ${
        active
          ? 'bg-indigo-600 text-white ring-indigo-600'
          : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'
      }`}
    >
      {label}
    </button>
  );
}

/**
 * The quantity stepper. Deliberately a stepper and not a free number box: the count is almost always
 * 1–4, and a stepper cannot produce the slipped keypress ("22") that a text field can. Typing is
 * still allowed for the rare large count, clamped to the catalog's max on the way through.
 */
function QuantityStepper({ value, max, onChange, label }) {
  const n = Math.max(1, Number(value) || 1);
  const step = (delta) => onChange(Math.max(1, Math.min(max, n + delta)));

  return (
    <div className="flex items-center gap-1.5">
      <span className="text-[11px] font-medium text-slate-500">{label}</span>
      <div className="inline-flex items-center rounded-lg ring-1 ring-slate-300">
        <button
          type="button"
          onClick={() => step(-1)}
          disabled={n <= 1}
          aria-label={`${label} −`}
          className="px-2 py-1 text-sm font-semibold text-slate-500 transition hover:bg-slate-50 disabled:opacity-30"
        >
          −
        </button>
        <input
          type="number"
          min={1}
          max={max}
          value={n}
          onChange={(e) => onChange(Math.max(1, Math.min(max, Number(e.target.value) || 1)))}
          aria-label={label}
          className="w-10 border-x border-slate-200 bg-transparent py-1 text-center text-sm font-semibold text-slate-700 focus:outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none"
        />
        <button
          type="button"
          onClick={() => step(1)}
          disabled={n >= max}
          aria-label={`${label} +`}
          className="px-2 py-1 text-sm font-semibold text-slate-500 transition hover:bg-slate-50 disabled:opacity-30"
        >
          +
        </button>
      </div>
    </div>
  );
}

function FaultRow({ symptom, detail, groups, index, required, maxQuantity, onChange }) {
  const { t, tf, lang } = useI18n();
  const [query, setQuery] = useState('');
  const [open, setOpen] = useState(() => new Set());

  const picked = detail?.locations || [];
  const quantity = detail?.quantity || 1;
  const missing = required && picked.length === 0;

  const visible = useMemo(() => searchGroups(groups, query), [groups, query]);
  const searching = query.trim().length > 0;
  const isOpen = (g) => searching || open.has(g.key) || (g.locations || []).some((l) => picked.includes(l.key));

  const toggleGroup = (key) =>
    setOpen((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });

  const toggleLocation = (slug) =>
    onChange({
      quantity,
      locations: picked.includes(slug) ? picked.filter((s) => s !== slug) : [...picked, slug],
    });

  return (
    <div className={`rounded-xl border bg-white p-3 ${missing ? 'border-amber-300 bg-amber-50/40' : 'border-slate-200'}`}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm font-semibold text-slate-800">
          {symptom}
          {required && <span className="ms-1 text-rose-500" title={t('faultDetail.requiredHint')}>*</span>}
        </p>
        <QuantityStepper
          value={quantity}
          max={maxQuantity}
          label={t('faultDetail.quantity')}
          onChange={(q) => onChange({ quantity: q, locations: picked })}
        />
      </div>

      {/* THE PREVIEW. The one sentence this fault will be filed as, rendered from the same rule the
          backend uses — so what the inspector reads here is what every screen shows afterwards. */}
      <p className="mt-2 rounded-lg bg-slate-50 px-2.5 py-1.5 text-xs text-slate-600 ring-1 ring-inset ring-slate-200/70">
        <span className="text-slate-400">{t('faultDetail.preview')}</span>{' '}
        <strong className="font-semibold text-slate-800">{previewFor(symptom, { quantity, locations: picked }, index, lang)}</strong>
      </p>

      {/* Picked places, always visible and removable even with every group collapsed. */}
      {picked.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-1.5">
          {picked.map((slug) => (
            <span key={slug} className="inline-flex items-center gap-1 rounded-full bg-indigo-600 px-2.5 py-1 text-xs font-medium text-white">
              {index[slug] ? (lang === 'ar' && index[slug].label_ar ? index[slug].label_ar : index[slug].label) : slug}
              <button
                type="button"
                onClick={() => toggleLocation(slug)}
                aria-label={t('faultDetail.remove', { label: index[slug]?.label || slug })}
                className="text-indigo-200 transition hover:text-white"
              >
                ×
              </button>
            </span>
          ))}
        </div>
      )}

      {missing && (
        <p className="mt-2 flex items-start gap-1.5 text-[11px] text-amber-800">
          <Icon.Alert className="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-500" />
          {tf('faultDetail.missing', 'Say where on the car — this fault cannot be filed without a location.')}
        </p>
      )}

      <div className="mt-2 space-y-2">
        <div className="relative">
          <Icon.Search className="pointer-events-none absolute inset-y-0 start-3 my-auto h-4 w-4 text-slate-400" />
          <input
            type="search"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder={t('faultDetail.searchPlaceholder')}
            aria-label={t('faultDetail.searchPlaceholder')}
            className="w-full rounded-lg border border-slate-300 bg-white py-1.5 pe-3 ps-9 text-xs text-slate-700 placeholder:text-slate-400 focus:border-indigo-400 focus:outline-none focus:ring-1 focus:ring-indigo-400"
          />
        </div>

        <div className="divide-y divide-slate-100 overflow-hidden rounded-lg border border-slate-200">
          {visible.map((group) => {
            const groupOpen = isOpen(group);
            const count = (group.locations || []).filter((l) => picked.includes(l.key)).length;
            return (
              <div key={group.key}>
                <button
                  type="button"
                  onClick={() => toggleGroup(group.key)}
                  aria-expanded={groupOpen}
                  className={`flex w-full items-center gap-2 px-2.5 py-2 text-start transition ${groupOpen ? 'bg-slate-50/80' : 'hover:bg-slate-50'}`}
                >
                  <Icon.ChevronDown className={`h-3.5 w-3.5 shrink-0 text-slate-400 transition-transform ${groupOpen ? '' : '-rotate-90 rtl:rotate-90'}`} />
                  <span className="truncate text-xs font-semibold text-slate-700">
                    {lang === 'ar' && group.label_ar ? group.label_ar : group.label}
                  </span>
                  <span className="flex-1" />
                  {count > 0 && (
                    <span className="inline-flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-indigo-600 px-1 text-[10px] font-bold text-white">
                      {count}
                    </span>
                  )}
                </button>
                {groupOpen && (
                  <div className="flex flex-wrap gap-1.5 border-t border-slate-100 bg-white px-2.5 pb-2.5 pt-2">
                    {(group.locations || []).map((loc) => (
                      <LocationChip
                        key={loc.key}
                        label={lang === 'ar' && loc.label_ar ? loc.label_ar : loc.label}
                        active={picked.includes(loc.key)}
                        onClick={() => toggleLocation(loc.key)}
                      />
                    ))}
                  </div>
                )}
              </div>
            );
          })}

          {visible.length === 0 && (
            <p className="px-2.5 py-3 text-center text-[11px] text-slate-400">
              {t('faultDetail.noMatch', { query })}
            </p>
          )}
        </div>
      </div>
    </div>
  );
}

export default function FaultDetailPicker({
  symptoms = [],
  groups = [],
  policy = {},
  value = {},
  onChange,
  maxQuantity = DEFAULT_MAX_QUANTITY,
}) {
  const { t } = useI18n();
  const index = useMemo(() => locationIndex(groups), [groups]);

  // Only faults that HAVE a place get a row. A type whose policy is `none` is not a gap in the
  // report — it is a fault with nowhere to point at, and showing it an empty picker would train
  // people to click past the ones that matter.
  const rows = useMemo(
    () => symptoms.filter((s) => s && takesLocation(policy, s)),
    [symptoms, policy],
  );

  const skipped = symptoms.filter((s) => s && !takesLocation(policy, s));

  if (rows.length === 0) {
    return (
      <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-400 ring-1 ring-inset ring-slate-200/70">
        {symptoms.length === 0
          ? t('faultDetail.emptyNoFindings')
          : t('faultDetail.emptyNoLocatable')}
      </p>
    );
  }

  const set = (symptom, next) => onChange({ ...value, [symptom]: next });

  return (
    <div className="space-y-2.5">
      {rows.map((s) => (
        <FaultRow
          key={s}
          symptom={s}
          detail={value[s]}
          groups={groups}
          index={index}
          required={requiresLocation(policy, s)}
          maxQuantity={maxQuantity}
          onChange={(next) => set(s, next)}
        />
      ))}

      {/* Named, not hidden: a fault that was skipped here should read as a deliberate answer
          ("nowhere to point at"), never as something the screen forgot to ask about. */}
      {skipped.length > 0 && (
        <p className="text-[11px] text-slate-400">
          {t('faultDetail.skipped', { list: skipped.join(', ') })}
        </p>
      )}
    </div>
  );
}

export { policyFor };
