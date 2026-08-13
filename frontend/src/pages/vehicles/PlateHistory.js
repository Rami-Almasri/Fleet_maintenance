import { useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useI18n } from '../../i18n/I18nContext';
import { SectionCard } from '../../components/ui/Table';
import Badge from '../../components/ui/Badge';
import Icon from '../../components/ui/Icon';
import { Skeleton } from '../../components/ui/Skeleton';
import { fmtDate } from '../../lib/format';

/**
 * Plate History — every vehicle that has ever carried this car's plate. In the UAE a plate is
 * re-issued to a new car after the old one is sold, so one plate spans several physical vehicles.
 *
 * This section makes that history DISCOVERABLE without ever merging it: each holder keeps its own
 * maintenance / inspection / repair records forever (they live on that vehicle_id). All we show
 * here is the timeline of who held the plate when, current holder first, with links to navigate
 * between the vehicles. It renders nothing for a plate that was never reused.
 *
 * Presentational: the parent (VehicleProfile) owns the fetch of GET /Vehicle/{id}/plate-history
 * (so it can decide whether to surface the dedicated "Plate History" tab) and passes the payload in.
 */
export default function PlateHistory({ data = null, loading = false }) {
  const { t } = useI18n();
  const holders = useMemo(() => data?.holders || [], [data]);
  const isReused = !!data?.is_reused;

  // Nothing to show for a plate that only ever belonged to one car.
  if (!loading && !isReused) return null;

  const roleBadge = (h) => {
    if (h.is_current) return <Badge tone="emerald" dot>{t('Current plate holder')}</Badge>;
    if (h.is_gone) return <Badge tone="gray" dot>{t('Sold vehicle · history')}</Badge>;
    return <Badge tone="amber" dot>{t('Previous plate holder')}</Badge>;
  };

  return (
    <SectionCard
      title={t('Plate History')}
      subtitle={t('This plate was reused across more than one vehicle. Each car below is a different physical vehicle and keeps its own records — nothing is merged.')}
      actions={(data?.plate_display || data?.plate_no) ? <span className="opx-plate" style={{ fontSize: 12, padding: '2px 9px' }}>{data.plate_display || data.plate_no}</span> : null}
      bodyClass="p-4 sm:p-5"
    >
      {loading ? (
        <div className="space-y-2"><Skeleton className="h-16 rounded-lg" /><Skeleton className="h-16 rounded-lg" /></div>
      ) : (
        <>
          <div className="mb-4 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-xs leading-relaxed text-amber-700">
            <span className="mt-px">⚠️</span>
            <span>
              {t('The history below belongs to different physical vehicles that shared this plate number over time. A record stays attached to the vehicle it happened on — the plate moving to a new car never moves the old car’s history.')}
            </span>
          </div>

          {/* Vertical timeline: current holder first, previous holders below. */}
          <ol className="relative ms-2 border-s border-slate-200">
            {holders.map((h) => (
              <li key={h.vehicle_id} className="relative mb-5 ps-6 last:mb-0">
                <span
                  className={`absolute -start-[7px] top-1.5 h-3 w-3 rounded-full ring-4 ring-white ${
                    h.is_current ? 'bg-emerald-500' : h.is_gone ? 'bg-slate-400' : 'bg-amber-500'
                  }`}
                />
                <div className={`rounded-xl border p-3.5 ${h.is_self ? 'border-cyan-300 bg-cyan-50/40' : 'border-slate-200 bg-white'}`}>
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex flex-wrap items-center gap-2">
                      {roleBadge(h)}
                      {h.is_self && <Badge tone="cyan">{t('You are here')}</Badge>}
                    </div>
                    <span className="text-xs text-slate-400">
                      {h.from_date ? fmtDate(h.from_date) : '—'} <span className="inline-block rtl:-scale-x-100">→</span> {h.to_date ? fmtDate(h.to_date) : t('present')}
                    </span>
                  </div>

                  <div className="mt-2 flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                    {(h.plate_display || h.plate_no) && (
                      <span className="opx-plate" style={{ fontSize: 11, padding: '1px 7px' }}>{h.plate_display || h.plate_no}</span>
                    )}
                    <span className="text-sm font-semibold text-slate-800">
                      {[h.make, h.model].filter(Boolean).join(' ') || t('Vehicle')}
                    </span>
                    {h.year && <span className="text-xs text-slate-400">{h.year}</span>}
                    {h.color && <span className="text-xs text-slate-400">· {h.color}</span>}
                    <span className="text-xs text-slate-400">· {h.status_label || h.status}</span>
                  </div>

                  {h.vin && <p className="mono mt-0.5 text-[11px] text-slate-400">{h.vin}</p>}

                  <div className="mt-2.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
                    <span className="inline-flex items-center gap-1"><Icon.Wrench className="h-3 w-3" /> {t('{n} maintenance', { n: h.maintenance_count })}</span>
                    <span className="inline-flex items-center gap-1"><Icon.Shield className="h-3 w-3" /> {h.inspection_count === 1 ? t('1 inspection') : t('{n} inspections', { n: h.inspection_count })}</span>
                    <span className="inline-flex items-center gap-1"><Icon.Check className="h-3 w-3" /> {h.repair_count === 1 ? t('1 repair') : t('{n} repairs', { n: h.repair_count })}</span>
                    {!h.is_self && (
                      <Link to={`/vehicles/${h.vehicle_id}`} className="ms-auto inline-flex items-center gap-1 font-medium text-cyan-700 hover:underline">
                        {t('Open this vehicle')} <Icon.ArrowRight className="h-3 w-3 rtl:-scale-x-100" />
                      </Link>
                    )}
                  </div>

                  {h.confidence && h.confidence !== 'high' && (
                    <p className="mt-2 text-[11px] italic text-slate-400">
                      {h.confidence === 'low'
                        ? t('Current holder not yet confirmed for this plate — pending review.')
                        : h.note || t('Timeline derived from available data.')}
                    </p>
                  )}
                </div>
              </li>
            ))}
          </ol>
        </>
      )}
    </SectionCard>
  );
}
