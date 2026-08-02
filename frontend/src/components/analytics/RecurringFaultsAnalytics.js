// The analytics dashboard for Recurring Fault Reviews.
//
// A review case is opened automatically whenever the workshop CONFIRMS a fault that this same car was
// already fixed for. One case is an incident; the aggregate is a rework scorecard nobody otherwise sees:
//
//   1. Is rework getting worse?          → recurrences opened per month (12 months)
//   2. How fast do repairs fail?         → days-to-return histogram; ≤7 days is a repair that never worked
//   3. Whose repairs come back?          → by garage, and by car, and by fault
//
// Fed by GET /recurring-fault-reviews/stats — FLEET-WIDE on purpose, not the filtered table. Running these
// off the visible rows would show ~100% "awaiting a ruling" whenever the page sits on its default filter.
// Every number is counted from recurring_fault_reviews; nothing here is inferred or scored.

import { SectionCard } from '../ui/Table';
import DateRangePicker from '../ui/DateRangePicker';
import MetricCard, { MetricGrid } from '../ui/MetricCard';
import RankedBar from '../ui/RankedBar';
import BarChart from '../ui/BarChart';
import LineChart from '../ui/LineChart';
import { MetricCardSkeleton } from '../ui/Skeleton';
import { num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

// The days-to-return histogram is a severity ramp: a fault back within a week means the repair never
// worked; two months out is closer to ordinary wear.
const SPEED_COLOR = { '0-7': 'red', '8-30': 'orange', '31-60': 'amber', '61+': 'slate' };

const pct = (n) => `${Math.round(n || 0)}%`;
const cases = (n) => `${num(n)} case${n === 1 ? '' : 's'}`;

// What the fault ranking is currently counting, said in the subtitle. The window is echoed back by the
// API rather than read off local state, so the caption can never describe a range the numbers aren't in.
const DAY_LABEL = { 30: 'last 30 days', 90: 'last 90 days', 180: 'last 6 months', 365: 'last year' };
const shortDate = (iso) => {
  if (!iso) return '';
  const [y, m, d] = iso.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
};
const faultScope = (w) => {
  if (!w) return 'across the fleet';
  if (w.from && w.to) return `${shortDate(w.from)} – ${shortDate(w.to)}`;
  if (w.from) return `since ${shortDate(w.from)}`;
  if (w.to) return `up to ${shortDate(w.to)}`;
  if (w.days > 0) return DAY_LABEL[w.days] || `last ${num(w.days)} days`;
  return 'across the fleet';
};

export default function RecurringFaultsAnalytics({
  stats,
  loading = false,
  // The fault ranking's own date window — {days, from, to}, mirroring the API params. Only this one
  // panel is scoped; every other chart here stays fleet-wide and all-time by design.
  faultWindow = { days: 0, from: null, to: null },
  onFaultWindowChange,
}) {
  const { t } = useI18n();
  if (loading && !stats) {
    return (
      <MetricGrid cols={4}>
        {[0, 1, 2, 3].map((i) => <MetricCardSkeleton key={i} />)}
      </MetricGrid>
    );
  }
  if (!stats) return null;

  const k = stats.kpi || {};
  if (!k.total) return null;

  // Momentum vs the previous 30 days. A RISE in recurrences is bad news, so it takes the red `down`
  // treatment — the pill's colour tracks whether the fleet is improving, not whether the number grew.
  const delta = (k.last_30_days || 0) - (k.prev_30_days || 0);
  const deltaPill = k.prev_30_days || k.last_30_days
    ? { delta: `${delta > 0 ? '+' : ''}${num(delta)} vs prev 30d`, trend: delta > 0 ? 'down' : delta < 0 ? 'up' : 'flat' }
    : {};

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
  }));

  return (
    <div className="space-y-4">
      {/* ── Headline numbers ─────────────────────────────────────────────── */}
      <MetricGrid cols={4}>
        <MetricCard
          label={t('recurringFaults.awaitingRuling')}
          value={num(k.open || 0)}
          tone={k.open > 0 ? 'amber' : 'emerald'}
          hint={`${cases(k.total)} on record`}
          tooltip="Confirmed recurrences with no management decision yet. Each one blocks its repair."
        />
        <MetricCard
          label="Came back (last 30 days)"
          value={num(k.last_30_days || 0)}
          tone={delta > 0 ? 'red' : 'indigo'}
          hint={`${num(k.prev_30_days || 0)} in the 30 days before`}
          tooltip="Review cases opened in the last 30 days — the rate at which repairs are failing."
          {...deltaPill}
        />
        <MetricCard
          label="Median time to return"
          value={k.median_days != null ? `${num(k.median_days)}d` : '—'}
          tone={k.median_days != null && k.median_days <= 14 ? 'red' : 'slate'}
          hint={`Detection window ${num(k.window_days)} days`}
          tooltip="Median days between a repair being completed and the same fault being confirmed again. Median, not average, so one late return can't flatter the number."
        />
        <MetricCard
          label="Failed after a verified fix"
          value={num(k.verified_fixed || 0)}
          tone={k.verified_fixed > 0 ? 'red' : 'emerald'}
          hint={`${pct(k.verified_share)} of all cases`}
          tooltip="The previous repair passed a post-repair inspection and the fault still came back. That is a QC failure, not bad luck."
        />
      </MetricGrid>

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
              tooltip={(d) => (d.faults?.length ? (
                <div className="mt-1 space-y-0.5">
                  {d.faults.map((f) => (
                    <div key={f.label} className="flex gap-3 capitalize">
                      <span className="flex-1 truncate">{f.label}</span>
                      <span className="tabular-nums">{num(f.value)}</span>
                    </div>
                  ))}
                  {d.more > 0 && (
                    <div className="text-white/50">+{num(d.more)} other fault{d.more === 1 ? '' : 's'}</div>
                  )}
                </div>
              ) : null)}
            />
          ) : (
            <div className="flex h-[200px] items-center justify-center text-sm text-slate-400">
              No timing recorded yet.
            </div>
          )}
        </SectionCard>

        <SectionCard
          title="Faults that keep coming back"
          subtitle={`By fault category · ${faultScope(stats.faults_window)}`}
          bodyClass="p-5"
          // The only date-scoped panel on this dashboard. Which faults dominate goes stale fastest: a
          // batch of brake jobs replaced in March keeps topping the all-time list long after it stopped
          // recurring, so this ranking gets a window of its own. Ranking happens AFTER the window is
          // applied server-side, so narrowing can promote a fault the all-time top-8 never showed.
          actions={onFaultWindowChange ? (
            <DateRangePicker
              days={faultWindow.days}
              from={faultWindow.from}
              to={faultWindow.to}
              onChange={onFaultWindowChange}
            />
          ) : null}
        >
          <RankedBar
            items={stats.faults || []}
            showRank
            color="orange"
            format={(n) => num(Math.round(n))}
            valueLabel="Cases"
            valueWidth={56}
            labelWidth={150}
            tooltip={(f) => `Across ${num(f.cars)} car${f.cars === 1 ? '' : 's'}`}
            empty={faultScope(stats.faults_window) === 'across the fleet'
              ? 'No recurring faults recorded.'
              : 'No faults came back in this window.'}
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
          subtitle="Recurring-fault cases raised per car"
          bodyClass="p-5"
        >
          <RankedBar
            items={vehicles}
            showRank
            color="red"
            format={(n) => num(Math.round(n))}
            valueLabel="Cases"
            valueWidth={56}
            labelWidth={150}
            tooltip={(r) => (r.open > 0 ? `${num(r.open)} still awaiting a ruling` : 'All ruled on')}
            empty="No cases raised."
          />
        </SectionCard>
      </div>
    </div>
  );
}
