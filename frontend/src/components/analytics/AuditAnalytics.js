// A shared chart strip for the oversight / audit lists — Mileage Discrepancies,
// Left-the-Garage Invoices, Diagnostic Review, Mis-Diagnosis and Resolved Transfers.
//
// These pages all answer "which records tripped this check", and they all share the
// same row shape (a plate, a ticket, and usually one magnitude). The single question
// a table of them can't answer is REPETITION: one car appearing eight times in an
// audit is a systemic problem, while eight cars appearing once each is just noise.
// So the strip is deliberately one chart, not a padded row of three.
//
//   <AuditAnalytics rows={rows} title="Cars flagged most often" metricLabel="Flags" />
//
// Pass `magnitude` to rank by a size instead of a count (e.g. km of odometer drift,
// days a car sat with an open contract).

import { useMemo } from 'react';
import { SectionCard } from '../ui/Table';
import RankedBar from '../ui/RankedBar';
import { num } from '../../lib/format';

export default function AuditAnalytics({
  rows = [],
  title = 'Cars flagged most often',
  subtitle = 'Repeat appearances in this audit — one car many times is a systemic problem, not noise',
  metricLabel = 'Flags',
  color = 'orange',
  magnitude,          // optional (row) => number — rank by size instead of count
  magnitudeLabel,     // what that size is called
  magnitudeFormat = (n) => num(Math.round(n)),
  minCount = 1,       // only show cars flagged at least this many times
}) {
  const board = useMemo(() => {
    const groups = new Map();
    rows.forEach((r) => {
      const key = r.plate_no || r.plate || r.vehicle_id || r.ticket_id;
      if (key == null) return;
      const g = groups.get(key) || {
        key,
        label: r.plate_no || r.plate || `#${r.ticket_id ?? r.vehicle_id}`,
        sub: r.car || undefined,
        to: r.ticket_id ? `/maintenance-workflow/${r.ticket_id}` : undefined,
        count: 0,
        peak: 0,
      };
      g.count += 1;
      if (magnitude) {
        const m = Math.abs(Number(magnitude(r)) || 0);
        if (m > g.peak) g.peak = m;
      }
      groups.set(key, g);
    });

    const list = [...groups.values()].filter((g) => g.count >= minCount);
    const value = (g) => (magnitude ? g.peak : g.count);
    return list
      .filter((g) => value(g) > 0)
      .sort((a, b) => value(b) - value(a))
      .slice(0, 10)
      .map((g) => ({ ...g, value: value(g) }));
  }, [rows, magnitude, minCount]);

  if (!rows.length || !board.length) return null;

  return (
    <SectionCard title={title} subtitle={subtitle} bodyClass="p-5">
      <RankedBar
        items={board}
        showRank
        color={color}
        format={magnitude ? magnitudeFormat : (n) => num(Math.round(n))}
        valueLabel={magnitude ? (magnitudeLabel || metricLabel) : metricLabel}
        valueWidth={magnitude ? 88 : 56}
        tooltip={(r) =>
          magnitude
            ? `Flagged ${num(r.count)} time${r.count === 1 ? '' : 's'}`
            : r.peak > 0
              ? `Largest: ${magnitudeFormat(r.peak)}`
              : 'Open the ticket for detail'
        }
        empty="Nothing flagged."
      />
    </SectionCard>
  );
}
