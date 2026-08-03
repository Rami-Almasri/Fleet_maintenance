import { useState } from 'react';
import Badge from '../ui/Badge';
import { SHOW_FINANCIALS } from '../../config/features';
import { aed, fmtDate, num } from '../../lib/format';

/**
 * The FULL purchase record of one part on one vehicle, shown at buy time.
 *
 * The duplicate warning above it answers "should I be alarmed?" — a windowed question. This answers the
 * one the buyer is actually asking: what has this part done on this car? So it is deliberately unwindowed.
 * Purchases older than the alert window are still listed; they are simply not tinted as the reason for a
 * warning. Feeds off the `history` block of GET /part-purchases/duplicate-check.
 *
 * Money rule (matches the duplicate banner): a per-purchase price is a RECORDED figure — the spend this
 * screen exists to justify — so it shows even while SHOW_FINANCIALS hides money elsewhere. The computed
 * roll-ups (total spend, fleet average) are gated, because those are analytics, not the record.
 */

const RESULT_TONE = { success: 'green', failed: 'red', pending: 'slate' };
const RESULT_LABEL = { success: 'Worked', failed: 'Failed', pending: 'Pending' };
const sourceLabel = (s) => (s === 'garage' ? 'Garage' : s === 'supplier' ? 'Supplier' : null);

const DEFAULT_VISIBLE = 3;

function Fact({ label, children }) {
  if (children === null || children === undefined || children === '') return null;
  return (
    <div className="min-w-0">
      <dt className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{label}</dt>
      <dd className="truncate text-xs font-medium text-slate-700">{children}</dd>
    </div>
  );
}

function PurchaseRow({ rec }) {
  const price = rec.total_price ?? rec.unit_price;
  const cur = rec.currency && rec.currency !== 'AED' ? ` ${rec.currency}` : '';

  return (
    <li
      className={`rounded-lg px-3 py-2.5 ring-1 ring-inset ${
        rec.within_alert_window ? 'bg-amber-50/70 ring-amber-500/25' : 'bg-white ring-slate-200'
      }`}
    >
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
        <span className="text-sm font-semibold text-slate-800">{fmtDate(rec.purchased_at)}</span>
        {rec.days_ago != null && (
          <span className="text-xs text-slate-500">
            {rec.days_ago === 0 ? 'today' : `${num(rec.days_ago)} day(s) ago`}
          </span>
        )}
        {price != null && (
          <span className="text-sm font-semibold text-slate-800">
            · {aed(price)}{cur}
            {rec.quantity > 1 && <span className="font-normal text-slate-500"> ({num(rec.quantity)} × {aed(rec.unit_price)}{cur})</span>}
          </span>
        )}
        <span className="ml-auto flex items-center gap-1.5">
          {rec.result && <Badge tone={RESULT_TONE[rec.result] || 'gray'}>{RESULT_LABEL[rec.result] || rec.result}</Badge>}
          {rec.flagged && <Badge tone="red">Flagged</Badge>}
          {!rec.installed_at && <Badge tone="slate">Not fitted</Badge>}
        </span>
      </div>

      <dl className="mt-1.5 grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-4">
        <Fact label="Bought from">
          {rec.source_name || sourceLabel(rec.purchase_source)}
          {rec.source_name && sourceLabel(rec.purchase_source) ? ` (${sourceLabel(rec.purchase_source)})` : ''}
        </Fact>
        <Fact label="Fault">{rec.fault}</Fact>
        <Fact label="Ticket">{rec.maintenance_id ? `#${rec.maintenance_id}` : null}</Fact>
        <Fact label="Fitted by">{rec.installed_by || rec.purchased_by}</Fact>
        <Fact label="Fitted on">{rec.installed_at ? fmtDate(rec.installed_at) : null}</Fact>
        <Fact label="Odometer">{rec.installed_odometer ? `${num(rec.installed_odometer)} km` : null}</Fact>
        <Fact label="PO / invoice">{rec.po_number}</Fact>
        <Fact label="Root cause">{rec.root_cause}</Fact>
      </dl>

      {rec.notes && <p className="mt-1.5 text-xs italic text-slate-500">“{rec.notes}”</p>}
    </li>
  );
}

/**
 * @param showEmpty  render an explicit "never bought before" panel instead of nothing. Inline under a
 *                   form, silence is right — there is no record and the form is the point. But when the
 *                   user CLICKED to see the record, silence reads as a broken screen, so the answer
 *                   "there is none" has to be stated out loud.
 */
