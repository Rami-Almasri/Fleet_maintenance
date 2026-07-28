// RepairIntelligencePanel — the Fleet Knowledge Engine surface (P0). Renders "Previous Similar Repairs +
// Recommendation Explanation" for a fault. REUSABLE across mount points: pass `taskId` for an existing
// fault, or `preview={{ vehicleId, symptom, categoryKey }}` for a fault being typed at registration.
//
// It binds ONLY the frozen contract (lib/repairIntelligence) — never a backend internal shape. Money is
// shown only when the server marked it visible (billing.view) AND the global SHOW_FINANCIALS flag is on;
// otherwise cost is simply absent, never broken. The three states (no_history / low_confidence / ready)
// each render a clear message so the panel never looks empty or broken when history is weak.

import { useEffect, useState } from 'react';
import { useI18n } from '../../i18n/I18nContext';
import { SHOW_FINANCIALS } from '../../config/features';
import { aed } from '../../lib/format';
import {
  getRepairIntelligenceForTask, previewRepairIntelligence,
  CONFIDENCE_TONE, RISK_TONE, OUTCOME_TONE, groupByTier, fmtCostBand, fmtDurationBand,
} from '../../lib/repairIntelligence';

function Chip({ tone = 'text-slate-600 bg-slate-100 ring-slate-200', children }) {
  return <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${tone}`}>{children}</span>;
}

export default function RepairIntelligencePanel({ taskId, preview, className = '' }) {
  const { t } = useI18n();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [err, setErr] = useState('');
  const [showEvidence, setShowEvidence] = useState(false);

  const previewKey = preview ? `${preview.vehicleId}|${preview.symptom || ''}|${preview.categoryKey || ''}` : '';

  useEffect(() => {
    let alive = true;
    if (!taskId && !preview?.vehicleId) { setLoading(false); return; }
    setLoading(true);
    setErr('');
    const p = taskId ? getRepairIntelligenceForTask(taskId) : previewRepairIntelligence(preview);
    p.then((d) => { if (alive) setData(d); })
      .catch((e) => { if (alive) setErr(e?.response?.data?.message || 'Could not load repair intelligence'); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, [taskId, previewKey]); // eslint-disable-line react-hooks/exhaustive-deps

  if (loading) {
    return <div className={`rounded-xl border border-slate-200 bg-slate-50/60 px-3.5 py-3 text-sm text-slate-500 ${className}`}>{t('common.loading')}</div>;
  }
  if (err || !data) {
    return null; // read-only intelligence: never block the workflow if it can't load
  }

  const money = SHOW_FINANCIALS && data.financials_visible;
  const rec = data.recommendation || {};
  const conf = rec.confidence || { band: 'low', score: 0, reasons: [] };
  const stats = data.statistics || {};
  const grouped = groupByTier(data.similar_repairs);

  return (
    <section className={`rounded-xl border border-indigo-100 bg-indigo-50/30 ${className}`}>
      {/* Header + confidence */}
      <header className="flex flex-wrap items-center gap-2 border-b border-indigo-100 px-3.5 py-2.5">
        <span className="text-sm font-semibold text-indigo-900">{t('repairIntel.title')}</span>
        <Chip tone={CONFIDENCE_TONE[conf.band] || CONFIDENCE_TONE.low}>
          {t('repairIntel.confidence')}: {t(`repairIntel.confidenceBand.${conf.band}`)} · {conf.score}
        </Chip>
        <span className="ms-auto text-xs text-slate-500">{data.message}</span>
      </header>

      {/* No-history state — a clear, non-broken empty note. */}
      {data.state === 'no_history' && (
        <div className="px-3.5 py-3 text-sm text-slate-500">{t('repairIntel.noHistoryTitle')}</div>
      )}

      {data.state !== 'no_history' && (
        <div className="space-y-3 px-3.5 py-3">
          {/* Recommendation headline */}
          {(rec.action || rec.summary) && (
            <div className="rounded-lg bg-white/70 px-3 py-2 ring-1 ring-inset ring-indigo-100">
              {rec.action && <div className="text-sm font-semibold text-indigo-900">{rec.action}</div>}
              {rec.summary && <div className="mt-0.5 text-xs text-slate-600">{rec.summary}</div>}
            </div>
          )}

          {/* Key facts */}
          <div className="flex flex-wrap gap-1.5">
            {rec.likely_cause?.value && (
              <Chip>{t('repairIntel.likelyCause')}: <b className="font-semibold">{rec.likely_cause.value}</b>{rec.likely_cause.share != null && ` (${Math.round(rec.likely_cause.share * 100)}%)`}</Chip>
            )}
            {stats.success_rate != null && (
              <Chip tone={CONFIDENCE_TONE.high}>{t('repairIntel.successRate', { pct: Math.round(stats.success_rate * 100) })}</Chip>
            )}
            {stats.average_duration != null && (
              <Chip>{t('repairIntel.expectedDuration')}: {fmtDurationBand(rec.expected_duration) || `${Math.round(stats.average_duration)}d`}</Chip>
            )}
            {money && rec.expected_cost && fmtCostBand(rec.expected_cost) && (
              <Chip>{t('repairIntel.expectedCost')}: {fmtCostBand(rec.expected_cost)} {rec.expected_cost.currency}</Chip>
            )}
            {rec.recurrence_risk && (
              <Chip tone={RISK_TONE[rec.recurrence_risk] || RISK_TONE.low}>{t('repairIntel.recurrenceRisk')}: {t(`repairIntel.risk.${rec.recurrence_risk}`)}</Chip>
            )}
          </div>

          {/* Expected parts */}
          {rec.expected_parts?.length > 0 && (
            <div className="flex flex-wrap items-center gap-1.5">
              <span className="text-xs font-medium text-slate-500">{t('repairIntel.expectedParts')}:</span>
              {rec.expected_parts.map((p, i) => (
                <Chip key={i}>{p.name || p.part_number} · {p.freq}×</Chip>
              ))}
            </div>
          )}

          {/* Suggested garage */}
          {rec.suggested_garage?.name && (
            <div className="flex flex-wrap items-center gap-2 rounded-lg bg-white/70 px-3 py-2 text-sm ring-1 ring-inset ring-slate-100">
              <span className="font-semibold text-slate-800">{t('repairIntel.suggestedGarage')}: {rec.suggested_garage.name}</span>
              <span className="text-xs text-slate-500">
                {t('repairIntel.jobs', { n: rec.suggested_garage.jobs })}
                {rec.suggested_garage.success_rate != null && ` · ${Math.round(rec.suggested_garage.success_rate * 100)}%`}
                {rec.suggested_garage.avg_duration_days != null && ` · ${rec.suggested_garage.avg_duration_days}d`}
                {money && rec.suggested_garage.avg_cost != null && ` · ${aed(rec.suggested_garage.avg_cost)}`}
              </span>
            </div>
          )}

          {/* Similar repairs by tier */}
          {grouped.length > 0 && (
            <div className="space-y-2">
              <span className="text-xs font-semibold text-slate-500">{t('repairIntel.similar')}</span>
              {grouped.map(({ tier, rows }) => (
                <div key={tier} className="space-y-1">
                  <div className="text-[11px] font-medium uppercase tracking-wide text-indigo-400">{t(`repairIntel.tier.${tier}`)}</div>
                  {rows.map((r, i) => (
                    <div key={i} className="flex flex-wrap items-center gap-x-2 gap-y-0.5 rounded-lg bg-white/60 px-2.5 py-1.5 text-xs text-slate-600 ring-1 ring-inset ring-slate-100">
                      <span className="font-medium text-slate-800">{r.garage || '—'}</span>
                      {r.duration_days != null && <span>· {r.duration_days}d</span>}
                      {money && r.cost != null && <span>· {aed(r.cost)}</span>}
                      <Chip tone={OUTCOME_TONE[r.outcome] || OUTCOME_TONE.fixed}>{t(`repairIntel.outcome.${r.outcome}`)}</Chip>
                      {r.repaired_at && <span className="ms-auto text-slate-400">{r.repaired_at}</span>}
                    </div>
                  ))}
                </div>
              ))}
            </div>
          )}

          {/* Why + evidence */}
          {data.explanation?.why?.length > 0 && (
            <div className="rounded-lg bg-white/70 px-3 py-2 ring-1 ring-inset ring-slate-100">
              <div className="text-xs font-semibold text-slate-600">{t('repairIntel.why')}</div>
              <ul className="mt-1 list-disc space-y-0.5 ps-4 text-xs text-slate-600">
                {data.explanation.why.map((w, i) => <li key={i}>{w}</li>)}
              </ul>
              {data.explanation.evidence?.length > 0 && (
                <button type="button" onClick={() => setShowEvidence((v) => !v)} className="mt-1.5 text-xs font-medium text-indigo-600 hover:underline">
                  {showEvidence ? t('repairIntel.hideEvidence') : t('repairIntel.showEvidence')}
                </button>
              )}
              {showEvidence && (
                <div className="mt-1.5 space-y-1">
                  {data.explanation.evidence.map((e, i) => (
                    <div key={i} className="flex flex-wrap items-center gap-x-2 text-[11px] text-slate-500">
                      <span className="font-medium text-slate-700">{e.plate || e.vehicle || `#${e.maintenance_task_id}`}</span>
                      <span>· {e.garage || '—'}</span>
                      {e.duration_days != null && <span>· {e.duration_days}d</span>}
                      {money && e.cost != null && <span>· {aed(e.cost)}</span>}
                      <Chip tone={OUTCOME_TONE[e.outcome] || OUTCOME_TONE.fixed}>{t(`repairIntel.outcome.${e.outcome}`)}</Chip>
                      {e.recurred && <Chip tone={RISK_TONE.high}>{t('repairIntel.recurred')}</Chip>}
                      {e.repaired_at && <span className="ms-auto text-slate-400">{e.repaired_at}</span>}
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}
        </div>
      )}
    </section>
  );
}
