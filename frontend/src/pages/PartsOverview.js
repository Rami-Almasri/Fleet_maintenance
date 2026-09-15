// Parts → Overview. The tab somebody opens when they are NOT working a row: how much is moving
// through the parts pipeline, what the fleet keeps buying, where the open requests are sitting, and
// what came in most recently. Every number on it is a count of rows on the board next door — this
// page owns no data of its own, so it can never disagree with the board.
//
// WHY THE TILES ARE A FUNNEL, NOT THE BOARD'S STATUS TILES
// The board's tiles count CURRENT status, and they partition: a request is in exactly one of them.
// That answers "what is on my desk". It cannot answer "how much did we approve / buy / fit", because
// a request that was approved, bought and fitted last week now counts only as `completed`. So these
// tiles count STAGES REACHED — approved_at is set, a purchase carries purchased_at, a purchase
// carries installed_at — which is why they overlap and why Approved can exceed Purchased.
//
// THE DELTA IS AN EVENT COUNT, NEVER A GUESS
// "+12% vs last month" compares the events in the last 30 days against the 30 before it, using the
// stage's OWN timestamp. A stage with no timestamp gets no delta: `completed` is a status with no
// recorded date, so its tile shows the count and says so rather than inventing a trend.

import { useCallback, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Icon from '../components/ui/Icon';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import CompositionDonut from '../components/ui/CompositionDonut';
import { SectionCard } from '../components/ui/Table';
import { EmptyState, TableSkeleton } from '../components/ui/Misc';
import PartsAnalytics from '../components/analytics/PartsAnalytics';
import { SHOW_FINANCIALS } from '../config/features';
import { num } from '../lib/format';
import { useI18n } from '../i18n/I18nContext';
import { ALL_STATUSES, CLASS_LABEL, CLASS_TONE, STATUS_LABEL, STATUS_TONE } from './Parts';

const payload = (r) => (r?.data && 'data' in r.data ? r.data.data : r?.data);

const DAY = 86400000;
const WINDOW_DAYS = 30;

const shortDate = (s) => (s ? new Date(s).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '—');

// The earliest recorded moment of `field` across a request's purchases — a request split over two
// purchases reached the stage when the FIRST one did.
const purchaseAt = (r, field) => {
  const times = (r.purchases || []).map((p) => p?.[field]).filter(Boolean).sort();
  return times[0] || null;
};

// The funnel. `at` returns the moment this request reached the stage, or null if it never did (and
// null for a stage the data does not date — see the header note).
// Badge tone → chart palette key. The two vocabularies overlap but are not the same set: Badge has
// `green`/`gray`, the chart palette has `emerald`/`slate`, and an unmapped key silently falls back to
// the brand gold — which would paint half the ring the same colour. `violet` goes to `purple` for the
// same reason: in this brand `violet` IS the gold, so Purchased and Installed would be twins.
const ARC_COLOR = { green: 'emerald', gray: 'slate', violet: 'purple' };

const STAGES = [
  {
    key: 'requested',
    label: 'Total Requests',
    tone: 'blue',
    icon: <Icon.Coins className="h-5 w-5" />,
    filter: '',
    at: (r) => r.requested_at || r.created_at || null,
    reached: (r) => true,
  },
  {
    key: 'approved',
    label: 'Approved',
    tone: 'emerald',
    icon: <Icon.Check className="h-5 w-5" />,
    filter: 'approved',
    at: (r) => r.approved_at || null,
    reached: (r) => !!r.approved_at,
  },
  {
    key: 'purchased',
    label: 'Purchased',
    tone: 'amber',
    icon: <Icon.Cash className="h-5 w-5" />,
    filter: 'purchased',
    at: (r) => purchaseAt(r, 'purchased_at'),
    reached: (r) => (r.purchases || []).some((p) => p?.purchased_at),
  },
  {
    key: 'installed',
    label: 'Installed',
    tone: 'violet',
    icon: <Icon.Cog className="h-5 w-5" />,
    filter: 'installed',
    at: (r) => purchaseAt(r, 'installed_at'),
    reached: (r) => (r.purchases || []).some((p) => p?.installed_at),
  },
  {
    key: 'completed',
    label: 'Completed',
    tone: 'green',
    icon: <Icon.Check className="h-5 w-5" />,
    filter: 'completed',
    // Completion is a status, not a dated event — so this tile counts, and refuses to trend.
    at: () => null,
    reached: (r) => r.status === 'completed',
  },
];

export default function PartsOverview() {
  const { t, tf } = useI18n();
  const [, setParams] = useSearchParams();

  // The same call the board makes, so the two tabs can never show different totals.
  const fetcher = useCallback(async () => {
    const r = await api.get('/part-requests', { params: { per_page: 200 } });
    return payload(r) || {};
  }, []);
  const { data, loading, error } = useFetch(fetcher, [], { refreshInterval: 60000 });

  const requests = useMemo(() => data?.requests || [], [data]);

  // Hand a tile or a donut slice to the board, pre-filtered. TabbedHub keeps the active tab in
  // ?tab, and Parts reads ?status — so one navigation does both.
  const openBoard = (status) => setParams(status ? { tab: 'board', status } : { tab: 'board' }, { replace: false });

  const tiles = useMemo(() => {
    const now = Date.now();
    const thisFrom = now - WINDOW_DAYS * DAY;
    const prevFrom = now - 2 * WINDOW_DAYS * DAY;

    return STAGES.map((s) => {
      const reached = requests.filter(s.reached);
      const count = reached.length;

      // Undated stage → count only. Never a pill.
      const stamps = reached.map(s.at).filter(Boolean).map((v) => new Date(v).getTime());
      if (!stamps.length) {
        return { ...s, count, delta: null, trend: 'flat', hint: t('No date is recorded for this step') };
      }

      const recent = stamps.filter((ms) => ms >= thisFrom).length;
      const prior = stamps.filter((ms) => ms >= prevFrom && ms < thisFrom).length;
      const hint = t('{n} in the last 30 days', { n: num(recent) });

      // A percentage against zero is not a percentage. Say the movement in plain counts instead.
      if (prior === 0) {
        return { ...s, count, delta: null, trend: 'flat', hint };
      }
      const pct = Math.round(((recent - prior) / prior) * 100);
      return {
        ...s,
        count,
        delta: `${pct > 0 ? '+' : ''}${pct}%`,
        trend: pct > 0 ? 'up' : pct < 0 ? 'down' : 'flat',
        hint,
      };
    });
  }, [requests, t]);

  // Where every request currently sits — this one DOES partition, which is why the hole can carry
  // the fleet-wide total.
  const statusSegments = useMemo(() => {
    const counts = new Map();
    requests.forEach((r) => counts.set(r.status, (counts.get(r.status) || 0) + 1));
    return ALL_STATUSES.filter((s) => counts.get(s))
      .map((s) => ({
        key: s,
        label: tf(`parts.status.${s}`, STATUS_LABEL[s] || s),
        value: counts.get(s),
        color: ARC_COLOR[STATUS_TONE[s]] || STATUS_TONE[s] || 'slate',
      }));
  }, [requests, tf]);

  const recent = useMemo(
    () =>
      [...requests]
        .sort((a, b) => new Date(b.requested_at || b.created_at || 0) - new Date(a.requested_at || a.created_at || 0))
        .slice(0, 5),
    [requests],
  );

  return (
    <div className="space-y-6">
      {error && (
        <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
      )}

      {/* The funnel. Each tile opens the board filtered to that step. */}
      <MetricGrid cols={5}>
        {tiles.map((s) => (
          <MetricCard
            key={s.key}
            label={t(s.label)}
            value={loading ? '…' : num(s.count)}
            icon={s.icon}
            tone={s.tone}
            delta={s.delta}
            trend={s.trend}
            hint={loading ? undefined : s.hint}
            tooltip={
              s.key === 'requested'
                ? t('Every part request on the board, all time.')
                : t('Requests that have reached this step, all time. Steps overlap — a part that was fitted was also approved.')
            }
            onClick={() => openBoard(s.filter)}
          />
        ))}
      </MetricGrid>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        {/* What the fleet keeps asking for / where parts money goes — the board's own ranking,
            fleet-wide rather than scoped to a filtered table, because nothing is filtered here. */}
        <div className="lg:col-span-2">
          <PartsAnalytics requests={requests} showFinancials={SHOW_FINANCIALS} />
        </div>

        <SectionCard
          title={t('Requests by status')}
          subtitle={t('Where every part request is sitting right now')}
          bodyClass="p-5"
        >
          {loading ? (
            <div className="h-56 animate-pulse rounded-xl bg-slate-100" />
          ) : statusSegments.length === 0 ? (
            <EmptyState title={t('No part requests yet')} />
          ) : (
            <CompositionDonut
              segments={statusSegments}
              format={(n) => num(Math.round(n))}
              centerLabel={t('Total requests')}
              showValue
              onSelect={(seg) => openBoard(seg.key)}
            />
          )}
        </SectionCard>
      </div>

      <SectionCard
        title={t('Recent part requests')}
        subtitle={t('The latest five, and where each one has got to')}
        actions={
          <Button variant="secondary" size="sm" onClick={() => openBoard('')}>
            {t('View all')}
          </Button>
        }
      >
        <div className="overflow-x-auto">
          <table className="min-w-full border-separate border-spacing-0 text-sm">
            <thead className="bg-slate-50/90">
              <tr className="text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('parts.cols.vehicle')}</th>
                <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('parts.cols.part')}</th>
                <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('parts.cols.source')}</th>
                <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('parts.cols.status')}</th>
                <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('Requested by')}</th>
                <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('parts.cols.requested')}</th>
                <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-end">{t('parts.cols.actions')}</th>
              </tr>
            </thead>

            {loading ? (
              <TableSkeleton cols={7} rows={5} />
            ) : (
              <tbody>
                {recent.map((r) => (
                  <tr key={r.id} className="bg-white transition-colors even:bg-slate-50/40 hover:bg-indigo-50/40">
                    <td className="border-b border-slate-100 px-5 py-3.5">
                      <div className="font-medium text-slate-900">{r.vehicle?.plate || (r.vehicle?.id ? `#${r.vehicle.id}` : '—')}</div>
                      <div className="text-xs text-slate-400">{[r.vehicle?.make, r.vehicle?.model].filter(Boolean).join(' ') || '—'}</div>
                    </td>
                    <td className="border-b border-slate-100 px-5 py-3.5">
                      <div className="flex items-center gap-2">
                        <span className="font-medium text-slate-900">{r.part_name}</span>
                        {r.part_class && (
                          <Badge tone={CLASS_TONE[r.part_class] || 'gray'}>
                            {tf(`parts.class.${r.part_class}`, CLASS_LABEL[r.part_class] || r.part_class)}
                          </Badge>
                        )}
                      </div>
                      {r.part_number && <div className="text-xs text-slate-400">{r.part_number}</div>}
                    </td>
                    <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">
                      {r.source ? tf(`parts.source.${r.source}`, r.source) : '—'}
                    </td>
                    <td className="border-b border-slate-100 px-5 py-3.5">
                      <Badge tone={STATUS_TONE[r.status] || 'gray'}>{tf(`parts.status.${r.status}`, STATUS_LABEL[r.status] || r.status)}</Badge>
                    </td>
                    <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">{r.requested_by || '—'}</td>
                    <td className="whitespace-nowrap border-b border-slate-100 px-5 py-3.5 text-slate-600">{shortDate(r.requested_at || r.created_at)}</td>
                    <td className="border-b border-slate-100 px-5 py-3.5 text-end">
                      <Button
                        variant="ghost"
                        size="sm"
                        className="text-indigo-600 hover:bg-indigo-50"
                        onClick={() => setParams({ tab: 'board', focus: String(r.id) }, { replace: false })}
                      >
                        {t('Open')}
                      </Button>
                    </td>
                  </tr>
                ))}
                {recent.length === 0 && (
                  <tr>
                    <td colSpan={7} className="px-5 py-10">
                      <EmptyState title={t('No part requests yet')} message={t('parts.startFromTicket')} />
                    </td>
                  </tr>
                )}
              </tbody>
            )}
          </table>
        </div>
      </SectionCard>

      {/* Data origin — what these numbers are counted from, said on the page that shows them. */}
      <p className="text-xs text-slate-400">
        {t('Counted from the part requests board (the most recent 200 requests) and the purchases recorded against them. The spend ranking is fleet-wide and comes from the parts ledger.')}
      </p>
    </div>
  );
}
