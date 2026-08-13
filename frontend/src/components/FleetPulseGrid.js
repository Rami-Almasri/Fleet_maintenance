// "Fleet Pulse" — the dashboard's live status grid. One card per car, colour-coded
// by state (green on rent · blue available · amber in garage · red incident), with a
// repair-progress bar for cars in the shop. Replaces a flat table with a glanceable,
// command-center wall you can scan in a second. Filter chips + search on top.

import { useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { ProgressBar } from './ui/Progress';
import { Skeleton } from './ui/Skeleton';
import Icon from './ui/Icon';
import { num } from '../lib/format';
import { useI18n } from '../i18n/I18nContext';

// Tone → the card's accent colours (rail, ring, chip). Mirrors the app palette.
const PULSE = {
  emerald: { rail: '#10b981', dot: 'bg-emerald-500', chip: 'bg-emerald-50 text-emerald-700', ring: '' },
  blue:    { rail: '#3b82f6', dot: 'bg-blue-500',    chip: 'bg-blue-50 text-blue-700',       ring: '' },
  amber:   { rail: '#f59e0b', dot: 'bg-amber-500',   chip: 'bg-amber-50 text-amber-700',     ring: 'ring-1 ring-amber-100' },
  red:     { rail: '#ef4444', dot: 'bg-red-500',     chip: 'bg-red-50 text-red-700',         ring: 'ring-1 ring-red-200' },
  slate:   { rail: '#94a3b8', dot: 'bg-slate-400',   chip: 'bg-slate-100 text-slate-600',    ring: '' },
};

// Visual Condition Grade → the small corner flag on a card (perfect/green shows nothing).
const CONDITION_FLAG = {
  orange: { wrap: 'bg-orange-50 text-orange-700', dot: 'bg-orange-500', label: 'Cosmetic',   title: 'Minor cosmetic issues' },
  yellow: { wrap: 'bg-yellow-50 text-yellow-800', dot: 'bg-yellow-500', label: 'Maintenance', title: 'Maintenance needed — blocked from renting, route to garage' },
  red:    { wrap: 'bg-red-50 text-red-700',       dot: 'bg-red-500',    label: 'Critical',    title: 'Critical condition — not rentable' },
};

// The filter chips, in priority order. `match` maps a row → which bucket it belongs to.
const FILTERS = [
  { key: 'all',       label: 'All' },
  { key: 'incident',  label: 'Incidents',  tone: 'red' },
  { key: 'garage',    label: 'In garage',  tone: 'amber' },
  { key: 'rented',    label: 'On rent',    tone: 'emerald' },
  { key: 'available', label: 'Available',  tone: 'blue' },
];

function PulseCard({ v }) {
  const { t } = useI18n();
  const p = PULSE[v.tone] || PULSE.slate;
  const inGarage = v.completion != null;
  const flag = CONDITION_FLAG[v.condition_grade];
  return (
    <Link
      to={`/vehicles/${v.id}`}
      className={`hover-lift relative block overflow-hidden rounded-2xl border border-slate-200/60 bg-white p-4 shadow-soft ${p.ring}`}
    >
      <span className="absolute inset-y-0 start-0 w-1.5" style={{ background: p.rail }} />
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="truncate font-mono text-sm font-bold tracking-wider text-slate-800">{v.plate || '—'}</p>
          <p className="mt-0.5 truncate text-xs text-slate-500">{v.car || '—'}</p>
          {/* Visual Condition Grade flag — orange (cosmetic) / yellow (service due) / red
              (grounded); hidden when perfect. */}
          {flag && (
            <span
              title={v.condition_note || t(flag.title)}
              className={`mt-1 inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-semibold ${flag.wrap}`}
            >
              <span className={`h-1.5 w-1.5 rounded-full ${flag.dot}`} />
              {t(flag.label)}
            </span>
          )}
          {/* Deferred Maintenance — car pulled from the shop for a customer; still owes the garage. */}
          {v.is_deferred_maintenance && (
            <span
              title={v.deferred_maintenance_reason
                ? t('Owes maintenance — {reason}', { reason: v.deferred_maintenance_reason })
                : t('Pulled from the workshop for a customer — must go back to the garage.')}
              className="mt-1 inline-flex items-center gap-1 rounded-full bg-red-50 px-1.5 py-0.5 text-[10px] font-semibold text-red-700"
            >
              <span className="h-1.5 w-1.5 rounded-full bg-red-500" />
              🛠️↩️ {t('Owes maint.')}
            </span>
          )}
        </div>
        <span className={`inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold ${p.chip}`}>
          {v.state === 'incident' ? (
            <span className="relative flex h-2 w-2">
              <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-400 opacity-70" />
              <span className={`relative inline-flex h-2 w-2 rounded-full ${p.dot}`} />
            </span>
          ) : (
            <span className={`h-2 w-2 rounded-full ${p.dot}`} />
          )}
          {v.status}
        </span>
      </div>

      {inGarage ? (
        <div className="mt-4">
          <ProgressBar value={v.completion} max={100} tone={v.tone} showPct label={t('Repair progress')} />
          <p className="mt-2 flex items-center gap-1 text-[11px] font-medium text-slate-400">
            <Icon.Clock className="h-3 w-3" />
            {v.days_in_shop != null ? t('{n}d in the shop', { n: v.days_in_shop }) : t('In the shop')}
          </p>
        </div>
      ) : (
        <p className="mt-4 flex items-center gap-1 text-[11px] font-medium text-slate-400">
          <Icon.Gauge className="h-3 w-3" />
          {v.odometer != null ? `${num(v.odometer)} km` : '—'}
        </p>
      )}
    </Link>
  );
}

export default function FleetPulseGrid() {
  const { t } = useI18n();
  const fetcher = useCallback(async () => {
    const res = await api.get('/Dashboard/fleet-pulse');
    return res.data.data || [];
  }, []);
  const { data, loading, error } = useFetch(fetcher);
  const rows = useMemo(() => data || [], [data]);

  const [filter, setFilter] = useState('all');
  const [q, setQ] = useState('');

  const counts = useMemo(() => {
    const c = { all: rows.length, incident: 0, garage: 0, rented: 0, available: 0 };
    rows.forEach((r) => { if (c[r.state] != null) c[r.state] += 1; });
    return c;
  }, [rows]);

  const shown = useMemo(() => {
    const needle = q.trim().toLowerCase();
    return rows.filter((r) => {
      if (filter !== 'all' && r.state !== filter) return false;
      if (!needle) return true;
      return `${r.plate || ''} ${r.car || ''}`.toLowerCase().includes(needle);
    });
  }, [rows, filter, q]);

  if (error) {
    return <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>;
  }

  return (
    <div className="space-y-5">
      {/* controls: filter chips + search */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-wrap gap-2">
          {FILTERS.map((f) => {
            const on = filter === f.key;
            return (
              <button
                key={f.key}
                type="button"
                onClick={() => setFilter(f.key)}
                className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition ${
                  on ? 'bg-slate-900 text-white shadow-soft' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'
                }`}
              >
                {f.tone && <span className={`h-2 w-2 rounded-full ${PULSE[f.tone].dot}`} />}
                {t(f.label)}
                <span className={`tabular-nums ${on ? 'text-white/60' : 'text-slate-400'}`}>{counts[f.key] ?? 0}</span>
              </button>
            );
          })}
        </div>
        <div className="relative sm:w-64">
          <Icon.Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder={t('Search plate or model…')}
            className="w-full rounded-xl border border-slate-200 bg-white py-2 ps-9 pe-3 text-sm text-slate-700 shadow-soft outline-none placeholder:text-slate-400 focus:border-brand-400 focus:ring-2 focus:ring-brand-100"
          />
        </div>
      </div>

      {/* the grid */}
      {loading ? (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
          {Array.from({ length: 15 }).map((_, i) => <Skeleton key={i} className="h-[132px] rounded-2xl" />)}
        </div>
      ) : shown.length === 0 ? (
        <p className="py-12 text-center text-sm text-slate-400">{t('No vehicles match this view.')}</p>
      ) : (
        <div className="stagger grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
          {shown.map((v) => <PulseCard key={v.id} v={v} />)}
        </div>
      )}
    </div>
  );
}
