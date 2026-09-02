import { useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import DataTable, { SectionCard } from '../../components/ui/Table';
import Badge from '../../components/ui/Badge';
import Drawer from '../../components/ui/Drawer';
import Icon from '../../components/ui/Icon';
import MetricCard, { MetricGrid } from '../../components/ui/MetricCard';
import { MetricGridSkeleton } from '../../components/ui/Skeleton';
import { EmptyState, ErrorState, SearchInput } from '../../components/ui/Misc';
import Segmented from '../../components/ui/Segmented';
import DateRangePicker from '../../components/ui/DateRangePicker';
import ComponentRepeatAlert from './ComponentRepeatAlert';
import { PartSpecDetail, PartSpecLine } from '../parts/PartSpecFields';
import { aed2, fmtDate, num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

/**
 * Vehicle Installed Components — "what is physically on this car TODAY", plus the full replacement
 * history of every slot.
 *
 * There is NO "Add Component" control here, on purpose. A part appears on this list only because the
 * maintenance workflow reached its install step; the API that backs this panel is read-only, so the
 * record cannot drift from the physical car through a side door. When the list looks wrong, the fix
 * is upstream in the ticket, which is exactly where the evidence lives.
 */

// Human labels for the raw category keys the catalog uses. The English here is also the i18n
// phrase key — every read site resolves it through t() before it reaches the screen.
const CATEGORY_LABEL = {
  engine: 'Engine', brakes: 'Brakes', tyres: 'Tyres & Wheels', suspension: 'Suspension & Steering',
  transmission: 'Transmission', electrical: 'Electrical', ac: 'Climate / A-C', fluids: 'Fluids',
  bodywork: 'Bodywork', interior: 'Interior', lights: 'Lights', routine: 'Routine',
};

const POSITION_LABEL = {
  front_left: 'Front L', front_right: 'Front R', rear_left: 'Rear L', rear_right: 'Rear R',
  front: 'Front', rear: 'Rear',
};

const WARRANTY_TONE = { active: 'green', expiring_soon: 'amber', expired: 'gray', none: 'gray' };
const WARRANTY_LABEL = { active: 'Under warranty', expiring_soon: 'Expiring soon', expired: 'Expired', none: 'No warranty' };

const LIFE_TONE = { within: 'green', due_soon: 'amber', overdue: 'red', unknown: 'gray' };
const LIFE_LABEL = { within: 'Within life', due_soon: 'Due soon', overdue: 'Past expected life', unknown: 'No expectation set' };

const STATUS_TONE = { active: 'green', in_stock: 'blue', retired: 'gray' };

const REASON_LABEL = {
  failed: 'Failed', worn_out: 'Worn out', accident: 'Accident', upgrade: 'Upgraded',
  recall: 'Recall', transfer: 'Transferred', vehicle_sold: 'Vehicle sold', unknown_legacy: 'Unknown (legacy)',
};

const EVENT_LABEL = {
  purchased: 'Purchased', stored: 'Stored in warehouse', installed: 'Installed', removed: 'Removed',
  transferred: 'Transferred to another vehicle', disposed: 'Scrapped',
  returned_supplier: 'Returned to supplier', warranty_claimed: 'Returned under warranty', sold: 'Sold',
};

const km = (v) => (v === null || v === undefined ? '—' : `${num(v)} km`);

/**
 * The replacement limit this part is judged against, written the way the workshop says it out loud:
 * "12 months or 20,000 km". Whichever clock runs out FIRST is what makes the part due, which is why
 * the two are joined by "or" and never by a comma.
 *
 * Returns null when no expectation is stated — the caller decides how to say "we never set one",
 * because a blank and a zero mean very different things and neither may be guessed.
 */
function limitText(life, t) {
  if (!life) return null;
  const months = life.expected_life_months;
  const distance = life.expected_life_km;

  // Whole phrases rather than a translated " or " glue string: Arabic joins these differently and
  // a bare separator gives the translator no sentence to work with.
  if (months && distance) return t('{n} months or {distance}', { n: months, distance: km(distance) });
  if (months) return t('{n} months', { n: months });
  if (distance) return km(distance);
  return null;
}

/**
 * WHERE that limit came from. 'recorded' is the limit frozen onto this part when it was fitted —
 * the honest answer to "what was it back then". 'catalog' means this row predates the snapshot and
 * we are showing the part type's CURRENT expectation standing in for it, which is a weaker claim
 * and is labelled rather than quietly presented as history.
 */
const LIMIT_SOURCE_NOTE = {
  recorded: 'Set when this part was fitted',
  catalog: "Today's catalogue figure — this part predates limit recording",
};

/**
 * "2 y 3 mo" / "8 mo" / "12 d" — an age a human reads at a glance instead of counting days.
 * The translator is a parameter because this lives outside a component; every caller passes its
 * own `t` from useI18n().
 */
function humanAge(days, t) {
  if (days === null || days === undefined) return '—';
  if (days < 45) return t('{n} d', { n: days });
  const months = Math.round(days / 30.44);
  if (months < 24) return t('{n} mo', { n: months });
  return t('{y} y {m} mo', { y: Math.floor(months / 12), m: months % 12 });
}

/**
 * Keep the rows whose `field` date falls inside the window {days, from, to} — the same shape
 * DateRangePicker emits. `days: 0` with no from/to means all time and returns the list untouched.
 *
 * A row with NO date on that field is DROPPED once a window is set, deliberately: an undated row
 * cannot be shown to belong to a period, and silently keeping it would let "replaced last month"
 * include a replacement nobody dated.
 */
function filterByRange(rows, field, { days, from, to }) {
  if (!days && !from && !to) return rows;

  const startOfDay = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate());
  const parse = (s) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };

  let start = null;
  let end = null;

  if (from || to) {
    start = from ? parse(from) : null;
    // Inclusive end: the whole of the chosen day counts.
    end = to ? new Date(parse(to).getTime() + 86400000 - 1) : null;
  } else {
    const today = startOfDay(new Date());
    start = new Date(today.getTime() - (days - 1) * 86400000);
  }

  return rows.filter((r) => {
    const raw = r[field];
    if (!raw) return false;
    const ts = new Date(raw).getTime();
    if (Number.isNaN(ts)) return false;
    if (start && ts < start.getTime()) return false;
    if (end && ts > end.getTime()) return false;
    return true;
  });
}

