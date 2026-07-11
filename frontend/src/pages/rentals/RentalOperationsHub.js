import { useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { PageHeader } from '../../components/ui/Misc';
import { SectionCard } from '../../components/ui/Table';
import { MetricGridSkeleton } from '../../components/ui/Skeleton';
import Badge, { ContractTypeBadge } from '../../components/ui/Badge';
import Icon from '../../components/ui/Icon';
import { fmtDate, num } from '../../lib/format';

// Expected return = out date + contracted days (OfficeManager has no due-date field). Mirrors the
// same derivation the contract detail page uses.
function dueDate(out, days) {
  if (!out || !(Number(days) > 0)) return null;
  const d = new Date(out);
  d.setDate(d.getDate() + Number(days));
  return d.toISOString().slice(0, 10);
}

// Collapse the compact readiness verdict into one chip: red when a car can't be handed over, amber
// when it's deliverable but carrying advisories, green when it's clean. Null while readiness is absent.
function readinessChip(r) {
  if (!r) return { tone: 'slate', label: 'No vehicle' };
  if (r.blocked) return { tone: 'red', label: `Blocked · ${r.blocker_count}` };
  if (r.warn_count > 0) return { tone: 'amber', label: `Ready · ${r.warn_count} advisory` };
  return { tone: 'green', label: 'Ready' };
}

function KpiTile({ label, value, tone = 'slate', Glyph }) {
  const soft = {
    slate: 'bg-slate-100 text-slate-600',
    emerald: 'bg-emerald-100 text-emerald-600',
    red: 'bg-red-100 text-red-600',
    indigo: 'bg-indigo-100 text-indigo-600',
  }[tone];
  return (
    <div className="flex items-center gap-3 rounded-2xl border border-slate-200/70 bg-white px-5 py-4 shadow-soft">
      <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${soft}`}>
        <Glyph className="h-5 w-5" />
      </span>
      <div>
        <p className="text-2xl font-bold leading-none text-slate-900">{num(value)}</p>
        <p className="mt-1 text-xs font-medium text-slate-500">{label}</p>
      </div>
    </div>
  );
}

function RentalRow({ r }) {
  const chip = readinessChip(r.readiness);
  const due = dueDate(r.out_date, r.days);
  const overdue = r.contract_type === 'C' && !r.in_date && due && new Date(due) < new Date();
  const vehLabel = r.vehicle?.plate_no || [r.vehicle?.make, r.vehicle?.model].filter(Boolean).join(' ') || '—';
  return (
    <tr className="hover:bg-slate-50/60">
      <td className="px-4 py-3">
        <Link to={`/contracts/${r.id}`} className="font-semibold text-indigo-600 hover:text-indigo-700">
          #{r.contract_no || r.id}
        </Link>
        <div className="mt-1"><ContractTypeBadge type={r.contract_type} /></div>
      </td>
      <td className="px-4 py-3">
        {r.vehicle ? (
          <Link to={`/vehicles/${r.vehicle.id}`} className="text-sm font-medium text-slate-800 hover:text-indigo-600">{vehLabel}</Link>
        ) : <span className="text-sm text-slate-400">—</span>}
        {r.readiness?.condition_grade && r.readiness.condition_grade !== 'green' && (
          <span className="ml-2 text-xs capitalize text-slate-400">{r.readiness.condition_grade}</span>
        )}
      </td>
      <td className="px-4 py-3">
        {r.customer ? (
          <Link to={`/customers/${r.customer.id}`} className="text-sm text-slate-700 hover:text-indigo-600">
            {r.customer.name_en || `#${r.customer.customer_no}`}
          </Link>
        ) : <span className="text-sm text-slate-400">—</span>}
      </td>
      <td className="px-4 py-3 text-sm text-slate-600">{fmtDate(r.out_date) || '—'}</td>
      <td className="px-4 py-3 text-sm">
        {due ? <span className={overdue ? 'font-semibold text-red-600' : 'text-slate-600'}>{fmtDate(due)}{overdue ? ' · overdue' : ''}</span> : <span className="text-slate-400">—</span>}
      </td>
      <td className="px-4 py-3 text-right">
        <Badge tone={chip.tone}>{chip.label}</Badge>
        {r.readiness && <span className="ml-2 text-xs text-slate-400">{r.readiness.pass_count}/{r.readiness.total}</span>}
      </td>
    </tr>
  );
}

