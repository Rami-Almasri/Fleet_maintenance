import { useCallback } from 'react';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { Card, Spinner } from '../ui/Misc';
import Badge from '../ui/Badge';
import Icon from '../ui/Icon';
import { useI18n } from '../../i18n/I18nContext';

// Per-status styling for a single readiness check. Mirrors the pass / warn / fail verdicts that
// VehicleReadinessService::evaluate() emits for each of its nine points.
const STATUS_META = {
  pass: { tone: 'green', dot: 'bg-emerald-500', ring: 'ring-emerald-100', Icon: Icon.Check, label: 'Pass' },
  warn: { tone: 'amber', dot: 'bg-amber-500', ring: 'ring-amber-100', Icon: Icon.Alert, label: 'Advisory' },
  fail: { tone: 'red', dot: 'bg-red-500', ring: 'ring-red-100', Icon: Icon.Alert, label: 'Blocker' },
};

// One point in the 9-check readiness list.
function CheckRow({ c }) {
  const { t } = useI18n();
  const m = STATUS_META[c.status] || STATUS_META.warn;
  const Glyph = m.Icon;
  return (
    <div className={`flex items-start gap-3 rounded-xl border border-slate-200/70 bg-white px-4 py-3 ring-1 ${m.ring}`}>
      <span className={`mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-white ${m.dot}`}>
        <Glyph className="h-4 w-4" />
      </span>
      <div className="min-w-0 flex-1">
        <div className="flex items-center justify-between gap-2">
          <p className="truncate text-sm font-semibold text-slate-800">{c.label}</p>
          <Badge tone={m.tone}>{t(m.label)}</Badge>
        </div>
        <p className="mt-0.5 text-xs text-slate-500">{c.detail}</p>
        {c.pillar && <p className="mt-0.5 text-[11px] font-medium uppercase tracking-wide text-slate-300">{c.pillar}</p>}
      </div>
    </div>
  );
}

/**
 * The vehicle's live 9-point Pre-Delivery Readiness — the same VehicleReadinessService::evaluate()
 * verdict the Rental Manager uses to decide whether to hand a car over or take it back. Read-only:
 * the checks are derived live from condition grade, open tickets, damage flags, registration /
 * insurance expiry, check-in state, cleaning, service interval and GPS.
 */
export default function ReadinessPanel({ vehicleId, plate }) {
  const { t } = useI18n();
  const fetcher = useCallback(async () => {
    const { data } = await api.get(`/readiness/vehicle/${vehicleId}`);
    return data.data;
  }, [vehicleId]);
  const { data, loading, error } = useFetch(fetcher, [vehicleId]);

  if (!vehicleId) return null;

  const overallTone = data ? (data.blocked ? 'red' : (data.summary?.includes('advisory') ? 'amber' : 'green')) : 'slate';

  return (
    <Card className={`p-6 ring-1 ${data?.blocked ? 'ring-red-200' : 'ring-slate-200/60'}`}>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-400">{t('Readiness · 9-point condition check')}</h3>
          {plate && <span className="text-xs text-slate-400">{plate}</span>}
        </div>
        {data && <Badge tone={overallTone}>{data.summary}</Badge>}
      </div>

      {loading ? (
        <div className="flex items-center gap-2 py-6 text-slate-400"><Spinner className="h-5 w-5" /><span className="text-sm">{t('Evaluating readiness…')}</span></div>
      ) : error ? (
        <p className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{t('Couldn’t load readiness: {error}', { error })}</p>
      ) : (
        <>
          {data.blocked && (
            <p className="mb-4 rounded-lg bg-red-50 px-4 py-2.5 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">
              {data.blockers.length === 1
                ? t('1 blocking issue — this car is not fit to hand over until resolved.')
                : t('{n} blocking issues — this car is not fit to hand over until resolved.', { n: data.blockers.length })}
            </p>
          )}
          <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
            {data.checks.map((c) => <CheckRow key={c.key} c={c} />)}
          </div>
          <p className="mt-4 text-xs text-slate-400">
            <span className="font-semibold text-slate-500">{t('Data origin:')}</span>{' '}
            {t('Derived live from condition grade, open workshop tickets, flagged damage, registration/insurance expiry, check-in state, cleaning, service interval and GPS — the same live authority the rest of the fleet trusts. Nothing here is editable.')}
          </p>
        </>
      )}
    </Card>
  );
}
