// The analytics dashboard for Recurring Fault Reviews.
//
// A review case is opened automatically whenever the workshop CONFIRMS a fault that this same car was
// already fixed for. One case is an incident; the aggregate is a rework scorecard nobody otherwise sees:
//
//   1. Is rework getting worse?          → recurrences opened per month (12 months)
//   2. How fast do repairs fail?         → days-to-return histogram; ≤7 days is a repair that never worked
//   3. Whose repairs come back?          → by garage, and by car, and by fault
//
// All charts, no headline numbers: the counts that used to sit in a KPI row are readable off the table
// below and the charts here, and a scorecard that restates them is one more thing to keep honest.
//
// Fed by GET /recurring-fault-reviews/stats — FLEET-WIDE on purpose, not the filtered table. Running these
// off the visible rows would show ~100% "awaiting a ruling" whenever the page sits on its default filter.
// Every number is counted from recurring_fault_reviews; nothing here is inferred or scored.

import { SectionCard } from '../ui/Table';
import DateRangePicker from '../ui/DateRangePicker';
import RankedBar from '../ui/RankedBar';
import BarChart from '../ui/BarChart';
import LineChart from '../ui/LineChart';
import Skeleton from '../ui/Skeleton';
import { num } from '../../lib/format';

// The days-to-return histogram is a severity ramp: a fault back within a week means the repair never
// worked; two months out is closer to ordinary wear.
const SPEED_COLOR = { '0-7': 'red', '8-30': 'orange', '31-60': 'amber', '61+': 'slate' };

// What a windowed ranking is currently counting, said in its subtitle. The window is echoed back by the
// API rather than read off local state, so the caption can never describe a range the numbers aren't in.
const DAY_LABEL = { 30: 'last 30 days', 90: 'last 90 days', 180: 'last 6 months', 365: 'last year' };
const shortDate = (iso) => {
  if (!iso) return '';
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
};
const ALL_TIME = 'across the fleet';
const scopeLabel = (w) => {
  if (!w) return ALL_TIME;
  if (w.from && w.to) return `${shortDate(w.from)} – ${shortDate(w.to)}`;
  if (w.from) return `since ${shortDate(w.from)}`;
  if (w.to) return `up to ${shortDate(w.to)}`;
  if (w.days > 0) return DAY_LABEL[w.days] || `last ${num(w.days)} days`;
  return ALL_TIME;
};
const isScoped = (w) => scopeLabel(w) !== ALL_TIME;

// The window a ranking is actually counting, stated in full above the bars. It lives in the BODY, not
// in the card's subtitle: the header truncates to make room for the picker, and a scope caption that
// reads "across the fl…" is worse than none. Read off the API's echo, never local state, so it can
// never describe a range the bars aren't in.
function ScopeNote({ window: w, noun }) {
  const scoped = isScoped(w);
  return (
    <p className={`mb-3 text-xs ${scoped ? 'font-medium text-indigo-600' : 'text-slate-400'}`}>
      {scopeLabel(w)}
      {w?.cases != null && ` · ${num(w.cases)} ${noun}${w.cases === 1 ? '' : 's'}`}
    </p>
  );
}

// The "made of what?" list inside a tooltip — the cars behind a fault, the faults behind a car, the
// faults inside a speed bucket. One shape for all three so a breakdown always reads the same way.
function Breakdown({ items = [], more = 0, unit, empty }) {
  if (!items.length) return empty || null;
  return (
    <div className="mt-1 space-y-0.5">
      {items.map((it) => (
        <div key={it.label} className="flex gap-3 capitalize">
          <span className="flex-1 truncate">{it.label}</span>
          <span className="tabular-nums">{num(it.value)}</span>
        </div>
      ))}
      {more > 0 && (
        <div className="text-white/50">+{num(more)} other {unit}{more === 1 ? '' : 's'}</div>
      )}
    </div>
  );
}

