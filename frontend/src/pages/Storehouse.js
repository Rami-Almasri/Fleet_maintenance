import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { useToast } from '../components/ui/Toast';
import { usePermissions } from '../hooks/usePermissions';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Icon from '../components/ui/Icon';
import Modal from '../components/ui/Modal';
import SearchSelect from '../components/ui/SearchSelect';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import { MetricGridSkeleton } from '../components/ui/Skeleton';
import { EmptyState, ErrorState, SearchInput } from '../components/ui/Misc';
import { Input, Select, Textarea } from '../components/ui/Field';
import CatalogPartPicker from '../components/parts/CatalogPartPicker';
import { aed2, fmtAgo, num } from '../lib/format';
import { useI18n } from '../i18n/I18nContext';

/**
 * The STOREHOUSE — the fleet's own shelf.
 *
 * The page exists because a part does not have to be bought for a car that is already in the
 * workshop. Filters, bulbs, pads and belts are bought in tens when they are cheap and fitted months
 * later to whichever car needs one. Three tabs, in the order the stock actually moves:
 *
 *   WHAT WE HAVE — every shelf as a card: how many are left, whether that is few, what it is worth.
 *   ON ITS WAY   — "we need this in stock", with no car attached: asked → approved → received.
 *                  RECEIVING is the step that raises a shelf; nothing before it moves a unit.
 *   HISTORY      — every unit in and out, with the car it went to. The evidence behind every level.
 *
 * READ TOP-DOWN, and in that order deliberately: one sentence saying what the store is, then the
 * short list of things waiting for a person, then three figures, and only then the tabs. The page
 * used to open with five metric tiles and a row of five controls sharing a line, which meant the
 * first thing a newcomer met was arithmetic and the most important control (the view switcher) was
 * the least visible thing on the screen. Numbers that carry an action now live in "Needs someone"
 * as a sentence and a button; the tiles keep only the three facts nobody has to act on.
 *
 * Nothing on this page can hand a part to a car. That happens from the ticket, where the fault and
 * the odometer are, and it is what keeps one write path onto a vehicle.
 */

const payload = (r) => (r?.data && 'data' in r.data ? r.data.data : r?.data);

const REQUEST_TONE = {
  requested: 'slate', approved: 'cyan', ordered: 'violet',
  received: 'green', rejected: 'red', cancelled: 'gray',
};

/** Reason keys → the sentence a person would say. Resolved through t() at render. */
const MOVEMENT_LABEL = {
  opening: 'Opening count', receipt: 'Received', return_from_vehicle: 'Returned unused',
  issue: 'Issued to a job', write_off: 'Written off', adjustment: 'Count corrected',
};

/** The three views, each with the one sentence that says what you are looking at. */
const VIEWS = [
  { key: 'shelf', label: 'What we have', blurb: 'Every part we hold right now, and how many are left of each.' },
  { key: 'requests', label: 'On its way', blurb: 'Parts somebody asked the store to buy. Nothing reaches a shelf until it is received here.' },
  { key: 'movements', label: 'History', blurb: 'Every unit that came in or went out, newest first — the evidence behind every count above.' },
];

const qty = (v) => (v === null || v === undefined ? '—' : num(Number(v)));

