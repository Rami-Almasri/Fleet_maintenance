// Transferred — Faults Already Fixed (/oversight/resolved-transfers) — cars a supervisor moved to a
// DIFFERENT garage even though every fault on the ticket was already fixed. Because there was nothing
// left to repair, the move demanded a justification note at transfer time; each one is listed here with
// its from → to garage, odometer, who moved it and the reason they gave, so an unnecessary or mistaken
// transfer is caught. Backed by GET /Oversight/resolved-transfers.

import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Icon from '../../components/ui/Icon';
import { Skeleton } from '../../components/ui/Skeleton';

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');

export default function ResolvedTransfers() {
  const { t } = useI18n();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [q, setQ] = useState('');

  useEffect(() => {
    let alive = true;
    api.get('/Oversight/resolved-transfers')
      .then((res) => { if (alive) setData(res.data.data); })
      .catch(() => {})
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, []);

  const rows = useMemo(() => {
    let r = data?.rows || [];
    const term = q.trim().toLowerCase();
    if (term) r = r.filter((x) => `${x.plate_no || ''} ${x.car || ''} ${x.from_garage || ''} ${x.to_garage || ''} ${x.flagged_by || ''}`.toLowerCase().includes(term));
    return r;
  }, [data, q]);

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1200px] space-y-6 px-4 sm:px-6 lg:px-8">
        <div className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <Link to="/oversight" className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-400 hover:text-slate-600">
              <Icon.ArrowRight className="h-3 w-3 rotate-180" /> {t('oversight.hub.title')}
            </Link>
            <h1 className="font-display text-2xl font-bold tracking-tight text-slate-900">{t('oversight.resolvedTransfers.title')}</h1>
            <p className="mt-1 max-w-2xl text-sm text-slate-500">{t('oversight.resolvedTransfers.subtitle')}</p>
          </div>
          <div className="rounded-2xl bg-white px-4 py-3 text-center shadow-sm ring-1 ring-slate-200">
            <p className="text-2xl font-bold tabular-nums text-slate-900">{data?.total ?? 0}</p>
            <p className="text-[11px] uppercase tracking-wide text-slate-400">{t('oversight.resolvedTransfers.flagged')}</p>
          </div>
        </div>

        <div className="flex items-center">
          <div className="relative ms-auto">
            <Icon.Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder={t('common.search')}
              className="w-56 rounded-lg border border-slate-200 bg-white py-2 ps-9 pe-3 text-sm outline-none focus:border-indigo-300 focus:ring-2 focus:ring-indigo-100"
            />
          </div>
        </div>

        {loading ? (
          <Skeleton className="h-64 rounded-2xl" />
        ) : rows.length === 0 ? (
          <div className="flex flex-col items-center justify-center rounded-2xl bg-white py-20 text-center shadow-sm ring-1 ring-slate-200">
            <Icon.Check className="h-10 w-10 text-emerald-500" />
            <p className="mt-3 text-sm font-medium text-slate-700">{t('oversight.common.empty')}</p>
            <p className="text-xs text-slate-400">{t('oversight.common.emptyBody')}</p>
          </div>
        ) : (
          <div className="space-y-3">
            {rows.map((r) => (
              <div key={r.id} className="flex flex-col gap-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 sm:flex-row sm:items-start">
                {/* Vehicle */}
                <div className="sm:w-44 sm:flex-shrink-0">
                  <Link to={`/maintenance-workflow/${r.ticket_id}`} className="font-mono text-base font-bold text-slate-900 hover:text-indigo-600">{r.plate_no || `#${r.ticket_id}`}</Link>
                  {r.car && <p className="text-xs text-slate-400">{r.car}</p>}
                  <p className="mt-1 text-[11px] text-slate-400">{fmtDate(r.at)}</p>
                </div>

                {/* From → To garage */}
                <div className="sm:w-72 sm:flex-shrink-0">
                  <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{t('oversight.resolvedTransfers.move')}</p>
                  <div className="flex items-center gap-2 text-sm">
                    <span className="inline-flex items-center gap-1 rounded-lg bg-slate-50 px-2 py-1 font-medium text-slate-600 ring-1 ring-slate-200">
                      <Icon.Wrench className="h-3 w-3 text-slate-400" /> {r.from_garage || '—'}
                    </span>
                    <Icon.ArrowRight className="h-4 w-4 flex-shrink-0 text-slate-300" />
                    <span className="inline-flex items-center gap-1 rounded-lg bg-amber-50 px-2 py-1 font-medium text-amber-700 ring-1 ring-amber-200">
                      <Icon.Wrench className="h-3 w-3 text-amber-500" /> {r.to_garage || '—'}
                    </span>
                  </div>
                  <div className="mt-1.5 flex flex-wrap gap-3 text-[11px] text-slate-400">
                    {r.odometer != null && <span>{t('oversight.resolvedTransfers.odometer')}: {Number(r.odometer).toLocaleString()} km</span>}
                    {r.flagged_by && <span>{t('oversight.resolvedTransfers.by')}: {r.flagged_by}</span>}
                  </div>
                </div>

                {/* The justification note */}
                <div className="min-w-0 flex-1">
                  <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{t('oversight.resolvedTransfers.note')}</p>
                  <p className="rounded-lg bg-amber-50/60 px-3 py-2 text-sm text-slate-700 ring-1 ring-inset ring-amber-100">{r.note || '—'}</p>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
