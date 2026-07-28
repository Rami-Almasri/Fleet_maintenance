// Left the Garage — Invoices Due (/oversight/left-garage) — cars that have physically left the garage
// (a garage-out reading was captured) but still owe an invoice. The actionable "which garages do I need
// a bill from?" list: each row shows the garage, when the car left, how long ago, and whether an invoice
// was already requested. Requesting / recording the invoice happens on the ticket (deep-linked).
// Backed by GET /Oversight/left-garage.

import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Icon from '../../components/ui/Icon';
import { Skeleton } from '../../components/ui/Skeleton';

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' }) : '—');

export default function GarageInvoiceQueue() {
  const { t } = useI18n();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [onlyNeeds, setOnlyNeeds] = useState(false);
  const [q, setQ] = useState('');

  useEffect(() => {
    let alive = true;
    api.get('/Oversight/left-garage')
      .then((res) => { if (alive) setData(res.data.data); })
      .catch(() => {})
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, []);

  const rows = useMemo(() => {
    let r = data?.rows || [];
    if (onlyNeeds) r = r.filter((x) => x.needs_request);
    const term = q.trim().toLowerCase();
    if (term) r = r.filter((x) => `${x.plate_no} ${x.car} ${x.garage || ''}`.toLowerCase().includes(term));
    return r;
  }, [data, onlyNeeds, q]);

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1200px] space-y-6 px-4 sm:px-6 lg:px-8">
        <div className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <Link to="/apps/reports" className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-400 hover:text-slate-600">
              <Icon.ArrowRight className="h-3 w-3 rotate-180" /> Reports
            </Link>
            <h1 className="font-display text-2xl font-bold tracking-tight text-slate-900">{t('oversight.garage.title')}</h1>
            <p className="mt-1 max-w-2xl text-sm text-slate-500">{t('oversight.garage.subtitle')}</p>
          </div>
          <div className="flex gap-3">
            <div className="rounded-2xl bg-white px-4 py-3 text-center shadow-sm ring-1 ring-slate-200">
              <p className="text-2xl font-bold tabular-nums text-slate-900">{data?.total ?? 0}</p>
              <p className="text-[11px] uppercase tracking-wide text-slate-400">{t('oversight.garage.queue')}</p>
            </div>
            <div className={`rounded-2xl px-4 py-3 text-center shadow-sm ring-1 ${data?.needs_request ? 'bg-amber-50 ring-amber-200' : 'bg-white ring-slate-200'}`}>
              <p className={`text-2xl font-bold tabular-nums ${data?.needs_request ? 'text-amber-600' : 'text-slate-900'}`}>{data?.needs_request ?? 0}</p>
              <p className="text-[11px] uppercase tracking-wide text-slate-400">{t('oversight.garage.needsRequest')}</p>
            </div>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <button
            onClick={() => setOnlyNeeds((v) => !v)}
            className={`rounded-full px-3 py-1.5 text-xs font-semibold transition ${onlyNeeds ? 'bg-amber-500 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'}`}
          >
            {t('oversight.garage.needsRequest')}
          </button>
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
          <div className="overflow-x-auto rounded-2xl border border-slate-200/60 bg-white shadow-soft">
            <table className="w-full min-w-[760px] border-separate border-spacing-0 text-sm">
              <thead>
                <tr className="text-left">
                  <th className="whitespace-nowrap border-b border-slate-200 bg-slate-50/90 px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{t('oversight.common.vehicle')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 bg-slate-50/90 px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{t('oversight.garage.garage')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 bg-slate-50/90 px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{t('oversight.garage.left')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 bg-slate-50/90 px-5 py-3 text-center text-xs font-semibold uppercase tracking-wide text-slate-500">{t('oversight.garage.faults')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 bg-slate-50/90 px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{t('oversight.garage.invoice')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 bg-slate-50/90 px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500" />
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.ticket_id} className={`transition-colors ${r.needs_request ? 'bg-amber-50/50 hover:bg-amber-50' : 'bg-white even:bg-slate-50/40 hover:bg-indigo-50/40'}`}>
                    <td className="border-b border-slate-100 px-5 py-3.5">
                      <Link to={`/maintenance-workflow/${r.ticket_id}`} className="font-mono font-semibold text-slate-900 hover:text-indigo-600">{r.plate_no || `#${r.ticket_id}`}</Link>
                      {r.car && <p className="text-xs text-slate-400">{r.car}</p>}
                    </td>
                    <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">
                      <span className="inline-flex items-center gap-1.5"><Icon.Wrench className="h-3.5 w-3.5 text-slate-300" />{r.garage || <span className="text-slate-300">—</span>}</span>
                    </td>
                    <td className="border-b border-slate-100 px-5 py-3.5">
                      <p className="text-slate-500">{fmtDate(r.left_at)}</p>
                      {r.days_since != null && <p className="text-[11px] text-slate-400">{t('oversight.garage.daysAgo', { n: r.days_since })}</p>}
                    </td>
                    <td className="border-b border-slate-100 px-5 py-3.5 text-center tabular-nums text-slate-600">{r.faults || 0}</td>
                    <td className="border-b border-slate-100 px-5 py-3.5">
                      {r.invoice_requested ? (
                        <span className="inline-flex items-center gap-1 rounded-full bg-sky-100 px-2 py-0.5 text-xs font-semibold text-sky-700"><Icon.Clock className="h-3 w-3" />{t('oversight.garage.requested')}</span>
                      ) : (
                        <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700"><Icon.Alert className="h-3 w-3" />{t('oversight.garage.notRequested')}</span>
                      )}
                    </td>
                    <td className="border-b border-slate-100 px-5 py-3.5 text-right">
                      <Link to={`/maintenance-workflow/${r.ticket_id}`} className="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-indigo-600 hover:bg-indigo-50">
                        {t('oversight.garage.request')} <Icon.ArrowRight className="h-3 w-3" />
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
