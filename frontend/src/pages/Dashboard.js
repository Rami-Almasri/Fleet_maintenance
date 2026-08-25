import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge from '../components/ui/Badge';
import { Card } from '../components/ui/Misc';
import { SectionCard } from '../components/ui/Table';
import { Skeleton } from '../components/ui/Skeleton';
import { InfoTip } from '../components/ui/Tooltip';
import Icon from '../components/ui/Icon';
import FleetStatusCard from '../components/ui/FleetStatusCard';
import BarChart from '../components/ui/BarChart';
import LineChart from '../components/ui/LineChart';
import CountUp from '../components/ui/CountUp';
import FleetPulseGrid from '../components/FleetPulseGrid';
import RecentlyFixedCard from '../components/RecentlyFixedCard';
import RepeatPartPurchases from '../components/dashboard/RepeatPartPurchases';
import PipelinePanel from '../components/analytics/PipelinePanel';
import { aed, fmtDate } from '../lib/format';
import { useCheckpointVocab, resolveCheckpointTicket } from '../lib/maintenanceCheckpoints';
import CheckpointModal from '../components/maintenance/CheckpointModal';
import { useToast } from '../components/ui/Toast';
import { SHOW_FINANCIALS } from '../config/features';
import { useAuth } from '../auth/AuthContext';
import { useI18n } from '../i18n/I18nContext';

// Time-of-day greeting key for the dashboard header ("Good morning, Rami!").
function greetKey() {
  const h = new Date().getHours();
  if (h < 12) return 'morning';
  if (h < 18) return 'afternoon';
  return 'evening';
}

// Compact currency for chart axes (AED 4,180 → "4.2k") so y-labels never overflow.
const aedK = (n) => {
  const v = Number(n) || 0;
  if (Math.abs(v) >= 1000) return `${(v / 1000).toFixed(Math.abs(v) % 1000 ? 1 : 0)}k`;
  return Math.round(v).toString();
};

const TILE_TONE_SOFT = {
  amber: 'bg-amber-100 text-amber-600',
  red:   'bg-red-100 text-red-600',
  blue:  'bg-blue-100 text-blue-600',
};

// Proactive Flags — the live "act on this now" panel. The lead column is every car in the workshop
// right now (with its repair-ETA KPI); an optional Payments-Overdue column (returned rentals with an
// unpaid balance) is gated by SHOW_FINANCIALS. Reads /Dashboard/proactive-flags, the SAME source the
// notification bell raises its alerts from — so a card here and its bell alert can never disagree.
// Every row deep-links to its source record (traceability).

// Threshold palette for the repair-progress bar. Green under 75% of target, orange 75–100%, red
// once the target is exceeded — matched track / fill / badge / percent tints so a card reads as one.
const PROGRESS_TONE = {
  green:  { bar: 'bg-emerald-500', track: 'bg-emerald-100', badge: 'bg-emerald-50 text-emerald-700 ring-emerald-200', pct: 'text-emerald-600', dot: 'bg-emerald-500', from: '#34d399', to: '#059669', accent: 'from-emerald-400 to-emerald-500', glow: 'bg-emerald-400/20' },
  orange: { bar: 'bg-amber-500',   track: 'bg-amber-100',   badge: 'bg-amber-50 text-amber-700 ring-amber-200',       pct: 'text-amber-600',   dot: 'bg-amber-500', from: '#fbbf24', to: '#d97706', accent: 'from-amber-400 to-amber-500',   glow: 'bg-amber-400/20' },
  red:    { bar: 'bg-rose-500',    track: 'bg-rose-100',    badge: 'bg-rose-50 text-rose-700 ring-rose-200',          pct: 'text-rose-600',    dot: 'bg-rose-500',  from: '#fb7185', to: '#e11d48', accent: 'from-rose-400 to-rose-500',     glow: 'bg-rose-400/20' },
};

// Whole days the revised ETA slipped past the previous one (positive = later).
function checkpointDelayDays(prev, next) {
  if (!prev || !next) return null;
  const a = new Date(`${prev}T00:00:00`);
  const b = new Date(`${next}T00:00:00`);
  if (isNaN(a) || isNaN(b)) return null;
  return Math.round((b - a) / 86400000);
}

// The workshop's last progress update, clearly labelled so the delay story reads as information rather
// than two unlabelled dates jammed together: the ETA change (Previous → New + how many days it slipped),
// the reason it moved, and who filed it when. Amber "No update filed yet" when nobody has reported.
function CheckpointLine({ cp }) {
  const { t } = useI18n();
  const { delayReasonLabel } = useCheckpointVocab();
  if (!cp) {
    return <p className="text-[11px] font-semibold text-amber-600">{t('dash.cp.none')}</p>;
  }
  const reason = cp.delay_reason === 'other' ? (cp.delay_reason_other || null) : delayReasonLabel(cp.delay_reason);
  const etaMoved = !!cp.next_expected_date
    && (!cp.previous_expected_date || cp.previous_expected_date !== cp.next_expected_date);
  const dd = checkpointDelayDays(cp.previous_expected_date, cp.next_expected_date);
  return (
    <div className="space-y-0.5 leading-tight" title={cp.summary || undefined}>
      {cp.next_expected_date ? (
        <p className="flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[11px]">
          {etaMoved && cp.previous_expected_date && (
            <span className="text-slate-400"><span className="line-through decoration-slate-300">{fmtDate(cp.previous_expected_date)}</span> →</span>
          )}
          <span className="font-semibold text-slate-700">{etaMoved ? t('dash.cp.newEta') : t('dash.cp.eta')} {fmtDate(cp.next_expected_date)}</span>
          {dd != null && dd > 0 && (
            <span className="rounded-full bg-red-50 px-1.5 py-0.5 text-[10px] font-bold text-red-600 ring-1 ring-red-200">{t('dash.cp.slipDays', { n: dd })}</span>
          )}
        </p>
      ) : (
        <p className="text-[11px] font-semibold text-slate-600">{t('dash.cp.filed')}</p>
      )}
      {etaMoved && (
        reason
          ? <p className="text-[11px] text-amber-700"><span className="font-semibold">{t('dash.cp.reason')}</span> {reason}</p>
          : <p className="text-[11px] text-amber-600">{t('dash.cp.noReason')}</p>
      )}
      <p className="text-[11px] text-slate-400">{cp.by ? t('dash.cp.updatedBy', { by: cp.by }) : t('dash.cp.updated')}{cp.at ? ` · ${fmtDate(cp.at)}` : ''}</p>
    </div>
  );
}

// One labelled figure in a card's KPI grid.
function KpiCell({ label, value, tone = 'text-slate-800' }) {
  return (
    <div className="min-w-0">
      <dt className="truncate text-[10px] font-medium uppercase tracking-wide text-slate-400">{label}</dt>
      <dd className={`truncate text-xs font-semibold tabular-nums ${tone}`}>{value}</dd>
    </div>
  );
}

// pluralised "N day(s)" — bound to the active language's plural rules by the
// caller, since Arabic needs six forms rather than English's two.
const daysWith = (tp) => (n) => tp('dash.days', Math.abs(n), { n });

// WHERE we know this car is in the shop from — the standing traceability rule: no card without its
// data origin. 'contract' = the OM/sheet-synced maintenance contract; 'workshop' = the app's own
// maintenance-workflow ticket; 'both' = the same visit exists in each (shown once).
const SOURCE_BADGE = {
  contract: 'bg-sky-50 text-sky-700 ring-sky-200',
  workshop: 'bg-violet-50 text-violet-700 ring-violet-200',
  both: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
};

// A stable DOM id per in-shop card, so a checkpoint reminder carrying ?ticket=<id> can scroll to and
// ring the exact car it was raised for. Contract-only rows have no ticket yet and key off the contract.
const flagKey = (it) => `${it?.source}-${it?.ticket_id ?? it?.contract_id ?? it?.id}`;
const flagCardId = (it) => `in-shop-${flagKey(it)}`;

function SourceBadge({ source }) {
  const { t } = useI18n();
  const cls = SOURCE_BADGE[source];
  if (!cls) return null;
  return (
    <span
      className={`shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 ${cls}`}
      title={t(`dash.source.${source}.title`)}
    >
      {t(`dash.source.${source}.label`)}
    </span>
  );
}

