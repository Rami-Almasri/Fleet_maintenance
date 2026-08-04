// "WHO IS BEST AT TYRES?" — pick a repair area, get the record.
//
// Both ends, always. A leaderboard that only shows winners cannot say "this garage has a problem",
// which is half of what this page is for; the two lists sit side by side so the spread is visible
// rather than implied.
//
// This is a RECORD, not a routing decision. It knows nothing about the car, how urgent the fault
// is, what the garage's queue looks like or what the job will cost — the Garage Finder and the
// assign step know all four. The footer says so, because a leaderboard that reads like an
// instruction will be followed like one.

import { useMemo, useState } from 'react';
import { SectionCard } from '../ui/Table';
import Badge from '../ui/Badge';
import { num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

function Side({ rows = [], tone, title, empty, onPick, onEvidence, t }) {
  const g = (k, v) => t(`garages.${k}`, v);
  return (
    <div>
      <p className="mb-2 text-[11px] font-bold uppercase tracking-wide text-slate-400">{title}</p>
      {rows.length === 0 ? (
        <p className="text-xs text-slate-400">{empty}</p>
      ) : (
        <ul className="space-y-1.5">
          {rows.map((r) => (
            <li key={r.vendor_id}>
              <button
                type="button"
                onClick={() => onPick?.(r.vendor_id)}
                className="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-start transition hover:bg-slate-50"
              >
                <span className="w-5 shrink-0 text-xs font-semibold tabular-nums text-slate-400">#{r.rank}</span>
                <span className="min-w-0 flex-1 truncate text-sm text-slate-800">{r.garage}</span>
                <span
                  role={r.evidence_query_id && onEvidence ? 'button' : undefined}
                  tabIndex={r.evidence_query_id && onEvidence ? 0 : undefined}
                  onClick={r.evidence_query_id && onEvidence ? (e) => { e.stopPropagation(); onEvidence(r.evidence_query_id); } : undefined}
                  onKeyDown={r.evidence_query_id && onEvidence ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); e.stopPropagation(); onEvidence(r.evidence_query_id); } } : undefined}
                  className={`shrink-0 text-sm font-bold tabular-nums ${tone === 'green' ? 'text-emerald-600' : 'text-red-600'} ${r.evidence_query_id && onEvidence ? 'cursor-pointer underline decoration-dotted underline-offset-4' : ''}`}
                >
                  {Math.round(r.comeback_pct)}%
                </span>
                <span className="w-16 shrink-0 text-end text-[11px] text-slate-400">
                  {g('leaderboard.over', { n: num(r.jobs) })}
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export default function DomainLeaderboard({ leaderboard = {}, domains = [], onPick, onEvidence }) {
  const { t } = useI18n();
  const g = (k, v) => t(`garages.${k}`, v);

  const areas = useMemo(
    () => domains.filter((d) => d.graded && (leaderboard[d.key]?.measured || 0) > 1),
    [domains, leaderboard],
  );
  const [area, setArea] = useState(null);
  const key = area && leaderboard[area] ? area : areas[0]?.key;
  const board = key ? leaderboard[key] : null;

  if (!board) {
    return (
      <SectionCard title={g('leaderboard.title')} subtitle={g('leaderboard.subtitle')} bodyClass="p-5">
        <p className="py-6 text-center text-sm text-slate-400">{g('leaderboard.empty')}</p>
      </SectionCard>
    );
  }

  return (
    <SectionCard title={g('leaderboard.title')} subtitle={g('leaderboard.subtitle')} bodyClass="p-5">
      <div className="mb-4 flex flex-wrap gap-1.5">
        {areas.map((d) => {
          const on = d.key === key;
          return (
            <button
              key={d.key}
              type="button"
              onClick={() => setArea(d.key)}
              className={`rounded-lg px-2.5 py-1.5 text-xs font-medium transition ${
                on ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-300 hover:bg-slate-50'}`}
            >
              {d.label}
            </button>
          );
        })}
      </div>

      <div className="mb-4 flex flex-wrap items-center gap-2 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-500 ring-1 ring-inset ring-slate-200">
        <Badge tone="slate">{g('leaderboard.fleetRate', { pct: Math.round(board.fleet_pct) })}</Badge>
        <span>{g('leaderboard.comparedAcross', { n: board.measured })}</span>
      </div>

      <div className="grid gap-5 sm:grid-cols-2">
        <Side rows={board.best} tone="green" title={g('leaderboard.best')} empty={g('leaderboard.empty')} onPick={onPick} onEvidence={onEvidence} t={t} />
        <Side rows={board.worst} tone="red" title={g('leaderboard.worst')} empty={g('leaderboard.empty')} onPick={onPick} onEvidence={onEvidence} t={t} />
      </div>

      <p className="mt-4 border-t border-slate-100 pt-3 text-[11px] leading-relaxed text-slate-400">
        {g('leaderboard.footnote')}
      </p>
    </SectionCard>
  );
}
