// Bought Again — the dashboard's repeat-purchase watch list.
//
// "We put a part on this car, and now we're putting the same part on the same car again." Each row is a
// PAIR: the first buy, the repeat, how many days apart they were — and WHO APPROVED each one, which is
// the half of the question the buy-time warning never answers.
//
// Self-fetching from GET /part-purchases/repeats, the same PartIntelligenceService that raises the
// pre-buy duplicate warning in the ticket's part modal, so the card and the modal can never grade the
// same repeat differently. This card is the retrospective view: it lists repeats that ALREADY happened,
// including the ones nobody flagged at the time.
//
// Renders nothing for a user without `parts.view` (the endpoint would 403 anyway).

import { useCallback, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import Icon from '../ui/Icon';
import { Skeleton } from '../ui/Skeleton';
import { SectionCard } from '../ui/Table';
import { InfoTip } from '../ui/Tooltip';
import { aed2, fmtDate, num } from '../../lib/format';
import { SHOW_FINANCIALS } from '../../config/features';
import { usePermissions } from '../../hooks/usePermissions';

// The "again" window — how close together the two buys must be to count as a repeat. 30 days is the
// operational default ("we bought it last month and we're buying it again"); the others are there
// because a supervisor chasing a specific suspicion needs to tighten or widen it.
const WINDOWS = [7, 30, 60, 90];

const PRIORITY = {
  high:   { chip: 'bg-rose-100 text-rose-700 ring-rose-200',    label: 'High' },
  medium: { chip: 'bg-amber-100 text-amber-700 ring-amber-200', label: 'Watch' },
};

export default function RepeatPartPurchases({ limit = 8 }) {
  const { can } = usePermissions();
  const allowed = can('parts.view');

  const [windowDays, setWindowDays] = useState(30);
  const [withConsumables, setWithConsumables] = useState(false);

  const fetcher = useCallback(async () => {
    if (!allowed) return null;
    const res = await api.get('/part-purchases/repeats', {
      params: {
        window_days: windowDays,
        lookback_days: 365,
        limit,
        include_consumables: withConsumables ? 1 : 0,
      },
    });
    return res.data.data || null;
  }, [allowed, windowDays, withConsumables, limit]);

  const { data, loading, error } = useFetch(fetcher, [allowed, windowDays, withConsumables, limit]);

  if (!allowed) return null;

  const rows = data?.rows || [];
  const summary = data?.summary || {};

  return (
    <SectionCard
      title={
        <span className="flex items-center gap-1.5">
          Bought Again
          <InfoTip content="Cars that received the SAME part twice within the selected window. Each row shows both purchases, how far apart they were, what each cost, and who approved them. Matching is by part name — part numbers are hand-typed and differ between suppliers for the same component, so a number-only match would report a clean fleet while the same part goes on twice." />
        </span>
      }
      subtitle={`Same part, same car, bought twice within ${windowDays} days — and who signed each buy off`}
      bodyClass="px-4 pb-4 pt-1 sm:px-5"
      actions={
        <div className="flex flex-wrap items-center justify-end gap-3">
          {/* The gap window. */}
          <div className="inline-flex rounded-lg bg-slate-100 p-0.5" title="Maximum days between the two purchases">
            {WINDOWS.map((w) => (
              <button
                key={w}
                type="button"
                onClick={() => setWindowDays(w)}
                className={`rounded-md px-2.5 py-1 text-xs font-semibold transition ${
                  windowDays === w ? 'bg-white text-slate-900 shadow-soft' : 'text-slate-500 hover:text-slate-700'
                }`}
              >
                {w}d
              </button>
            ))}
          </div>
          {/* Filters, wipers and oil are MEANT to come round again — hidden unless asked for, so the
              list stays a list of things that look wrong. */}
          <label className="inline-flex cursor-pointer items-center gap-1.5 text-xs text-slate-500" title="Filters, wipers, bulbs and fluids repeat by design">
            <input
              type="checkbox"
              checked={withConsumables}
              onChange={(e) => setWithConsumables(e.target.checked)}
              className="h-3.5 w-3.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
            />
            Include routine parts
          </label>
          <Link to="/parts" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">
            Parts board →
          </Link>
        </div>
      }
    >
      {error && (
        <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</p>
      )}

      {loading ? (
        <ul className="space-y-2 pt-3">
          {Array.from({ length: 3 }).map((_, i) => <li key={i}><Skeleton className="h-24 rounded-xl" /></li>)}
        </ul>
      ) : rows.length === 0 ? (
        <EmptyState windowDays={windowDays} onWiden={() => setWindowDays(90)} />
      ) : (
        <>
          <SummaryStrip summary={summary} />
          <ul className="mt-3 space-y-2">
            {rows.map((r) => <RepeatRow key={`${r.previous.purchase_id}-${r.current.purchase_id}`} row={r} />)}
          </ul>
          {data?.truncated && (
            <p className="mt-3 text-center text-xs text-slate-400">
              Showing the {num(rows.length)} most recent — there are more repeats in this window.
            </p>
          )}
        </>
      )}
    </SectionCard>
  );
}

/** The counts above the list: how many repeats, on how many cars, how many nobody approved. */
function SummaryStrip({ summary }) {
  const tiles = [
    { label: 'repeat buys', value: num(summary.pairs || 0), tone: 'text-slate-900' },
    { label: 'cars affected', value: num(summary.vehicles || 0), tone: 'text-slate-900' },
    { label: 'high priority', value: num(summary.high || 0), tone: summary.high ? 'text-rose-600' : 'text-slate-400' },
    // The accountability count — a repeat that never passed an approval step at all.
    { label: 'no approval on file', value: num(summary.no_approval || 0), tone: summary.no_approval ? 'text-amber-600' : 'text-slate-400' },
  ];
  if (SHOW_FINANCIALS && summary.repeat_spend != null) {
    tiles.push({ label: `spent on the repeat buy${summary.spend_covers !== summary.pairs ? ` (${num(summary.spend_covers)} of ${num(summary.pairs)} priced)` : ''}`, value: aed2(summary.repeat_spend), tone: 'text-slate-900' });
  }

  return (
    <div className="mt-3 flex flex-wrap gap-x-6 gap-y-2 rounded-xl bg-slate-50 px-4 py-3">
      {tiles.map((tile) => (
        <div key={tile.label}>
          <div className={`text-lg font-bold tabular-nums ${tile.tone}`}>{tile.value}</div>
          <div className="text-[11px] text-slate-500">{tile.label}</div>
        </div>
      ))}
    </div>
  );
}

function RepeatRow({ row }) {
  const p = PRIORITY[row.priority];
  const demo = row.current.is_demo || row.previous.is_demo;

  return (
    <li className={`rounded-xl px-3 py-3 ring-1 ring-inset ${row.priority === 'high' ? 'bg-rose-50/50 ring-rose-200' : 'bg-white ring-slate-200'}`}>
      {/* Headline: what part, on which car, how far apart. */}
      <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
        <Link
          to={row.vehicle.id ? `/vehicles/${row.vehicle.id}` : '#'}
          className="font-mono text-sm font-semibold text-slate-900 hover:text-indigo-600"
        >
          {row.vehicle.plate || `#${row.vehicle.id}`}
        </Link>
        {row.vehicle.car && <span className="text-[11px] text-slate-400">{row.vehicle.car}</span>}
        <span className="font-semibold text-slate-800">· {row.part_name || 'Unnamed part'}</span>
        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">
          bought again after {num(row.days_between)} {row.days_between === 1 ? 'day' : 'days'}
        </span>
        {p && <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${p.chip}`}>{p.label}</span>}
        {/* The gravest shape of this finding: the same part, for the SAME fault — a fix that did not hold. */}
        {row.same_fault && (
          <span className="rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-semibold text-rose-700 ring-1 ring-inset ring-rose-200">
            same fault — the repair did not hold
          </span>
        )}
        {row.part_class === 'consumable' && (
          <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500" title="Filters, wipers, bulbs and fluids are expected to be re-bought">
            routine part
          </span>
        )}
      </div>

      {/* Identity evidence — matching two agreeing SKUs is a stronger claim than matching two names. */}
      <p className="mt-1 text-[11px] text-slate-400">
        {row.matched_by === 'part_number'
          ? <>Matched on part number <span className="font-mono">{row.part_number}</span> — both buys carry the same SKU.</>
          : <>Matched on part name. The two buys carry different (or no) part numbers, which is normal when suppliers differ.</>}
        {demo && <span className="ms-2 rounded-full bg-amber-50 px-1.5 py-px font-semibold text-amber-700 ring-1 ring-inset ring-amber-200">Demo data</span>}
      </p>

      {/* The two buys, first then repeat. */}
      <div className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
        <BuySide side={row.previous} label="First bought" />
        <BuySide side={row.current} label="Bought again" highlight />
      </div>
    </li>
  );
}

/** One purchase: when, from whom, for how much, on which ticket — and the approval behind it. */
function BuySide({ side, label, highlight }) {
  return (
    <div className={`rounded-lg px-3 py-2 ring-1 ring-inset ${highlight ? 'bg-white ring-slate-200' : 'bg-slate-50/70 ring-slate-100'}`}>
      <div className="flex items-baseline justify-between gap-2">
        <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{label}</span>
        <span className="text-[11px] tabular-nums text-slate-500">
          {fmtDate(side.purchased_at)}
          {side.days_ago != null && <span className="text-slate-400"> · {side.days_ago}d ago</span>}
        </span>
      </div>

      <p className="mt-1 flex flex-wrap items-center gap-x-2.5 gap-y-0.5 text-xs text-slate-600">
        <span className="inline-flex items-center gap-1 truncate">
          <Icon.Truck className="h-3 w-3 shrink-0 text-slate-300" />
          {side.source_name || (side.purchase_source === 'garage' ? 'Garage' : 'Supplier not recorded')}
        </span>
        {SHOW_FINANCIALS && side.total_price != null && (
          <span className="font-semibold tabular-nums">{aed2(side.total_price)}</span>
        )}
        {side.maintenance_id && (
          <Link to={`/maintenance-workflow/${side.maintenance_id}`} className="text-indigo-600 hover:text-indigo-700">
            Ticket #{side.maintenance_id}
          </Link>
        )}
      </p>

      {side.fault && (
        <p className="mt-0.5 truncate text-[11px] text-slate-500" title={side.fault}>Fault: {side.fault}</p>
      )}

      <Approval approval={side.approval} boughtBy={side.purchased_by} />
    </div>
  );
}

/**
 * WHO APPROVED IT. Three honest states, never a blank:
 *   approved     — a request was raised and signed off; name it and date it.
 *   not_approved — a request exists but nobody has approved it (or it was rejected/cancelled).
 *   no_request   — the part was bought without ever passing an approval step. That is not missing
 *                  data, it IS the finding, so it reads as a warning rather than a dash.
 */
function Approval({ approval, boughtBy }) {
  const state = approval?.state;

  if (state === 'approved') {
    return (
      <p className="mt-1.5 flex items-start gap-1.5 text-[11px] text-emerald-700">
        <Icon.Check className="mt-px h-3 w-3 shrink-0" />
        <span>
          Approved by <span className="font-semibold">{approval.approved_by || 'an unnamed approver'}</span>
          {approval.approved_at && <> on {fmtDate(approval.approved_at)}</>}
          {approval.requested_by && <span className="text-slate-400"> · requested by {approval.requested_by}</span>}
        </span>
      </p>
    );
  }

  if (state === 'not_approved') {
    return (
      <p className="mt-1.5 flex items-start gap-1.5 text-[11px] text-amber-700">
        <Icon.Alert className="mt-px h-3 w-3 shrink-0" />
        <span>
          Request #{approval.request_id} is <span className="font-semibold">{approval.request_status}</span> — never approved
          {approval.requested_by && <span className="text-slate-400"> · requested by {approval.requested_by}</span>}
        </span>
      </p>
    );
  }

  return (
    <p className="mt-1.5 flex items-start gap-1.5 text-[11px] text-amber-700">
      <Icon.Alert className="mt-px h-3 w-3 shrink-0" />
      <span>
        No approval on file — bought without a part request
        {boughtBy && <span className="text-slate-400"> · recorded by {boughtBy}</span>}
      </span>
    </p>
  );
}

/** Nothing found is a real answer here — say what was searched, and offer the wider search. */
function EmptyState({ windowDays, onWiden }) {
  return (
    <div className="py-8 text-center">
      <p className="text-sm font-medium text-slate-600">No car received the same part twice within {windowDays} days.</p>
      <p className="mx-auto mt-1 max-w-lg text-xs text-slate-400">
        Searched every purchase in the parts ledger from the last 12 months. Parts bought before the ledger
        went live — recorded only as invoice lines on a ticket — are not counted here.
      </p>
      {windowDays < 90 && (
        <button type="button" onClick={onWiden} className="mt-3 text-xs font-semibold text-indigo-600 hover:text-indigo-700">
          Widen the window to 90 days →
        </button>
      )}
    </div>
  );
}