// Repair-Progress KPI card for one car in the workshop. A visual horizontal progress bar (elapsed
// days-in-shop / planned target days) with threshold colours — green < 75%, orange 75–100%, red once
// the target is exceeded (bar stays pinned at 100% when overdue) — a status badge ("N days remaining"
// / "Due today" / "+N days overdue"), and a compact grid of the underlying figures. All data comes
// from the open type-U maintenance contract's eta (out_date = start, expected_return_date = target;
// a null target falls back to the default window and the card is flagged "Estimated"). The whole card
// is the KPI the user asked for — no plain text ETA.
//
// Clicking the card opens the TICKET — everything that has happened to this car on this visit, which
// is what someone reading a stalled repair actually wants. A car we only know about from the sheet
// contract has no ticket yet, so it falls back to the vehicle profile. The "File update" button files
// the checkpoint (the promised date, why it moved, a note and photos) without leaving the Dashboard —
// this card replaced the /maintenance-progress queue, so the form has to live on it.
function RepairProgressCard({ item, onCheckpoint, busy, highlighted }) {
  const { t, tp } = useI18n();
  const days = daysWith(tp);
  const { id, plate, car, garage, eta, checkpoint, problem, problem_items, problem_type, source, ticket_id, other_tickets } = item || {};
  const e = eta || {};
  const to = ticket_id ? `/maintenance-workflow/${ticket_id}` : id ? `/vehicles/${id}` : '/maintenance-workflow';

  const el = e.days_elapsed ?? 0;     // total days in the workshop
  const al = e.days_allotted ?? 0;    // planned repair duration (target)
  const over = e.days_over ?? 0;
  const left = e.days_left ?? 0;
  const est = !!e.is_estimated;
  const status = e.status || 'on_track';

  // Fill ratio = elapsed / target; bar caps at 100% (keeps filling to full when overdue).
  const ratio = al > 0 ? el / al : (status === 'overdue' ? 1.2 : 1);
  const pct = Math.min(100, Math.round(ratio * 100));
  const colorKey = ratio > 1 ? 'red' : ratio >= 0.75 ? 'orange' : 'green';
  const c = PROGRESS_TONE[colorKey];

  const badge = status === 'overdue' ? t('dash.repair.overdueBy', { days: days(over) })
    : status === 'due_today' ? t('dash.repair.dueToday')
      : t('dash.repair.remainingIn', { days: days(left) });
  const remainTone = status === 'overdue' ? 'text-red-600' : status === 'due_today' ? 'text-amber-600' : 'text-emerald-600';

  return (
    <div
      id={flagCardId(item)}
      className={`group relative overflow-hidden rounded-2xl border bg-white p-3.5 pt-4 shadow-soft transition hover:-translate-y-0.5 hover:shadow-md ${
        highlighted
          ? 'border-indigo-400 ring-2 ring-indigo-400/60'
          : 'border-slate-200/70 hover:border-slate-300'
      }`}
    >
      {/* Tone accent strip + ambient wash — instant read of health before the eye reaches the bar.
          Positioned against the CARD, so they stay outside the content wrapper below. */}
      <div className={`pointer-events-none absolute inset-x-0 top-0 z-[1] h-1 bg-gradient-to-r ${c.accent}`} />
      <div className={`pointer-events-none absolute -end-8 -top-10 h-24 w-24 rounded-full ${c.glow} blur-2xl`} />

      {/* The whole card opens the ticket. It sits UNDER the content (which is click-through) so the
          one interactive control on the card — "File update" — can still take its own clicks. */}
      <Link
        to={to}
        aria-label={t('dash.repair.openTicket', { car: plate || car || t('dash.repair.vehicle') })}
        className="absolute inset-0 z-0 rounded-2xl focus-ring-self"
      />
      <div className="pointer-events-none relative z-10">
      {/* Vehicle header */}
      <div className="relative mb-3 flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="truncate text-sm font-bold text-slate-900">{plate || car || t('dash.repair.vehicle')}</p>
          <p className="truncate text-xs text-slate-400">{[car, garage].filter(Boolean).join(' · ') || '—'}</p>
        </div>
        <div className="flex shrink-0 flex-wrap items-center justify-end gap-1">
          {/* Data origin — sheet contract, app ticket, or both (traceability: no card without its source). */}
          <SourceBadge source={source} />
          {other_tickets > 0 && (
            <span
              className="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-500"
              title={t('dash.repair.otherTicketsHint', { n: other_tickets + 1 })}
            >
              {tp('dash.repair.moreTickets', other_tickets, { n: other_tickets })}
            </span>
          )}
          {est && (
            <span
              className="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-500"
              title={t(source === 'workshop' ? 'dash.repair.estimatedTicket' : 'dash.repair.estimatedContract')}
            >
              {t('dash.repair.estimated')}
            </span>
          )}
        </div>
      </div>

      {/* WHY the car is in the shop — the fault(s)/reason behind the visit. */}
      <div className="relative mb-3 rounded-xl bg-slate-50 px-2.5 py-2 ring-1 ring-slate-100">
        <p className="text-[10px] font-medium uppercase tracking-wide text-slate-400">{t('dash.repair.problem')}</p>
        {problem ? (
          <p className="truncate text-xs font-semibold text-slate-700" title={(problem_items || []).length > 1 ? problem_items.join(' · ') : problem}>
            {problem}{problem_type ? <span className="ms-1 font-normal text-slate-400">· {problem_type}</span> : null}
          </p>
        ) : (
          <p className="text-xs text-slate-400">
            {t(source === 'workshop' ? 'dash.repair.noFaultTicket' : 'dash.repair.noFaultContract')}
          </p>
        )}
      </div>

      {/* Progress bar */}
      <div className="relative">
        <div className="mb-1.5 flex items-baseline justify-between gap-2">
          <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{t('dash.repair.progress')}</span>
          <span className="flex items-baseline gap-1 tabular-nums">
            <span className={`text-lg font-extrabold leading-none ${c.pct}`}>{pct}%</span>
          </span>
        </div>
        <div className="mb-1.5 flex items-baseline justify-between text-xs font-semibold text-slate-700">
          <span>{t('dash.repair.day')} <span className="tabular-nums">{el}</span></span>
          <span className="text-slate-400">{t('dash.repair.target', { days: days(al) })}</span>
        </div>
        <div className={`h-2.5 w-full overflow-hidden rounded-full ring-1 ring-inset ring-slate-200/50 ${c.track}`}>
          <div
            className="relative h-full rounded-full transition-[width] duration-[900ms] ease-out"
            style={{ width: `${Math.max(3, pct)}%`, background: `linear-gradient(90deg, ${c.from}, ${c.to})` }}
          >
            <span className="absolute inset-x-0 top-0 h-1/2 rounded-full bg-white/25" />
          </div>
        </div>
        <div className="mt-2 flex flex-wrap items-center gap-2">
          <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ${c.badge}`}>
            <span className={`h-1.5 w-1.5 rounded-full ${c.dot} ${status === 'overdue' ? 'animate-pulse' : ''}`} />
            {badge}
          </span>
        </div>
      </div>

      {/* Last checkpoint filed on /maintenance-progress — the delay story (ETA change + reason + who/when). */}
      <div className="relative mt-2.5 rounded-xl bg-slate-50/70 px-2.5 py-2 ring-1 ring-slate-100">
        <p className="mb-0.5 text-[10px] font-medium uppercase tracking-wide text-slate-400">{t('dash.repair.latestCheckpoint')}</p>
        <CheckpointLine cp={checkpoint} />
      </div>

      {/* Underlying figures */}
      <dl className="relative mt-3 grid grid-cols-2 gap-x-3 gap-y-2 border-t border-slate-100 pt-3">
        <KpiCell label={t('dash.repair.inWorkshop')} value={days(el)} />
        <KpiCell label={t('dash.repair.planned')} value={`${days(al)}${est ? ` · ${t('dash.repair.estAbbr')}` : ''}`} />
        <KpiCell
          label={status === 'overdue' ? t('dash.repair.overdue') : t('dash.repair.remaining')}
          value={status === 'overdue' ? days(over) : status === 'due_today' ? t('dash.repair.dueToday') : days(left)}
          tone={remainTone}
        />
        <KpiCell label={t('dash.repair.expected')} value={fmtDate(e.expected_on) || '—'} />
        <KpiCell label={t('dash.repair.started')} value={fmtDate(e.started_on) || '—'} />
      </dl>
      </div>

      {/* File the workshop's progress update from here — the promised date, why it moved, a note and
          photos. A contract-only car has no ticket yet; the handler links one before opening the form. */}
      <div className="relative z-10 mt-3 flex justify-end border-t border-slate-100 pt-3">
        <button
          type="button"
          disabled={busy}
          onClick={() => onCheckpoint?.(item)}
          className="focus-ring-self inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-2.5 py-1.5 text-xs font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-60"
        >
          {busy ? t('dash.repair.opening') : t('dash.repair.fileUpdate')}
        </button>
      </div>
    </div>
  );
}

function ProactiveFlags({ data, loading, onReload }) {
  const { t, isRTL } = useI18n();
  const toast = useToast();
  const inShop = data?.in_maintenance || { count: 0, items: [] };
  const src = inShop.sources || {};
  // Memoised so the ?ticket= focus effect below doesn't re-run on every render.
  const allItems = useMemo(() => inShop.items || [], [inShop.items]);

  // Filing a progress update from a card. A contract-only row has no ticket until the first
  // checkpoint is filed, so resolveCheckpointTicket lazily links one (idempotent) before the form opens.
  const [active, setActive] = useState(null);   // { ticketId, label, sub }
  const [opening, setOpening] = useState(null); // flagKey of the card currently resolving its ticket
  const openCheckpoint = useCallback(async (row) => {
    const label = row.plate || row.car || t('dash.repair.vehicle');
    if (row.ticket_id) {
      setActive({ ticketId: row.ticket_id, label, sub: row.garage });
      return;
    }
    setOpening(flagKey(row));
    try {
      const ticketId = await resolveCheckpointTicket(row);
      if (ticketId) setActive({ ticketId, label, sub: row.garage });
      else toast.error(t('dash.repair.checkpointFailed'));
    } catch {
      toast.error(t('dash.repair.checkpointFailed'));
    } finally {
      setOpening(null);
    }
  }, [t, toast]);

  // A checkpoint reminder deep-links here as /dashboard?ticket=<id>. Scroll that card into view, ring
  // it, and open its form straight away — the reminder exists to get an update filed on THAT car.
  const [searchParams, setSearchParams] = useSearchParams();
  // The ring outlives the URL param: the param is dropped as soon as it is honoured (so a refresh
  // doesn't reopen the form), but the card stays marked for the rest of the visit.
  const [focusTicket, setFocusTicket] = useState(null);
  const consumedFocus = useRef(false);
  useEffect(() => {
    const wanted = Number(searchParams.get('ticket')) || null;
    if (!wanted || consumedFocus.current || !allItems.length) return;
    const row = allItems.find((it) => Number(it.ticket_id) === wanted);
    if (!row) return;
    consumedFocus.current = true;
    setFocusTicket(wanted);
    document.getElementById(flagCardId(row))?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    openCheckpoint(row);
    const next = new URLSearchParams(searchParams);
    next.delete('ticket');
    setSearchParams(next, { replace: true });
  }, [allItems, openCheckpoint, searchParams, setSearchParams]);

  // Provenance filter — look at the whole shop, or only the cars we know about from ONE record.
  // 'both' cars (contract AND live ticket) satisfy either single-source filter, since they genuinely
  // exist in both systems.
  const [sourceFilter, setSourceFilter] = useState('all');
  const matchesSource = (it) =>
    sourceFilter === 'all' ||
    it.source === sourceFilter ||
    (it.source === 'both' && (sourceFilter === 'contract' || sourceFilter === 'workshop'));
  const items = allItems.filter(matchesSource);

  const SOURCE_FILTERS = [
    { key: 'all',      label: t('dash.flags.filterAll'),      count: inShop.count },
    { key: 'contract', label: t('dash.source.contract.label'), count: (src.contract || 0) + (src.both || 0) },
    { key: 'workshop', label: t('dash.source.workshop.label'), count: (src.workshop || 0) + (src.both || 0) },
  ];

  const groups = [
    {
      // Every car in the shop right now rendered as a visual Repair-Progress KPI card (progress bar +
      // figures), not a text row. `cardItems` switches the renderer from the row list to the card grid.
      key: 'maintenance', title: t('dash.flags.inMaintenance'), icon: <Icon.Wrench className="h-4 w-4" />, tone: 'blue',
      count: inShop.count, viewAll: '/maintenance-workflow',
      empty: t(sourceFilter === 'all' ? 'dash.flags.emptyAll' : 'dash.flags.emptySource'),
      cardItems: items,
      // The provenance filter, doubling as the split ("7 from sheet · 10 from system").
      filter: (
        <div className="flex shrink-0 flex-wrap items-center gap-1" role="group" aria-label={t('dash.flags.filterAria')}>
          {SOURCE_FILTERS.map((f) => {
            const on = sourceFilter === f.key;
            return (
              <button
                key={f.key}
                type="button"
                onClick={() => setSourceFilter(f.key)}
                aria-pressed={on}
                title={t(`dash.flags.filterHint.${f.key}`)}
                className={`rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 transition ${
                  on
                    ? 'bg-indigo-600 text-white ring-indigo-600'
                    : 'bg-white text-slate-500 ring-slate-200 hover:bg-slate-50 hover:text-slate-700'
                }`}
              >
                {f.label} <span className="tabular-nums opacity-80">{f.count}</span>
              </button>
            );
          })}
        </div>
      ),
    },
  ];

  const totalCount = groups.reduce((s, g) => s + (g.count || 0), 0);

  return (
    <SectionCard
      title={
        <span className="flex items-center gap-1.5">
          {t('dash.flags.title')}
          <InfoTip content={t('dash.flags.tooltip')} />
        </span>
      }
      subtitle={t('dash.flags.subtitle')}
      actions={<Badge tone={totalCount ? 'amber' : 'gray'}>{totalCount}</Badge>}
    >
      {loading ? (
        <div className="grid grid-cols-1 gap-4">
          <Skeleton className="h-40 rounded-2xl" />
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-4">
          {groups.map((g) => (
            <div
              key={g.key}
              className="rounded-2xl border border-slate-200/60 bg-white p-4 shadow-soft"
            >
              <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div className="flex min-w-0 items-center gap-2">
                  <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-xl ${TILE_TONE_SOFT[g.tone]}`}>{g.icon}</span>
                  <h3 className="truncate text-sm font-semibold text-slate-800">{g.title}</h3>
                  <Badge tone={g.count ? g.tone : 'gray'}>{g.count}</Badge>
                  {g.note && <span className="truncate text-xs font-medium text-slate-400">{g.note}</span>}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  {g.filter}
                  <Link to={g.viewAll} className="shrink-0 text-xs font-medium text-indigo-600 hover:text-indigo-700">{t('dash.all')} {isRTL ? '←' : '→'}</Link>
                </div>
              </div>
              {g.cardItems ? (
                // In Maintenance — a responsive grid of visual Repair-Progress KPI cards, one per car.
                g.cardItems.length === 0 ? (
                  <p className="py-6 text-center text-xs text-slate-400">{g.empty}</p>
                ) : (
                  <div className="grid grid-cols-1 gap-3 md:grid-cols-2 2xl:grid-cols-3">
                    {g.cardItems.map((it) => (
                      <RepairProgressCard
                        key={flagKey(it)}
                        item={it}
                        onCheckpoint={openCheckpoint}
                        busy={opening === flagKey(it)}
                        highlighted={!!focusTicket && Number(it.ticket_id) === focusTicket}
                      />
                    ))}
                  </div>
                )
              ) : g.rows.length === 0 ? (
                <p className="py-6 text-center text-xs text-slate-400">{g.empty}</p>
              ) : (
                <ul className="space-y-1">
                  {g.rows.map((row, i) => (
                    <li key={i}>
                      <Link to={row.to} className="flex items-center justify-between gap-3 rounded-xl px-2.5 py-2 hover:bg-slate-50">
                        <div className="min-w-0">
                          <p className="truncate text-sm font-semibold text-slate-800">{row.primary}</p>
                          <p className="truncate text-xs text-slate-400">{row.secondary || '—'}</p>
                        </div>
                        <div className="flex shrink-0 flex-col items-end">
                          {row.rightNode || (
                            <>
                              <span className={`text-sm font-bold tabular-nums ${row.rightTone}`}>{row.right}</span>
                              {row.rightSub}
                            </>
                          )}
                        </div>
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          ))}
        </div>
      )}

      {/* The checkpoint form — the same one the retired /maintenance-progress queue used. Filing an
          update refreshes the flags so the card's ETA and "latest checkpoint" line move immediately. */}
      {active && (
        <CheckpointModal
          open={!!active}
          ticketId={active.ticketId}
          title={t('dash.repair.checkpointTitle', { label: active.label })}
          subtitle={active.sub || undefined}
          onClose={() => setActive(null)}
          onDone={(msg) => { setActive(null); toast.success(msg || t('dash.repair.checkpointFiled')); onReload?.(); }}
        />
      )}
    </SectionCard>
  );
}

