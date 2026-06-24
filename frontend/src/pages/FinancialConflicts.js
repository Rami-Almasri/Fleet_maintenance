import { useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge from '../components/ui/Badge';
import { PageHeader, EmptyState } from '../components/ui/Misc';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import DataTable, { SectionCard } from '../components/ui/Table';
import { MetricGridSkeleton } from '../components/ui/Skeleton';
import Icon from '../components/ui/Icon';
import { Tooltip } from '../components/ui/Tooltip';
import { usePageStat } from '../components/PageStat';
import { num } from '../lib/format';

// Per-severity presentation: tone (Badge/MetricCard), a one-word label for the
// section header, and whether such a row is "the worst" (used to highlight rows).
const SEV = {
  critical: { tone: 'red', label: 'Money at risk' },
  warning: { tone: 'amber', label: 'Books off' },
  info: { tone: 'blue', label: 'Minor' },
};

// The "open" link for a conflict row: contract → car.
function RowLink({ it }) {
  if (it.contract_id) return <Link to={`/contracts/${it.contract_id}`} className="text-xs font-medium text-indigo-600 hover:text-indigo-700">Contract →</Link>;
  if (it.vehicle_id) return <Link to={`/vehicles/${it.vehicle_id}`} className="text-xs font-medium text-indigo-600 hover:text-indigo-700">Car →</Link>;
  return <span className="text-slate-300">—</span>;
}

// Invoice identity cell: invoice number + contract / plate / car context line.
function Record({ it }) {
  return (
    <div>
      <span className="font-medium text-slate-900">#{it.invoice_no}</span>
      <div className="text-xs text-slate-400">
        {it.contract_no ? `Contract ${it.contract_no}` : '—'}
        {it.plate ? ` · ${it.plate}` : ''}
        {it.car ? ` · ${it.car}` : ''}
      </div>
    </div>
  );
}

// One conflict type → a SectionCard wrapping a DataTable of its broken invoices.
function Group({ g }) {
  const sev = SEV[g.severity] || SEV.warning;
  const isCritical = g.severity === 'critical';
  return (
    <SectionCard
      title={
        <span className="inline-flex items-center gap-2">
          <span className={`h-2.5 w-2.5 rounded-full ${isCritical ? 'bg-red-500' : g.severity === 'warning' ? 'bg-amber-500' : 'bg-blue-500'}`} />
          {g.title}
          <Badge tone={sev.tone}>{num(g.count)}</Badge>
        </span>
      }
      subtitle={g.description}
      actions={<span className="text-xs font-medium uppercase tracking-wide text-slate-400">{sev.label}</span>}
    >
      <DataTable
        rows={g.items}
        // No stable id on a conflict row; DataTable falls back to the array index (matches prior key).
        // Flag the money-at-risk groups so the worst rows draw the eye.
        highlightRow={() => isCritical}
        empty="Nothing to show."
        columns={[
          { key: 'invoice', header: 'Invoice', render: (it) => <Record it={it} /> },
          { key: 'detail', header: 'Conflict', render: (it) => it.detail },
          {
            key: 'amount', header: 'Amount', align: 'right', cellClass: 'tabular-nums text-slate-700',
            render: (it) => (it.amount ? `AED ${it.amount}` : <span className="text-slate-300">—</span>),
          },
          { key: 'open', header: 'Open', align: 'right', render: (it) => <RowLink it={it} /> },
        ]}
      />
      {g.shown < g.count && (
        <div className="border-t border-slate-100 px-5 py-2 text-xs text-slate-400">
          Showing the first {num(g.shown)} of {num(g.count)}.
        </div>
      )}
    </SectionCard>
  );
}

export default function FinancialConflicts() {
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/FinancialConflicts');
    return data.data;
  }, []);
  const { data, loading, error } = useFetch(fetcher);

  // Floating page gauge: of all conflicts, the share that are money-at-risk (critical).
  const total = data?.total_conflicts || 0;
  const critical = data?.critical || 0;
  usePageStat({
    percent: loading || !total ? null : (critical / total) * 100,
    label: 'Critical',
    color: 'red',
    hint: `${critical} of ${total} financial conflicts are money-at-risk`,
  });

  const groups = (data?.groups || []).filter((g) => g.count > 0);
  const clean = !loading && !error && groups.length === 0;

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title="Financial Conflicts" subtitle="The accounting clean-up hub — only the broken invoices. VAT that doesn't add up, invoices that disagree with their contract, and overlapping (double) billing. Fix these to keep the books bulletproof." />

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {/* Summary counts per conflict type. Problem counts go red/amber; a zero
            count is reassuring emerald. */}
        {loading ? (
          <MetricGridSkeleton count={4} />
        ) : (
          <MetricGrid cols={4}>
            <MetricCard
              label="Total conflicts"
              value={num(data?.total_conflicts)}
              tone={total ? 'slate' : 'emerald'}
              icon={<Icon.Invoice className="h-5 w-5" />}
              tooltip="Every broken invoice across all conflict types — the size of the clean-up backlog."
            />
            <MetricCard
              label="Money at risk"
              value={num(data?.critical)}
              tone={critical ? 'red' : 'emerald'}
              icon={<Icon.Cash className="h-5 w-5" />}
              tooltip="Critical conflicts where real money is exposed — e.g. double-billing (the same period billed twice) or an invoice that disagrees with its contract."
            />
            <MetricCard
              label="Books off"
              value={num(data?.warning)}
              tone={(data?.warning || 0) ? 'amber' : 'emerald'}
              icon={<Icon.Scale className="h-5 w-5" />}
              tooltip="Bookkeeping errors that don't directly lose money but make the ledger inconsistent — e.g. VAT math that doesn't add up."
            />
            <MetricCard
              label="Conflict types"
              value={num(data?.flagged_groups)}
              tone={(data?.flagged_groups || 0) ? 'amber' : 'emerald'}
              icon={<Icon.Alert className="h-5 w-5" />}
              tooltip="How many distinct categories of problem are currently flagged."
            />
          </MetricGrid>
        )}

        {/* Glossary of the technical terms used in the conflict groups below. */}
        {!loading && !clean && (
          <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 rounded-xl bg-slate-50/70 px-4 py-3 text-xs text-slate-500 ring-1 ring-inset ring-slate-900/5">
            <span className="font-medium text-slate-600">Terms:</span>
            <Tooltip content="The invoice's VAT line doesn't equal the taxable base × the VAT rate — the tax math doesn't add up.">
              <span className="cursor-help underline decoration-dotted">VAT math</span>
            </Tooltip>
            <Tooltip content="The vehicle the invoice points at, after matching it back to the contract's actual car (not just the raw label on the invoice).">
              <span className="cursor-help underline decoration-dotted">Resolved vehicle</span>
            </Tooltip>
            <Tooltip content="Two invoices charge for the same car over time windows that intersect.">
              <span className="cursor-help underline decoration-dotted">Overlap</span>
            </Tooltip>
            <Tooltip content="The same rental period billed more than once — real money charged twice.">
              <span className="cursor-help underline decoration-dotted">Double-billing</span>
            </Tooltip>
          </div>
        )}

        {loading ? (
          <SectionCard title="Conflicts">
            <DataTable
              loading
              rows={[]}
              columns={[
                { key: 'invoice', header: 'Invoice' },
                { key: 'detail', header: 'Conflict' },
                { key: 'amount', header: 'Amount', align: 'right' },
                { key: 'open', header: 'Open', align: 'right' },
              ]}
            />
          </SectionCard>
        ) : clean ? (
          <SectionCard>
            <EmptyState
              title="Books are clean 🎉"
              message="Every invoice adds up, matches its contract, and bills a non-overlapping period."
              icon={<Icon.Check className="h-7 w-7 text-emerald-500" />}
            />
          </SectionCard>
        ) : (
          groups.map((g) => <Group key={g.key} g={g} />)
        )}
      </div>
    </div>
  );
}