export default function Storehouse() {
  const { t } = useI18n();
  const toast = useToast();
  const { can } = usePermissions();
  const canRequest = can('parts.request');
  const canMove = can('parts.purchase');
  const canReview = can('parts.investigate') || can('maintenance.manage');
  // Correcting a COST is a higher bar than moving stock: the authority that keyed a wrong price must
  // not also be the one that erases it. See StoreService::correctPrice().
  const canReprice = can('parts.investigate') || can('maintenance.manage');

  const [view, setView] = useState('shelf');
  const [q, setQ] = useState('');
  const [onlyLow, setOnlyLow] = useState(false);
  const [showFinished, setShowFinished] = useState(false);
  const [flow, setFlow] = useState('all'); // movements: all | in | out

  // Modals hold a row — pause the background refresh while any is open so nothing shifts underneath.
  const [receiveOpen, setReceiveOpen] = useState(false);
  const [askOpen, setAskOpen] = useState(false);
  const [adjustFor, setAdjustFor] = useState(null);
  const [receiveReq, setReceiveReq] = useState(null);
  const [rejectReq, setRejectReq] = useState(null);
  const [repriceFor, setRepriceFor] = useState(null);
  const [detailId, setDetailId] = useState(null);
  const anyModal = receiveOpen || askOpen || !!adjustFor || !!receiveReq || !!rejectReq || !!repriceFor || !!detailId;

  const fetcher = useCallback(async () => {
    const [items, requests, movements] = await Promise.all([
      api.get('/store/items'),
      api.get('/store/stock-requests', { params: { per_page: 100 } }),
      api.get('/store/movements', { params: { per_page: 100 } }),
    ]);
    return {
      ...(payload(items) || {}),
      requests: payload(requests)?.requests || [],
      movements: payload(movements)?.movements || [],
    };
  }, []);
  const { data, loading, error, reload } = useFetch(fetcher, [], { refreshInterval: 60000, paused: () => anyModal });

  const items = useMemo(() => data?.items || [], [data]);
  const requests = useMemo(() => data?.requests || [], [data]);
  const movements = useMemo(() => data?.movements || [], [data]);
  const summary = data?.summary || {};

  const shelves = useMemo(() => {
    const needle = q.trim().toLowerCase();
    return items.filter((i) => {
      if (onlyLow && !i.is_low) return false;
      if (!needle) return true;
      return [i.part_name, i.part_number, i.location, i.catalog_name]
        .filter(Boolean)
        .some((v) => String(v).toLowerCase().includes(needle));
    });
  }, [items, q, onlyLow]);

  const openRequests = useMemo(() => requests.filter((r) => !['received', 'rejected', 'cancelled'].includes(r.status)), [requests]);
  const shownRequests = showFinished ? requests : openRequests;
  const waitingDecision = useMemo(() => requests.filter((r) => r.status === 'requested'), [requests]);
  const waitingArrival = useMemo(() => requests.filter((r) => ['approved', 'ordered'].includes(r.status)), [requests]);
  const shownMovements = useMemo(
    () => (flow === 'all' ? movements : movements.filter((m) => m.direction === flow)),
    [movements, flow],
  );

  const act = async (url, body, okMsg) => {
    try {
      await api.post(url, body || {});
      toast.success(okMsg);
      reload({ silent: true });
      return true;
    } catch (err) {
      const res = err.response?.data;
      toast.error(res?.message || res?.msg || t('Action failed'));
      return false;
    }
  };

  // The three things a person can actually DO here, in the order the stock moves. Each one is a
  // sentence and a button, not a number to interpret — a count on a tile tells you something is
  // wrong but not what to press, which is the whole reason this page read as a wall of figures.
  const todo = useMemo(() => {
    const rows = [];
    if (canReview && waitingDecision.length) {
      rows.push({
        key: 'decide',
        tone: 'amber',
        icon: <Icon.Clock className="h-5 w-5" />,
        text: t('{n} stock requests are waiting for someone to say yes or no.', { n: num(waitingDecision.length) }),
        cta: t('Review them'),
        onClick: () => { setView('requests'); setShowFinished(false); },
      });
    }
    if (canMove && waitingArrival.length) {
      rows.push({
        key: 'receive',
        tone: 'cyan',
        icon: <Icon.Truck className="h-5 w-5" />,
        text: t('{n} approved parts have not been put on a shelf yet.', { n: num(waitingArrival.length) }),
        cta: t('Receive them'),
        onClick: () => { setView('requests'); setShowFinished(false); },
      });
    }
    if (summary.low_count > 0) {
      rows.push({
        key: 'low',
        tone: 'red',
        icon: <Icon.Alert className="h-5 w-5" />,
        text: t('{n} parts have dropped to the level where someone asked to be told.', { n: num(summary.low_count) }),
        cta: t('Show me which'),
        onClick: () => { setView('shelf'); setOnlyLow(true); setQ(''); },
      });
    }
    return rows;
  }, [t, canReview, canMove, waitingDecision.length, waitingArrival.length, summary.low_count]);

  if (error) return <ErrorState message={t('Could not load the storehouse.')} onRetry={reload} />;

  const blurb = VIEWS.find((v) => v.key === view)?.blurb;

  return (
    <div className="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
      {/* One plain sentence, before any number. Nobody arriving here for the first time knows what
          a "storehouse" is in this app, and the answer is short enough to just say. */}
      <div className="rounded-2xl border border-slate-200/60 bg-white px-5 py-4 shadow-soft">
        <h2 className="font-display text-lg font-bold text-slate-900">{t('Storehouse')}</h2>
        <p className="mt-1 max-w-3xl text-sm text-slate-500">
          {t('Parts we buy and keep here in advance, so a car that needs one does not have to wait for a shop run. A part leaves only when a job takes it, and the History tab says which car got it.')}
        </p>
      </div>

      <TodoPanel rows={todo} loading={loading && !data} />

      {loading && !data ? (
        <MetricGridSkeleton count={3} />
      ) : (
        <MetricGrid cols={3}>
          <MetricCard
            label={t('Different parts in stock')}
            value={num(summary.in_stock_count ?? 0)}
            icon={<Icon.Box className="h-5 w-5" />}
            tone="indigo"
            hint={t('{n} part types tracked in total', { n: num(summary.item_count ?? 0) })}
          />
          <MetricCard
            label={t('Pieces on the shelves')}
            value={num(summary.units_on_hand ?? 0)}
            icon={<Icon.Coins className="h-5 w-5" />}
            hint={t('Everything physically in the storehouse right now')}
          />
          {/* A sum over what is KNOWN, never a claim of completeness — a shelf counted but never
              costed contributes nothing, and the hint says how many those are. */}
          <MetricCard
            label={t('What it is all worth')}
            value={aed2(summary.stock_value ?? 0)}
            icon={<Icon.Cash className="h-5 w-5" />}
            tone="emerald"
            hint={summary.uncosted_items
              ? t('{n} shelves have stock but no recorded cost — they are not in this figure', { n: num(summary.uncosted_items) })
              : t('At the average price we paid')}
          />
        </MetricGrid>
      )}

      <div className="overflow-hidden rounded-2xl border border-slate-200/60 bg-white shadow-soft">
        {/* The view switcher gets its own row and real size. It used to sit at the end of a line of
            five controls, which made the most important choice on the page the least visible one. */}
        <div className="flex items-center gap-1 overflow-x-auto border-b border-slate-100 px-3">
          {VIEWS.map((v) => {
            const on = v.key === view;
            const count = { shelf: items.length, requests: openRequests.length, movements: movements.length }[v.key];
            return (
              <button
                key={v.key}
                type="button"
                role="tab"
                aria-selected={on}
                onClick={() => setView(v.key)}
                className={`-mb-px shrink-0 border-b-2 px-4 py-3 text-sm font-semibold transition ${
                  on ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:text-slate-800'
                }`}
              >
                {t(v.label)}
                <span className={`ms-2 rounded-full px-2 py-0.5 text-xs tabular-nums ${on ? 'bg-indigo-50 text-indigo-700' : 'bg-slate-100 text-slate-500'}`}>
                  {num(count)}
                </span>
              </button>
            );
          })}
        </div>

        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3">
          <p className="max-w-md text-xs text-slate-500">{t(blurb)}</p>

          <div className="flex flex-wrap items-center justify-end gap-2">
            {view === 'shelf' && (
              <>
                <SearchInput className="w-full sm:w-56" value={q} onChange={setQ} placeholder={t('Part, number, shelf…')} />
                <Button variant={onlyLow ? 'primary' : 'secondary'} size="sm" onClick={() => setOnlyLow((v) => !v)}>
                  {onlyLow ? t('Showing only low stock') : t('Only what is low')}
                </Button>
              </>
            )}
            {view === 'requests' && requests.length > openRequests.length && (
              <Button variant={showFinished ? 'primary' : 'secondary'} size="sm" onClick={() => setShowFinished((v) => !v)}>
                {t('Include finished ({n})', { n: num(requests.length - openRequests.length) })}
              </Button>
            )}
            {view === 'movements' && (
              <div className="inline-flex rounded-lg bg-slate-100 p-0.5">
                {[['all', t('Everything')], ['in', t('Came in')], ['out', t('Went out')]].map(([key, label]) => (
                  <button
                    key={key}
                    type="button"
                    onClick={() => setFlow(key)}
                    className={`rounded-[6px] px-2.5 py-1 text-xs font-semibold transition ${
                      flow === key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'
                    }`}
                  >
                    {label}
                  </button>
                ))}
              </div>
            )}
            {canRequest && (
              <Button size="sm" variant="secondary" onClick={() => setAskOpen(true)}>
                {t('Ask the store to buy one')}
              </Button>
            )}
            {canMove && (
              <Button size="sm" onClick={() => setReceiveOpen(true)}>
                <Icon.Plus className="me-1 h-4 w-4" />
                {t('Put stock in')}
              </Button>
            )}
          </div>
        </div>

        <div className="p-4 sm:p-5">
          {view === 'shelf' && (
            items.length === 0 && !loading ? (
              <EmptyState
                title={t('Nothing is in the store yet')}
                message={t('Nothing has been booked in yet. Use “Put stock in” for what is already on the shelf, or request a part so it is bought into the store.')}
              />
            ) : shelves.length === 0 ? (
              <EmptyState title={t('Nothing matches')} message={onlyLow ? t('No part is running low right now — a good place to be.') : t('No shelf matches that.')} />
            ) : (
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {shelves.map((item) => (
                  <ShelfCard
                    key={item.id}
                    item={item}
                    canMove={canMove}
                    canReprice={canReprice}
                    onOpen={() => setDetailId(item.id)}
                    onAdjust={() => setAdjustFor(item)}
                    onReprice={() => setRepriceFor(item)}
                  />
                ))}
              </div>
            )
          )}

          {view === 'requests' && (
            shownRequests.length === 0 ? (
              <EmptyState
                title={requests.length ? t('Nothing is waiting') : t('No stock requests yet')}
                message={requests.length
                  ? t('Every request has been dealt with. Turn on “Include finished” to see the ones already closed.')
                  : t('A stock request asks for a part to be put on the shelf — no car, no ticket. It moves no stock until someone receives it.')}
              />
            ) : (
              <ul className="space-y-2.5">
                {shownRequests.map((r) => (
                  <RequestRow
                    key={r.id}
                    request={r}
                    canReview={canReview}
                    canMove={canMove}
                    onApprove={() => act(`/store/stock-requests/${r.id}/approve`, {}, t('Stock request approved'))}
                    onReject={() => setRejectReq(r)}
                    onReceive={() => setReceiveReq(r)}
                  />
                ))}
              </ul>
            )
          )}

          {view === 'movements' && (
            shownMovements.length === 0 ? (
              <EmptyState title={t('Nothing has moved yet.')} />
            ) : (
              <ul className="divide-y divide-slate-100">
                {shownMovements.map((m) => <MovementRow key={m.id} movement={m} />)}
              </ul>
            )
          )}
        </div>
      </div>

      <ReceiveStockModal open={receiveOpen} onClose={() => setReceiveOpen(false)} onDone={reload} />
      <AskForStockModal open={askOpen} onClose={() => setAskOpen(false)} onDone={reload} />
      <AdjustModal item={adjustFor} onClose={() => setAdjustFor(null)} onDone={reload} />
      <CorrectPriceModal item={repriceFor} onClose={() => setRepriceFor(null)} onDone={reload} />
      <ReceiveRequestModal request={receiveReq} onClose={() => setReceiveReq(null)} onDone={reload} />
      <RejectRequestModal
        request={rejectReq}
        onClose={() => setRejectReq(null)}
        onSubmit={(reason) => act(`/store/stock-requests/${rejectReq.id}/reject`, { reason }, t('Stock request rejected'))}
      />
      <ShelfDrawerModal itemId={detailId} onClose={() => setDetailId(null)} />
    </div>
  );
}

/**
 * "Is there anything for me to do?" — answered before any figure on the page.
 *
 * Deliberately silent when there is nothing: an empty attention panel that still occupies a card is
 * noise, and the one quiet line it leaves behind is the reassurance that the silence is real and not
 * a page that failed to load.
 */