// Inspection Accuracy — a single oversight signal scored as right-vs-wrong. `total` is everything the
// inspector handled (grades given / faults called / readings taken), `wrong` is the subset that was
// flagged. We show the accuracy rate as the headline, the raw right/wrong split below, and a two-tone
// bar so a card with a handful of misses on a big volume reads green (strong) at a glance.
// Tone ramp for the accuracy gauge — strong (green) ≥90%, watch (amber) ≥75%, poor (red) below —
// with a matching gauge gradient, verdict word, and soft ambient glow so each card reads as one piece.
const ACCURACY_TONE = {
  emerald: { text: 'text-emerald-600', bar: 'bg-emerald-500', chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200', from: '#34d399', to: '#059669', glow: 'bg-emerald-400/20', verdict: 'strong' },
  amber:   { text: 'text-amber-600',   bar: 'bg-amber-500',   chip: 'bg-amber-50 text-amber-700 ring-amber-200',       from: '#fbbf24', to: '#d97706', glow: 'bg-amber-400/20',   verdict: 'watch' },
  red:     { text: 'text-rose-600',    bar: 'bg-rose-500',    chip: 'bg-rose-50 text-rose-700 ring-rose-200',          from: '#fb7185', to: '#e11d48', glow: 'bg-rose-400/20',     verdict: 'review' },
};

function AccuracyCard({ title, icon, to, total, wrong, rightLabel, wrongLabel, tooltip, loading }) {
  // `tx` (not `t`) — this component already uses `t` for the total count.
  const { t: tx, lang } = useI18n();
  const numLocale = lang === 'ar' ? 'ar-AE-u-nu-latn' : 'en-US';
  const t = Math.max(0, Number(total) || 0);
  const w = Math.min(t, Math.max(0, Number(wrong) || 0));
  const right = t - w;
  const rate = t > 0 ? Math.round((right / t) * 100) : 100;
  const tone = rate >= 90 ? 'emerald' : rate >= 75 ? 'amber' : 'red';
  const c = ACCURACY_TONE[tone];
  const gid = `acc-${String(title).replace(/\W+/g, '-').toLowerCase()}`;   // unique gradient id per card
  // Circular gauge geometry — a single ring whose filled arc = the accuracy rate.
  const R = 42;
  const CIRC = 2 * Math.PI * R;
  const dashOffset = loading ? CIRC : CIRC * (1 - rate / 100);

  return (
    <Link
      to={to}
      className="group relative flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-soft transition hover:-translate-y-0.5 hover:shadow-md"
    >
      {/* ambient tone wash in the corner — subtle, matches the verdict */}
      <div className={`pointer-events-none absolute -end-8 -top-10 h-28 w-28 rounded-full ${c.glow} blur-2xl`} />

      <div className="relative flex items-center justify-between">
        <div className="flex items-center gap-2">
          <span className={`flex h-8 w-8 items-center justify-center rounded-xl ring-1 ${c.chip}`}>{icon}</span>
          <p className="flex items-center gap-1 text-sm font-semibold text-slate-800">
            {title}
            <InfoTip content={tooltip} />
          </p>
        </div>
        <Icon.ArrowRight className="h-4 w-4 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-slate-400" />
      </div>

      <div className="relative mt-4 flex items-center gap-5">
        {/* Circular accuracy gauge — filled arc = right share, soft track = wrong remainder. */}
        <div className="relative h-24 w-24 shrink-0">
          <svg viewBox="0 0 100 100" className="h-full w-full -rotate-90">
            <defs>
              <linearGradient id={gid} x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" stopColor={c.from} />
                <stop offset="100%" stopColor={c.to} />
              </linearGradient>
            </defs>
            <circle cx="50" cy="50" r={R} fill="none" strokeWidth="9" className="stroke-slate-100" />
            <circle
              cx="50" cy="50" r={R} fill="none" strokeWidth="9" strokeLinecap="round"
              stroke={`url(#${gid})`}
              strokeDasharray={CIRC}
              strokeDashoffset={dashOffset}
              style={{ transition: 'stroke-dashoffset 0.9s cubic-bezier(0.22,1,0.36,1)' }}
            />
          </svg>
          <div className="absolute inset-0 flex flex-col items-center justify-center">
            <span className={`font-display text-2xl font-bold leading-none tabular-nums ${c.text}`}>
              {loading ? '—' : `${rate}%`}
            </span>
            <span className="mt-0.5 text-[10px] font-medium uppercase tracking-wide text-slate-400">{tx('dash.accuracy.label')}</span>
          </div>
        </div>

        <div className="min-w-0 flex-1 space-y-2.5">
          {/* Verdict — a plain-language read of the rate, instead of the raw miss count. */}
          <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ${c.chip}`}>
            <span className={`h-1.5 w-1.5 rounded-full ${c.bar}`} />{loading ? '—' : tx(`dash.accuracy.verdict.${c.verdict}`)}
          </span>
          <div className="space-y-1.5 text-xs">
            <span className="flex items-baseline gap-1.5 font-medium text-slate-500">
              <span className={`h-2 w-2 shrink-0 self-center rounded-full ${c.bar}`} />
              <span className="tabular-nums text-base font-extrabold text-slate-900">{loading ? '—' : right.toLocaleString(numLocale)}</span> {rightLabel}
            </span>
            <span className="flex items-baseline gap-1.5 font-medium text-slate-500">
              <span className="h-2 w-2 shrink-0 self-center rounded-full bg-slate-300" />
              <span className="tabular-nums text-base font-extrabold text-slate-900">{loading ? '—' : w.toLocaleString(numLocale)}</span> {wrongLabel}
            </span>
          </div>
        </div>
      </div>
    </Link>
  );
}

// Most Frequent Faults — a ranked "Fault Leaderboard". Each fault is one maintenance task (inspector
// test-drive or garage finding); cancelled / not-found faults are excluded server-side so the board
// only counts issues that really happened. Bar LENGTH = how OFTEN it happens, bar COLOUR = how BAD it
// is (worst severity ever graded). Reads /Dashboard/top-faults (ranked + capped server-side). Honest
// magnitude comparison (bars, shared scale) with severity as a second, labelled encoding — never
// colour alone.

// Severity → colour (status palette) + gradient + human label. Always shown beside the chip text.
// `glyph` tints the leading emoji disc, `halo` is a soft ambient wash behind the featured #1 offender.
const SEVERITY_META = {
  critical: { key: 'critical', from: '#fb7185', to: '#e11d48', text: 'text-rose-700',    soft: 'bg-rose-50 text-rose-700 ring-rose-200',       dot: 'bg-rose-500',   glyph: 'bg-rose-100 text-rose-700 ring-rose-200',       halo: 'from-rose-500/15' },
  high:     { key: 'high',         from: '#fdba74', to: '#ea580c', text: 'text-orange-700',  soft: 'bg-orange-50 text-orange-700 ring-orange-200', dot: 'bg-orange-500', glyph: 'bg-orange-100 text-orange-700 ring-orange-200', halo: 'from-orange-500/15' },
  moderate: { key: 'moderate',     from: '#fcd34d', to: '#d97706', text: 'text-amber-700',   soft: 'bg-amber-50 text-amber-700 ring-amber-200',    dot: 'bg-amber-500',  glyph: 'bg-amber-100 text-amber-700 ring-amber-200',    halo: 'from-amber-400/15' },
  routine:  { key: 'routine',      from: '#6ee7b7', to: '#059669', text: 'text-emerald-700', soft: 'bg-emerald-50 text-emerald-700 ring-emerald-200', dot: 'bg-emerald-500', glyph: 'bg-emerald-100 text-emerald-700 ring-emerald-200', halo: 'from-emerald-400/15' },
  unknown:  { key: 'unknown',      from: '#cbd5e1', to: '#64748b', text: 'text-slate-600',   soft: 'bg-slate-100 text-slate-600 ring-slate-200',   dot: 'bg-slate-400',  glyph: 'bg-slate-100 text-slate-500 ring-slate-200',    halo: 'from-slate-400/10' },
};
const sevMeta = (s) => SEVERITY_META[s] || SEVERITY_META.unknown;

// Podium styling for the top three ranks — gold / silver / bronze medals. Everything else is a plain chip.
const RANK_MEDAL = {
  0: 'bg-gradient-to-br from-amber-300 to-amber-500 text-white shadow-sm ring-1 ring-amber-300/60',
  1: 'bg-gradient-to-br from-slate-200 to-slate-400 text-white shadow-sm ring-1 ring-slate-300/60',
  2: 'bg-gradient-to-br from-orange-300 to-orange-500 text-white shadow-sm ring-1 ring-orange-300/60',
};

// A little life: an emoji per fault family, matched on the category label. Purely decorative.
function faultGlyph(fault = '') {
  const f = fault.toLowerCase();
  if (/brake|pedal/.test(f)) return '🛑';
  if (/cooling|overheat|coolant|temp/.test(f)) return '🌡️';
  if (/ac|climate|air/.test(f)) return '❄️';
  if (/electric|batter|ignition|alternator|start/.test(f)) return '🔋';
  if (/suspension|steering|tyre|tire|wheel|align|bump/.test(f)) return '🛞';
  if (/transmission|gearbox|clutch/.test(f)) return '⚙️';
  if (/exhaust|emission/.test(f)) return '💨';
  if (/fuel|injector/.test(f)) return '⛽';
  if (/safety|airbag|seatbelt/.test(f)) return '🛡️';
  if (/body|interior|chair|door|glass/.test(f)) return '🚗';
  if (/oil|fluid|filter|leak/.test(f)) return '🛢️';
  if (/engine|mechanical|misfire|idle|power/.test(f)) return '🔧';
  return '⚠️';
}

function MostFrequentFaults() {
  const { t, tp, lang } = useI18n();
  const numLocale = lang === 'ar' ? 'ar-AE-u-nu-latn' : 'en-US';
  const num = (n) => Number(n || 0).toLocaleString(numLocale);
  const [data, setData] = useState({ items: [], total: 0 });
  const [loading, setLoading] = useState(true);
  const [grown, setGrown] = useState(false);   // flips true after mount → bars animate their width in
  // Per-fault "who fixes this most" drill-down: which fault row is open + its fetched car list (cached).
  const [openFault, setOpenFault] = useState(null);
  const [cars, setCars] = useState({}); // { [fault]: { loading, items, total, cars, error } }

  useEffect(() => {
    let alive = true;
    setLoading(true);
    api.get('/Dashboard/top-faults', { params: { limit: 6 } })
      .then((res) => { if (alive) setData(res.data.data || { items: [], total: 0 }); })
      .catch(() => { if (alive) setData({ items: [], total: 0 }); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, []);

  // Toggle a fault open; fetch its top cars once (cached in state afterwards).
  const toggleFault = (fault) => {
    setOpenFault((cur) => (cur === fault ? null : fault));
    if (!cars[fault]) {
      setCars((c) => ({ ...c, [fault]: { loading: true, items: [], error: false } }));
      api.get('/Dashboard/fault-cars', { params: { fault, limit: 8 } })
        .then((res) => {
          const d = res.data.data || {};
          setCars((c) => ({ ...c, [fault]: { loading: false, items: d.items || [], total: d.total || 0, cars: d.cars || 0, error: false } }));
        })
        .catch(() => setCars((c) => ({ ...c, [fault]: { loading: false, items: [], error: true } })));
    }
  };

  // Kick the grow-in one frame after the rows render.
  useEffect(() => {
    if (loading || !data.items?.length) return undefined;
    const id = requestAnimationFrame(() => setGrown(true));
    return () => cancelAnimationFrame(id);
  }, [loading, data.items]);

  const items = data.items || [];
  const max = items.reduce((m, it) => Math.max(m, it.count), 0) || 1;
  const worst = items[0];

  return (
    <SectionCard
      title={
        <span className="flex items-center gap-1.5">
          {t('dash.faults.title')}
          <InfoTip content={t('dash.faults.tooltip')} />
        </span>
      }
      subtitle={t('dash.faults.subtitle')}
      actions={<Link to="/vehicles?tab=per-car" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">{t('dash.faults.history')} →</Link>}
    >
      {loading ? (
        <ul className="space-y-3">
          {Array.from({ length: 6 }).map((_, i) => <li key={i}><Skeleton className="h-11 rounded-xl" /></li>)}
        </ul>
      ) : items.length === 0 ? (
        <p className="py-8 text-center text-sm text-slate-400">{t('dash.faults.empty')}</p>
      ) : (
        <div>
          {/* Headline: total faults on record + the current worst offender, side by side. */}
          <div className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
            {/* Total faults — the running tally, with its two sources broken out as pills. */}
            <div className="rounded-2xl bg-gradient-to-br from-slate-50 to-white p-4 ring-1 ring-slate-200/70">
              <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{t('dash.faults.onRecord')}</p>
              <p className="mt-0.5 text-[2rem] font-extrabold leading-none tabular-nums text-slate-900">
                <CountUp value={data.total} format={(n) => num(Math.round(n))} />
              </p>
              <div className="mt-2.5 flex flex-wrap items-center gap-1.5 text-[11px] font-semibold tabular-nums">
                <span className="inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 text-slate-600 ring-1 ring-slate-200" title={t('dash.faults.sheetHint')}>
                  <span aria-hidden>🗒️</span>{num(data.sheet_total)}
                  <span className="font-medium text-slate-400">{t('dash.faults.sheet')}</span>
                </span>
                <span className="inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 text-slate-600 ring-1 ring-slate-200" title={t('dash.faults.systemHint')}>
                  <span aria-hidden>⚙️</span>{num(data.system_total)}
                  <span className="font-medium text-slate-400">{t('dash.faults.system')}</span>
                </span>
              </div>
            </div>

            {/* Featured worst offender — the #1 fault, blown up as a hero tile with an ambient severity wash. */}
            {worst && (() => {
              const wm = sevMeta(worst.severity);
              const wshare = data.total ? Math.round((worst.count / data.total) * 100) : 0;
              return (
                <div className={`relative overflow-hidden rounded-2xl bg-white p-4 ring-1 ring-slate-200/70`}>
                  <div className={`pointer-events-none absolute -end-6 -top-8 h-28 w-28 rounded-full bg-gradient-to-br ${wm.halo} to-transparent blur-xl`} />
                  <div className="relative flex items-center justify-between">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{t('dash.faults.topOffender')}</p>
                    <span className={`inline-flex items-center gap-1 rounded-full px-1.5 py-px text-[11px] font-semibold ring-1 ${wm.soft}`}>
                      <span className={`h-1.5 w-1.5 rounded-full ${wm.dot}`} />{t(`dash.severity.${wm.key}`)}
                    </span>
                  </div>
                  <div className="relative mt-2 flex items-center gap-2.5">
                    <span className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-2xl ring-1 ${wm.glyph}`} aria-hidden>
                      {faultGlyph(worst.fault)}
                    </span>
                    <div className="min-w-0">
                      <p className="truncate text-sm font-bold text-slate-900">{worst.fault}</p>
                      <p className="text-[11px] font-medium tabular-nums text-slate-500">
                        <span className="font-extrabold text-slate-800">{num(worst.count)}</span> {t('dash.faults.reportsShare', { pct: wshare })}
                      </p>
                    </div>
                  </div>
                </div>
              );
            })()}
          </div>

          {/* Ranked bars — length = frequency, colour = severity, medals for the podium.
              Click a row to reveal the cars that racked up this fault the most. */}
          <ol className="space-y-1">
            {items.map((it, i) => {
              const m = sevMeta(it.severity);
              const pct = Math.max(6, Math.round((it.count / max) * 100));   // floor so tiny bars still read
              const share = data.total ? Math.round((it.count / data.total) * 100) : 0;
              const medal = RANK_MEDAL[i];
              const isOpen = openFault === it.fault;
              const cd = cars[it.fault];
              return (
                <li key={it.fault} className={`rounded-xl transition-colors ${isOpen ? 'bg-slate-50 ring-1 ring-slate-200/70' : ''}`}>
                  <button
                    type="button"
                    onClick={() => toggleFault(it.fault)}
                    aria-expanded={isOpen}
                    className="group flex w-full items-center gap-3 rounded-xl px-2 py-2 text-start transition-colors hover:bg-slate-50"
                  >
                    {/* rank — medal for the top three, plain chip below */}
                    <span className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-xs font-extrabold tabular-nums ${
                      medal || 'bg-slate-100 text-slate-500'
                    }`}>
                      {i + 1}
                    </span>

                    {/* label + bar */}
                    <div className="min-w-0 flex-1">
                      <div className="mb-1 flex items-baseline justify-between gap-2">
                        <span className="flex min-w-0 items-center gap-1.5 text-[13px] font-semibold text-slate-800">
                          <span aria-hidden className="shrink-0">{faultGlyph(it.fault)}</span>
                          <span className="truncate">{it.fault}</span>
                          <Icon.ChevronDown className={`h-3.5 w-3.5 shrink-0 text-slate-300 transition-transform group-hover:text-indigo-500 ${isOpen ? 'rotate-180 text-indigo-500' : ''}`} />
                        </span>
                        <span className="flex shrink-0 items-baseline gap-1 tabular-nums">
                          <span className="text-base font-extrabold text-slate-900">{num(it.count)}</span>
                          <span className="text-[11px] font-medium text-slate-400">
                            {tp('dash.faults.times', it.count)}
                          </span>
                        </span>
                      </div>

                      <div className="relative h-3 w-full overflow-hidden rounded-full bg-slate-100 ring-1 ring-inset ring-slate-200/60">
                        <div
                          className="relative h-full rounded-full transition-[width] duration-[900ms] ease-out"
                          style={{
                            width: grown ? `${pct}%` : '0%',
                            transitionDelay: `${i * 80}ms`,
                            background: `linear-gradient(90deg, ${m.from}, ${m.to})`,
                          }}
                        >
                          {/* glossy top highlight so the fill reads as a solid, lit pill */}
                          <span className="absolute inset-x-0 top-0 h-1/2 rounded-full bg-white/25" />
                        </div>
                      </div>

                      {/* severity + source split + spread — the labelled second encoding (never colour-alone). */}
                      <div className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px]">
                        <span className={`inline-flex items-center gap-1 rounded-full px-1.5 py-px font-semibold ring-1 ${m.soft}`}>
                          <span className={`h-1.5 w-1.5 rounded-full ${m.dot}`} />{t(`dash.severity.${m.key}`)}
                        </span>
                        <span className="tabular-nums text-slate-400" title={t('dash.faults.splitHint')}>
                          🗒️ {num(it.sheet)} · ⚙️ {num(it.system)}
                        </span>
                        <span className="text-slate-300">·</span>
                        <span className={`tabular-nums font-medium ${isOpen ? 'text-indigo-600' : 'text-slate-400 group-hover:text-indigo-500'}`}>
                          {tp('dash.faults.cars', it.cars)} →
                        </span>
                        <span className="text-slate-300">·</span>
                        <span className="tabular-nums font-medium text-slate-400">{t('dash.faults.ofTotal', { pct: share })}</span>
                      </div>
                    </div>
                  </button>

                  {isOpen && (
                    <div className="px-2 pb-3 pt-0.5">
                      <FaultCarBreakdown detail={cd} fault={it.fault} sevMeta={m} />
                    </div>
                  )}
                </li>
              );
            })}
          </ol>
        </div>
      )}
    </SectionCard>
  );
}

// The per-fault drill-down: "which cars fixed this fault the most". Given one fault category, ranks the
// vehicles that racked it up — medal for the podium, a mini-bar scaled to the top offender, plate/model
// linking to the profile, and the sheet-vs-system split. Reads /Dashboard/fault-cars?fault=… (lazy).
function FaultCarBreakdown({ detail, fault, sevMeta: m }) {
  const { t, tp, lang } = useI18n();
  const numLocale = lang === 'ar' ? 'ar-AE-u-nu-latn' : 'en-US';
  const num = (n) => Number(n || 0).toLocaleString(numLocale);
  if (!detail || detail.loading) {
    return (
      <div className="space-y-1.5 rounded-xl bg-white p-2 ring-1 ring-slate-200/70">
        {[0, 1, 2].map((i) => <Skeleton key={i} className="h-9 rounded-lg" />)}
      </div>
    );
  }
  if (detail.error) {
    return <p className="rounded-xl bg-white px-3 py-3 text-xs text-rose-600 ring-1 ring-slate-200/70">{t('dash.faultCars.error')}</p>;
  }
  if (!detail.items.length) {
    return <p className="rounded-xl bg-white px-3 py-3 text-xs text-slate-400 ring-1 ring-slate-200/70">{t('dash.faultCars.empty')}</p>;
  }

  const topCount = detail.items[0]?.count || 1;
  const leader = detail.items[0];

  return (
    <div className="overflow-hidden rounded-xl bg-white ring-1 ring-slate-200/70">
      {/* Header strip — who's the repeat offender for THIS fault. */}
      <div className="flex items-center justify-between gap-2 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white px-3 py-2">
        <p className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
          <Icon.Car className="h-3.5 w-3.5 text-slate-400" />
          {t('dash.faultCars.title', { fault })}
        </p>
        <span className="text-[11px] font-medium tabular-nums text-slate-400">
          {tp('dash.faults.cars', detail.cars)} · {t('dash.faultCars.total', { n: num(detail.total) })}
        </span>
      </div>

      <ol className="divide-y divide-slate-50">
        {detail.items.map((c, i) => {
          const pct = Math.max(8, Math.round((c.count / topCount) * 100));
          const medal = RANK_MEDAL[i];
          const isLeader = i === 0;
          return (
            <li key={c.id}>
              <Link
                to={`/vehicles/${c.id}`}
                className="group/car flex items-center gap-2.5 px-3 py-2 transition-colors hover:bg-indigo-50/50"
              >
                {/* rank medal / chip */}
                <span className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-[11px] font-extrabold tabular-nums ${
                  medal || 'bg-slate-100 text-slate-500'
                }`}>
                  {i + 1}
                </span>

                <div className="min-w-0 flex-1">
                  <div className="flex items-baseline justify-between gap-2">
                    <span className="flex min-w-0 items-baseline gap-1.5">
                      <span className="truncate font-mono text-[13px] font-semibold text-slate-900 group-hover/car:text-indigo-600">
                        {c.plate || `#${c.id}`}
                      </span>
                      {c.car && <span className="truncate text-[11px] text-slate-400">{c.car}</span>}
                      {isLeader && (
                        <span className="hidden shrink-0 rounded-full bg-rose-50 px-1.5 py-px text-[10px] font-semibold text-rose-600 ring-1 ring-rose-200 sm:inline">
                          {t('dash.faultCars.repeatOffender')}
                        </span>
                      )}
                    </span>
                    <span className="flex shrink-0 items-baseline gap-1 tabular-nums">
                      <span className="text-sm font-extrabold text-slate-900">{num(c.count)}</span>
                      <span className="text-[10px] font-medium text-slate-400">×</span>
                    </span>
                  </div>

                  {/* mini bar scaled to this fault's top offender, tinted by the fault's severity */}
                  <div className="mt-1 flex items-center gap-2">
                    <div className="relative h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100">
                      <div
                        className="h-full rounded-full"
                        style={{ width: `${pct}%`, background: `linear-gradient(90deg, ${m.from}, ${m.to})` }}
                      />
                    </div>
                    <span className="shrink-0 text-[10px] tabular-nums text-slate-400" title={t('dash.faultCars.splitHint')}>
                      🗒️{num(c.sheet)} · ⚙️{num(c.system)}
                    </span>
                  </div>
                </div>

                <Icon.ArrowRight className="h-3.5 w-3.5 shrink-0 text-slate-300 transition group-hover/car:translate-x-0.5 group-hover/car:text-indigo-500" />
              </Link>
            </li>
          );
        })}
      </ol>

      {/* One interpolated sentence rather than JSX fragments around each figure:
          the clause order differs in Arabic, so the numbers have to be able to
          move within the sentence. */}
      {leader && (
        <div className="border-t border-slate-100 bg-slate-50/60 px-3 py-2 text-[11px] text-slate-500">
          <span className="font-mono font-semibold text-slate-700">{leader.plate || `#${leader.id}`}</span>{' '}
          {tp('dash.faultCars.leads', leader.count, { n: num(leader.count), fault: fault.toLowerCase() })}
          {detail.total > leader.count
            && ` — ${t('dash.faultCars.leadShare', {
              pct: Math.round((leader.count / detail.total) * 100),
              total: num(detail.total),
            })}`}.
        </div>
      )}
    </div>
  );
}

