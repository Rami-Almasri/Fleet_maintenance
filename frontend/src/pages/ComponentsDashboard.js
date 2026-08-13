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
import { useI18n } from '../i18n/I18nContext';

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

// Vocabulary tables. The words are resolved through the translator at call time, so `t` is threaded
// in rather than captured — these are plain modules, not components.
const categoryLabel = (t, v) => ({
  engine: t('Engine'), brakes: t('Brakes'), tyres: t('Tyres & Wheels'), suspension: t('Suspension & Steering'),
  transmission: t('Transmission'), electrical: t('Electrical'), ac: t('Climate / A-C'), fluids: t('Fluids'),
  bodywork: t('Bodywork'), interior: t('Interior'), lights: t('Lights'), routine: t('Routine'),
}[v]);

const reasonLabel = (t, v) => ({
  failed: t('Failed'), worn_out: t('Worn out'), accident: t('Accident'), upgrade: t('Upgraded'),
  recall: t('Recall'), transfer: t('Transferred'), vehicle_sold: t('Vehicle sold'),
  unknown_legacy: t('Unknown (legacy)'),
}[v]);

const km = (v) => (v === null || v === undefined ? '—' : `${num(v)} km`);

function humanAge(t, days) {
  if (days === null || days === undefined) return '—';
  if (days < 45) return t('{n} d', { n: days });
  const months = Math.round(days / 30.44);
  if (months < 24) return t('{n} mo', { n: months });
  return t('{y} y {m} mo', { y: Math.floor(months / 12), m: months % 12 });
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
  const { t } = useI18n();
  return (
    <div className="min-w-0">
      <div className="font-semibold text-slate-900">{row.type || row.part_name}</div>
      <div className="truncate text-xs text-slate-400">
        {[row.brand, row.part_number].filter(Boolean).join(' · ') || categoryLabel(t, row.category) || row.category}
      </div>
    </div>
  );
}

