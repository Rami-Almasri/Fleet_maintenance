// ONE GARAGE, WHOLE. The judgement and the live workload on the same card.
//
// These used to be two different pages' worth of thinking: "how many cars are in there and how
// late are they" (operational) and "is the work any good" (historical). Keeping them apart meant
// nobody ever asked the follow-up question — you saw six cars overdue at a garage without ever
// seeing that its repairs come back twice as often as anyone else's.
//
// The card leads with the sentence, not the number. "Weakest on Tyres & Wheels — 40 of every 100
// repairs there come back within 90 days" is something a supervisor can act on; a bare 31/100 is
// something they have to decode first. The score is there to rank by, the sentence is there to
// read, and the area table underneath is there when somebody wants to argue with it.

import { useState } from 'react';
import { Link } from 'react-router-dom';
import Badge from '../ui/Badge';
import Icon from '../ui/Icon';
import { Card } from '../ui/Misc';
import { Tooltip } from '../ui/Tooltip';
import { GRADES, scoreTone, BAND_TONE } from './scorecardUtils';
import { headlineFor, comparisonWords, outcomeLines } from './phrasing';
import OutcomeBar from './OutcomeBar';
import { aed2, num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

const DOT = { on_track: 'bg-emerald-500', at_risk: 'bg-amber-500', breached: 'bg-red-500', unknown: 'bg-slate-300' };
const PRIO_DOT = { critical: 'bg-red-500', special: 'bg-violet-500', minor: 'bg-amber-500', routine: 'bg-emerald-500' };

/**
 * A tooltip laid out as short lines rather than one long sentence.
 *
 * The record is four or five facts — how many repairs, how many held, how many came back and how
 * fast — and a hover that runs them together into a paragraph is the thing that got this panel
 * called hard to read. One fact per line, and the comparison as the closing clause.
 */
function TipBlock({ title, lines, tail }) {
  return (
    <span className="block text-start">
      {title && <span className="mb-0.5 block font-semibold">{title}</span>}
      {lines.map((l) => <span key={l} className="block">{l}</span>)}
      {tail && <span className="mt-0.5 block text-white/70">{tail}</span>}
    </span>
  );
}

// Spans, not divs — a hinted stat is wrapped in <Tooltip>, whose trigger is a <span>, and block
// elements inside it would be invalid markup that React silently renders and browsers reflow oddly.
function Stat({ label, value, tone = 'text-slate-900', hint }) {
  const body = (
    <span className="block">
      <span className="block text-xs font-medium text-slate-500">{label}</span>
      <span className={`mt-0.5 block text-lg font-bold tracking-tight ${tone}`}>{value}</span>
    </span>
  );
  return hint ? <Tooltip content={hint} className="w-full">{body}</Tooltip> : body;
}

/** The 0–100 headline, drawn as a ring so it reads as a rating rather than a count. */
function ScoreDial({ score, t }) {
  const g = (k, v) => t(`garages.${k}`, v);
  const v = score?.value;
  const tone = scoreTone(v);
  const stroke = { green: '#10b981', emerald: '#34d399', slate: '#94a3b8', amber: '#f59e0b', red: '#ef4444', gray: '#cbd5e1' }[tone];
  const r = 26;
  const c = 2 * Math.PI * r;

  return (
    <div className="flex items-center gap-3">
      <div className="relative h-16 w-16 shrink-0">
        <svg viewBox="0 0 64 64" className="h-16 w-16 -rotate-90">
          <circle cx="32" cy="32" r={r} fill="none" stroke="rgb(226 232 240)" strokeWidth="6" />
          {v != null && (
            <circle
              cx="32" cy="32" r={r} fill="none" stroke={stroke} strokeWidth="6" strokeLinecap="round"
              strokeDasharray={c} strokeDashoffset={c * (1 - v / 100)}
              style={{ transition: 'stroke-dashoffset 700ms ease-out' }}
            />
          )}
        </svg>
        <span className="absolute inset-0 flex items-center justify-center text-lg font-bold tabular-nums text-slate-900">
          {v ?? '—'}
        </span>
      </div>
      <div className="min-w-0">
        <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">{g('score.label')}</p>
        {v != null ? (
          <>
            <Badge tone={BAND_TONE[score.band] || 'gray'}>{g(`score.band.${score.band}`)}</Badge>
            <p className="mt-1 text-[11px] text-slate-400">{g('score.meaning')}</p>
          </>
        ) : (
          <p className="mt-0.5 max-w-[16rem] text-[11px] leading-relaxed text-slate-400">{score?.reason}</p>
        )}
      </div>
    </div>
  );
}

export default function GarageCard({ perf, card, defaultOpen = false }) {
  const { t, isRTL } = useI18n();
  const g = (k, v) => t(`garages.${k}`, v);
  const [open, setOpen] = useState(defaultOpen);

  const name = card?.garage || perf?.garage || '—';
  const areas = card?.domains || [];

  return (
    <Card>
      <div className="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 px-6 py-4">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="text-base font-semibold text-slate-900">{name}</h3>
            {perf?.in_garage_now > 0 && <Badge tone="blue">{g('badge.inGarage', { n: perf.in_garage_now })}</Badge>}
            {perf?.overdue_now > 0 && <Badge tone="red">{g('badge.overdue', { n: perf.overdue_now })}</Badge>}
          </div>

          {/* Built client-side from the payload's numbers — see phrasing.js. The server used to send
              this sentence pre-written in English, which an Arabic reader got verbatim on an
              otherwise translated card. */}
          {card && (
            <p className="mt-1.5 max-w-2xl text-sm leading-relaxed text-slate-600">{headlineFor(card, t)}</p>
          )}

          {/* The garage's whole record in one picture: how many repairs held, how many came back, and
              how fast. This is the thing a percentage was standing in for. */}
          {card?.reliability?.n > 0 && (
            <div className="mt-2.5 max-w-md">
              <OutcomeBar row={card.reliability} />
            </div>
          )}

          {/* The chips ARE the answer to "what is he good at / bad at". Word first, number second —
              a colour-only chip is unreadable to a third of people and to anyone printing this. */}
          {(card?.strengths?.length > 0 || card?.problems?.length > 0) && (
            <div className="mt-2.5 flex flex-wrap gap-1.5">
              {card.problems.map((d) => (
                <Tooltip
                  key={`p-${d.key}`}
                  content={<TipBlock title={d.label} lines={outcomeLines(d, t)} tail={g('area.tip', { cmp: comparisonWords(d, t) })} />}
                >
                  <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/20">
                    <Icon.Alert className="h-3 w-3" />
                    {g('chip.weakAt', { area: d.label, n: Math.round(d.vs_fleet_pts) })}
                  </span>
                </Tooltip>
              ))}
              {card.strengths.map((d) => (
                <Tooltip
                  key={`s-${d.key}`}
                  content={<TipBlock title={d.label} lines={outcomeLines(d, t)} tail={g('area.tip', { cmp: comparisonWords(d, t) })} />}
                >
                  <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
                    <Icon.Check className="h-3 w-3" />
                    {g('chip.strongAt', { area: d.label, n: Math.abs(Math.round(d.vs_fleet_pts)) })}
                  </span>
                </Tooltip>
              ))}
            </div>
          )}
        </div>

        {card && <ScoreDial score={card.score} t={t} />}
      </div>

      {/* Operational facts. Late returns are shown but never scored — see the Data Origin panel. */}
      <div className="grid grid-cols-2 gap-4 px-6 py-4 sm:grid-cols-6">
        <Stat label={g('stat.jobs')} value={num(perf?.jobs || 0)} />
        <Stat label={g('stat.inNow')} value={num(perf?.in_garage_now || 0)} tone={perf?.in_garage_now > 0 ? 'text-blue-600' : 'text-slate-900'} />
        <Stat
          label={g('stat.comeback')}
          value={card?.reliability?.comeback_pct != null ? `${Math.round(card.reliability.comeback_pct)}%` : '—'}
          tone={card?.reliability?.vs_expected_pts > 8 ? 'text-red-600' : card?.reliability?.vs_expected_pts < -8 ? 'text-emerald-600' : 'text-slate-900'}
          hint={card?.reliability?.expected_pct != null
            ? g('stat.comebackHint', { exp: Math.round(card.reliability.expected_pct), n: num(card.reliability.n) })
            : card?.reliability?.reason}
        />
        <Stat
          label={g('stat.turnaround')}
          value={card?.speed?.days != null ? `${card.speed.days}${t('dash.unit.d')}` : '—'}
          hint={card?.speed?.expected_days != null
            ? g('stat.turnaroundHint', { exp: card.speed.expected_days, n: num(card.speed.n) })
            : card?.speed?.reason}
        />
        <Stat label={g('stat.lateReturns')} value={num(perf?.late_returns || 0)} tone={perf?.late_returns > 0 ? 'text-red-600' : 'text-slate-900'} hint={g('stat.lateHint')} />
        <Stat label={g('stat.totalSpent')} value={aed2(perf?.total_spent || 0)} />
      </div>

      {perf?.current_cars?.length > 0 && (
        <div className="border-t border-slate-100 px-6 py-3">
          <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{g('carsHereNow')}</p>
          <div className="flex flex-wrap gap-2">
            {perf.current_cars.map((car) => (
              <Link key={car.id} to={`/contracts/${car.id}`} className="inline-flex flex-col gap-0.5 rounded-lg border border-slate-200 px-3 py-1.5 text-sm hover:bg-slate-50">
                <span className="flex items-center gap-2">
                  <span className={`h-2 w-2 rounded-full ${DOT[car.status] || DOT.unknown}`} />
                  <span className="font-medium text-slate-800">{car.plate || `#${car.id}`}</span>
                  <span className="text-xs text-slate-400">
                    {car.days_out}{t('dash.unit.d')}{car.overdue_days > 0 ? ` · ${g('lateBy', { n: car.overdue_days })}` : ''}
                  </span>
                </span>
                {car.car && <span className="ps-4 text-xs text-slate-500">{car.car}</span>}
              </Link>
            ))}
          </div>
        </div>
      )}

      {!perf?.current_cars?.length && perf?.recent_cars?.length > 0 && (
        <div className="border-t border-slate-100 px-6 py-3">
          <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{g('recentCars')}</p>
          <div className="flex flex-wrap gap-2">
            {perf.recent_cars.map((car) => (
              <Link key={car.id} to={`/vehicles/${car.id}`} className="inline-flex flex-col gap-0.5 rounded-lg border border-slate-200 px-3 py-1.5 text-sm hover:bg-slate-50">
                <span className="flex items-center gap-2">
                  <span className={`h-2 w-2 rounded-full ${PRIO_DOT[car.priority] || PRIO_DOT.routine}`} />
                  <span className="font-medium text-slate-800">{car.plate || `#${car.id}`}</span>
                  <span className="text-xs text-slate-400">{car.date || ''}</span>
                </span>
                {car.car && <span className="ps-4 text-xs text-slate-500">{car.car}</span>}
              </Link>
            ))}
          </div>
        </div>
      )}

      {areas.length > 0 && (
        <div className="border-t border-slate-100">
          <button
            type="button"
            onClick={() => setOpen((o) => !o)}
            className="flex w-full items-center justify-between px-6 py-2.5 text-xs font-semibold text-slate-500 hover:bg-slate-50"
            aria-expanded={open}
          >
            <span>{g('area.breakdown', { n: areas.length })}</span>
            <Icon.ChevronDown className={`h-4 w-4 transition-transform ${open ? 'rotate-180' : ''}`} />
          </button>

          {open && (
            <div className="overflow-x-auto px-6 pb-4">
              <table className="w-full min-w-[38rem] text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-[11px] uppercase tracking-wide text-slate-400">
                    <th className="py-2 text-start font-semibold">{g('area.col.area')}</th>
                    <th className="py-2 text-end font-semibold">{g('area.col.jobs')}</th>
                    <th className="py-2 text-start font-semibold">{g('area.col.happened')}</th>
                    <th className="py-2 text-end font-semibold">{g('area.col.comeback')}</th>
                    <th className="py-2 text-end font-semibold">{g('area.col.fleet')}</th>
                    <th className="py-2 text-end font-semibold">{g('area.col.days')}</th>
                    <th className="py-2 text-end font-semibold">{g('area.col.rank')}</th>
                    <th className="py-2 text-end font-semibold">{g('area.col.verdict')}</th>
                  </tr>
                </thead>
                <tbody>
                  {areas.map((d) => {
                    const grade = GRADES[d.grade] || GRADES.thin;
                    return (
                      <tr key={d.key} className="border-b border-slate-50 last:border-0">
                        <td className="py-2 pe-2 font-medium text-slate-800">
                          {d.label}
                          {d.share_pct != null && <span className="ms-1.5 text-[11px] font-normal text-slate-400">{g('area.share', { pct: d.share_pct })}</span>}
                        </td>
                        {/* JOBS AND GRADED JOBS ARE DIFFERENT NUMBERS, and the row is a lie when it
                            shows only the first. "Bodywork & Exterior · 336 jobs · 15% come back"
                            reads as 15% of 336 — but 295 of those are dent and paint work, excluded
                            from the grade because cars get scraped. The percentage is really 41
                            windscreen and mirror repairs. Same for Tyres, where rim work is excluded.
                            So the basis is printed whenever it differs from the volume. */}
                        <td className="py-2 text-end tabular-nums text-slate-600">
                          {num(d.jobs)}
                          {d.graded && d.graded_jobs < d.jobs && (
                            <Tooltip content={g('area.gradedHint', { n: num(d.graded_jobs), total: num(d.jobs) })}>
                              <span className="ms-1 text-[11px] text-slate-400 underline decoration-dotted">
                                {g('area.gradedOn', { n: num(d.graded_jobs) })}
                              </span>
                            </Tooltip>
                          )}
                        </td>
                        {/* The picture first, the percentage after it. Same measurement, but the bar
                            is the one a reader can compare down the column at a glance. */}
                        <td className="py-2 pe-3">
                          {d.graded
                            ? <Tooltip content={<TipBlock lines={outcomeLines(d, t)} />}><OutcomeBar row={d} compact /></Tooltip>
                            : <span className="text-slate-300">—</span>}
                        </td>
                        <td className="py-2 text-end tabular-nums font-semibold text-slate-800">
                          {d.graded ? `${Math.round(d.comeback_pct)}%` : '—'}
                        </td>
                        <td className="py-2 text-end tabular-nums text-slate-400">
                          {d.fleet_comeback_pct != null ? `${Math.round(d.fleet_comeback_pct)}%` : '—'}
                        </td>
                        <td className="py-2 text-end tabular-nums text-slate-600">
                          {d.turnaround_days != null ? `${d.turnaround_days}${t('dash.unit.d')}` : '—'}
                        </td>
                        <td className="py-2 text-end tabular-nums text-slate-600">
                          {d.rank ? g('area.rankOf', { rank: d.rank, of: d.ranked_of }) : '—'}
                        </td>
                        <td className="py-2 text-end">
                          {d.graded ? (
                            <Badge tone={grade.tone}>{g(`grade.${d.grade}`)}</Badge>
                          ) : (
                            <Tooltip content={d.not_graded_reason}>
                              <span className="text-[11px] text-slate-400 underline decoration-dotted">{g(`grade.${d.grade}`)}</span>
                            </Tooltip>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
              <p className="mt-2 text-[11px] text-slate-400">{g('area.footnote')}</p>
            </div>
          )}
        </div>
      )}

      <div className="border-t border-slate-100 px-6 py-2.5">
        <Link
          to={`/garage-finder`}
          className="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-700"
        >
          {g('askFinder')} {isRTL ? '←' : '→'}
        </Link>
      </div>
    </Card>
  );
}
