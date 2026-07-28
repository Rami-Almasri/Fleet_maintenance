// FleetView's garage recommendation for the assign step — designed to read as a confident DECISION, not
// an analytics list. One hero card carries the recommended garage (score, confidence, a few short reasons,
// a Select button); the runner-up "Other proven garages" and "Specialists" sit behind a Compare toggle so
// the operator grasps the call in seconds. Tapping any garage pre-fills the picker (onPick); the full
// result is lifted (onResult) so the modal can record suggested-vs-chosen and show the prefill note.

import { useEffect, useState } from 'react';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Icon from '../ui/Icon';

const CONF_STYLE = {
  high: 'bg-emerald-100 text-emerald-800 ring-emerald-600/20',
  medium: 'bg-amber-100 text-amber-800 ring-amber-600/20',
  low: 'bg-slate-100 text-slate-600 ring-slate-500/20',
};
const CONF_DOT = { high: 'bg-emerald-500', medium: 'bg-amber-500', low: 'bg-slate-400' };

function ConfBadge({ conf, t }) {
  if (!conf) return null;
  return (
    <span
      title={t(`workflow.garageRec.confidenceTip.${conf}`)}
      className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 ring-inset ${CONF_STYLE[conf] || CONF_STYLE.low}`}
    >
      <span className={`h-1.5 w-1.5 rounded-full ${CONF_DOT[conf] || CONF_DOT.low}`} />
      {t(`workflow.garageRec.confidence.${conf}`)}
    </span>
  );
}

export default function GarageRecommendations({ ticketId, selectedVendorId, onPick, onResult }) {
  const { t } = useI18n();
  const [state, setState] = useState({ loading: true, data: null, error: false });
  const [showMore, setShowMore] = useState(false);
  const [showDetails, setShowDetails] = useState(false);

  useEffect(() => {
    if (!ticketId) return undefined;
    let alive = true;
    setState({ loading: true, data: null, error: false });
    api
      .get(`/maintenance-tickets/${ticketId}/garage-recommendations`)
      .then((res) => {
        if (!alive) return;
        const data = res.data?.data || null;
        setState({ loading: false, data, error: false });
        if (data && onResult) onResult(data);
      })
      .catch(() => alive && setState({ loading: false, data: null, error: true }));
    return () => { alive = false; };
  }, [ticketId]); // eslint-disable-line react-hooks/exhaustive-deps

  const { loading, data, error } = state;

  const shell = (children) => (
    <div className="rounded-xl bg-gradient-to-br from-indigo-50/80 to-slate-50 p-3.5 ring-1 ring-inset ring-indigo-200/60">
      <p className="mb-2.5 flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-indigo-700">
        <Icon.Wrench className="h-3.5 w-3.5" /> {t('workflow.garageRec.recTitle')}
      </p>
      {children}
    </div>
  );

  if (loading) return shell(<p className="text-xs text-slate-400">{t('workflow.garageRec.loading')}</p>);
  if (error) return null;

  const primary = data?.primary || [];
  const also = data?.also_consider || [];
  const faultLabels = data?.criteria?.fault_labels || [];
  if (!primary.length && !also.length) return shell(<p className="text-xs text-slate-400">{t('workflow.garageRec.none')}</p>);

  const isSel = (id) => String(selectedVendorId || '') === String(id);
  const top = primary[0];
  const others = primary.slice(1);
  const topSelected = top && isSel(top.vendor_id);
  const gr = (k, v) => t(`workflow.garageRec.${k}`, v);
  // Per-fault coverage evidence for the top pick (tied to THIS repair's detected faults).
  const faultsDetail = data?.ticket?.faults_detail || [];
  const modelLabel = data?.ticket?.model_label || '';
  const covByCat = {};
  (top?.fault_coverage || []).forEach((fc) => { covByCat[fc.category_key] = fc; });
  const compareBtn = (others.length > 0 || also.length > 0) && (
    <button
      type="button"
      onClick={() => setShowMore((v) => !v)}
      className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-[13px] font-semibold text-slate-600 transition hover:bg-slate-50"
    >
      {showMore ? gr('hide') : gr('compare')}
    </button>
  );

  return shell(
    <>
      {faultLabels.length > 0 && (
        <div className="mb-2.5 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500">
          <span className="font-medium">{gr('recommendedFor')}:</span>
          {faultLabels.map((f) => (
            <span key={f} className="rounded-md bg-white px-1.5 py-0.5 text-[11px] font-semibold text-slate-700 ring-1 ring-inset ring-slate-200">{f}</span>
          ))}
        </div>
      )}

      {/* HERO — the recommended decision */}
      {top && (
        <div className="rounded-xl border-2 border-indigo-500/80 bg-white p-3 shadow-sm">
          <div className="flex items-center gap-1.5">
            <span aria-hidden className="text-lg">🥇</span>
            <span className="truncate text-[15px] font-bold text-slate-900">{top.garage}</span>
          </div>

          {/* Score is the anchor; confidence is secondary. */}
          <div className="mt-1.5 flex items-end justify-between gap-3">
            <div className="flex items-baseline gap-1">
              <span className="text-[34px] font-extrabold leading-none tabular-nums text-indigo-700">{top.match_score}</span>
              <span className="text-sm font-semibold text-slate-400">/100</span>
            </div>
            <div className="flex items-center gap-1.5 pb-0.5">
              {top.warn && <Icon.Alert className="h-3.5 w-3.5 text-amber-500" title="Mixed re-inspection record" />}
              <ConfBadge conf={top.confidence} t={t} />
            </div>
          </div>
          <p className="mt-1 text-[11px] leading-snug text-slate-400">{gr('basedOn')}</p>

          {top.confidence === 'low' && (
            <div className="mt-2 flex items-start gap-1.5 rounded-lg bg-amber-50 px-2.5 py-1.5 text-[12px] text-amber-800 ring-1 ring-inset ring-amber-600/15">
              <Icon.Alert className="mt-0.5 h-3.5 w-3.5 shrink-0" /> <span>{gr('lowWarning')}</span>
            </div>
          )}

          {top.reasons?.length > 0 && (
            <div className="mt-2.5">
              <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{gr('why.title')}</p>
              <ul className="space-y-1">
                {top.reasons.map((r, i) => (
                  <li key={i} className="flex items-start gap-1.5 text-[13px] text-slate-700">
                    <Icon.Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" />
                    <span>{r.t}{r.s ? <span className="text-slate-400"> · {r.s}</span> : null}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}

          {/* Power-user evidence — collapsed by default */}
          <button type="button" onClick={() => setShowDetails((v) => !v)} className="mt-2 text-[12px] font-semibold text-indigo-600 hover:text-indigo-700">
            {showDetails ? gr('hideDetails') : gr('viewDetails')}
          </button>
          {showDetails && (
            <div className="mt-1.5 space-y-2 rounded-lg bg-slate-50 p-2.5 text-[12px] text-slate-600 ring-1 ring-inset ring-slate-200">
              {/* Per-fault coverage — has this garage repaired THESE exact problems before? */}
              {faultsDetail.length > 0 && top.coverage && (
                <div className="rounded-md bg-white p-2 ring-1 ring-inset ring-slate-200">
                  <div className="mb-1.5 flex items-center justify-between">
                    <span className="font-semibold text-slate-700">{gr('faultCoverageTitle')}</span>
                    <b className="tabular-nums text-slate-800">{top.coverage.covered}/{top.coverage.total}</b>
                  </div>
                  <div className="space-y-1.5">
                    {faultsDetail.map((f, i) => {
                      const c = covByCat[f.category_key] || { at_garage: 0, same_model: 0, label: f.label };
                      const has = c.at_garage > 0;
                      return (
                        <div key={i} className="flex items-start gap-1.5">
                          {has
                            ? <Icon.Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" />
                            : <Icon.Alert className="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-500" />}
                          <div className="min-w-0">
                            <div className="font-medium text-slate-700">{f.symptom}</div>
                            <div className="text-slate-500">{gr('repairsHere', { n: c.at_garage, label: c.label })}</div>
                            <div className="text-slate-500">{gr('repairsModel', { n: c.same_model, model: modelLabel, label: c.label })}</div>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                  {top.coverage.missing?.length > 0 && (
                    <div className="mt-1.5 text-[11px] font-medium text-amber-700">{gr('missingExperience')}: {top.coverage.missing.join(', ')}</div>
                  )}
                </div>
              )}
              <div className="flex justify-between"><span>{gr('details.matches')}</span><b className="tabular-nums text-slate-800">{top.matched}</b></div>
              <div className="flex justify-between"><span>{gr('details.specialization')}</span><b className="tabular-nums text-slate-800">{top.concentration}%</b></div>
              <div className="flex justify-between"><span>{gr('details.ranking')}</span><b className="text-slate-800">{gr('rankOf', { rank: 1, total: primary.length })}</b></div>
              <div><span className="font-medium text-slate-500">{gr('details.confidence')}:</span> {t(`workflow.garageRec.confidenceTip.${top.confidence}`)}</div>
              <div><span className="font-medium text-slate-500">{gr('details.alternatives')}:</span> {gr('altSummary', { proven: others.length, spec: also.length })}</div>
            </div>
          )}

          {/* CTA, or a richer confirmation once selected */}
          {topSelected ? (
            <>
              <div className="mt-3 rounded-lg bg-emerald-50 p-3 ring-1 ring-inset ring-emerald-600/20">
                <p className="flex items-center gap-1.5 text-[13px] font-bold text-emerald-800"><Icon.Check className="h-4 w-4" /> {gr('confirmSelected')}</p>
                <div className="mt-1.5 space-y-0.5 text-[13px] text-emerald-900">
                  <div>{gr('score')}: <b className="tabular-nums">{top.match_score}/100</b></div>
                  <div>{gr('details.confidence')}: <b>{gr(`confidence.${top.confidence}`)}</b></div>
                </div>
                <p className="mt-2 text-[11px] text-emerald-700/80">{gr('auditNote')}</p>
              </div>
              {compareBtn && <div className="mt-2">{compareBtn}</div>}
            </>
          ) : (
            <div className="mt-3 flex gap-2">
              <button
                type="button"
                onClick={() => onPick(top.vendor_id)}
                className="flex-1 rounded-lg bg-indigo-600 px-3 py-2 text-[13px] font-semibold text-white transition hover:bg-indigo-700"
              >
                {gr('selectRecommended')}
              </button>
              {compareBtn}
            </div>
          )}
        </div>
      )}

      {/* No PRIMARY pick — no garage has a proven record on this exact vehicle + fault. Rather than leave
          the panel blank under "Recommended for: …", explain why and surface the fallback specialists
          directly (they'd otherwise be stranded behind a Compare button that only lives inside the hero). */}
      {!top && (
        <div className="rounded-xl border border-amber-300 bg-amber-50/70 p-3">
          <p className="flex items-center gap-1.5 text-[13px] font-bold text-amber-800">
            <Icon.Alert className="h-4 w-4 shrink-0" /> {gr('noPrimaryTitle')}
          </p>
          <p className="mt-1 text-[12px] leading-snug text-amber-800/90">
            {also.length > 0 ? gr('noPrimary') : gr('noPrimaryNoAlso')}
          </p>
        </div>
      )}

      {/* Secondary — revealed on Compare (when there's a hero) or shown directly when there's no primary
          pick, so the specialist fallbacks are always reachable. */}
      {(showMore || !top) && (
        <div className="mt-3 space-y-3">
          {others.length > 0 && (
            <div>
              <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{t('workflow.garageRec.otherProven')}</p>
              <div className="space-y-1.5">
                {others.map((g) => (
                  <div key={g.vendor_id} className={`flex items-center justify-between gap-2 rounded-lg border bg-white px-2.5 py-1.5 ${isSel(g.vendor_id) ? 'border-indigo-400' : 'border-slate-200'}`}>
                    <div className="min-w-0">
                      <div className="flex items-center gap-1.5">
                        <span className={`h-1.5 w-1.5 rounded-full ${CONF_DOT[g.confidence] || CONF_DOT.low}`} title={t(`workflow.garageRec.confidenceTip.${g.confidence}`)} />
                        <span className="truncate text-[13px] font-semibold text-slate-800">{g.garage}</span>
                      </div>
                      <span className="text-[11px] text-slate-400">
                        {t('workflow.garageRec.matches', { n: g.matched })} · {g.match_score}/100
                      </span>
                    </div>
                    <button
                      type="button"
                      onClick={() => onPick(g.vendor_id)}
                      className={`shrink-0 rounded-md px-2.5 py-1 text-[12px] font-semibold transition ${isSel(g.vendor_id) ? 'bg-indigo-600 text-white' : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100'}`}
                    >
                      {isSel(g.vendor_id) ? t('workflow.garageRec.selected') : t('workflow.garageRec.use')}
                    </button>
                  </div>
                ))}
              </div>
            </div>
          )}

          {also.length > 0 && (
            <div>
              <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{t('workflow.garageRec.specialists')}</p>
              <div className="flex flex-wrap gap-2">
                {also.map((a) => (
                  <button
                    key={`${a.dimension}-${a.vendor_id}`}
                    type="button"
                    onClick={() => onPick(a.vendor_id)}
                    title={t('workflow.garageRec.alsoHint')}
                    className={`inline-flex flex-col items-start rounded-lg border px-2.5 py-1.5 text-left transition hover:border-indigo-400 ${isSel(a.vendor_id) ? 'border-indigo-500 bg-indigo-50' : 'border-dashed border-slate-300 bg-white'}`}
                  >
                    <span className="text-[10px] font-semibold uppercase tracking-wide text-indigo-600">{a.label}</span>
                    <span className="text-xs font-semibold text-slate-800">{a.garage}</span>
                    <span className="text-[11px] text-slate-400">{t('workflow.garageRec.specialistJobs', { jobs: a.jobs, conc: a.concentration })}</span>
                  </button>
                ))}
              </div>
            </div>
          )}
        </div>
      )}
    </>
  );
}
