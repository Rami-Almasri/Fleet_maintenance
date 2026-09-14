// The small, repeated pieces of a Fleet Registry row: the car's picture, its live state as one
// pill, and where it currently is.
//
// Each of these is a CLAIM about a real vehicle, so each degrades to "we don't know" rather than to
// a plausible-looking default. A car we hold no photograph of gets its marque, then a silhouette —
// never another car's picture. A car whose whereabouts nothing records says so, rather than
// inheriting the branch most cars happen to sit at.

import { useState } from 'react';
import { carPhoto, brandLogo } from '../../lib/carAssets';
import { useI18n } from '../../i18n/I18nContext';

/* ------------------------------- thumbnail ------------------------------- */

/**
 * Three tiers, tried in order: the model's photograph, the marque's logo, a silhouette. `tier`
 * only ever moves forward, so a 404 on the photo falls to the logo and a 404 on the logo falls to
 * the silhouette without looping.
 */
export function VehicleThumb({ vehicle, className = '' }) {
  const [tier, setTier] = useState(0);
  const photo = carPhoto(vehicle.make, vehicle.model);
  const logo = brandLogo(vehicle.make, vehicle.model);
  const src = tier === 0 ? photo || logo : tier === 1 ? logo : null;

  // Skip straight past a tier we know has no asset, so the first render is already correct.
  if (tier === 0 && !photo && !logo) {
    return <Silhouette className={className} />;
  }
  if (!src) return <Silhouette className={className} />;

  const isLogo = src === logo;
  return (
    // The catalogue photographs are cut-outs on a transparent field, so they are CONTAINED, never
    // cropped: `object-cover` on a car that has been isolated from its background slices the nose
    // or the roof off, which is precisely the part that identifies it.
    <span className={`flex h-11 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-white ring-1 ring-slate-200/70 dark:bg-slate-800/60 dark:ring-slate-700 ${className}`}>
      <img
        src={src}
        alt=""
        aria-hidden="true"
        loading="lazy"
        className={isLogo ? 'h-6 w-10 object-contain' : 'h-full w-full object-contain p-0.5'}
        onError={() => setTier((n) => n + 1)}
      />
    </span>
  );
}

function Silhouette({ className = '' }) {
  return (
    <span className={`flex h-11 w-16 shrink-0 items-center justify-center rounded-lg bg-slate-50 text-slate-300 ring-1 ring-slate-200/70 dark:bg-slate-800 dark:text-slate-600 dark:ring-slate-700 ${className}`}>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
        <path d="M5 13l1.6-4.7A2 2 0 0 1 8.5 7h7a2 2 0 0 1 1.9 1.3L19 13m-14 0h14m-14 0a2 2 0 0 0-2 2v3a1 1 0 0 0 1 1h1m14-6a2 2 0 0 1 2 2v3a1 1 0 0 1-1 1h-1M7 17h.01M17 17h.01" />
      </svg>
    </span>
  );
}

/* --------------------------------- status -------------------------------- */

const PILL = {
  available: 'bg-emerald-50 text-emerald-700 ring-emerald-200/70 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/25',
  reserved: 'bg-blue-50 text-blue-700 ring-blue-200/70 dark:bg-blue-500/10 dark:text-blue-400 dark:ring-blue-500/25',
  rented: 'bg-violet-50 text-violet-700 ring-violet-200/70 dark:bg-violet-500/10 dark:text-violet-400 dark:ring-violet-500/25',
  maint: 'bg-amber-50 text-amber-700 ring-amber-200/70 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-500/25',
  idle: 'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-700/50 dark:text-slate-300 dark:ring-slate-600',
};

/**
 * ONE pill per car, and the order below is the whole rule.
 *
 * A car can be several things at once — out on rent AND owing the garage a visit is routine here.
 * The pill names the state that decides what can be done with the car next, which is why the
 * workshop outranks the rental: a car in a shop cannot be handed to the next customer whatever its
 * rental paperwork says. The states the pill loses are not thrown away; they are still on the row
 * (the deferred flag, the contract lines on the car's own page).
 */
export function vehicleState(v) {
  if (v.under_maintenance || v.operational_status === 'maintenance') return 'maint';
  if (v.rented) return 'rented';
  if (v.reserved) return 'reserved';
  if (v.available) return 'available';
  return 'idle';
}

export function StatusPill({ vehicle }) {
  const { t } = useI18n();
  const state = vehicleState(vehicle);
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${PILL[state]}`}>
      <span className="h-1.5 w-1.5 rounded-full bg-current" />
      {t(`vehicles.state.${state}`)}
    </span>
  );
}

/* -------------------------------- location ------------------------------- */

/**
 * Where the car is, in the words someone would use on the phone.
 *
 * The `location` column is free text typed into the fleet sheet and is mostly unusable — a third of
 * it is null and much of the rest is a number someone parked in the wrong column. So the live
 * movement answers first (in the shop / on rent / being driven somewhere), and the typed value is
 * only believed when it actually reads like a place. Anything numeric is discarded rather than
 * printed as a location, because "600" is not somewhere a car can be.
 */
export function vehicleLocation(v, t) {
  if (v.operational_status === 'in_transit' && v.transit_destination) {
    return { text: t('vehicles.loc.transit', { to: v.transit_destination }), known: true };
  }
  if (v.under_maintenance || v.operational_status === 'maintenance') {
    // Which garage is not on this payload — the list endpoint doesn't ship it — so the honest
    // answer is the kind of place, not a name we'd be inventing.
    return { text: t('vehicles.loc.workshop'), known: true };
  }
  if (v.rented) return { text: t('vehicles.loc.onRent'), known: true };

  const raw = String(v.location || '').trim();
  if (raw && !/^\d+$/.test(raw)) {
    // Sheet text is lower-case as often as not ("dubai", "free parking").
    return { text: raw.replace(/\b\w/g, (ch) => ch.toUpperCase()), known: true };
  }
  return { text: t('vehicles.loc.unknown'), known: false };
}

export function LocationCell({ vehicle }) {
  const { t } = useI18n();
  const { text, known } = vehicleLocation(vehicle, t);
  return (
    <span className={`inline-flex items-center gap-1.5 text-sm ${known ? 'text-slate-600 dark:text-slate-300' : 'text-slate-400 dark:text-slate-500'}`}>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="h-3.5 w-3.5 shrink-0">
        <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 1 1 16 0z" />
        <circle cx="12" cy="10" r="3" />
      </svg>
      {text}
    </span>
  );
}
