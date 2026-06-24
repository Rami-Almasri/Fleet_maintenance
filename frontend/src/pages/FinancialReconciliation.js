import { useState, useCallback, useEffect } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../api/client';
import Badge from '../components/ui/Badge';
import { Card, PageHeader, Spinner, EmptyState } from '../components/ui/Misc';
import { aed2, fmtDate } from '../lib/format';

// Verdict styling per reconciliation status.
const STATUS = {
  reconciled: { tone: 'green', dot: 'bg-emerald-500', title: 'Reconciled', ring: 'ring-emerald-200', head: 'bg-emerald-50 text-emerald-800', blurb: 'Net cash collected matches the amount billed, within tolerance, and the contract is posted to the books.' },
  review: { tone: 'amber', dot: 'bg-amber-500', title: 'Needs review', ring: 'ring-amber-200', head: 'bg-amber-50 text-amber-800', blurb: 'The cash gap is beyond the fee/rounding tolerance — worth a look.' },
  exception: { tone: 'red', dot: 'bg-red-500', title: 'Exception', ring: 'ring-red-200', head: 'bg-red-50 text-red-800', blurb: 'A reconciliation problem was found that needs fixing.' },
  error: { tone: 'gray', dot: 'bg-slate-400', title: 'Could not reconcile', ring: 'ring-slate-200', head: 'bg-slate-50 text-slate-700', blurb: 'One or more accounting feeds could not be read (the OfficeManager server may be slow). Try again.' },
};

// A labelled money row inside a comparison column.
function Line({ label, value, hint, strong, tone = 'text-slate-900' }) {
  return (
    <div className="flex items-baseline justify-between gap-3 py-1.5">
      <span className="text-sm text-slate-500">{label}{hint && <span className="ml-1 text-xs text-slate-400">{hint}</span>}</span>
      <span className={`tabular-nums ${strong ? 'text-base font-semibold' : 'text-sm'} ${tone}`}>{value}</span>
    </div>
  );
}

function ColumnCard({ title, subtitle, children, error }) {
  return (
    <Card className="ring-1 ring-slate-200/60">
      <div className="border-b border-slate-100 bg-slate-50/60 px-5 py-3">
        <h3 className="text-sm font-semibold text-slate-800">{title}</h3>
        {subtitle && <p className="mt-0.5 text-xs text-slate-400">{subtitle}</p>}
      </div>
      <div className="px-5 py-3">
        {error
          ? <p className="py-3 text-sm text-red-600">Couldn’t load: {error}</p>
          : children}
      </div>
    </Card>
  );
}

