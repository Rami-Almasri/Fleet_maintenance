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
//
// The time window ({days, from, to}) scopes BOTH modes, so the picker means the same thing
// either way: money mode sends it to the ledger (which dates a purchase by purchased_at and a
// line by installed_on/invoice date); volume mode applies it to requested_at here.

import { useEffect, useMemo, useState } from 'react';
import api from '../../api/client';
import { SectionCard } from '../ui/Table';
import RankedBar from '../ui/RankedBar';
import Segmented from '../ui/Segmented';
import DateRangePicker from '../ui/DateRangePicker';
import { aedCompact, num } from '../../lib/format';

const payload = (r) => (r?.data && 'data' in r.data ? r.data.data : r?.data);

const DAY_LABEL = { 30: 'last 30 days', 90: 'last 90 days', 180: 'last 6 months', 365: 'last year' };
const shortDate = (s) => new Date(s).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });

// How the window reads inside the caption sentence ('' = all time, which needs no qualifier).
function windowLabel(w) {
  if (w.from && w.to) return `${shortDate(w.from)} – ${shortDate(w.to)}`;
  if (w.from) return `since ${shortDate(w.from)}`;
  if (w.to) return `until ${shortDate(w.to)}`;
  if (w.days > 0) return DAY_LABEL[w.days] || `last ${num(w.days)} days`;
  return '';
}

export default function PartsAnalytics({ requests = [], showFinancials = false }) {
  const [view, setView] = useState('part');
  const [spend, setSpend] = useState(null);   // null = still loading
  const [meta, setMeta] = useState(null);     // { window, dated } — what the ledger actually counted
  const [win, setWin] = useState({ days: 0, from: '', to: '' });

  // Fleet-wide spend ledger, refetched per view + window. Only when money is actually shown.
  useEffect(() => {
    if (!showFinancials) return undefined;
    let alive = true;
    setSpend(null);
    api
      .get('/part-requests/spend', {
        params: { by: view, limit: 10, days: win.days || undefined, from: win.from || undefined, to: win.to || undefined },
      })
      .then((r) => {
        if (!alive) return;
        const d = payload(r);
        setSpend(d?.rows || []);
        setMeta({ window: d?.window, dated: d?.dated, totals: d?.totals });
      })
      .catch(() => { if (alive) { setSpend([]); setMeta(null); } });
    return () => { alive = false; };
  }, [view, showFinancials, win.days, win.from, win.to]);

  // Request VOLUME over the filtered table — the non-financial ranking. The same window is applied
  // to requested_at, so switching the financial layer off doesn't silently change the time scope.
  const volume = useMemo(() => {
    const fromMs = win.from ? new Date(`${win.from}T00:00:00`).getTime()
      : win.days > 0 ? Date.now() - win.days * 86400000 : null;
    const toMs = win.to ? new Date(`${win.to}T23:59:59`).getTime() : null;

    const inWindow = (r) => {
      if (!fromMs && !toMs) return true;
      if (!r.requested_at) return false;   // undated rows can't honestly sit inside a window
      const t = new Date(r.requested_at).getTime();
      return (!fromMs || t >= fromMs) && (!toMs || t <= toMs);
    };

    const groups = new Map();
    requests.filter(inWindow).forEach((r) => {
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
  }, [requests, view, win.days, win.from, win.to]);

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
  const scope = windowLabel(win);
  const base = money
    ? view === 'car'
      ? 'Cars the fleet has spent the most on in parts — invoiced part lines + purchases, fleet-wide'
      : 'The parts the fleet spends the most on — invoiced part lines + purchases, fleet-wide'
    : view === 'car'
      ? 'Cars needing the most part requests'
      : 'The most frequently requested parts';
  // The window is stated in the caption, never left implicit — a ranked total with an unstated
  // time scope is the easiest number on the page to misread.
  const subtitle = scope ? `${base} · ${scope}` : `${base} · all time`;

  // Data-origin note: how many counted charges carry a real purchase/invoice date, and how many are
  // only dated by the day someone typed them in. Shown whenever a window is actually filtering.
  const entryDated = meta?.dated?.entry ?? 0;
  const datingNote = money && scope && entryDated > 0
    ? `${num(entryDated)} of ${num(meta.dated.total)} charge${meta.dated.total === 1 ? '' : 's'} in this window have no purchase or invoice date — they are placed by the day they were entered.`
    : null;

  return (
    <div className="grid grid-cols-1 gap-4">
      <SectionCard
        title={money ? 'Where parts money goes' : 'What the fleet keeps asking for'}
        subtitle={subtitle}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Segmented
              value={view}
              onChange={setView}
              options={[
                { key: 'part', label: 'By part' },
                { key: 'car', label: 'By car' },
              ]}
            />
            <DateRangePicker days={win.days} from={win.from} to={win.to} onChange={setWin} />
          </div>
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
              ? (spend === null
                  ? 'Loading parts spend…'
                  : scope ? `No parts money recorded in this window (${scope}).` : 'No parts money recorded yet.')
              : scope ? `No part requests in this window (${scope}).` : 'No part requests yet.'
          }
        />

        {datingNote && (
          <p className="mt-3 border-t border-slate-100 pt-3 text-xs text-slate-500">{datingNote}</p>
        )}
      </SectionCard>

    </div>
  );
}
