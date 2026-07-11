// Mis-Diagnosis Review (/oversight/misdiagnoses) — every fault the INSPECTOR (Abu Maroof) diagnosed that
// a supervisor later OVERRULED as wrong (the "mark fault incorrect" override, In-Workshop only). Shows the
// symptom he called, who diagnosed it, who overruled it, the reason and when — plus a per-inspector tally
// so a recurring mis-caller stands out. Backed by GET /Oversight/misdiagnoses.

import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Icon from '../../components/ui/Icon';
import { Skeleton } from '../../components/ui/Skeleton';

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' }) : '—');

const SEV_TONE = {
  red: 'bg-red-50 text-red-700 ring-red-200',
  orange: 'bg-orange-50 text-orange-700 ring-orange-200',
  amber: 'bg-amber-50 text-amber-700 ring-amber-200',
  green: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
};

export default function Misdiagnoses() {
  const { t } = useI18n();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [q, setQ] = useState('');

  useEffect(() => {
    let alive = true;
    api.get('/Oversight/misdiagnoses')
      .then((res) => { if (alive) setData(res.data.data); })
      .catch(() => {})
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, []);

  const rows = useMemo(() => {
    let r = data?.rows || [];
    const term = q.trim().toLowerCase();
    if (term) r = r.filter((x) => `${x.plate_no} ${x.car} ${x.symptom} ${x.diagnosed_by || ''} ${x.overruled_by || ''}`.toLowerCase().includes(term));
    return r;
  }, [data, q]);

  const byInspector = data?.by_inspector || [];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1200px] space-y-6 px-4 sm:px-6 lg:px-8">
        <div className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <Link to="/oversight" className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-400 hover:text-slate-600">
              <Icon.ArrowRight className="h-3 w-3 rotate-180" /> {t('oversight.hub.title')}
            </Link>
            <h1 className="font-display text-2xl font-bold tracking-tight text-slate-900">{t('oversight.misdiag.title')}</h1>
            <p className="mt-1 max-w-2xl text-sm text-slate-500">{t('oversight.misdiag.subtitle')}</p>
          </div>
          <div className="rounded-2xl bg-white px-4 py-3 text-center shadow-sm ring-1 ring-slate-200">
            <p className="text-2xl font-bold tabular-nums text-slate-900">{data?.total ?? 0}</p>
            <p className="text-[11px] uppercase tracking-wide text-slate-400">{t('oversight.misdiag.overruled')}</p>
          </div>
        </div>

        {/* Per-inspector tally */}
        {byInspector.length > 0 && (
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-xs font-semibold uppercase tracking-wide text-slate-400">{t('oversight.misdiag.byInspector')}</span>
            {byInspector.map((p) => (
              <span key={p.name} className="inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1 text-xs font-medium text-slate-700 shadow-sm ring-1 ring-slate-200">
                <Icon.Users className="h-3 w-3 text-slate-300" /> {p.name}
                <span className="rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-bold text-red-700">{p.count}</span>
              </span>
            ))}
          </div>
        )}

        <div className="relative max-w-xs">
          <Icon.Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder={t('common.search')}
            className="w-full rounded-lg border border-slate-200 bg-white py-2 ps-9 pe-3 text-sm outline-none focus:border-indigo-300 focus:ring-2 focus:ring-indigo-100"
          />
        </div>

        {loading ? (
          <Skeleton className="h-64 rounded-2xl" />
        ) : rows.length === 0 ? (
          <div className="flex flex-col items-center justify-center rounded-2xl bg-white py-20 text-center shadow-sm ring-1 ring-slate-200">
            <Icon.Check className="h-10 w-10 text-emerald-500" />
            <p className="mt-3 text-sm font-medium text-slate-700">{t('oversight.misdiag.emptyTitle')}</p>
            <p className="text-xs text-slate-400">{t('oversight.misdiag.emptyBody')}</p>
          </div>
        ) : (
          <div className="space-y-3">
            {rows.map((r) => (
              <div key={r.task_id} className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <Link to={`/maintenance-workflow/${r.ticket_id}`} className="font-mono text-base font-bold text-slate-900 hover:text-indigo-600">{r.plate_no || `#${r.ticket_id}`}</Link>
                      {r.car && <span className="text-xs text-slate-400">{r.car}</span>}
                      {r.severity_label && (
                        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ${SEV_TONE[r.severity_tone] || SEV_TONE.amber}`}>
                          {r.severity_emoji} {r.severity_label}
                        </span>
                      )}
                    </div>
                    {/* The fault he called, now disputed */}
                    <p className="mt-2 text-sm font-semibold text-slate-800 line-through decoration-red-300">{r.symptom}</p>
                    {r.root_cause && <p className="text-xs text-slate-400">{t('oversight.misdiag.calledCause')}: {r.root_cause}</p>}
                  </div>
                  <span className="inline-flex items-center gap-1 rounded-full bg-red-100 px-2.5 py-1 text-xs font-bold text-red-700">
                    <Icon.XCircle className="h-3.5 w-3.5" /> {t('oversight.misdiag.badge')}
                  </span>
                </div>

                {/* Who / why */}
                <div className="mt-3 grid gap-3 border-t border-slate-100 pt-3 sm:grid-cols-3">
                  <Field label={t('oversight.misdiag.diagnosedBy')} icon="Users" value={r.diagnosed_by} />
                  <Field label={t('oversight.misdiag.overruledBy')} icon="Shield" value={r.overruled_by} />
                  <Field label={t('oversight.common.when')} icon="Clock" value={fmtDate(r.at)} />
                </div>
                {r.reason && (
                  <p className="mt-3 rounded-lg bg-red-50/60 px-3 py-2 text-sm italic text-slate-600 ring-1 ring-red-100">
                    <span className="font-semibold not-italic text-red-700">{t('oversight.misdiag.reason')}: </span>“{r.reason}”
                  </p>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

function Field({ label, icon, value }) {
  const Ico = Icon[icon] || Icon.Info;
  return (
    <div>
      <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{label}</p>
      <p className="mt-0.5 inline-flex items-center gap-1.5 text-sm text-slate-700">
        <Ico className="h-3.5 w-3.5 text-slate-300" /> {value || <span className="text-slate-300">—</span>}
      </p>
    </div>
  );
}
