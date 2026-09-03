// The maintenance pipeline chart strip. Three questions a manager asks about a shop they are not
// going to scroll through car by car:
//
//   1. Pipeline by stage   → the funnel profile, in journey order (not ranked —
//                            the order IS the process, so it must not be re-sorted)
//   2. Longest in the workshop → the specific cars that have stalled at the garage
//   3. Who's holding it    → load per responsible role
//
// Everything derives from the lanes it is handed, so it can never disagree with the board those
// lanes came from. Rendered on the Dashboard by PipelinePanel.

import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { SectionCard } from '../ui/Table';
import Icon from '../ui/Icon';
import RankedBar from '../ui/RankedBar';
import PieChart from '../ui/PieChart';
import { fmtDuration, stageSeconds } from '../workflow/meta';
import { num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

// Role → the chart palette key matching its chip colour on the cards below.
const ROLE_COLOR = {
  inspector: 'amber',
  supervisor: 'purple',
  driver: 'blue',
  garage: 'orange',
  none: 'slate',
};
const ROLE_LABEL = {
  inspector: 'Inspector',
  supervisor: 'Supervisor',
  driver: 'Driver',
  garage: 'Garage',
  none: 'On hold',
};

// The lane that IS the workshop — the only one where "how long has it sat here" measures real repair
// dwell time rather than a handover step. This is the board COLUMN key (see config/maintenanceLanes.js),
// which is stable; the lane's display name is not.
const WORKSHOP_LANE = 'under_repair';

export default function PipelineAnalytics({ lanes = [] }) {
  const { t, isRTL } = useI18n();

  // Picking a stage doesn't assume what the reader wants — two different questions hide behind the
  // same tile ("which cars are these?" and "take me to that lane"), so the click offers BOTH rather
  // than guessing. `picked` is the lane key chosen; `showCars` is the reader having chosen to look
  // at the list here instead of leaving the Dashboard.
  const [picked, setPicked] = useState(null);
  const [showCars, setShowCars] = useState(false);
  const pickedLane = useMemo(() => lanes.find((l) => l.key === picked) || null, [lanes, picked]);
  const selectStage = (row) => {
    setPicked((cur) => (cur === row.key ? null : row.key));
    setShowCars(false);
  };
  const closeStage = () => { setPicked(null); setShowCars(false); };

  // The funnel profile — kept in the lanes' own order, because that order is the
  // repair journey. Sorting it by size would destroy the meaning.
  const byStage = useMemo(
    () =>
      lanes.map((l) => ({
        key: l.key,
        label: l.name,
        value: l.tickets.length,
        color: l.tone,
        role: ROLE_LABEL[l.role] || l.role,
      })),
    [lanes],
  );

  // The cars that have sat longest AT THE GARAGE. Deliberately scoped to the In-Workshop lane: the
  // other lanes are transitional (a car is "in transit" or "awaiting pickup" for hours, not weeks),
  // so ranking them together buried the repairs that are genuinely stuck behind short-lived handover
  // steps. Time in the workshop lane IS the repair's dwell time — the number worth chasing a garage over.
  const stalled = useMemo(() => {
    const workshop = lanes.find((l) => l.key === WORKSHOP_LANE);
    return (workshop?.tickets || [])
      .map((tk) => ({
        key: tk.id,
        label: tk.plate || `#${tk.id}`,
        sub: tk.ops?.responsibility?.garage || workshop.name,
        to: `/maintenance-workflow/${tk.id}`,
        value: stageSeconds(tk) ?? 0,
        color: workshop.tone,
        car: tk.car,
      }))
      .sort((a, b) => b.value - a.value)
      .slice(0, 8);
  }, [lanes]);

  // Load per responsible role — who the pipeline is currently waiting on.
  const byRole = useMemo(() => {
    const totals = {};
    lanes.forEach((l) => {
      if (!l.tickets.length) return;
      totals[l.role] = (totals[l.role] || 0) + l.tickets.length;
    });
    return Object.entries(totals)
      .map(([role, value]) => ({ label: t(ROLE_LABEL[role] || role), value, color: ROLE_COLOR[role] || 'slate' }))
      .sort((a, b) => b.value - a.value);
  }, [lanes, t]);

  const total = byStage.reduce((n, s) => n + s.value, 0);
  if (!total) return null;

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        title={t('Pipeline by stage')}
        subtitle={t('Cars at each step, in journey order')}
        bodyClass="p-5"
      >
        <RankedBar
          items={byStage}
          format={(n) => num(Math.round(n))}
          valueLabel={t('Cars')}
          labelWidth={128}
          valueWidth={44}
          onSelect={selectStage}
          selectedKey={picked}
          tooltip={(r) => t('Owned by {role}', { role: t(r.role) })}
          empty={t('No cars in the pipeline.')}
        />

        {/* The chooser the stage click opens: look at the cars here, or go to the lane on the board. */}
        {pickedLane && (
          <div className="mt-3 rounded-xl bg-slate-50 p-2.5 ring-1 ring-slate-200/70">
            <div className="mb-2 flex items-center justify-between gap-2">
              <p className="flex min-w-0 items-baseline gap-1.5">
                <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: pickedLane.tone }} />
                <span className="truncate text-[13px] font-semibold text-slate-800">{pickedLane.name}</span>
                <span className="shrink-0 text-[11px] font-medium tabular-nums text-slate-400">
                  {pickedLane.tickets.length} {t('Cars').toLowerCase()}
                </span>
              </p>
              <button
                type="button"
                onClick={closeStage}
                aria-label={t('Close')}
                className="focus-ring-self flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-slate-400 hover:bg-slate-200/70 hover:text-slate-600"
              >
                <Icon.X className="h-3 w-3" />
              </button>
            </div>

            {!showCars ? (
              <div className="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                <button
                  type="button"
                  onClick={() => setShowCars(true)}
                  disabled={!pickedLane.tickets.length}
                  className="focus-ring-self flex items-center gap-2 rounded-lg bg-white px-2.5 py-2 text-start text-xs font-semibold text-slate-700 ring-1 ring-slate-200 transition hover:bg-indigo-50 hover:text-indigo-700 hover:ring-indigo-200 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  <Icon.Car className="h-3.5 w-3.5 shrink-0 text-slate-400" />
                  <span className="truncate">{t('Show the cars in this stage')}</span>
                </button>
                <Link
                  to={`/maintenance-workflow?stage=${pickedLane.key}`}
                  className="focus-ring-self flex items-center gap-2 rounded-lg bg-white px-2.5 py-2 text-xs font-semibold text-slate-700 ring-1 ring-slate-200 transition hover:bg-indigo-50 hover:text-indigo-700 hover:ring-indigo-200"
                >
                  <Icon.ArrowRight className="h-3.5 w-3.5 shrink-0 text-slate-400" />
                  <span className="truncate">{t('Take me to this stage')}</span>
                </Link>
              </div>
            ) : (
              <div>
                <ul className="max-h-56 space-y-1 overflow-y-auto">
                  {pickedLane.tickets.map((tk) => (
                    <li key={tk.id}>
                      <Link
                        to={`/maintenance-workflow/${tk.id}`}
                        className="flex items-center gap-2 rounded-lg bg-white px-2.5 py-1.5 ring-1 ring-slate-200/70 transition hover:bg-indigo-50/60 hover:ring-indigo-200"
                      >
                        <span className="min-w-0 flex-1">
                          <span className="block truncate font-mono text-[12px] font-semibold text-slate-900">
                            {tk.plate || `#${tk.id}`}
                          </span>
                          <span className="block truncate text-[11px] text-slate-400">
                            {[tk.car, tk.ops?.responsibility?.garage].filter(Boolean).join(' · ') || '—'}
                          </span>
                        </span>
                        <span className="shrink-0 text-[11px] font-medium tabular-nums text-slate-400">
                          {fmtDuration(stageSeconds(tk) ?? 0)}
                        </span>
                      </Link>
                    </li>
                  ))}
                </ul>
                <div className="mt-2 flex items-center justify-between gap-2">
                  <button
                    type="button"
                    onClick={() => setShowCars(false)}
                    className="focus-ring-self rounded text-[11px] font-semibold text-slate-500 hover:text-slate-700"
                  >
                    {isRTL ? '→' : '←'} {t('Back')}
                  </button>
                  <Link
                    to={`/maintenance-workflow?stage=${pickedLane.key}`}
                    className="text-[11px] font-semibold text-indigo-600 hover:text-indigo-700"
                  >
                    {t('Take me to this stage')} {isRTL ? '←' : '→'}
                  </Link>
                </div>
              </div>
            )}
          </div>
        )}
      </SectionCard>

      <SectionCard
        title={t('Longest in the workshop')}
        subtitle={t('Cars that have stalled at the garage')}
        bodyClass="p-5"
      >
        <RankedBar
          items={stalled}
          showRank
          format={fmtDuration}
          valueLabel={t('At garage')}
          labelWidth={116}
          valueWidth={68}
          tooltip={(r) => [r.car, r.sub].filter(Boolean).join(' · ')}
          empty={t('No cars are in the workshop right now.')}
        />
      </SectionCard>

      <SectionCard
        title={t("Who's holding the work")}
        subtitle={t('Open cars by responsible role')}
        bodyClass="flex items-center justify-center p-5"
      >
        {byRole.length ? (
          <PieChart segments={byRole} size={150} />
        ) : (
          <div className="flex h-[150px] items-center justify-center text-sm text-slate-400">
            {t('Nobody has work assigned.')}
          </div>
        )}
      </SectionCard>
    </div>
  );
}