function TodoPanel({ rows, loading }) {
  const { t } = useI18n();
  if (loading) return <div className="h-16 animate-pulse rounded-2xl bg-slate-100" />;

  if (!rows.length) {
    return (
      <div className="flex items-center gap-2.5 rounded-2xl border border-emerald-200/70 bg-emerald-50/60 px-5 py-3.5 text-sm text-emerald-800">
        <Icon.Check className="h-5 w-5 shrink-0" />
        {t('Nothing needs doing — nothing is waiting for a decision and nothing is running low.')}
      </div>
    );
  }

  const TONE = {
    amber: 'bg-amber-50 text-amber-700 ring-amber-600/20',
    cyan: 'bg-cyan-50 text-cyan-700 ring-cyan-600/20',
    red: 'bg-rose-50 text-rose-700 ring-rose-600/20',
  };

  return (
    <div className="overflow-hidden rounded-2xl border border-slate-200/60 bg-white shadow-soft">
      <div className="border-b border-slate-100 px-5 py-3">
        <h3 className="text-sm font-semibold text-slate-900">{t('Needs someone')}</h3>
      </div>
      <ul className="divide-y divide-slate-100">
        {rows.map((r) => (
          <li key={r.key} className="flex flex-wrap items-center gap-3 px-5 py-3.5">
            <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ring-1 ring-inset ${TONE[r.tone] || TONE.amber}`}>
              {r.icon}
            </span>
            <p className="min-w-0 flex-1 text-sm text-slate-700">{r.text}</p>
            <Button size="sm" variant="secondary" onClick={r.onClick}>
              {r.cta}
              <Icon.ArrowRight className="ms-1 h-4 w-4" />
            </Button>
          </li>
        ))}
      </ul>
    </div>
  );
}

/**
 * One shelf, as a card rather than a table row.
 *
 * The six numeric columns this replaces were all true and all unreadable at a glance. A card can
 * lead with the ONE number anyone came for — how many are left — and let the bar carry "is that a
 * lot or a little", which is the question the old "Reorder at" column asked people to answer in
 * their head, column by column.
 */
function ShelfCard({ item, canMove, canReprice, onOpen, onAdjust, onReprice }) {
  const { t } = useI18n();
  const on = Number(item.qty_on_hand) || 0;
  const min = item.min_qty === null ? null : Number(item.min_qty);
  const out = on <= 0;

  // Full bar = twice the reorder level, so the reorder point sits at the halfway mark and "below
  // half" reads as "order more" without anybody having to compare two numbers.
  const pct = min && min > 0 ? Math.max(2, Math.min(100, (on / (min * 2)) * 100)) : null;
  const barTone = out ? 'bg-rose-500' : item.is_low ? 'bg-amber-500' : 'bg-emerald-500';

  const sub = [item.part_number, item.location && t('Shelf {location}', { location: item.location })]
    .filter(Boolean).join(' · ');

  return (
    <div
      role="button"
      tabIndex={0}
      onClick={onOpen}
      onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onOpen(); } }}
      className={`flex cursor-pointer flex-col rounded-2xl border bg-white p-4 text-start shadow-soft outline-none transition hover:-translate-y-0.5 hover:shadow-md focus:ring-4 focus:ring-indigo-500/10 ${
        out ? 'border-rose-200' : item.is_low ? 'border-amber-200' : 'border-slate-200/70 hover:border-indigo-200'
      }`}
    >
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <div className="truncate font-semibold text-slate-900">{item.part_name}</div>
          <div className="truncate text-xs text-slate-400">{sub || t('No part number or shelf recorded')}</div>
        </div>
        {out ? <Badge tone="red">{t('None left')}</Badge> : item.is_low ? <Badge tone="amber">{t('Running low')}</Badge> : null}
      </div>

      <div className="mt-4 flex items-baseline gap-2">
        <span className={`font-display text-3xl font-bold leading-none tabular-nums ${out ? 'text-rose-600' : 'text-slate-900'}`}>
          {qty(item.qty_on_hand)}
        </span>
        <span className="text-xs text-slate-500">{t('on the shelf')}</span>
      </div>

      <div className="mt-2.5">
        {pct === null ? (
          // A shelf with no level set is NOT "0" — nobody has said what enough means for it, and the
          // low-stock count deliberately ignores it. Saying so is what stops the card from reading
          // as broken when it never turns amber.
          <p className="text-xs text-slate-400">{t('Nobody has said when this is too few')}</p>
        ) : (
          <>
            <div className="h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
              <div className={`h-full rounded-full ${barTone}`} style={{ width: `${pct}%` }} />
            </div>
            <p className="mt-1.5 text-xs text-slate-400">{t('Tell someone when it reaches {n}', { n: num(min) })}</p>
          </>
        )}
      </div>

      <div className="mt-3 flex items-center justify-between gap-2 border-t border-slate-100 pt-3">
        {item.avg_unit_cost === null ? (
          <span className="text-xs text-slate-400" title={t('No priced receipt stands behind this shelf, so a unit off it carries no cost.')}>
            {t('Price unknown')}
          </span>
        ) : (
          <span className="text-xs text-slate-500">
            {t('{price} each', { price: aed2(item.avg_unit_cost) })}
            {' · '}
            <span className="font-semibold text-slate-700">{aed2(item.stock_value ?? 0)}</span>
          </span>
        )}
        <div className="flex shrink-0 gap-1.5">
          {/* Cost and count are separate corrections behind separate permissions — see
              CorrectPriceModal for why they are not one button. */}
          {canReprice && (
            <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); onReprice(); }}>
              {t('Fix the price')}
            </Button>
          )}
          {canMove && (
            <Button size="sm" variant="secondary" onClick={(e) => { e.stopPropagation(); onAdjust(); }}>
              {t('Fix the count')}
            </Button>
          )}
        </div>
      </div>
    </div>
  );
}

function statusLabel(t, status) {
  return {
    // Said as where the request has GOT to, not as a database state. "Approved" answers a question
    // nobody asked; "waiting to be bought" answers the one they did.
    requested: t('Waiting for a decision'), approved: t('Approved — not here yet'), ordered: t('Ordered — not here yet'),
    received: t('On the shelf'), rejected: t('Turned down'), cancelled: t('Cancelled'),
  }[status] || status;
}

/**
 * One stock request as a sentence somebody would say out loud, with the buttons that move it on.
 *
 * The table this replaces spread the same facts over six columns — asked for, on shelf now, price,
 * status, who, when — and left the reader to reassemble them. The one comparison that actually
 * decides the answer ("ten more, when we already hold forty?") now sits in the sentence itself.
 */
function RequestRow({ request: r, canReview, canMove, onApprove, onReject, onReceive }) {
  const { t } = useI18n();
  const open = !['received', 'rejected', 'cancelled'].includes(r.status);

  const facts = [
    r.requested_by_name ? t('asked by {name}', { name: r.requested_by_name }) : null,
    r.requested_at ? fmtAgo(r.requested_at) : null,
    r.on_hand_now === null
      ? t('new to the store')
      : t('the shelf holds {n} right now', { n: num(r.on_hand_now) }),
    r.estimated_price === null ? null : t('about {price} each', { price: aed2(r.estimated_price) }),
  ].filter(Boolean);

  return (
    <li className={`flex flex-wrap items-start gap-3 rounded-2xl border p-4 ${open ? 'border-slate-200/70 bg-white' : 'border-slate-200/50 bg-slate-50/60'}`}>
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <span className="font-semibold text-slate-900">
            {t('{n} × {part}', { n: num(r.quantity), part: r.part_name })}
          </span>
          <Badge tone={REQUEST_TONE[r.status] || 'gray'}>{statusLabel(t, r.status)}</Badge>
        </div>

        <p className="mt-1 text-xs text-slate-500">{facts.join(' · ')}</p>

        {r.reason && <p className="mt-1.5 text-sm text-slate-600">{r.reason}</p>}
        {r.rejection_reason && (
          <p className="mt-1.5 text-sm text-rose-600">{t('Turned down: {reason}', { reason: r.rejection_reason })}</p>
        )}
      </div>

      <div className="flex shrink-0 flex-wrap gap-1.5">
        {canReview && r.status === 'requested' && (
          <>
            <Button size="sm" onClick={onApprove}>{t('Yes, buy it')}</Button>
            <Button size="sm" variant="danger" onClick={onReject}>{t('No')}</Button>
          </>
        )}
        {canMove && ['approved', 'ordered'].includes(r.status) && (
          <Button size="sm" onClick={onReceive}>{t('It arrived')}</Button>
        )}
      </div>
    </li>
  );
}

/**
 * One movement as a line of the story: what happened, to what, and where it went.
 *
 * Eight columns became one sentence and one signed number. The signed number keeps its column
 * position so a run of them still scans vertically — that is the only part of a table worth keeping
 * for a ledger this simple.
 */
function MovementRow({ movement: m }) {
  const { t } = useI18n();
  const inbound = m.direction === 'in';

  const where = m.vehicle
    ? <Link to={`/vehicles/${m.vehicle.id}?tab=components`} onClick={(e) => e.stopPropagation()} className="font-medium text-indigo-600 hover:underline">{m.vehicle.plate}</Link>
    : m.supplier?.name || null;

  return (
    <li className="flex items-start gap-3 py-3">
      <span className={`mt-0.5 inline-flex h-8 min-w-[3rem] shrink-0 items-center justify-center rounded-lg px-2 text-sm font-bold tabular-nums ${
        inbound ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-600'
      }`}>
        {inbound ? '+' : '−'}{qty(m.quantity)}
      </span>

      <div className="min-w-0 flex-1">
        <p className="text-sm text-slate-800">
          <span className="font-semibold">{MOVEMENT_LABEL[m.reason] ? t(MOVEMENT_LABEL[m.reason]) : m.reason}</span>
          {' — '}
          {m.item?.part_name || '—'}
          {where && <> {' · '} {where}</>}
        </p>

        <p className="mt-0.5 text-xs text-slate-400">
          {fmtAgo(m.occurred_at)}
          {m.actor_name ? ` · ${m.actor_name}` : ''}
          {` · ${t('{n} left on the shelf', { n: qty(m.qty_after) })}`}
          {m.value === null ? '' : ` · ${aed2(m.value)}`}
        </p>

        {m.note && <p className="mt-0.5 text-xs text-slate-500">{m.note}</p>}

        {/* Present only on receipts that moved this shelf's price far enough to need accounting for.
            It belongs in the ledger, not buried in the form that captured it — the whole point is
            that a later reader can see why the part suddenly cost what it cost. */}
        {m.price_variance_note && (
          <p className="mt-1 rounded-lg bg-amber-50 px-2 py-1 text-xs text-amber-800">
            {t('Price changed: {why}', { why: m.price_variance_note })}
          </p>
        )}

        {/* Every receipt carries its bill. The in-movements that legitimately have none say which
            they are, so a blank never has to be read as a missing document. */}
        <div className="mt-0.5 text-xs">
          {m.invoice ? (
            <Link to={`/parts?tab=invoices&focus=${m.invoice.id}`} className="font-medium text-indigo-600 hover:underline">
              {t('Invoice {no}', { no: m.invoice.invoice_no || `#${m.invoice.id}` })}
            </Link>
          ) : m.reason === 'opening' ? (
            <span className="text-slate-400">{t('First count')}</span>
          ) : inbound && m.reason === 'return_from_vehicle' ? (
            <span className="text-slate-400">{t('Already paid')}</span>
          ) : m.reason === 'receipt' ? (
            // A receipt with no bill can only be one booked before the paper was required. Named,
            // not dashed: it is a gap someone can still close.
            <Badge tone="amber">{t('No bill on file')}</Badge>
          ) : null}
        </div>
      </div>
    </li>
  );
}

/**
 * Turning a request down, in the app rather than in a browser prompt.
 *
 * `window.prompt` was the last un-styled thing on this page, and it silently discards the reason if
 * the person hits Escape — on the one action whose whole value is the reason being recorded.
 */
function RejectRequestModal({ request, onClose, onSubmit }) {
  const { t } = useI18n();
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => { if (request) { setReason(''); setSaving(false); } }, [request]);

  const submit = async () => {
    setSaving(true);
    const ok = await onSubmit(reason.trim());
    setSaving(false);
    if (ok) onClose();
  };

  return (
    <Modal
      open={!!request}
      onClose={() => !saving && onClose()}
      title={t('Turn this request down')}
      subtitle={request ? t('{n} × {part}', { n: num(request.quantity), part: request.part_name }) : ''}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>{t('Cancel')}</Button>
          <Button variant="danger" onClick={submit} loading={saving} disabled={!reason.trim()}>{t('Turn it down')}</Button>
        </>
      }
    >
      <Textarea
        label={t('Why is this stock request being rejected?')}
        rows={3}
        value={reason}
        onChange={(e) => setReason(e.target.value)}
      />
      <p className="mt-1 text-xs text-slate-400">
        {t('The person who asked will see this, so say enough that they know what to do instead.')}
      </p>
    </Modal>
  );
}

