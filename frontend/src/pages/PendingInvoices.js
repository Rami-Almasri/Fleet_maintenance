// Invoice Tracker (/invoices/pending-submission) — every maintenance ticket whose repair is DONE and the
// car is back in service, but the paper invoice hasn't landed yet (workflow_status = awaiting_invoice).
// Oldest-waiting first; anything past the SLA is flagged red. Each row deep-links to the ticket (where the
// garage link / line-items entry live) and offers a quick "Mark received" to close it out once the invoice
// arrives. Backed by GET /maintenance-tickets/pending-invoices; mirrors the daily 3-day overdue scan.

import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import { usePermissions } from '../hooks/usePermissions';
import { useI18n } from '../i18n/I18nContext';
import { useToast } from '../components/ui/Toast';
import Icon from '../components/ui/Icon';
import Button from '../components/ui/Button';
import { Skeleton } from '../components/ui/Skeleton';
import { PageHeader, EmptyState } from '../components/ui/Misc';
import { SHOW_FINANCIALS } from '../config/features';

const fmtAED = (n) => `AED ${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' }) : '—');

export default function PendingInvoices() {
  const { t } = useI18n();
  const toast = useToast();
  const { can } = usePermissions();

  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [busyId, setBusyId] = useState(null);

  const load = useCallback(async () => {
    try {
      const res = await api.get('/maintenance-tickets/pending-invoices');
      setData(res.data.data);
    } catch (_) {
      // keep last good state
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);
  useEffect(() => { const id = setInterval(load, 30000); return () => clearInterval(id); }, [load]);

  const markReceived = async (ticket) => {
    setBusyId(ticket.id);
    try {
      await api.post(`/maintenance-tickets/${ticket.id}/finalize-invoice`);
      toast.success(t('pendingInvoices.received', { plate: ticket.plate || `#${ticket.id}` }));
      await load();
    } catch (e) {
      toast.error(e?.response?.data?.msg || t('pendingInvoices.error'));
    } finally {
      setBusyId(null);
    }
  };

  const tickets = data?.tickets || [];
  const manage = can('maintenance.manage');

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1200px] space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title={t('pendingInvoices.title')} subtitle={t('pendingInvoices.subtitle', { days: data?.sla_days ?? 3 })}>
          <div className="rounded-xl border border-slate-200/60 bg-white px-4 py-3 text-center shadow-soft">
            <p className="text-2xl font-bold tabular-nums text-slate-900">{data?.total ?? 0}</p>
            <p className="text-[11px] uppercase tracking-wide text-slate-400">{t('pendingInvoices.pending')}</p>
          </div>
          <div className={`rounded-xl border px-4 py-3 text-center shadow-soft ${data?.overdue ? 'border-red-200 bg-red-50' : 'border-slate-200/60 bg-white'}`}>
            <p className={`text-2xl font-bold tabular-nums ${data?.overdue ? 'text-red-600' : 'text-slate-900'}`}>{data?.overdue ?? 0}</p>
            <p className="text-[11px] uppercase tracking-wide text-slate-400">{t('pendingInvoices.overdue')}</p>
          </div>
        </PageHeader>

        {loading ? (
          <Skeleton className="h-64 rounded-2xl" />
        ) : tickets.length === 0 ? (
          <div className="overflow-hidden rounded-2xl border border-slate-200/60 bg-white shadow-soft">
            <EmptyState icon={<Icon.Check className="h-6 w-6 text-emerald-500" />} title={t('pendingInvoices.emptyTitle')} message={t('pendingInvoices.emptyBody')} />
          </div>
        ) : (
          <div className="overflow-hidden rounded-2xl border border-slate-200/60 bg-white shadow-soft">
            <div className="overflow-x-auto">
            <table className="min-w-full border-separate border-spacing-0 text-sm">
              <thead className="bg-slate-50/90 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 font-semibold">{t('pendingInvoices.vehicle')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 font-semibold">{t('pendingInvoices.garage')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 font-semibold">{t('pendingInvoices.since')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 font-semibold">{t('pendingInvoices.waiting')}</th>
                  {SHOW_FINANCIALS && <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-right font-semibold">{t('pendingInvoices.cost')}</th>}
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-right font-semibold">{t('pendingInvoices.actions')}</th>
                </tr>
              </thead>
              <tbody>
                {tickets.map((tk) => (
                  <tr key={tk.id} className={`transition-colors hover:bg-indigo-50/40 ${tk.invoice_overdue ? 'bg-red-50/50' : 'bg-white even:bg-slate-50/40'}`}>
                    <td className="border-b border-slate-100 px-5 py-3.5">
                      <Link to={`/maintenance-workflow/${tk.id}`} className="font-mono font-semibold text-slate-900 hover:text-indigo-600">{tk.plate || `#${tk.id}`}</Link>
                      {tk.car && <p className="text-xs text-slate-400">{tk.car}</p>}
                    </td>
                    <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">{tk.garage || '—'}</td>
                    <td className="border-b border-slate-100 px-5 py-3.5 text-slate-500">{fmtDate(tk.awaiting_invoice_since)}</td>
                    <td className="border-b border-slate-100 px-5 py-3.5">
                      <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ${tk.invoice_overdue ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600'}`}>
                        {tk.invoice_overdue && <Icon.Alert className="h-3 w-3" />}
                        {t('pendingInvoices.daysWaiting', { n: tk.invoice_days_waiting ?? 0 })}
                      </span>
                    </td>
                    {SHOW_FINANCIALS && <td className="border-b border-slate-100 px-5 py-3.5 text-right tabular-nums text-slate-700">{tk.cost != null ? fmtAED(tk.cost) : <span className="text-slate-300">{t('pendingInvoices.noCost')}</span>}</td>}
                    <td className="border-b border-slate-100 px-5 py-3.5">
                      <div className="flex items-center justify-end gap-2">
                        <Link to={`/maintenance-workflow/${tk.id}`} className="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-indigo-600 hover:bg-indigo-50">{t('pendingInvoices.open')}</Link>
                        {manage && (
                          <Button size="sm" variant="secondary" disabled={busyId === tk.id} onClick={() => markReceived(tk)}>
                            {t('pendingInvoices.markReceived')}
                          </Button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
