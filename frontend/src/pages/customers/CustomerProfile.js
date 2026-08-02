import { useCallback, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import Badge, { ContractTypeBadge, ContractStateBadge } from '../../components/ui/Badge';
import { EmptyState, Spinner } from '../../components/ui/Misc';
import { CommandPanel } from '../../components/ops';
import Button from '../../components/ui/Button';
import CustomerReconciliation from '../../components/CustomerReconciliation';
import { useCountUp } from '../../components/ui/Gauge';
import { aed2, fmtDate, num } from '../../lib/format';
import { SHOW_FINANCIALS } from '../../config/features';

function CountUp({ value, format }) {
  const v = useCountUp(Number(value) || 0);
  return <>{format ? format(v) : Math.round(v).toLocaleString()}</>;
}

const STAT_TONE = {
  gray: { bg: 'bg-slate-100 text-slate-500', text: 'text-slate-900' },
  indigo: { bg: 'bg-indigo-50 text-indigo-600', text: 'text-slate-900' },
  emerald: { bg: 'bg-emerald-50 text-emerald-600', text: 'text-emerald-600' },
  red: { bg: 'bg-red-50 text-red-600', text: 'text-red-600' },
  amber: { bg: 'bg-amber-50 text-amber-600', text: 'text-slate-900' },
};

function Stat({ label, value, icon, tone = 'gray', format, highlight }) {
  const t = STAT_TONE[tone] || STAT_TONE.gray;
  return (
    <div className="opx-kpi">
      <div className="flex items-center justify-between gap-2">
        <p className="lbl" style={{ marginBottom: 0 }}>{label}</p>
        {icon && (
          <span className={`flex h-8 w-8 items-center justify-center rounded-lg ${t.bg}`}>
            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d={icon} /></svg>
          </span>
        )}
      </div>
      <p className="v tnum" style={{ fontSize: 26, marginTop: 8 }}>
        <CountUp value={value} format={format} />
      </p>
    </div>
  );
}

function Field({ label, value }) {
  return (
    <div className="flex justify-between gap-4 py-1.5 text-sm" style={{ borderBottom: '1px solid var(--line)' }}>
      <span style={{ color: 'var(--ink-3)' }}>{label}</span>
      <span className="text-end font-medium" style={{ color: 'var(--ink)' }}>{value || '—'}</span>
    </div>
  );
}

const balTone = (b) => (Number(b) > 0 ? 'red' : Number(b) < 0 ? 'green' : 'gray');

export default function CustomerProfile() {
  const { id } = useParams();
  const fetcher = useCallback(async () => {
    const { data } = await api.get(`/Customer/${id}/profile`);
    return data.data;
  }, [id]);
  const { data, loading, error } = useFetch(fetcher, [id]);
  const [showAllContracts, setShowAllContracts] = useState(false);

  if (loading) return <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>;
  if (error || !data) {
    return (
      <div className="mx-auto max-w-3xl px-4 py-12">
        <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error || 'Not found'}</div>
        <Link to="/customers" className="mt-4 inline-block text-sm font-medium text-indigo-600">← Back to customers</Link>
      </div>
    );
  }

  const c = data.customer;
  const contracts = data.contracts || [];
  // Rental history is collapsed to the most recent few; "Show more" reveals the rest.
  const CONTRACTS_PREVIEW = 6;
  const shownContracts = showAllContracts ? contracts : contracts.slice(0, CONTRACTS_PREVIEW);
  const hiddenContracts = contracts.length - shownContracts.length;
  const stats = data.stats || {};
  const categoryLedger = data.category_ledger || [];
  const ledgerTotals = data.ledger_totals;
  const balance = Number(c.balance || 0);
  const wallet = Number(c.available_wallet ?? Math.max(0, -balance));
  const initials = (c.name_en || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?';

  return (
    <div className="opx py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        {/* Back */}
        <Link to="/customers" className="inline-flex items-center gap-1 text-sm font-medium text-slate-500 transition hover:text-slate-700">
          <svg aria-hidden="true" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M15 19l-7-7 7-7" /></svg>
          Customers
        </Link>

        {/* Hero */}
        <div className="relative overflow-hidden rounded-2xl bg-navy-950 p-6 shadow-card sm:p-8">
          <div className="relative flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
            <div className="flex items-start gap-4">
              <div className="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-indigo-600 text-xl font-bold text-white ring-1 ring-inset ring-white/20">
                {initials}
              </div>
              <div className="min-w-0">
                <h1 className="text-2xl font-bold tracking-tight text-white sm:text-3xl">{c.name_en || 'Customer'}</h1>
                <p className="mt-1 text-xs font-medium text-white/50">#{c.customer_no || c.id}{c.nationality ? ` · ${c.nationality}` : ''}</p>
                <div className="mt-3 flex flex-wrap gap-2">
                  {c.mobile1 && (
                    <a href={`tel:${c.mobile1}`} className="inline-flex items-center gap-1.5 rounded-lg bg-white/10 px-2.5 py-1 text-xs font-medium text-white ring-1 ring-inset ring-white/15 backdrop-blur transition hover:bg-white/15">
                      <svg className="h-3.5 w-3.5 text-indigo-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8"><path strokeLinecap="round" strokeLinejoin="round" d="M3 5a2 2 0 0 1 2-2h2.3a1 1 0 0 1 1 .8l1 4a1 1 0 0 1-.3 1L8 11a14 14 0 0 0 5 5l1.2-1.3a1 1 0 0 1 1-.3l4 1a1 1 0 0 1 .8 1V19a2 2 0 0 1-2 2A16 16 0 0 1 3 5z" /></svg>
                      {c.mobile1}
                    </a>
                  )}
                  {c.email && (
                    <span className="inline-flex items-center gap-1.5 rounded-lg bg-white/10 px-2.5 py-1 text-xs font-medium text-white ring-1 ring-inset ring-white/15 backdrop-blur">
                      <svg className="h-3.5 w-3.5 text-indigo-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8"><path strokeLinecap="round" strokeLinejoin="round" d="M4 6h16v12H4zM4 7l8 6 8-6" /></svg>
                      {c.email}
                    </span>
                  )}
                </div>
              </div>
            </div>

            {/* Balance highlight */}
            {SHOW_FINANCIALS && (
              <div className="w-full shrink-0 rounded-2xl bg-white/5 p-4 ring-1 ring-inset ring-white/10 backdrop-blur lg:w-72">
                <p className="text-xs font-medium text-white/55">{balance > 0 ? 'Outstanding (owes)' : balance < 0 ? 'Credit (overpaid)' : 'Balance'}</p>
                <p className={`mt-1 text-3xl font-bold tracking-tight ${balance > 0 ? 'text-red-300' : balance < 0 ? 'text-emerald-300' : 'text-white'}`}>
                  {aed2(Math.abs(balance))}
                </p>
                <div className="mt-3 flex gap-4 border-t border-white/10 pt-3 text-xs">
                  <div>
                    <p className="text-white/45">Wallet</p>
                    <p className="font-semibold text-white">{aed2(wallet)}</p>
                  </div>
                  <div>
                    <p className="text-white/45">Deposit held</p>
                    <p className="font-semibold text-white">{aed2(c.deposit)}</p>
                  </div>
                </div>
              </div>
            )}
          </div>
        </div>

        {/* Stats */}
        <div className={`grid grid-cols-2 gap-4 ${SHOW_FINANCIALS ? 'sm:grid-cols-3 lg:grid-cols-5' : 'sm:grid-cols-2'}`}>
          {SHOW_FINANCIALS && (
            <>
              <Stat label={balance > 0 ? 'Outstanding' : balance < 0 ? 'Credit' : 'Balance'} value={Math.abs(balance)} format={aed2} tone={balance > 0 ? 'red' : 'emerald'} highlight={balance !== 0}
                icon="M12 8c-1.7 0-3 .9-3 2s1.3 2 3 2 3 .9 3 2-1.3 2-3 2m0-8V6m0 12v-2m9-4a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
              <Stat label="Available Wallet" value={wallet} format={aed2} tone="emerald" highlight={wallet > 0}
                icon="M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7zm13 5h5M16 12a1.5 1.5 0 0 0 0 3h5v-3h-5z" />
              <Stat label="Deposit Held" value={c.deposit} format={aed2} tone="indigo"
                icon="M3 10l9-6 9 6M5 10v9a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-9M9 20v-6h6v6" />
            </>
          )}
          <Stat label="Total Contracts" value={stats.contracts_count} tone="indigo"
            icon="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2z" />
          <Stat label="Open Now" value={stats.open_count} tone="emerald" highlight={stats.open_count > 0}
            icon="M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
        </div>

        {/* Contact + Documents */}
        <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
          <CommandPanel title="Contact" dotColor="#22d3ee">
            <Field label="Mobile" value={c.mobile1} />
            <Field label="Mobile 2" value={c.mobile2} />
            <Field label="WhatsApp" value={c.whatsapp} />
            <Field label="Email" value={c.email} />
            <Field label="City" value={c.city} />
            <Field label="Address" value={c.address} />
          </CommandPanel>
          <CommandPanel title="Documents" dotColor="#8b7bfb">
            <Field label="Passport" value={c.passport_no} />
            <Field label="Passport Expiry" value={fmtDate(c.passport_expiry)} />
            <Field label="License" value={c.license_no} />
            <Field label="License Expiry" value={fmtDate(c.license_expiry)} />
            <Field label="Emirates ID" value={c.id_no} />
            <Field label="ID Expiry" value={fmtDate(c.id_expiry)} />
          </CommandPanel>
        </div>

        {/* Per-category account reconciliation rolled up across all contracts */}
        {SHOW_FINANCIALS && <CustomerReconciliation ledger={categoryLedger} totals={ledgerTotals} />}

        {/* Rental history — a timeline of this customer's contracts */}
        <CommandPanel title="Rental History" dotColor="#34d399" label="ledger" meta={`${num(contracts.length)} total · newest first`} bodyFlush>
          {contracts.length === 0 ? (
            <EmptyState
              title="No contracts yet"
              message="Contracts appear here automatically once this customer rents a car."
            />
          ) : (
            <div className="relative px-6 py-6">
              <span aria-hidden className="pointer-events-none absolute bottom-8 start-10 top-8 w-px bg-slate-200" />
              <ol className="stagger space-y-4">
                {shownContracts.map((ct) => {
                  const open = ct.state === 'open';
                  return (
                    <li key={ct.id} className="relative flex gap-4">
                      <span className={`relative z-10 mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full ring-4 ring-white ${open ? 'bg-emerald-100' : 'bg-slate-100'}`}>
                        <span className={`h-2.5 w-2.5 rounded-full ${open ? 'bg-emerald-500' : 'bg-slate-400'}`} />
                      </span>
                      <div className="hover-lift min-w-0 flex-1 rounded-2xl border border-slate-200/60 bg-white p-4 shadow-soft">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                          <div className="flex flex-wrap items-center gap-2">
                            <Link to={`/contracts/${ct.id}`} className="text-sm font-semibold text-indigo-600 hover:text-indigo-700">#{ct.contract_no || ct.id}</Link>
                            <ContractTypeBadge type={ct.contract_type} />
                            <ContractStateBadge state={ct.state} />
                          </div>
                          {SHOW_FINANCIALS && <Badge tone={balTone(ct.balance)}>{aed2(ct.balance)}</Badge>}
                        </div>
                        <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
                          {ct.vehicle && (
                            <span className="inline-flex items-center gap-1.5">
                              <svg className="h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"><path d="M5 13l1.5-4.5A2 2 0 0 1 8.4 7h7.2a2 2 0 0 1 1.9 1.5L19 13m-14 0h14M7.5 16h.01M16.5 16h.01" /></svg>
                              {ct.vehicle_id ? <Link to={`/vehicles/${ct.vehicle_id}`} className="font-medium text-slate-600 hover:text-indigo-600">{ct.vehicle}</Link> : <span className="font-medium text-slate-600">{ct.vehicle}</span>}
                            </span>
                          )}
                          <span>{fmtDate(ct.out_date)} → {ct.in_date ? fmtDate(ct.in_date) : <span className="text-emerald-600">still out</span>}</span>
                          {SHOW_FINANCIALS && <span className="text-slate-400">Dr {aed2(ct.debit)} · Cr {aed2(ct.credit)}</span>}
                        </div>
                      </div>
                    </li>
                  );
                })}
              </ol>

              {contracts.length > CONTRACTS_PREVIEW && (
                <div className="mt-5 flex justify-center">
                  <Button
                    variant="secondary"
                    onClick={() => setShowAllContracts((v) => !v)}
                    className="rounded-full"
                    aria-expanded={showAllContracts}
                  >
                    {showAllContracts ? 'Show less' : `Show ${hiddenContracts} more`}
                    <svg aria-hidden="true" className={`h-4 w-4 transition-transform ${showAllContracts ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M19 9l-7 7-7-7" /></svg>
                  </Button>
                </div>
              )}
            </div>
          )}
        </CommandPanel>
      </div>
    </div>
  );
}