/**
 * The catalog every part picker in the app selects from, fetched once per modal open.
 *
 * A catalog that will not load must never block the work: the caller falls back to a typed name,
 * which is weaker identity but still a part someone can put on a shelf.
 */
function useCatalog(open) {
  const [catalog, setCatalog] = useState([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (!open) return undefined;
    let alive = true;
    setLoading(true);
    api.get('/parts-catalog')
      .then(({ data }) => { if (alive) setCatalog((data?.data?.parts || []).filter((p) => p.is_active)); })
      .catch(() => { if (alive) setCatalog([]); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, [open]);

  return { catalog, loading };
}

/**
 * The supplier's bill, captured with the stock rather than after it.
 *
 * Putting a part on the shelf is a money event: somebody bought it, from somebody, for a price, on a
 * document. Recording the price and forgetting the document leaves a shelf whose cost nobody can
 * check — so the paper is part of THIS form, not a task someone is trusted to come back and finish.
 *
 * Two shapes, because supplier trips have two shapes: a new bill, or one already keyed here because
 * the same trip bought parts for a car in the workshop too.
 *
 * `blank` is exported as the parent's initial state so the two receive modals can reset it without
 * duplicating the field list.
 */
const BLANK_INVOICE = {
  mode: 'new',            // 'new' | 'existing'
  part_invoice_id: '',
  invoice_no: '',
  invoice_date: new Date().toISOString().slice(0, 10),
  vendor_id: '',
  supplier_name: '',
  tax_amount: '',
  stated_total: '',
  variance_explanation: '',
  photo: null,
};

/**
 * Build the request body for a receive call.
 *
 * ALWAYS FormData, even with no photo attached: the endpoint is multipart because the bill may carry
 * one, and sending JSON sometimes and multipart other times is how a form ends up with two code
 * paths and one of them rotting. `invoice` rides as a JSON string — multipart cannot nest objects,
 * which is why the controller decodes it.
 */
function receiveBody(fields, invoice, needsInvoice) {
  const body = new FormData();

  for (const [k, v] of Object.entries(fields)) {
    if (v !== null && v !== undefined && v !== '') body.append(k, v);
  }

  if (!needsInvoice) return body;

  if (invoice.mode === 'existing') {
    body.append('part_invoice_id', invoice.part_invoice_id);
    return body;
  }

  body.append('invoice', JSON.stringify({
    invoice_no: invoice.invoice_no.trim(),
    invoice_date: invoice.invoice_date || null,
    vendor_id: invoice.vendor_id || null,
    supplier_name: invoice.supplier_name.trim() || null,
    tax_amount: invoice.tax_amount === '' ? 0 : Number(invoice.tax_amount),
    stated_total: invoice.stated_total === '' ? null : Number(invoice.stated_total),
    variance_explanation: invoice.variance_explanation.trim() || null,
  }));
  if (invoice.photo) body.append('invoice_photo', invoice.photo);

  return body;
}

/** Is the invoice half of the form complete enough to submit? */
function invoiceReady(invoice, needsInvoice) {
  if (!needsInvoice) return true;
  return invoice.mode === 'existing'
    ? !!invoice.part_invoice_id
    : !!invoice.invoice_no.trim() && !!invoice.invoice_date;
}

function InvoiceFields({ invoice, onChange, lineTotal, errors = {} }) {
  const { t } = useI18n();
  const [vendors, setVendors] = useState([]);
  const [invoices, setInvoices] = useState([]);
  const [photoUrl, setPhotoUrl] = useState(null);
  const set = (k, v) => onChange({ ...invoice, [k]: v });

  // The attached bill, rendered rather than filed. Revoked on change so a long session keying a
  // dozen receipts does not hold every image it ever previewed.
  useEffect(() => {
    if (!invoice.photo) { setPhotoUrl(null); return undefined; }
    const url = URL.createObjectURL(invoice.photo);
    setPhotoUrl(url);
    return () => URL.revokeObjectURL(url);
  }, [invoice.photo]);

  useEffect(() => {
    let alive = true;
    api.get('/Vendor').catch(() => null).then((r) => {
      if (!alive) return;
      const p = payload(r);
      setVendors(Array.isArray(p) ? p : p?.items || []);
    });
    // Recent bills, so "this is on the invoice I keyed an hour ago" is one click and not a re-key.
    api.get('/part-invoices', { params: { per_page: 25 } }).catch(() => null).then((r) => {
      if (alive) setInvoices(payload(r)?.invoices || []);
    });
    return () => { alive = false; };
  }, []);

  const vendorOptions = useMemo(
    () => vendors.map((v) => ({ id: v.id, label: v.name || `#${v.id}`, sub: v.type })),
    [vendors],
  );

  const chosen = useMemo(
    () => invoices.find((i) => String(i.id) === String(invoice.part_invoice_id)) || null,
    [invoices, invoice.part_invoice_id],
  );

  // What the parts on this receipt come to, beside what the paper says. The gap is the thing worth
  // seeing while it can still be fixed — after submit it becomes a variance that needs explaining.
  const stated = invoice.stated_total === '' ? null : Number(invoice.stated_total);
  const withTax = lineTotal + (invoice.tax_amount === '' ? 0 : Number(invoice.tax_amount) || 0);
  const gap = stated === null ? null : Math.round((withTax - stated) * 100) / 100;
  const gapMatters = gap !== null && Math.abs(gap) > 0.01;

  return (
    <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h4 className="text-sm font-semibold text-slate-800">{t('The supplier’s invoice')}</h4>
        <div className="inline-flex rounded-lg border border-slate-300 bg-white p-0.5">
          {[['new', t('New invoice')], ['existing', t('Already keyed')]].map(([key, label]) => (
            <button
              key={key}
              type="button"
              onClick={() => set('mode', key)}
              className={`rounded-md px-3 py-1 text-xs font-semibold transition ${
                invoice.mode === key ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'
              }`}
            >
              {label}
            </button>
          ))}
        </div>
      </div>

      {invoice.mode === 'existing' ? (
        <>
          <Select label={t('Which invoice are these parts on?')} value={invoice.part_invoice_id} onChange={(e) => set('part_invoice_id', e.target.value)}>
            <option value="">{t('Choose an invoice…')}</option>
            {invoices.map((inv) => (
              <option key={inv.id} value={inv.id}>
                {[inv.invoice_no || t('No number'), inv.supplier, inv.invoice_date].filter(Boolean).join(' · ')}
              </option>
            ))}
          </Select>
          <p className="mt-1 text-xs text-slate-400">
            {t('Use this when the same supplier trip also bought parts for a car — one trip, one document.')}
          </p>

          {/* The same check, for a bill that was keyed earlier: its photo beside its numbers, so
              "is this really the right document" is answered by looking at it rather than by
              trusting the invoice number in the dropdown. */}
          {chosen && (
            <div className="mt-3 rounded-xl border border-slate-200 bg-white p-3">
              <h5 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                {t('The bill you picked')}
              </h5>
              <div className="grid gap-3 sm:grid-cols-2">
                {chosen.photo_url ? (
                  <a href={chosen.photo_url} target="_blank" rel="noreferrer" className="group block" title={t('Open the photo full size')}>
                    <img
                      src={chosen.photo_url}
                      alt={t('Photo of the bill')}
                      className="h-40 w-full rounded-lg border border-slate-200 bg-slate-50 object-contain transition group-hover:border-indigo-300"
                    />
                    <span className="mt-1 block text-xs font-medium text-indigo-600 group-hover:underline">
                      {t('Open the photo full size')}
                    </span>
                  </a>
                ) : (
                  <div className="flex h-40 items-center justify-center rounded-lg border border-dashed border-slate-300 bg-slate-50/60 px-3 text-center text-xs text-slate-500">
                    {t('No photo was attached to this bill.')}
                  </div>
                )}

                <div className="space-y-1.5 text-sm">
                  <Line label={t('Keyed on it so far')} value={aed2(chosen.total_amount ?? 0)} />
                  <Line
                    label={t('Printed on the bill')}
                    value={chosen.stated_total === null ? t('Not recorded') : aed2(chosen.stated_total)}
                  />
                  <div className="flex items-center justify-between border-t border-slate-200 pt-1.5 font-semibold text-slate-900">
                    <span>{t('These parts add')}</span>
                    <span className="tabular-nums">{aed2(lineTotal)}</span>
                  </div>
                  <p className="pt-1 text-xs text-slate-400">
                    {t('Check the photo says the same supplier and number before you add to it.')}
                  </p>
                </div>
              </div>
            </div>
          )}
        </>
      ) : (
        <div className="space-y-3">
          <div className="grid gap-3 sm:grid-cols-2">
            <Input label={t('Invoice number')} required value={invoice.invoice_no} error={errors['invoice.invoice_no']?.[0]} onChange={(e) => set('invoice_no', e.target.value)} />
            <Input label={t('Invoice date')} required type="date" value={invoice.invoice_date} error={errors['invoice.invoice_date']?.[0]} onChange={(e) => set('invoice_date', e.target.value)} />
          </div>

          <div>
            <span className="mb-1 block text-sm font-medium text-slate-700">{t('Supplier')}</span>
            <SearchSelect value={invoice.vendor_id} onChange={(v) => set('vendor_id', v)} options={vendorOptions} placeholder={t('Pick a supplier…')} />
          </div>
          {/* A walk-in shop with no vendor record is still a real supplier, and the bill still
              exists. Typing the name is worse data than picking one, and far better than no bill. */}
          <Input label={t('Or type who issued it')} placeholder={t('For a supplier with no record here')} value={invoice.supplier_name} onChange={(e) => set('supplier_name', e.target.value)} />

          <div className="grid gap-3 sm:grid-cols-2">
            <Input label={t('VAT / tax on the bill')} type="number" min="0" step="0.01" value={invoice.tax_amount} onChange={(e) => set('tax_amount', e.target.value)} />
            <Input label={t('Total printed on the bill')} type="number" min="0" step="0.01" value={invoice.stated_total} onChange={(e) => set('stated_total', e.target.value)} />
          </div>

          {/* THE CHECK: the paper on one side, what was typed on the other.
              The arithmetic here has always been right — lines + tax against the printed total — but
              it only ever compared two things the SAME person typed, so a number read wrong off the
              bill agreed with itself and passed. The photo was a file input and, later, a link that
              opened in another tab: nobody ever saw the bill and the figure at the same moment.
              Putting them side by side is what turns the total into something a person can verify
              instead of re-assert. */}
          <div className="rounded-xl border border-slate-200 bg-white p-3">
            <h5 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
              {t('Check the bill against what you typed')}
            </h5>

            <div className="grid gap-3 sm:grid-cols-2">
              <div>
                {photoUrl ? (
                  <a href={photoUrl} target="_blank" rel="noreferrer" className="group block" title={t('Open the photo full size')}>
                    <img
                      src={photoUrl}
                      alt={t('Photo of the bill')}
                      className="h-48 w-full rounded-lg border border-slate-200 bg-slate-50 object-contain transition group-hover:border-indigo-300"
                    />
                    <span className="mt-1 block text-xs font-medium text-indigo-600 group-hover:underline">
                      {t('Open the photo full size')}
                    </span>
                  </a>
                ) : (
                  <div className="flex h-48 flex-col items-center justify-center rounded-lg border border-dashed border-slate-300 bg-slate-50/60 px-3 text-center">
                    <Icon.Camera className="h-6 w-6 text-slate-300" />
                    <p className="mt-2 text-xs text-slate-500">{t('Attach the bill and it appears here, next to the numbers.')}</p>
                  </div>
                )}
                <input
                  type="file"
                  accept="image/*"
                  onChange={(e) => set('photo', e.target.files?.[0] || null)}
                  className="mt-2 block w-full text-sm text-slate-600 file:me-3 file:rounded-lg file:border-0 file:bg-slate-200 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-slate-700 hover:file:bg-slate-300"
                />
                <p className="mt-1 text-xs text-slate-400">{t('Optional, but it is the only copy that survives the paper being lost.')}</p>
              </div>

              <div className="space-y-1.5 text-sm">
                <Line label={t('These parts')} value={aed2(lineTotal)} />
                <Line label={t('VAT / tax you typed')} value={aed2(invoice.tax_amount === '' ? 0 : Number(invoice.tax_amount) || 0)} />
                <div className="flex items-center justify-between border-t border-slate-200 pt-1.5 font-semibold text-slate-900">
                  <span>{t('Comes to')}</span>
                  <span className="tabular-nums">{aed2(withTax)}</span>
                </div>

                {stated === null ? (
                  <p className="pt-1 text-xs text-slate-400">
                    {t('Type the total printed on the bill above and it is compared here.')}
                  </p>
                ) : (
                  <>
                    <div className="flex items-center justify-between pt-1.5 text-slate-600">
                      <span>{t('Printed on the bill')}</span>
                      <span className="tabular-nums">{aed2(stated)}</span>
                    </div>
                    <div className={`mt-1 flex items-center gap-1.5 rounded-lg px-2.5 py-2 text-xs font-medium ${
                      gapMatters ? 'bg-amber-50 text-amber-800' : 'bg-emerald-50 text-emerald-800'
                    }`}>
                      {gapMatters ? <Icon.Alert className="h-4 w-4 shrink-0" /> : <Icon.Check className="h-4 w-4 shrink-0" />}
                      {gapMatters
                        ? t('Off by {gap} — read the photo again, or say why below.', { gap: aed2(Math.abs(gap)) })
                        : t('Matches the bill.')}
                    </div>
                  </>
                )}
              </div>
            </div>
          </div>

          {gapMatters && (
            <Textarea
              label={t('Explain the difference')}
              rows={2}
              value={invoice.variance_explanation}
              onChange={(e) => set('variance_explanation', e.target.value)}
            />
          )}
        </div>
      )}
    </div>
  );
}

/**
 * What this shelf has been costing, and whether a newly typed price agrees with it.
 *
 * Mirrors StoreService's gate exactly — 20% AND at least 25 — because a form that lets you press the
 * button and then rejects you has taught you nothing. The server is still the authority; this is
 * only the same question asked early enough to be answered by re-reading the bill.
 */
const PRICE_VARIANCE_FRACTION = 0.20;
const PRICE_VARIANCE_FLOOR = 25;

function priceGap(was, now) {
  if (was === null || was === undefined || !(Number(was) > 0)) return null;
  if (now === '' || now === null || now === undefined) return null;
  const delta = Math.round((Number(now) - Number(was)) * 100) / 100;
  if (Math.abs(delta) < PRICE_VARIANCE_FLOOR) return null;
  if (Math.abs(delta) / Number(was) < PRICE_VARIANCE_FRACTION) return null;
  return delta;
}

/** Ask the shelf what it already holds and what it cost, for a part being received. */
function useShelfPrice(active, catalogId, partName, partNumber) {
  const [shelf, setShelf] = useState(null);

  useEffect(() => {
    if (!active || (!catalogId && String(partName || '').trim().length < 2)) { setShelf(null); return undefined; }
    let alive = true;
    api.get('/store/availability', {
      params: {
        component_catalog_id: catalogId || undefined,
        part_name: partName || undefined,
        part_number: partNumber || undefined,
      },
    })
      .then((r) => { if (alive) setShelf(payload(r)); })
      .catch(() => { if (alive) setShelf(null); });
    return () => { alive = false; };
  }, [active, catalogId, partName, partNumber]);

  return shelf;
}

/**
 * "We have paid a different price for this before" — said while the price can still be retyped.
 *
 * The shelf keeps a WEIGHTED AVERAGE, so a wrong price here does not just look wrong on one row: it
 * is blended into the shelf's cost permanently, and that blended figure is what every future car
 * gets charged when it takes one off this shelf. Two AC compressors at very different prices become
 * a shelf that says a number true of neither.
 */
function PriceAgainstShelf({ shelf, unitCost, note, onNote, error }) {
  const { t } = useI18n();
  const was = shelf?.unit_cost ?? null;

  if (was === null || !(Number(was) > 0)) return null;

  const gap = priceGap(was, unitCost);
  const typed = unitCost === '' || unitCost === null ? null : Number(unitCost);

  return (
    <div className={`rounded-xl border p-3 ${gap === null ? 'border-slate-200 bg-slate-50/60' : 'border-amber-300 bg-amber-50'}`}>
      <div className="flex items-start gap-2">
        {gap === null ? <Icon.Check className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> : <Icon.Alert className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />}
        <div className="min-w-0 text-sm">
          <p className={gap === null ? 'text-slate-600' : 'font-medium text-amber-900'}>
            {t('This shelf has been costing {was} a unit.', { was: aed2(was) })}
            {typed !== null && gap !== null && (
              ` ${t('You typed {now} — {dir} by {gap}.', {
                now: aed2(typed),
                dir: gap > 0 ? t('up') : t('down'),
                gap: aed2(Math.abs(gap)),
              })}`
            )}
          </p>
          <p className="mt-0.5 text-xs text-slate-500">
            {gap === null
              ? t('Close enough to what we have been paying — nothing to explain.')
              : t('The shelf blends every price it is given, and that blend is what the next car is charged. Check the bill before you accept this.')}
          </p>
        </div>
      </div>

      {gap !== null && (
        <div className="mt-3">
          <Textarea
            label={t('Why has the price changed?')}
            rows={2}
            value={note}
            error={error}
            onChange={(e) => onNote(e.target.value)}
          />
          <p className="mt-1 text-xs text-amber-800">
            {t('Required — it is kept with this receipt, so the jump can be explained later.')}
          </p>
        </div>
      )}
    </div>
  );
}

/** One label-and-figure row in the comparison beside the bill photo. */
function Line({ label, value }) {
  return (
    <div className="flex items-center justify-between text-slate-600">
      <span>{label}</span>
      <span className="tabular-nums">{value}</span>
    </div>
  );
}

/** Book stock in with no request behind it: the opening count, or a walk-in buy. */
function ReceiveStockModal({ open, onClose, onDone }) {
  const { t } = useI18n();
  const toast = useToast();
  const { catalog, loading: catalogLoading } = useCatalog(open);
  const [part, setPart] = useState(null);
  const [form, setForm] = useState({ quantity: 1, unit_cost: '', location: '', min_qty: '', reason: 'receipt', note: '', price_variance_note: '' });
  const [invoice, setInvoice] = useState(BLANK_INVOICE);
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState({});

  useEffect(() => {
    if (!open) return;
    setPart(null);
    setForm({ quantity: 1, unit_cost: '', location: '', min_qty: '', reason: 'receipt', note: '', price_variance_note: '' });
    setInvoice(BLANK_INVOICE);
    setErrors({});
  }, [open]);

  const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));

  const shelf = useShelfPrice(open, part?.component_catalog_id || null, part?.part_name || '', part?.part_number || '');
  const gap = priceGap(shelf?.unit_cost, form.unit_cost);

  // Only a BUY needs paper. An opening count is stock that was already on the shelf the day the
  // storehouse started — its document, if one ever existed, is not ours to invent.
  const needsInvoice = form.reason === 'receipt';
  const lineTotal = (Number(form.quantity) || 0) * (Number(form.unit_cost) || 0);

  const submit = async () => {
    setSaving(true);
    setErrors({});
    try {
      await api.post('/store/items/receive', receiveBody({
        component_catalog_id: part?.component_catalog_id || '',
        part_name: (part?.part_name || '').trim(),
        part_number: (part?.part_number || '').trim(),
        quantity: Number(form.quantity) || 0,
        unit_cost: form.unit_cost,
        location: form.location.trim(),
        min_qty: form.min_qty,
        reason: form.reason,
        note: form.note.trim(),
        price_variance_note: gap === null ? '' : form.price_variance_note.trim(),
      }, invoice, needsInvoice));
      toast.success(t('Stock booked into the storehouse'));
      onDone?.();
      onClose();
    } catch (err) {
      const res = err.response?.data;
      if (res?.errors) { setErrors(res.errors); toast.error(t('Please fix the highlighted fields')); }
      else toast.error(res?.message || res?.msg || t('Could not book the stock in'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={() => !saving && onClose()}
      title={t('Put stock in')}
      subtitle={t('Raises the shelf. Every unit booked here is one a job can take later.')}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>{t('Cancel')}</Button>
          <Button
            onClick={submit}
            loading={saving}
            disabled={!part?.part_name || !Number(form.quantity) || (needsInvoice && !Number(form.unit_cost))
              || !invoiceReady(invoice, needsInvoice) || (gap !== null && !form.price_variance_note.trim())}
          >
            {t('Book it in')}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <CatalogPartPicker value={part} onChange={setPart} catalog={catalog} loading={catalogLoading} />
        {errors.part_name && <p className="text-xs text-rose-500">{errors.part_name[0]}</p>}

        <div className="grid gap-4 sm:grid-cols-2">
          <Input label={t('How many')} type="number" min="0" step="0.01" value={form.quantity} error={errors.quantity?.[0]} onChange={(e) => set('quantity', e.target.value)} />
          {/* The price is what makes a unit off this shelf cost anything later. Left blank on
              purpose for an opening count nobody has an invoice for — the shelf then says "not
              costed" rather than quietly charging a car zero as though the part were free. */}
          <div>
            <Input
              label={t('Cost per unit')}
              type="number"
              min="0"
              step="0.01"
              value={form.unit_cost}
              error={errors.unit_cost?.[0]}
              onChange={(e) => set('unit_cost', e.target.value)}
            />
            <p className="mt-1 text-xs text-slate-400">
              {t('Leave blank if unknown — the shelf will show as not costed rather than free.')}
            </p>
          </div>
          <div className="sm:col-span-2">
            <PriceAgainstShelf
              shelf={shelf}
              unitCost={form.unit_cost}
              note={form.price_variance_note}
              onNote={(v) => set('price_variance_note', v)}
              error={errors.price_variance_note?.[0]}
            />
          </div>
          <Input label={t('Shelf / bin')} value={form.location} onChange={(e) => set('location', e.target.value)} />
          <Input label={t('Tell me when it drops to')} type="number" min="0" step="0.01" value={form.min_qty} onChange={(e) => set('min_qty', e.target.value)} />
        </div>

        <div>
          <Select label={t('What is this')} value={form.reason} onChange={(e) => set('reason', e.target.value)}>
            <option value="receipt">{t('A buy that arrived')}</option>
            <option value="opening">{t('Stock we already had (first count)')}</option>
          </Select>
          {!needsInvoice && (
            <p className="mt-1 text-xs text-slate-400">
              {t('A first count needs no invoice — it is stock that was already here. Its price is unknown unless you enter one, and the shelf will say so.')}
            </p>
          )}
        </div>

        {needsInvoice && <InvoiceFields invoice={invoice} onChange={setInvoice} lineTotal={lineTotal} errors={errors} />}

        <Textarea label={t('Note')} rows={2} value={form.note} onChange={(e) => set('note', e.target.value)} />
      </div>
    </Modal>
  );
}

/** Ask for a part to be bought INTO the store — the door that needs no car. */
function AskForStockModal({ open, onClose, onDone }) {
  const { t } = useI18n();
  const toast = useToast();
  const { catalog, loading: catalogLoading } = useCatalog(open);
  const [part, setPart] = useState(null);
  const [have, setHave] = useState(null);
  const [form, setForm] = useState({ quantity: 1, estimated_price: '', reason: '', notes: '' });
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState({});

  useEffect(() => {
    if (!open) return;
    setPart(null);
    setHave(null);
    setForm({ quantity: 1, estimated_price: '', reason: '', notes: '' });
    setErrors({});
  }, [open]);

  // What we already hold, shown BEFORE the request is raised. Asking for ten of something we have
  // forty of is the commonest waste this page can prevent, and it can only prevent it by saying so
  // while there is still a decision to make.
  const catalogId = part?.component_catalog_id || null;
  const partName = part?.part_name || '';
  useEffect(() => {
    if (!open || (!catalogId && partName.trim().length < 2)) { setHave(null); return undefined; }
    let alive = true;
    api.get('/store/availability', { params: { component_catalog_id: catalogId || undefined, part_name: partName || undefined } })
      .then((r) => { if (alive) setHave(payload(r)); })
      .catch(() => { if (alive) setHave(null); });
    return () => { alive = false; };
  }, [open, catalogId, partName]);

  const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));

  const submit = async () => {
    setSaving(true);
    setErrors({});
    try {
      await api.post('/store/stock-requests', {
        component_catalog_id: catalogId,
        part_name: partName.trim(),
        part_number: (part?.part_number || '').trim() || null,
        quantity: Number(form.quantity) || 1,
        estimated_price: form.estimated_price === '' ? null : Number(form.estimated_price),
        reason: form.reason.trim(),
        notes: form.notes.trim() || null,
      });
      toast.success(t('Stock request created'));
      onDone?.();
      onClose();
    } catch (err) {
      const res = err.response?.data;
      if (res?.errors) { setErrors(res.errors); toast.error(t('Please fix the highlighted fields')); }
      else toast.error(res?.message || res?.msg || t('Could not create the stock request'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={() => !saving && onClose()}
      title={t('Request a part for the store')}
      subtitle={t('No car and no ticket — this asks for the part to be put on the shelf, ready for whichever job needs it.')}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>{t('Cancel')}</Button>
          <Button onClick={submit} loading={saving} disabled={!partName.trim() || !form.reason.trim()}>{t('Send the request')}</Button>
        </>
      }
    >
      <div className="space-y-4">
        <CatalogPartPicker value={part} onChange={setPart} catalog={catalog} loading={catalogLoading} />

        {have?.qty_on_hand > 0 && (
          <div className="rounded-xl bg-sky-50 px-4 py-3 text-sm text-sky-800 ring-1 ring-inset ring-sky-600/20">
            {t('The storehouse already holds {n} of this. Ask only for what you still need.', { n: num(have.qty_on_hand) })}
          </div>
        )}

        <div className="grid gap-4 sm:grid-cols-2">
          <Input label={t('How many')} type="number" min="0" step="0.01" value={form.quantity} error={errors.quantity?.[0]} onChange={(e) => set('quantity', e.target.value)} />
          <Input label={t('Expected price per unit')} type="number" min="0" step="0.01" value={form.estimated_price} onChange={(e) => set('estimated_price', e.target.value)} />
        </div>

        <Textarea label={t('Why the store needs it')} rows={3} value={form.reason} error={errors.reason?.[0]} onChange={(e) => set('reason', e.target.value)} />
        <Textarea label={t('Notes')} rows={2} value={form.notes} onChange={(e) => set('notes', e.target.value)} />
      </div>
    </Modal>
  );
}

/** The part arrived — the one step that raises a shelf for a requested buy. */
function ReceiveRequestModal({ request, onClose, onDone }) {
  const { t } = useI18n();
  const toast = useToast();
  const [form, setForm] = useState({ quantity: '', unit_cost: '', location: '', note: '', price_variance_note: '' });
  const [invoice, setInvoice] = useState(BLANK_INVOICE);
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState({});

  useEffect(() => {
    if (!request) return;
    setForm({
      quantity: String(request.quantity ?? ''),
      unit_cost: request.unit_cost ?? request.estimated_price ?? '',
      location: '',
      note: '',
      price_variance_note: '',
    });
    setInvoice({ ...BLANK_INVOICE, vendor_id: request.supplier?.id || '', supplier_name: request.supplier_name || '' });
    setErrors({});
  }, [request]);

  const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));

  // This is the door the AC compressor came through twice, at two very different prices, with
  // nothing comparing the second to the first.
  const shelf = useShelfPrice(!!request, request?.component_catalog_id || null, request?.part_name || '', request?.part_number || '');
  const gap = priceGap(shelf?.unit_cost, form.unit_cost);

  const lineTotal = (Number(form.quantity) || 0) * (Number(form.unit_cost) || 0);

  const submit = async () => {
    setSaving(true);
    setErrors({});
    try {
      await api.post(`/store/stock-requests/${request.id}/receive`, receiveBody({
        quantity: form.quantity,
        unit_cost: form.unit_cost,
        location: form.location.trim(),
        note: form.note.trim(),
        price_variance_note: gap === null ? '' : form.price_variance_note.trim(),
      }, invoice, true));
      toast.success(t('Stock received into the storehouse'));
      onDone?.();
      onClose();
    } catch (err) {
      const res = err.response?.data;
      if (res?.errors) { setErrors(res.errors); toast.error(t('Please fix the highlighted fields')); }
      else toast.error(res?.message || res?.msg || t('Could not receive the stock'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={!!request}
      onClose={() => !saving && onClose()}
      title={t('Receive into the store')}
      subtitle={request?.part_name}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>{t('Cancel')}</Button>
          {/* The bill is not optional here. Receiving is the moment the fleet takes ownership and
              owes money; a shelf raised now and papered "later" is a cost with nothing behind it. */}
          <Button
            onClick={submit}
            loading={saving}
            disabled={!Number(form.unit_cost) || !invoiceReady(invoice, true) || (gap !== null && !form.price_variance_note.trim())}
          >
            {t('It is on the shelf')}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {/* Asked-for and received are separate facts on purpose: a supplier who sends eight of ten
            has delivered eight, and the shelf must say eight. */}
        <div>
          <Input
            label={t('How many actually arrived')}
            type="number"
            min="0"
            step="0.01"
            value={form.quantity}
            onChange={(e) => set('quantity', e.target.value)}
          />
          {request && <p className="mt-1 text-xs text-slate-400">{t('{n} were asked for', { n: num(request.quantity) })}</p>}
        </div>
        <Input label={t('Cost per unit')} required type="number" min="0" step="0.01" value={form.unit_cost} error={errors.unit_cost?.[0]} onChange={(e) => set('unit_cost', e.target.value)} />

        <PriceAgainstShelf
          shelf={shelf}
          unitCost={form.unit_cost}
          note={form.price_variance_note}
          onNote={(v) => set('price_variance_note', v)}
          error={errors.price_variance_note?.[0]}
        />

        <Input label={t('Shelf / bin')} value={form.location} onChange={(e) => set('location', e.target.value)} />

        <InvoiceFields invoice={invoice} onChange={setInvoice} lineTotal={lineTotal} errors={errors} />

        <Textarea label={t('Note')} rows={2} value={form.note} onChange={(e) => set('note', e.target.value)} />
      </div>
    </Modal>
  );
}

/**
 * Put right what a shelf costs, after a wrong price was blended into its average.
 *
 * Separate from "Fix the count" on purpose, and behind a higher permission. A count and a cost are
 * different facts with different evidence: a miscount is corrected by looking at the shelf, a
 * mispricing by looking at the bills. Offering both behind one button would invite somebody
 * adjusting a quantity to nudge a price on the way past.
 */
function CorrectPriceModal({ item, onClose, onDone }) {
  const { t } = useI18n();
  const toast = useToast();
  const [price, setPrice] = useState('');
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (item) { setPrice(item.avg_unit_cost === null ? '' : String(item.avg_unit_cost)); setReason(''); }
  }, [item]);

  const submit = async () => {
    setSaving(true);
    try {
      await api.post(`/store/items/${item.id}/correct-price`, {
        avg_unit_cost: Number(price),
        reason: reason.trim(),
      });
      toast.success(t('Shelf price corrected'));
      onDone?.();
      onClose();
    } catch (err) {
      const res = err.response?.data;
      toast.error(res?.message || res?.msg || t('Could not correct the price'));
    } finally {
      setSaving(false);
    }
  };

  const onHand = Number(item?.qty_on_hand) || 0;
  const newValue = price === '' ? null : Math.round(onHand * Number(price) * 100) / 100;

  return (
    <Modal
      open={!!item}
      onClose={() => !saving && onClose()}
      title={t('Correct what this shelf costs')}
      subtitle={item?.part_name}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>{t('Cancel')}</Button>
          <Button onClick={submit} loading={saving} disabled={price === '' || Number(price) < 0 || reason.trim().length < 3}>
            {t('Correct it')}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {/* What this actually changes, said plainly. The average is not a display figure — it is
            the price charged to the next car that takes one off this shelf. */}
        <div className="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-600/20">
          {t('This is the price charged to the next car that takes one off this shelf. It does not change what any past receipt recorded, and it moves no stock.')}
        </div>

        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <span className="mb-1 block text-sm font-medium text-slate-700">{t('It costs now')}</span>
            <p className="rounded-lg bg-slate-100 px-3 py-2 text-sm font-semibold tabular-nums text-slate-800">
              {item?.avg_unit_cost === null ? t('Not costed') : aed2(item?.avg_unit_cost)}
            </p>
          </div>
          <Input
            label={t('It should cost')}
            type="number"
            min="0"
            step="0.01"
            value={price}
            onChange={(e) => setPrice(e.target.value)}
          />
        </div>

        {newValue !== null && (
          <p className="text-xs text-slate-500">
            {t('{n} on the shelf, so the stock becomes worth {value}.', { n: num(onHand), value: aed2(newValue) })}
          </p>
        )}

        <div>
          <Textarea label={t('Why is the old price wrong?')} rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
          <p className="mt-1 text-xs text-slate-400">
            {t('Required — this is the one change to money with no document behind it, so the sentence is the evidence.')}
          </p>
        </div>
      </div>
    </Modal>
  );
}

/** Correct a count or write stock off — the only movements with no document behind them. */
function AdjustModal({ item, onClose, onDone }) {
  const { t } = useI18n();
  const toast = useToast();
  const [form, setForm] = useState({ direction: 'out', quantity: 1, reason: 'adjustment', note: '' });
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (item) setForm({ direction: 'out', quantity: 1, reason: 'adjustment', note: '' });
  }, [item]);

  const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));

  const submit = async () => {
    setSaving(true);
    try {
      await api.post(`/store/items/${item.id}/adjust`, {
        direction: form.direction,
        quantity: Number(form.quantity) || 0,
        reason: form.reason,
        note: form.note.trim(),
      });
      toast.success(t('Stock adjusted'));
      onDone?.();
      onClose();
    } catch (err) {
      const res = err.response?.data;
      toast.error(res?.message || res?.msg || t('Could not adjust the stock'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={!!item}
      onClose={() => !saving && onClose()}
      title={t('Adjust the count')}
      subtitle={item ? t('{part} — {n} on hand', { part: item.part_name, n: num(item.qty_on_hand) }) : ''}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>{t('Cancel')}</Button>
          <Button onClick={submit} loading={saving} disabled={!form.note.trim() || !Number(form.quantity)}>{t('Apply')}</Button>
        </>
      }
    >
      <div className="space-y-4">
        <Select label={t('Which way')} value={form.direction} onChange={(e) => set('direction', e.target.value)}>
          <option value="out">{t('Take units off the shelf')}</option>
          <option value="in">{t('Add units to the shelf')}</option>
        </Select>

        <Select label={t('Why')} value={form.reason} onChange={(e) => set('reason', e.target.value)}>
          <option value="adjustment">{t('The count was wrong')}</option>
          <option value="write_off">{t('Damaged, lost or expired')}</option>
        </Select>

        <Input label={t('How many')} type="number" min="0" step="0.01" value={form.quantity} onChange={(e) => set('quantity', e.target.value)} />

        {/* Required, not optional. A quantity that changed with nobody saying why is
            indistinguishable from a mistake, and this is the one movement with no paper behind it. */}
        <div>
          <Textarea label={t('What happened')} rows={3} value={form.note} onChange={(e) => set('note', e.target.value)} />
          <p className="mt-1 text-xs text-slate-400">
            {t('Required — an unexplained change to a count cannot be told apart from an error.')}
          </p>
        </div>
      </div>
    </Modal>
  );
}

/** One shelf and everything that ever moved on it. */
function ShelfDrawerModal({ itemId, onClose }) {
  const { t } = useI18n();
  const fetcher = useCallback(async () => (itemId ? payload(await api.get(`/store/items/${itemId}`)) : null), [itemId]);
  const { data, loading } = useFetch(fetcher, [itemId], { paused: () => !itemId });

  const item = data?.item;
  const movements = data?.movements || [];
  const corrections = data?.price_corrections || [];

  return (
    <Modal open={!!itemId} onClose={onClose} title={item?.part_name || t('Shelf')} subtitle={item?.part_number || ''} size="lg">
      {loading && !data ? (
        <p className="p-5 text-sm text-slate-400">{t('Loading…')}</p>
      ) : (
        <div className="space-y-5">
          <div className="grid grid-cols-2 gap-3 rounded-xl bg-slate-50 px-4 py-3 sm:grid-cols-4">
            <Fact label={t('On hand')} value={qty(item?.qty_on_hand)} />
            <Fact label={t('Unit cost')} value={item?.avg_unit_cost === null ? t('Not costed') : aed2(item?.avg_unit_cost)} />
            <Fact label={t('Stock value')} value={item?.stock_value === null ? '—' : aed2(item?.stock_value)} />
            <Fact label={t('Shelf / bin')} value={item?.location || '—'} />
          </div>

          {/* Corrections to the shelf's COST, shown apart from the movements. Those are units;
              these are money, and mixing them would make the ledger's quantities read as if a
              re-price had shifted stock. */}
          {corrections.length > 0 && (
            <div>
              <h3 className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">{t('Price corrections')}</h3>
              <ul className="space-y-2">
                {corrections.map((c) => (
                  <li key={c.id} className="rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-900">
                    <div className="font-semibold">
                      {c.old === null
                        ? t('Priced at {now}', { now: aed2(c.new) })
                        : t('{was} → {now} a unit', { was: aed2(c.old), now: aed2(c.new) })}
                    </div>
                    <div className="mt-0.5">{c.reason}</div>
                    <div className="mt-0.5 text-amber-700">
                      {c.actor_name || '—'}{c.occurred_at ? ` · ${fmtAgo(c.occurred_at)}` : ''}
                    </div>
                  </li>
                ))}
              </ul>
            </div>
          )}

          <div>
            <h3 className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">{t('Everything that moved')}</h3>
            {movements.length === 0 ? (
              <p className="text-sm text-slate-400">{t('Nothing has moved on this shelf yet.')}</p>
            ) : (
              <ol className="space-y-3">
                {movements.map((m) => (
                  <li key={m.id} className="flex gap-3">
                    <span className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${m.direction === 'in' ? 'bg-emerald-400' : 'bg-amber-400'}`} />
                    <div className="min-w-0">
                      <div className="text-sm font-medium text-slate-800">
                        {MOVEMENT_LABEL[m.reason] ? t(MOVEMENT_LABEL[m.reason]) : m.reason}
                        {' · '}
                        {m.direction === 'in' ? '+' : '−'}{qty(m.quantity)}
                        {' · '}
                        {t('{n} left', { n: qty(m.qty_after) })}
                      </div>
                      <div className="text-xs text-slate-400">
                        {fmtAgo(m.occurred_at)}
                        {m.actor_name ? ` · ${m.actor_name}` : ''}
                        {m.vehicle ? ` · ${m.vehicle.plate}` : ''}
                        {m.supplier?.name ? ` · ${m.supplier.name}` : ''}
                      </div>
                      {/* The paper, one click away — with the photo when one was attached, because
                          the scan is what survives the original being lost. */}
                      {m.invoice && (
                        <div className="mt-0.5 text-xs">
                          <Link to={`/parts?tab=invoices&focus=${m.invoice.id}`} className="font-medium text-indigo-600 hover:underline">
                            {t('Invoice {no}', { no: m.invoice.invoice_no || `#${m.invoice.id}` })}
                          </Link>
                          <span className="text-slate-400">{m.invoice.supplier ? ` · ${m.invoice.supplier}` : ''}</span>
                          {m.invoice.photo_url && (
                            <a href={m.invoice.photo_url} target="_blank" rel="noreferrer" className="ms-2 font-medium text-slate-500 hover:underline">
                              {t('View the bill')}
                            </a>
                          )}
                        </div>
                      )}
                      {m.note && <div className="mt-0.5 text-xs text-slate-500">{m.note}</div>}
                    </div>
                  </li>
                ))}
              </ol>
            )}
          </div>
        </div>
      )}
    </Modal>
  );
}

function Fact({ label, value }) {
  return (
    <div className="min-w-0">
      <dt className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{label}</dt>
      <dd className="truncate text-sm font-semibold text-slate-800">{value ?? '—'}</dd>
    </div>
  );
}
