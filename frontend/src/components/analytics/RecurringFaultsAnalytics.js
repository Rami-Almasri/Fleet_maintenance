// The analytics dashboard for Recurring Fault Reviews.
//
// A review case is opened automatically whenever the workshop CONFIRMS a fault that this same car was
// already fixed for. One case is an incident; the aggregate is a rework scorecard nobody otherwise sees:
//
//   1. Is rework getting worse?          → recurrences opened per month (12 months)
//   2. Where does responsibility land?   → the management decision mix
//   3. How fast do repairs fail?         → days-to-return histogram; ≤7 days is a repair that never worked
//   4. Whose repairs come back?          → by garage, and by car, and by fault
//
// Fed by GET /recurring-fault-reviews/stats — FLEET-WIDE on purpose, not the filtered table. Running these
// off the visible rows would show ~100% "awaiting a ruling" whenever the page sits on its default filter.
// Every number is counted from recurring_fault_reviews; nothing here is inferred or scored.

import { SectionCard } from '../ui/Table';
import MetricCard, { MetricGrid } from '../ui/MetricCard';
import RankedBar from '../ui/RankedBar';
import PieChart from '../ui/PieChart';
import BarChart from '../ui/BarChart';
import LineChart from '../ui/LineChart';
import { MetricCardSkeleton } from '../ui/Skeleton';
import { num } from '../../lib/format';

// Decision → the page's own badge tone, mapped to the chart palette.
const DECISION = {
  same_repair_failed: { label: 'Same repair failed', color: 'red' },
  new_unrelated_failure: { label: 'New unrelated failure', color: 'blue' },
  workshop_responsibility: { label: 'Workshop responsibility', color: 'amber' },
  customer_misuse: { label: 'Customer misuse', color: 'purple' },
  investigation_required: { label: 'Investigation required', color: 'cyan' },
};

// The days-to-return histogram is a severity ramp: a fault back within a week means the repair never
// worked; two months out is closer to ordinary wear.
const SPEED_COLOR = { '0-7': 'red', '8-30': 'orange', '31-60': 'amber', '61+': 'slate' };

const pct = (n) => `${Math.round(n || 0)}%`;
const cases = (n) => `${num(n)} case${n === 1 ? '' : 's'}`;

export default function RecurringFaultsAnalytics({ stats, loading = false }) {
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
  const decisions = (stats.decisions || []).map((d) => ({
    label: DECISION[d.key]?.label || d.key,
    value: d.value,
    color: DECISION[d.key]?.color || 'slate',
  }));
  // The undecided backlog belongs in the mix — otherwise a page with 2 rulings and 40 open cases reads
  // as a solved problem.
  const undecided = Math.max(0, (k.total || 0) - (k.decided || 0));
  if (undecided) decisions.push({ label: 'Awaiting a ruling', value: undecided, color: 'slate' });

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
          label="Awaiting a ruling"
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

      {/* ── Trend + responsibility ───────────────────────────────────────── */}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <SectionCard
          className="lg:col-span-2"
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

        <SectionCard
          title="Where responsibility lands"
          subtitle="Management rulings, all cases"
          bodyClass="p-5"
        >
          {/* stacked: this card is a third of the row, so a side-by-side legend
              squeezes the labels into ellipses. Pie on top, full-width legend under. */}
          <PieChart segments={decisions} size={168} stacked />
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
          subtitle="By fault category across the fleet"
          bodyClass="p-5"
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
            empty="No recurring faults recorded."
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
