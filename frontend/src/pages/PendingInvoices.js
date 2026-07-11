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
        {/* Header */}
        <div className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <h1 className="font-display text-2xl font-bold tracking-tight text-slate-900">{t('pendingInvoices.title')}</h1>
            <p className="mt-1 text-sm text-slate-500">{t('pendingInvoices.subtitle', { days: data?.sla_days ?? 3 })}</p>
          </div>
          <div className="flex gap-3">
            <div className="rounded-2xl bg-white px-4 py-3 text-center shadow-sm ring-1 ring-slate-200">
              <p className="text-2xl font-bold tabular-nums text-slate-900">{data?.total ?? 0}</p>
              <p className="text-[11px] uppercase tracking-wide text-slate-400">{t('pendingInvoices.pending')}</p>
            </div>
            <div className={`rounded-2xl px-4 py-3 text-center shadow-sm ring-1 ${data?.overdue ? 'bg-red-50 ring-red-200' : 'bg-white ring-slate-200'}`}>
              <p className={`text-2xl font-bold tabular-nums ${data?.overdue ? 'text-red-600' : 'text-slate-900'}`}>{data?.overdue ?? 0}</p>
              <p className="text-[11px] uppercase tracking-wide text-slate-400">{t('pendingInvoices.overdue')}</p>
            </div>
          </div>
        </div>

        {loading ? (
          <Skeleton className="h-64 rounded-2xl" />
        ) : tickets.length === 0 ? (
          <div className="flex flex-col items-center justify-center rounded-2xl bg-white py-20 text-center shadow-sm ring-1 ring-slate-200">
            <Icon.Check className="h-10 w-10 text-emerald-500" />
            <p className="mt-3 text-sm font-medium text-slate-700">{t('pendingInvoices.emptyTitle')}</p>
            <p className="text-xs text-slate-400">{t('pendingInvoices.emptyBody')}</p>
          </div>
        ) : (
          <div className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
            <table className="w-full text-sm">
              <thead className="border-b border-slate-100 bg-slate-50/70 text-left text-xs uppercase tracking-wide text-slate-400">
                <tr>
                  <th className="px-4 py-3 font-semibold">{t('pendingInvoices.vehicle')}</th>
                  <th className="px-4 py-3 font-semibold">{t('pendingInvoices.garage')}</th>
                  <th className="px-4 py-3 font-semibold">{t('pendingInvoices.since')}</th>
                  <th className="px-4 py-3 font-semibold">{t('pendingInvoices.waiting')}</th>
                  {SHOW_FINANCIALS && <th className="px-4 py-3 text-right font-semibold">{t('pendingInvoices.cost')}</th>}
                  <th className="px-4 py-3 text-right font-semibold">{t('pendingInvoices.actions')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {tickets.map((tk) => (
                  <tr key={tk.id} className={tk.invoice_overdue ? 'bg-red-50/40' : ''}>
                    <td className="px-4 py-3">
                      <Link to={`/maintenance-workflow/${tk.id}`} className="font-mono font-semibold text-slate-900 hover:text-indigo-600">{tk.plate || `#${tk.id}`}</Link>
                      {tk.car && <p className="text-xs text-slate-400">{tk.car}</p>}
                    </td>
                    <td className="px-4 py-3 text-slate-600">{tk.garage || '—'}</td>
                    <td className="px-4 py-3 text-slate-500">{fmtDate(tk.awaiting_invoice_since)}</td>
                    <td className="px-4 py-3">
                      <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ${tk.invoice_overdue ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600'}`}>
                        {tk.invoice_overdue && <Icon.Alert className="h-3 w-3" />}
                        {t('pendingInvoices.daysWaiting', { n: tk.invoice_days_waiting ?? 0 })}
                      </span>
                    </td>
                    {SHOW_FINANCIALS && <td className="px-4 py-3 text-right tabular-nums text-slate-700">{tk.cost != null ? fmtAED(tk.cost) : <span className="text-slate-300">{t('pendingInvoices.noCost')}</span>}</td>}
                    <td className="px-4 py-3">
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
        )}
      </div>
    </div>
  );
}
