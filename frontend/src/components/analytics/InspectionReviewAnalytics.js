// The chart strip for the Inspection Review queue. The cards below are one request
// at a time; a controller vetting a long queue needs the pattern instead:
//
//   1. Why inspections get requested → the trigger mix. A queue dominated by one
//      trigger is usually a rule that needs tuning, not twenty separate faults.
//   2. How long they've been waiting → the review backlog's age
//
// NOT severity: every ticket in this queue sits at pending_review / inspection_requested,
// which is BEFORE anyone has looked at the car. fault_severity is graded later, at Decide,
// so a severity chart here can only ever read "100% not graded yet". Waiting time is the
// one urgency signal that genuinely exists at this stage.
//
// Derived from the /maintenance-tickets/pending-review payload already on the page.

import { useMemo } from 'react';
import AnalyticsCard from './AnalyticsCard';
import RankedBar from '../ui/RankedBar';
import PieChart from '../ui/PieChart';
import { num } from '../../lib/format';

// Age buckets, oldest-first so the slice that needs attention leads the legend.
const AGE_BUCKETS = [
  { key: 'over_week', label: 'Over a week', color: 'red', min: 8 },
  { key: 'week', label: '4–7 days', color: 'orange', min: 4 },
  { key: 'few_days', label: '1–3 days', color: 'amber', min: 1 },
  { key: 'today', label: 'Today', color: 'emerald', min: 0 },
];

// "customer_reported" → "Customer reported". The trigger vocabulary lives in the
// page; prettifying the raw value keeps this chart correct if a new trigger is added.
const pretty = (s) =>
  String(s || 'Not recorded').replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

export default function InspectionReviewAnalytics({ tickets = [] }) {
  const triggers = useMemo(() => {
    const groups = new Map();
    tickets.forEach((t) => {
      const key = t.trigger_reason || 'unknown';
      const g = groups.get(key) || { key, label: pretty(key), value: 0, rented: 0 };
      g.value += 1;
      if (t.operational_status === 'rented') g.rented += 1;
      groups.set(key, g);
    });
    return [...groups.values()].sort((a, b) => b.value - a.value).slice(0, 10);
  }, [tickets]);

  // WHERE the requests came from. A separate breakdown from the trigger chart above — the two answer
  // different questions ("what's wrong?" vs. "who found it?"), which is the whole point of storing
  // request_origin apart from trigger_reason. This is the count behind "how many issues do drivers
  // find vs. inspectors vs. the scheduler?".
  const origins = useMemo(() => {
    const groups = new Map();
    tickets.forEach((t) => {
      const key = t.request_origin || 'unknown';
      const label = t.request_origin_label || pretty(key);
      const g = groups.get(key) || { key, label, value: 0, rented: 0 };
      g.value += 1;
      if (t.operational_status === 'rented') g.rented += 1;
      groups.set(key, g);
    });
    return [...groups.values()].sort((a, b) => b.value - a.value).slice(0, 10);
  }, [tickets]);

  // Waiting time = since the request was raised (legacy system requests predate the review
  // gate and can carry no requested_at, so fall back to when the ticket was created).
  const waiting = useMemo(() => {
    const now = Date.now();
    const totals = {};
    tickets.forEach((t) => {
      const raw = t.handoffs?.requested?.at || t.created_at;
      const ms = raw ? Date.parse(raw) : NaN;
      if (Number.isNaN(ms)) {
        totals.undated = (totals.undated || 0) + 1;
        return;
      }
      const days = Math.max(0, Math.floor((now - ms) / 86400000));
      const b = AGE_BUCKETS.find((x) => days >= x.min) || AGE_BUCKETS[AGE_BUCKETS.length - 1];
      totals[b.key] = (totals[b.key] || 0) + 1;
    });
    const rows = AGE_BUCKETS
      .filter((b) => totals[b.key] > 0)
      .map((b) => ({ label: b.label, value: totals[b.key], color: b.color }));
    if (totals.undated) rows.push({ label: 'No date on file', value: totals.undated, color: 'slate' });
    return rows;
  }, [tickets]);

  if (!tickets.length) return null;

  return (
    <div className="space-y-4">
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <AnalyticsCard
        variant="opx"
        dotColor="#8b7bfb"
        className="lg:col-span-2"
        title="Why these were requested"
        subtitle="Trigger behind each request awaiting review"
      >
        <RankedBar
          items={triggers}
          showRank
          color="violet"
          format={(n) => num(Math.round(n))}
          valueLabel="Requests"
          labelWidth={160}
          valueWidth={56}
          tooltip={(r) =>
            r.rented > 0
              ? `${num(r.rented)} still out on rent — the car has to come back first`
              : 'All cars are back with us'
          }
          empty="Nothing awaiting review."
        />
      </AnalyticsCard>

      <AnalyticsCard
        variant="opx"
        dotColor="#e11d48"
        title="How long they've been waiting"
        subtitle="Age of each request still awaiting review"
      >
        {/* stacked: this card is a third of the row — a side-by-side legend would squeeze
            the labels and their numbers into ~180px. */}
        <div className="flex items-center justify-center">
          <PieChart segments={waiting} size={168} stacked />
        </div>
      </AnalyticsCard>
    </div>

    {/* WHERE they came from — the source axis, deliberately its own card so it can never be read as
        a restatement of the trigger chart above. */}
    <AnalyticsCard
      variant="opx"
      dotColor="#10b981"
      title="Where these came from"
      subtitle="Who or what raised each request awaiting review"
    >
      <RankedBar
        items={origins}
        showRank
        color="emerald"
        format={(n) => num(Math.round(n))}
        valueLabel="Requests"
        labelWidth={160}
        valueWidth={56}
        tooltip={(r) =>
          r.rented > 0
            ? `${num(r.rented)} still out on rent — the car has to come back first`
            : 'All cars are back with us'
        }
        empty="Nothing awaiting review."
      />
    </AnalyticsCard>
    </div>
  );
}