export default function FinancialReconciliation() {
  const [params, setParams] = useSearchParams();
  const [input, setInput] = useState(params.get('contract_no') || '');
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const run = useCallback(async (contractNo) => {
    const no = String(contractNo || '').trim();
    if (!no) return;
    setLoading(true);
    setError('');
    setData(null);
    setParams({ contract_no: no });
    try {
      const res = await api.get('/Reconciliation', { params: { contract_no: no } });
      setData(res.data.data);
    } catch (err) {
      setError(err.response?.data?.message || err.message || 'Lookup failed');
    } finally {
      setLoading(false);
    }
  }, [setParams]);

  const onSubmit = (e) => {
    e.preventDefault();
    run(input);
  };

  // Arriving from a "Reconcile" shortcut (?contract_no=…) runs the lookup immediately.
  useEffect(() => {
    const no = params.get('contract_no');
    if (no) run(no);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const r = data?.reconciliation;
  const sev = r ? (STATUS[r.status] || STATUS.review) : null;

  return (
    <div className="py-8">
      <div className="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Financial Reconciliation"
          subtitle="Bridge one contract to the official accounting system. Side-by-side: the Fleet ledger, the real Cash Collected (accounting receipts), and the Booked Vouchers that prove it was posted. A small fee/rounding tolerance keeps the noise out — you’re only alerted when a gap is significant."
        />

        {/* Lookup */}
        <Card>
          <form onSubmit={onSubmit} className="flex flex-wrap items-end gap-3 px-5 py-4">
            <div className="flex-1 min-w-[200px]">
              <label className="mb-1 block text-xs font-medium text-slate-500">Contract number</label>
              <input
                value={input}
                onChange={(e) => setInput(e.target.value)}
                placeholder="e.g. 86817"
                inputMode="numeric"
                className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10"
              />
            </div>
            <button
              type="submit"
              disabled={loading || !input.trim()}
              className="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:opacity-50"
            >
              {loading ? 'Reconciling…' : 'Reconcile'}
            </button>
          </form>
        </Card>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {loading && <div className="flex justify-center py-16"><Spinner className="h-8 w-8" /></div>}

        {!loading && !data && !error && (
          <Card><EmptyState title="Enter a contract number" message="Look up any contract to see its fleet income, the cash actually collected in the accounting system, and the vouchers booked against it." /></Card>
        )}

        {data && r && (
          <>
            {/* Verdict banner */}
            <Card className={`ring-1 ${sev.ring}`}>
              <div className={`flex flex-col gap-2 px-6 py-4 ${sev.head}`}>
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <h3 className="flex items-center gap-2 text-base font-semibold">
                    <span className={`h-2.5 w-2.5 rounded-full ${sev.dot}`} />
                    {sev.title}
                    <span className="text-sm font-normal opacity-70">
                      · Contract {data.contract.contract_no}
                      {data.contract.vehicle ? ` · ${data.contract.vehicle}` : ''}
                      {data.contract.customer ? ` · ${data.contract.customer}` : ''}
                    </span>
                  </h3>
                  {data.contract.id && (
                    <Link to={`/contracts/${data.contract.id}`} className="text-xs font-medium text-indigo-700 hover:underline">Open contract →</Link>
                  )}
                </div>
                <p className="text-sm opacity-90">{sev.blurb}</p>
                {r.flags?.map((f, i) => (
                  <p key={i} className="text-sm font-medium">• {f.message}</p>
                ))}
              </div>
            </Card>

            {/* Three-way comparison */}
            <div className="grid gap-5 lg:grid-cols-3">
              <ColumnCard title="Fleet ledger" subtitle="What FleetView reports (synced from OfficeManager)">
                <Line label="Income" hint="ex-VAT, reference" value={aed2(data.fleet.income)} tone="text-slate-500" />
                <Line label="Billed" hint="incl. VAT" value={aed2(data.fleet.billed)} />
                <Line label="Recorded collected" value={aed2(data.fleet.recorded)} strong />
                <Line
                  label="Outstanding"
                  value={aed2(data.fleet.outstanding)}
                  tone={Math.abs(data.fleet.outstanding) > 1 ? 'text-amber-600' : 'text-slate-900'}
                />
              </ColumnCard>

              <ColumnCard title="Cash collected" subtitle="Accounting cash-receipts journal" error={data.cash.error}>
                <Line label="Received" value={aed2(data.cash.received)} tone="text-emerald-600" />
                <Line label="Refunded / sent" value={data.cash.refunded ? `– ${aed2(data.cash.refunded)}` : aed2(0)} tone="text-slate-500" />
                <Line label="Net collected" value={aed2(data.cash.net)} strong />
                <div className="mt-2 border-t border-slate-100 pt-2">
                  {data.cash.rows?.length
                    ? data.cash.rows.map((row, i) => (
                      <div key={i} className="flex items-center justify-between gap-2 py-1 text-xs text-slate-500">
                        <span>{row.date} · {row.journal}</span>
                        <span className={`tabular-nums ${String(row.type).toLowerCase().startsWith('rec') ? 'text-emerald-600' : 'text-slate-400'}`}>
                          {String(row.type).toLowerCase().startsWith('rec') ? '' : '– '}{aed2(row.amount)}
                        </span>
                      </div>
                    ))
                    : <p className="py-1 text-xs text-slate-400">No receipts found for this contract.</p>}
                </div>
              </ColumnCard>

              <ColumnCard title="Booked vouchers" subtitle="Posting proof — headers only, no amount" error={data.vouchers.error}>
                <Line label="Total vouchers" value={data.vouchers.total} strong tone={data.vouchers.total ? 'text-slate-900' : 'text-red-600'} />
                <Line label="Journal postings" value={data.vouchers.journal} />
                <Line label="Receipt vouchers" value={data.vouchers.receipts} />
                <div className="mt-2 border-t border-slate-100 pt-2">
                  {data.vouchers.rows?.length
                    ? data.vouchers.rows.slice(0, 12).map((v, i) => (
                      <div key={i} className="flex items-center justify-between gap-2 py-1 text-xs text-slate-500">
                        <span>#{v.voucher_no} · {fmtDate(v.date)}</span>
                        <Badge tone={v.kind === 'receipt' ? 'green' : 'blue'}>{v.kind}</Badge>
                      </div>
                    ))
                    : <p className="py-1 text-xs text-slate-400">No vouchers booked against this contract.</p>}
                </div>
              </ColumnCard>
            </div>

            {/* Gap summary */}
            <Card>
              <div className="flex flex-wrap items-center justify-between gap-4 px-6 py-4">
                <div>
                  <p className="text-xs font-medium text-slate-500">Cash gap (net collected − billed)</p>
                  <p className={`text-2xl font-bold tracking-tight ${r.within_tolerance ? 'text-emerald-600' : 'text-red-600'}`}>
                    {r.cash_gap > 0 ? '+' : ''}{aed2(r.cash_gap)}
                  </p>
                </div>
                <div className="text-right text-xs text-slate-500">
                  <p>Tolerance: ± {aed2(r.tolerance)} ({r.tolerance_pct}% of the larger side)</p>
                  <p className={`mt-1 font-medium ${r.within_tolerance ? 'text-emerald-600' : 'text-red-600'}`}>
                    {r.within_tolerance ? 'Within tolerance — treated as fees/rounding' : 'Beyond tolerance — flagged as an exception'}
                  </p>
                </div>
              </div>
            </Card>
          </>
        )}
      </div>
    </div>
  );
}
