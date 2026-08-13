import { useMemo } from 'react';
import InspectionReport from './InspectionReport';
import {
  damageStatus,
  damageStatusMeta,
  fuelStatusMeta,
  fuelFraction,
  severityMeta,
  damageTypeLabel,
  FUEL,
} from '../../lib/inspections';
import { SHOW_FINANCIALS } from '../../config/features';
import { useI18n } from '../../i18n/I18nContext';

// ── Inspection Summary — "The Dispute Killer" ─────────────────────────────────
//
// One screen that fuses the Damage Mapper and the Fuel Audit before a check-in is
// finalized. It sorts every finding into the three statuses (Existing · New ·
// Charged) and states the fuel verdict (Balanced · Shortage) so the customer sees
// — in one glance — exactly what is new, what is being charged, and why. The
// detailed, exportable Check-in Report sits underneath.

const money = (n) => `${FUEL.currency} ${Number(n || 0).toFixed(2)}`;

export default function InspectionSummary({
  records,
  labelFor,
  fuelAudit,
  session,
  inspectorName,
  phaseLabel,
  onFinalize,
  finalized = false,
}) {
  const { t } = useI18n();
  const groups = useMemo(() => {
    const damages = Object.entries(records)
      .filter(([, r]) => r.damage)
      .map(([id, r]) => ({ zone: id, ...r.damage, status: damageStatus(r.damage) }));
    const by = { existing: [], new: [], charged: [] };
    damages.forEach((d) => by[d.status].push(d));
    return by;
  }, [records]);

  const newCount = groups.new.length;
  const fuelShort = fuelAudit?.status === 'shortage';
  const disputable = newCount > 0 || fuelShort;
  const fuelMeta = fuelAudit ? fuelStatusMeta(fuelAudit.status) : null;

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="flex items-center gap-2">
            <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600">
              <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                <path d="M9 12l2 2 4-4M7.8 4.5a2 2 0 0 0-1.4.6L4.1 7.4a2 2 0 0 0-.6 1.4v6.4a2 2 0 0 0 .6 1.4l2.3 2.3a2 2 0 0 0 1.4.6h8.4a2 2 0 0 0 1.4-.6l2.3-2.3a2 2 0 0 0 .6-1.4V8.8a2 2 0 0 0-.6-1.4l-2.3-2.3a2 2 0 0 0-1.4-.6z" />
              </svg>
            </span>
            <h2 className="text-sm font-semibold text-slate-900">{t('Inspection Summary — the dispute killer')}</h2>
          </div>
          <p className="mt-0.5 text-xs text-slate-500">
            {t('Damage and fuel reconciled into one signed-off view. New findings and fuel shortages are isolated so the final amount can’t be disputed.')}
          </p>
        </div>

        {onFinalize && (
          <button
            onClick={() => onFinalize(!finalized)}
            className={`inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold shadow-sm transition ${
              finalized
                ? 'bg-emerald-600 text-white hover:bg-emerald-700'
                : 'bg-indigo-600 text-white hover:bg-indigo-700'
            }`}
          >
            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d={finalized ? 'M20 6 9 17l-5-5' : 'M5 13l4 4L19 7'} />
            </svg>
            {finalized ? t('Check-in finalized') : t('Finalize check-in')}
          </button>
        )}
      </div>

      {/* headline status cards */}
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <StatusCard
          meta={damageStatusMeta('new')}
          value={newCount}
          label={t('New damages')}
          sub={t('Needs assessment')}
          emphatic={newCount > 0}
        />
        <StatusCard
          meta={damageStatusMeta('charged')}
          value={groups.charged.length}
          label={t('Charged')}
          sub={t('Linked to invoice')}
          emphatic={groups.charged.length > 0}
        />
        <StatusCard
          meta={damageStatusMeta('existing')}
          value={groups.existing.length}
          label={t('Existing')}
          sub={t('Documented at delivery')}
        />
        <FuelCard audit={fuelAudit} meta={fuelMeta} t={t} />
      </div>

      {/* the verdict banner */}
      {finalized ? (
        <Banner tone="emerald">
          {t('Check-in finalized — this reconciled report is the customer’s record of record.')}
        </Banner>
      ) : disputable ? (
        <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-4">
          <div className="flex items-center gap-2 text-sm font-semibold text-rose-800">
            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round">
              <path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" />
            </svg>
            {t('Charges to settle at check-in')}
          </div>
          <ul className="mt-2 space-y-1.5">
            {groups.new.map((d, i) => (
              <li key={`d${i}`} className="flex items-center gap-2 text-sm text-rose-800">
                <span className="h-1.5 w-1.5 rounded-full bg-amber-500" />
                <span className="font-semibold">{labelFor(d.zone)}</span>
                <span className="text-rose-600">
                  — {damageTypeLabel(d.type)} · {severityMeta(d.severity).label}
                </span>
              </li>
            ))}
            {fuelShort && (
              <li className="flex items-center gap-2 text-sm text-rose-800">
                <span className="h-1.5 w-1.5 rounded-full bg-rose-500" />
                <span className="font-semibold">{t('Fuel shortage')}</span>
                <span className="text-rose-600">
                  — {fuelAudit.shortagePct}% ({fuelAudit.litresShort} L)
                  {SHOW_FINANCIALS && <> · <b>{money(fuelAudit.charge)}</b></>}
                </span>
              </li>
            )}
          </ul>
          {SHOW_FINANCIALS && fuelShort && (
            <p className="mt-2 text-xs font-medium text-rose-500">
              {t('Fuel Charge auto-calculated from the gauge gap at {price} {currency}/L on a {tank} L tank.', {
                price: FUEL.pricePerL.toFixed(2),
                currency: FUEL.currency,
                tank: FUEL.tankCapacityL,
              })}
            </p>
          )}
        </div>
      ) : (
        <Banner tone="emerald">
          {t('Nothing new to charge — no new damage and fuel reads balanced. Vehicle is clear for this phase.')}
        </Banner>
      )}

      {/* detailed, exportable Check-in Report */}
      <div className="border-t border-slate-100 pt-5">
        <InspectionReport
          records={records}
          labelFor={labelFor}
          phaseLabel={phaseLabel}
          session={session}
          inspectorName={inspectorName}
          fuelAudit={fuelAudit}
        />
      </div>
    </div>
  );
}