export default function PartPurchaseHistory({ history, partName, loading = false, showEmpty = false, className = '' }) {
  const [expanded, setExpanded] = useState(false);

  if (loading) {
    return <p className={`text-xs text-slate-400 ${className}`}>Loading this part’s full record…</p>;
  }

  const records = history?.records || [];
  const s = history?.summary;
  const fleet = history?.fleet;

  // No record on this vehicle is itself an answer worth stating — and the fleet may still know the part.
  if (!records.length) {
    if (!fleet) {
      if (!showEmpty) return null;
      return (
        <div className={`rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600 ring-1 ring-inset ring-slate-200 ${className}`}>
          <p className="font-semibold text-slate-700">No purchase record.</p>
          <p className="mt-0.5 text-xs">
            {partName ? <span className="font-medium">{partName}</span> : 'This part'} has never been bought for this
            vehicle, and no other vehicle in the fleet has had it either.
          </p>
        </div>
      );
    }
    return (
      <div className={`rounded-xl bg-slate-50 px-4 py-3 text-xs text-slate-600 ring-1 ring-inset ring-slate-200 ${className}`}>
        <span className="font-semibold text-slate-700">First time on this vehicle.</span>{' '}
        Elsewhere in the fleet this part was bought {num(fleet.purchases)} time(s) across {num(fleet.vehicles)} vehicle(s)
        {fleet.last_purchased_at ? `, last on ${fmtDate(fleet.last_purchased_at)}` : ''}
        {SHOW_FINANCIALS && fleet.avg_price != null
          ? ` · typically ${aed(fleet.avg_price)} (${aed(fleet.min_price)}–${aed(fleet.max_price)})`
          : ''}
        .
      </div>
    );
  }

  const shown = expanded ? records : records.slice(0, DEFAULT_VISIBLE);
  const hidden = records.length - shown.length;

  return (
    <div className={`rounded-xl bg-slate-50 px-4 py-3 ring-1 ring-inset ring-slate-200 ${className}`}>
      <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
        <h4 className="text-sm font-bold text-slate-800">
          Full record — {partName ? <span className="text-slate-900">{partName}</span> : 'this part'} on this vehicle
        </h4>
        <span className="text-xs text-slate-500">
          bought {num(s.total_purchases)} time(s)
          {s.first_purchased_at && s.total_purchases > 1 ? ` since ${fmtDate(s.first_purchased_at)}` : ''}
        </span>
      </div>

      {/* The headline counts. `in_alert_window` is what the old 90-day check saw; the rest is what it hid. */}
      <div className="mt-2 flex flex-wrap items-center gap-1.5">
        {SHOW_FINANCIALS && s.total_spend != null && (
          <Badge tone="slate">
            {aed(s.total_spend)} total
            {s.spend_covers < s.shown ? ` (${num(s.spend_covers)} priced)` : ''}
          </Badge>
        )}
        {s.days_since_last != null && <Badge tone="slate">Last {num(s.days_since_last)} day(s) ago</Badge>}
        {s.failed > 0 && <Badge tone="red">{num(s.failed)} failed</Badge>}
        {s.flagged > 0 && <Badge tone="amber">{num(s.flagged)} flagged</Badge>}
        {s.never_installed > 0 && <Badge tone="slate">{num(s.never_installed)} never fitted</Badge>}
        {s.in_alert_window > 0 && (
          <Badge tone="amber">{num(s.in_alert_window)} within {num(s.alert_window_days)} day(s)</Badge>
        )}
      </div>

      {/* Data origin: which identity actually found these rows. A name match means the part number on
          this request matched nothing, so the list is only as good as the naming — say so, don't imply
          a SKU-exact match the data cannot support. */}
      {s.matched_by === 'part_name' && (
        <p className="mt-1.5 text-[11px] text-slate-500">
          Matched by part <span className="font-medium">name</span> — no purchase carries this part number.
        </p>
      )}

      <ul className="mt-2.5 space-y-2">
        {shown.map((rec) => <PurchaseRow key={rec.purchase_id} rec={rec} />)}
      </ul>

      {(hidden > 0 || expanded) && records.length > DEFAULT_VISIBLE && (
        <button
          type="button"
          onClick={() => setExpanded((v) => !v)}
          className="mt-2 text-xs font-semibold text-indigo-600 hover:text-indigo-700"
        >
          {expanded ? 'Show less' : `Show all ${num(records.length)} purchases`}
        </button>
      )}

      {/* The cap is stated, never silent — otherwise a partial list reads as the whole record. */}
      {history?.truncated && (
        <p className="mt-2 text-[11px] text-slate-500">
          Showing the {num(s.shown)} most recent of {num(s.total_purchases)} purchases.
        </p>
      )}

      {fleet && (
        <p className="mt-2 border-t border-slate-200 pt-2 text-[11px] text-slate-600">
          <span className="font-semibold">Across the fleet:</span> {num(fleet.purchases)} more purchase(s) on{' '}
          {num(fleet.vehicles)} other vehicle(s)
          {SHOW_FINANCIALS && fleet.avg_price != null
            ? ` · typically ${aed(fleet.avg_price)} (${aed(fleet.min_price)}–${aed(fleet.max_price)})`
            : ''}
          .
        </p>
      )}
    </div>
  );
}
