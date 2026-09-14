import { useMemo, useState } from 'react';
import Icon from '../ui/Icon';
import { useI18n } from '../../i18n/I18nContext';

/**
 * WHAT WAS DAMAGED, AND WHERE — picked from the two curated vocabularies, never typed.
 *
 * ── WHY THIS REPLACED A TEXT BOX ───────────────────────────────────────────────────────────────
 *
 * A typed area name cannot be grouped. The moment one person writes "front bumper", another writes
 * "Front Bumper" and a third writes "f. bumper", the question "what does accident damage actually
 * cost us, and on which panel?" stops having an answer — and nothing anywhere reports that it
 * stopped. The damage is still recorded; it is simply no longer countable.
 *
 * So both axes come from vocabularies the rest of the app already curates:
 *   WHAT   `damage_catalog`    — Bumper Damage, Rim Scratch, Broken Glass… (bodywork/interior/tyres)
 *   WHERE  `vehicle_locations` — Front Bumper, Rear-Left Door, Windscreen… ([[fault-location-axis]])
 *
 * The same two-axis shape the fault side has used since the findings picker, so a person who knows
 * one knows the other.
 *
 * ── WHERE IS OPTIONAL, AND THAT IS DELIBERATE ──────────────────────────────────────────────────
 *
 * Some damage genuinely has no single place — "Paint Peeling / Fading", "Vandalism". Forcing a
 * location there would make somebody pick a wrong one to get past the form, which is worse than no
 * location at all. The catalog row's own `area_key` is used to float the plausible places to the
 * top rather than to restrict the list, because a bumper scrape that somehow landed on a door is a
 * real thing that must still be recordable.
 *
 * ── THE ESCAPE HATCH IS THE CATALOG, NOT A TEXT BOX ────────────────────────────────────────────
 *
 * The catalog carries deliberate generics — "Body Damage", "Interior Damage", "Accident Damage" —
 * so anything unusual has a home. Specifics go in the DESCRIPTION field beside this picker, which is
 * detail rather than category. That is the same division the rest of the app draws: a note alongside
 * a named thing is welcome; a note INSTEAD of one is how the vocabulary dies.
 *
 * Shape contract:
 *   damageGroups   : [{ key, label, label_ar, items: [{ id, name, name_ar, area_key }] }]
 *   locationGroups : [{ key, label, locations: [{ id, name, name_ar, area_key }] }]
 *   value          : { damage_catalog_id, vehicle_location_id }
 *   onChange       : (nextValue) => void
 */

function Chip({ label, active, onClick, tone = 'indigo' }) {
  const on = tone === 'rose'
    ? 'bg-rose-600 text-white ring-rose-600'
    : 'bg-indigo-600 text-white ring-indigo-600';
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={`rounded-full px-3 py-1 text-xs font-medium ring-1 transition ${
        active ? on : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'
      }`}
    >
      {label}
    </button>
  );
}

/** Fold a search string so Arabic behaves the way Latin does — same rule as the fault picker. */
function fold(s) {
  return (s || '')
    .toLowerCase()
    .replace(/[ً-ْٰـ]/g, '')
    .replace(/[آأإاٱ]/g, 'ا')
    .replace(/ة/g, 'ه')
    .replace(/ى/g, 'ي');
}