export default function VehicleComponentsPanel({ vehicleId }) {
  const { t } = useI18n();
  const [selectedId, setSelectedId] = useState(null);
  const [view, setView] = useState('installed');
  const [q, setQ] = useState('');
  // Time window over the TABLE only. `days: 0` = all time; from/to override it with an explicit
  // range. The whole history is already in the payload, so this filters in place — no refetch.
  const [range, setRange] = useState({ days: 0, from: null, to: null });

  const fetcher = useCallback(
    async () => (await api.get(`/Vehicle/${vehicleId}/components`)).data.data,
    [vehicleId]
  );
  const { data, loading, error, reload } = useFetch(fetcher, [vehicleId], {
    // Pause polling while the dossier drawer is open so the row under the user cannot shift.
    paused: () => selectedId !== null,
    refreshInterval: 60000,
  });

  const summary = data?.summary || {};
  const installed = useMemo(() => data?.installed || [], [data]);
  const consumables = useMemo(() => data?.consumables || [], [data]);
  const history = useMemo(() => data?.history || [], [data]);

  // The "installed" view merges the asset rows with the consumables refreshed by routine service,
  // because to the person looking at the car they are all just "what is on it now".
  const currentRows = useMemo(() => [...installed, ...consumables], [installed, consumables]);

  const rows = view === 'installed' ? currentRows : history;

  // The window means a different thing in each view, and it has to: on "Installed" the question is
  // "what was FITTED in this period", on "Replaced" it is "what came OFF in this period". Filtering
  // both on the same column would make the Replaced tab answer a question nobody asked.
  const dateField = view === 'installed' ? 'installed_at' : 'removed_at';

  const windowed = useMemo(() => filterByRange(rows, dateField, range), [rows, dateField, range]);

  const filtered = useMemo(() => {
    const needle = q.trim().toLowerCase();
    if (!needle) return windowed;
    return windowed.filter((r) =>
      [r.type, r.part_name, r.brand, r.part_number, r.serial_no, r.supplier?.name, CATEGORY_LABEL[r.category] ? t(CATEGORY_LABEL[r.category]) : r.category]
        .filter(Boolean)
        .some((v) => String(v).toLowerCase().includes(needle))
    );
  }, [windowed, q, t]);

  const windowActive = range.days > 0 || !!range.from || !!range.to;

  // Both tab counts under the SAME window, so switching views never surprises with a jump.
  const installedInWindow = useMemo(
    () => filterByRange(currentRows, 'installed_at', range).length,
    [currentRows, range]
  );
  const historyInWindow = useMemo(
    () => filterByRange(history, 'removed_at', range).length,
    [history, range]
  );

  const columns = useMemo(() => {
    const base = [
      {
        key: 'category',
        header: t('Category'),
        render: (r) => (
          <span className="text-xs font-medium text-slate-500">
            {CATEGORY_LABEL[r.category] ? t(CATEGORY_LABEL[r.category]) : r.category || '—'}
          </span>
        ),
      },
      {
        key: 'part',
        header: t('Part'),
        render: (r) => (
          <div className="min-w-0">
            <div className="flex items-center gap-1.5">
              <span className="font-semibold text-slate-900">{r.type || r.part_name}</span>
              {r.position && (
                <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500">
                  {POSITION_LABEL[r.position] ? t(POSITION_LABEL[r.position]) : r.position}
                </span>
              )}
              {r.kind === 'consumable' && (
                <span className="rounded bg-sky-50 px-1.5 py-0.5 text-[10px] font-semibold text-sky-600">{t('Service')}</span>
              )}
            </div>
            <div className="truncate text-xs text-slate-400">{r.part_name}</div>
            {/* WHAT it is — "12V · 60 Ah", "225/65R17". Rendered by the backend through
                PartSpecs so the wording matches every other surface, and absent entirely on a
                part nobody specced rather than showing an empty line. */}
            <PartSpecLine summary={r.spec_summary} className="block truncate" />
          </div>
        ),
      },
      { key: 'brand', header: t('Brand'), render: (r) => r.brand || '—' },
      {
        key: 'part_number',
        header: t('Part Number'),
        cellClass: 'font-mono text-xs',
        render: (r) => r.part_number || '—',
      },
      {
        key: 'installed_at',
        header: t('Installed'),
        render: (r) => (
          <div>
            <div>{fmtDate(r.installed_at)}</div>
            <div className="text-xs text-slate-400">{t('{age} ago', { age: humanAge(r.age_days, t) })}</div>
          </div>
        ),
      },
      { key: 'installed_odometer', header: t('Mileage'), align: 'right', cellClass: 'tabular-nums', render: (r) => km(r.installed_odometer) },
    ];

    if (view === 'installed') {
      return [
        ...base,
        {
          key: 'warranty',
          header: t('Warranty'),
          render: (r) => (
            <div>
              <Badge tone={WARRANTY_TONE[r.warranty?.status] || 'gray'}>
                {r.warranty?.months
                  ? t('{n} mo', { n: r.warranty.months })
                  : WARRANTY_LABEL[r.warranty?.status] ? t(WARRANTY_LABEL[r.warranty?.status]) : '—'}
              </Badge>
              {r.warranty?.until && (
                <div className="mt-0.5 text-xs text-slate-400">
                  {r.warranty.status === 'expired'
                    ? t('ended {date}', { date: fmtDate(r.warranty.until) })
                    : t('ends {date}', { date: fmtDate(r.warranty.until) })}
                </div>
              )}
            </div>
          ),
        },
        {
          key: 'life',
          header: t('Service life'),
          render: (r) => <LifeBar life={r.service_life} />,
        },
        {
          key: 'supplier',
          header: t('Supplier'),
          // A part taken off the fleet's own shelf has no supplier to name — it was bought into the
          // storehouse at some earlier date, from whoever sold it then. Saying "Storehouse" is the
          // honest answer to "where did this come from?"; leaving the cell blank would read as a
          // missing record, which is the one thing it is not.
          render: (r) => (r.from_store
            ? <Badge tone="blue">{t('Storehouse')}</Badge>
            : r.supplier?.name || '—'),
        },
        {
          key: 'cost',
          header: t('Cost'),
          align: 'right',
          cellClass: 'tabular-nums font-semibold text-slate-800',
          // A blank cost means two different things and the user must be able to tell them
          // apart: a purchased part missing its price is a data-entry gap worth chasing; a
          // technician-reported part never had one, and chasing it is wasted effort. The
          // "Reported" chip is the difference, and it is why evidence_channel is on the wire.
          render: (r) => {
            if (r.purchase_cost !== null && r.purchase_cost !== undefined) return aed2(r.purchase_cost);
            return r.evidence_channel === 'repair_capture' ? (
              <span title={t('Recorded by the technician at repair capture — there is no purchase order, so no cost or supplier exists for this part.')}>
                <Badge tone="gray">{t('Reported')}</Badge>
              </span>
            ) : (
              '—'
            );
          },
        },
      ];
    }

    return [
      ...base,
      {
        key: 'removed_at',
        header: t('Removed'),
        render: (r) => (
          <div>
            <div>{fmtDate(r.removed_at)}</div>
            <div className="text-xs text-slate-400">{km(r.removed_odometer)}</div>
          </div>
        ),
      },
      {
        key: 'reason',
        header: t('Reason'),
        render: (r) => (
          <Badge tone={r.removal_reason === 'failed' ? 'red' : 'gray'}>
            {REASON_LABEL[r.removal_reason] ? t(REASON_LABEL[r.removal_reason]) : r.removal_reason || '—'}
          </Badge>
        ),
      },
      {
        key: 'lived',
        header: t('Lasted'),
        render: (r) => (
          <div>
            <div>{humanAge(r.age_days, t)}</div>
            <div className="text-xs text-slate-400">{km(r.distance_km)}</div>
          </div>
        ),
      },
      // What it was SUPPOSED to last, as the limit stood when this part was fitted — sat directly
      // beside what it actually managed, because the only useful reading of "lasted 9 months" is
      // against the 12 it was bought for. `limit_source` is what stops an old row (no snapshot,
      // catalogue value standing in) from being read as a recorded historical fact.
      {
        key: 'limit',
        header: t('Limit at fitting'),
        render: (r) => {
          const limit = limitText(r.service_life, t);
          if (!limit) return <span className="text-xs text-slate-400">{t('No expectation set')}</span>;

          const note = LIMIT_SOURCE_NOTE[r.service_life?.limit_source];
          const pct = r.service_life?.life_used_pct;

          return (
            <div title={note ? t(note) : undefined}>
              <div className={r.service_life?.limit_source === 'catalog' ? 'text-slate-400 italic' : undefined}>
                {limit}
              </div>
              {pct !== null && pct !== undefined && (
                <div className="mt-0.5 text-xs text-slate-400">
                  {pct >= 100
                    ? t('reached {pct}% of it', { pct })
                    : t('used {pct}% of it', { pct })}
                </div>
              )}
            </div>
          );
        },
      },
      {
        key: 'cost',
        header: t('Cost'),
        align: 'right',
        cellClass: 'tabular-nums font-semibold text-slate-800',
        render: (r) => (r.purchase_cost === null || r.purchase_cost === undefined ? '—' : aed2(r.purchase_cost)),
      },
    ];
  }, [view, t]);

  if (error) return <ErrorState message={t("Could not load this vehicle's components.")} onRetry={reload} />;

  return (
    <div className="space-y-6">
      {/* Fed from the payload already in hand — no second request, and the same numbers the
          overview tab's banner shows. */}
      <ComponentRepeatAlert data={data?.repeat_replacements ?? null} />

      {loading && !data ? (
        <MetricGridSkeleton count={5} />
      ) : (
        <MetricGrid cols={5}>
          <MetricCard
            label={t('Installed components')}
            value={num(summary.installed_count ?? 0)}
            icon={<Icon.Wrench className="h-5 w-5" />}
            hint={t('Parts currently fitted to this vehicle')}
          />
          {/* The value is a SUM OVER WHAT IS KNOWN, not a total, and the hint has to say so.
              A part fitted through repair capture has no purchase behind it and therefore no
              cost — counting it as zero would understate the car silently, and printing the
              remainder as "installed value" would claim a completeness we do not have. */}
          <MetricCard
            label={t('Installed value')}
            value={aed2(summary.total_installed_value ?? 0)}
            icon={<Icon.Cash className="h-5 w-5" />}
            hint={
              summary.uncosted_count
                ? t('Based on {costed} of {total} fitted parts — {uncosted} have no recorded cost. Lifetime spend {spend}', {
                    costed: num(summary.costed_count ?? 0),
                    total: num(summary.installed_count ?? 0),
                    uncosted: num(summary.uncosted_count),
                    spend: aed2(summary.lifetime_component_spend ?? 0),
                  })
                : t('Lifetime component spend {spend}', { spend: aed2(summary.lifetime_component_spend ?? 0) })
            }
          />
          <MetricCard
            label={t('Average age')}
            value={humanAge(summary.average_age_days, t)}
            icon={<Icon.Clock className="h-5 w-5" />}
            hint={t('Mean age of everything currently fitted')}
          />
          <MetricCard
            label={t('Warranty expiring')}
            value={num(summary.warranty_expiring ?? 0)}
            tone={summary.warranty_expiring > 0 ? 'amber' : undefined}
            icon={<Icon.Shield className="h-5 w-5" />}
            hint={t('{n} still comfortably under warranty', { n: num(summary.under_warranty ?? 0) })}
          />
          <MetricCard
            label={t('Past expected life')}
            value={num(summary.past_expected_life ?? 0)}
            tone={summary.past_expected_life > 0 ? 'red' : undefined}
            icon={<Icon.Alert className="h-5 w-5" />}
            hint={t('{n} more due soon', { n: num(summary.due_soon ?? 0) })}
          />
        </MetricGrid>
      )}

      <SectionCard
        title={t('Current configuration')}
        subtitle={
          // Two whole sentences rather than one with a swapped word: Arabic will not accept a
          // FITTED/REMOVED verb dropped into a fixed frame.
          windowActive
            ? view === 'installed'
              ? t('Showing parts FITTED in the selected window — {shown} of {total}. The cards above always describe the car as it stands today.', {
                  shown: num(windowed.length),
                  total: num(rows.length),
                })
              : t('Showing parts REMOVED in the selected window — {shown} of {total}. The cards above always describe the car as it stands today.', {
                  shown: num(windowed.length),
                  total: num(rows.length),
                })
            : t('Derived from the maintenance workflow — components appear here only when a ticket installs them.')
        }
        actions={
          // The search box carries no intrinsic width, so as a flex sibling of the Segmented it
          // shrank until the icon's padding swallowed the placeholder. Pin a width and let the row
          // wrap instead of crushing both controls in a narrow card header.
          <div className="flex flex-wrap items-center justify-end gap-2">
            <SearchInput className="w-full sm:w-64" value={q} onChange={setQ} placeholder={t('Part, brand, number, supplier…')} />
            <DateRangePicker
              days={range.days}
              from={range.from}
              to={range.to}
              onChange={(next) => setRange({ days: next.days ?? 0, from: next.from || null, to: next.to || null })}
            />
            <Segmented
              value={view}
              onChange={setView}
              options={[
                // Counts follow the window, so a tab never advertises rows the window has hidden.
                { key: 'installed', label: t('Installed ({n})', { n: installedInWindow }) },
                { key: 'history', label: t('Replaced ({n})', { n: historyInWindow }) },
              ]}
            />
          </div>
        }
      >
        {!loading && rows.length === 0 ? (
          <EmptyState
            title={view === 'installed' ? t('No components recorded yet') : t('No replacements recorded yet')}
            message={
              view === 'installed'
                ? t('This vehicle has no installed components on record. Components are created automatically when a maintenance ticket reaches its install step — there is no manual entry.')
                : t('Nothing has been replaced on this vehicle yet. The first time a part is swapped, the old one is retired here with its reason and the part that replaced it.')
            }
          />
        ) : (
          <DataTable
            columns={columns}
            rows={filtered}
            rowKey={(r) => (r.kind === 'consumable' ? `svc-${r.service_record_id}` : r.id)}
            loading={loading && !data}
            // A consumable has no asset record behind it, so there is nothing to drill into.
            onRowClick={(r) => r.kind !== 'consumable' && setSelectedId(r.id)}
            highlightRow={(r) => r.service_life?.status === 'overdue' || r.warranty?.status === 'expiring_soon'}
            empty={
              windowActive && windowed.length === 0
                ? view === 'installed'
                  ? t('Nothing was fitted in this window. Widen the date range to see more.')
                  : t('Nothing was removed in this window. Widen the date range to see more.')
                : t('Nothing matches that search.')
            }
            stickyHeader
          />
        )}
      </SectionCard>

      <ComponentDossierDrawer componentId={selectedId} onClose={() => setSelectedId(null)} />
    </div>
  );
}

