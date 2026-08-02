import { useCallback, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { PageHeader, EmptyState, ErrorState } from '../components/ui/Misc';
import DataTable, { SectionCard } from '../components/ui/Table';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import { MetricGridSkeleton } from '../components/ui/Skeleton';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import Segmented from '../components/ui/Segmented';
import { aed2, fmtDate, num } from '../lib/format';

/**
 * Fleet Component Intelligence — the asset-layer counterpart to the per-vehicle Installed Components
 * tab. Answers the questions that only make sense across the whole fleet: what is about to fall out
 * of warranty, what is running past its expected life, what we have been replacing lately, and which
 * component types churn hardest (the buying signal).
 *
 * Every headline number is drillable: each card carries the true total AND the rows behind it, so a
 * manager can open any figure rather than trust it (standing traceability rule — no black boxes).
 * Read-only throughout; the maintenance workflow remains the only writer.
 */

const CATEGORY_LABEL = {
  engine: 'Engine', brakes: 'Brakes', tyres: 'Tyres & Wheels', suspension: 'Suspension & Steering',
  transmission: 'Transmission', electrical: 'Electrical', ac: 'Climate / A-C', fluids: 'Fluids',
  bodywork: 'Bodywork', interior: 'Interior', lights: 'Lights', routine: 'Routine',
};

const REASON_LABEL = {
  failed: 'Failed', worn_out: 'Worn out', accident: 'Accident', upgrade: 'Upgraded',
  recall: 'Recall', transfer: 'Transferred', vehicle_sold: 'Vehicle sold', unknown_legacy: 'Unknown (legacy)',
};

const km = (v) => (v === null || v === undefined ? '—' : `${num(v)} km`);

function humanAge(days) {
  if (days === null || days === undefined) return '—';
  if (days < 45) return `${days} d`;
  const months = Math.round(days / 30.44);
  if (months < 24) return `${months} mo`;
  return `${Math.floor(months / 12)} y ${months % 12} mo`;
}

/** Plate cell → straight into that car's Installed Components tab, the row's natural next step. */
function PlateLink({ row }) {
  if (!row.vehicle_id) return <span className="text-slate-400">—</span>;
  return (
    <Link to={`/vehicles/${row.vehicle_id}?tab=components`} className="font-semibold text-indigo-600 hover:underline">
      {row.plate_no || `#${row.vehicle_id}`}
    </Link>
  );
}

function PartCell({ row }) {
  return (
    <div className="min-w-0">
      <div className="font-semibold text-slate-900">{row.type || row.part_name}</div>
      <div className="truncate text-xs text-slate-400">
        {[row.brand, row.part_number].filter(Boolean).join(' · ') || CATEGORY_LABEL[row.category] || row.category}
      </div>
    </div>
  );
}

export default function ComponentsDashboard() {
  const [board, setBoard] = useState('warranty');

  const fetcher = useCallback(async () => (await api.get('/components/dashboard', { params: { limit: 100 } })).data.data, []);
  const { data, loading, error, reload } = useFetch(fetcher, [], { refreshInterval: 120000 });

  const totals = data?.totals || {};
  const expiring = data?.warranty_expiring || {};
  const pastLife = data?.past_expected_life || {};
  const recent = data?.recently_replaced || {};
  const frequent = data?.frequently_replaced || {};

  if (error) return <ErrorState message="Could not load the component dashboard." onRetry={reload} />;

  return (
    <div className="space-y-6">
      <PageHeader
        title="Component Intelligence"
        subtitle="Every part installed across the fleet — warranty exposure, service life, replacement churn and asset value. Derived entirely from the maintenance workflow."
      />

      {loading && !data ? (
        <MetricGridSkeleton count={6} />
      ) : (
        <MetricGrid cols={6}>
          <MetricCard
            label="Installed components"
            value={num(totals.installed_components ?? 0)}
            icon={<Icon.Wrench className="h-5 w-5" />}
            hint={`Across ${num(totals.vehicles_covered ?? 0)} vehicles`}
          />
          {/* Not "everything currently fitted" — everything whose cost we know. Parts installed
              through repair capture carry no purchase and no price, so the hint states the
              denominator rather than letting the headline imply a complete total. */}
          <MetricCard
            label="Total installed value"
            value={aed2(totals.total_installed_value ?? 0)}
            icon={<Icon.Cash className="h-5 w-5" />}
            tone="indigo"
            hint={
              totals.uncosted_components
                ? `Purchase cost of ${num(totals.costed_components ?? 0)} of ${num(totals.installed_components ?? 0)} fitted parts — ${num(totals.uncosted_components)} were reported without one`
                : 'Purchase cost of everything currently fitted'
            }
          />
          <MetricCard
            label="Average age"
            value={humanAge(totals.average_age_days)}
            icon={<Icon.Clock className="h-5 w-5" />}
            hint="Mean age of the fleet's fitted parts"
          />
          <MetricCard
            label="Warranty expiring"
            value={num(expiring.count ?? 0)}
            tone={expiring.count > 0 ? 'amber' : 'slate'}
            icon={<Icon.Shield className="h-5 w-5" />}
            hint={`Within the next ${expiring.window_days ?? 60} days`}
            onClick={() => setBoard('warranty')}
          />
          <MetricCard
            label="Past expected life"
            value={num(pastLife.count ?? 0)}
            tone={pastLife.count > 0 ? 'red' : 'slate'}
            icon={<Icon.Alert className="h-5 w-5" />}
            hint="Beyond the catalog's expected km or months"
            onClick={() => setBoard('life')}
          />
          <MetricCard
            label="Recently replaced"
            value={num(recent.count ?? 0)}
            icon={<Icon.Refresh className="h-5 w-5" />}
            hint={`In the last ${recent.window_days ?? 90} days`}
            onClick={() => setBoard('recent')}
          />
        </MetricGrid>
      )}

      <SectionCard
        title="Component boards"
        subtitle="Each board is the full row set behind the card above it — open any number and audit it."
        actions={
          <Segmented
            value={board}
            onChange={setBoard}
            options={[
              { key: 'warranty', label: `Warranty (${num(expiring.count ?? 0)})` },
              { key: 'life', label: `Past life (${num(pastLife.count ?? 0)})` },
              { key: 'recent', label: `Replaced (${num(recent.count ?? 0)})` },
              { key: 'frequent', label: 'Churn' },
            ]}
          />
        }
      >
        {board === 'warranty' && (
          <DataTable
            loading={loading && !data}
            rows={expiring.rows || []}
            rowKey={(r) => r.id}
            empty="No component warranty expires in the next 60 days."
            stickyHeader
            columns={[
              { key: 'plate', header: 'Vehicle', render: (r) => <PlateLink row={r} /> },
              { key: 'part', header: 'Component', render: (r) => <PartCell row={r} /> },
              { key: 'supplier', header: 'Supplier', render: (r) => r.supplier?.name || '—' },
              { key: 'installed', header: 'Installed', render: (r) => fmtDate(r.installed_at) },
              {
                key: 'ends',
                header: 'Warranty ends',
                render: (r) => (
                  <div>
                    <div className="font-medium">{fmtDate(r.warranty?.until)}</div>
                    <Badge tone={r.warranty?.days_remaining <= 14 ? 'red' : 'amber'}>
                      {r.warranty?.days_remaining} days left
                    </Badge>
                  </div>
                ),
              },
              { key: 'cost', header: 'Cost', align: 'right', cellClass: 'tabular-nums font-semibold', render: (r) => aed2(r.purchase_cost || 0) },
            ]}
          />
        )}

        {board === 'life' && (
          <DataTable
            loading={loading && !data}
            rows={pastLife.rows || []}
            rowKey={(r) => r.id}
            empty="Nothing is running past its expected service life."
            stickyHeader
            columns={[
              { key: 'plate', header: 'Vehicle', render: (r) => <PlateLink row={r} /> },
              { key: 'part', header: 'Component', render: (r) => <PartCell row={r} /> },
              { key: 'installed', header: 'Installed', render: (r) => `${fmtDate(r.installed_at)} · ${km(r.installed_odometer)}` },
              { key: 'age', header: 'Age', render: (r) => <div>{humanAge(r.age_days)}<div className="text-xs text-slate-400">{km(r.distance_km)} driven</div></div> },
              {
                key: 'used',
                header: 'Life used',
                render: (r) => (
                  <div>
                    <Badge tone="red">{r.service_life?.life_used_pct}%</Badge>
                    <div className="mt-0.5 text-xs text-slate-400">
                      expected {r.service_life?.expected_life_km ? km(r.service_life.expected_life_km) : `${r.service_life?.expected_life_months} mo`}
                    </div>
                  </div>
                ),
              },
            ]}
          />
        )}

        {board === 'recent' && (
          <DataTable
            loading={loading && !data}
            rows={recent.rows || []}
            rowKey={(r) => r.id}
            empty="No components have been replaced in the last 90 days."
            stickyHeader
            columns={[
              { key: 'plate', header: 'Vehicle', render: (r) => <PlateLink row={r} /> },
              { key: 'part', header: 'Removed part', render: (r) => <PartCell row={r} /> },
              { key: 'removed', header: 'Removed', render: (r) => fmtDate(r.removed_at) },
              {
                key: 'reason',
                header: 'Reason',
                render: (r) => <Badge tone={r.removal_reason === 'failed' ? 'red' : 'gray'}>{REASON_LABEL[r.removal_reason] || r.removal_reason}</Badge>,
              },
              { key: 'lasted', header: 'Lasted', render: (r) => <div>{humanAge(r.life_days)}<div className="text-xs text-slate-400">{km(r.life_km)}</div></div> },
              {
                key: 'successor',
                header: 'Replaced by',
                render: (r) =>
                  r.replaced_by ? (
                    <div>
                      <div className="font-medium text-slate-900">{r.replaced_by.part_name}</div>
                      <div className="text-xs text-slate-400">fitted {fmtDate(r.replaced_by.installed_at)}</div>
                    </div>
                  ) : (
                    <span className="text-slate-400">Not replaced</span>
                  ),
              },
            ]}
          />
        )}

        {board === 'frequent' && (
          <DataTable
            loading={loading && !data}
            rows={frequent.rows || []}
            rowKey={(r) => r.component_catalog_id}
            empty="No replacement history yet."
            stickyHeader
            columns={[
              {
                key: 'type',
                header: 'Component type',
                render: (r) => (
                  <div>
                    <div className="font-semibold text-slate-900">{r.type}</div>
                    <div className="text-xs text-slate-400">{CATEGORY_LABEL[r.category] || r.category}</div>
                  </div>
                ),
              },
              { key: 'replacements', header: 'Replacements', align: 'right', cellClass: 'tabular-nums font-semibold', render: (r) => num(r.replacements) },
              { key: 'vehicles', header: 'Vehicles', align: 'right', cellClass: 'tabular-nums', render: (r) => num(r.vehicles) },
              {
                key: 'life',
                header: 'Average life achieved',
                render: (r) => (
                  <div>
                    <div>{km(r.avg_life_km)}</div>
                    <div className="text-xs text-slate-400">{humanAge(r.avg_life_days)}</div>
                  </div>
                ),
              },
              {
                key: 'vs',
                header: 'vs expected',
                tooltip: 'Average achieved life against the catalog expectation. Consistently short means a bad part or a bad supplier.',
                render: (r) => {
                  if (!r.expected_life_km || !r.avg_life_km) return <span className="text-slate-400">—</span>;
                  const pct = Math.round((r.avg_life_km / r.expected_life_km) * 100);
                  return <Badge tone={pct < 70 ? 'red' : pct < 95 ? 'amber' : 'green'}>{pct}% of expected</Badge>;
                },
              },
              { key: 'spend', header: 'Total spend', align: 'right', cellClass: 'tabular-nums font-semibold', render: (r) => aed2(r.total_spend || 0) },
            ]}
          />
        )}

        {!loading && !data && <EmptyState title="No component data" message="Nothing has been installed through the workflow yet." />}
      </SectionCard>

      <p className="px-1 text-xs leading-relaxed text-slate-400">
        <span className="font-semibold text-slate-500">Data origin</span> · {data?.data_origin}
      </p>
    </div>
  );
}