export default function AccidentDamagePicker({
  damageGroups = [],
  locationGroups = [],
  value = {},
  onChange,
}) {
  const { t, lang } = useI18n();
  const [q, setQ] = useState('');

  const name = (o) => (lang === 'ar' && o?.name_ar) || o?.name || '';
  const set = (patch) => onChange?.({ ...value, ...patch });

  const needle = fold(q.trim());
  const shownDamage = useMemo(() => {
    if (!needle) return damageGroups;
    return damageGroups
      .map((g) => ({ ...g, items: g.items.filter((i) => fold(`${i.name} ${i.name_ar || ''}`).includes(needle)) }))
      .filter((g) => g.items.length);
  }, [damageGroups, needle]);

  // The damage type currently picked, so the WHERE list can float its plausible places first.
  const picked = useMemo(
    () => damageGroups.flatMap((g) => g.items).find((i) => i.id === value.damage_catalog_id),
    [damageGroups, value.damage_catalog_id],
  );

  /**
   * Locations ordered so the ones matching the damage type's own `area_key` come first. A SORT, not
   * a filter — the catalog's hint is coarse ("window", "wheel") and being wrong about it must not
   * make a real location unreachable.
   */
  const orderedLocationGroups = useMemo(() => {
    if (!picked?.area_key) return locationGroups;
    const hint = picked.area_key;
    const score = (l) => (l.area_key && l.area_key === hint ? 0 : 1);
    return [...locationGroups]
      .map((g) => ({ ...g, locations: [...g.locations].sort((a, b) => score(a) - score(b)) }))
      .sort((a, b) => Math.min(...a.locations.map(score)) - Math.min(...b.locations.map(score)));
  }, [locationGroups, picked]);

  return (
    <div className="space-y-4">
      {/* ── WHAT ────────────────────────────────────────────────────────────────────────────── */}
      <div>
        <span className="mb-1.5 block text-sm font-medium text-slate-700">
          {t('What was damaged?')}<span className="ms-0.5 text-red-500">*</span>
        </span>

        <div className="rounded-xl border border-slate-200 bg-white">
          <div className="relative border-b border-slate-100 p-2">
            <Icon.Search className="pointer-events-none absolute inset-y-0 start-4 my-auto h-4 w-4 text-slate-400" />
            <input
              type="search"
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder={t('Search damage types…')}
              className="w-full rounded-lg border-0 bg-slate-50 py-2 ps-9 pe-3 text-sm text-slate-800 placeholder:text-slate-400 focus:bg-white focus:ring-2 focus:ring-rose-500"
            />
          </div>

          <div className="max-h-52 overflow-y-auto p-2">
            {shownDamage.length === 0 && (
              <p className="px-2 py-6 text-center text-sm text-slate-400">{t('No damage type matches that.')}</p>
            )}
            {shownDamage.map((g) => (
              <div key={g.key} className="mb-2 last:mb-0">
                <p className="px-1 pb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                  {(lang === 'ar' && g.label_ar) || g.label}
                </p>
                <div className="flex flex-wrap gap-1.5">
                  {g.items.map((i) => (
                    <Chip
                      key={i.id}
                      label={name(i)}
                      tone="rose"
                      active={value.damage_catalog_id === i.id}
                      // Tapping the active one clears it, so a mis-tap is undoable without a reset.
                      onClick={() => set({
                        damage_catalog_id: value.damage_catalog_id === i.id ? null : i.id,
                      })}
                    />
                  ))}
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* ── WHERE ───────────────────────────────────────────────────────────────────────────── */}
      <div>
        <div className="mb-1.5 flex items-baseline justify-between gap-2">
          <span className="text-sm font-medium text-slate-700">{t('Where on the car?')}</span>
          <span className="text-[11px] text-slate-400">
            {t('Optional — some damage has no one place')}
          </span>
        </div>

        <div className="max-h-44 overflow-y-auto rounded-xl border border-slate-200 bg-white p-2">
          {orderedLocationGroups.map((g) => (
            <div key={g.key} className="mb-2 last:mb-0">
              <p className="px-1 pb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                {t(g.label)}
              </p>
              <div className="flex flex-wrap gap-1.5">
                {g.locations.map((l) => (
                  <Chip
                    key={l.id}
                    label={name(l)}
                    active={value.vehicle_location_id === l.id}
                    onClick={() => set({
                      vehicle_location_id: value.vehicle_location_id === l.id ? null : l.id,
                    })}
                  />
                ))}
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* WHAT WILL BE FILED, shown before it is — the same courtesy the fault detail picker gives.
          The server derives this label from the very same two ids, so the preview cannot drift
          from the record. */}
      {picked && (
        <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 ring-1 ring-inset ring-slate-100">
          {t('Will be recorded as')}:{' '}
          <span className="font-semibold text-slate-800">
            {[name(picked), name(locationGroups.flatMap((g) => g.locations)
              .find((l) => l.id === value.vehicle_location_id))].filter(Boolean).join(' — ')}
          </span>
        </p>
      )}
    </div>
  );
}
