// THE MATRIX — garages down, repair areas across, "is this garage good at this" in every cell.
//
// This is the panel that answers the actual question people bring to this page: not "is this garage
// good" but "is this garage good AT TYRES". A per-garage average hides exactly that — a shop can sit
// on a respectable overall number while being the worst place in the fleet to send a suspension job,
// and the average is the thing doing the hiding.
//
// IT SAYS "Good" AND "Weak", NOT "−26" AND "+6".
//
// The first version filled every cell with a signed percentage-point delta. It was precise, it was
// traceable, and reading it required knowing that the number was points-against-that-column's-fleet-
// rate, that negative meant fewer repairs came back, and therefore that −26 was good news and +6 was
// bad. That is three things to hold in your head per cell, ninety cells to a screen — and it got the
// entirely fair response that the panel was hard to read. A grid nobody can read at a glance is not a
// summary; it is a table that has to be studied, which is what the garage cards below are for.
//
// So the default cell is a WORD. The numbers are one toggle away, unchanged, for whoever wants to
// audit a cell or argue with it — the precision was never the problem, leading with it was.
//
// READING RULE, stated on the panel and not just in a doc: a cell compares this garage to the FLEET
// IN THAT AREA. Comparing tyre work to the all-fleet average would penalise every tyre shop for a
// fault type that recurs more than average everywhere.
//
// Colour is never the only carrier — every cell prints its word (or its number), so the panel is
// readable in greyscale and to a colourblind reader. Cells below the sample floor are drawn empty
// rather than tinted, because a grade we did not earn must not look like one.

import { Link } from 'react-router-dom';
import { useMemo, useState } from 'react';
import { Tooltip } from '../ui/Tooltip';
import { SectionCard } from '../ui/Table';
import Segmented from '../ui/Segmented';
import { deltaCell } from './scorecardUtils';
import { comparisonWords, outcomeLines } from './phrasing';
import { useI18n } from '../../i18n/I18nContext';

const ROWS = 14;

// Three states, flattened from the seven-step diverging ramp the numbers view uses. A heat map with
// seven shades invites the reader to rank shades; three states invite them to read a word.
const SIMPLE = {
  strong: { bg: 'bg-emerald-500', text: 'text-white', key: 'good' },
  on_par: { bg: 'bg-slate-200', text: 'text-slate-700', key: 'ok' },
  weak: { bg: 'bg-red-500', text: 'text-white', key: 'weak' },
};

