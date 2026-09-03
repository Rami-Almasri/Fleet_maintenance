// THE VISIT JOURNEY — everything that happened to the car under one maintenance contract.
//
// A type-'U' contract is opened the moment a car is booked in for a look and closed when Final QA signs
// it back into service, so it already IS the record of one workshop visit. It just never showed it: the
// page had the dates and the money, while the story — who found which fault, where it was fixed, how
// long it took — sat scattered across the ticket, its faults and the event log.
//
// This panel is that story, in the order a supervisor asks for it:
//   1. The headline — how many faults, how many the inspector caught before the car left, how many the
//      garage found once it was on the lift, and what it all cost.
//   2. Every fault, one card each: who found it, which garage finished it, how long it took, where it
//      travelled if it moved between garages.
//   3. The stage spine and the odometer chain — the car's own movements under this contract.
//   4. The full event trail, on demand.
//
// Fed by GET /Contract/{id}/journey (MaintenanceVisitJourneyService). Every number on this page is
// either a recorded fact or derived from one — nothing here is inferred or scored.

import { useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { Card, Spinner } from '../ui/Misc';
import Badge from '../ui/Badge';
import { fmtDate, fmtClock, fmtSeconds, num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

// A timestamp as "12 Aug 2026 · 3:40 PM". Built from the two existing helpers rather than a third
// date formatter, so this page reads in the same voice as every other date in the app.
const stamp = (value) => (value ? `${fmtDate(value)} · ${fmtClock(value)}` : '—');

// Fault status → badge tone. `completed` is the only one that means work happened and worked.
const STATUS_TONE = {
  completed: 'green',
  in_progress: 'blue',
  pending: 'amber',
  transferred: 'blue',
  cancelled: 'slate',
  not_found: 'slate',
};

// Who raised the fault. This split is the reason the panel exists, so it gets real colour, not a chip.
// `violet` and `orange` are Badge's own tone names — there is no `purple` in that palette.
const SOURCE_TONE = { inspector: 'violet', garage: 'orange' };

// A duration, or null when there is nothing to say. fmtSeconds renders "—" for null, which is right in
// a table cell but wrong here, where the absence decides which LABEL the cell gets ("worked" vs "at the
// garage") — so the null has to survive long enough to be tested.
const dur = (seconds) => (seconds == null ? null : fmtSeconds(seconds));

// Static per-tone classes — a computed `text-${tone}-700` does not survive Tailwind's purge.
const STAT_TONE = {
  slate: 'text-slate-700',
  violet: 'text-violet-700',
  orange: 'text-orange-700',
  green: 'text-emerald-700',
};

/** One number with its caption — the headline row. */
function Stat({ label, value, sub, tone = 'slate' }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white px-4 py-3">
      <div className={`text-2xl font-semibold tabular-nums ${STAT_TONE[tone] || STAT_TONE.slate}`}>{value}</div>
      <div className="mt-0.5 text-xs font-medium text-slate-500">{label}</div>
      {sub && <div className="mt-0.5 text-[11px] text-slate-400">{sub}</div>}
    </div>
  );
}

/**
 * ONE FAULT, end to end.
 *
 * The two questions this card exists to answer are given the most room: who found it, and where it was
 * fixed. The time it took is stated with its BASIS visible — "worked" is time actually spent on this
 * fault, "at the garage" is how long the car sat there for it. A fault that never recorded a per-fault
 * start signal shows only the second, labelled as such, rather than passing the car's shared workshop
 * time off as this fault's own.
 */
function FaultCard({ fault, t }) {
  const moved = fault.garages.length > 1;
  const worked = dur(fault.timing.work_seconds);
  const custody = dur(fault.timing.custody_seconds);

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <span className="font-semibold text-slate-900">{fault.name || fault.symptom || t('workflow.journey.fault.unnamed')}</span>
            {fault.quantity > 1 && <span className="text-xs text-slate-500">×{fault.quantity}</span>}
            <Badge tone={STATUS_TONE[fault.status] || 'slate'}>{t(`workflow.journey.faultStatus.${fault.status}`)}</Badge>
            {fault.reinspection_failures > 0 && (
              <Badge tone="red">{t('workflow.journey.fault.cameBack', { n: fault.reinspection_failures })}</Badge>
            )}
          </div>
          {/* The free-text the reporter typed, kept only when it says something the catalog name doesn't. */}
          {fault.symptom && fault.symptom !== fault.name && (
            <p className="mt-1 text-sm text-slate-600">{fault.symptom}</p>
          )}
        </div>
        <Badge tone={SOURCE_TONE[fault.source] || 'slate'}>
          {t(`workflow.journey.source.${fault.source || 'unknown'}`)}
        </Badge>
      </div>

      <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-4">
        <div>
          <dt className="text-xs text-slate-400">{t('workflow.journey.fault.foundBy')}</dt>
          <dd className="text-slate-800">{fault.found_by || '—'}</dd>
          {fault.found_at && <dd className="text-[11px] text-slate-400">{stamp(fault.found_at)}</dd>}
        </div>
        <div>
          <dt className="text-xs text-slate-400">{t('workflow.journey.fault.fixedAt')}</dt>
          <dd className="text-slate-800">{fault.fixed_at_garage || '—'}</dd>
          {fault.resolved_at && <dd className="text-[11px] text-slate-400">{stamp(fault.resolved_at)}</dd>}
        </div>
        <div>
          <dt className="text-xs text-slate-400">
            {worked ? t('workflow.journey.fault.worked') : t('workflow.journey.fault.atGarage')}
          </dt>
          <dd className="text-slate-800">{worked || custody || '—'}</dd>
          {/* Say plainly when the number is custody rather than hands-on time, instead of letting the
              bigger figure read as effort. */}
          {!worked && custody && <dd className="text-[11px] text-slate-400">{t('workflow.journey.fault.custodyOnly')}</dd>}
        </div>
        <div>
          <dt className="text-xs text-slate-400">{t('workflow.journey.fault.attempts')}</dt>
          <dd className="text-slate-800">
            {fault.timing.attempts || '—'}
            {fault.timing.labor_hours != null && (
              <span className="ml-1 text-[11px] text-slate-400">
                {t('workflow.journey.fault.laborHours', { n: fault.timing.labor_hours })}
              </span>
            )}
          </dd>
        </div>
      </dl>

      {/* Where it travelled. Only shown when it actually moved — a single-garage fault already said
          everything under "fixed at". */}
      {moved && (
        <div className="mt-3 rounded-lg bg-slate-50 px-3 py-2">
          <div className="text-xs font-medium text-slate-500">{t('workflow.journey.fault.travelled')}</div>
          <ol className="mt-1 flex flex-wrap items-center gap-1 text-sm text-slate-700">
            {fault.garages.map((g, i) => (
              <li key={i} className="flex items-center gap-1">
                {i > 0 && <span className="text-slate-300">→</span>}
                <span>{g.garage || t('workflow.journey.fault.unknownGarage')}</span>
                {g.outcome && <span className="text-[11px] text-slate-400">({t(`workflow.journey.outcome.${g.outcome}`)})</span>}
              </li>
            ))}
          </ol>
        </div>
      )}

      {fault.resolution_note && (
        <p className="mt-2 text-sm text-slate-600">
          <span className="text-xs text-slate-400">{t('workflow.journey.fault.note')}: </span>
          {fault.resolution_note}
        </p>
      )}
    </div>
  );
}

