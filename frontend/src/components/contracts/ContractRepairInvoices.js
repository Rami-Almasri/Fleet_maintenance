// EVERY BILL RAISED AGAINST THIS CONTRACT.
//
// A workshop visit is billed by more than one party: the garage that did the work invoices for the
// repair, and the supplier that sold the parts invoices separately for those. Both were recorded, but
// each answered only to its own ticket — so the contract that paid for the visit showed a total with no
// paper behind it, and "what were we billed for this contract?" meant opening every ticket in turn.
//
// This lists both kinds together, each openable at its source. It never re-adds the money: the totals
// come from the documents themselves.
//
// A SUPPLIER BILL CAN COVER SEVERAL CARS. Showing its full total here would charge this contract for
// another car's parts, so a shared invoice shows BOTH figures — what the whole invoice came to, and the
// share of it that is this contract's — and only the share is counted in the total.

import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import { CommandPanel } from '../ops';
import { Spinner } from '../ui/Misc';
import Badge from '../ui/Badge';
import { aed2, fmtDate } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

export default function ContractRepairInvoices({ contractId }) {
  const { t } = useI18n();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!contractId) return undefined;

    let alive = true;
    setLoading(true);
    api.get(`/Contract/${contractId}/repair-invoices`)
      .then(({ data: res }) => alive && setData(res?.data || null))
      .catch(() => alive && setError(t('contractDetail.repairInvoices.error')))
      .finally(() => alive && setLoading(false));
    return () => { alive = false; };
  }, [contractId, t]);

  if (loading) return <CommandPanel title={t('contractDetail.repairInvoices.title')} dotColor="#f59e0b"><Spinner /></CommandPanel>;
  if (error) return <CommandPanel title={t('contractDetail.repairInvoices.title')} dotColor="#f59e0b"><p className="text-sm" style={{ color: 'var(--ink-3)' }}>{error}</p></CommandPanel>;

  const garage = data?.garage_invoices || [];
  const supplier = data?.supplier_invoices || [];

  // A contract with no repairs is the common case for a rental — say so plainly rather than showing
  // an empty table that reads like something failed to load.
  if (!garage.length && !supplier.length) {
    return (
      <CommandPanel title={t('contractDetail.repairInvoices.title')} dotColor="#f59e0b">
        <p className="text-sm" style={{ color: 'var(--ink-3)' }}>{t('contractDetail.repairInvoices.empty')}</p>
      </CommandPanel>
    );
  }

  const totals = data?.totals || {};

  return (
    <CommandPanel title={t('contractDetail.repairInvoices.title')} dotColor="#f59e0b">
      {/* WHAT THIS CONTRACT WAS BILLED, split by who billed it. */}
      <div className="mb-4 grid grid-cols-3 gap-3">
        {[
          ['garage', totals.garage],
          ['supplier', totals.supplier],
          ['all', totals.all],
        ].map(([key, value]) => (
          <div key={key} className="rounded-lg px-3 py-2" style={{ background: 'var(--surface-2)', border: '1px solid var(--line)' }}>
            <p className="text-[11px] uppercase tracking-wide" style={{ color: 'var(--ink-3)' }}>{t(`contractDetail.repairInvoices.totals.${key}`)}</p>
            <p className={`tabular-nums ${key === 'all' ? 'text-base font-bold' : 'text-sm font-semibold'}`} style={{ color: 'var(--ink)' }}>{aed2(value || 0)}</p>
          </div>
        ))}
      </div>

      {garage.length > 0 && (
        <section className="mb-4">
          <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide" style={{ color: 'var(--ink-3)' }}>
            {t('contractDetail.repairInvoices.garageHeading')}
          </p>
          <ul className="space-y-2">
            {garage.map((inv) => (
              <li key={`g-${inv.id}`} className="rounded-lg px-3 py-2.5" style={{ background: 'var(--surface-2)', border: '1px solid var(--line)' }}>
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                  {/* Who billed it. An in-house cost has no garage by design, and says so. */}
                  <span className="text-sm font-medium" style={{ color: 'var(--ink)' }} dir="auto">
                    {inv.is_internal ? t('contractDetail.repairInvoices.inHouse') : (inv.vendor_name || t('contractDetail.repairInvoices.unnamedGarage'))}
                  </span>
                  {inv.invoice_no && <span className="text-xs" style={{ color: 'var(--ink-3)' }}>#{inv.invoice_no}</span>}
                  {inv.reconciliation_status === 'flagged' && (
                    <Badge tone="amber">{t('contractDetail.repairInvoices.flagged')}</Badge>
                  )}
                  <span className="ms-auto text-sm font-semibold tabular-nums" style={{ color: 'var(--ink)' }}>{aed2(inv.amount)}</span>
                </div>

                {/* What the bill was for, in the words of the faults it covered. */}
                {inv.covers?.length > 0 && (
                  <p className="mt-1 text-xs" style={{ color: 'var(--ink-3)' }} dir="auto">{inv.covers.join(' · ')}</p>
                )}

                <div className="mt-1.5 flex flex-wrap items-center gap-x-3 text-[11px]" style={{ color: 'var(--ink-3)' }}>
                  <span>{t('contractDetail.repairInvoices.partsLabor', { parts: aed2(inv.parts_total), labor: aed2(inv.labor_total) })}</span>
                  {inv.recorded_at && <span>{fmtDate(inv.recorded_at)}</span>}
                  <Link to={`/maintenance-workflow/${inv.maintenance_id}`} className="font-medium" style={{ color: 'var(--accent)' }}>
                    {t('contractDetail.repairInvoices.openTicket')}
                  </Link>
                </div>
              </li>
            ))}
          </ul>
        </section>
      )}

      {supplier.length > 0 && (
        <section>
          <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide" style={{ color: 'var(--ink-3)' }}>
            {t('contractDetail.repairInvoices.supplierHeading')}
          </p>
          <ul className="space-y-2">
            {supplier.map((inv) => (
              <li key={`s-${inv.id}`} className="rounded-lg px-3 py-2.5" style={{ background: 'var(--surface-2)', border: '1px solid var(--line)' }}>
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                  <span className="text-sm font-medium" style={{ color: 'var(--ink)' }} dir="auto">{inv.vendor_name}</span>
                  {inv.invoice_no && <span className="text-xs" style={{ color: 'var(--ink-3)' }}>#{inv.invoice_no}</span>}
                  <span className="ms-auto text-sm font-semibold tabular-nums" style={{ color: 'var(--ink)' }}>{aed2(inv.contract_share)}</span>
                </div>

                {inv.parts?.length > 0 && (
                  <p className="mt-1 text-xs" style={{ color: 'var(--ink-3)' }} dir="auto">{inv.parts.join(' · ')}</p>
                )}

                <div className="mt-1.5 flex flex-wrap items-center gap-x-3 text-[11px]" style={{ color: 'var(--ink-3)' }}>
                  {/* Only a bill that ALSO covers other cars needs explaining — a bill entirely for this
                      contract would just be repeating its own total. */}
                  {inv.is_shared && (
                    <span>{t('contractDetail.repairInvoices.sharedInvoice', { total: aed2(inv.total_amount) })}</span>
                  )}
                  {inv.invoice_date && <span>{fmtDate(inv.invoice_date)}</span>}
                  <Link to={`/part-invoices?invoice=${inv.id}`} className="font-medium" style={{ color: 'var(--accent)' }}>
                    {t('contractDetail.repairInvoices.openInvoice')}
                  </Link>
                </div>
              </li>
            ))}
          </ul>
        </section>
      )}

      {/* Traceability: the page says where its numbers came from, never a total with no origin. */}
      <p className="mt-3 text-[11px]" style={{ color: 'var(--ink-3)' }}>{t('contractDetail.repairInvoices.origin')}</p>
    </CommandPanel>
  );
}