export default function RecurringFaultsAnalytics({
  stats,
  loading = false,
  // Each "keeps coming back" ranking carries its OWN date window — {days, from, to}, mirroring the API
  // params. Only those two panels are scoped; every other chart here stays fleet-wide and all-time.
  faultWindow = { days: 0, from: null, to: null },
  onFaultWindowChange,
  carWindow = { days: 0, from: null, to: null },
  onCarWindowChange,
}) {
  if (loading && !stats) {
    return <Skeleton className="h-[300px] w-full rounded-2xl" />;
  }
  if (!stats) return null;

  // The KPI row is gone, but its `total` still decides whether there is a dashboard to draw at all:
  // with no case on record every chart below would be an empty frame.
  if (!(stats.kpi?.total)) return null;

  const trend = (stats.trend || []).map((t) => ({ ...t, label: t.label }));

  const speed = (stats.speed || []).map((s) => ({ ...s, color: SPEED_COLOR[s.key] || 'slate' }));
  const hasSpeed = speed.some((s) => s.value > 0);

  const vehicles = (stats.vehicles || []).map((v) => ({
    label: v.label,
    sub: v.sub || undefined,
    // This ranking says WHICH car; the car's own "Keeps breaking down" panel says WHICH faults and
    // how each repair failed. ?focus=repeat-faults lands on the Overview tab, scrolled to that panel.
    to: v.vehicle_id ? `/vehicles/${v.vehicle_id}?focus=repeat-faults` : undefined,
    value: v.value,
    open: v.open,
    faults: v.faults,
    more: v.more,
  }));

  return (
    <div className="space-y-4">
      {/* ── Trend ────────────────────────────────────────────────────────── */}
      <div className="grid grid-cols-1 gap-4">
        <SectionCard
          title="Is rework getting worse?"
          subtitle="Recurring-fault cases opened per month"
          bodyClass="p-5 pb-2"
        >
          <LineChart
            data={trend}
            color="red"
            height={240}
            format={(n) => num(Math.round(n))}
            valueLabel="Cases opened"
            tooltip={(d) => (d.verified > 0
              ? `${num(d.verified)} after a verified fix`
              : 'None had a verified fix')}
          />
        </SectionCard>
      </div>

      {/* ── How fast they fail + which faults ────────────────────────────── */}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <SectionCard
          title="How fast the repair failed"
          subtitle="Days between the repair and the fault returning"
          bodyClass="p-5 pb-2"
        >
          {hasSpeed ? (
            <BarChart
              data={speed}
              height={230}
              format={(n) => num(Math.round(n))}
              valueLabel="Cases"
              // A bucket count on its own doesn't say what to fix. Hovering names the faults that came
              // back in that window, biggest first, so "9 within a week" becomes "6 of them brake noise".
              tooltip={(d) => <Breakdown items={d.faults} more={d.more} unit="fault" />}
            />
          ) : (
            <div className="flex h-[200px] items-center justify-center text-sm text-slate-400">
              No timing recorded yet.
            </div>
          )}
        </SectionCard>

        <SectionCard
          title="Faults that keep coming back"
          subtitle="By fault category"
          bodyClass="p-5"
          // Which faults dominate goes stale fastest: a batch of brake jobs replaced in March keeps
          // topping the all-time list long after it stopped recurring, so this ranking gets a window of
          // its own. Ranking happens AFTER the window is applied server-side, so narrowing it can
          // promote a fault the all-time top-8 never showed.
          overflowVisible
          actions={onFaultWindowChange ? (
            <DateRangePicker
              days={faultWindow.days}
              from={faultWindow.from}
              to={faultWindow.to}
              onChange={onFaultWindowChange}
            />
          ) : null}
        >
          <ScopeNote window={stats.faults_window} noun="fault case" />
          <RankedBar
            items={stats.faults || []}
            showRank
            color="orange"
            format={(n) => num(Math.round(n))}
            valueLabel="Cases"
            valueWidth={56}
            labelWidth={150}
            // Hovering names the CARS behind the fault: "Brake failure, 10 cases" reads very
            // differently once you see it is one car ten times rather than ten cars once each.
            tooltip={(f) => (
              <>
                <div>Across {num(f.cars)} car{f.cars === 1 ? '' : 's'}</div>
                <Breakdown items={f.top_cars} more={f.cars_more} unit="car" />
              </>
            )}
            empty={isScoped(stats.faults_window)
              ? 'No faults came back in this window.'
              : 'No recurring faults recorded.'}
          />
        </SectionCard>
      </div>

      {/* ── Who owns the rework ──────────────────────────────────────────── */}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <SectionCard
          title="Repairs that came back, by garage"
          subtitle="A count of returns — not a blame ranking"
          bodyClass="p-5"
        >
          <RankedBar
            items={stats.garages || []}
            showRank
            color="amber"
            format={(n) => num(Math.round(n))}
            valueLabel="Returns"
            valueWidth={56}
            labelWidth={150}
            tooltip={(g) => [
              g.workshop > 0 ? `${num(g.workshop)} ruled workshop responsibility` : null,
              g.verified > 0 ? `${num(g.verified)} after a verified fix` : null,
              g.open > 0 ? `${num(g.open)} still awaiting a ruling` : null,
            ].filter(Boolean).join(' · ') || 'No rulings against this garage'}
            empty="No garage recorded on the previous repairs."
          />
        </SectionCard>

        <SectionCard
          title="Cars that keep coming back"
          subtitle="Cases raised per car"
          bodyClass="p-5"
          // Its own window, independent of the fault ranking's: "which cars are hurting us THIS quarter"
          // is a different question from "which faults", and a car sold months ago should be able to
          // drop off this list without touching the other. Ranked after the window, same as faults.
          overflowVisible
          actions={onCarWindowChange ? (
            <DateRangePicker
              days={carWindow.days}
              from={carWindow.from}
              to={carWindow.to}
              onChange={onCarWindowChange}
            />
          ) : null}
        >
          <ScopeNote window={stats.cars_window} noun="case" />
          <RankedBar
            items={vehicles}
            showRank
            color="red"
            format={(n) => num(Math.round(n))}
            valueLabel="Cases"
            valueWidth={56}
            labelWidth={150}
            // Hovering names the FAULTS this car keeps coming back with: "4 cases" says the car is a
            // problem, "3 of them the same AC fault" says what the problem is.
            tooltip={(r) => (
              <>
                <div>{r.open > 0 ? `${num(r.open)} still awaiting a ruling` : 'All ruled on'}</div>
                <Breakdown items={r.faults} more={r.more} unit="fault" />
              </>
            )}
            empty={isScoped(stats.cars_window) ? 'No cases raised in this window.' : 'No cases raised.'}
          />
        </SectionCard>
      </div>
    </div>
  );
}
