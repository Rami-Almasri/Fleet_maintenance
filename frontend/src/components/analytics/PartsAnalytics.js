// The chart strip for the Parts board. The status tiles above already count the
// pipeline, so this chart deliberately answers what the tiles can't: what are we
// actually buying, and for which cars? → ranked bar, grouped by part name or by vehicle.
//
// Money does NOT come from this page's request list: most parts the fleet pays for are
// itemised on a garage invoice and never pass through a part request. The spend ranking
// is therefore served fleet-wide by GET /part-requests/spend (PartSpendService = part
// line items + purchases not yet fitted, each dirham counted once). With the financial
// layer off, the same charts rank the FILTERED requests by VOLUME instead — the shape of
// demand, without the prices.

import { useEffect, useMemo, useState } from 'react';
import api from '../../api/client';
import { SectionCard } from '../ui/Table';
import RankedBar from '../ui/RankedBar';
import Segmented from '../ui/Segmented';
import { aedCompact, num } from '../../lib/format';

const payload = (r) => (r?.data && 'data' in r.data ? r.data.data : r?.data);

export default function PartsAnalytics({ requests = [], showFinancials = false }) {
  const [view, setView] = useState('part');
  const [spend, setSpend] = useState(null);   // null = still loading

  // Fleet-wide spend ledger, refetched per view. Only when money is actually shown.
  useEffect(() => {
    if (!showFinancials) return undefined;
    let alive = true;
    setSpend(null);
    api
      .get('/part-requests/spend', { params: { by: view, limit: 10 } })
      .then((r) => { if (alive) setSpend(payload(r)?.rows || []); })
      .catch(() => { if (alive) setSpend([]); });
    return () => { alive = false; };
  }, [view, showFinancials]);

  // Request VOLUME over the filtered table — the non-financial ranking.
  const volume = useMemo(() => {
    const groups = new Map();
    requests.forEach((r) => {
      const key =
        view === 'car'
          ? r.vehicle?.plate || (r.vehicle?.id ? `#${r.vehicle.id}` : 'Unassigned')
          : r.part_name || 'Unnamed part';
      const g = groups.get(key) || {
        key,
        label: key,
        count: 0,
        to: view === 'car' && r.vehicle?.id ? `/vehicles/${r.vehicle.id}` : undefined,
        sub: view === 'car' ? [r.vehicle?.make, r.vehicle?.model].filter(Boolean).join(' ') || undefined : undefined,
      };
      g.count += 1;
      groups.set(key, g);
    });

    return [...groups.values()]
      .filter((g) => g.count > 0)
      .sort((a, b) => b.count - a.count)
      .slice(0, 10)
      .map((g) => ({ ...g, value: g.count }));
  }, [requests, view]);

  // What the bar chart actually plots.
  const board = useMemo(() => {
    if (!showFinancials) return volume;
    return (spend || []).map((g, i) => ({
      ...g,
      key: `${g.label}-${i}`,
      value: g.spend,
      to: view === 'car' && g.vehicle_id ? `/vehicles/${g.vehicle_id}` : undefined,
    }));
  }, [showFinancials, spend, volume, view]);

  const money = showFinancials;
  const subtitle = money
    ? view === 'car'
      ? 'Cars the fleet has spent the most on in parts — invoiced part lines + purchases, fleet-wide'
      : 'The parts the fleet spends the most on — invoiced part lines + purchases, fleet-wide'
    : view === 'car'
      ? 'Cars needing the most part requests'
      : 'The most frequently requested parts';

  return (
    <div className="grid grid-cols-1 gap-4">
      <SectionCard
        title={money ? 'Where parts money goes' : 'What the fleet keeps asking for'}
        subtitle={subtitle}
        actions={
          <Segmented
            value={view}
            onChange={setView}
            options={[
              { key: 'part', label: 'By part' },
              { key: 'car', label: 'By car' },
            ]}
          />
        }
        bodyClass="p-5"
      >
        <RankedBar
          items={board}
          showRank
          color={money ? 'violet' : 'blue'}
          format={money ? aedCompact : (n) => num(Math.round(n))}
          valueLabel={money ? 'Spent' : 'Requests'}
          labelWidth={160}
          valueWidth={money ? 96 : 56}
          tooltip={(r) =>
            money
              ? `${num(r.count)} charge${r.count === 1 ? '' : 's'} recorded`
              : `${num(r.count)} request${r.count === 1 ? '' : 's'}`
          }
          empty={
            money
              ? (spend === null ? 'Loading parts spend…' : 'No parts money recorded yet.')
              : 'No part requests yet.'
          }
        />
      </SectionCard>

    </div>
  );
}
