// THE SCOREBOARD — every scored garage on one axis, best first.
//
// The number is not "how good is this garage" in the abstract; it is "how does this garage do
// against what the fleet averages on the SAME mix of work it takes on". That is why 50 is drawn
// as a line rather than left implicit: without it a 48 looks like a failing grade instead of what
// it is — an ordinary garage.
//
// Garages that cannot be scored are NOT hidden and NOT sorted in as zeros. They sit in their own
// list underneath with the reason, because "we have not measured this garage" and "this garage is
// bad" are opposite claims and a chart that blends them is lying.

import { Tooltip } from '../ui/Tooltip';
import Badge from '../ui/Badge';
import { SectionCard } from '../ui/Table';
import { scoreBar, BAND_TONE } from './scorecardUtils';
import { headlineFor } from './phrasing';
import { num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

export default function GarageScoreboard({ garages = [], fleet = {}, limit = 12, onPick, selected = [], onToggleSelect, onCompare }) {
  const { t } = useI18n();
  const g = (k, v) => t(`garages.${k}`, v);

  const scored = garages.filter((x) => x.score?.value != null).slice(0, limit);
  const unscored = garages.filter((x) => x.score?.value == null);

  return (
    <SectionCard
      title={g('score.boardTitle')}
      subtitle={g('score.boardSubtitle', {
        pct: fleet.comeback_pct != null ? Math.round(fleet.comeback_pct) : '—',
        n: num(fleet.comeback_n || 0),
      })}
      bodyClass="p-5"
    >
      {scored.length === 0 ? (
        <p className="py-6 text-center text-sm text-slate-400">{g('score.noneScored')}</p>
      ) : (
        <div className="space-y-2.5">
          {/* The tooltip wraps the whole row rather than the bar: an interactive tooltip target
              nested inside the button would be invalid markup, and hovering a 5px-tall track is a
              worse hit area than hovering the row. */}
          {scored.map((x, i) => (
            <div key={x.vendor_id} className="flex items-center gap-2">
              {onToggleSelect && (
                <input
                  type="checkbox"
                  checked={selected.includes(x.vendor_id)}
                  onChange={() => onToggleSelect(x.vendor_id)}
                  className="h-3.5 w-3.5 shrink-0 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                  aria-label={g('score.selectForCompare', { garage: x.garage })}
                />
              )}
            <Tooltip content={headlineFor(x, t)} className="w-full">
              <button
                type="button"
                onClick={() => onPick?.(x.vendor_id)}
                className="flex w-full items-center gap-3 rounded-lg px-1 py-1 text-start transition hover:bg-slate-50"
              >
                <span className="w-5 shrink-0 text-xs font-semibold tabular-nums text-slate-400">{i + 1}</span>
                <span className="w-36 shrink-0 truncate text-sm font-medium text-slate-800 sm:w-48">{x.garage}</span>

                {/* The track carries the 50 = fleet-average reference; the bar is the score. */}
                <span className="relative h-5 flex-1 overflow-hidden rounded-md bg-slate-100">
                  <span
                    className={`absolute inset-y-0 start-0 rounded-md transition-all duration-500 ${scoreBar(x.score.value)}`}
                    style={{ width: `${Math.max(x.score.value, 2)}%` }}
                  />
                  <span
                    className="absolute inset-y-0 w-px bg-slate-400/70"
                    style={{ insetInlineStart: '50%' }}
                    aria-hidden
                  />
                </span>

                <span className="w-8 shrink-0 text-end text-sm font-bold tabular-nums text-slate-900">{x.score.value}</span>
                <span className="hidden w-24 shrink-0 sm:block">
                  <Badge tone={BAND_TONE[x.score.band] || 'gray'}>
                    {g(`score.band.${x.score.band}`)}
                  </Badge>
                </span>
              </button>
            </Tooltip>
            </div>
          ))}

          {onCompare && selected.length > 0 && (
            <div className="flex items-center justify-between gap-3 rounded-lg bg-blue-50 px-3 py-2 ring-1 ring-inset ring-blue-200">
              <span className="text-xs font-medium text-blue-900">
                {g('score.selectedCount', { n: selected.length })}
              </span>
              <button
                type="button"
                onClick={onCompare}
                disabled={selected.length < 2}
                className="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-blue-300"
              >
                {selected.length < 2 ? g('score.pickOneMore') : g('score.compareN', { n: selected.length })}
              </button>
            </div>
          )}

          <div className="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-slate-100 pt-3 text-[11px] text-slate-400">
            <span className="flex items-center gap-1.5"><span className="h-2 w-4 rounded-sm bg-emerald-500" />{g('score.legend.better')}</span>
            <span className="flex items-center gap-1.5"><span className="h-2 w-4 rounded-sm bg-slate-400" />{g('score.legend.average')}</span>
            <span className="flex items-center gap-1.5"><span className="h-2 w-4 rounded-sm bg-red-500" />{g('score.legend.worse')}</span>
            <span className="flex items-center gap-1.5"><span className="h-3 w-px bg-slate-400" />{g('score.legend.midline')}</span>
          </div>
        </div>
      )}

      {unscored.length > 0 && (
        <div className="mt-4 border-t border-slate-100 pt-3">
          <p className="text-xs font-semibold text-slate-500">{g('score.unscored', { n: unscored.length })}</p>
          <p className="mt-1 text-xs leading-relaxed text-slate-400">{g('score.unscoredWhy')}</p>
        </div>
      )}
    </SectionCard>
  );
}
