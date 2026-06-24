import { useState } from 'react';
import { Card } from './ui/Misc';
import { aed2 } from '../lib/format';

/**
 * Customer-level Account Reconciliation — the same per-category Charged/Settled/Outstanding
 * clarity as the contract page, but rolled up across ALL the customer's contracts. Built
 * entirely from OfficeManager's ledger (backend `category_ledger` + `ledger_totals`); the
 * "Discounts & adjustments" balancing row absorbs anything OM posts outside the categories,
 * so the Outstanding total ALWAYS ties to the customer's Balance to the cent.
 */
export default function CustomerReconciliation({ ledger = [], totals }) {
  const [open, setOpen] = useState(false);
  if (!ledger.length || !totals) return null;

  const balance = Number(totals.outstanding) || 0;

  return (
    <Card>
      <button type="button" onClick={() => setOpen((v) => !v)} className="flex w-full items-center justify-between gap-3 px-6 py-4 text-left">
        <div>
          <h3 className="text-base font-semibold text-slate-900">Account Reconciliation</h3>
          <p className="mt-0.5 text-xs text-slate-400">Every charge across all contracts — what was billed, settled, and exactly what's still owed.</p>
        </div>
        <span className="flex items-center gap-2 text-sm font-medium text-indigo-600">
          {open ? 'Hide' : 'Show'}
          <svg className={`h-4 w-4 transition-transform ${open ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M19 9l-7 7-7-7" /></svg>
        </span>
      </button>

      {open && (
        <div className="border-t border-slate-100 px-6 py-5">
          <div className="overflow-x-auto rounded-xl border border-gray-100">
            <table className="min-w-full divide-y divide-gray-100 text-sm">
              <thead className="bg-gray-50/60">
                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                  <th className="px-4 py-2">Category</th>
                  <th className="px-4 py-2 text-right">Charged</th>
                  <th className="px-4 py-2 text-right">Settled</th>
                  <th className="px-4 py-2 text-right">Outstanding</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {ledger.map((r) => (
                  <tr key={r.label} className={r.outstanding > 0 ? 'bg-red-50/40' : r.outstanding < 0 ? 'bg-emerald-50/40' : ''}>
                    <td className="px-4 py-2 font-medium text-gray-800">{r.label}</td>
                    <td className="px-4 py-2 text-right text-gray-600">{aed2(r.charged)}</td>
                    <td className="px-4 py-2 text-right text-gray-600">{aed2(r.settled)}</td>
                    <td className={`px-4 py-2 text-right font-semibold ${r.outstanding > 0 ? 'text-red-600' : r.outstanding < 0 ? 'text-emerald-600' : 'text-gray-300'}`}>
                      {Number(r.outstanding) === 0 ? '—' : aed2(r.outstanding)}
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr className="border-t-2 border-gray-200 bg-gray-50/60 font-semibold">
                  <td className="px-4 py-2 text-gray-700">Total</td>
                  <td className="px-4 py-2 text-right text-gray-900">{aed2(totals.charged)}</td>
                  <td className="px-4 py-2 text-right text-gray-900">{aed2(totals.settled)}</td>
                  <td className={`px-4 py-2 text-right ${balance > 0 ? 'text-red-600' : balance < 0 ? 'text-emerald-600' : 'text-gray-900'}`}>{aed2(balance)}</td>
                </tr>
              </tfoot>
            </table>
          </div>
          <p className="mt-2 text-xs text-gray-500">
            Outstanding = Charged − Settled, per category, summed across every contract — this is OfficeManager's ledger and totals exactly to the customer's{' '}
            <span className="font-medium text-gray-700">{balance < 0 ? 'credit / wallet' : 'balance'} ({aed2(Math.abs(balance))})</span>.
            {' '}Red rows are still owed; green rows are in the customer's favour (e.g. discounts).
          </p>
        </div>
      )}
    </Card>
  );
}
