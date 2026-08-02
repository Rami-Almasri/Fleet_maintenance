import { Fragment, useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { Skeleton } from '../ui/Skeleton';
import { InfoTip } from '../ui/Tooltip';
import Icon from '../ui/Icon';
import { aed2, fmtDate, num } from '../../lib/format';

/**
 * "This car keeps breaking down" — one car's REPEAT-FAULT chains.
 *
 * Reads GET /Vehicle/{id}/repeat-faults (VehicleFaultRecurrenceService). Each fault the car came back
 * for is drawn as a TIMELINE: one marker per repair episode, and on the rail between them the gap the
 * car actually held before it broke again.
 *
 *   ●──── held 25d ────●──── held 50d ────●
 *   FIRST TIME          RETURN 1           LATEST
 *   During rental       #4473              #4728 ×2
 *
 * Every marker is a real record: a Type-U maintenance contract the car went out on (clickable), or a
 * workshop visit with no contract open — i.e. it broke while it was out with a customer. Nothing is
 * predicted; the panel states its own source at the bottom ([[traceability-visibility-requirement]]).
 */

/** Escalating tone by how many separate times the fault came back — 2 is a worry, 4+ is a pattern. */
const toneFor = (episodes) => {
  if (episodes >= 4) {
    return { badge: 'bg-red-600', text: 'text-red-700', stripe: 'bg-red-500', soft: 'bg-red-50', ring: 'ring-red-200', dot: 'bg-red-500' };
  }
  if (episodes === 3) {
    return { badge: 'bg-orange-500', text: 'text-orange-700', stripe: 'bg-orange-400', soft: 'bg-orange-50', ring: 'ring-orange-200', dot: 'bg-orange-500' };
  }
  return { badge: 'bg-amber-500', text: 'text-amber-700', stripe: 'bg-amber-400', soft: 'bg-amber-50', ring: 'ring-amber-200', dot: 'bg-amber-500' };
};

/**
 * The fault-family grade from the Maintenance Reasons catalog. Only `critical` is worth a chip — the
 * catalog's other grades (routine / minor / special) say nothing the recurrence count doesn't already.
 */
const CRITICAL_TIP =
  'Graded Critical in the Maintenance Reasons catalog — the systems that make a car unsafe or undrivable: '
  + 'engine, braking, steering, suspension, transmission, cooling/overheating, electrical, ignition, fuel, '
  + 'exhaust, battery, airbags, ABS and seatbelts. The grade is the fault family’s, not this car’s.';

/** Why the chain broke here — said the way an operator would say it. */
const BOUNDARY_TEXT = {
  gap: 'Came back after the shop had closed it off.',
  contract_change: 'Went out again on a new maintenance contract.',
  contract_opened: 'A maintenance contract was opened for it.',
  contract_closed: 'The maintenance contract had already closed.',
};

const plural = (n, word) => `${num(n)} ${word}${Number(n) === 1 ? '' : 's'}`;

/** One figure in the header strip. */
function Stat({ value, label, tip, tone = 'text-slate-900' }) {
  return (
    <div className="min-w-0 px-5 py-3.5">
      <p className={`truncate text-[26px] font-extrabold leading-none tracking-tight tabular-nums ${tone}`}>{value}</p>
      <p className="mt-1.5 flex items-center gap-1 truncate text-[10px] font-bold uppercase tracking-[0.08em] text-slate-400">
        {label}
        {tip && <InfoTip content={tip} />}
      </p>
    </div>
  );
}

/**
 * One marker on the timeline — a single repair episode. Contract episodes deep-link to the contract;
 * rental episodes are called out, because a fault that returns while a customer has the car is the
 * expensive kind.
 */
function Episode({ node, index, total, tone, showFinancials }) {
  const isRental = node.kind !== 'contract';
  const isFirst = index === 0;
  const isLast = index === total - 1;

  const caption = isFirst ? 'First time' : isLast ? 'Latest' : `Return ${index}`;
  const meta = [
    node.visits > 1 ? `${node.visits} visits` : null,
    node.shop_days ? `${node.shop_days}d in shop` : null,
    showFinancials && node.cost > 0 ? aed2(node.cost) : null,
  ].filter(Boolean);

  const title = [
    `${fmtDate(node.first)}${node.last !== node.first ? ` → ${fmtDate(node.last)}` : ''}`,
    node.garages?.length ? node.garages.join(' · ') : null,
    isRental ? 'No maintenance contract was open — the car was out with a customer.' : null,
  ].filter(Boolean).join('\n');

  const card = (
    <>
      <span className="flex items-center gap-1.5 text-[13px] font-bold leading-tight">
        {isRental
          ? <Icon.Car className="h-3.5 w-3.5 shrink-0 text-slate-400" />
          : <Icon.Invoice className="h-3.5 w-3.5 shrink-0 text-indigo-400" />}
        <span className="truncate">{node.contract_no ? `#${node.contract_no}` : 'During rental'}</span>
        {node.visits > 1 && (
          <span className="ms-auto shrink-0 rounded bg-slate-900/5 px-1 text-[10px] font-black tabular-nums text-slate-500">
            ×{node.visits}
          </span>
        )}
      </span>
      <span className="mt-1 block text-[11px] font-semibold text-slate-500">{fmtDate(node.first)}</span>
      {meta.length > 0 && (
        <span className="mt-0.5 block truncate text-[11px] text-slate-400">{meta.join(' · ')}</span>
      )}
    </>
  );

  const shell = 'block w-full rounded-xl border-l-[3px] bg-white px-2.5 py-2 text-start shadow-sm ring-1 transition '
    + (isRental
      ? 'border-l-slate-300 text-slate-700 ring-slate-200/70'
      : 'border-l-indigo-500 text-indigo-900 ring-slate-200/70 hover:-translate-y-px hover:shadow-md hover:ring-indigo-300');

  return (
    <div className="w-[158px] shrink-0">
      {/* Rail segment + marker. The half-lines are transparent at the ends so the rail
          starts and stops exactly at the first and last episode. */}
      <div className="flex h-4 items-center">
        <span className={`h-[3px] flex-1 rounded-full ${isFirst ? 'bg-transparent' : 'bg-slate-200'}`} />
        <span
          className={`h-3 w-3 shrink-0 rounded-full ring-[3px] ring-white ${isLast ? tone.dot : 'bg-slate-300'}`}
          title={caption}
        />
        <span className={`h-[3px] flex-1 rounded-full ${isLast ? 'bg-transparent' : 'bg-slate-200'}`} />
      </div>

      <p className={`mt-1.5 text-center text-[9px] font-black uppercase tracking-[0.1em] ${isLast ? tone.text : 'text-slate-300'}`}>
        {caption}
      </p>

      <div className="mt-1.5 px-1" title={title}>
        {node.contract_id
          ? <Link to={`/contracts/${node.contract_id}`} className={shell}>{card}</Link>
          : <div className={shell}>{card}</div>}

        {/* Which system recorded this step. Ticket steps deep-link to the ticket. */}
        {node.ticket_ids?.length > 0 && (
          <div className="mt-1 flex flex-wrap items-center gap-1">
            {(node.ticket_ids || []).slice(0, 2).map((id) => (
              <Link
                key={id}
                to={`/maintenance-workflow/${id}`}
                title="Open the maintenance ticket"
                className="inline-flex items-center gap-0.5 rounded bg-violet-50 px-1.5 py-px text-[10px] font-bold text-violet-700 ring-1 ring-inset ring-violet-200 transition hover:bg-violet-100"
              >
                <Icon.Wrench className="h-2.5 w-2.5" />T-{id}
              </Link>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

/** The rail between two episodes: how long the car actually held after that repair. */
function Held({ node }) {
  const days = node.gap_days;
  // A return inside a month is the damning case — the repair barely held.
  const short = days != null && days <= 30;

  return (
    <div className="w-[86px] shrink-0" title={BOUNDARY_TEXT[node.boundary] || ''}>
      <div className="flex h-4 items-center">
        <span className="h-[3px] flex-1 rounded-full bg-slate-200" />
        <span
          className={`shrink-0 rounded-full px-1.5 py-px text-[10px] font-black tabular-nums ring-1 ring-inset ${
            short ? 'bg-red-50 text-red-600 ring-red-200' : 'bg-white text-slate-500 ring-slate-200'
          }`}
        >
          {days != null ? `${num(days)}d` : '—'}
        </span>
        <span className="h-[3px] flex-1 rounded-full bg-slate-200" />
      </div>
      <p className={`mt-1.5 text-center text-[9px] font-black uppercase tracking-[0.1em] ${short ? 'text-red-400' : 'text-slate-300'}`}>
        held
      </p>
    </div>
  );
}

/** One repeat fault: the headline, its timeline, the measured facts, and the slow-garage caveat. */
function Fault({ fault, showFinancials }) {
  const tone = toneFor(fault.episodes);

  const facts = [
    fault.shortest_gap_days != null
      ? { label: 'Shortest hold', value: plural(fault.shortest_gap_days, 'day'), alert: fault.shortest_gap_days <= 30 }
      : null,
    fault.shop_days > 0 ? { label: 'Total shop time', value: plural(fault.shop_days, 'day') } : null,
    showFinancials && fault.total_cost > 0 ? { label: 'Spent', value: aed2(fault.total_cost) } : null,
    fault.garages?.length
      ? { label: fault.garages.length === 1 ? 'Garage' : 'Garages', value: fault.garages.length === 1 ? fault.garages[0] : num(fault.garages.length), title: fault.garages.join(' · ') }
      : null,
  ].filter(Boolean);

  return (
    <div className="relative py-4 ps-5 pe-5">
      {/* Severity stripe — the whole row is graded by how many times it came back. */}
      <span className={`absolute inset-y-4 start-0 w-1 rounded-e-full ${tone.stripe}`} />

      <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1.5">
        <span className={`inline-flex h-7 items-center rounded-lg px-2.5 text-[13px] font-black tabular-nums text-white shadow-sm ${tone.badge}`}>
          {fault.episodes}×
        </span>
        <h4 className="text-[15px] font-bold capitalize leading-none tracking-tight text-slate-900">{fault.issue}</h4>

        {fault.level === 'critical' && (
          <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-black uppercase tracking-wide text-red-700 ring-1 ring-inset ring-red-200">
            Critical system
            <InfoTip content={CRITICAL_TIP} />
          </span>
        )}
        {fault.hint && <span className="text-xs font-medium text-slate-400">{fault.hint}</span>}

        <span className="ms-auto whitespace-nowrap text-xs text-slate-400">
          Last <span className="font-semibold text-slate-600">{fmtDate(fault.last_seen)}</span>
          <span className="text-slate-300"> · </span>
          {fault.days_since_last === 0 ? 'today' : `${plural(fault.days_since_last, 'day')} ago`}
        </span>
      </div>

      {/* The exact wordings that were folded into this one chain — so grouping "Tires", "Flat Tire"
          and a ticket's "Puncture / slow leak" together is visible, never a silent merge. */}
      {fault.variants?.length > 0 && (
        <p className="mt-1.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-[11px] text-slate-400">
          <span className="font-semibold text-slate-400">Recorded as</span>
          {fault.variants.map((v) => (
            <span key={v} className="rounded bg-slate-100 px-1.5 py-px font-medium text-slate-500">{v}</span>
          ))}
        </p>
      )}

      {/* The timeline. Scrolls inside itself so a long history never widens the page. */}
      <div className="mt-3 -mx-1 overflow-x-auto px-1 pb-1">
        <div className="flex min-w-min items-start">
          {fault.chain.map((node, i) => (
            <Fragment key={`${node.first}-${i}`}>
              {i > 0 && <Held node={node} />}
              <Episode node={node} index={i} total={fault.chain.length} tone={tone} showFinancials={showFinancials} />
            </Fragment>
          ))}
        </div>
      </div>

      {facts.length > 0 && (
        <div className="mt-3 flex flex-wrap items-center gap-x-6 gap-y-2">
          {facts.map((f) => (
            <div key={f.label} className="min-w-0" title={f.title}>
              <p className="text-[9px] font-black uppercase tracking-[0.1em] text-slate-300">{f.label}</p>
              <p className={`mt-0.5 truncate text-[13px] font-bold tabular-nums ${f.alert ? 'text-red-600' : 'text-slate-700'}`}>
                {f.value}
              </p>
            </div>
          ))}
        </div>
      )}

      {fault.stalling && (
        <p className={`mt-3 flex items-start gap-2 rounded-xl px-3 py-2 text-xs leading-relaxed text-slate-600 ring-1 ring-inset ${tone.soft} ${tone.ring}`}>
          <Icon.Info className="mt-px h-4 w-4 shrink-0 text-slate-400" />
          <span>
            <span className="font-bold text-slate-800">{fault.stalling.garage || 'One garage'}</span> had this car{' '}
            {fault.stalling.visits}× for this in {plural(fault.stalling.span_days, 'day')} — that stretch reads as a slow
            workshop, not the car failing again.
          </span>
        </p>
      )}
    </div>
  );
}

/** The card shell, so the loading / error / all-clear states sit in the same frame as the real thing. */
// id="repeat-faults" is a deep-link anchor: the Recurring Faults analytics ("Cars that keep coming
// back") links straight here with ?focus=repeat-faults. scroll-mt clears the sticky app header + tabs.
function Shell({ children, header }) {
  return (
    <div id="repeat-faults" className="scroll-mt-32 overflow-hidden rounded-2xl border border-slate-200/60 bg-white shadow-soft">
      {header}
      {children}
    </div>
  );
}

const QUIET_HEADER = (
  <div className="flex items-center gap-3 border-b border-slate-100 px-5 py-4">
    <div className="min-w-0">
      <h3 className="truncate text-base font-semibold text-slate-900">Repeat faults</h3>
      <p className="mt-0.5 truncate text-xs text-slate-400">Same problem, back again after it was repaired</p>
    </div>
  </div>
);

export default function VehicleRepeatFaults({ vehicleId, showFinancials = false }) {
  const fetcher = useCallback(async () => (await api.get(`/Vehicle/${vehicleId}/repeat-faults`)).data.data, [vehicleId]);
  const { data, loading, error } = useFetch(fetcher, [vehicleId]);

  const [expanded, setExpanded] = useState(false);
  const faults = useMemo(() => data?.faults || [], [data]);
  const summary = data?.summary || {};

  // Long histories collapse to the worst three — the rest is one click away.
  const VISIBLE = 3;
  const shown = expanded ? faults : faults.slice(0, VISIBLE);
  const hidden = faults.length - VISIBLE;

  if (loading) {
    return <Shell header={QUIET_HEADER}><div className="space-y-3 p-5"><Skeleton className="h-6 w-56" /><Skeleton className="h-20 w-full" /></div></Shell>;
  }

  if (error) {
    return <Shell header={QUIET_HEADER}><p className="px-5 py-4 text-sm text-red-600">{error}</p></Shell>;
  }

  // The good-news case deserves to be stated plainly — silence would read as "not computed".
  if (faults.length === 0) {
    return (
      <Shell header={QUIET_HEADER}>
        <div className="flex items-center gap-3 px-5 py-5">
          <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 ring-1 ring-inset ring-emerald-100">
            <Icon.Check className="h-5 w-5 text-emerald-600" />
          </span>
          <p className="text-sm leading-relaxed text-slate-600">
            <span className="font-bold text-slate-900">Nothing came back.</span> No fault on this car has returned after
            it was repaired — every workshop visit was for something new.
          </p>
        </div>
      </Shell>
    );
  }

  return (
    <Shell
      header={
        /* A deliberately loud header: this panel is the car's rap sheet, not another stat card. */
        <div className="flex flex-wrap items-center gap-x-4 gap-y-2 bg-gradient-to-r from-slate-900 via-slate-900 to-red-900/90 px-5 py-4">
          <span className="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white/10 ring-1 ring-inset ring-white/15">
            <Icon.Refresh className="h-[18px] w-[18px] text-red-300" />
            <span className="absolute -end-0.5 -top-0.5 h-2.5 w-2.5 rounded-full bg-red-500 ring-2 ring-slate-900" />
          </span>
          <div className="min-w-0">
            <h3 className="truncate text-base font-bold tracking-tight text-white">Keeps breaking down</h3>
            <p className="mt-0.5 truncate text-xs text-slate-400">
              The same faults return after each fix — every step below is a real record
            </p>
          </div>
          <span className="ms-auto inline-flex shrink-0 items-baseline gap-1.5 rounded-full bg-white/10 px-3 py-1.5 ring-1 ring-inset ring-white/15">
            <span className="text-lg font-black leading-none tabular-nums text-white">{num(summary.returns)}</span>
            <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-300">
              {Number(summary.returns) === 1 ? 'return' : 'returns'}
            </span>
          </span>
        </div>
      }
    >
      {/* What the whole pattern has cost this car. */}
      <div className="grid grid-cols-2 divide-x divide-slate-100 border-b border-slate-100 bg-slate-50/70 sm:grid-cols-4">
        <Stat
          value={num(summary.repeat_faults)}
          label="Repeat faults"
          tone="text-red-600"
          tip="Distinct faults this car has come back for after they were repaired."
        />
        <Stat
          value={num(summary.returns)}
          label="Times it came back"
          tip="Every repair episode after the first one, added up across all repeat faults."
        />
        <Stat
          value={num(summary.shop_days)}
          label="Days in the shop"
          tip="Total days this car spent in a garage for the repeat faults below."
        />
        {showFinancials ? (
          <Stat value={aed2(summary.total_cost)} label="Spent on them" tip="Recorded workshop cost across every visit for these faults." />
        ) : (
          <Stat
            value={num(summary.stalling || 0)}
            label="Slow-garage cases"
            tip="Repeat faults where one garage held the car 3+ times inside 10 days — a workshop problem, not the car."
          />
        )}
      </div>

      <div className="divide-y divide-slate-100">
        {shown.map((fault, i) => (
          <Fault key={`${fault.key}-${i}`} fault={fault} showFinancials={showFinancials} />
        ))}
      </div>

      {hidden > 0 && (
        <button
          type="button"
          onClick={() => setExpanded((v) => !v)}
          aria-expanded={expanded}
          className="flex w-full items-center justify-center gap-1.5 border-t border-slate-100 px-5 py-3 text-xs font-bold text-indigo-600 transition hover:bg-indigo-50/60"
        >
          {expanded ? 'Show fewer' : `Show ${hidden} more repeat fault${hidden === 1 ? '' : 's'}`}
          <Icon.ChevronDown className={`h-4 w-4 transition-transform ${expanded ? 'rotate-180' : ''}`} />
        </button>
      )}

      {/* Standing rule: every surface says where its numbers came from. */}
      <div className="border-t border-slate-100 bg-slate-50/70 px-5 py-3">
        <div className="mb-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] font-semibold text-slate-500">
          <span className="inline-flex items-center gap-1.5">
            <span className="h-2 w-2 rounded-full bg-slate-400" /> Workshop log (N-Maintenance sheet)
          </span>
          <span className="inline-flex items-center gap-1.5">
            <span className="h-2 w-2 rounded-full bg-violet-500" /> Maintenance tickets
            <span className="rounded bg-violet-50 px-1 text-[10px] font-bold text-violet-700 ring-1 ring-inset ring-violet-200">T-</span>
          </span>
        </div>
        <p className="text-[11px] leading-relaxed text-slate-400">
          <span className="font-bold text-slate-500">Where this comes from:</span> {data?.origin} Events inside one
          maintenance contract count as a single repair, so garage-to-garage shuffling never inflates the count. Routine
          planned service, cosmetic work and faults the workshop ruled incorrect are excluded.
        </p>
      </div>
    </Shell>
  );
}