/** The stage spine — the car's checkpoints under this contract, with the wait before each. */
function Stages({ stages, t }) {
  if (!stages.length) return null;
  return (
    <ol className="space-y-1">
      {stages.map((s) => (
        <li key={s.key} className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 border-l-2 border-slate-200 py-1 pl-3">
          <span className="min-w-[10rem] font-medium text-slate-800">{t(`workflow.journey.stage.${s.key}`)}</span>
          <span className="text-sm text-slate-500">{stamp(s.at)}</span>
          {s.by && <span className="text-sm text-slate-600">· {s.by}</span>}
          {s.odometer != null && <span className="text-sm tabular-nums text-slate-500">· {num(s.odometer)} km</span>}
          {s.since_previous_seconds != null && (
            <span className="text-[11px] text-slate-400">({t('workflow.journey.stage.after', { d: dur(s.since_previous_seconds) })})</span>
          )}
        </li>
      ))}
    </ol>
  );
}

export default function VisitJourneyPanel({ contractId }) {
  const { t } = useI18n();
  const [showEvents, setShowEvents] = useState(false);

  const fetcher = useCallback(async () => {
    const { data } = await api.get(`/Contract/${contractId}/journey`);
    return data?.data ?? data;
  }, [contractId]);
  const { data, loading, error } = useFetch(fetcher, [contractId]);

  const visits = useMemo(() => data?.visits ?? [], [data]);
  const totals = data?.totals;

  if (loading) return <Card className="p-6"><Spinner /></Card>;
  // A journey that can't load must not take the contract page down with it — it is one panel among many.
  if (error) return null;
  // A rental contract has no visit. Saying nothing is the honest answer; an empty "0 faults" card would
  // imply a workshop visit happened and found nothing.
  if (!visits.length) return null;

  return (
    <Card className="p-6">
      <div className="mb-4">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-400">{t('workflow.journey.title')}</h3>
        <p className="mt-0.5 text-sm text-slate-500">{t('workflow.journey.subtitle')}</p>
      </div>

      {totals && (
        <div className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
          <Stat label={t('workflow.journey.totals.faults')} value={totals.faults} />
          <Stat
            label={t('workflow.journey.totals.foundByInspector')}
            value={totals.found_by_inspector}
            sub={t('workflow.journey.totals.beforeItLeft')}
            tone="violet"
          />
          <Stat
            label={t('workflow.journey.totals.foundByGarage')}
            value={totals.found_by_garage}
            sub={t('workflow.journey.totals.onTheLift')}
            tone="orange"
          />
          <Stat
            label={t('workflow.journey.totals.repaired')}
            value={totals.repaired}
            // Closed-without-a-repair is called out rather than folded into the gap, so "6 faults,
            // 4 repaired" doesn't read as two failures when two were simply ruled non-issues.
            sub={totals.closed_unrepaired > 0 ? t('workflow.journey.totals.closedUnrepaired', { n: totals.closed_unrepaired }) : null}
            tone="green"
          />
        </div>
      )}

      {visits.map((v) => (
        <div key={v.ticket.id} className="mb-6 last:mb-0">
          <div className="mb-3 flex flex-wrap items-center gap-2">
            <Link to={`/maintenance-workflow?ticket=${v.ticket.id}`} className="text-sm font-semibold text-indigo-600 hover:underline">
              {t('workflow.journey.ticket', { id: v.ticket.id })}
            </Link>
            {v.ticket.garage && <span className="text-sm text-slate-600">· {v.ticket.garage}</span>}
            {v.ticket.is_open && <Badge tone="amber">{t('workflow.journey.stillOpen')}</Badge>}
            {v.ticket.ticket_seconds != null && (
              <span className="text-sm text-slate-500">· {t('workflow.journey.tookTotal', { d: dur(v.ticket.ticket_seconds) })}</span>
            )}
          </div>

          <div className="space-y-3">
            {v.faults.map((f) => <FaultCard key={f.id} fault={f} t={t} />)}
            {!v.faults.length && <p className="text-sm text-slate-400">{t('workflow.journey.noFaults')}</p>}
          </div>

          {v.stages.length > 0 && (
            <div className="mt-5">
              <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{t('workflow.journey.stagesTitle')}</h4>
              <Stages stages={v.stages} t={t} />
            </div>
          )}

          {v.odometer.length > 0 && (
            <div className="mt-5">
              <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{t('workflow.journey.odometerTitle')}</h4>
              <div className="flex flex-wrap gap-2">
                {v.odometer.map((o) => (
                  <div key={o.key} className="rounded-lg border border-slate-200 px-3 py-1.5 text-sm">
                    <span className="text-xs text-slate-400">{t(`workflow.journey.odo.${o.key}`)} </span>
                    <span className="font-mono font-medium text-slate-800">{num(o.reading)}</span>
                    {o.delta != null && o.delta !== 0 && (
                      <span className="ml-1 text-[11px] text-slate-400">({o.delta > 0 ? '+' : ''}{num(o.delta)})</span>
                    )}
                  </div>
                ))}
              </div>
            </div>
          )}

          {v.events.total > 0 && (
            <div className="mt-5">
              <button
                type="button"
                onClick={() => setShowEvents((s) => !s)}
                className="text-xs font-semibold uppercase tracking-wide text-indigo-600 hover:underline"
              >
                {showEvents ? t('workflow.journey.hideEvents') : t('workflow.journey.showEvents', { n: v.events.total })}
              </button>
              {showEvents && (
                <ol className="mt-2 space-y-1">
                  {v.events.items.map((e) => (
                    <li key={e.id} className="flex flex-wrap items-baseline gap-x-2 text-sm">
                      <span className="text-xs tabular-nums text-slate-400">{stamp(e.at)}</span>
                      <span className="text-slate-700">{e.description}</span>
                      {e.by && <span className="text-xs text-slate-400">· {e.by}</span>}
                    </li>
                  ))}
                  {/* Never let a cap masquerade as the whole trail. */}
                  {v.events.truncated && (
                    <li className="text-xs text-slate-400">{t('workflow.journey.eventsTruncated', { n: v.events.items.length, total: v.events.total })}</li>
                  )}
                </ol>
              )}
            </div>
          )}
        </div>
      ))}
    </Card>
  );
}
