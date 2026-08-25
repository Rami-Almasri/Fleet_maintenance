// WHAT THIS CAR COSTS TO RUN — the /cost-intelligence figures for one car, on the car's own page.
//
// The fleet board answers "which cars cost the most?". Someone already looking at ONE car is asking
// the other half of the question: what does THIS car cost per km, per day, per rental — and is that
// good or bad? So every figure is shown beside the fleet's own, and the gap between them is stated in
// words ("above the fleet" / "below the fleet") rather than left for the reader to work out.
//
// One call: GET /intelligence/cost?vehicle_id=<id> returns this car's row plus the whole-fleet summary,
// so the comparison can never drift from the board it came from. Nothing is recomputed here.
//
// Gated on `insights.view` and SHOW_FLEET_INTELLIGENCE — renders nothing without either, so a viewer
// who cannot open /cost-intelligence does not see its numbers by another door.

import { useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { SectionCard } from '../../components/ui/Table';
import { Skeleton } from '../../components/ui/Skeleton';
import { InfoTip } from '../../components/ui/Tooltip';
import Icon from '../../components/ui/Icon';
import { usePermissions } from '../../hooks/usePermissions';
import { SHOW_FLEET_INTELLIGENCE } from '../../config/features';
import { aed2, num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

// One cost figure: this car's number, the fleet's beside it, and which side of the fleet it falls on.
// A null car figure is UNKNOWN (unmeasured distance, no rentals) — shown as a dash, never as zero.
function CostFigure({ label, tip, value, fleet, format }) {
  const { t } = useI18n();
  const known = value != null;
  const comparable = known && fleet != null && fleet > 0;
  const above = comparable && value > fleet;
  const same = comparable && value === fleet;

  return (
    <div className="rounded-xl bg-slate-50/70 p-3 ring-1 ring-inset ring-slate-100">
      <dt className="flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide text-slate-400">
        {label}
        {tip && <InfoTip content={tip} />}
      </dt>
      <dd className="mt-1 text-lg font-bold tabular-nums text-slate-900">
        {known ? format(value) : <span className="text-slate-300">—</span>}
      </dd>
      {fleet != null && (
        <p className="mt-0.5 text-[11px] text-slate-400">
          {t('vehicleProfile.cost.fleet', { value: format(fleet) })}
        </p>
      )}
      {comparable && !same && (
        <p className={`mt-0.5 text-[11px] font-semibold ${above ? 'text-red-600' : 'text-emerald-600'}`}>
          {above ? t('vehicleProfile.cost.aboveFleet') : t('vehicleProfile.cost.belowFleet')}
        </p>
      )}
    </div>
  );
}

export default function VehicleCostIntelligence({ vehicleId }) {
  const { t } = useI18n();
  const { can } = usePermissions();
  const allowed = SHOW_FLEET_INTELLIGENCE && can('insights.view');

  // The fetch itself is the gate: a viewer without `insights.view` never calls the endpoint (hooks
  // can't be skipped, so the guard lives inside the fetcher rather than around the hook).
  const fetcher = useCallback(async () => {
    if (!allowed || !vehicleId) return null;
    const { data } = await api.get('/intelligence/cost', { params: { vehicle_id: vehicleId } });
    return data.data;
  }, [vehicleId, allowed]);
  const { data, loading, error } = useFetch(fetcher, [vehicleId, allowed]);

  if (!allowed) return null;

  const row = data?.vehicles?.[0] || null;
  const s = data?.summary || {};

  return (
    <SectionCard
      title={
        <span className="flex items-center gap-1.5">
          {t('vehicleProfile.cost.intelTitle')}
          <InfoTip content={t('vehicleProfile.cost.intelTooltip')} />
        </span>
      }
      subtitle={t('vehicleProfile.cost.intelSubtitle')}
      actions={(
        <Link to="/vehicles?tab=cost" className="inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-700">
          {t('vehicleProfile.cost.wholeFleet')} <Icon.ArrowRight className="h-3.5 w-3.5 rtl:-scale-x-100" />
        </Link>
      )}
    >
      {loading ? (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
          {[0, 1, 2, 3, 4].map((i) => <Skeleton key={i} className="h-20 rounded-xl" />)}
        </div>
      ) : error ? (
        <p className="py-4 text-xs text-slate-400">{error}</p>
      ) : !row ? (
        <p className="py-4 text-xs text-slate-400">{t('vehicleProfile.cost.notMeasured')}</p>
      ) : (
        <>
          <dl className="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <CostFigure
              label={t('vehicleProfile.cost.maintenance')}
              tip={t('vehicleProfile.cost.maintenanceTip')}
              value={row.maintenance_cost}
              format={aed2}
            />
            <CostFigure
              label={t('vehicleProfile.cost.perKm')}
              tip={t('vehicleProfile.cost.perKmTip')}
              value={row.cost_per_km}
              fleet={s.fleet_cost_per_km}
              format={aed2}
            />
            <CostFigure
              label={t('vehicleProfile.cost.perDay')}
              tip={t('vehicleProfile.cost.perDayTip')}
              value={row.cost_per_day}
              fleet={s.fleet_cost_per_day}
              format={aed2}
            />
            <CostFigure
              label={t('vehicleProfile.cost.perRental')}
              tip={t('vehicleProfile.cost.perRentalTip')}
              value={row.cost_per_rental}
              fleet={s.fleet_cost_per_rental}
              format={aed2}
            />
            <CostFigure
              label={t('vehicleProfile.cost.distance')}
              tip={t('vehicleProfile.cost.distanceTip')}
              value={row.distance_km}
              format={num}
            />
          </dl>
          <p className="mt-3 text-xs leading-relaxed text-slate-400">
            {t('vehicleProfile.cost.intelOrigin')}
          </p>
        </>
      )}
    </SectionCard>
  );
}