/**
 * A compact proportion bar for "how much of its expected life this part has used", with the LIMIT
 * that produced the percentage printed underneath.
 *
 * The limit is shown even when the bar cannot be drawn. A percentage with no stated limit is an
 * unreadable number — "78% used" only means something once you can see it is 78% of 20,000 km.
 */
function LifeBar({ life }) {
  const { t } = useI18n();
  const limit = limitText(life, t);
  const note = LIMIT_SOURCE_NOTE[life?.limit_source];

  const caption = limit && (
    <div
      className={`mt-1 text-xs ${life?.limit_source === 'catalog' ? 'text-slate-400 italic' : 'text-slate-500'}`}
      title={note ? t(note) : undefined}
    >
      {t('Limit: {limit}', { limit })}
    </div>
  );

  if (!life || life.status === 'unknown') {
    return (
      <div className="min-w-[110px]">
        <span className="text-xs text-slate-400">{t('No expectation set')}</span>
        {caption}
      </div>
    );
  }

  const pct = Math.min(100, life.life_used_pct ?? 0);
  const bar = life.status === 'overdue' ? 'bg-rose-500' : life.status === 'due_soon' ? 'bg-amber-500' : 'bg-emerald-500';

  return (
    <div className="min-w-[110px]">
      <div className="h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
        <div className={`h-full rounded-full ${bar}`} style={{ width: `${pct}%` }} />
      </div>
      <div className="mt-1 text-xs text-slate-400">
        {life.basis === 'distance'
          ? t('{pct}% used · by distance', { pct: life.life_used_pct })
          : t('{pct}% used · by age', { pct: life.life_used_pct })}
      </div>
      {caption}
    </div>
  );
}

