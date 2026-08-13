import { useState } from 'react';
import Badge from '../ui/Badge';
import { useI18n } from '../../i18n/I18nContext';
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
// `t` is threaded in because these labels live outside a component body.
const resultLabel = (t, r) =>
  ({ success: t('Worked'), failed: t('Failed'), pending: t('Pending') })[r] || null;
const sourceLabel = (t, s) => (s === 'garage' ? t('Garage') : s === 'supplier' ? t('Supplier') : null);

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

function PurchaseRow({ rec, askedFor }) {
  const { t } = useI18n();
  const price = rec.total_price ?? rec.unit_price;
  const cur = rec.currency && rec.currency !== 'AED' ? ` ${rec.currency}` : '';
  const source = sourceLabel(t, rec.purchase_source);

  // This row was written down differently from the part being asked about. Shown on the row itself,
  // not only in the summary, so the reader can see WHICH purchase the claim rests on.
  const otherWording = rec.part_name
    && askedFor
    && rec.part_name.trim().toLowerCase() !== askedFor.trim().toLowerCase();

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
            {rec.days_ago === 0
              ? t('today')
              : rec.days_ago === 1
                ? t('1 day ago')
                : t('{n} days ago', { n: num(rec.days_ago) })}
          </span>
        )}
        {price != null && (
          <span className="text-sm font-semibold text-slate-800">
            · {aed(price)}{cur}
            {rec.quantity > 1 && <span className="font-normal text-slate-500"> ({num(rec.quantity)} × {aed(rec.unit_price)}{cur})</span>}
          </span>
        )}
        <span className="ms-auto flex items-center gap-1.5">
          {otherWording && (
            <span
              className="rounded bg-cyan-50 px-1.5 py-0.5 text-[11px] font-medium text-cyan-700"
              dir="auto"
              title={t('Recorded as “{earlier}” — matched as the same part.', { earlier: rec.part_name })}
            >
              {rec.part_name}
            </span>
          )}
          {rec.result && <Badge tone={RESULT_TONE[rec.result] || 'gray'}>{resultLabel(t, rec.result) || rec.result}</Badge>}
          {rec.flagged && <Badge tone="red">{t('Flagged')}</Badge>}
          {!rec.installed_at && <Badge tone="slate">{t('Not fitted')}</Badge>}
        </span>
      </div>

      <dl className="mt-1.5 grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-4">
        <Fact label={t('Bought from')}>
          {rec.source_name || source}
          {rec.source_name && source ? ` (${source})` : ''}
        </Fact>
        <Fact label={t('Fault')}>{rec.fault}</Fact>
        <Fact label={t('Ticket')}>{rec.maintenance_id ? `#${rec.maintenance_id}` : null}</Fact>
        <Fact label={t('Fitted by')}>{rec.installed_by || rec.purchased_by}</Fact>
        <Fact label={t('Fitted on')}>{rec.installed_at ? fmtDate(rec.installed_at) : null}</Fact>
        <Fact label={t('Odometer')}>{rec.installed_odometer ? `${num(rec.installed_odometer)} km` : null}</Fact>
        <Fact label={t('PO / invoice')}>{rec.po_number}</Fact>
        <Fact label={t('Root cause')}>{rec.root_cause}</Fact>
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
  const { t } = useI18n();
  const [expanded, setExpanded] = useState(false);

  if (loading) {
    return <p className={`text-xs text-slate-400 ${className}`}>{t('Loading this part’s full record…')}</p>;
  }

  const records = history?.records || [];
  const s = history?.summary;
  const fleet = history?.fleet;

  // The fleet-wide price band, stated as its own sentence so Arabic can order the clause naturally.
  const typically =
    fleet && SHOW_FINANCIALS && fleet.avg_price != null
      ? t('Typically {avg} ({min}–{max}).', {
        avg: aed(fleet.avg_price),
        min: aed(fleet.min_price),
        max: aed(fleet.max_price),
      })
      : '';

  // No record on this vehicle is itself an answer worth stating — and the fleet may still know the part.
  if (!records.length) {
    if (!fleet) {
      if (!showEmpty) return null;
      return (
        <div className={`rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600 ring-1 ring-inset ring-slate-200 ${className}`}>
          <p className="font-semibold text-slate-700">{t('No purchase record.')}</p>
          <p className="mt-0.5 text-xs">
            {t('{part} has never been bought for this vehicle, and no other vehicle in the fleet has had it either.', {
              part: partName || t('This part'),
            })}
          </p>
        </div>
      );
    }
    return (
      <div className={`rounded-xl bg-slate-50 px-4 py-3 text-xs text-slate-600 ring-1 ring-inset ring-slate-200 ${className}`}>
        <span className="font-semibold text-slate-700">{t('First time on this vehicle.')}</span>{' '}
        {t('Elsewhere in the fleet it was bought {n} time(s) on {v} vehicle(s).', {
          n: num(fleet.purchases),
          v: num(fleet.vehicles),
        })}
        {fleet.last_purchased_at ? ` ${t('Last on {date}.', { date: fmtDate(fleet.last_purchased_at) })}` : ''}
        {typically ? ` ${typically}` : ''}
      </div>
    );
  }

  const shown = expanded ? records : records.slice(0, DEFAULT_VISIBLE);
  const hidden = records.length - shown.length;

  return (
    <div className={`rounded-xl bg-slate-50 px-4 py-3 ring-1 ring-inset ring-slate-200 ${className}`}>
      <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
        <h4 className="text-sm font-bold text-slate-800">
          {t('Full record — {part} on this vehicle', { part: partName || t('this part') })}
        </h4>
        <span className="text-xs text-slate-500">
          {s.first_purchased_at && s.total_purchases > 1
            ? t('bought {n} time(s) since {date}', { n: num(s.total_purchases), date: fmtDate(s.first_purchased_at) })
            : t('bought {n} time(s)', { n: num(s.total_purchases) })}
        </span>
      </div>

      {/* The headline counts. `in_alert_window` is what the old 90-day check saw; the rest is what it hid. */}
      <div className="mt-2 flex flex-wrap items-center gap-1.5">
        {SHOW_FINANCIALS && s.total_spend != null && (
          <Badge tone="slate">
            {s.spend_covers < s.shown
              ? t('{amount} total ({n} priced)', { amount: aed(s.total_spend), n: num(s.spend_covers) })
              : t('{amount} total', { amount: aed(s.total_spend) })}
          </Badge>
        )}
        {s.days_since_last != null && <Badge tone="slate">{t('Last {n} day(s) ago', { n: num(s.days_since_last) })}</Badge>}
        {s.failed > 0 && <Badge tone="red">{t('{n} failed', { n: num(s.failed) })}</Badge>}
        {s.flagged > 0 && <Badge tone="amber">{t('{n} flagged', { n: num(s.flagged) })}</Badge>}
        {s.never_installed > 0 && <Badge tone="slate">{t('{n} never fitted', { n: num(s.never_installed) })}</Badge>}
        {/* Rows on this list that were WRITTEN DIFFERENTLY. Said out loud because a buyer scanning a
            list titled "Alternator" and finding a row called "دينامو" would otherwise assume the
            screen is wrong — and because it is the count that shows what identity matching bought. */}
        {s.other_wording > 0 && (
          <Badge tone="cyan">{t('{n} under another name', { n: num(s.other_wording) })}</Badge>
        )}
        {s.in_alert_window > 0 && (
          <Badge tone="amber">{t('{n} within {d} day(s)', { n: num(s.in_alert_window), d: num(s.alert_window_days) })}</Badge>
        )}
      </div>

      {/* DATA ORIGIN — how this list was assembled, never left implicit. The whole feature rests on a
          claim ("these are the same part") that the reader cannot verify by looking, so the basis for
          it is stated: the catalog part it was identified as, and the other names that counted. */}
      <p className="mt-1.5 text-[11px] text-slate-500" dir="auto">
        {s.identified_via === 'catalog' || s.identified_via === 'name'
          ? t('Matched as {part} — the part itself, not the wording.', { part: s.part_name })
          : t('Matched on this exact wording only — this part is not linked to the catalog, so the same part bought under another name will not appear.')}
        {s.other_names?.length
          ? ` ${t('Also counts: {names}.', { names: s.other_names.join(', ') })}`
          : ''}
      </p>

      <ul className="mt-2.5 space-y-2">
        {shown.map((rec) => <PurchaseRow key={rec.purchase_id} rec={rec} askedFor={partName} />)}
      </ul>

      {(hidden > 0 || expanded) && records.length > DEFAULT_VISIBLE && (
        <button
          type="button"
          onClick={() => setExpanded((v) => !v)}
          className="mt-2 text-xs font-semibold text-indigo-600 hover:text-indigo-700"
        >
          {expanded ? t('Show less') : t('Show all {n} purchases', { n: num(records.length) })}
        </button>
      )}

      {/* The cap is stated, never silent — otherwise a partial list reads as the whole record. */}
      {history?.truncated && (
        <p className="mt-2 text-[11px] text-slate-500">
          {t('Showing the {shown} most recent of {total} purchases.', { shown: num(s.shown), total: num(s.total_purchases) })}
        </p>
      )}

      {fleet && (
        <p className="mt-2 border-t border-slate-200 pt-2 text-[11px] text-slate-600">
          <span className="font-semibold">{t('Across the fleet:')}</span>{' '}
          {t('{n} more purchase(s) on {v} other vehicle(s).', { n: num(fleet.purchases), v: num(fleet.vehicles) })}
          {typically ? ` ${typically}` : ''}
        </p>
      )}
    </div>
  );
}
