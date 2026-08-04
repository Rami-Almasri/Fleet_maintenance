// WHAT HAPPENED TO THESE REPAIRS — the three counts, drawn.
//
// A garage's record used to be one percentage: "34% comeback rate". That number is correct and it is
// not a picture of anything. Two garages can share it and be nothing alike — one whose failures limp
// back within a fortnight, one whose cars run for two months first — and a single percentage hides
// exactly the difference an operator would act on.
//
// So the record is three counts of real repairs, in the order the eye should read them:
//
//   ├──────────── never came back ────────────┤── within 3 months ──┤─ within a month ─┤
//
// Good news leads because it is usually the majority; the worst outcome sits at the far end where a
// long red tail is unmissable. The counts are printed beside the bar, never colour alone.
//
// A stacked bar is the right form here and a pie is not: these are parts of one whole that must be
// compared ACROSS garages, and segment lengths on a shared baseline compare where pie angles do not.

import { num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

/** 2px surface gaps between segments so adjacent fills read as separate parts, not one blur. */
function Seg({ value, total, className, title }) {
  if (!value) return null;
  return (
    <span
      className={`h-full ${className}`}
      style={{ width: `${(value / total) * 100}%` }}
      title={title}
      aria-hidden
    />
  );
}

/**
 * @param row      any object carrying held / back_30 / back_90 (a domain row, or a garage's `reliability`)
 * @param compact  bar only, no legend — for a table cell
 */
export default function OutcomeBar({ row, compact = false }) {
  const { t } = useI18n();
  const g = (k, v) => t(`garages.outcome.${k}`, v);

  const held = row?.held ?? 0;
  const fast = row?.back_30 ?? 0;
  const slow = row?.back_90 ?? 0;
  const total = held + fast + slow;
  if (!total) return null;

  return (
    <div className={compact ? '' : 'space-y-1.5'}>
      <span className={`flex ${compact ? 'h-2 w-24' : 'h-2.5 w-full'} gap-px overflow-hidden rounded-full bg-slate-100`}>
        <Seg value={held} total={total} className="bg-emerald-500" title={g('held', { n: held })} />
        <Seg value={slow} total={total} className="bg-amber-400" title={g('back90', { n: slow })} />
        <Seg value={fast} total={total} className="bg-red-500" title={g('back30', { n: fast })} />
      </span>

      {!compact && (
        <p className="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] text-slate-500">
          <span className="font-semibold text-slate-700">{g('total', { n: num(total) })}</span>
          {held > 0 && (
            <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-emerald-500" />{g('held', { n: num(held) })}</span>
          )}
          {slow > 0 && (
            <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-amber-400" />{g('back90', { n: num(slow) })}</span>
          )}
          {fast > 0 && (
            <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-red-500" />{g('back30', { n: num(fast) })}</span>
          )}
          {/* Only when something actually came back — "usually 0 days later" on a clean record is a
              sentence about nothing. */}
          {row?.return_days != null && (fast + slow) > 0 && (
            <span className="text-slate-400">{g('typical', { d: Math.round(row.return_days) })}</span>
          )}
        </p>
      )}
    </div>
  );
}
