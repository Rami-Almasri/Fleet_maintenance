// Fixed & Completed Repairs (/completed-repairs) — the ledger of every car whose repair is DONE and
// signed off (workflow_status = closed). Each row is a finished job with the full story: who requested
// it, who drove it, WHERE it was fixed, what was found + repaired, and what it cost. Expand a row for
// the people chain (requested → inspected → dispatched → garage → closed), the odometer chain and the
// resolved faults. Backed by GET /maintenance-tickets/completed. Newest-closed first.

import { Fragment, useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import { useI18n } from '../i18n/I18nContext';
import Icon from '../components/ui/Icon';
import { Skeleton } from '../components/ui/Skeleton';
import { PageHeader, EmptyState } from '../components/ui/Misc';
import { SHOW_FINANCIALS } from '../config/features';

const fmtAED = (n) => `AED ${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' }) : '—');
const fmtDateTime = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');

// Human "how long ago" from an ISO date, coarse (the ledger only needs day-scale precision).
const ago = (iso) => {
  if (!iso) return '';
  const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000);
  if (days <= 0) return 'today';
  if (days === 1) return '1 day ago';
  if (days < 30) return `${days} days ago`;
  const months = Math.floor(days / 30);
  return months === 1 ? '1 month ago' : `${months} months ago`;
};

export default function CompletedRepairs() {
  const { t } = useI18n();

  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [open, setOpen] = useState(() => new Set()); // expanded ticket ids

  const load = useCallback(async () => {
    try {
      const res = await api.get('/maintenance-tickets/completed');
      setData(res.data.data);
    } catch (_) {
      // keep last good state
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  const tickets = useMemo(() => data?.tickets || [], [data]);

  // Client-side filter over the loaded set (the endpoint also supports ?search=, but the fleet's
  // closed set is capped at 500 so filtering in-memory keeps the box instant).
  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return tickets;
    return tickets.filter((tk) =>
      [tk.plate, tk.car, tk.garage, tk.requested_by_name, tk.assigned_driver_name, tk.dispatched_by_name]
        .filter(Boolean)
        .some((v) => String(v).toLowerCase().includes(q)),
    );
  }, [tickets, search]);

  const totalCost = useMemo(
    () => filtered.reduce((sum, tk) => sum + Number(tk.cost || 0), 0),
    [filtered],
  );

  const toggle = (id) => setOpen((prev) => {
    const next = new Set(prev);
    if (next.has(id)) next.delete(id); else next.add(id);
    return next;
  });

  // "Who drove it" — prefer the delegated/assigned driver, fall back to the dispatch snapshot.
  const driverOf = (tk) => tk.assigned_driver_name || tk.dispatched_by_name || null;

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1280px] space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title={t('completedRepairs.title')} subtitle={t('completedRepairs.subtitle')}>
          <div className="rounded-xl border border-slate-200/60 bg-white px-4 py-3 text-center shadow-soft">
            <p className="text-2xl font-bold tabular-nums text-slate-900">{filtered.length}</p>
            <p className="text-[11px] uppercase tracking-wide text-slate-400">{t('completedRepairs.fixed')}</p>
          </div>
          {SHOW_FINANCIALS && (
            <div className="rounded-xl border border-slate-200/60 bg-white px-4 py-3 text-center shadow-soft">
              <p className="text-2xl font-bold tabular-nums text-slate-900">{fmtAED(totalCost)}</p>
              <p className="text-[11px] uppercase tracking-wide text-slate-400">{t('completedRepairs.totalSpend')}</p>
            </div>
          )}
        </PageHeader>

        <div className="relative max-w-md">
          <Icon.Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t('completedRepairs.searchPlaceholder')}
            className="w-full rounded-xl border border-slate-200 bg-white py-2.5 pl-10 pr-4 text-sm shadow-soft outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"
          />
        </div>

        {loading ? (
          <Skeleton className="h-64 rounded-2xl" />
        ) : filtered.length === 0 ? (
          <div className="overflow-hidden rounded-2xl border border-slate-200/60 bg-white shadow-soft">
            <EmptyState icon={<Icon.Check className="h-6 w-6 text-emerald-500" />} title={t('completedRepairs.emptyTitle')} message={t('completedRepairs.emptyBody')} />
          </div>
        ) : (
          <div className="overflow-hidden rounded-2xl border border-slate-200/60 bg-white shadow-soft">
            <div className="overflow-x-auto">
              <table className="min-w-full border-separate border-spacing-0 text-sm">
                <thead className="bg-slate-50/90 text-left text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="w-8 border-b border-slate-200 px-3 py-3" />
                    <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 font-semibold">{t('completedRepairs.vehicle')}</th>
                    <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 font-semibold">{t('completedRepairs.fixedAt')}</th>
                    <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 font-semibold">{t('completedRepairs.requestedBy')}</th>
                    <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 font-semibold">{t('completedRepairs.driver')}</th>
                    <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 font-semibold">{t('completedRepairs.closed')}</th>
                    {SHOW_FINANCIALS && <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-right font-semibold">{t('completedRepairs.cost')}</th>}
                    <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-right font-semibold">{t('completedRepairs.actions')}</th>
                  </tr>
                </thead>
                <tbody>
                  {filtered.map((tk) => {
                    const isOpen = open.has(tk.id);
                    const faults = tk.findings || [];
                    return (
                      <Fragment key={tk.id}>
                        <tr className="cursor-pointer bg-white transition-colors even:bg-slate-50/40 hover:bg-indigo-50/40" onClick={() => toggle(tk.id)}>
                          <td className="border-b border-slate-100 px-3 py-3.5 text-slate-400">
                            <Icon.ChevronDown className={`h-4 w-4 transition-transform ${isOpen ? '' : '-rotate-90'}`} />
                          </td>
                          <td className="border-b border-slate-100 px-5 py-3.5">
                            <span className="font-mono font-semibold text-slate-900">{tk.plate || `#${tk.id}`}</span>
                            {tk.car && <p className="text-xs text-slate-400">{tk.car}</p>}
                          </td>
                          <td className="border-b border-slate-100 px-5 py-3.5">
                            <span className="inline-flex items-center gap-1.5 text-slate-700">
                              <Icon.Wrench className="h-3.5 w-3.5 text-slate-400" />
                              {tk.garage || <span className="text-slate-300">{t('completedRepairs.onSite')}</span>}
                            </span>
                          </td>
                          <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">{tk.requested_by_name || tk.handoffs?.inspected?.name || '—'}</td>
                          <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">{driverOf(tk) || '—'}</td>
                          <td className="border-b border-slate-100 px-5 py-3.5">
                            <span className="text-slate-700">{fmtDate(tk.handoffs?.closed?.at)}</span>
                            <p className="text-xs text-slate-400">{ago(tk.handoffs?.closed?.at)}</p>
                          </td>
                          {SHOW_FINANCIALS && <td className="border-b border-slate-100 px-5 py-3.5 text-right tabular-nums text-slate-700">{tk.cost != null ? fmtAED(tk.cost) : <span className="text-slate-300">—</span>}</td>}
                          <td className="border-b border-slate-100 px-5 py-3.5 text-right">
                            <Link to={`/maintenance-workflow/${tk.id}`} onClick={(e) => e.stopPropagation()} className="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-indigo-600 hover:bg-indigo-50">{t('completedRepairs.open')}</Link>
                          </td>
                        </tr>
                        {isOpen && (
                          <tr className="bg-slate-50/60">
                            <td />
                            <td colSpan={SHOW_FINANCIALS ? 7 : 6} className="border-b border-slate-100 px-5 py-5">
                              <div className="grid gap-6 lg:grid-cols-3">
                                {/* People chain — the full custody story */}
                                <div>
                                  <h4 className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{t('completedRepairs.journey')}</h4>
                                  <ol className="space-y-2.5 text-sm">
                                    <ChainStep label={t('completedRepairs.requested')} name={tk.handoffs?.requested?.name || tk.requested_by_name} at={tk.handoffs?.requested?.at} />
                                    <ChainStep label={t('completedRepairs.inspected')} name={tk.handoffs?.inspected?.name} at={tk.handoffs?.inspected?.at} />
                                    <ChainStep label={t('completedRepairs.dispatched')} name={tk.handoffs?.dispatched?.name} at={tk.handoffs?.dispatched?.at} sub={tk.handoffs?.dispatched?.destination} />
                                    <ChainStep label={t('completedRepairs.atGarage')} name={tk.garage} at={tk.handoffs?.repair_started?.at} />
                                    <ChainStep label={t('completedRepairs.closedStep')} name={tk.handoffs?.closed?.name} at={tk.handoffs?.closed?.at} last />
                                  </ol>
                                </div>

                                {/* Faults fixed */}
                                <div>
                                  <h4 className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{t('completedRepairs.faultsFixed')} ({faults.length})</h4>
                                  {faults.length === 0 ? (
                                    <p className="text-sm text-slate-400">{t('completedRepairs.noFaults')}</p>
                                  ) : (
                                    <ul className="flex flex-wrap gap-1.5">
                                      {faults.map((f, i) => (
                                        <li key={i} className="inline-flex items-center rounded-full bg-white px-2.5 py-1 text-xs font-medium text-slate-700 ring-1 ring-slate-200">
                                          {f.symptom || f.text || f.keyword || t('completedRepairs.fault')}
                                        </li>
                                      ))}
                                    </ul>
                                  )}
                                  {tk.has_video && (
                                    <p className="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-indigo-600">
                                      <Icon.Video className="h-3.5 w-3.5" />
                                      {t('completedRepairs.hasVideo', { n: tk.video_count ?? 0 })}
                                    </p>
                                  )}
                                </div>

                                {/* Facts */}
                                <div>
                                  <h4 className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{t('completedRepairs.details')}</h4>
                                  <dl className="space-y-1.5 text-sm">
                                    <FactRow label={t('completedRepairs.garageLabel')} value={tk.garage} />
                                    <FactRow label={t('completedRepairs.severity')} value={tk.fault_severity_label ? `${tk.fault_severity_emoji || ''} ${tk.fault_severity_label}` : null} />
                                    <FactRow label={t('completedRepairs.odometerIn')} value={tk.receive_odometer != null ? `${Number(tk.receive_odometer).toLocaleString()} km` : null} />
                                    <FactRow label={t('completedRepairs.odometerOut')} value={tk.return_odometer != null ? `${Number(tk.return_odometer).toLocaleString()} km` : null} />
                                    <FactRow label={t('completedRepairs.contract')} value={tk.linked_contract_no} />
                                    <FactRow label={t('completedRepairs.reported')} value={fmtDateTime(tk.created_at)} />
                                    {SHOW_FINANCIALS && <FactRow label={t('completedRepairs.cost')} value={tk.cost != null ? fmtAED(tk.cost) : null} />}
                                  </dl>
                                </div>
                              </div>
                            </td>
                          </tr>
                        )}
                      </Fragment>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

// One step in the custody chain — a labelled who + when, with a connecting rail.
function ChainStep({ label, name, at, sub, last }) {
  const done = Boolean(name || at);
  return (
    <li className="relative flex gap-3 pl-1">
      <span className="mt-1 flex flex-col items-center">
        <span className={`h-2.5 w-2.5 rounded-full ${done ? 'bg-emerald-500' : 'bg-slate-300'}`} />
        {!last && <span className="mt-0.5 h-6 w-px bg-slate-200" />}
      </span>
      <span className="min-w-0">
        <span className="block text-[11px] font-semibold uppercase tracking-wide text-slate-400">{label}</span>
        <span className="block truncate text-slate-800">{name || '—'}</span>
        {sub && <span className="block truncate text-xs text-slate-400">→ {sub}</span>}
        {at && <span className="block text-xs text-slate-400">{fmtDateTime(at)}</span>}
      </span>
    </li>
  );
}

function FactRow({ label, value }) {
  return (
    <div className="flex items-baseline justify-between gap-4">
      <dt className="text-slate-400">{label}</dt>
      <dd className="text-right font-medium text-slate-700">{value || <span className="text-slate-300">—</span>}</dd>
    </div>
  );
}
