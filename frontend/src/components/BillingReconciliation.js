import { useMemo, useState } from 'react';
import { Card } from './ui/Misc';
import DataTable from './ui/Table';
import { InfoTip } from './ui/Tooltip';
import Icon from './ui/Icon';
import { aed2 } from '../lib/format';

const round2 = (n) => Math.round((Number(n) || 0) * 100) / 100;

// OfficeManager's contract CHARGE categories (debit/credit field prefixes). Deposit is
// intentionally excluded: it's a refundable security hold OM keeps OUT of contract_debit/
// credit/balance, so including it would make the rows sum 2,000 higher than the Total.
const CATEGORIES = [
  ['Rent', 'rents'],
  ['Salik (tolls)', 'salik'],
  ['VAT', 'vat'],
  ['Damages', 'damages'],
  ['Breaches', 'breachs'],
  ['Extra charges', 'extra_charges'],
  ['Fuel', 'fuel'],
  ['KM', 'km'],
  ['GPS', 'gps'],
  ['CDW', 'cdw'],
  ['Extra driver', 'extra_driver'],
  ['Co-driver', 'co_driver'],
  ['Cardoo', 'cardoo'],
];

/**
 * Billing Reconciliation — the "before & after" of a contract's money, built ONLY from
 * OfficeManager's authoritative figures (never recomputed):
 *   1. Account ledger, per category: Charged vs Settled vs Outstanding. The total reconciles
 *      to OM's own Balance, and each row shows exactly which charge is still owed.
 *   2. Invoice billing: Gross → discounts/credit notes → Net billed, proving the discount
 *      was applied on the invoices (not lost).
 * The two are OM's separate records; the Account ledger is the source of the Balance.
 */
