import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge from '../components/ui/Badge';
import { Card } from '../components/ui/Misc';
import { SectionCard } from '../components/ui/Table';
import { MetricGridSkeleton, Skeleton } from '../components/ui/Skeleton';
import { InfoTip } from '../components/ui/Tooltip';
import Icon from '../components/ui/Icon';
import FleetStatusCard from '../components/ui/FleetStatusCard';
import BarChart from '../components/ui/BarChart';
import LineChart from '../components/ui/LineChart';
import FleetPulseGrid from '../components/FleetPulseGrid';
import { usePageStat } from '../components/PageStat';
import { aed, aed2, fmtDate } from '../lib/format';
import { SHOW_FINANCIALS } from '../config/features';
import { useAuth } from '../auth/AuthContext';

// Time-of-day greeting for the dashboard header ("Good morning, Rami!").
function greeting() {
  const h = new Date().getHours();
  if (h < 12) return 'Good morning';
  if (h < 18) return 'Good afternoon';
  return 'Good evening';
}

// Compact currency for chart axes (AED 4,180 → "4.2k") so y-labels never overflow.
const aedK = (n) => {
  const v = Number(n) || 0;
  if (Math.abs(v) >= 1000) return `${(v / 1000).toFixed(Math.abs(v) % 1000 ? 1 : 0)}k`;
  return Math.round(v).toString();
};

const TILE_TONE_SOFT = {
  amber: 'bg-amber-100 text-amber-600',
  red:   'bg-red-100 text-red-600',
  blue:  'bg-blue-100 text-blue-600',
};

// Proactive Flags — the live "act on this now" panel. The lead column is every car in the workshop
// right now (with its repair-ETA KPI); an optional Payments-Overdue column (returned rentals with an
// unpaid balance) is gated by SHOW_FINANCIALS. Reads /Dashboard/proactive-flags, the SAME source the
// notification bell raises its alerts from — so a card here and its bell alert can never disagree.
// Every row deep-links to its source record (traceability).

// Threshold palette for the repair-progress bar. Green under 75% of target, orange 75–100%, red
// once the target is exceeded — matched track / fill / badge / percent tints so a card reads as one.
const PROGRESS_TONE = {
  green:  { bar: 'bg-emerald-500', track: 'bg-emerald-100', badge: 'bg-emerald-50 text-emerald-700 ring-emerald-200', pct: 'text-emerald-600', dot: 'bg-emerald-500' },
  orange: { bar: 'bg-amber-500',   track: 'bg-amber-100',   badge: 'bg-amber-50 text-amber-700 ring-amber-200',       pct: 'text-amber-600',   dot: 'bg-amber-500' },
  red:    { bar: 'bg-red-500',     track: 'bg-red-100',     badge: 'bg-red-50 text-red-700 ring-red-200',             pct: 'text-red-600',     dot: 'bg-red-500' },
};

// One labelled figure in a card's KPI grid.
function KpiCell({ label, value, tone = 'text-slate-800' }) {
  return (
    <div className="min-w-0">
      <dt className="truncate text-[10px] font-medium uppercase tracking-wide text-slate-400">{label}</dt>
      <dd className={`truncate text-xs font-semibold tabular-nums ${tone}`}>{value}</dd>
    </div>
  );
}

// pluralised "N day(s)".
const days = (n) => `${n} day${Math.abs(n) === 1 ? '' : 's'}`;

