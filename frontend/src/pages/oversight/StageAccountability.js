// Stage Accountability (/oversight/stages) — per ticket, the full workflow chain laid out stage by
// stage: who owned each stage and the mileage they recorded there. One card per ticket; each stage is
// a step in a vertical timeline with its owner and (where captured) the odometer reading.
// Backed by GET /Oversight/stage-accountability.

import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Icon from '../../components/ui/Icon';
import { Skeleton } from '../../components/ui/Skeleton';
import { Card, PageHeader, SearchInput, EmptyState, ErrorState } from '../../components/ui/Misc';

const fmtKm = (n) => (n == null ? null : `${Number(n).toLocaleString()} km`);
const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—');

const SEV_TONE = {
  red: 'bg-red-50 text-red-700 ring-red-200',
  orange: 'bg-orange-50 text-orange-700 ring-orange-200',
  amber: 'bg-amber-50 text-amber-700 ring-amber-200',
  green: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
};

export default function StageAccountability() {
  const { t } = useI18n();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [q, setQ] = useState('');

  useEffect(() => {
    let alive = true;
    api.get('/Oversight/stage-accountability')
      .then((res) => { if (alive) setData(res.data.data); })
      .catch(() => { if (alive) setError(true); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, []);

  const tickets = useMemo(() => {
    let r = data?.tickets || [];
    const term = q.trim().toLowerCase();
    if (term) r = r.filter((x) => `${x.plate_no} ${x.car} ${x.workflow_status} ${x.stages.map((s) => s.owner).join(' ')}`.toLowerCase().includes(term));
    return r;
  }, [data, q]);

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1200px] space-y-6 px-4 sm:px-6 lg:px-8">
        <div>
          <Link to="/oversight" className="mb-2 inline-flex items-center gap-1 text-xs font-medium text-slate-400 transition-colors hover:text-slate-600">
            <Icon.ArrowRight className="h-3 w-3 rotate-180" /> {t('oversight.hub.title')}
          </Link>
          <PageHeader title={t('oversight.stages.title')} subtitle={t('oversight.stages.subtitle')}>
            <div className="rounded-2xl border border-slate-200/60 bg-white px-4 py-3 text-center shadow-soft">
              <p className="text-2xl font-bold tabular-nums text-slate-900">{data?.total ?? 0}</p>
              <p className="text-[11px] uppercase tracking-wide text-slate-400">{t('oversight.stages.tickets')}</p>
            </div>
          </PageHeader>
        </div>

        <div className="flex items-center">
          <SearchInput value={q} onChange={setQ} placeholder={t('common.search')} className="ms-auto w-64" />
        </div>

        {loading ? (
          <div className="grid gap-5 md:grid-cols-2">
            {[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-72 rounded-2xl" />)}
          </div>
        ) : error ? (
          <Card><ErrorState /></Card>
        ) : tickets.length === 0 ? (
          <Card>
            <EmptyState
              icon={<Icon.Route className="h-6 w-6" />}
              title={t('oversight.common.empty')}
              message={t('oversight.common.emptyBody')}
            />
          </Card>
        ) : (
          <div className="stagger grid gap-5 md:grid-cols-2">
            {tickets.map((tk) => (
              <div key={tk.ticket_id} className="rounded-2xl border border-slate-200/60 bg-white p-5 shadow-soft">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <Link to={`/maintenance-workflow/${tk.ticket_id}`} className="font-mono text-base font-bold text-slate-900 hover:text-indigo-600">{tk.plate_no || `#${tk.ticket_id}`}</Link>
                    {tk.car && <p className="text-xs text-slate-400">{tk.car}</p>}
                  </div>
                  <div className="flex flex-col items-end gap-1">
                    {tk.severity_label && (
                      <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ${SEV_TONE[tk.severity_tone] || SEV_TONE.amber}`}>
                        {tk.severity_emoji} {tk.severity_label}
                      </span>
                    )}
                    <span className="text-[11px] text-slate-400">{t('oversight.stages.stagesReached', { n: tk.stage_count })}</span>
                  </div>
                </div>

                <ol className="mt-4 space-y-0">
                  {tk.stages.map((s, i) => (
                    <li key={s.key} className="relative flex gap-3 pb-4 last:pb-0">
                      {/* connector */}
                      {i < tk.stages.length - 1 && <span className="absolute start-[7px] top-4 h-full w-px bg-slate-200" aria-hidden="true" />}
                      <span className="relative mt-1 h-3.5 w-3.5 flex-shrink-0 rounded-full border-2 border-indigo-400 bg-white" />
                      <div className="min-w-0 flex-1">
                        <div className="flex items-baseline justify-between gap-2">
                          <p className="text-sm font-semibold text-slate-800">{s.label}</p>
                          {fmtKm(s.odometer) && <span className="whitespace-nowrap font-mono text-xs font-semibold text-indigo-600">{fmtKm(s.odometer)}</span>}
                        </div>
                        <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-slate-500">
                          <span className="inline-flex items-center gap-1">
                            <Icon.Users className="h-3 w-3 text-slate-300" />
                            {s.owner || <span className="italic text-slate-400">{t('oversight.stages.noOwner')}</span>}
                          </span>
                          <span className="text-slate-300">·</span>
                          <span>{fmtDate(s.at)}</span>
                        </p>
                      </div>
                    </li>
                  ))}
                </ol>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
