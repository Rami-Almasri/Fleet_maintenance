import { useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { PageHeader, Spinner, EmptyState } from '../components/ui/Misc';
import { usePageStat } from '../components/PageStat';
import { aed2, fmtDate, num } from '../lib/format';

// How alarming is "late by N days"? Drives the whole card's color language.
const severity = (d) =>
  d >= 14
    ? {
        key: 'critical',
        label: 'Critical',
        ring: 'ring-red-200',
        hoverRing: 'hover:ring-red-200',
        accent: 'from-red-500 to-rose-600',
        soft: 'bg-red-50',
        text: 'text-red-700',
        dot: 'bg-red-500',
        glow: 'shadow-[0_1px_2px_rgba(0,0,0,0.04),0_8px_24px_-8px_rgba(220,38,38,0.25)]',
      }
    : d >= 4
    ? {
        key: 'high',
        label: 'Escalating',
        ring: 'ring-amber-200',
        hoverRing: 'hover:ring-amber-200',
        accent: 'from-amber-400 to-orange-500',
        soft: 'bg-amber-50',
        text: 'text-amber-700',
        dot: 'bg-amber-500',
        glow: 'shadow-[0_1px_2px_rgba(0,0,0,0.04),0_8px_24px_-8px_rgba(245,158,11,0.22)]',
      }
    : {
        key: 'recent',
        label: 'Recently due',
        ring: 'ring-slate-200',
        hoverRing: 'hover:ring-slate-200',
        accent: 'from-slate-300 to-slate-400',
        soft: 'bg-slate-50',
        text: 'text-slate-600',
        dot: 'bg-slate-400',
        glow: 'shadow-soft',
      };

function StatCard({ label, value, tone = 'text-slate-900' }) {
  return (
    <div className="rounded-2xl border border-slate-200/60 bg-white px-5 py-4 shadow-soft">
      <p className="text-xs font-medium text-slate-500">{label}</p>
      <p className={`mt-1 text-2xl font-bold tracking-tight ${tone}`}>{value}</p>
    </div>
  );
}

function OverdueCard({ r }) {
  const s = severity(r.days_overdue || 0);
  return (
    <div
      className={`group relative flex flex-col overflow-hidden rounded-2xl border border-slate-200/60 bg-white ring-1 ring-transparent transition duration-200 hover:-translate-y-0.5 ${s.hoverRing} ${s.glow}`}
    >
      {/* Severity accent rail */}
      <span className={`absolute inset-y-0 left-0 w-1.5 bg-gradient-to-b ${s.accent}`} />

      <div className="flex flex-1 flex-col gap-4 p-5 pl-6">
        {/* Header: car + severity pill */}
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            {r.vehicle_id ? (
              <Link
                to={`/vehicles/${r.vehicle_id}`}
                className="text-base font-bold tracking-tight text-slate-900 transition hover:text-indigo-600"
              >
                {r.plate || `#${r.vehicle_id}`}
              </Link>
            ) : (
              <span className="text-base font-bold tracking-tight text-slate-900">{r.plate || '—'}</span>
            )}
            <p className="truncate text-xs text-slate-400">{r.car || 'Unknown model'}</p>
          </div>

          <div
            className={`flex shrink-0 flex-col items-center rounded-xl ${s.soft} px-3 py-1.5 ring-1 ring-inset ${s.ring}`}
          >
            <span className={`text-lg font-bold leading-none ${s.text}`}>{r.days_overdue}</span>
            <span className={`mt-0.5 text-[10px] font-semibold uppercase tracking-wide ${s.text}`}>days late</span>
          </div>
        </div>

        {/* Customer */}
        <div className="flex items-center gap-2 text-sm">
          <svg className="h-4 w-4 shrink-0 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7">
            <path strokeLinecap="round" strokeLinejoin="round" d="M16 14a4 4 0 1 0-8 0M12 7a3 3 0 1 0 0 6 3 3 0 0 0 0-6zM5 20a7 7 0 0 1 14 0" />
          </svg>
          {r.customer_id ? (
            <Link to={`/customers/${r.customer_id}`} className="truncate font-medium text-slate-700 hover:text-indigo-600">
              {r.customer || '—'}
            </Link>
          ) : (
            <span className="truncate font-medium text-slate-700">{r.customer || '—'}</span>
          )}
        </div>

        {/* Timeline: out -> est. return */}
        <div className="flex items-center justify-between rounded-xl bg-slate-50/80 px-3.5 py-2.5 ring-1 ring-inset ring-slate-100">
          <div className="text-center">
            <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Out</p>
            <p className="mt-0.5 text-xs font-semibold text-slate-700">{fmtDate(r.out_date)}</p>
          </div>
          <div className="flex flex-1 flex-col items-center px-2">
            <span className="text-[10px] font-medium text-slate-400">{r.days}d rental</span>
            <div className="mt-1 flex w-full items-center">
              <span className="h-1.5 w-1.5 rounded-full bg-slate-300" />
              <span className="h-px flex-1 bg-slate-200" />
              <span className={`h-1.5 w-1.5 rounded-full ${s.dot}`} />
            </div>
          </div>
          <div className="text-center">
            <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Est. return</p>
            <p className={`mt-0.5 text-xs font-semibold ${s.text}`}>{fmtDate(r.due)}</p>
          </div>
        </div>

        {/* Footer: contract + balance */}
        <div className="mt-auto flex items-end justify-between border-t border-slate-100 pt-3.5">
          <Link
            to={`/contracts/${r.id}`}
            className="inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 transition hover:text-indigo-700"
          >
            Contract {r.contract_no ? `#${r.contract_no}` : `#${r.id}`}
            <svg className="h-3.5 w-3.5 transition group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
              <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
            </svg>
          </Link>
          <div className="text-right">
            <p className="text-[10px] font-medium uppercase tracking-wide text-slate-400">Balance</p>
            <p className={`text-sm font-bold tracking-tight ${Number(r.balance) > 0 ? 'text-slate-900' : 'text-slate-400'}`}>
              {r.balance ? aed2(r.balance) : '—'}
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}

export default function OverdueRentals() {
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/Dashboard/overdue-rentals');
    return data.data || [];
  }, []);
  const { data, loading, error } = useFetch(fetcher);

  // Floating page gauge: of all overdue rentals, the share that are badly late
  // (14+ days). Hidden while loading or when nothing is overdue.
  const allRows = data || [];
  const severe = allRows.filter((r) => (r.days_overdue || 0) >= 14).length;
  usePageStat({
    percent: loading || allRows.length === 0 ? null : (severe / allRows.length) * 100,
    label: '14+ days late',
    color: 'red',
    hint: `${severe} of ${allRows.length} overdue rentals are 14+ days late`,
  });

  if (loading) return <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>;

  // Most-overdue first so the worst offenders lead the grid.
  const rows = [...(data || [])].sort((a, b) => (b.days_overdue || 0) - (a.days_overdue || 0));
  const totalBalance = rows.reduce((sum, r) => sum + (Number(r.balance) || 0), 0);
  const worst = rows.reduce((m, r) => Math.max(m, r.days_overdue || 0), 0);

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title="Overdue Rentals" subtitle="Open rentals whose estimated return date (handover date + rental days) has already passed.">
          <Link to="/" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">← Dashboard</Link>
        </PageHeader>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
          <StatCard label="Overdue rentals" value={num(rows.length)} tone="text-red-600" />
          <StatCard label="Most overdue" value={worst ? `${worst}d` : '—'} />
          <StatCard label="Outstanding balance" value={aed2(totalBalance)} />
        </div>

        {rows.length === 0 ? (
          <div className="rounded-2xl border border-slate-200/60 bg-white shadow-soft">
            <EmptyState title="No overdue rentals 🎉" message="Every rented car is still within its expected return window." />
          </div>
        ) : (
          <div className="grid grid-cols-1 gap-5 stagger-rows sm:grid-cols-2 xl:grid-cols-3">
            {rows.map((r) => (
              <OverdueCard key={r.id} r={r} />
            ))}
          </div>
        )}

        <p className="text-xs text-gray-400">
          Note: a rental only appears here once its planned <span className="font-medium">rental days</span> are known (filled in by the
          OfficeManager <span className="font-medium">Contracts</span> sync). Rentals not yet synced won't show until that data arrives.
        </p>
      </div>
    </div>
  );
}