/**
 * The drill-down behind a row: provenance (ticket → purchase order → invoice → supplier), the
 * append-only biography, warranty, and both neighbours in the replacement chain.
 */
function ComponentDossierDrawer({ componentId, onClose }) {
  const { t } = useI18n();
  const fetcher = useCallback(
    async () => (componentId ? (await api.get(`/components/${componentId}`)).data.data : null),
    [componentId]
  );
  const { data, loading, error } = useFetch(fetcher, [componentId], { paused: () => !componentId });

  const c = data?.component;
  const links = data?.links || {};

  return (
    <Drawer
      open={!!componentId}
      onClose={onClose}
      width="lg"
      eyebrow={c ? (CATEGORY_LABEL[c.category] ? t(CATEGORY_LABEL[c.category]) : c.category) : t('Component')}
      title={c ? c.part_name || c.type : t('Component')}
      subtitle={
        c
          ? [c.brand, c.part_number, c.position && (POSITION_LABEL[c.position] ? t(POSITION_LABEL[c.position]) : c.position)]
              .filter(Boolean)
              .join(' · ')
          : ''
      }
    >
      {error && <ErrorState message={t('Could not load this component.')} />}
      {loading && !data && <div className="p-5 text-sm text-slate-400">{t('Loading…')}</div>}

      {c && (
        <div className="space-y-5 p-5">
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={STATUS_TONE[c.status] || 'gray'}>
              {c.status === 'active' ? t('Installed') : c.status === 'retired' ? t('Removed') : t('In stock')}
            </Badge>
            <Badge tone={WARRANTY_TONE[c.warranty?.status] || 'gray'}>
              {WARRANTY_LABEL[c.warranty?.status] ? t(WARRANTY_LABEL[c.warranty?.status]) : ''}
            </Badge>
            {c.service_life?.status !== 'unknown' && (
              <Badge tone={LIFE_TONE[c.service_life?.status] || 'gray'}>
                {LIFE_LABEL[c.service_life?.status] ? t(LIFE_LABEL[c.service_life?.status]) : ''}
              </Badge>
            )}
          </div>

          <DossierSection title={t('Fitment')}>
            <Row label={t('Type')} value={c.type} />
            <Row label={t('Brand / model')} value={[c.brand, c.model].filter(Boolean).join(' ') || '—'} />
            <Row label={t('Part number')} value={c.part_number} mono />
            <Row label={t('Serial number')} value={c.serial_no} mono />
            {/* The full specification, INCLUDING the fields nobody answered — "Terminal side: not
                recorded" is the line that gets someone to go and look at the battery, and it is
                the only reason the gaps are shown rather than hidden. */}
            {c.spec_detail?.length > 0 && (
              <div className="col-span-full pt-2">
                <PartSpecDetail detail={c.spec_detail} emptyLabel={t('Not recorded')} />
              </div>
            )}
            <Row label={t('Position')} value={c.position ? (POSITION_LABEL[c.position] ? t(POSITION_LABEL[c.position]) : c.position) : '—'} />
            <Row label={t('Installed')} value={`${fmtDate(c.installed_at)} · ${km(c.installed_odometer)}`} />
            <Row label={t('Age')} value={t('{age} · {distance} driven', { age: humanAge(c.age_days, t), distance: km(c.distance_km) })} />
            {/* The limit as it stood when this part was fitted, sat directly under the age and
                distance it has actually run — the two numbers only mean anything together. */}
            <Row
              label={t('Service limit')}
              value={
                limitText(c.service_life, t)
                  ? c.service_life.limit_source === 'catalog'
                    ? t('{limit} (current catalogue figure — not recorded at fitting)', { limit: limitText(c.service_life, t) })
                    : t('{limit} — set when this part was fitted', { limit: limitText(c.service_life, t) })
                  : t('No expectation set')
              }
            />
            <Row label={t('Fitted by')} value={c.installed_by_name || c.technician_name || '—'} />
            <Row label={t('Workshop')} value={c.installer?.name} />
          </DossierSection>

          <DossierSection title={t('Commercial')}>
            <Row
              label={t('Supplier')}
              value={c.from_store ? t('Taken from the storehouse') : c.supplier?.name}
            />
            <Row label={t('Purchase cost')} value={c.purchase_cost === null ? '—' : aed2(c.purchase_cost)} />
            <Row label={t('Cost per km')} value={c.cost_per_km ? `${aed2(c.cost_per_km)} / km` : '—'} />
            <Row label={t('Warranty')} value={c.warranty?.months ? t('{n} months', { n: c.warranty.months }) : t('None')} />
            <Row
              label={t('Warranty ends')}
              value={
                c.warranty?.until
                  ? c.warranty.days_remaining >= 0
                    ? t('{date} · {n} days left', { date: fmtDate(c.warranty.until), n: c.warranty.days_remaining })
                    : t('{date} · expired', { date: fmtDate(c.warranty.until) })
                  : '—'
              }
            />
          </DossierSection>

          {c.removed_at && (
            <DossierSection title={t('Removal')}>
              <Row label={t('Removed')} value={`${fmtDate(c.removed_at)} · ${km(c.removed_odometer)}`} />
              <Row label={t('Reason')} value={REASON_LABEL[c.removal_reason] ? t(REASON_LABEL[c.removal_reason]) : c.removal_reason} />
              <Row label={t('Where it went')} value={(c.disposition || '').replace(/_/g, ' ') || '—'} />
              <Row label={t('Removed by')} value={c.removed_by_name} />
              <Row label={t('Note')} value={c.removal_note} />
            </DossierSection>
          )}

          <DossierSection title={t('Where this record came from')}>
            <LinkRow label={t('Maintenance ticket')} to={links.maintenance_id && `/maintenance-workflow/${links.maintenance_id}`} value={links.maintenance_id && `#${links.maintenance_id}`} />
            <Row label={t('Purchase order')} value={links.purchase_order_no} mono />
            <LinkRow label={t('Part purchase')} to={links.part_purchase_id && `/parts?purchase=${links.part_purchase_id}`} value={links.part_purchase_id && `#${links.part_purchase_id}`} />
            {/* Where a stock-issued part actually came from: the shelf it left. The purchase above
                has no supplier and no invoice behind it, and this is what explains why. */}
            <LinkRow
              label={t('Storehouse shelf')}
              to={links.store_item_id && `/parts?tab=store`}
              value={links.store_item_id && t('Issued from stock')}
            />
            <LinkRow label={t('Invoice')} to={links.maintenance_invoice_id && `/maintenance-workflow/${links.maintenance_id}?tab=invoices`} value={links.maintenance_invoice_id && `#${links.maintenance_invoice_id}`} />
            <LinkRow label={t('Supplier')} to={links.supplier_vendor_id && `/vendors/${links.supplier_vendor_id}`} value={c.supplier?.name} />
            <LinkRow label={t('Removal ticket')} to={links.removal_maintenance_id && `/maintenance-workflow/${links.removal_maintenance_id}`} value={links.removal_maintenance_id && `#${links.removal_maintenance_id}`} />
            <Row label={t('Record source')} value={c.source === 'workflow' ? t('Maintenance workflow') : c.source} />
          </DossierSection>

          {(data.replaces || data.replaced_by) && (
            <DossierSection title={t('Replacement chain')}>
              {data.replaces && <ChainRow role={t('This replaced')} node={data.replaces} onOpen={onClose} />}
              {data.replaced_by && <ChainRow role={t('Replaced by')} node={data.replaced_by} onOpen={onClose} />}
            </DossierSection>
          )}

          <DossierSection title={t('History')}>
            {(data.events || []).length === 0 ? (
              <p className="py-2 text-sm text-slate-400">{t('No events recorded.')}</p>
            ) : (
              <ol className="space-y-3">
                {data.events.map((e) => (
                  <li key={e.id} className="flex gap-3">
                    <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-indigo-400" />
                    <div className="min-w-0">
                      <div className="text-sm font-medium text-slate-800">{EVENT_LABEL[e.event] ? t(EVENT_LABEL[e.event]) : e.event}</div>
                      <div className="text-xs text-slate-400">
                        {fmtDate(e.at)}
                        {e.odometer ? ` · ${km(e.odometer)}` : ''}
                        {e.actor_name ? ` · ${e.actor_name}` : ''}
                      </div>
                      {e.note && <div className="mt-0.5 text-xs text-slate-500">{e.note}</div>}
                    </div>
                  </li>
                ))}
              </ol>
            )}
          </DossierSection>

          {(data.inspections || []).length > 0 && (
            <DossierSection title={t('Inspections & services')}>
              {data.inspections.map((r) => (
                <Row key={r.id} label={(r.service_type || '').replace(/_/g, ' ')} value={`${fmtDate(r.performed_at)} · ${r.workshop || '—'}`} />
              ))}
            </DossierSection>
          )}

          {(data.media || []).length > 0 && (
            <DossierSection title={t('Photos')}>
              <p className="py-1 text-sm text-slate-500">{t('{n} file(s) attached to this component.', { n: data.media.length })}</p>
            </DossierSection>
          )}
        </div>
      )}
    </Drawer>
  );
}