/**
 * Rental Operations Hub — the Rental Manager's check-in / check-out board. Every car that's out on a
 * rental or reserved on a booking, each carrying the live 9-point readiness verdict for its vehicle so
 * the manager can see at a glance which cars are fit to hand over. Open a row for the full checklist.
 */
export default function RentalOperationsHub() {
  const [q, setQ] = useState('');
  const [onlyBlocked, setOnlyBlocked] = useState(false);

  const fetcher = useCallback(async () => {
    const { data } = await api.get('/RentalOperations');
    return data.data;
  }, []);
  const { data, loading, error } = useFetch(fetcher, [], { refreshInterval: 60000 });

  const rows = useMemo(() => data?.items || [], [data]);
  const readyCount = useMemo(() => rows.filter((r) => r.readiness && !r.readiness.blocked).length, [rows]);

  const shown = useMemo(() => {
    const needle = q.trim().toLowerCase();
    return rows.filter((r) => {
      if (onlyBlocked && !(r.readiness?.blocked)) return false;
      if (!needle) return true;
      return [r.contract_no, r.vehicle?.plate_no, r.vehicle?.make, r.vehicle?.model, r.customer?.name_en]
        .filter(Boolean).some((s) => String(s).toLowerCase().includes(needle));
    });
  }, [rows, q, onlyBlocked]);

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Rental Operations"
          subtitle="Check cars in and out. Every active rental and upcoming booking with its car's live 9-point readiness — open a contract for the full condition checklist before you hand the keys over."
        />

        {error && <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>}

        {loading && !rows.length ? (
          <MetricGridSkeleton count={3} />
        ) : (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <KpiTile label="Active & upcoming" value={data?.total || 0} tone="indigo" Glyph={Icon.Car} />
            <KpiTile label="Ready to hand over" value={readyCount} tone="emerald" Glyph={Icon.Check} />
            <KpiTile label="Blocked" value={data?.blocked || 0} tone="red" Glyph={Icon.Alert} />
          </div>
        )}

        <SectionCard
          title="Rentals & bookings"
          subtitle="Active rentals (car out) and active/upcoming bookings"
          actions={<span className="text-xs text-slate-400">{num(shown.length)}</span>}
        >
          <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-3">
              <div className="relative flex-1 min-w-[12rem]">
                <Icon.Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input
                  value={q}
                  onChange={(e) => setQ(e.target.value)}
                  placeholder="Search plate, customer, contract…"
                  className="w-full rounded-full border border-slate-200 bg-white py-2 pl-9 pr-3 text-sm text-slate-700 placeholder:text-slate-400 focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100"
                />
              </div>
              <button
                type="button"
                onClick={() => setOnlyBlocked((v) => !v)}
                className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition ${onlyBlocked ? 'bg-red-600 text-white' : 'bg-slate-100 text-slate-500 hover:text-slate-700'}`}
              >
                <Icon.Alert className="h-3.5 w-3.5" /> Blocked only
              </button>
            </div>

            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-slate-100 text-sm">
                <thead>
                  <tr className="text-left text-xs font-semibold uppercase tracking-wide text-slate-400">
                    <th className="px-4 py-2">Contract</th>
                    <th className="px-4 py-2">Vehicle</th>
                    <th className="px-4 py-2">Customer</th>
                    <th className="px-4 py-2">Out</th>
                    <th className="px-4 py-2">Due back</th>
                    <th className="px-4 py-2 text-right">Readiness</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-50">
                  {shown.length ? shown.map((r) => <RentalRow key={r.id} r={r} />) : (
                    <tr><td colSpan={6} className="px-4 py-12 text-center text-sm text-slate-400">{rows.length ? 'No rentals match.' : 'No active rentals or upcoming bookings.'}</td></tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </SectionCard>
      </div>
    </div>
  );
}