function StatusCard({ meta, value, label, sub, emphatic = false }) {
  return (
    <div className={`rounded-2xl border p-3.5 ${emphatic ? meta.ring + ' ring-1' : 'border-slate-200 bg-white'}`}>
      <div className="flex items-center justify-between">
        <span className="text-2xl font-extrabold tabular-nums text-slate-900">{value}</span>
        <span className={`h-2.5 w-2.5 rounded-full ${meta.dot}`} />
      </div>
      <p className="mt-1 text-sm font-semibold text-slate-700">{label}</p>
      <p className="text-[11px] text-slate-400">{sub}</p>
    </div>
  );
}

function FuelCard({ audit, meta, t }) {
  if (!audit) {
    return (
      <div className="rounded-2xl border border-dashed border-slate-200 p-3.5">
        <span className="text-sm font-semibold text-slate-400">{t('Fuel')}</span>
        <p className="mt-1 text-[11px] text-slate-400">{t('Set both gauges to audit')}</p>
      </div>
    );
  }
  const short = audit.status === 'shortage';
  return (
    <div className={`rounded-2xl border p-3.5 ${short ? meta.ring + ' ring-1' : 'border-emerald-200 bg-emerald-50'}`}>
      <div className="flex items-center justify-between">
        <span className={`text-sm font-bold ${short ? 'text-rose-700' : 'text-emerald-700'}`}>{meta.label}</span>
        <span className={`h-2.5 w-2.5 rounded-full ${meta.dot}`} />
      </div>
      <p className="mt-1 text-sm font-semibold text-slate-700">
        {Math.round(audit.delivered)}% → {Math.round(audit.returned)}%
      </p>
      <p className="text-[11px] text-slate-400">
        {short
          ? t('Short {pct}% · {litres} L', { pct: audit.shortagePct, litres: audit.litresShort })
          : audit.surplus ? t('Returned fuller') : t('Balanced')}
        {' · '}
        {fuelFraction(audit.returned)}
      </p>
    </div>
  );
}

function Banner({ tone, children }) {
  const tones = {
    emerald: 'border-emerald-200 bg-emerald-50 text-emerald-800',
  };
  return (
    <div className={`flex items-center gap-2 rounded-2xl border px-4 py-3 text-sm font-medium ${tones[tone]}`}>
      <svg className="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M20 6 9 17l-5-5" />
      </svg>
      {children}
    </div>
  );
}