// Repair-Progress KPI card for one car in the workshop. A visual horizontal progress bar (elapsed
// days-in-shop / planned target days) with threshold colours — green < 75%, orange 75–100%, red once
// the target is exceeded (bar stays pinned at 100% when overdue) — a status badge ("N days remaining"
// / "Due today" / "+N days overdue"), and a compact grid of the underlying figures. All data comes
// from the open type-U maintenance contract's eta (out_date = start, expected_return_date = target;
// a null target falls back to the default window and the card is flagged "Estimated"). Deep-links to
// the vehicle. The whole card is the KPI the user asked for — no plain text ETA.
function RepairProgressCard({ item }) {
  const { id, plate, car, garage, eta } = item || {};
  const e = eta || {};
  const to = id ? `/vehicles/${id}` : '/maintenance-workflow';

  const el = e.days_elapsed ?? 0;     // total days in the workshop
  const al = e.days_allotted ?? 0;    // planned repair duration (target)
  const over = e.days_over ?? 0;
  const left = e.days_left ?? 0;
  const est = !!e.is_estimated;
  const status = e.status || 'on_track';

  // Fill ratio = elapsed / target; bar caps at 100% (keeps filling to full when overdue).
  const ratio = al > 0 ? el / al : (status === 'overdue' ? 1.2 : 1);
  const pct = Math.min(100, Math.round(ratio * 100));
  const colorKey = ratio > 1 ? 'red' : ratio >= 0.75 ? 'orange' : 'green';
  const c = PROGRESS_TONE[colorKey];

  const badge = status === 'overdue' ? `+${days(over)} overdue`
    : status === 'due_today' ? 'Due today'
      : `${days(left)} remaining`;
  const remainTone = status === 'overdue' ? 'text-red-600' : status === 'due_today' ? 'text-amber-600' : 'text-emerald-600';

  return (
    <Link
      to={to}
      className="block rounded-2xl border border-slate-200/70 bg-white p-3.5 shadow-soft transition hover:border-slate-300 hover:shadow-md"
    >
      {/* Vehicle header */}
      <div className="mb-3 flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="truncate text-sm font-bold text-slate-900">{plate || car || 'Vehicle'}</p>
          <p className="truncate text-xs text-slate-400">{[car, garage].filter(Boolean).join(' · ') || '—'}</p>
        </div>
        {est && (
          <span
            className="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-500"
            title="No ready-by date on the maintenance contract — measured against the default repair window"
          >
            Estimated
          </span>
        )}
      </div>

      {/* Progress bar */}
      <div className="mb-1 flex items-center justify-between">
        <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Repair Progress</span>
        <span className={`text-xs font-bold tabular-nums ${c.pct}`}>{pct}%</span>
      </div>
      <div className="flex items-baseline justify-between text-xs font-semibold text-slate-700">
        <span>Day {el}</span>
        <span className="text-slate-400">Target {days(al)}</span>
      </div>
      <div className={`mt-1.5 h-2.5 w-full overflow-hidden rounded-full ${c.track}`}>
        <div className={`h-full rounded-full ${c.bar} transition-all`} style={{ width: `${Math.max(3, pct)}%` }} />
      </div>
      <div className="mt-2">
        <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ${c.badge}`}>
          <span className={`h-1.5 w-1.5 rounded-full ${c.dot}`} />
          {badge}
        </span>
      </div>

      {/* Underlying figures */}
      <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 border-t border-slate-100 pt-3">
        <KpiCell label="In workshop" value={days(el)} />
        <KpiCell label="Planned" value={`${days(al)}${est ? ' · est.' : ''}`} />
        <KpiCell
          label={status === 'overdue' ? 'Overdue' : 'Remaining'}
          value={status === 'overdue' ? days(over) : status === 'due_today' ? 'Due today' : days(left)}
          tone={remainTone}
        />
        <KpiCell label="Expected" value={fmtDate(e.expected_on) || '—'} />
        <KpiCell label="Started" value={fmtDate(e.started_on) || '—'} />
      </dl>
    </Link>
  );
}

function ProactiveFlags({ data, loading }) {
  const inShop = data?.in_maintenance || { count: 0, items: [] };
  const invoices = data?.invoice_overdue || { count: 0, items: [] };

  const groups = [
    {
      // The primary column — every car in the shop right now rendered as a visual Repair-Progress KPI
      // card (progress bar + figures), not a text row. `wide` spans the extra width; `cardItems`
      // switches the renderer from the row list to the card grid.
      key: 'maintenance', title: 'In Maintenance', icon: <Icon.Wrench className="h-4 w-4" />, tone: 'blue', wide: true,
      count: inShop.count, viewAll: '/maintenance-workflow', empty: 'No cars in the workshop right now',
      cardItems: inShop.items || [],
    },
    ...(SHOW_FINANCIALS ? [{
      key: 'invoices', title: 'Payments Overdue', icon: <Icon.Coins className="h-4 w-4" />, tone: 'red',
      count: invoices.count, note: invoices.total ? aed(invoices.total) : null,
      viewAll: '/contracts', empty: 'No unpaid balances on returned rentals',
      rows: (invoices.items || []).map((r) => ({
        to: `/contracts/${r.id}`,
        primary: r.customer || `#${r.contract_no || r.id}`,
        secondary: [r.plate, `returned ${fmtDate(r.returned_on)}`].filter(Boolean).join(' · '),
        right: aed2(r.balance),
        rightTone: 'text-red-600',
      })),
    }] : []),
  ];

  const totalCount = groups.reduce((s, g) => s + (g.count || 0), 0);

  return (
    <SectionCard
      title={
        <span className="flex items-center gap-1.5">
          Proactive Flags
          <InfoTip content="Conditions to act on. Sources — In Maintenance: every car with an open maintenance contract (in the workshop right now), shown as a Repair-Progress card — a bar filling elapsed days-in-shop against the planned target (green under 75%, orange 75–100%, red once exceeded), a badge (N days remaining / Due today / +N days overdue), and the figures behind it (in-shop total, planned duration, remaining/overdue, expected completion, repair start). Repair start = the contract's out-date; target = its ready-by date, or a default window (card flagged 'Estimated') when none is set. Payments Overdue: returned rentals with an outstanding contract balance. Click a card to open its vehicle." />
        </span>
      }
      subtitle="What needs attention now — every car in the shop and how it's tracking against its repair ETA"
      actions={<Badge tone={totalCount ? 'amber' : 'gray'}>{totalCount}</Badge>}
    >
      {loading ? (
        <div className={`grid grid-cols-1 gap-4 ${SHOW_FINANCIALS ? 'lg:grid-cols-3' : ''}`}>
          {Array.from({ length: SHOW_FINANCIALS ? 2 : 1 }).map((_, i) => <Skeleton key={i} className="h-40 rounded-2xl" />)}
        </div>
      ) : (
        <div className={`grid grid-cols-1 gap-4 ${SHOW_FINANCIALS ? 'lg:grid-cols-3' : ''}`}>
          {groups.map((g) => (
            <div
              key={g.key}
              className={`rounded-2xl border border-slate-200/60 bg-white p-4 shadow-soft ${g.wide && SHOW_FINANCIALS ? 'lg:col-span-2' : ''}`}
            >
              <div className="mb-3 flex items-center justify-between gap-2">
                <div className="flex min-w-0 items-center gap-2">
                  <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-xl ${TILE_TONE_SOFT[g.tone]}`}>{g.icon}</span>
                  <h3 className="truncate text-sm font-semibold text-slate-800">{g.title}</h3>
                  <Badge tone={g.count ? g.tone : 'gray'}>{g.count}</Badge>
                  {g.note && <span className="truncate text-xs font-medium text-slate-400">{g.note}</span>}
                </div>
                <Link to={g.viewAll} className="shrink-0 text-xs font-medium text-indigo-600 hover:text-indigo-700">All →</Link>
              </div>
              {g.cardItems ? (
                // In Maintenance — a responsive grid of visual Repair-Progress KPI cards, one per car.
                g.cardItems.length === 0 ? (
                  <p className="py-6 text-center text-xs text-slate-400">{g.empty}</p>
                ) : (
                  <div className="grid grid-cols-1 gap-3 md:grid-cols-2 2xl:grid-cols-3">
                    {g.cardItems.map((it, i) => <RepairProgressCard key={i} item={it} />)}
                  </div>
                )
              ) : g.rows.length === 0 ? (
                <p className="py-6 text-center text-xs text-slate-400">{g.empty}</p>
              ) : (
                <ul className="space-y-1">
                  {g.rows.map((row, i) => (
                    <li key={i}>
                      <Link to={row.to} className="flex items-center justify-between gap-3 rounded-xl px-2.5 py-2 hover:bg-slate-50">
                        <div className="min-w-0">
                          <p className="truncate text-sm font-semibold text-slate-800">{row.primary}</p>
                          <p className="truncate text-xs text-slate-400">{row.secondary || '—'}</p>
                        </div>
                        <div className="flex shrink-0 flex-col items-end">
                          {row.rightNode || (
                            <>
                              <span className={`text-sm font-bold tabular-nums ${row.rightTone}`}>{row.right}</span>
                              {row.rightSub}
                            </>
                          )}
                        </div>
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          ))}
        </div>
      )}
    </SectionCard>
  );
}

// Inspection Accuracy — a single oversight signal scored as right-vs-wrong. `total` is everything the
// inspector handled (grades given / faults called / readings taken), `wrong` is the subset that was
// flagged. We show the accuracy rate as the headline, the raw right/wrong split below, and a two-tone
// bar so a card with a handful of misses on a big volume reads green (strong) at a glance.
function AccuracyCard({ title, icon, to, total, wrong, rightLabel, wrongLabel, tooltip, loading }) {
  const t = Math.max(0, Number(total) || 0);
  const w = Math.min(t, Math.max(0, Number(wrong) || 0));
  const right = t - w;
  const rate = t > 0 ? Math.round((right / t) * 100) : 100;
  // Tone by how clean the record is: strong (green) ≥90%, watch (amber) ≥75%, poor (red) below.
  const tone = rate >= 90 ? 'emerald' : rate >= 75 ? 'amber' : 'red';
  const toneText = { emerald: 'text-emerald-600', amber: 'text-amber-600', red: 'text-red-600' }[tone];
  const toneBar  = { emerald: 'bg-emerald-500', amber: 'bg-amber-500', red: 'bg-red-500' }[tone];
  const toneChip = { emerald: 'bg-emerald-50 text-emerald-600', amber: 'bg-amber-50 text-amber-600', red: 'bg-red-50 text-red-600' }[tone];
  const toneStroke = { emerald: 'stroke-emerald-500', amber: 'stroke-amber-500', red: 'stroke-red-500' }[tone];
  // Circular gauge geometry — a single ring whose filled arc = the accuracy rate.
  const R = 42;
  const CIRC = 2 * Math.PI * R;
  const dashOffset = loading ? CIRC : CIRC * (1 - rate / 100);

  return (
    <Link
      to={to}
      className="group flex flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-soft transition hover:-translate-y-0.5 hover:shadow-md"
    >
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <span className={`flex h-8 w-8 items-center justify-center rounded-xl ${toneChip}`}>{icon}</span>
          <p className="flex items-center gap-1 text-sm font-semibold text-slate-800">
            {title}
            <InfoTip content={tooltip} />
          </p>
        </div>
        <Icon.ArrowRight className="h-4 w-4 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-slate-400" />
      </div>

      <div className="mt-4 flex items-center gap-5">
        {/* Circular accuracy gauge — filled arc = right share, red track = wrong remainder. */}
        <div className="relative h-24 w-24 shrink-0">
          <svg viewBox="0 0 100 100" className="h-full w-full -rotate-90">
            <circle cx="50" cy="50" r={R} fill="none" strokeWidth="9" className="stroke-red-100" />
            <circle
              cx="50" cy="50" r={R} fill="none" strokeWidth="9" strokeLinecap="round"
              className={toneStroke}
              strokeDasharray={CIRC}
              strokeDashoffset={dashOffset}
              style={{ transition: 'stroke-dashoffset 0.6s ease' }}
            />
          </svg>
          <div className="absolute inset-0 flex flex-col items-center justify-center">
            <span className={`font-display text-2xl font-bold leading-none tabular-nums ${toneText}`}>
              {loading ? '—' : `${rate}%`}
            </span>
            <span className="mt-0.5 text-[10px] font-medium text-slate-400">accuracy</span>
          </div>
        </div>

        <div className="min-w-0 flex-1 space-y-2">
          <span className={`inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums ${toneChip}`}>
            {loading ? '—' : `${w.toLocaleString()} of ${t.toLocaleString()}`}
          </span>
          <div className="space-y-1.5 text-xs">
            <span className="flex items-center gap-1.5 font-medium text-slate-600">
              <span className={`h-2 w-2 rounded-full ${toneBar}`} />
              <span className="tabular-nums font-semibold text-slate-900">{loading ? '—' : right.toLocaleString()}</span> {rightLabel}
            </span>
            <span className="flex items-center gap-1.5 font-medium text-slate-600">
              <span className="h-2 w-2 rounded-full bg-red-500" />
              <span className="tabular-nums font-semibold text-slate-900">{loading ? '—' : w.toLocaleString()}</span> {wrongLabel}
            </span>
          </div>
        </div>
      </div>
    </Link>
  );
}

// Most Maintained Cars — the individual VEHICLES ranked by TOTAL LIFETIME days in maintenance: the
// sum of every maintenance period the car has ever had (type-U maintenance contracts, out→in; an
// open stay counts to today), all-time, no window, rental time ignored. Reads the purpose-built
// /Dashboard/most-maintained-cars, which already ranks + caps server-side.
function MostMaintainedCars() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let alive = true;
    setLoading(true);
    // Ranked by true downtime days (Rental is King) — same numbers as Fleet Utilization (All Time).
    api.get('/Dashboard/most-maintained-cars', { params: { limit: 8, sort: 'downtime' } })
      .then((res) => { if (alive) setRows(res.data.data?.items || []); })
      .catch(() => { if (alive) setRows([]); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, []);

  return (
    <SectionCard
      title={
        <span className="flex items-center gap-1.5">
          Most Maintained Cars
          <InfoTip content="The exact same lifetime numbers as Fleet Utilization (All Time). Each car's in-service days split into rented (green) and true off-road shop days (red). Rental is King: a day the car is both on rent and in the shop counts as rental, never shop time; overlapping maintenance periods are merged so no day is double-counted. Ranked by true off-road shop days." />
        </span>
      }
      subtitle="True downtime — distinct calendar days unavailable for maintenance · lifetime"
      actions={
        <Link to="/maintenance-history" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">All cars →</Link>
      }
    >
      {loading ? (
        <ul className="space-y-2">
          {Array.from({ length: 6 }).map((_, i) => <li key={i}><Skeleton className="h-10 rounded-xl" /></li>)}
        </ul>
      ) : rows.length === 0 ? (
        <p className="py-8 text-center text-sm text-slate-400">No workshop days recorded yet.</p>
      ) : (
        <ol className="space-y-1.5">
          {rows.map((r, i) => {
            // rented / shop / idle split of in-service days — same Rental-is-King numbers as Fleet
            // Utilization. Denominator is the segment sum so the bar always fills exactly.
            const splitTotal = (r.days_rented + r.days_in_shop + r.days_idle) || 1;
            const segW = (v) => `${((v / splitTotal) * 100).toFixed(1)}%`;
            return (
              <li key={r.id} className="flex items-center gap-3 rounded-xl px-2 py-2 hover:bg-slate-50">
                <span className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-xs font-bold tabular-nums ${
                  i === 0 ? 'bg-amber-100 text-amber-700' : i < 3 ? 'bg-indigo-50 text-indigo-600' : 'bg-slate-100 text-slate-500'
                }`}>
                  {i + 1}
                </span>
                <div className="min-w-0 flex-1">
                  <div className="flex items-baseline justify-between gap-2">
                    <div className="min-w-0">
                      <Link to={`/vehicles/${r.id}`} className="block truncate text-sm font-semibold text-slate-800 hover:text-indigo-600">
                        {r.plate || `#${r.id}`}
                      </Link>
                      {r.car && <p className="truncate text-[11px] text-slate-400">{r.car}</p>}
                    </div>
                    <span className="shrink-0 text-sm font-bold tabular-nums text-slate-900">
                      {Number(r.days_in_shop).toLocaleString()}<span className="ms-0.5 text-[11px] font-medium text-slate-400">d</span>
                    </span>
                  </div>

                  {/* Full day split — rented + util%, shop, and total in-service days (matches Fleet Utilization). */}
                  <p className="mt-1 flex flex-wrap items-center gap-x-2.5 gap-y-0.5 text-[11px] tabular-nums text-slate-500">
                    <span className="inline-flex items-center gap-1" title="Days on a paid rental">
                      <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />{Number(r.days_rented).toLocaleString()}d rented
                    </span>
                    {r.utilization_pct != null && (
                      <span className="font-semibold text-emerald-600" title="Utilization — rented ÷ in-service days">{r.utilization_pct}%</span>
                    )}
                    <span className="inline-flex items-center gap-1" title="True off-road shop days (no active rental)">
                      <span className="h-1.5 w-1.5 rounded-full bg-red-500" />{Number(r.days_in_shop).toLocaleString()}d shop
                    </span>
                    <span className="inline-flex items-center gap-1" title="Idle — available but not earning (not rented, not in the shop)">
                      <span className="h-1.5 w-1.5 rounded-full bg-slate-300" />{Number(r.days_idle).toLocaleString()}d idle
                    </span>
                    <span className="text-slate-400">· {Number(r.days_in_service).toLocaleString()}d total</span>
                  </p>

                  <div className="mt-1.5 flex h-1.5 w-full overflow-hidden rounded-full bg-slate-100" title={`${Number(r.days_rented).toLocaleString()}d rented · ${Number(r.days_in_shop).toLocaleString()}d shop · ${Number(r.days_idle).toLocaleString()}d idle`}>
                    {r.days_rented > 0 && <div className="bg-emerald-500" style={{ width: segW(r.days_rented) }} />}
                    {r.days_in_shop > 0 && <div className="bg-red-500" style={{ width: segW(r.days_in_shop) }} />}
                    {r.days_idle > 0 && <div className="bg-slate-300" style={{ width: segW(r.days_idle) }} />}
                  </div>
                </div>
                {r.currently_in_shop && (
                  <span className="hidden shrink-0 items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-700 sm:inline-flex">
                    <span className="h-1.5 w-1.5 rounded-full bg-amber-500" /> In shop
                  </span>
                )}
              </li>
            );
          })}
        </ol>
      )}
    </SectionCard>
  );
}

