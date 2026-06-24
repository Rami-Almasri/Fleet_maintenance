import { useCallback } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { PageHeader } from '../components/ui/Misc';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import DataTable, { SectionCard } from '../components/ui/Table';
import { MetricGridSkeleton } from '../components/ui/Skeleton';
import { Tooltip } from '../components/ui/Tooltip';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import { num } from '../lib/format';

// "2026-06-22T12:41:51Z" -> "22 Jun 2026, 12:41"
const fmtDateTime = (v) => {
  if (!v) return '—';
  const d = new Date(v);
  if (isNaN(d)) return String(v);
  return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

/**
 * Override Audit — the transparency trail for the "Rental-First" policy. Every time a
 * manager knowingly opened a maintenance contract on a car that still had a live rental,
 * a row lands here: who allowed it, why (reason + notes), and which rental it broke.
 */
export default function OverrideAudit() {
  const fetcher = useCallback(async () => (await api.get('/Operations/overrides')).data.data, []);
  const { data, loading, error } = useFetch(fetcher);

  const rows = data?.overrides || [];

  // Summary stats — total overrides, the most-used reason code, and how many
  // distinct managers have exercised the override. Pure presentation rollups
  // over the already-fetched rows (no extra fetching/business logic).
  const total = rows.length;
  const reasonCounts = rows.reduce((acc, r) => {
    const key = r.reason_label || r.reason_code || 'Unspecified';
    acc[key] = (acc[key] || 0) + 1;
    return acc;
  }, {});
  const topReason = Object.entries(reasonCounts).sort((a, b) => b[1] - a[1])[0];
  const distinctManagers = new Set(rows.map((r) => r.user_name).filter(Boolean)).size;

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Override Audit"
          subtitle='Every "Rental-First" override: a manager opened a maintenance contract on a car that still had a live rental. Who allowed it, why, and which rental it closed.'
        >
          <Link to="/vehicles" className="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 transition hover:text-indigo-700">
            Vehicles <Icon.ArrowRight className="h-4 w-4" />
          </Link>
        </PageHeader>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {/* Policy primer */}
        <div className="flex items-start gap-2.5 rounded-xl bg-amber-50/70 px-4 py-3 text-xs text-amber-800 ring-1 ring-inset ring-amber-600/15">
          <Icon.Shield className="mt-px h-4 w-4 shrink-0 text-amber-600" />
          <p>
            Under the{' '}
            <Tooltip content="Rental-First policy: a car must not be put on a maintenance contract while it still has a live rental. Staff are hard-blocked; only a manager can override, and must record a reason code.">
              <span className="cursor-help font-semibold underline decoration-dotted">Rental-First policy</span>
            </Tooltip>
            , staff are blocked from opening maintenance on a rented car. Each row below is a deliberate manager{' '}
            <Tooltip content="Override: a manager bypassed the Rental-First block, knowingly opening a maintenance contract on a car with a live rental. Logged here for transparency.">
              <span className="cursor-help font-semibold underline decoration-dotted">override</span>
            </Tooltip>
            {' '}with its{' '}
            <Tooltip content="Reason code: the categorised justification a manager selected when overriding (e.g. urgent safety, customer no-show). Free-text notes may add detail.">
              <span className="cursor-help font-semibold underline decoration-dotted">reason code</span>
            </Tooltip>
            .
          </p>
        </div>

        {/* Summary metrics */}
        {loading ? (
          <MetricGridSkeleton count={3} />
        ) : (
          <MetricGrid cols={3}>
            <MetricCard
              label="Total Overrides"
              value={num(total)}
              tone={total > 0 ? 'amber' : 'slate'}
              icon={<Icon.Shield className="h-5 w-5" />}
              hint={total === 1 ? '1 override logged' : `${num(total)} overrides logged`}
              tooltip="Every time a manager bypassed the Rental-First block to open a maintenance contract on a rented car."
            />
            <MetricCard
              label="Top Reason"
              value={topReason ? topReason[0] : '—'}
              tone={total > 0 ? 'red' : 'slate'}
              icon={<Icon.Flag className="h-5 w-5" />}
              hint={topReason ? `${num(topReason[1])} of ${num(total)} override${total === 1 ? '' : 's'}` : 'No overrides yet'}
              tooltip="The most frequently selected reason code across all overrides."
            />
            <MetricCard
              label="Managers Involved"
              value={num(distinctManagers)}
              tone="slate"
              icon={<Icon.Users className="h-5 w-5" />}
              hint={distinctManagers === 1 ? '1 distinct manager' : `${num(distinctManagers)} distinct managers`}
              tooltip="Distinct managers who have exercised at least one Rental-First override."
            />
          </MetricGrid>
        )}

        {/* Audit log */}
        <SectionCard
          title="Override log"
          subtitle="Chronological trail of every Rental-First override."
          actions={
            !loading && total > 0 ? (
              <span className="text-xs text-slate-400">{num(total)} override{total === 1 ? '' : 's'} logged</span>
            ) : null
          }
        >
          <DataTable
            rows={rows}
            rowKey={(r) => r.id}
            loading={loading}
            empty="When a manager overrides the Rental-First block to open a maintenance contract on a rented car, it will appear here."
            columns={[
              {
                key: 'when', header: 'When',
                tooltip: 'When the override was recorded (local time).',
                cellClass: 'whitespace-nowrap text-slate-500',
                render: (r) => fmtDateTime(r.created_at),
              },
              {
                key: 'manager', header: 'Manager',
                tooltip: 'The manager who authorised the override.',
                cellClass: 'font-medium text-slate-700',
                render: (r) => r.user_name || '—',
              },
              {
                key: 'car', header: 'Car',
                render: (r) => (
                  r.vehicle_id
                    ? <Link to={`/vehicles/${r.vehicle_id}`} className="font-medium text-indigo-600 hover:text-indigo-700">{r.plate || `#${r.vehicle_id}`}</Link>
                    : (r.plate || '—')
                ),
              },
              {
                key: 'reason', header: 'Reason',
                tooltip: 'The reason code the manager selected, with any free-text notes.',
                render: (r) => (
                  <div className="space-y-1">
                    <Badge tone="amber" className="normal-case">{r.reason_label || r.reason_code || 'Unspecified'}</Badge>
                    {r.notes && <p className="text-xs text-slate-500">{r.notes}</p>}
                  </div>
                ),
              },
              {
                key: 'rental', header: 'Rental closed',
                tooltip: 'The live rental contract that the override broke / closed.',
                cellClass: 'text-slate-500',
                render: (r) => r.rental_contract_no || '—',
              },
              {
                key: 'maintenance', header: 'Maintenance',
                tooltip: 'The maintenance contract opened as a result of the override.',
                cellClass: 'text-slate-500',
                render: (r) => r.result_contract_no || '—',
              },
            ]}
          />
        </SectionCard>
      </div>
    </div>
  );
}
