import { useState } from 'react';
import { Card } from './ui/Misc';
import DataTable from './ui/Table';
import Icon from './ui/Icon';
import { aed2 } from '../lib/format';
import { useI18n } from '../i18n/I18nContext';

/**
 * Customer-level Account Reconciliation — the same per-category Charged/Settled/Outstanding
 * clarity as the contract page, but rolled up across ALL the customer's contracts. Built
 * entirely from OfficeManager's ledger (backend `category_ledger` + `ledger_totals`); the
 * "Discounts & adjustments" balancing row absorbs anything OM posts outside the categories,
 * so the Outstanding total ALWAYS ties to the customer's Balance to the cent.
 */
export default function CustomerReconciliation({ ledger = [], totals }) {
  const { t } = useI18n();
  const [open, setOpen] = useState(false);
  if (!ledger.length || !totals) return null;

  const balance = Number(totals.outstanding) || 0;
  const balanceTone = balance > 0 ? 'text-red-600' : balance < 0 ? 'text-emerald-600' : 'text-slate-900';

  return (
    <Card>
      <button type="button" onClick={() => setOpen((v) => !v)} aria-expanded={open} className="flex w-full items-center justify-between gap-3 px-6 py-4 text-start transition-colors duration-150 hover:bg-slate-50/60">
        <div className="flex items-center gap-3">
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-indigo-100 text-indigo-600">
            <Icon.Scale className="h-5 w-5" />
          </span>
          <div>
            <h3 className="text-base font-semibold text-slate-900">{t('Account Reconciliation')}</h3>
            <p className="mt-0.5 text-xs text-slate-400">{t("Every charge across all contracts — what was billed, settled, and exactly what's still owed.")}</p>
          </div>
        </div>
        <span className="flex items-center gap-2 text-sm font-medium text-indigo-600">
          {open ? t('Hide') : t('Show')}
          <svg aria-hidden="true" className={`h-4 w-4 transition-transform ${open ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M19 9l-7 7-7-7" /></svg>
        </span>
      </button>

      {open && (
        <div className="border-t border-slate-100 px-6 py-5">
          <div className="overflow-hidden rounded-xl border border-slate-200/60">
            <DataTable
              rows={ledger}
              rowKey={(r) => r.label}
              dense
              zebra={false}
              highlightRow={(r) => r.outstanding > 0}
              empty={t('No ledger entries.')}
              columns={[
                { key: 'label', header: t('Category'), cellClass: 'font-medium text-slate-800', render: (r) => r.label },
                { key: 'charged', header: t('Charged'), align: 'right', cellClass: 'tabular-nums text-slate-600', render: (r) => aed2(r.charged) },
                { key: 'settled', header: t('Settled'), align: 'right', cellClass: 'tabular-nums text-slate-600', render: (r) => aed2(r.settled) },
                {
                  key: 'outstanding', header: t('Outstanding'), align: 'right',
                  tooltip: t('Charged − Settled per category. Red = still owed; green = in the customer’s favour (e.g. a discount).'),
                  render: (r) => (
                    <span className={`tabular-nums font-semibold ${r.outstanding > 0 ? 'text-red-600' : r.outstanding < 0 ? 'text-emerald-600' : 'text-slate-300'}`}>
                      {Number(r.outstanding) === 0 ? '—' : aed2(r.outstanding)}
                    </span>
                  ),
                },
              ]}
            />
            {/* Total row — ties to the customer's Balance to the cent. */}
            <div className="grid grid-cols-4 gap-3 border-t-2 border-slate-200 bg-slate-50/60 px-5 py-3 text-sm font-semibold">
              <span className="text-slate-700">{t('Total')}</span>
              <span className="text-end tabular-nums text-slate-900">{aed2(totals.charged)}</span>
              <span className="text-end tabular-nums text-slate-900">{aed2(totals.settled)}</span>
              <span className={`text-end tabular-nums ${balanceTone}`}>{aed2(balance)}</span>
            </div>
          </div>
          <p className="mt-2 text-xs text-slate-500">
            {t("Outstanding = Charged − Settled, per category, summed across every contract — this is OfficeManager's ledger and totals exactly to the customer's {term} ({amount}). Red rows are still owed; green rows are in the customer's favour (e.g. discounts).", {
              term: balance < 0 ? t('credit / wallet') : t('balance'),
              amount: aed2(Math.abs(balance)),
            })}
          </p>
        </div>
      )}
    </Card>
  );
}