export default function BillingReconciliation({ contract: c }) {
  const [open, setOpen] = useState(false);

  const rows = useMemo(
    () =>
      CATEGORIES.map(([label, key]) => {
        const charged = Number(c[`${key}_debit`]) || 0;
        const settled = Number(c[`${key}_credit`]) || 0;
        return { label, charged, settled, outstanding: round2(charged - settled) };
      }).filter((r) => r.charged !== 0 || r.settled !== 0),
    [c],
  );

  const totalCharged = Number(c.contract_debit) || 0;
  const totalSettled = Number(c.contract_credit) || 0;
  const balance = Number(c.contract_balance) || 0;

  // ---- Net Payable view -------------------------------------------------------------
  // The TRUE amount collectable. A "wash" entry — a charge raised then fully reversed or
  // settled (charged === settled, e.g. an accident breach charged then credited back) —
  // contributes nothing to the balance, so it is EXCLUDED here to stop it inflating (and
  // confusing) the payable. What remains is exactly the charges that still carry a balance.
  const payableRows = rows.filter((r) => round2(r.outstanding) !== 0);
  const washTotal   = round2(rows.filter((r) => round2(r.outstanding) === 0).reduce((s, r) => s + r.charged, 0));
  // Anchor to OM's authoritative totals (contract_debit/credit) minus the wash entries, NOT a
  // sum of category rows — so the view still reconciles to the Balance when contract_debit
  // carries an amount outside the named per-category fields. Washes net to zero, so removing
  // them changes which rows show but never the balance.
  const payableCharged = round2(totalCharged - washTotal);
  const payableSettled = round2(totalSettled - washTotal);
  // Residual = the part of the balance not attributable to a named category (e.g. an OM
  // adjustment baked straight into contract_debit). Keeps the itemised list summing to Balance.
  const itemisedOwed = round2(payableRows.reduce((s, r) => s + r.outstanding, 0));
  const residualOwed = round2(balance - itemisedOwed);

  // Discount is a RECORDED ADJUSTMENT on the deal — never a credit sitting on the customer's
  // account and never a refund. Shown for transparency only; it does NOT move the balance.
  const invoices = c.invoices || [];
  const discount = round2(invoices.reduce((s, i) => s + (Number(i.discount) || 0), 0)) || (Number(c.contract_discount) || 0);

  // Illustrative per-day rent before vs after spreading the discount over the rental days.
  const dayPrice  = Number(c.day_price) || 0;
  const rentDays  = Number(c.days) || 0;
  const grossRent = round2(Number(c.rents_debit) || dayPrice * rentDays);
  const dayPriceAfter = rentDays > 0 ? round2(Math.max(0, grossRent - discount) / rentDays) : 0;
  const showRentDelta = discount > 0 && dayPrice > 0 && rentDays > 0;

  if (rows.length === 0) return null;

  const balanceTone = balance > 0 ? 'text-red-600' : balance < 0 ? 'text-emerald-600' : 'text-slate-900';

  return (
    <Card className="p-6">
      <button type="button" onClick={() => setOpen((v) => !v)} className="flex w-full items-center justify-between gap-3 text-left">
        <div className="flex items-center gap-3">
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-indigo-100 text-indigo-600">
            <Icon.Invoice className="h-5 w-5" />
          </span>
          <div>
            <h3 className="text-base font-semibold text-slate-900">Billing Reconciliation</h3>
            <p className="mt-0.5 text-xs text-slate-400">Every charge: what was billed, what's been settled, the discount on record, and exactly what's still owed.</p>
          </div>
        </div>
        <span className="flex items-center gap-2 text-sm font-medium text-indigo-600">
          {open ? 'Hide' : 'Show'}
          <svg className={`h-4 w-4 transition-transform ${open ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M19 9l-7 7-7-7" /></svg>
        </span>
      </button>

      {open && (
        <div className="mt-5 space-y-6">
          {/* 1. Account ledger — per category, reconciles to OM's Balance */}
          <div>
            <h4 className="mb-2 flex items-center gap-1.5 text-sm font-semibold text-slate-700">
              Account ledger — what's charged, settled &amp; outstanding
              <InfoTip content="OfficeManager's own per-category ledger. Charged − Settled = Outstanding, and the total ties exactly to the contract Balance." />
            </h4>
            <div className="overflow-hidden rounded-xl border border-slate-200/60">
              <DataTable
                rows={rows}
                rowKey={(r) => r.label}
                dense
                zebra={false}
                highlightRow={(r) => r.outstanding !== 0}
                empty="No charges on this contract."
                columns={[
                  { key: 'label', header: 'Category', cellClass: 'font-medium text-slate-800', render: (r) => r.label },
                  { key: 'charged', header: 'Charged', align: 'right', cellClass: 'tabular-nums text-slate-600', render: (r) => aed2(r.charged) },
                  { key: 'settled', header: 'Settled', align: 'right', cellClass: 'tabular-nums text-slate-600', render: (r) => aed2(r.settled) },
                  {
                    key: 'outstanding', header: 'Outstanding', align: 'right',
                    tooltip: 'Charged − Settled. The highlighted rows are the charges still owed.',
                    render: (r) => (
                      <span className={`tabular-nums font-semibold ${r.outstanding > 0 ? 'text-red-600' : r.outstanding < 0 ? 'text-emerald-600' : 'text-slate-300'}`}>
                        {r.outstanding === 0 ? '—' : aed2(r.outstanding)}
                      </span>
                    ),
                  },
                ]}
              />
              {/* Total row — reconciles to OM's Balance. */}
              <div className="grid grid-cols-4 gap-3 border-t-2 border-slate-200 bg-slate-50/60 px-5 py-3 text-sm font-semibold">
                <span className="text-slate-700">Total</span>
                <span className="text-right tabular-nums text-slate-900">{aed2(totalCharged)}</span>
                <span className="text-right tabular-nums text-slate-900">{aed2(totalSettled)}</span>
                <span className={`text-right tabular-nums ${balanceTone}`}>{aed2(balance)}</span>
              </div>
            </div>
            <p className="mt-2 text-xs text-slate-500">
              Outstanding = Charged − Settled, per category — this is OfficeManager's own ledger and totals exactly to the <span className="font-medium text-slate-700">Balance ({aed2(balance)})</span>. The highlighted row is the charge that's still owed.
            </p>
          </div>

          {/* 2. Net Payable — only real, still-owed charges (wash entries excluded) */}
          {(Math.abs(balance) >= 0.01 || discount > 0) && (
            <div>
              <h4 className="mb-2 flex items-center gap-1.5 text-sm font-semibold text-slate-700">
                What's actually owed
                <InfoTip content="The true collectable amount. Fully-settled / reversed 'wash' entries are excluded so the total equals OfficeManager's Balance." />
              </h4>
              <div className="rounded-xl border border-slate-200/60 p-4">
                <dl className="space-y-1.5 text-sm">
                  <div className="flex justify-between">
                    <dt className="text-slate-500">Total invoiced <span className="text-slate-400">(payable charges — wash entries excluded)</span></dt>
                    <dd className="font-medium tabular-nums text-slate-800">{aed2(payableCharged)}</dd>
                  </div>
                  <div className="flex justify-between text-emerald-700">
                    <dt>Less settled <span className="text-emerald-600/70">(cash received)</span></dt>
                    <dd className="font-medium tabular-nums">− {aed2(payableSettled)}</dd>
                  </div>
                  <div className={`flex justify-between border-t border-slate-200 pt-1.5 text-base font-semibold ${balanceTone}`}>
                    <dt>{balance < 0 ? 'Credit due to customer' : 'Current balance owed'}</dt>
                    <dd className="tabular-nums">{aed2(Math.abs(balance))}</dd>
                  </div>
                </dl>
                {(payableRows.length > 0 || Math.abs(residualOwed) >= 0.01) && (
                  <div className="mt-3 border-t border-slate-100 pt-3">
                    <p className="mb-1 text-xs font-medium text-slate-500">Still owed, by charge:</p>
                    <ul className="space-y-0.5 text-xs text-slate-600">
                      {payableRows.map((r) => (
                        <li key={r.label} className="flex justify-between">
                          <span>{r.label}</span>
                          <span className={`font-medium tabular-nums ${r.outstanding > 0 ? 'text-red-600' : 'text-emerald-600'}`}>{aed2(r.outstanding)}</span>
                        </li>
                      ))}
                      {Math.abs(residualOwed) >= 0.01 && (
                        <li className="flex justify-between">
                          <span>Other / uncategorised</span>
                          <span className={`font-medium tabular-nums ${residualOwed > 0 ? 'text-red-600' : 'text-emerald-600'}`}>{aed2(residualOwed)}</span>
                        </li>
                      )}
                    </ul>
                  </div>
                )}
              </div>

              {/* Discount — a recorded adjustment on the deal, shown as a distinct callout so it
                  is never mistaken for a credit/refund on the customer's balance. */}
              {discount > 0 && (
                <div className="relative mt-3 overflow-hidden rounded-2xl border border-amber-200/70 bg-gradient-to-br from-amber-50 via-amber-50 to-orange-100/40 p-4">
                  <svg aria-hidden className="pointer-events-none absolute -right-4 -top-4 h-24 w-24 text-amber-200/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" strokeLinejoin="round"><path d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a3 3 0 0 1 0 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a3 3 0 0 1 0-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375Z" /></svg>
                  <div className="relative flex items-center gap-4">
                    <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-600 ring-1 ring-inset ring-amber-200">
                      <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"><path d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a3 3 0 0 1 0 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a3 3 0 0 1 0-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375Z" /></svg>
                    </span>
                    <div className="min-w-0 flex-1">
                      <div className="flex items-baseline gap-2">
                        <span className="text-xs font-semibold uppercase tracking-wide text-amber-700/80">Discount applied</span>
                        <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-700 ring-1 ring-inset ring-amber-200">Recorded adjustment</span>
                      </div>
                      <p className="mt-0.5 text-2xl font-bold tracking-tight tabular-nums text-amber-700">− {aed2(discount)}</p>
                      <p className="mt-0.5 text-xs text-amber-800/70">Given on the contract — <span className="font-medium">not a refundable balance</span> and not subtracted from what's owed.</p>
                    </div>
                  </div>
                  {showRentDelta && (
                    <div className="relative mt-3 flex items-center gap-3 rounded-xl bg-white/60 p-3 ring-1 ring-inset ring-amber-200/70">
                      <span className="text-[11px] font-semibold uppercase tracking-wide text-amber-700/70">Rent / day</span>
                      <span className="text-sm text-amber-900/50 line-through tabular-nums">{aed2(dayPrice)}</span>
                      <svg className="h-4 w-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M13 5l7 7-7 7M5 12h15" /></svg>
                      <span className="text-base font-bold tabular-nums text-emerald-600">{aed2(dayPriceAfter)}</span>
                      <span className="ml-auto text-[11px] text-amber-700/60">over {rentDays} {rentDays === 1 ? 'day' : 'days'}</span>
                    </div>
                  )}
                </div>
              )}

              <p className="mt-2 text-xs text-slate-500">
                Only charges that still carry a balance are shown; fully-settled and reversed “wash” entries (a charge raised then credited back, e.g. an accident breach) are excluded so the total is exactly what's collectable — OfficeManager's Balance ({aed2(balance)}).
              </p>
            </div>
          )}
        </div>
      )}
    </Card>
  );
}