export default function ComponentsDashboard() {
  const { t } = useI18n();
  const [board, setBoard] = useState('warranty');

  const fetcher = useCallback(async () => (await api.get('/components/dashboard', { params: { limit: 100 } })).data.data, []);
  const { data, loading, error, reload } = useFetch(fetcher, [], { refreshInterval: 120000 });

  const totals = data?.totals || {};
  const expiring = data?.warranty_expiring || {};
  const pastLife = data?.past_expected_life || {};
  const recent = data?.recently_replaced || {};
  const frequent = data?.frequently_replaced || {};

  if (error) return <ErrorState message={t('Could not load the component dashboard.')} onRetry={reload} />;

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('Component Intelligence')}
        subtitle={t('Every part installed across the fleet — warranty exposure, service life, replacement churn and asset value. Derived entirely from the maintenance workflow.')}
      />

      {loading && !data ? (
        <MetricGridSkeleton count={6} />
      ) : (
        <MetricGrid cols={6}>
          <MetricCard
            label={t('Installed components')}
            value={num(totals.installed_components ?? 0)}
            icon={<Icon.Wrench className="h-5 w-5" />}
            hint={t('Across {n} vehicles', { n: num(totals.vehicles_covered ?? 0) })}
          />
          {/* Not "everything currently fitted" — everything whose cost we know. Parts installed
              through repair capture carry no purchase and no price, so the hint states the
              denominator rather than letting the headline imply a complete total. */}
          <MetricCard
            label={t('Total installed value')}
            value={aed2(totals.total_installed_value ?? 0)}
            icon={<Icon.Cash className="h-5 w-5" />}
            tone="indigo"
            hint={
              totals.uncosted_components
                ? t('Purchase cost of {costed} of {total} fitted parts — {uncosted} were reported without one', {
                  costed: num(totals.costed_components ?? 0),
                  total: num(totals.installed_components ?? 0),
                  uncosted: num(totals.uncosted_components),
                })
                : t('Purchase cost of everything currently fitted')
            }
          />
          <MetricCard
            label={t('Average age')}
            value={humanAge(t, totals.average_age_days)}
            icon={<Icon.Clock className="h-5 w-5" />}
            hint={t("Mean age of the fleet's fitted parts")}
          />
          <MetricCard
            label={t('Warranty expiring')}
            value={num(expiring.count ?? 0)}
            tone={expiring.count > 0 ? 'amber' : 'slate'}
            icon={<Icon.Shield className="h-5 w-5" />}
            hint={t('Within the next {n} days', { n: expiring.window_days ?? 60 })}
            onClick={() => setBoard('warranty')}
          />
          <MetricCard
            label={t('Past expected life')}
            value={num(pastLife.count ?? 0)}
            tone={pastLife.count > 0 ? 'red' : 'slate'}
            icon={<Icon.Alert className="h-5 w-5" />}
            hint={t("Beyond the catalog's expected km or months")}
            onClick={() => setBoard('life')}
          />
          <MetricCard
            label={t('Recently replaced')}
            value={num(recent.count ?? 0)}
            icon={<Icon.Refresh className="h-5 w-5" />}
            hint={t('In the last {n} days', { n: recent.window_days ?? 90 })}
            onClick={() => setBoard('recent')}
          />
        </MetricGrid>
      )}

      <SectionCard
        title={t('Component boards')}
        subtitle={t('Each board is the full row set behind the card above it — open any number and audit it.')}
        actions={
          <Segmented
            value={board}
            onChange={setBoard}
            options={[
              { key: 'warranty', label: t('Warranty ({n})', { n: num(expiring.count ?? 0) }) },
              { key: 'life', label: t('Past life ({n})', { n: num(pastLife.count ?? 0) }) },
              { key: 'recent', label: t('Replaced ({n})', { n: num(recent.count ?? 0) }) },
              { key: 'frequent', label: t('Churn') },
            ]}
          />
        }
      >
        {board === 'warranty' && (
          <DataTable
            loading={loading && !data}
            rows={expiring.rows || []}
            rowKey={(r) => r.id}
            empty={t('No component warranty expires in the next 60 days.')}
            stickyHeader
            columns={[
              { key: 'plate', header: t('Vehicle'), render: (r) => <PlateLink row={r} /> },
              { key: 'part', header: t('Component'), render: (r) => <PartCell row={r} /> },
              { key: 'supplier', header: t('Supplier'), render: (r) => r.supplier?.name || '—' },
              { key: 'installed', header: t('Installed'), render: (r) => fmtDate(r.installed_at) },
              {
                key: 'ends',
                header: t('Warranty ends'),
                render: (r) => (
                  <div>
                    <div className="font-medium">{fmtDate(r.warranty?.until)}</div>
                    <Badge tone={r.warranty?.days_remaining <= 14 ? 'red' : 'amber'}>
                      {r.warranty?.days_remaining === 1
                        ? t('1 day left')
                        : t('{n} days left', { n: r.warranty?.days_remaining })}
                    </Badge>
                  </div>
                ),
              },
              { key: 'cost', header: t('Cost'), align: 'right', cellClass: 'tabular-nums font-semibold', render: (r) => aed2(r.purchase_cost || 0) },
            ]}
          />
        )}

        {board === 'life' && (
          <DataTable
            loading={loading && !data}
            rows={pastLife.rows || []}
            rowKey={(r) => r.id}
            empty={t('Nothing is running past its expected service life.')}
            stickyHeader
            columns={[
              { key: 'plate', header: t('Vehicle'), render: (r) => <PlateLink row={r} /> },
              { key: 'part', header: t('Component'), render: (r) => <PartCell row={r} /> },
              { key: 'installed', header: t('Installed'), render: (r) => `${fmtDate(r.installed_at)} · ${km(r.installed_odometer)}` },
              { key: 'age', header: t('Age'), render: (r) => <div>{humanAge(t, r.age_days)}<div className="text-xs text-slate-400">{t('{km} driven', { km: km(r.distance_km) })}</div></div> },
              {
                key: 'used',
                header: t('Life used'),
                render: (r) => (
                  <div>
                    <Badge tone="red">{r.service_life?.life_used_pct}%</Badge>
                    <div className="mt-0.5 text-xs text-slate-400">
                      {t('expected {life}', {
                        life: r.service_life?.expected_life_km
                          ? km(r.service_life.expected_life_km)
                          : t('{n} mo', { n: r.service_life?.expected_life_months }),
                      })}
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
            empty={t('No components have been replaced in the last 90 days.')}
            stickyHeader
            columns={[
              { key: 'plate', header: t('Vehicle'), render: (r) => <PlateLink row={r} /> },
              { key: 'part', header: t('Removed part'), render: (r) => <PartCell row={r} /> },
              { key: 'removed', header: t('Removed'), render: (r) => fmtDate(r.removed_at) },
              {
                key: 'reason',
                header: t('Reason'),
                render: (r) => <Badge tone={r.removal_reason === 'failed' ? 'red' : 'gray'}>{reasonLabel(t, r.removal_reason) || r.removal_reason}</Badge>,
              },
              { key: 'lasted', header: t('Lasted'), render: (r) => <div>{humanAge(t, r.life_days)}<div className="text-xs text-slate-400">{km(r.life_km)}</div></div> },
              {
                key: 'successor',
                header: t('Replaced by'),
                render: (r) =>
                  r.replaced_by ? (
                    <div>
                      <div className="font-medium text-slate-900">{r.replaced_by.part_name}</div>
                      <div className="text-xs text-slate-400">{t('fitted {date}', { date: fmtDate(r.replaced_by.installed_at) })}</div>
                    </div>
                  ) : (
                    <span className="text-slate-400">{t('Not replaced')}</span>
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
            empty={t('No replacement history yet.')}
            stickyHeader
            columns={[
              {
                key: 'type',
                header: t('Component type'),
                render: (r) => (
                  <div>
                    <div className="font-semibold text-slate-900">{r.type}</div>
                    <div className="text-xs text-slate-400">{categoryLabel(t, r.category) || r.category}</div>
                  </div>
                ),
              },
              { key: 'replacements', header: t('Replacements'), align: 'right', cellClass: 'tabular-nums font-semibold', render: (r) => num(r.replacements) },
              { key: 'vehicles', header: t('Vehicles'), align: 'right', cellClass: 'tabular-nums', render: (r) => num(r.vehicles) },
              {
                key: 'life',
                header: t('Average life achieved'),
                render: (r) => (
                  <div>
                    <div>{km(r.avg_life_km)}</div>
                    <div className="text-xs text-slate-400">{humanAge(t, r.avg_life_days)}</div>
                  </div>
                ),
              },
              {
                key: 'vs',
                header: t('vs expected'),
                tooltip: t('Average achieved life against the catalog expectation. Consistently short means a bad part or a bad supplier.'),
                render: (r) => {
                  if (!r.expected_life_km || !r.avg_life_km) return <span className="text-slate-400">—</span>;
                  const pct = Math.round((r.avg_life_km / r.expected_life_km) * 100);
                  return <Badge tone={pct < 70 ? 'red' : pct < 95 ? 'amber' : 'green'}>{t('{pct}% of expected', { pct })}</Badge>;
                },
              },
              { key: 'spend', header: t('Total spend'), align: 'right', cellClass: 'tabular-nums font-semibold', render: (r) => aed2(r.total_spend || 0) },
            ]}
          />
        )}

        {!loading && !data && <EmptyState title={t('No component data')} message={t('Nothing has been installed through the workflow yet.')} />}
      </SectionCard>

      <p className="px-1 text-xs leading-relaxed text-slate-400">
        <span className="font-semibold text-slate-500">{t('Data origin')}</span> · {data?.data_origin}
      </p>
    </div>
  );
}