export default function DomainMatrix({ garages = [], domains = [], onPick, onEvidence }) {
  const { t } = useI18n();
  const g = (k, v) => t(`garages.${k}`, v);
  const [view, setView] = useState('simple');
  const numbers = view === 'numbers';

  // Only areas the fleet actually grades, biggest first — an all-grey column of ungradeable
  // damage work would push the columns that matter off the screen.
  const cols = useMemo(
    () => domains.filter((d) => d.graded && d.garages_ranked > 0).slice(0, 9),
    [domains],
  );

  // Always best-rated first. The volume ordering that used to live here is what the Directory tab is
  // sorted by anyway, and a second control on a panel whose complaint was "too hard to read" is the
  // wrong trade.
  const rows = useMemo(() => {
    const scored = garages.filter((x) => x.score?.value != null);
    return [...scored]
      .sort((a, b) => b.score.value - a.score.value)
      .slice(0, ROWS)
      .map((x) => ({ ...x, cells: Object.fromEntries(x.domains.map((d) => [d.key, d])) }));
  }, [garages]);

  if (cols.length === 0 || rows.length === 0) {
    return (
      <SectionCard title={g('matrix.title')} subtitle={g('matrix.subtitle')} bodyClass="p-5">
        <p className="py-6 text-center text-sm text-slate-400">{g('matrix.empty')}</p>
      </SectionCard>
    );
  }

  return (
    <SectionCard
      title={g('matrix.title')}
      subtitle={g('matrix.subtitle')}
      actions={
        <Segmented
          value={view}
          onChange={setView}
          options={[{ key: 'simple', label: g('matrix.simple') }, { key: 'numbers', label: g('matrix.numbers') }]}
        />
      }
      bodyClass="p-5"
    >
      {/* The key sits ABOVE the grid, in one sentence, because a legend under a table is read after
          the table has already confused someone. */}
      <p className="mb-3 rounded-xl bg-slate-50 px-3 py-2 text-xs leading-relaxed text-slate-600 ring-1 ring-inset ring-slate-200">
        {g(numbers ? 'matrix.keyNumbers' : 'matrix.keySimple')}
      </p>

      {/* Wide content scrolls inside its own box — the page body must never scroll sideways. */}
      <div className="-mx-1 overflow-x-auto px-1 pb-1">
        <table className="w-full min-w-[46rem] border-separate border-spacing-x-0 border-spacing-y-1">
          <thead>
            <tr>
              <th className="sticky start-0 z-10 bg-white text-start text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                {g('matrix.garage')}
              </th>
              {cols.map((c) => (
                <th key={c.key} className="px-1 pb-1 align-bottom">
                  <span className="block text-[11px] font-semibold leading-tight text-slate-500">{c.label}</span>
                  {/* The fleet rate is context for a number, and clutter beside a word. */}
                  {numbers && (
                    <span className="block text-[10px] font-normal text-slate-400">
                      {g('matrix.fleetPct', { pct: Math.round(c.comeback_pct) })}
                    </span>
                  )}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.vendor_id}>
                <td className="sticky start-0 z-10 bg-white pe-3">
                  {/* Straight to the profile. onPick (scroll to the card below) predates the profile
                      page and is kept on the scoreboard, where staying on the page is the point. */}
                  <Link
                    to={`/intelligence/garages/${r.vendor_id}`}
                    className="block w-40 truncate text-start text-sm font-medium text-slate-800 hover:text-indigo-600 hover:underline"
                    title={r.garage}
                  >
                    {r.garage}
                  </Link>
                  <span className="text-[10px] text-slate-400">{g('matrix.score', { n: r.score.value })}</span>
                </td>
                {cols.map((c) => {
                  const cell = r.cells[c.key];
                  const graded = cell?.graded;
                  const pts = graded ? cell.vs_fleet_pts : null;
                  const simple = graded ? SIMPLE[cell.grade] : null;
                  const style = numbers
                    ? deltaCell(pts)
                    : (simple || { bg: 'bg-slate-50 ring-1 ring-inset ring-slate-200', text: 'text-slate-300' });

                  // COUNTED REPAIRS, ONE PER LINE. A hover is not the place to make somebody parse a
                  // paragraph of percentages — it is the place to show what actually happened to the
                  // cars this garage worked on.
                  const tip = !cell
                    ? g('matrix.tip.none', { garage: r.garage, area: c.label })
                    : graded
                      ? (
                        <span className="block text-start">
                          <span className="mb-0.5 block font-semibold">{g('matrix.tip.head', { area: c.label, garage: r.garage })}</span>
                          {outcomeLines(cell, t).map((l) => <span key={l} className="block">{l}</span>)}
                          <span className="mt-0.5 block text-white/70">{g('matrix.tip.tail', { cmp: comparisonWords(cell, t) })}</span>
                        </span>
                      )
                      : cell.not_graded_reason;

                  const label = numbers
                    ? (graded ? (pts > 0 ? `+${Math.round(pts)}` : Math.round(pts)) : '·')
                    : (simple ? g(`matrix.cell.${simple.key}`) : '·');

                  // A GRADED cell opens onto the repairs behind it. An ungraded one stays inert —
                  // there is nothing to show, and a button that opens an empty drawer teaches people
                  // the drawer is not worth clicking.
                  const canDrill = graded && cell?.evidence_query_id && onEvidence;

                  const face = (
                    <span
                      className={`flex h-9 w-full min-w-[3.5rem] items-center justify-center rounded-md text-xs font-semibold tabular-nums ${style.bg} ${style.text} ${canDrill ? 'cursor-pointer ring-offset-1 transition hover:ring-2 hover:ring-blue-400' : ''}`}
                    >
                      {label}
                    </span>
                  );

                  return (
                    <td key={c.key} className="px-0.5">
                      <Tooltip content={tip} className="w-full">
                        {canDrill ? (
                          <button
                            type="button"
                            onClick={() => onEvidence(cell.evidence_query_id)}
                            className="block w-full"
                            aria-label={g('matrix.tip.head', { area: c.label, garage: r.garage })}
                          >
                            {face}
                          </button>
                        ) : face}
                      </Tooltip>
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t border-slate-100 pt-3 text-[11px] text-slate-400">
        {numbers ? (
          <>
            <span className="flex items-center gap-1.5"><span className="h-3 w-5 rounded-sm bg-emerald-500" />{g('matrix.legend.better')}</span>
            <span className="flex items-center gap-1.5"><span className="h-3 w-5 rounded-sm bg-slate-200" />{g('matrix.legend.same')}</span>
            <span className="flex items-center gap-1.5"><span className="h-3 w-5 rounded-sm bg-red-500" />{g('matrix.legend.worse')}</span>
          </>
        ) : (
          <>
            <span className="flex items-center gap-1.5"><span className="rounded-sm bg-emerald-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">{g('matrix.cell.good')}</span>{g('matrix.legend.better')}</span>
            <span className="flex items-center gap-1.5"><span className="rounded-sm bg-slate-200 px-1.5 py-0.5 text-[10px] font-semibold text-slate-700">{g('matrix.cell.ok')}</span>{g('matrix.legend.same')}</span>
            <span className="flex items-center gap-1.5"><span className="rounded-sm bg-red-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">{g('matrix.cell.weak')}</span>{g('matrix.legend.worse')}</span>
          </>
        )}
        <span className="flex items-center gap-1.5"><span className="h-3 w-5 rounded-sm bg-slate-50 ring-1 ring-inset ring-slate-200" />{g('matrix.legend.thin')}</span>
      </div>
      <p className="mt-2 text-[11px] leading-relaxed text-slate-400">{g('matrix.readingRule')}</p>
    </SectionCard>
  );
}