// Rental Billing — live invoice settlement (Track A). Paid / Partial / Not Paid counts for the
// OM-synced rental invoices, derived from each invoice's balance on sync (no manual sheet).
// Deep-links to the full Financial Reconciliation page. Money widget → gated by SHOW_FINANCIALS.
const BILL_TILES = [
  { key: 'paid',     label: 'Paid',     ring: 'border-emerald-200 bg-emerald-50', num: 'text-emerald-700', dot: 'bg-emerald-500' },
  { key: 'partial',  label: 'Partial',  ring: 'border-amber-200 bg-amber-50',     num: 'text-amber-700',   dot: 'bg-amber-500' },
  { key: 'not_paid', label: 'Not Paid', ring: 'border-red-200 bg-red-50',         num: 'text-red-600',     dot: 'bg-red-500' },
];
function RentalBillingSummary({ billing, loading }) {
  const b = billing || {};
  const total = b.total || 0;
  return (
    <SectionCard
      title={
        <span className="flex items-center gap-1.5">
          Rental Billing
          <InfoTip content="Live settlement status of rental invoices synced from OfficeManager — Paid, Partial, or Not Paid is derived from each invoice's outstanding balance on every sync. Replaces the manual bills sheet." />
        </span>
      }
      subtitle="Invoice settlement · synced from OfficeManager"
      actions={<Link to="/financial-reconciliation" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">Financial Reconciliation →</Link>}
      bodyClass="px-5 py-4 sm:px-6"
    >
      {loading ? (
        <MetricGridSkeleton count={3} />
      ) : (
        <div className="space-y-4">
          <div className="grid grid-cols-3 gap-3">
            {BILL_TILES.map((t) => (
              <Link
                key={t.key}
                to={`/financial-reconciliation?payment_status=${t.key}`}
                className={`rounded-2xl border ${t.ring} px-4 py-3 transition hover:shadow-sm`}
              >
                <div className="flex items-center gap-1.5">
                  <span className={`h-2 w-2 rounded-full ${t.dot}`} />
                  <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">{t.label}</span>
                </div>
                <div className={`mt-1 font-display text-3xl font-bold tabular-nums ${t.num}`}>
                  {Number(b[t.key] || 0).toLocaleString()}
                </div>
              </Link>
            ))}
          </div>
          <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-sm">
            <span className="text-slate-500">
              {Number(total).toLocaleString()} rental invoice{total === 1 ? '' : 's'} with live status
              {b.unsynced ? ` · ${Number(b.unsynced).toLocaleString()} awaiting status sync` : ''}
            </span>
            <span className="font-medium text-slate-700">
              {Number(b.pending || 0).toLocaleString()} pending ·{' '}
              <span className="tabular-nums">{aed(b.outstanding_balance || 0)}</span> outstanding
            </span>
          </div>
        </div>
      )}
    </SectionCard>
  );
}

export default function Dashboard() {
  const { user } = useAuth();
  const firstName = user?.name ? String(user.name).trim().split(/\s+/)[0] : '';
  const [view, setView] = useState('metrics'); // 'metrics' | 'pulse'

  const fetcher = useCallback(async () => {
    // The KPI summary is the one critical call (drives the headline counts + fleet
    // composition). The auxiliary feeds degrade to empty on failure, so a flaky
    // trends/overdue/expiring endpoint can never blank the whole dashboard.
    const safe = (fallback) => () => ({ data: { data: fallback } });
    const emptyFlags = { contract_expiry: { count: 0, items: [] }, in_maintenance: { count: 0, items: [] }, invoice_overdue: { count: 0, items: [] }, inspection_due: { count: 0, items: [] } };
    const emptyBilling = { paid: 0, partial: 0, not_paid: 0, unsynced: 0, pending: 0, total: 0, outstanding_balance: 0 };
    const emptyOversight = { mileage_flags: 0, mileage_readings_total: 0, severity_mismatches: 0, severity_graded_total: 0, misdiagnoses: 0, diagnosed_total: 0, awaiting_parts: 0, left_garage: 0, resolved_transfers: 0 };
    const [kpiRes, trendsRes, flagsRes, billingRes, oversightRes] = await Promise.all([
      api.get('/Dashboard', { params: { expiring_days: 7 } }),
      api.get('/Dashboard/trends', { params: { months: 12 } }).catch(safe({ cost: [], downtime: [] })),
      api.get('/Dashboard/proactive-flags', { params: { days: 7 } }).catch(safe(emptyFlags)),
      api.get('/Invoice/status-summary').catch(safe(emptyBilling)),
      api.get('/Oversight/overview').catch(safe(emptyOversight)),
    ]);
    return {
      // Fold the workflow-oversight roll-up (mileage / severity / mis-diagnosis / waiting-for-parts
      // counts) into the KPI object so the oversight KPI tiles read straight from `kpis[card.key]`.
      kpis: { ...(kpiRes.data.data || {}), ...(oversightRes.data.data || emptyOversight) },
      trends: trendsRes.data.data || { cost: [], downtime: [] },
      proactive: flagsRes.data.data || emptyFlags,
      billing: billingRes.data.data || emptyBilling,
    };
  }, []);
  const { data, loading, error } = useFetch(fetcher);

  const kpis = data?.kpis || {};
  const trends = data?.trends || { cost: [], downtime: [] };
  const proactive = data?.proactive || {};
  const billing = data?.billing || {};

  const fleet = kpis.fleet_status || {};
  const available = fleet.available || 0;
  const rented = fleet.rented || 0;
  const maint = fleet.maintenance || 0;
  // Operational fleet = cars the team actually works with (ready + on-rent + in-shop); excludes
  // sold / disposed / office-use, which inflate fleet.total. All readiness ratios divide by THIS.
  const activeFleet = available + rented + maint;
  const utilizationRate = activeFleet ? Math.round((rented / activeFleet) * 100) : 0;

  // Headline percent for the floating page gauge: fleet utilization.
  usePageStat({
    percent: loading || !activeFleet ? null : utilizationRate,
    label: 'Utilization',
    color: 'indigo',
    hint: `${rented} of ${activeFleet} operational cars currently rented out`,
  });

  // Fleet Status — a live snapshot for the headline donut, limited to the three
  // operational states the team actually works with. The "Unavailable" catch-all
  // (sold/disposed/office-use/other) was dropped because those cars surface in no
  // list, so the donut totals only the active, accounted-for fleet.
  const fleetStatusSegments = [
    { label: 'Available',   value: available, color: 'green'  },
    { label: 'On Rent',     value: rented,    color: 'blue'   },
    { label: 'Maintenance', value: maint,     color: 'yellow' },
  ];

  // Inspection Accuracy — the three oversight signals reframed as a right-vs-wrong scorecard: instead of
  // just "how many were wrong", each card scores the inspector against everything he handled, so a low
  // flag count on a huge volume reads as the strong performance it is (and vice-versa).
  const accuracy = [
    {
      key: 'severity', title: 'Severity Grading', icon: <Icon.Alert className="h-4 w-4" />, to: '/oversight/severity',
      total: kpis.severity_graded_total || 0, wrong: kpis.severity_mismatches || 0,
      rightLabel: 'graded right', wrongLabel: 'under-graded',
      tooltip: 'How often the inspector\'s fault-severity grade held up. Wrong = a critical-risk keyword, a breakdown, or a red-graded car said the grade was too low.',
    },
    {
      key: 'diagnosis', title: 'Diagnosis Accuracy', icon: <Icon.XCircle className="h-4 w-4" />, to: '/oversight/misdiagnoses',
      total: kpis.diagnosed_total || 0, wrong: kpis.misdiagnoses || 0,
      rightLabel: 'calls held', wrongLabel: 'overruled',
      tooltip: 'Of every fault the inspector diagnosed, how many stood. Wrong = a supervisor later overruled the call as a mis-diagnosis ("mark fault incorrect").',
    },
    {
      key: 'odometer', title: 'Odometer Accuracy', icon: <Icon.Gauge className="h-4 w-4" />, to: '/oversight/mileage',
      total: kpis.mileage_readings_total || 0, wrong: kpis.mileage_flags || 0,
      rightLabel: 'clean readings', wrongLabel: 'flagged',
      tooltip: 'Of every odometer reading captured across the workflow, how many were clean. Wrong = ran backwards, jumped, came from a test-drive, or was rejected outright.',
    },
  ];

  return (
    <div className="opx py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        {/* Greeting header — a light, personable "Good morning, {name}!" band with a live
            pulse, the last-updated stamp, the fleet-size counter, and the view switcher. */}
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div className="min-w-0">
            <div className="opx-hint" style={{ letterSpacing: '.16em', textTransform: 'uppercase', marginBottom: 7, display: 'flex', alignItems: 'center', gap: 8 }}>
              <span className="h-1.5 w-1.5 rounded-full" style={{ background: 'var(--avail)', boxShadow: '0 0 8px var(--avail)' }} />
              Fleet Command · Live
            </div>
            <div className="flex items-center gap-2.5">
              <span className="h-5 w-1 rounded-full bg-indigo-500" />
              <h1 className="font-display text-2xl font-bold tracking-tight" style={{ color: 'var(--ink)' }}>
                {greeting()}{firstName ? `, ${firstName}` : ''}
              </h1>
            </div>
            <p className="mt-1.5 text-sm sm:ps-3.5" style={{ color: 'var(--ink-3)' }}>
              Live snapshot of your fleet's {SHOW_FINANCIALS ? 'finances and operations' : 'status and operations'}.
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-3">
            <span className="hidden text-xs font-medium text-slate-400 sm:inline">Updated {fmtDate(new Date())}</span>
            {/* View toggle — flip between the analytical "Metrics" view and the live "Fleet Pulse" wall. */}
            <div className="inline-flex rounded-xl bg-slate-100 p-1">
              {[
                { key: 'metrics', label: 'Metrics', icon: <Icon.Chart className="h-4 w-4" /> },
                { key: 'pulse', label: 'Fleet Pulse', icon: <Icon.Activity className="h-4 w-4" /> },
              ].map((t) => (
                <button
                  key={t.key}
                  type="button"
                  onClick={() => setView(t.key)}
                  className={`inline-flex items-center gap-1.5 rounded-lg px-3.5 py-1.5 text-sm font-semibold transition ${
                    view === t.key ? 'bg-white text-slate-900 shadow-soft' : 'text-slate-500 hover:text-slate-700'
                  }`}
                >
                  {t.icon}
                  {t.label}
                </button>
              ))}
            </div>
          </div>
        </div>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {view === 'pulse' && <FleetPulseGrid />}

        {view === 'metrics' && (
          <>
        {/* Fleet composition — the status donut beside a compact utilization summary. */}
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          {loading ? (
            <Card className="lg:col-span-2">
              <div className="flex justify-center py-16"><Skeleton className="h-56 w-56 rounded-full" /></div>
            </Card>
          ) : (
            <FleetStatusCard
              className="lg:col-span-2"
              title="Fleet Status"
              centerLabel="Active Fleet"
              unit="cars"
              total={activeFleet}
              segments={fleetStatusSegments}
              headerRight={
                <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                  <span className="h-2 w-2 rounded-full bg-emerald-500" />
                  Live
                </span>
              }
            />
          )}

          {/* Fleet split — a compact legend of the active-fleet composition
              (Available / On Rent / Maintenance), matching the donut beside it. */}
          <Card className="flex flex-col p-6">
            <p className="flex items-center gap-1 text-sm font-semibold text-slate-800">
              Fleet Split
              <InfoTip content="How the active fleet breaks down right now — cars free to rent, out on rent, and in maintenance." />
            </p>
            <div className="mt-5 flex-1 space-y-3.5 border-t border-slate-100 pt-5">
              {[
                { label: 'Available',   value: available, dot: '#22C55E' },
                { label: 'On Rent',     value: rented,    dot: '#2F7EF6' },
                { label: 'Maintenance', value: maint,     dot: '#F5C518' },
              ].map((s) => (
                <div key={s.label} className="flex items-center gap-3">
                  <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: s.dot }} />
                  <span className="flex-1 text-sm font-medium text-slate-600">{s.label}</span>
                  <span className="text-sm font-semibold tabular-nums text-slate-900">
                    {loading ? '—' : s.value.toLocaleString()}
                  </span>
                </div>
              ))}
            </div>
          </Card>
        </div>

        {/* Proactive Flags — forward-looking conditions (rentals expiring, payments overdue,
            inspections due) surfaced before they become problems. Same source lists as the
            notification bell; every row deep-links to its record. */}
        <ProactiveFlags data={proactive} loading={loading} />

        {/* Most Maintained Cars — the individual vehicles with the most total days in the shop, all-time. */}
        <MostMaintainedCars />

        {/* Data visualization — maintenance spend per month (bar) and the downtime
            trend (line). Both are bespoke SVG, so they match the gauges and donut. */}
        <div className={`grid grid-cols-1 gap-6 ${SHOW_FINANCIALS ? 'lg:grid-cols-2' : ''}`}>
          {SHOW_FINANCIALS && (
          <Card>
            <div className="border-b border-slate-100 px-6 py-4">
              <h2 className="flex items-center gap-1.5 text-base font-semibold text-slate-900">
                Maintenance Cost
                <InfoTip content="Total workshop spend per month over the last 12 months, from the live garage log (imported + hand-entered events). Hover a bar for the visit count." />
              </h2>
              <p className="mt-0.5 text-xs text-slate-500">Monthly repair spend · last 12 months</p>
            </div>
            <div className="px-3 py-5 sm:px-5">
              {loading ? (
                <Skeleton className="h-[260px] w-full rounded-2xl" />
              ) : (
                <BarChart
                  data={trends.cost}
                  color="indigo"
                  height={260}
                  format={aed}
                  tickFormat={aedK}
                  valueLabel="Spend"
                  tooltip={(d) => `${d.visits} visit${d.visits === 1 ? '' : 's'}`}
                />
              )}
            </div>
          </Card>
          )}

          <Card>
            <div className="border-b border-slate-100 px-6 py-4">
              <h2 className="flex items-center gap-1.5 text-base font-semibold text-slate-900">
                Downtime Trend
                <InfoTip content="Average number of days a car spent in the shop per repair visit, by month. A falling line means cars are being turned around faster — downtime is improving." />
              </h2>
              <p className="mt-0.5 text-xs text-slate-500">Avg days in shop per visit · lower is better</p>
            </div>
            <div className="px-3 py-5 sm:px-5">
              {loading ? (
                <Skeleton className="h-[260px] w-full rounded-2xl" />
              ) : (
                <LineChart
                  data={trends.downtime}
                  color="emerald"
                  height={260}
                  format={(v) => `${v} day${v === 1 ? '' : 's'}`}
                  tickFormat={(v) => `${Math.round(v)}d`}
                  valueLabel="Avg in shop"
                  tooltip={(d) => `${d.visits} visit${d.visits === 1 ? '' : 's'}`}
                />
              )}
            </div>
          </Card>
        </div>

        {/* Inspection Accuracy — the oversight signals scored as right-vs-wrong instead of raw problem
            counts, so a few misses on a large volume reads as the strong record it is. */}
        <SectionCard
          title={
            <span className="flex items-center gap-1.5">
              Inspection Accuracy
              <InfoTip content="How the inspector is performing across the three oversight checks — his grades, diagnoses and odometer readings — each scored as a share that held up, not just the count that didn't." />
            </span>
          }
          subtitle="How the inspector's grades, diagnoses & readings held up · lifetime"
        >
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {accuracy.map((a) => (
              <AccuracyCard key={a.key} {...a} loading={loading} />
            ))}
          </div>
        </SectionCard>

        {/* Rental Billing — live invoice settlement (Track A): Paid / Partial / Not Paid counts
            for OM-synced rental invoices, derived on sync (replaces the manual bills sheet). */}
        {SHOW_FINANCIALS && <RentalBillingSummary billing={billing} loading={loading} />}

          </>
        )}

      </div>
    </div>
  );
}