// Most Maintained Cars — the individual VEHICLES ranked by TOTAL LIFETIME days in maintenance: the
// sum of every maintenance period the car has ever had (type-U maintenance contracts, out→in; an
// open stay counts to today), all-time, no window, rental time ignored. Reads the purpose-built
// /Dashboard/most-maintained-cars, which already ranks + caps server-side.
// Real elapsed downtime → a compact { n, u } label: whole days for ≥ 1 day, hours for anything less
// (a short same-day shop visit now reads as e.g. "9h", not a rounded-up whole day). Falls back to the
// rounded-day int when no second-precision figure is present.
const durParts = (seconds, fallbackDays = 0) => {
  const s = Number(seconds);
  if (!Number.isFinite(s) || s <= 0) return { n: Math.round(fallbackDays) || 0, u: 'd' };
  if (s < 86400) return { n: Math.max(1, Math.round(s / 3600)), u: 'h' };
  return { n: Math.round(s / 86400), u: 'd' };
};
function MostMaintainedCars() {
  const { t, lang } = useI18n();
  const numLocale = lang === 'ar' ? 'ar-AE-u-nu-latn' : 'en-US';
  const num = (n) => Number(n || 0).toLocaleString(numLocale);
  // Duration unit suffixes ("12d" / "9h") are language-dependent.
  const dur = (seconds, fallbackDays) => {
    const { n, u } = durParts(seconds, fallbackDays);
    return `${num(n)}${t(`dash.unit.${u}`)}`;
  };
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let alive = true;
    setLoading(true);
    // Ranked by true downtime days (Rental is King) — same numbers as Fleet Utilization (All Time).
    api.get('/Dashboard/most-maintained-cars', { params: { limit: 8, sort: 'downtime' } })
      .then((res) => { if (alive) setRows(res.data.data?.items || []); })
      .catch(() => { if (alive) setRows([]); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, []);

  return (
    <SectionCard
      title={
        <span className="flex items-center gap-1.5">
          {t('dash.maintained.title')}
          <InfoTip content={t('dash.maintained.tooltip')} />
        </span>
      }
      subtitle={t('dash.maintained.subtitle')}
      actions={
        <Link to="/vehicles?tab=per-car" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">{t('dash.maintained.allCars')} →</Link>
      }
    >
      {loading ? (
        <ul className="space-y-2">
          {Array.from({ length: 6 }).map((_, i) => <li key={i}><Skeleton className="h-10 rounded-xl" /></li>)}
        </ul>
      ) : rows.length === 0 ? (
        <p className="py-8 text-center text-sm text-slate-400">{t('dash.maintained.empty')}</p>
      ) : (
        <ol className="space-y-1">
          {rows.map((r, i) => {
            // rented / shop / idle split of in-service time — same Rental-is-King numbers as Fleet
            // Utilization, driven off the PRECISE seconds so sub-day slices still show. Denominator is
            // the segment sum so the bar always fills exactly.
            const rentSec = Number(r.rented_seconds ?? r.days_rented * 86400);
            const shopSec = Number(r.maintenance_seconds ?? r.days_in_shop * 86400);
            const idleSec = Number(r.idle_seconds ?? r.days_idle * 86400);
            const splitTotal = (rentSec + shopSec + idleSec) || 1;
            const segW = (v) => `${((v / splitTotal) * 100).toFixed(1)}%`;
            const shop = durParts(r.maintenance_seconds, r.days_in_shop);
            const medal = RANK_MEDAL[i];
            return (
              <li key={r.id} className="group flex items-center gap-3 rounded-xl px-2 py-2 transition-colors hover:bg-slate-50">
                <span className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-xs font-extrabold tabular-nums ${
                  medal || 'bg-slate-100 text-slate-500'
                }`}>
                  {i + 1}
                </span>
                <div className="min-w-0 flex-1">
                  <div className="flex items-baseline justify-between gap-2">
                    <div className="min-w-0">
                      <Link to={`/vehicles/${r.id}`} className="flex items-center gap-1.5 truncate text-sm font-semibold text-slate-800 hover:text-indigo-600">
                        <span className="truncate">{r.plate || `#${r.id}`}</span>
                        {r.currently_in_shop && (
                          <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-amber-100 px-1.5 py-px text-[10px] font-semibold text-amber-700" title={t('dash.maintained.inShopHint')}>
                            <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-amber-500" />{t('dash.maintained.inShop')}
                          </span>
                        )}
                      </Link>
                      {r.car && <p className="truncate text-[11px] text-slate-400">{r.car}</p>}
                    </div>
                    {/* The ranking metric — true off-road shop time — featured in a rose tint. */}
                    <span className="flex shrink-0 items-baseline gap-0.5 rounded-lg bg-rose-50 px-2 py-0.5 tabular-nums ring-1 ring-rose-100" title={t('dash.maintained.shopTimeHint')}>
                      <span className="text-base font-extrabold text-rose-700">{num(shop.n)}</span>
                      <span className="text-[11px] font-semibold text-rose-400">{t(`dash.unit.${shop.u}`)}</span>
                    </span>
                  </div>

                  <div className="mt-1.5 flex h-2 w-full overflow-hidden rounded-full bg-slate-100 ring-1 ring-inset ring-slate-200/60" title={t('dash.maintained.splitHint', {
                    rented: dur(r.rented_seconds, r.days_rented),
                    shop: dur(r.maintenance_seconds, r.days_in_shop),
                    idle: dur(r.idle_seconds, r.days_idle),
                  })}>
                    {rentSec > 0 && <div className="relative bg-gradient-to-b from-emerald-400 to-emerald-500" style={{ width: segW(rentSec) }}><span className="absolute inset-x-0 top-0 h-1/2 bg-white/25" /></div>}
                    {shopSec > 0 && <div className="relative bg-gradient-to-b from-rose-400 to-rose-500" style={{ width: segW(shopSec) }}><span className="absolute inset-x-0 top-0 h-1/2 bg-white/25" /></div>}
                    {idleSec > 0 && <div className="relative bg-slate-300" style={{ width: segW(idleSec) }} />}
                  </div>

                  {/* Full split — rented + util%, shop, idle, and total in-service time (matches Fleet Utilization). */}
                  <p className="mt-1.5 flex flex-wrap items-center gap-x-2.5 gap-y-0.5 text-[11px] tabular-nums text-slate-500">
                    <span className="inline-flex items-center gap-1" title={t('dash.maintained.rentedHint')}>
                      <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />{dur(r.rented_seconds, r.days_rented)} {t('dash.maintained.rented')}
                    </span>
                    {r.utilization_pct != null && (
                      <span className="font-semibold text-emerald-600" title={t('dash.maintained.utilHint')}>{r.utilization_pct}%</span>
                    )}
                    <span className="inline-flex items-center gap-1" title={t('dash.maintained.shopHint')}>
                      <span className="h-1.5 w-1.5 rounded-full bg-rose-500" />{dur(r.maintenance_seconds, r.days_in_shop)} {t('dash.maintained.shop')}
                    </span>
                    <span className="inline-flex items-center gap-1" title={t('dash.maintained.idleHint')}>
                      <span className="h-1.5 w-1.5 rounded-full bg-slate-300" />{dur(r.idle_seconds, r.days_idle)} {t('dash.maintained.idle')}
                    </span>
                    <span className="text-slate-400">· {t('dash.maintained.totalDays', { n: num(r.days_in_service) })}</span>
                  </p>
                </div>
              </li>
            );
          })}
        </ol>
      )}
    </SectionCard>
  );
}


export default function Dashboard() {
  const { user } = useAuth();
  const { t, tp } = useI18n();
  const firstName = user?.name ? String(user.name).trim().split(/\s+/)[0] : '';
  const [view, setView] = useState('metrics'); // 'metrics' | 'pulse'

  const fetcher = useCallback(async () => {
    // The KPI summary is the one critical call (drives the headline counts + fleet
    // composition). The auxiliary feeds degrade to empty on failure, so a flaky
    // trends/overdue/expiring endpoint can never blank the whole dashboard.
    const safe = (fallback) => () => ({ data: { data: fallback } });
    const emptyFlags = { contract_expiry: { count: 0, items: [] }, in_maintenance: { count: 0, items: [] }, invoice_overdue: { count: 0, items: [] }, inspection_due: { count: 0, items: [] } };
    const emptyBilling = { paid: 0, partial: 0, not_paid: 0, unsynced: 0, pending: 0, total: 0, outstanding_balance: 0 };
    const emptyOversight = { mileage_flags: 0, mileage_readings_total: 0, severity_mismatches: 0, severity_graded_total: 0, misdiagnoses: 0, diagnosed_total: 0, awaiting_parts: 0, left_garage: 0, resolved_transfers: 0 };
    const [kpiRes, trendsRes, flagsRes, billingRes, oversightRes] = await Promise.all([
      api.get('/Dashboard', { params: { expiring_days: 7 } }),
      api.get('/Dashboard/trends', { params: { months: 12 } }).catch(safe({ cost: [], downtime: [] })),
      api.get('/Dashboard/proactive-flags', { params: { days: 7 } }).catch(safe(emptyFlags)),
      api.get('/Invoice/status-summary').catch(safe(emptyBilling)),
      api.get('/Oversight/overview').catch(safe(emptyOversight)),
    ]);
    return {
      // Fold the workflow-oversight roll-up (mileage / severity / mis-diagnosis / waiting-for-parts
      // counts) into the KPI object so the oversight KPI tiles read straight from `kpis[card.key]`.
      kpis: { ...(kpiRes.data.data || {}), ...(oversightRes.data.data || emptyOversight) },
      trends: trendsRes.data.data || { cost: [], downtime: [] },
      proactive: flagsRes.data.data || emptyFlags,
      billing: billingRes.data.data || emptyBilling,
    };
  }, []);
  const { data, loading, error, reload } = useFetch(fetcher);

  const kpis = data?.kpis || {};
  const trends = data?.trends || { cost: [], downtime: [] };
  const proactive = data?.proactive || {};

  const fleet = kpis.fleet_status || {};
  const available = fleet.available || 0;
  const rented = fleet.rented || 0;
  const maint = fleet.maintenance || 0;
  // Operational fleet = cars the team actually works with (ready + on-rent + in-shop); excludes
  // sold / disposed / office-use, which inflate fleet.total. All readiness ratios divide by THIS.
  const activeFleet = available + rented + maint;

  // Fleet Status — a live snapshot for the headline donut, limited to the three
  // operational states the team actually works with. The "Unavailable" catch-all
  // (sold/disposed/office-use/other) was dropped because those cars surface in no
  // list, so the donut totals only the active, accounted-for fleet.
  const fleetStatusSegments = [
    { label: t('dash.fleet.available'),   value: available, color: 'green'  },
    { label: t('dash.fleet.onRent'),      value: rented,    color: 'blue'   },
    { label: t('dash.fleet.maintenance'), value: maint,     color: 'yellow' },
  ];

  // Inspection Accuracy — the three oversight signals reframed as a right-vs-wrong scorecard: instead of
  // just "how many were wrong", each card scores the inspector against everything he handled, so a low
  // flag count on a huge volume reads as the strong performance it is (and vice-versa).
  const accuracy = [
    {
      key: 'severity', title: t('dash.accuracy.severity.title'), icon: <Icon.Alert className="h-4 w-4" />, to: '/oversight/severity',
      total: kpis.severity_graded_total || 0, wrong: kpis.severity_mismatches || 0,
      rightLabel: t('dash.accuracy.severity.right'), wrongLabel: t('dash.accuracy.severity.wrong'),
      tooltip: t('dash.accuracy.severity.tooltip'),
    },
    {
      key: 'diagnosis', title: t('dash.accuracy.diagnosis.title'), icon: <Icon.XCircle className="h-4 w-4" />, to: '/oversight/misdiagnoses',
      total: kpis.diagnosed_total || 0, wrong: kpis.misdiagnoses || 0,
      rightLabel: t('dash.accuracy.diagnosis.right'), wrongLabel: t('dash.accuracy.diagnosis.wrong'),
      tooltip: t('dash.accuracy.diagnosis.tooltip'),
    },
    {
      key: 'odometer', title: t('dash.accuracy.odometer.title'), icon: <Icon.Gauge className="h-4 w-4" />, to: '/oversight/mileage',
      total: kpis.mileage_readings_total || 0, wrong: kpis.mileage_flags || 0,
      rightLabel: t('dash.accuracy.odometer.right'), wrongLabel: t('dash.accuracy.odometer.wrong'),
      tooltip: t('dash.accuracy.odometer.tooltip'),
    },
  ];

  return (
    <div className="opx py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        {/* Greeting header — a light, personable "Good morning, {name}!" band with a live
            pulse, the last-updated stamp, the fleet-size counter, and the view switcher. */}
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div className="min-w-0">
            <div className="opx-hint" style={{ letterSpacing: '.16em', textTransform: 'uppercase', marginBottom: 7, display: 'flex', alignItems: 'center', gap: 8 }}>
              <span className="h-1.5 w-1.5 rounded-full" style={{ background: 'var(--avail)', boxShadow: '0 0 8px var(--avail)' }} />
              {t('dash.header.live')}
            </div>
            <div className="flex items-center gap-2.5">
              <span className="h-5 w-1 rounded-full bg-indigo-500" />
              <h1 className="font-display text-2xl font-bold tracking-tight" style={{ color: 'var(--ink)' }}>
                {firstName ? t(`shell.greet.${greetKey()}Named`, { name: firstName }) : t(`shell.greet.${greetKey()}`)}
              </h1>
            </div>
            <p className="mt-1.5 text-sm sm:ps-3.5" style={{ color: 'var(--ink-3)' }}>
              {t(SHOW_FINANCIALS ? 'dash.header.subtitleFinance' : 'dash.header.subtitle')}
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-3">
            <span className="hidden text-xs font-medium text-slate-400 sm:inline">{t('dash.header.updated', { date: fmtDate(new Date()) })}</span>
            {/* View toggle — flip between the analytical "Metrics" view and the live "Fleet Pulse" wall.
                The loop variable is `v`, not `t` — `t` is the translator in this scope. */}
            <div className="inline-flex rounded-xl bg-slate-100 p-1">
              {[
                { key: 'metrics', label: t('dash.header.viewMetrics'), icon: <Icon.Chart className="h-4 w-4" /> },
                { key: 'pulse', label: t('dash.header.viewPulse'), icon: <Icon.Activity className="h-4 w-4" /> },
              ].map((v) => (
                <button
                  key={v.key}
                  type="button"
                  onClick={() => setView(v.key)}
                  className={`inline-flex items-center gap-1.5 rounded-lg px-3.5 py-1.5 text-sm font-semibold transition ${
                    view === v.key ? 'bg-white text-slate-900 shadow-soft' : 'text-slate-500 hover:text-slate-700'
                  }`}
                >
                  {v.icon}
                  {v.label}
                </button>
              ))}
            </div>
          </div>
        </div>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {view === 'pulse' && <FleetPulseGrid />}

        {view === 'metrics' && (
          <>
        {/* Fleet composition — the status donut. */}
        <div className="grid grid-cols-1 gap-6">
          {loading ? (
            <Card>
              <div className="flex justify-center py-16"><Skeleton className="h-56 w-56 rounded-full" /></div>
            </Card>
          ) : (
            <FleetStatusCard
              title={t('dash.fleet.title')}
              centerLabel={t('dash.fleet.centerLabel')}
              unit={t('dash.fleet.unit')}
              total={activeFleet}
              segments={fleetStatusSegments}
              headerRight={
                <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                  <span className="h-2 w-2 rounded-full bg-emerald-500" />
                  {t('dash.fleet.liveBadge')}
                </span>
              }
            />
          )}
        </div>

        {/* Maintenance Pipeline — where every car in the workflow is, which ones have stalled at the
            garage, and whose desk the open work is sitting on. Self-fetched off the same board the
            /maintenance-workflow lanes render, so the two can never disagree. */}
        <PipelinePanel />

        {/* Proactive Flags — every car in the shop right now (from the sheet contract and from the
            app's own tickets) and how it is tracking against its repair ETA, with the checkpoint form
            on each card. Same source list as the notification bell; every card opens its ticket. */}
        <ProactiveFlags data={proactive} loading={loading} onReload={reload} />

        {/* Bought Again — the same part fitted to the same car twice inside the window, with the
            approval behind each buy. Self-fetching and permission-gated (renders nothing without
            `parts.view`), so it costs nothing for a user who can't see the parts ledger. */}
        <RepeatPartPurchases />

        {/* Recently Fixed — the cars that came back working: the problem, the fix, the garage and the
            downtime. The good-news counterpart to the pipeline cards above; the full ledger (with the
            date filter and the per-fault story) lives on /completed-repairs. */}
        <RecentlyFixedCard limit={3} />

        {/* Most Maintained Cars (by downtime) beside the Most Frequent Faults donut KPI. */}
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
          <MostMaintainedCars />
          <MostFrequentFaults />
        </div>



        {/* Data visualization — maintenance spend per month (bar) and the downtime
            trend (line). Both are bespoke SVG, so they match the gauges and donut. */}
        <div className={`grid grid-cols-1 gap-6 ${SHOW_FINANCIALS ? 'lg:grid-cols-2' : ''}`}>
          {SHOW_FINANCIALS && (
          <Card>
            <div className="border-b border-slate-100 px-6 py-4">
              <h2 className="flex items-center gap-1.5 text-base font-semibold text-slate-900">
                {t('dash.expenses.title')}
                <InfoTip content={t('dash.expenses.tooltip')} />
              </h2>
              <p className="mt-0.5 text-xs text-slate-500">{t('dash.expenses.subtitle')}</p>
            </div>
            <div className="px-3 py-5 sm:px-5">
              {loading ? (
                <Skeleton className="h-[260px] w-full rounded-2xl" />
              ) : (
                <BarChart
                  data={trends.cost}
                  color="indigo"
                  height={260}
                  format={aed}
                  tickFormat={aedK}
                  valueLabel={t('dash.expenses.valueLabel')}
                  tooltip={(d) => tp('dash.expenses.lines', d.visits)}
                />
              )}
            </div>
          </Card>
          )}

          <Card>
            <div className="border-b border-slate-100 px-6 py-4">
              <h2 className="flex items-center gap-1.5 text-base font-semibold text-slate-900">
                {t('dash.downtime.title')}
                <InfoTip content={t('dash.downtime.tooltip')} />
              </h2>
              <p className="mt-0.5 text-xs text-slate-500">{t('dash.downtime.subtitle')}</p>
            </div>
            <div className="px-3 py-5 sm:px-5">
              {loading ? (
                <Skeleton className="h-[260px] w-full rounded-2xl" />
              ) : (
                <LineChart
                  data={trends.downtime}
                  color="emerald"
                  height={260}
                  format={(v) => tp('dash.days', v, { n: v })}
                  tickFormat={(v) => `${Math.round(v)}${t('dash.unit.d')}`}
                  valueLabel={t('dash.downtime.valueLabel')}
                  tooltip={(d) => tp('dash.downtime.visits', d.visits)}
                />
              )}
            </div>
          </Card>
        </div>

        {/* Inspection Accuracy — the oversight signals scored as right-vs-wrong instead of raw problem
            counts, so a few misses on a large volume reads as the strong record it is. */}
        <SectionCard
          title={
            <span className="flex items-center gap-1.5">
              {t('dash.accuracy.title')}
              <InfoTip content={t('dash.accuracy.tooltip')} />
            </span>
          }
          subtitle={t('dash.accuracy.subtitle')}
        >
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {accuracy.map((a) => (
              <AccuracyCard key={a.key} {...a} loading={loading} />
            ))}
          </div>
        </SectionCard>

          </>
        )}

      </div>
    </div>
  );
}