function DossierSection({ title, children }) {
  return (
    <section className="rounded-xl border border-slate-200/70 bg-white p-4">
      <h3 className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">{title}</h3>
      <div className="divide-y divide-slate-100">{children}</div>
    </section>
  );
}

function Row({ label, value, mono }) {
  return (
    <div className="flex justify-between gap-4 py-1.5 text-sm">
      <span className="shrink-0 text-slate-500">{label}</span>
      <span className={`text-end font-medium text-slate-900 ${mono ? 'font-mono text-xs' : ''}`}>{value || '—'}</span>
    </div>
  );
}

function LinkRow({ label, to, value }) {
  if (!to || !value) return <Row label={label} value={null} />;
  return (
    <div className="flex justify-between gap-4 py-1.5 text-sm">
      <span className="shrink-0 text-slate-500">{label}</span>
      <Link to={to} className="text-end font-medium text-indigo-600 hover:underline">
        {value}
      </Link>
    </div>
  );
}

function ChainRow({ role, node, onOpen }) {
  const { t } = useI18n();
  return (
    <div className="flex items-center justify-between gap-4 py-2 text-sm">
      <div className="min-w-0">
        <div className="text-xs text-slate-400">{role}</div>
        <div className="truncate font-medium text-slate-900">{node.part_name || node.type}</div>
        <div className="text-xs text-slate-400">
          {node.removed_at
            ? t('Installed {installed} · removed {removed}', { installed: fmtDate(node.installed_at), removed: fmtDate(node.removed_at) })
            : t('Installed {installed} · still fitted', { installed: fmtDate(node.installed_at) })}
        </div>
      </div>
      <Badge tone={STATUS_TONE[node.status] || 'gray'}>{node.status === 'active' ? t('Installed') : t('Removed')}</Badge>
    </div>
  );
}
