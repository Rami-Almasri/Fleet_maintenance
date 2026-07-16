import { useState, useCallback, useEffect } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../api/client';
import Badge from '../components/ui/Badge';
import { Card, PageHeader, EmptyState } from '../components/ui/Misc';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import DataTable, { SectionCard } from '../components/ui/Table';
import { InfoTip } from '../components/ui/Tooltip';
import { MetricGridSkeleton, Skeleton } from '../components/ui/Skeleton';
import Icon from '../components/ui/Icon';
import { aed2, fmtDate } from '../lib/format';

// Verdict styling per reconciliation status.
const STATUS = {
  reconciled: { icon: <Icon.Check className="h-5 w-5" />, title: 'Reconciled', ring: 'ring-emerald-200', accent: 'from-emerald-500 to-emerald-400', bubble: 'bg-emerald-100 text-emerald-700', text: 'text-emerald-700', blurb: 'Net cash collected matches the amount billed, within tolerance, and the contract is posted to the books.' },
  review: { icon: <Icon.Alert className="h-5 w-5" />, title: 'Needs review', ring: 'ring-amber-200', accent: 'from-amber-500 to-amber-400', bubble: 'bg-amber-100 text-amber-700', text: 'text-amber-700', blurb: 'The cash gap is beyond the fee/rounding tolerance — worth a look.' },
  exception: { icon: <Icon.XCircle className="h-5 w-5" />, title: 'Exception', ring: 'ring-red-200', accent: 'from-red-500 to-red-400', bubble: 'bg-red-100 text-red-700', text: 'text-red-700', blurb: 'A reconciliation problem was found that needs fixing.' },
  error: { icon: <Icon.Info className="h-5 w-5" />, title: 'Could not reconcile', ring: 'ring-slate-200', accent: 'from-slate-400 to-slate-300', bubble: 'bg-slate-100 text-slate-700', text: 'text-slate-700', blurb: 'One or more accounting feeds could not be read (the OfficeManager server may be slow). Try again.' },
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
        <SectionCard>
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
              className="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:opacity-50"
            >
              <Icon.Scale className="h-4 w-4" />
              {loading ? 'Reconciling…' : 'Reconcile'}
            </button>
          </form>
        </SectionCard>

        {error && (
          <div className="flex items-start gap-2.5 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">
            <Icon.Alert className="mt-px h-4 w-4 shrink-0" />
            <span>{error}</span>
          </div>
        )}

        {loading && (
          <>
            <Card><div className="space-y-3 px-6 py-5"><Skeleton className="h-5 w-64" /><Skeleton className="h-3 w-full max-w-md" /></div></Card>
            <MetricGridSkeleton count={3} />
          </>
        )}

        {!loading && !data && !error && (
          <Card><EmptyState title="Enter a contract number" message="Look up any contract to see its fleet income, the cash actually collected in the accounting system, and the vouchers booked against it." /></Card>
        )}

        {data && r && !loading && (
          <>
            {/* Verdict banner */}
            <Card className={`ring-1 ${sev.ring}`}>
              <div className="relative px-6 py-5">
                <span className={`absolute inset-y-0 left-0 w-1.5 bg-gradient-to-b ${sev.accent}`} />
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="flex items-start gap-3">
                    <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${sev.bubble}`}>
                      {sev.icon}
                    </span>
                    <div className="min-w-0">
                      <h3 className={`flex flex-wrap items-center gap-2 text-base font-semibold ${sev.text}`}>
                        {sev.title}
                        <span className="text-sm font-normal text-slate-500">
                          · Contract {data.contract.contract_no}
                          {data.contract.vehicle ? ` · ${data.contract.vehicle}` : ''}
                          {data.contract.customer ? ` · ${data.contract.customer}` : ''}
                        </span>
                      </h3>
                      <p className="mt-1 text-sm text-slate-600">{sev.blurb}</p>
                      {r.flags?.map((f, i) => (
                        <p key={i} className={`mt-1 text-sm font-medium ${sev.text}`}>• {f.message}</p>
                      ))}
                    </div>
                  </div>
                  {data.contract.id && (
                    <Link to={`/contracts/${data.contract.id}`} className="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-700">
                      Open contract <Icon.ArrowRight className="h-3.5 w-3.5" />
                    </Link>
                  )}
                </div>
              </div>
            </Card>

            {/* Headline KPIs */}
            <MetricGrid cols={3}>
              <MetricCard
                label="Recorded collected"
                value={aed2(data.fleet.recorded)}
                tone="slate"
                icon={<Icon.Invoice className="h-5 w-5" />}
                hint={`Billed ${aed2(data.fleet.billed)}`}
                tooltip="What the Fleet ledger (synced from OfficeManager) records as collected against this contract."
              />
              <MetricCard
                label="Net cash collected"
                value={aed2(data.cash.net)}
                tone={data.cash.error ? 'red' : 'emerald'}
                icon={<Icon.Cash className="h-5 w-5" />}
                hint={data.cash.error ? 'Cash feed unavailable' : `Received ${aed2(data.cash.received)}${data.cash.refunded ? ` · refunded ${aed2(data.cash.refunded)}` : ''}`}
                tooltip="Real cash from the accounting cash-receipts journal: receipts received minus any refunds sent."
              />
              <MetricCard
                label="Booked vouchers"
                value={data.vouchers.error ? '—' : data.vouchers.total}
                tone={data.vouchers.error ? 'red' : data.vouchers.total ? 'indigo' : 'amber'}
                icon={<Icon.Check className="h-5 w-5" />}
                hint={data.vouchers.error ? 'Voucher feed unavailable' : `${data.vouchers.journal} journal · ${data.vouchers.receipts} receipt`}
                tooltip="Posting proof — the number of accounting vouchers booked against this contract (headers only, no amounts)."
              />
            </MetricGrid>

            {/* Three-way comparison */}
            <div className="grid gap-5 lg:grid-cols-3">
              <SectionCard title="Fleet ledger" subtitle="What Faster reports (synced from OfficeManager)">
                <div className="px-5 py-3">
                  <Line label="Income" hint="ex-VAT, reference" value={aed2(data.fleet.income)} tone="text-slate-500" />
                  <Line label="Billed" hint="incl. VAT" value={aed2(data.fleet.billed)} />
                  <Line label="Recorded collected" value={aed2(data.fleet.recorded)} strong />
                  <Line
                    label="Outstanding"
                    value={aed2(data.fleet.outstanding)}
                    tone={Math.abs(data.fleet.outstanding) > 1 ? 'text-amber-600' : 'text-slate-900'}
                  />
                </div>
              </SectionCard>

              <SectionCard
                title="Cash collected"
                subtitle="Accounting cash-receipts journal"
                actions={<InfoTip content="The cash-receipts journal in the accounting system — the real money received, not the synced fleet figure." />}
              >
                {data.cash.error ? (
                  <p className="px-5 py-4 text-sm text-red-600">Couldn’t load: {data.cash.error}</p>
                ) : (
                  <>
                    <div className="px-5 py-3">
                      <Line label="Received" value={aed2(data.cash.received)} tone="text-emerald-600" />
                      <Line label="Refunded / sent" value={data.cash.refunded ? `– ${aed2(data.cash.refunded)}` : aed2(0)} tone="text-slate-500" />
                      <Line label="Net collected" value={aed2(data.cash.net)} strong />
                    </div>
                    <DataTable
                      className="border-t border-slate-100"
                      rows={data.cash.rows || []}
                      dense
                      empty="No receipts found for this contract."
                      columns={[
                        { key: 'date', header: 'Date', cellClass: 'text-slate-500', render: (row) => row.date },
                        { key: 'journal', header: 'Journal', cellClass: 'text-slate-500', render: (row) => row.journal },
                        {
                          key: 'amount', header: 'Amount', align: 'right',
                          render: (row) => {
                            const isReceipt = String(row.type).toLowerCase().startsWith('rec');
                            return (
                              <span className={`tabular-nums ${isReceipt ? 'text-emerald-600' : 'text-slate-400'}`}>
                                {isReceipt ? '' : '– '}{aed2(row.amount)}
                              </span>
                            );
                          },
                        },
                      ]}
                    />
                  </>
                )}
              </SectionCard>

              <SectionCard
                title="Booked vouchers"
                subtitle="Posting proof — headers only, no amount"
                actions={<InfoTip content="A voucher is the accounting system's posting record. Their presence proves the contract was booked to the books." />}
              >
                {data.vouchers.error ? (
                  <p className="px-5 py-4 text-sm text-red-600">Couldn’t load: {data.vouchers.error}</p>
                ) : (
                  <>
                    <div className="px-5 py-3">
                      <Line label="Total vouchers" value={data.vouchers.total} strong tone={data.vouchers.total ? 'text-slate-900' : 'text-red-600'} />
                      <Line label="Journal postings" value={data.vouchers.journal} />
                      <Line label="Receipt vouchers" value={data.vouchers.receipts} />
                    </div>
                    <DataTable
                      className="border-t border-slate-100"
                      rows={(data.vouchers.rows || []).slice(0, 12)}
                      dense
                      empty="No vouchers booked against this contract."
                      columns={[
                        { key: 'voucher_no', header: 'Voucher', cellClass: 'text-slate-600', render: (v) => `#${v.voucher_no}` },
                        { key: 'date', header: 'Date', cellClass: 'text-slate-500', render: (v) => fmtDate(v.date) },
                        { key: 'kind', header: 'Kind', align: 'right', render: (v) => <Badge tone={v.kind === 'receipt' ? 'green' : 'blue'}>{v.kind}</Badge> },
                      ]}
                    />
                  </>
                )}
              </SectionCard>
            </div>

            {/* Gap summary */}
            <Card className={`ring-1 ${r.within_tolerance ? 'ring-emerald-200' : 'ring-red-200'}`}>
              <div className="flex flex-wrap items-center justify-between gap-4 px-6 py-5">
                <div>
                  <p className="flex items-center gap-1.5 text-xs font-medium text-slate-500">
                    Cash gap (net collected − billed)
                    <InfoTip content="The variance between real cash collected and the amount billed. Small gaps are treated as fees/rounding; large ones are flagged." />
                  </p>
                  <p className={`mt-1 text-2xl font-bold tracking-tight tabular-nums ${r.within_tolerance ? 'text-emerald-600' : 'text-red-600'}`}>
                    {r.cash_gap > 0 ? '+' : ''}{aed2(r.cash_gap)}
                  </p>
                </div>
                <div className="text-right text-xs text-slate-500">
                  <p className="inline-flex items-center gap-1">
                    Tolerance: ± {aed2(r.tolerance)} ({r.tolerance_pct}% of the larger side)
                    <InfoTip content="A small fee/rounding allowance. Gaps inside this band are not flagged as exceptions." />
                  </p>
                  <p className={`mt-1 inline-flex items-center gap-1 font-medium ${r.within_tolerance ? 'text-emerald-600' : 'text-red-600'}`}>
                    {r.within_tolerance
                      ? <><Icon.Check className="h-3.5 w-3.5" /> Within tolerance — treated as fees/rounding</>
                      : <><Icon.Alert className="h-3.5 w-3.5" /> Beyond tolerance — flagged as an exception</>}
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
