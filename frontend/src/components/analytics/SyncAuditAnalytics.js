// The chart strip for Sync Audit. A run log is read newest-first one line at a time;
// these charts turn it into the two things worth knowing about a sync:
//
//   1. What each run actually changed → new / updated / auto-corrected per run.
//      All three are counts of records, so they legitimately share one axis.
//   2. Are runs succeeding?           → outcome mix across the log
//
// A run that suddenly corrects far more than it updates is the signal this page
// exists for, and it's invisible in a table of numbers.
//
// Derived from the /Sync/audit runs already on the page.

import { useMemo } from 'react';
import { SectionCard } from '../ui/Table';
import GroupedBarChart from '../ui/GroupedBarChart';
import PieChart from '../ui/PieChart';
import { num } from '../../lib/format';

const STATUS_COLOR = {
  success: 'emerald',
  completed: 'emerald',
  ok: 'emerald',
  partial: 'amber',
  running: 'blue',
  failed: 'red',
  error: 'red',
};

// "2026-07-28T09:12" → "28 Jul". Short enough to sit under a bar.
const shortDate = (iso) => {
  if (!iso) return '—';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '—';
  return d.toLocaleDateString(undefined, { day: '2-digit', month: 'short' });
};

export default function SyncAuditAnalytics({ runs = [] }) {
  // Oldest → newest across the last dozen runs, so the trend reads left to right
  // like every other time axis in the app (the table itself is newest-first).
  const trend = useMemo(
    () =>
      [...runs]
        .slice(0, 12)
        .reverse()
        .map((r) => ({
          label: shortDate(r.started_at),
          created: Number(r.created) || 0,
          updated: Number(r.updated) || 0,
          corrections: Number(r.corrections) || 0,
          scanned: Number(r.scanned) || 0,
          action: r.action,
        })),
    [runs],
  );

  const outcomes = useMemo(() => {
    const totals = {};
    runs.forEach((r) => {
      const k = r.status || 'unknown';
      totals[k] = (totals[k] || 0) + 1;
    });
    return Object.entries(totals)
      .map(([k, value]) => ({
        label: k.charAt(0).toUpperCase() + k.slice(1),
        value,
        color: STATUS_COLOR[k] || 'slate',
      }))
      .sort((a, b) => b.value - a.value);
  }, [runs]);

  if (!runs.length) return null;

  const corrections = runs.reduce((a, r) => a + (Number(r.corrections) || 0), 0);

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        className="lg:col-span-2"
        title="What each run changed"
        subtitle="New, updated and auto-corrected records across the last 12 runs"
        bodyClass="px-3 pb-3 pt-2"
      >
        <GroupedBarChart
          data={trend}
          series={[
            { key: 'created', label: 'New', color: 'emerald' },
            { key: 'updated', label: 'Updated', color: 'blue' },
            { key: 'corrections', label: 'Auto-corrected', color: 'amber' },
          ]}
          height={240}
          integer
          format={(n) => num(Math.round(n))}
        />
        <p className="px-3 pb-1 pt-1 text-xs leading-relaxed text-slate-500">
          {corrections > 0 ? (
            <>
              <span className="font-semibold text-amber-600">{num(corrections)}</span> record
              {corrections === 1 ? '' : 's'} were auto-corrected across this log — a run that
              corrects more than it updates is worth opening.
            </>
          ) : (
            <>Nothing has needed auto-correction in this log.</>
          )}
        </p>
      </SectionCard>

      <SectionCard
        title="Run outcomes"
        subtitle="How the sync log ended"
        bodyClass="flex items-center justify-center p-5"
      >
        <PieChart segments={outcomes} size={150} />
      </SectionCard>
    </div>
  );
}
