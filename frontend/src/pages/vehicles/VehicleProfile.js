import { Fragment, useCallback, useEffect, useState } from 'react';
import { useParams, Link, useNavigate, useSearchParams } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { useToast } from '../../components/ui/Toast';
import Badge, { ContractTypeBadge, ContractStateBadge } from '../../components/ui/Badge';
import Button from '../../components/ui/Button';
import Modal from '../../components/ui/Modal';
import { Input } from '../../components/ui/Field';
import SearchSelect from '../../components/ui/SearchSelect';
import { Card } from '../../components/ui/Misc';
import DataTable, { SectionCard } from '../../components/ui/Table';
import { Skeleton, MetricGridSkeleton } from '../../components/ui/Skeleton';
import { InfoTip } from '../../components/ui/Tooltip';
import Icon from '../../components/ui/Icon';
import Tabs from '../../components/ui/Tabs';
import VehicleWorkflowPanel from '../../components/vehicles/VehicleWorkflowPanel';
import VehicleInvestigationTimeline from '../../components/vehicles/VehicleInvestigationTimeline';
import { aed2, fmtDate, fmtClock, num } from '../../lib/format';
import CompositionDonut from '../../components/ui/CompositionDonut';
import { faultTagSegments } from '../../lib/faultCategories';
import { openVehicleProfileReport } from '../../lib/vehicleProfileReport';
import { SHOW_FINANCIALS } from '../../config/features';
import ReadinessChecklist from './ReadinessChecklist';
import PlateHistory from './PlateHistory';
import FinancialsHero from './FinancialsHero';
import VehicleOverviewDashboard from './VehicleOverviewDashboard';
import DualState, { PausedRibbon } from '../../components/ops/DualState';
import { useCountUp } from '../../components/ui/Gauge';
import './vehicle-hero.css';

// Tone per maintenance-log event status (from the sheet's OUT/IN column).
const EVENT_TONE = { OUT: 'amber', IN: 'green', 'Follow up': 'blue', 'Select garage': 'violet', 'In garage': 'red', Change: 'indigo', Delay: 'red', Test: 'gray', 'Under Test': 'gray', Delivery: 'green', 'In Our Park': 'green', 'Final QA': 'violet' };

// Solid/soft marker colors for the maintenance timeline, keyed by the EVENT_TONE value.
// The full palette (mirrors the Badge tones) so every workflow stage can carry its OWN colour and a
// journey reads as a sequence of distinct hues rather than one green/violet/amber blob.
const EVENT_STYLE = {
  amber:  { dot: 'bg-amber-500',   soft: 'bg-amber-100',   text: 'text-amber-600',   ring: 'ring-amber-200' },
  green:  { dot: 'bg-emerald-500', soft: 'bg-emerald-100', text: 'text-emerald-600', ring: 'ring-emerald-200' },
  blue:   { dot: 'bg-blue-500',    soft: 'bg-blue-100',    text: 'text-blue-600',    ring: 'ring-blue-200' },
  indigo: { dot: 'bg-indigo-500',  soft: 'bg-indigo-100',  text: 'text-indigo-600',  ring: 'ring-indigo-200' },
  red:    { dot: 'bg-red-500',     soft: 'bg-red-100',     text: 'text-red-600',     ring: 'ring-red-200' },
  violet: { dot: 'bg-violet-500',  soft: 'bg-violet-100',  text: 'text-violet-600',  ring: 'ring-violet-200' },
  cyan:   { dot: 'bg-cyan-500',    soft: 'bg-cyan-100',    text: 'text-cyan-600',    ring: 'ring-cyan-200' },
  orange: { dot: 'bg-orange-500',  soft: 'bg-orange-100',  text: 'text-orange-600',  ring: 'ring-orange-200' },
  yellow: { dot: 'bg-yellow-500',  soft: 'bg-yellow-100',  text: 'text-yellow-600',  ring: 'ring-yellow-200' },
  slate:  { dot: 'bg-slate-400',   soft: 'bg-slate-100',   text: 'text-slate-500',   ring: 'ring-slate-200' },
  gray:   { dot: 'bg-slate-400',   soft: 'bg-slate-100',   text: 'text-slate-500',   ring: 'ring-slate-200' },
};

// A small glyph per workshop event type, so the timeline reads at a glance.
const EVENT_ICON = {
  OUT: 'M17 8l4 4m0 0l-4 4m4-4H3',                                  // arrow-out
  IN: 'M11 16l-4-4m0 0l4-4m-4 4h14',                                // arrow-in
  'Follow up': 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0zM12 7v5l3 3',  // clock
  'Select garage': 'M3 9l9-6 9 6v11a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z', // garage
  'In garage': 'M3 9l9-6 9 6v11a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z', // garage (under repair)
  Change: 'M4 4v5h.582m15.356 2A8.001 8.001 0 0 0 4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 0 1-15.357-2m15.357 2H15', // refresh
  Delay: 'M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z', // alert
  Test: 'M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 7h10v10H7z', // chip
  'Under Test': 'M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 7h10v10H7z',
  Delivery: 'M9 17a2 2 0 1 1-4 0 2 2 0 0 1 4 0zm10 0a2 2 0 1 1-4 0 2 2 0 0 1 4 0zM13 16V6a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v10h10zm0-6h5l3 3v3h-3', // truck
  'In Our Park': 'M4 21V5a1 1 0 0 1 1-1h9l-1.5 3L14 10H5m0 11v-6', // flag (back at base)
  'Final QA': 'M9 12l2 2 4-4m1.5-5.5A9 9 0 1 1 5 5', // clipboard/check (QA sign-off)
};
const DEFAULT_EVENT_ICON = 'M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828z'; // wrench/pencil

// Map each maintenance-workflow lifecycle event onto the LEGACY workshop stage vocabulary
// (OUT / IN / Follow up / Test) the team already reads on the sheet rows, so the manual workflow
// trail looks identical to the old log — no new "Inspector / Garage / Dispatched…" wording.
//   Test     = the inspection/diagnosis phase (request → test drive → report → cleared)
//   OUT      = car physically out at the garage (dispatch / under repair / sent back)
//   Follow up = an intermediate update (ready for re-inspection, a driver follow-up note)
//   IN       = car came back (re-inspected & closed)
const WF_STAGE = {
  inspection_requested: 'Test',
  diagnostic_started:   'Test',
  report_filed:         'Test',
  diagnostic_cleared:   'Test',
  garage_assigned:      'Select garage',
  dispatched:           'OUT',
  under_repair:         'In garage',
  reopened:             'OUT',
  ready:                'Follow up',
  follow_up:            'Follow up',
  closed:               'IN',
};
const wfStage = (eventType) => WF_STAGE[eventType] || 'Follow up';

// The Maintenance-Workflow board stage names, keyed by the workflow_status stamped on each log event.
// The team reads these exact titles on the /maintenance-workflow lanes, so the timeline badge names the
// stage the car was in — not a generic "Follow up". `tone`/`icon` reuse the palette + glyphs above.
// When a log event carries no workflow_status (legacy sheet rows, driver follow-up notes, readiness /
// condition events) we fall back to the event_type vocabulary via wfStage().
// Each stage gets its OWN colour, laid out so a car's journey reads as a progression of distinct hues:
// intake (violet/cyan) → dispatch prep (amber/yellow/orange) → on the road & in the shop (blue/red/indigo)
// → return leg (cyan → violet → green, the trio that used to be all-green) → QA loop (amber/red).
// Failures stay red; "all done" stays green. Tones reused across the map are never adjacent in a journey.
const WF_STATUS_META = {
  inspection_requested:   { label: 'Needs Test Drive',   tone: 'violet', icon: 'Test' },
  inspection_diagnostic:  { label: 'Being Inspected',    tone: 'cyan',   icon: 'Test' },
  diagnostic_cleared:     { label: 'Cleared — No Work',  tone: 'slate',  icon: 'IN' },
  inspection_pending:     { label: 'Needs Dispatch',     tone: 'amber',  icon: 'Select garage' },
  on_site_pending:        { label: 'On-Site Service',    tone: 'yellow', icon: 'Follow up' },
  awaiting_dispatch:      { label: 'Awaiting Pickup',    tone: 'orange', icon: 'OUT' },
  in_transit:             { label: 'En Route to Garage', tone: 'blue',   icon: 'OUT' },
  under_repair:           { label: 'In Workshop',        tone: 'red',    icon: 'In garage' },
  repair_review:          { label: 'Video Review',       tone: 'indigo', icon: 'Follow up' },
  ready_for_pickup:       { label: 'Ready for Pickup',   tone: 'cyan',   icon: 'Delivery' },
  in_our_park:            { label: 'In Our Park',        tone: 'violet', icon: 'In Our Park' },
  ready_for_reinspection: { label: 'Final QA',           tone: 'amber',  icon: 'Final QA' },
  reinspection_failed:    { label: 'Came Back Broken',   tone: 'red',    icon: 'Delay' },
  awaiting_invoice:       { label: 'Awaiting Invoice',   tone: 'orange', icon: 'Follow up' },
  closed:                 { label: 'Back in Service',    tone: 'green',  icon: 'IN' },
  complaint_triage:       { label: 'Complaint Triage',   tone: 'orange', icon: 'Test' },
  complaint_resolved:     { label: 'Complaint Resolved', tone: 'green',  icon: 'IN' },
};

// A workflow / follow-up entry in the unified timeline. Rendered with the SAME legacy stage
// vocabulary + colours as the sheet workshop rows (OUT / IN / Follow up / Test), so the manual
// workflow trail reads identically to the old log — no "Inspector / Garage / Dispatched…" wording.
function WorkflowLogItem({ m }) {
  // Prefer the exact Maintenance-Workflow board stage name (from workflow_status); fall back to the
  // legacy OUT/IN/Test vocabulary for events with no workflow_status (sheet rows, follow-up notes).
  const wf = m.workflow_status ? WF_STATUS_META[m.workflow_status] : null;
  const legacyStage = wfStage(m.event_type);
  const stage = wf?.label || legacyStage;
  const tone = wf?.tone || EVENT_TONE[legacyStage] || 'gray';
  const st = EVENT_STYLE[tone] || EVENT_STYLE.gray;
  const icon = EVENT_ICON[wf?.icon || legacyStage] || DEFAULT_EVENT_ICON;

  // Expandable sections — collapsed by default so a 6-fault ticket stays a one-line card, not a
  // paragraph. Each only renders its toggle when it actually has something to show.
  const [showFaults, setShowFaults] = useState(false);
  const [showPhotos, setShowPhotos] = useState(false);
  const [showNotes, setShowNotes] = useState(false);
  const faults = Array.isArray(m.faults) ? m.faults.filter(Boolean) : [];
  const photos = m.media || [];
  const notes = String(m.notes || '').trim();

  return (
    <li className="relative flex gap-4">
      {/* timeline marker — coloured by the legacy stage, exactly like the sheet rows */}
      <span className={`relative z-10 mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full ring-4 ring-white ${st.soft} ${st.text}`}>
        <svg className="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d={icon} /></svg>
      </span>

      <div className="min-w-0 flex-1 rounded-2xl border border-slate-200/60 bg-white p-4 shadow-soft">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <Badge tone={tone}>{stage}</Badge>
          <span className="text-xs font-medium text-slate-400">
            {m.date ? fmtDate(m.date) : 'No date'}
            {m.ts && <span className="text-slate-400"> · {fmtClock(m.ts)}</span>}
          </span>
        </div>

        {/* the note: a single fixed-length line — faults/odometer/actor are chips below, never inlined here */}
        {m.description && <p className="mt-2 text-sm leading-relaxed text-slate-600">{m.description}</p>}

        {/* Small info chips: garage, odometer, cost, actor, linked contract */}
        <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
          {m.garage && (
            <span className="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2 py-0.5 font-medium text-slate-600">
              🏭 {m.garage}
            </span>
          )}
          {m.odometer != null && (
            <span className="inline-flex items-center gap-1.5 font-medium text-slate-600">
              <Icon.Gauge className="h-3.5 w-3.5 text-slate-400" /> {num(m.odometer)} km
            </span>
          )}
          {SHOW_FINANCIALS && m.cost != null && Number(m.cost) > 0 && <span className="font-semibold text-slate-700">{aed2(m.cost)}</span>}
          {m.actor && <span className="text-slate-400">👤 {m.actor}</span>}
          {m.contract_id && (
            <Link to={`/contracts/${m.contract_id}`} className="font-medium text-indigo-600 hover:text-indigo-700">#{m.contract_no || m.contract_id}</Link>
          )}
        </div>

        {/* Actions — expand-in-place instead of spelling everything into the description */}
        {(faults.length > 0 || photos.length > 0 || notes || m.ticket_id) && (
          <div className="mt-3 flex flex-wrap gap-2 border-t border-slate-100 pt-3">
            {notes && (
              <button
                type="button"
                onClick={() => setShowNotes((v) => !v)}
                className="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-inset ring-slate-300 hover:bg-slate-200"
              >
                {showNotes ? 'Hide Workshop Notes' : 'Show Workshop Notes'}
              </button>
            )}
            {m.ticket_id && (
              <Link
                to={`/maintenance-workflow/${m.ticket_id}`}
                className="inline-flex items-center gap-1.5 rounded-lg bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20 hover:bg-amber-100"
              >
                <Icon.Wrench className="h-3.5 w-3.5" /> Show Fault
              </Link>
            )}
            {faults.length > 0 && (
              <button
                type="button"
                onClick={() => setShowFaults((v) => !v)}
                className="inline-flex items-center gap-1.5 rounded-lg bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-600/20 hover:bg-red-100"
              >
                {showFaults ? 'Hide Faults' : 'Show Faults'} ({faults.length})
              </button>
            )}
            {photos.length > 0 && (
              <button
                type="button"
                onClick={() => setShowPhotos((v) => !v)}
                className="inline-flex items-center gap-1.5 rounded-lg bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 ring-1 ring-inset ring-indigo-600/20 hover:bg-indigo-100"
              >
                {showPhotos ? 'Hide Photos' : 'Show Photos'} ({photos.length})
              </button>
            )}
          </div>
        )}

        {showNotes && notes && (
          <div className="mt-2 rounded-xl bg-slate-50/70 p-3 ring-1 ring-inset ring-slate-100">
            <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-400">Workshop Notes</p>
            <NotesList text={notes} />
          </div>
        )}

        {showFaults && faults.length > 0 && (
          <ul className="mt-2 space-y-1 rounded-xl bg-red-50/60 px-3 py-2 text-sm text-red-800">
            {faults.map((f, i) => <li key={i}>• {f}</li>)}
          </ul>
        )}

        {showPhotos && photos.length > 0 && (
          <div className="mt-2 flex flex-wrap gap-2">
            {photos.map((med, i) => (
              <a
                key={med.id}
                href={med.url}
                target="_blank"
                rel="noreferrer"
                className="inline-flex items-center gap-1.5 rounded-lg bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-inset ring-slate-200 hover:bg-slate-100"
                title={med.name || undefined}
              >
                {med.kind === 'image' ? 'Photo' : 'Video'} {i + 1}
              </a>
            ))}
          </div>
        )}
      </div>
    </li>
  );
}

// Humanise a stage duration (seconds) into the two most significant units: "2d 3h", "4h 12m",
// "35m", "48s". A running/open stage passes null and renders as a live ticking-style "so far" label
// upstream, so here we only format finished spans.
function fmtDuration(seconds) {
  if (seconds == null) return null;
  const s = Math.max(0, Math.round(seconds));
  if (s < 60) return `${s}s`;
  const m = Math.floor(s / 60);
  if (m < 60) return `${m}m`;
  const h = Math.floor(m / 60);
  const remM = m % 60;
  if (h < 24) return remM ? `${h}h ${remM}m` : `${h}h`;
  const d = Math.floor(h / 24);
  const remH = h % 24;
  return remH ? `${d}d ${remH}h` : `${d}d`;
}

// Resolve a stage's display label + tone from its workflow_status, falling back to the legacy
// OUT/IN/Test vocabulary for the handful of rows that carry only an event_type.
function stageMeta(stg) {
  const wf = stg.workflow_status ? WF_STATUS_META[stg.workflow_status] : null;
  const legacyStage = wfStage(stg.event_type);
  const label = wf?.label || legacyStage;
  const tone = wf?.tone || EVENT_TONE[legacyStage] || 'gray';
  return { label, tone, style: EVENT_STYLE[tone] || EVENT_STYLE.gray };
}

// Workflow states where the car is physically AT the workshop — the "in maintenance" window used to
// split a ticket's real shop time out of its full open-to-close lifespan. Mirrors the backend's
// Maintenance::WF_AT_GARAGE (under_repair · repair_review · ready_for_pickup), minus the terminal
// `closed` (a finished ticket contributes no ongoing shop time).
const SHOP_STATES = new Set(['under_repair', 'repair_review', 'ready_for_pickup']);

// One car's Workflow Journeys — a bar-meter PER maintenance ticket showing every stage the car
// passed through and how long it sat in each. Reads the profile's `workflow_journeys` payload
// (reshaped from the same VehicleLogEvent trail the Timeline tab shows as a flat feed). The point is
// "where did the time go" — a proportional stacked bar makes a stuck stage jump out at a glance.
function WorkflowJourneys({ journeys }) {
  if (!journeys.length) {
    return (
      <Card className="p-10 text-center">
        <p className="text-sm text-slate-500">No maintenance workflow tickets have been raised on this car yet.</p>
        <p className="mt-1 text-xs text-slate-400">Once a ticket moves through the board, its stage-by-stage journey shows up here.</p>
      </Card>
    );
  }
  return (
    <div className="space-y-5">
      {journeys.map((j) => {
        const total = j.total_seconds || 0;
        // "Days in maintenance" = time the car was actually at the workshop (under repair, in the
        // post-repair video review, or done-but-waiting-for-pickup) — a subset of the ticket's total
        // lifespan, which also counts intake, dispatch, transit, QA and idle waiting. Splitting the two
        // shows how much of a long ticket was real shop time vs. time the car sat waiting.
        const shopSeconds = (j.stages || []).reduce(
          (a, s) => a + (SHOP_STATES.has(s.workflow_status) ? (s.seconds || 0) : 0),
          0,
        );
        return (
          <Card key={j.ticket_id} className="overflow-hidden">
            {/* Header — which ticket, when it opened, and the total time it has taken so far */}
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
              <div className="flex items-center gap-3">
                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                  <Icon.Wrench className="h-5 w-5" />
                </span>
                <div>
                  <h3 className="text-sm font-semibold text-slate-900">Ticket #{j.ticket_id}</h3>
                  <p className="text-xs text-slate-400">
                    Opened {fmtDate(j.opened_at)} · {num(j.stage_count)} {j.stage_count === 1 ? 'stage' : 'stages'}
                  </p>
                </div>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                {/* All days — the whole ticket lifespan (open → close / now). */}
                {j.is_open
                  ? <Badge tone="blue">Live · {fmtDuration(total) || '0s'}</Badge>
                  : <Badge tone="green">Closed · {fmtDuration(total) || '0s'} total</Badge>}
                {/* Days in maintenance — only the time actually spent at the workshop. */}
                <Badge tone="amber">In maintenance · {fmtDuration(shopSeconds) || '0s'}</Badge>
              </div>
            </div>

            <div className="px-6 py-5">
              {/* Proportional stacked bar — each stage's width = its share of the total time */}
              <div className="mb-1 flex h-3 w-full overflow-hidden rounded-full bg-slate-100">
                {j.stages.map((stg, i) => {
                  const { style, label } = stageMeta(stg);
                  const pct = total > 0 && stg.seconds != null ? (stg.seconds / total) * 100 : (j.is_open && i === j.stages.length - 1 ? 100 : 0);
                  if (pct <= 0) return null;
                  return (
                    <div
                      key={i}
                      className={`${style.dot} h-full`}
                      style={{ width: `${pct}%` }}
                      title={`${label} · ${fmtDuration(stg.seconds) || 'in progress'}`}
                    />
                  );
                })}
              </div>
              <p className="mb-4 text-right text-[11px] text-slate-400">time in each stage (proportional)</p>

              {/* Stage-by-stage detail — a vertical rail, the current stage still ticking */}
              <ol className="relative space-y-3 border-l border-slate-200 pl-5">
                {j.stages.map((stg, i) => {
                  const { label, tone, style } = stageMeta(stg);
                  const isCurrent = j.is_open && i === j.stages.length - 1;
                  const dur = fmtDuration(stg.seconds);
                  return (
                    <li key={i} className="relative">
                      <span className={`absolute -left-[27px] mt-1 flex h-3.5 w-3.5 items-center justify-center rounded-full ring-4 ring-white ${style.dot}`} />
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                          <Badge tone={tone}>{label}</Badge>
                          {isCurrent && <span className="text-xs font-medium text-blue-600">current stage</span>}
                        </div>
                        <span className={`text-xs font-semibold tabular-nums ${isCurrent ? 'text-blue-600' : 'text-slate-600'}`}>
                          {isCurrent ? `${dur || '0s'} so far` : (dur || '—')}
                        </span>
                      </div>
                      <div className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-slate-400">
                        <span>{fmtDate(stg.entered_at)} · {fmtClock(stg.entered_at)}</span>
                        {stg.garage && <span className="text-slate-500">{stg.garage}</span>}
                        {stg.actor && <span>· {stg.actor}</span>}
                      </div>
                      {stg.description && <p className="mt-1 text-sm leading-relaxed text-slate-600">{stg.description}</p>}
                    </li>
                  );
                })}
              </ol>
            </div>
          </Card>
        );
      })}
    </div>
  );
}

// Multi-line workshop notes -> a tidy bulleted list; lines made only of -, *, = … become dividers.
function NotesList({ text }) {
  const lines = String(text || '').split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  if (lines.length === 0) return <span className="text-sm text-slate-400">No notes</span>;
  return (
    <ul className="space-y-1.5">
      {lines.map((line, i) =>
        /^[-*_=.~•\s]{2,}$/.test(line) ? (
          <li key={i} aria-hidden className="!mt-2 border-t border-dashed border-slate-200" />
        ) : (
          <li key={i} className="flex gap-2 text-sm leading-relaxed text-slate-600">
            <span className="mt-[7px] h-1.5 w-1.5 shrink-0 rounded-full bg-slate-300" />
            <span>{line}</span>
          </li>
        )
      )}
    </ul>
  );
}

// Maintenance situation severity (from the reason -> status link).
const PRIO = {
  critical: { label: 'Critical', tone: 'red', emoji: '🔴' },
  special: { label: 'Special', tone: 'violet', emoji: '🟣' },
  minor: { label: 'Minor', tone: 'amber', emoji: '🟡' },
  routine: { label: 'Routine', tone: 'green', emoji: '🟢' },
};

// Top-level tabs for the profile. Keys are also the ?tab= URL value (deep-linkable / shareable).
const TAB_KEYS = ['overview', 'plate', 'visits', 'timeline', 'journey', 'activity', 'financials', 'media'];

// Per-tab "Data Origin" line — the standing traceability rule: every surface names where its
// numbers come from, so a manager on any tab still sees the source (no black boxes).
const TAB_ORIGIN = {
  overview: 'Live figures derived from OfficeManager contracts via RealProfitService. Outstanding fines from the F RTA source. Cost of Ownership adds the purchase price from the FASTER Asset sheet.',
  maintenance: 'Health, findings & workflow tickets from the in-app maintenance workflow. Service log & tyre brand/DOT/tread/warranty from the ticket line items.',
  visits: 'Each maintenance visit is an OfficeManager type-U contract, enriched with its workshop events from the N-Maintenance sheet log (garage, issues, priority, cost).',
  timeline: 'The N-Maintenance sheet workshop log interleaved with the manual maintenance-workflow audit trail (transitions & follow-ups) logged in-app, newest first.',
  journey: 'The same in-app maintenance-workflow audit trail (vehicle event log), reshaped per ticket: each workflow_status transition marks a stage, timed to the next transition — so you see every stage the car went through and how long it sat in each.',
  activity: 'The car’s whole history as an investigation tool — search, filters, KPIs, grouping and sorting over every source unified: the N-Maintenance sheet workshop visits, the maintenance-workflow audit trail (inspections, dispatch, repair, re-inspection, parts, approvals & follow-ups), the logistics movement log, and inspection records. Every row carries who acted and when; nothing is editable, and the exact filtered view is captured in the URL to share.',
  financials: 'Reverse-engineered from OfficeManager billing via RealProfitService: rent − discount + realized usage − operating − car-level maintenance.',
  media: 'Pre/post condition & odometer photos captured during the maintenance workflow (inspection & garage steps).',
};

// A muted provenance caption shown at the foot of each tab.
function DataOrigin({ tab }) {
  const text = TAB_ORIGIN[tab];
  if (!text) return null;
  return (
    <p className="px-1 pt-1 text-xs leading-relaxed text-slate-400">
      <span className="font-semibold text-slate-500">Data origin</span> · {text}
    </p>
  );
}

function Field({ label, value, tip }) {
  return (
    <div className="flex justify-between gap-4 py-1.5 text-sm">
      <span className="flex items-center gap-1.5 text-slate-500">
        {label}
        {tip && <InfoTip content={tip} />}
      </span>
      <span className="text-right font-medium text-slate-900">{value ?? '—'}</span>
    </div>
  );
}

// One line of the Lifetime Net Profit working: a label (+ optional hint) and a right-aligned amount.
function BridgeLine({ label, hint, value, labelClass = 'text-slate-600', valueClass = 'text-slate-900' }) {
  return (
    <div className="flex items-baseline justify-between gap-4">
      <dt className={labelClass}>
        {label}
        {hint && <span className="ml-2 hidden text-xs font-normal text-slate-400 sm:inline">{hint}</span>}
      </dt>
      <dd className={`tabular-nums font-medium ${valueClass}`}>{value}</dd>
    </div>
  );
}

// A quiet "fact" chip for the flat header.
// Count-up telemetry tile on the dossier command deck.
function HeroStat({ label, value, unit }) {
  const n = useCountUp(Number(value) || 0, 1400);
  return (
    <div className="vhero-stat">
      <div className="lbl">{label}</div>
      <div className="num">
        {Math.round(n).toLocaleString()}
        {unit && <span className="unit">{unit}</span>}
      </div>
    </div>
  );
}

// One glowing health LED (Registration / Insurance / Service) with a short caption.
function HeroLed({ label, status, detail }) {
  return (
    <span className="vhero-led">
      <span className={`led ${status}`} aria-hidden />
      <span><b>{label}</b>{detail ? ` · ${detail}` : ''}</span>
    </span>
  );
}

export default function VehicleProfile() {
  const { id } = useParams();
  const fetcher = useCallback(async () => {
    const { data } = await api.get(`/Vehicle/${id}/profile`);
    return data.data;
  }, [id]);
  const { data, loading, error, reload } = useFetch(fetcher, [id]);
  // Plate history is fetched here at the parent so we can decide whether to surface the dedicated
  // "Plate History" tab (it only exists for a plate that was re-issued across vehicles). The panel
  // itself is presentational and consumes this payload.
  const plateFetcher = useCallback(async () => (await api.get(`/Vehicle/${id}/plate-history`)).data.data, [id]);
  const { data: plateData, loading: plateLoading } = useFetch(plateFetcher, [id]);
  const plateReused = !!plateData?.is_reused;
  const navigate = useNavigate();
  const toast = useToast();
  const [vendors, setVendors] = useState([]);
  const [maintOpen, setMaintOpen] = useState(false);
  const [maintForm, setMaintForm] = useState({ vendor_id: '', expected_return_date: '' });
  const [conflict, setConflict] = useState(null); // reservation clash returned by the API (409)
  const [busy, setBusy] = useState(false);
  const [openVisits, setOpenVisits] = useState({}); // expanded maintenance-history rows (by visit id)
  const [logEvent, setLogEvent] = useState(null); // maintenance-log event opened in the detail modal
  const [showAllVisits, setShowAllVisits] = useState(false); // collapse the Maintenance History table by default
  const [showAllLog, setShowAllLog] = useState(false); // collapse the Maintenance Log timeline by default
  const [showAllContracts, setShowAllContracts] = useState(false); // collapse the Contract History table by default
  const [contractType, setContractType] = useState('all'); // Contract History type filter: all | C | U | R
  const [bridgeOpen, setBridgeOpen] = useState(false); // "how is Lifetime Net Profit calculated" drill-down

  const VISITS_PREVIEW = 5;    // rows shown before "Show all"
  const LOG_PREVIEW = 4;       // timeline events shown before "Show all"
  const CONTRACTS_PREVIEW = 5; // contract rows shown before "Show all"

  const toggleVisit = (vid) => setOpenVisits((o) => ({ ...o, [vid]: !o[vid] }));

  // Deep-dive: arriving from Maintenance Foresight with ?event=<id> highlights that exact
  // workshop event (the worst-case offender) so the user can see what actually happened.
  const [searchParams, setSearchParams] = useSearchParams();
  const highlightEventId = searchParams.get('event');
  // Arriving from Fleet Utilization with ?focus=maintenance jumps straight to the Maintenance Log.
  const focus = searchParams.get('focus');

  // Which tab is showing. Deep-links win: an ?event= (Foresight) or ?focus=maintenance (Fleet
  // Utilization) link lands on the Timeline tab — where the workshop/workflow log now lives — so the
  // existing scroll-into-view still finds its target; otherwise honour ?tab=, else default to Overview.
  const deepLinksTimeline = !!highlightEventId || focus === 'maintenance';
  const tabParam = searchParams.get('tab');
  const [activeTab, setActiveTab] = useState(
    deepLinksTimeline ? 'timeline' : (TAB_KEYS.includes(tabParam) ? tabParam : 'overview')
  );

  // Switch tab + reflect it in the URL (shareable/back-button), preserving any other params.
  const changeTab = (key) => {
    setActiveTab(key);
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      next.set('tab', key);
      return next;
    }, { replace: true });
  };

  // Traceability: clicking a Financial Performance donut segment jumps to the raw records behind
  // that cost — acquisition → Overview (purchase details on the Snapshot card), maintenance → Visits
  // (the visit ledger), operating → the Rent tab's Contract History table (switch tab, then scroll).
  const drillFinancial = (key) => {
    if (key === 'maintenance') changeTab('visits');
    else if (key === 'acquisition') changeTab('overview');
    else {
      changeTab('financials');
      setTimeout(() => document.getElementById('contract-history')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 300);
    }
  };

  useEffect(() => { api.get('/Vendor').then((r) => setVendors(r.data.data || [])).catch(() => {}); }, []);

  // When deep-diving to a specific event, make sure the Maintenance tab is showing, expand the
  // full log, and scroll it into view once its panel is mounted.
  useEffect(() => {
    if (!highlightEventId || !data) return undefined;
    setActiveTab('timeline');
    setShowAllLog(true);
    const t = setTimeout(() => {
      const el = document.getElementById(`log-event-${highlightEventId}`);
      if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, 300);
    return () => clearTimeout(t);
  }, [highlightEventId, data]);

  useEffect(() => {
    if (focus !== 'maintenance' || !data) return undefined;
    setActiveTab('timeline');
    setShowAllLog(true);
    const t = setTimeout(() => {
      document.getElementById('maintenance-log')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 300);
    return () => clearTimeout(t);
  }, [focus, data]);

  // Keep the active tab in sync with the URL when it changes underneath us (browser back/forward,
  // or a shared ?tab= link). changeTab already updates both, so this is a no-op for normal clicks.
  useEffect(() => {
    if (tabParam && TAB_KEYS.includes(tabParam) && tabParam !== activeTab) setActiveTab(tabParam);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tabParam]);

  const closeMaint = () => {
    setMaintOpen(false);
    setConflict(null);
  };

  // Maintenance is logged only through the ticket workflow — the vehicle page never updates the service
  // record directly. The Service-Due alert deep-links here with ?serviceTicket=<type>; we ORIGINATE (or
  // reuse) a maintenance ticket pre-filled with that service and jump to it. The car's Service Status /
  // battery dates / odometer below update only when that ticket is later closed.
  const openServiceTicket = useCallback(async (serviceType) => {
    setBusy(true);
    try {
      const { data: res } = await api.post(`/Vehicle/${id}/service-ticket`, { service_type: serviceType });
      const ticket = res?.data?.ticket;
      toast.success(`Maintenance ticket #${ticket?.id} opened — perform the service on the ticket`);
      if (ticket?.url) navigate(ticket.url);
    } catch (e) {
      toast.error(e?.response?.data?.message || 'Could not open a maintenance ticket');
    } finally {
      setBusy(false);
    }
  }, [id, navigate, toast]);

  // Deep-link from the Service-Due notification: ?serviceTicket=<type> opens/creates the ticket once,
  // then drops the param (so a refresh/back doesn't re-fire) and routes to the ticket.
  useEffect(() => {
    const svc = searchParams.get('serviceTicket');
    if (!data || !svc) return;
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      next.delete('serviceTicket');
      return next;
    }, { replace: true });
    openServiceTicket(svc);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data, searchParams]);

  const sendToMaintenance = async ({ force = false } = {}) => {
    setBusy(true);
    try {
      await api.post(`/Vehicle/${id}/operation`, {
        category: 'maintenance',
        vendor_id: maintForm.vendor_id || undefined,
        expected_return_date: maintForm.expected_return_date || undefined,
        force: force || undefined,
      });
      toast.success('Car sent to maintenance');
      closeMaint();
      setMaintForm({ vendor_id: '', expected_return_date: '' });
      reload();
    } catch (e) {
      const res = e.response;
      if (res?.status === 409 && res.data?.data?.conflict) {
        // car is reserved and the timing clashes — show it and let the operator override
        setConflict({ message: res.data.message, reservations: res.data.data.reservations || [] });
      } else {
        toast.error(res?.data?.message || 'Could not update status');
      }
    } finally {
      setBusy(false);
    }
  };

  if (loading) {
    return (
      <div className="py-8">
        <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
          {/* hero placeholder */}
          <Skeleton className="h-44 w-full rounded-2xl" />
          {/* stats placeholder */}
          <MetricGridSkeleton count={4} />
          {/* specs + registration placeholder */}
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <Skeleton className="h-72 rounded-2xl lg:col-span-2" />
            <Skeleton className="h-72 rounded-2xl" />
          </div>
          {/* table placeholders */}
          <Skeleton className="h-64 w-full rounded-2xl" />
          <Skeleton className="h-64 w-full rounded-2xl" />
        </div>
      </div>
    );
  }
  if (error || !data) {
    return (
      <div className="mx-auto max-w-3xl px-4 py-12">
        <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error || 'Not found'}</div>
        <Link to="/vehicles" className="mt-4 inline-block text-sm font-medium text-indigo-600">← Back to vehicles</Link>
      </div>
    );
  }

  const v = data.vehicle;
  const reg = data.registration;
  const av = data.availability || {};
  const contracts = data.contracts || [];
  // Newest first — sort by the most recent date on the contract (out, falling back to in).
  const contractTime = (c) => { const t = new Date(c.out_date || c.in_date || 0).getTime(); return isNaN(t) ? 0 : t; };
  const sortedContracts = [...contracts].sort((a, b) => contractTime(b) - contractTime(a));
  // Contract History type filter — only offer the types this vehicle actually has.
  const contractTypeCounts = contracts.reduce((acc, c) => { acc[c.contract_type] = (acc[c.contract_type] || 0) + 1; return acc; }, {});
  const contractFilters = [
    { key: 'all', label: 'All', count: contracts.length },
    { key: 'C', label: 'Rental', count: contractTypeCounts.C || 0 },
    { key: 'U', label: 'Maintenance', count: contractTypeCounts.U || 0 },
    { key: 'R', label: 'Booking', count: contractTypeCounts.R || 0 },
  ].filter((f) => f.key === 'all' || f.count > 0);
  const filteredContracts = contractType === 'all' ? sortedContracts : sortedContracts.filter((c) => c.contract_type === contractType);
  const maintenance = data.maintenance || [];
  // Per-fault distribution for the hero telemetry card — each individual fault by its share of every
  // fault ever logged on this car (slices sum to 100%). Same source/shape as the Overview donut.
  const faultSegments = faultTagSegments(maintenance, { top: 8 });
  const maintenanceLog = data.maintenance_log || [];
  // One unified history: legacy sheet workshop events + the manual workflow audit trail + follow-ups.
  const timeline = data.timeline || maintenanceLog;
  // Per-ticket stage meter — every workflow stage the car passed through + time in each.
  const journeys = data.workflow_journeys || [];
  const analytics = data.maintenance_analytics || [];
  const stats = data.stats || {};

  // ── Command-deck telemetry ── everything below is derived from the payload already loaded.
  const totalFaults = faultSegments.reduce((a, s) => a + (s.value || 0), 0);
  // Health LEDs — same thresholds as the Overview health card (30-day amber window).
  const ledFromDays = (label, d) => ({
    label,
    status: d == null ? 'unknown' : d < 0 ? 'bad' : d < 30 ? 'warn' : 'good',
    detail: d == null ? 'no record' : d < 0 ? `expired ${num(Math.abs(d))}d ago` : `${num(d)}d left`,
  });
  const svcStatus = v.service_status;
  const heroLeds = [
    ledFromDays('Registration', reg ? reg.registration_days_left : null),
    ledFromDays('Insurance', reg ? reg.insurance_days_left : null),
    {
      label: 'Service',
      status: !svcStatus || svcStatus.status === 'no_data' ? 'unknown' : svcStatus.status === 'service_due' ? 'bad' : 'good',
      detail: !svcStatus || svcStatus.status === 'no_data' ? 'no data'
        : svcStatus.status === 'service_due' ? `${num(svcStatus.overdue_km)} km overdue` : `${num(svcStatus.remaining)} km left`,
    },
  ];
  // Latest-activity ticker — newest unified-timeline entry, named with the same stage vocabulary.
  const lastEvent = timeline[0] || null;
  const lastEventLabel = !lastEvent ? null
    : lastEvent.kind === 'workflow'
      ? (WF_STATUS_META[lastEvent.workflow_status]?.label || wfStage(lastEvent.event_type))
      : (lastEvent.event || 'Workshop event');

  return (
    <div className="opx py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        {/* Back */}
        <Link to="/vehicles" className="inline-flex items-center gap-1 text-sm font-medium text-slate-500 transition hover:text-slate-700">
          <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M15 19l-7-7 7-7" /></svg>
          Vehicles
        </Link>

        {/* Hero header — the "command deck": a living dark cockpit panel. Drifting aurora glows,
            blueprint grid + scanline behind; count-up telemetry tiles, glowing health LEDs and a
            latest-activity ticker fill the identity column; the fault donut sits on frosted glass
            so its light-theme chart colours stay legible in both themes. */}
        <div className="vhero">
          <span aria-hidden className="vhero-aurora a" />
          <span aria-hidden className="vhero-aurora b" />
          <div className="relative flex flex-col gap-6 p-6 sm:p-7 lg:flex-row lg:items-stretch lg:justify-between">
            {/* Identity + live telemetry */}
            <div className="vhero-in min-w-0 flex-1">
              <div className="flex items-start gap-4">
                <div className="vhero-badge">
                  <svg className="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M5 13l1.5-4.5A2 2 0 0 1 8.4 7h7.2a2 2 0 0 1 1.9 1.5L19 13m-14 0h14m-14 0a2 2 0 0 0-2 2v3a1 1 0 0 0 1 1h1m14-6a2 2 0 0 1 2 2v3a1 1 0 0 1-1 1h-1m-12 0v1a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-1m2 0h10M7.5 16h.01M16.5 16h.01" />
                  </svg>
                </div>
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-3">
                    <span style={{ fontSize: 10, fontWeight: 600, letterSpacing: '.2em', textTransform: 'uppercase', color: '#7f92b8' }}>Vehicle Dossier</span>
                    <span className="vhero-live"><span className="dot" />LIVE</span>
                  </div>
                  <h1 className="mt-1.5 font-display text-2xl font-bold tracking-tight text-white sm:text-3xl" style={{ letterSpacing: '-.02em', textShadow: '0 2px 24px rgba(34,211,238,.25)' }}>
                    {[v.make, v.model].filter(Boolean).join(' ') || 'Vehicle'}
                  </h1>
                  <div className="mt-2.5 flex flex-wrap items-center gap-2.5">
                    {(v.plate_display || v.plate_no) && <span className="opx-plate" style={{ fontSize: 13, padding: '3px 10px' }}>{v.plate_display || v.plate_no}</span>}
                    {v.vin && <span className="mono" style={{ fontSize: 11.5, color: '#7f92b8' }}>{v.vin}</span>}
                    {/* compact spec line — year / category / colour (odometer graduated to a live tile below) */}
                    {[v.year, v.category, v.color].some(Boolean) && (
                      <span style={{ fontSize: 12, color: '#93a7cd' }}>{[v.year, v.category, v.color].filter(Boolean).join(' · ')}</span>
                    )}
                  </div>
                  <div className="mt-3.5 flex flex-wrap items-center gap-2">
                    {/* Canonical dual-state — the same rental + maintenance identity used across the app. */}
                    <DualState vehicle={{ ...v, av_state: av.state }} size="md" />
                    {v.for_sale && <Badge tone="amber" dot>For sale</Badge>}
                  </div>
                </div>
              </div>

              {/* Telemetry tiles — the numbers count up on load */}
              <div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <HeroStat label="Odometer" value={v.odometer} unit="km" />
                <HeroStat label="Rentals" value={contractTypeCounts.C || 0} />
                <HeroStat label="Workshop Visits" value={maintenance.length} />
                <HeroStat label="Faults Logged" value={totalFaults} />
              </div>

              {/* Health LEDs — registration / insurance / service, glowing by urgency */}
              <div className="mt-4 flex flex-wrap items-center gap-x-6 gap-y-2">
                {heroLeds.map((l) => <HeroLed key={l.label} {...l} />)}
              </div>

              {/* Latest activity ticker */}
              {lastEvent && (
                <div className="vhero-ticker">
                  <span className="vhero-live"><span className="dot" /></span>
                  <span className="min-w-0 truncate">
                    Latest activity — <span className="what">{lastEventLabel}</span>
                    {lastEvent.garage ? <> at <span className="what">{lastEvent.garage}</span></> : null}
                    {lastEvent.date ? <span style={{ color: '#7f92b8' }}> · {fmtDate(lastEvent.date)}</span> : null}
                  </span>
                </div>
              )}
            </div>

            {/* Fault distribution — frosted glass telemetry card */}
            <div className="vhero-glass w-full shrink-0 p-5 lg:w-96">
              <div className="text-[10px] font-semibold uppercase text-slate-400" style={{ letterSpacing: '.14em', marginBottom: 4 }}>Fault Distribution</div>
              <p className="mb-4 text-xs text-slate-500">Each fault by share of all faults recorded</p>
              {faultSegments.length ? (
                <CompositionDonut
                  className="!flex-col !gap-5"
                  segments={faultSegments}
                  centerLabel="Faults"
                  size={168}
                  stroke={24}
                  format={(n) => Math.round(n).toLocaleString()}
                />
              ) : (
                <div className="flex h-[168px] items-center justify-center text-xs text-slate-400">
                  No fault history recorded yet.
                </div>
              )}
              {/* Always-available: generate the printable Vehicle Report (Save-as-PDF) from this dossier. */}
              <div className="mt-5">
                <Button variant="secondary" className="w-full justify-center" onClick={() => openVehicleProfileReport(data)}>
                  <Icon.Download className="h-4 w-4" /> Vehicle Report
                </Button>
              </div>
            </div>
          </div>
        </div>

        {/* Paused — Returned to Service ribbon: the fixed, app-wide amber strip. Renders only when
            the repair is paused, so a manager instantly reads "working, but unresolved maintenance risk". */}
        <PausedRibbon vehicle={{ ...v, av_state: av.state }} />

        {/* Tab navigation — the persistent hero above stays visible on every tab. Sticks just
            below the app header (h-16) so a manager keeps the tabs in reach while scrolling. */}
        <div className="sticky top-16 z-10 -mx-4 bg-white/80 px-4 backdrop-blur sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
          <Tabs
            active={activeTab}
            onChange={changeTab}
            ariaLabel="Vehicle profile sections"
            tabs={[
              { key: 'overview', label: 'Overview' },
              // Plate History is a first-class tab, but only when this plate was actually re-issued
              // across more than one physical vehicle (self-hides for a single-holder plate).
              ...(plateReused ? [{ key: 'plate', label: 'Plate History' }] : []),
              { key: 'financials', label: 'Rent', badge: num(contracts.length) },
              { key: 'visits', label: 'Visits', badge: num(maintenance.length) },
              { key: 'activity', label: 'Timeline' },
              { key: 'timeline', label: 'Maintenance Log', badge: num(timeline.length) },
              { key: 'journey', label: 'Journey', badge: num(journeys.length) },
              { key: 'media', label: 'Media' },
            ]}
          />
        </div>

        {/* ── OVERVIEW ─────────────────────────────────────────────── core KPIs at a glance */}
        {activeTab === 'overview' && (
        <div role="tabpanel" id="panel-overview" aria-labelledby="tab-overview" className="space-y-6">
        {/* Executive overview dashboard — Quick KPIs, vehicle health, revenue/expense
            architecture, repair trends & fault distribution, all off the loaded payload.
            Money surfaces are gated by SHOW_FINANCIALS inside the component. */}
        <VehicleOverviewDashboard
          v={v}
          stats={stats}
          reg={reg}
          av={av}
          contracts={contracts}
          maintenance={maintenance}
          showFinancials={SHOW_FINANCIALS}
          onNavigate={changeTab}
        />

        {/* Financial Performance deep-dive — lifetime revenue vs. every cost this car has
            incurred: ROI ring + interactive cost-composition donut (drill-through). */}
        {SHOW_FINANCIALS && (
          <div className="space-y-2">
            <FinancialsHero vehicle={v} stats={stats} onDrill={drillFinancial} />
            {!stats.is_new && (
              <button
                type="button"
                onClick={() => setBridgeOpen(true)}
                className="text-sm font-medium text-indigo-600 transition hover:text-indigo-700"
              >
                View profit breakdown →
              </button>
            )}
          </div>
        )}

        {/* Rental Readiness — the same VehicleReadinessService verdict that gates "Set to Ready",
            rendered as a live checklist so a manager sees at a glance what's blocking a handover.
            Failing rows deep-link to the tab where the underlying data can be fixed. */}
        <ReadinessChecklist vehicleId={id} onNavigate={changeTab} />

        <DataOrigin tab="overview" />
        </div>
        )}


        {/* ── PLATE HISTORY ────────── every physical vehicle that carried this plate (reuse only) */}
        {activeTab === 'plate' && plateReused && (
        <div role="tabpanel" id="panel-plate" aria-labelledby="tab-plate" className="space-y-6">
          <PlateHistory data={plateData} loading={plateLoading} />
        </div>
        )}

        {/* ── VISITS ─────────────────────────── every maintenance visit (type-U contracts) */}
        {activeTab === 'visits' && (
        <div role="tabpanel" id="panel-visits" aria-labelledby="tab-visits" className="space-y-6">
        {/* Maintenance history — the full "story" for this car */}
        <SectionCard
          title="Maintenance History"
          subtitle="Each visit, newest first · tap a row to see its workshop events."
          actions={<Badge tone="gray">{num(maintenance.length)} {maintenance.length === 1 ? 'visit' : 'visits'}</Badge>}
        >
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm stagger-rows">
              <thead className="bg-slate-50/90">
                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <th className="whitespace-nowrap border-b border-slate-200 px-6 py-3">Visit</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-6 py-3">Out / In</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-6 py-3">Priority</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-6 py-3">Status</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-6 py-3">Garage</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-6 py-3">Issues</th>
                  {SHOW_FINANCIALS && <th className="whitespace-nowrap border-b border-slate-200 px-6 py-3 text-right">Total Cost</th>}
                </tr>
              </thead>
              <tbody>
                {(showAllVisits ? maintenance : maintenance.slice(0, VISITS_PREVIEW)).map((m) => {
                  const p = PRIO[m.priority] || PRIO.routine;
                  const events = m.events || [];
                  const expandable = events.length > 0;
                  const open = !!openVisits[m.id];
                  return (
                    <Fragment key={m.id}>
                      <tr
                        className={`transition-colors hover:bg-indigo-50/40 ${expandable ? 'cursor-pointer' : ''}`}
                        onClick={expandable ? () => toggleVisit(m.id) : undefined}
                      >
                        <td className="border-b border-slate-100 px-6 py-3.5 font-medium">
                          <div className="flex items-center gap-2">
                            <span className={`text-slate-400 transition-transform ${open ? 'rotate-90' : ''} ${expandable ? '' : 'invisible'}`}>▶</span>
                            <Link to={`/contracts/${m.id}`} onClick={(e) => e.stopPropagation()} className="text-indigo-600 hover:text-indigo-700">#{m.contract_no || m.id}</Link>
                          </div>
                        </td>
                        <td className="border-b border-slate-100 px-6 py-3.5 text-slate-500">
                          {fmtDate(m.date)}
                          {m.in_date && <span className="text-slate-400"> → {fmtDate(m.in_date)}</span>}
                        </td>
                        <td className="border-b border-slate-100 px-6 py-3.5">
                          <Badge tone={p.tone} dot title={m.reason || ''}>{p.label}</Badge>
                        </td>
                        <td className="border-b border-slate-100 px-6 py-3.5">
                          {m.stage ? <Badge tone={EVENT_TONE[m.stage] || 'gray'}>{m.stage}</Badge> : <span className="text-xs text-slate-300">—</span>}
                          {m.event_count > 1 && <span className="ml-1 text-xs text-slate-400">×{m.event_count}</span>}
                        </td>
                        <td className="border-b border-slate-100 px-6 py-3.5 text-slate-700">{m.garage || '—'}</td>
                        <td className="border-b border-slate-100 px-6 py-3.5">
                          <div className="flex flex-wrap gap-1">
                            {(m.tags || []).slice(0, 4).map((t) => <Badge key={t} tone="indigo">{t}</Badge>)}
                            {(m.tags || []).length > 4 && <span className="text-xs text-slate-400">+{m.tags.length - 4}</span>}
                            {(!m.tags || m.tags.length === 0) && <span className="text-xs text-slate-300">—</span>}
                          </div>
                        </td>
                        {SHOW_FINANCIALS && <td className={`border-b border-slate-100 px-6 py-3.5 text-right tabular-nums ${Number(m.total) > 0 ? 'font-semibold text-slate-900' : 'text-slate-300'}`}>{aed2(m.total)}</td>}
                      </tr>
                      {open && expandable && (
                        <tr className="bg-slate-50/60">
                          <td colSpan={SHOW_FINANCIALS ? 7 : 6} className="px-6 py-4">
                            <div className="space-y-2">
                              <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Workshop events for this visit</p>
                              {events.map((e) => (
                                <div key={e.id} className="flex flex-wrap items-start gap-x-4 gap-y-1 rounded-xl bg-white px-4 py-2.5 text-sm shadow-soft ring-1 ring-inset ring-slate-100">
                                  <Badge tone={EVENT_TONE[e.event] || 'gray'}>{e.event || '—'}</Badge>
                                  <span className="text-slate-500">
                                    {e.date ? fmtDate(e.date) : 'No date'}
                                    {e.actual_in && e.actual_in !== e.date && <span className="text-slate-400"> → returned {fmtDate(e.actual_in)}</span>}
                                  </span>
                                  {e.garage && <span className="font-medium text-slate-600">{e.garage}</span>}
                                  {e.type && <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{e.type}</span>}
                                  {(e.issues || []).map((t) => <Badge key={t} tone="indigo">{t}</Badge>)}
                                  {e.cost != null && Number(e.cost) > 0 && <span className="font-semibold text-slate-700">{aed2(e.cost)}</span>}
                                  {e.notes && <div className="w-full pt-1"><NotesList text={e.notes} /></div>}
                                </div>
                              ))}
                            </div>
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  );
                })}
                {maintenance.length === 0 && (
                  <tr><td colSpan={SHOW_FINANCIALS ? 7 : 6} className="px-6 py-12 text-center text-slate-400">No maintenance recorded for this vehicle yet.</td></tr>
                )}
              </tbody>
            </table>
          </div>
          {maintenance.length > VISITS_PREVIEW && (
            <div className="border-t border-slate-100 px-6 py-3 text-center">
              <button
                type="button"
                onClick={() => setShowAllVisits((s) => !s)}
                className="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 transition hover:text-indigo-700"
              >
                {showAllVisits ? 'Show less' : `Show all ${num(maintenance.length)} visits`}
                <svg className={`h-4 w-4 transition-transform ${showAllVisits ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M19 9l-7 7-7-7" /></svg>
              </button>
            </div>
          )}
        </SectionCard>

        <DataOrigin tab="visits" />
        </div>
        )}

        {/* ── TIMELINE ──────────── unified sheet workshop history + the manual workflow trail */}
        {activeTab === 'timeline' && (
        <div role="tabpanel" id="panel-timeline" aria-labelledby="tab-timeline" className="space-y-6">
        {/* Vehicle Timeline — one unified history: legacy sheet workshop events AND the manual
            workflow trail (test drives, follow-ups, dispatch/repair/re-inspection), newest first. */}
        {timeline.length === 0 && (
          <p className="rounded-2xl border border-dashed border-slate-200 py-12 text-center text-sm text-slate-400">
            No workshop or workflow events recorded for this car yet.
          </p>
        )}
        {timeline.length > 0 && (
          <Card id="maintenance-log" className="scroll-mt-28">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
              <div>
                <h3 className="text-base font-semibold text-slate-900">Vehicle Timeline</h3>
                <p className="mt-0.5 text-xs text-slate-400">Sheet workshop history and the maintenance workflow you log by hand, newest first · tap a workshop event for the full record.</p>
              </div>
              <Badge tone="gray">{num(timeline.length)} {timeline.length === 1 ? 'event' : 'events'}</Badge>
            </div>

            <div className="relative px-6 py-6">
              {/* the connecting line behind the markers */}
              <span aria-hidden className="pointer-events-none absolute bottom-8 left-[2.625rem] top-8 w-px bg-gradient-to-b from-slate-200 via-slate-200 to-transparent" />
              <ol className="stagger space-y-3">
                {(showAllLog ? timeline : timeline.slice(0, LOG_PREVIEW)).map((m) => {
                  // Manual workflow / follow-up entries render in the same timeline, styled like the board.
                  if (m.kind === 'workflow') return <WorkflowLogItem key={`wf-${m.id}`} m={m} />;
                  const tone = EVENT_TONE[m.event] || 'gray';
                  const st = EVENT_STYLE[tone] || EVENT_STYLE.gray;
                  const icon = EVENT_ICON[m.event] || DEFAULT_EVENT_ICON;
                  const isHit = String(m.id) === String(highlightEventId);
                  return (
                    <li key={`ws-${m.id}`} id={`log-event-${m.id}`} className="relative flex scroll-mt-28 gap-4">
                      {/* timeline marker — now an icon chip */}
                      <span className={`relative z-10 mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full ring-4 ring-white ${st.soft} ${st.text}`}>
                        <svg className="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d={icon} /></svg>
                      </span>

                      {/* event card — a button so the whole row is clickable & keyboard-accessible */}
                      <button
                        type="button"
                        onClick={() => setLogEvent(m)}
                        className={`group min-w-0 flex-1 rounded-2xl border bg-white p-4 text-left shadow-soft transition-all duration-200 hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-card hover:ring-2 ${st.ring} focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 ${isHit ? 'border-red-300 bg-red-50/40 ring-2 ring-red-400' : 'border-slate-200/60'}`}
                      >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                          <div className="flex flex-wrap items-center gap-2">
                            <Badge tone={tone}>{m.event || '—'}</Badge>
                            {isHit && <Badge tone="red" dot>Worst-case downtime — investigate</Badge>}
                            {(m.type || m.severity) && (
                              <span className="rounded-lg bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">{m.type || m.severity}</span>
                            )}
                          </div>
                          <div className="flex items-center gap-2">
                            <span className="text-xs font-medium text-slate-400">
                              {m.date ? fmtDate(m.date) : 'No date'}
                              {m.actual_in && m.actual_in !== m.date && <span className="text-emerald-500"> → back {fmtDate(m.actual_in)}</span>}
                            </span>
                            <svg className="h-4 w-4 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M9 5l7 7-7 7" /></svg>
                          </div>
                        </div>

                        {/* the real fault — MAIN area(s) recorded for the visit (the "problem", not just the type) */}
                        {m.main && (
                          <p className="mt-2 text-sm font-semibold text-slate-800">{m.main}</p>
                        )}
                        {m.sup && (
                          <p className="mt-0.5 line-clamp-1 text-xs text-slate-500">{m.sup}</p>
                        )}

                        <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
                          {m.garage && (
                            <span className="inline-flex items-center gap-1.5">
                              <svg className="h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"><path d="M3 9l9-6 9 6v11a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z" /></svg>
                              <span className="font-medium text-slate-600">{m.garage}</span>
                            </span>
                          )}
                          {SHOW_FINANCIALS && m.cost != null && (
                            <span className="font-semibold text-slate-700">{aed2(m.cost)}</span>
                          )}
                        </div>

                        {m.notes && (
                          <div className="mt-3 rounded-xl bg-slate-50/70 p-3 ring-1 ring-inset ring-slate-100">
                            <p className="line-clamp-2 text-sm leading-relaxed text-slate-600">
                              {String(m.notes).split(/\r?\n/).map((l) => l.trim()).filter(Boolean).join(' · ')}
                            </p>
                            <span className="mt-1.5 inline-block text-[11px] font-medium text-indigo-500 opacity-0 transition group-hover:opacity-100">Read full notes →</span>
                          </div>
                        )}
                      </button>
                    </li>
                  );
                })}
              </ol>

              {timeline.length > LOG_PREVIEW && (
                <div className="mt-4 flex justify-center">
                  <button
                    type="button"
                    onClick={() => setShowAllLog((s) => !s)}
                    className="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-4 py-1.5 text-sm font-medium text-indigo-600 shadow-soft transition hover:border-slate-300 hover:text-indigo-700"
                  >
                    {showAllLog ? 'Show less' : `Show all ${num(timeline.length)} events`}
                    <svg className={`h-4 w-4 transition-transform ${showAllLog ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M19 9l-7 7-7-7" /></svg>
                  </button>
                </div>
              )}
            </div>
          </Card>
        )}

        <DataOrigin tab="timeline" />
        </div>
        )}

        {/* ── JOURNEY ─────────── per-ticket stage meter: every stage the car went through + time in each */}
        {activeTab === 'journey' && (
        <div role="tabpanel" id="panel-journey" aria-labelledby="tab-journey" className="space-y-6">
          <WorkflowJourneys journeys={journeys} />
          <DataOrigin tab="journey" />
        </div>
        )}

        {/* ── TIMELINE ─────────── the car's full audit trail as an investigation tool (search / filter / KPI / group) */}
        {activeTab === 'activity' && (
        <div role="tabpanel" id="panel-activity" aria-labelledby="tab-activity" className="space-y-6">
          <VehicleInvestigationTimeline vehicleId={id} legacyTimeline={timeline} />
          <DataOrigin tab="activity" />
        </div>
        )}

        {/* ── RENT ─────────────────────────────── contract history + maintenance cost analysis */}
        {activeTab === 'financials' && (
        <div role="tabpanel" id="panel-financials" aria-labelledby="tab-financials" className="space-y-6">
        {/* Cost analysis — per-service price trend & vs-fleet comparison */}
        {analytics.length > 0 && (
          <SectionCard
            title="Cost Analysis"
            subtitle="Latest price vs the previous one, and this car vs the fleet average."
          >
            <DataTable
              rows={analytics}
              rowKey={(s) => s.service}
              columns={[
                { key: 'service', header: 'Service', cellClass: 'font-medium text-slate-900', render: (s) => s.service },
                { key: 'visits', header: 'Visits', align: 'center', cellClass: 'text-slate-500', render: (s) => s.visits },
                { key: 'latest', header: 'Latest', align: 'right', cellClass: 'tabular-nums font-medium text-slate-900', render: (s) => aed2(s.latest_cost) },
                {
                  key: 'trend', header: 'Trend (vs previous)', align: 'right',
                  tooltip: 'Change from the previous recorded price for this service. Red = more expensive, green = cheaper.',
                  cellClass: 'tabular-nums font-medium',
                  render: (s) => {
                    const arrow = s.trend === 'up' ? '▲' : s.trend === 'down' ? '▼' : '–';
                    const tone = s.trend === 'up' ? 'text-red-600' : s.trend === 'down' ? 'text-emerald-600' : 'text-slate-400';
                    return <span className={tone}>{s.delta != null ? `${arrow} ${aed2(Math.abs(s.delta))}` : '—'}</span>;
                  },
                },
                { key: 'avg', header: 'This car avg', align: 'right', cellClass: 'tabular-nums text-slate-700', render: (s) => aed2(s.avg_cost) },
                {
                  key: 'fleet', header: 'Fleet avg', align: 'right',
                  tooltip: 'Average cost of this service across the whole fleet — to spot a car being over- or under-charged.',
                  cellClass: 'tabular-nums text-slate-700',
                  render: (s) => {
                    const vsFleet = (s.fleet_avg != null && s.avg_cost != null) ? s.avg_cost - s.fleet_avg : null;
                    return (
                      <>
                        {s.fleet_avg != null ? aed2(s.fleet_avg) : '—'}
                        {vsFleet != null && vsFleet !== 0 && (
                          <span className={`ml-1 text-xs ${vsFleet > 0 ? 'text-red-500' : 'text-emerald-600'}`}>
                            {vsFleet > 0 ? '(above)' : '(below)'}
                          </span>
                        )}
                      </>
                    );
                  },
                },
              ]}
            />
          </SectionCard>
        )}

        {/* Contract history */}
        <SectionCard
          id="contract-history"
          className="scroll-mt-28"
          title="Contract History"
          actions={(
            <div className="flex items-center gap-3">
              <Badge tone="gray">{num(contracts.length)} total</Badge>
              {/* Type filter — only shows when the vehicle has more than one type to switch between */}
              {contractFilters.length > 2 && (
                <div className="inline-flex rounded-lg bg-slate-100 p-0.5">
                  {contractFilters.map((f) => (
                    <button
                      key={f.key}
                      type="button"
                      onClick={() => { setContractType(f.key); setShowAllContracts(false); }}
                      className={`rounded-lg px-2.5 py-1 text-xs font-medium transition ${
                        contractType === f.key ? 'bg-white text-indigo-600 shadow-sm' : 'text-slate-500 hover:text-slate-800'
                      }`}
                    >
                      {f.label} <span className="tabular-nums opacity-60">{f.count}</span>
                    </button>
                  ))}
                </div>
              )}
            </div>
          )}
        >
          <DataTable
            rows={showAllContracts ? filteredContracts : filteredContracts.slice(0, CONTRACTS_PREVIEW)}
            rowKey={(c) => c.id}
            empty={contracts.length === 0 ? 'No contracts for this vehicle.' : 'No contracts of this type.'}
            highlightRow={(c) => !c.in_date}
            columns={[
              {
                key: 'contract', header: 'Contract', cellClass: 'font-medium',
                render: (c) => <Link to={`/contracts/${c.id}`} className="text-indigo-600 hover:text-indigo-700">#{c.contract_no || c.id}</Link>,
              },
              {
                key: 'customer', header: 'Customer',
                render: (c) => c.customer_id
                  ? <Link to={`/customers/${c.customer_id}`} className="text-indigo-600 hover:text-indigo-700">{c.customer || `#${c.customer_id}`}</Link>
                  : <span className="text-slate-400">—</span>,
              },
              { key: 'type', header: 'Type', render: (c) => <ContractTypeBadge type={c.contract_type} /> },
              { key: 'state', header: 'State', render: (c) => <ContractStateBadge state={c.state} /> },
              { key: 'out', header: 'Out', cellClass: 'text-slate-500', render: (c) => fmtDate(c.out_date) },
              { key: 'in', header: 'In', cellClass: 'text-slate-500', render: (c) => fmtDate(c.in_date) },
              { key: 'debit', header: 'Debit', align: 'right', cellClass: 'tabular-nums text-slate-600', render: (c) => aed2(c.debit) },
              { key: 'credit', header: 'Credit', align: 'right', cellClass: 'tabular-nums text-slate-600', render: (c) => aed2(c.credit) },
              {
                key: 'balance', header: 'Balance', align: 'right',
                tooltip: 'Debit − credit on the contract. Red = customer owes, green = credit due to customer.',
                render: (c) => <Badge tone={Number(c.balance) > 0 ? 'red' : Number(c.balance) < 0 ? 'green' : 'gray'}>{aed2(c.balance)}</Badge>,
              },
            ]}
          />
          {filteredContracts.length > CONTRACTS_PREVIEW && (
            <div className="border-t border-slate-100 px-6 py-3 text-center">
              <button
                type="button"
                onClick={() => setShowAllContracts((s) => !s)}
                className="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 transition hover:text-indigo-700"
              >
                {showAllContracts ? 'Show less' : `Show all ${num(filteredContracts.length)} contracts`}
                <svg className={`h-4 w-4 transition-transform ${showAllContracts ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M19 9l-7 7-7-7" /></svg>
              </button>
            </div>
          )}
        </SectionCard>
        <DataOrigin tab="financials" />
        </div>
        )}

        {/* ── MEDIA ──────────────────────────────── condition timeline & inspection photos */}
        {activeTab === 'media' && (
        <div role="tabpanel" id="panel-media" aria-labelledby="tab-media" className="space-y-6">
          <VehicleWorkflowPanel vehicleId={id} sections={['photos']} />
          <DataOrigin tab="media" />
        </div>
        )}
      </div>

      {/* Send to Maintenance */}
      <Modal
        open={maintOpen}
        onClose={() => !busy && closeMaint()}
        title="Send to Maintenance"
        subtitle={v.plate_no || v.vin}
        footer={(
          <>
            <Button variant="secondary" onClick={closeMaint} disabled={busy}>Cancel</Button>
            {conflict
              ? <Button variant="danger" onClick={() => sendToMaintenance({ force: true })} loading={busy}>Send anyway</Button>
              : <Button onClick={() => sendToMaintenance({})} loading={busy}>Send to Maintenance</Button>}
          </>
        )}
      >
        <div className="space-y-4">
          {conflict && (
            <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm">
              <p className="flex items-center gap-1.5 font-medium text-red-700">
                <svg className="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" /></svg>
                This car is reserved
              </p>
              <p className="mt-1 text-red-600">{conflict.message}</p>
              {conflict.reservations?.length > 0 && (
                <ul className="mt-2 space-y-1 text-xs text-red-600">
                  {conflict.reservations.map((r, i) => (
                    <li key={i}>• Reservation {r.label}{r.customer ? ` — ${r.customer}` : ''} ({r.reason})</li>
                  ))}
                </ul>
              )}
              <p className="mt-2 text-xs text-slate-500">Set an expected return date before the reservation starts, or press <span className="font-medium">Send anyway</span> to override.</p>
            </div>
          )}
          <p className="text-sm text-slate-500">This opens a maintenance record — the car immediately shows as "in maintenance". Maintenance can be opened even for a car with an active or upcoming booking; manage the overlap manually.</p>
          <label className="block">
            <span className="mb-1 block text-sm font-medium text-slate-700">Garage</span>
            <SearchSelect
              value={maintForm.vendor_id}
              onChange={(val) => setMaintForm((f) => ({ ...f, vendor_id: val }))}
              options={vendors.map((vn) => ({ id: vn.id, label: vn.name || `#${vn.id}`, sub: vn.type || '' }))}
              placeholder="Search garage…"
            />
          </label>
          <Input
            label="Expected Return"
            type="date"
            value={maintForm.expected_return_date}
            onChange={(e) => { setMaintForm((f) => ({ ...f, expected_return_date: e.target.value })); setConflict(null); }}
          />
        </div>
      </Modal>

      {/* Maintenance Log — event detail */}
      <Modal
        open={!!logEvent}
        onClose={() => setLogEvent(null)}
        size="lg"
        title="Workshop Event"
        subtitle={logEvent ? `${[v.make, v.model].filter(Boolean).join(' ')}${v.plate_no ? ` · ${v.plate_no}` : ''}` : ''}
        footer={<Button variant="secondary" onClick={() => setLogEvent(null)}>Close</Button>}
      >
        {logEvent && (() => {
          const tone = EVENT_TONE[logEvent.event] || 'gray';
          const st = EVENT_STYLE[tone] || EVENT_STYLE.gray;
          const icon = EVENT_ICON[logEvent.event] || DEFAULT_EVENT_ICON;
          return (
            <div className="space-y-5">
              {/* headline */}
              <div className="flex items-start gap-4 rounded-2xl border border-slate-200/60 bg-slate-50/60 p-4">
                <span className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-xl ${st.soft} ${st.text}`}>
                  <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d={icon} /></svg>
                </span>
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge tone={tone}>{logEvent.event || '—'}</Badge>
                    {(logEvent.type || logEvent.severity) && (
                      <span className="rounded-lg bg-white px-2 py-0.5 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-200">{logEvent.type || logEvent.severity}</span>
                    )}
                  </div>
                  <p className="mt-1.5 text-sm text-slate-500">
                    {logEvent.date ? fmtDate(logEvent.date) : 'No date recorded'}
                    {logEvent.actual_in && logEvent.actual_in !== logEvent.date && <span className="text-slate-400"> → returned {fmtDate(logEvent.actual_in)}</span>}
                  </p>
                </div>
                {logEvent.cost != null && Number(logEvent.cost) > 0 && (
                  <div className="shrink-0 text-right">
                    <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">Cost</p>
                    <p className="text-lg font-bold text-slate-900">{aed2(logEvent.cost)}</p>
                  </div>
                )}
              </div>

              {/* the real fault — MAIN area(s) + SUP detail(s) recorded for the visit */}
              {(logEvent.main || logEvent.sup) && (
                <div className="rounded-xl bg-slate-50/70 p-4 ring-1 ring-inset ring-slate-100">
                  <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">Problem</p>
                  {logEvent.main && <p className="text-sm font-semibold text-slate-800">{logEvent.main}</p>}
                  {logEvent.sup && <p className="mt-0.5 text-sm text-slate-600">{logEvent.sup}</p>}
                </div>
              )}

              {/* facts grid */}
              <div className="grid grid-cols-1 gap-x-8 sm:grid-cols-2">
                <Field label="Event" value={logEvent.event} />
                <Field label="Date" value={logEvent.date ? fmtDate(logEvent.date) : '—'} />
                <Field label="Garage" value={logEvent.garage} />
                <Field label="Returned" value={logEvent.actual_in ? fmtDate(logEvent.actual_in) : '—'} />
                <Field label="Type" value={logEvent.type} />
                <Field label="Damage" value={logEvent.damage} />
                <Field label="Severity" value={logEvent.severity} />
                <Field label="Cost" value={logEvent.cost != null ? aed2(logEvent.cost) : '—'} />
                {logEvent.contract_id && (
                  <div className="flex justify-between gap-4 py-1.5 text-sm">
                    <span className="text-slate-500">Contract</span>
                    <Link to={`/contracts/${logEvent.contract_id}`} className="font-medium text-indigo-600 hover:text-indigo-700">#{logEvent.contract_no || logEvent.contract_id}</Link>
                  </div>
                )}
              </div>

              {/* issue tags */}
              {(logEvent.issues || logEvent.tags || []).length > 0 && (
                <div>
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Issues</p>
                  <div className="flex flex-wrap gap-1.5">
                    {(logEvent.issues || logEvent.tags).map((t) => <Badge key={t} tone="indigo">{t}</Badge>)}
                  </div>
                </div>
              )}

              {/* full notes */}
              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Workshop Notes</p>
                <div className="rounded-xl bg-slate-50/70 p-4 ring-1 ring-inset ring-slate-100">
                  <NotesList text={logEvent.notes} />
                </div>
              </div>
            </div>
          );
        })()}
      </Modal>

      {/* "How is Lifetime Net Profit calculated" — the full working behind the headline figure. */}
      <Modal
        open={bridgeOpen}
        onClose={() => setBridgeOpen(false)}
        size="xl"
        title="How Lifetime Net Profit is calculated"
        subtitle={`${[v.make, v.model].filter(Boolean).join(' ')}${v.plate_no ? ` · ${v.plate_no}` : ''}`}
        footer={<Button variant="secondary" onClick={() => setBridgeOpen(false)}>Close</Button>}
      >
        {(() => {
          const pb = stats.profit_bridge || {};
          const pc = stats.profit_contracts || [];
          return (
            <div className="space-y-6">
              {/* The gross→net chain, with every sub-sum shown. */}
              <div className="rounded-2xl border border-slate-200/60 bg-slate-50/60 p-5">
                <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">The formula</p>
                <dl className="space-y-2 text-sm">
                  <BridgeLine label="Rent billed" hint="Σ rental charges (rents_debit)" value={aed2(pb.rent_billed)} />
                  <BridgeLine label="− Discount" hint="Σ discounts given" value={`− ${aed2(pb.discount)}`} valueClass="text-slate-500" />
                  <BridgeLine label="+ Realized usage" hint="COLLECTED km / fuel / cardoo / extra-driver / CDW / GPS / co-driver" value={`+ ${aed2(pb.realized_usage)}`} valueClass="text-slate-700" />
                  <div className="!mt-2 border-t border-dashed border-slate-200 pt-2">
                    <BridgeLine label="= Gross Revenue" value={aed2(pb.gross_revenue)} labelClass="font-semibold text-slate-900" valueClass="font-semibold text-slate-900" />
                  </div>
                  <BridgeLine label="− Operating costs" hint="Sales commissions + co-driver fees" value={`− ${aed2(pb.operating_cost)}`} valueClass="text-slate-500" />
                  <BridgeLine label="− Maintenance" hint={`Workshop repairs${pb.maintenance_visits ? ` · ${num(pb.maintenance_visits)} costed visit${pb.maintenance_visits === 1 ? '' : 's'}` : ''}`} value={`− ${aed2(pb.maintenance)}`} valueClass="text-amber-600" />
                  <div className="!mt-3 border-t border-slate-300 pt-3">
                    <BridgeLine
                      label="= Lifetime Net Profit"
                      labelClass="text-base font-bold text-slate-900"
                      value={aed2(pb.net_profit)}
                      valueClass={`text-base font-bold ${Number(pb.net_profit) < 0 ? 'text-red-600' : 'text-emerald-600'}`}
                    />
                  </div>
                </dl>
                <p className="mt-3 text-xs text-slate-400">
                  Rentals counted: {num(pb.rentals)}. Maintenance is taken at the car level (workshop log) so it is never double-counted. VAT, deposits and damages are excluded.
                </p>
              </div>

              {/* Every rental contract that summed into Gross Revenue − Operating. */}
              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
                  Per-contract working ({num(pc.length)} rental{pc.length === 1 ? '' : 's'})
                </p>
                {pc.length === 0 ? (
                  <p className="rounded-xl bg-slate-50/70 p-4 text-sm text-slate-500 ring-1 ring-inset ring-slate-100">No rental contracts.</p>
                ) : (
                  <div className="max-h-[22rem] overflow-auto rounded-xl ring-1 ring-inset ring-slate-200">
                    <table className="min-w-full divide-y divide-slate-100 text-sm">
                      <thead className="sticky top-0 bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                        <tr>
                          <th className="px-3 py-2 text-left font-semibold">Contract</th>
                          <th className="px-3 py-2 text-left font-semibold">Out</th>
                          <th className="px-3 py-2 text-right font-semibold">Rent</th>
                          <th className="px-3 py-2 text-right font-semibold">Disc.</th>
                          <th className="px-3 py-2 text-right font-semibold">Usage</th>
                          <th className="px-3 py-2 text-right font-semibold">Oper.</th>
                          <th className="px-3 py-2 text-right font-semibold">Net</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-50">
                        {pc.map((c) => (
                          <tr key={c.id} className="transition-colors hover:bg-indigo-50/40">
                            <td className="px-3 py-2">
                              <Link to={`/contracts/${c.id}`} className="font-medium text-indigo-600 hover:text-indigo-700">#{c.contract_no || c.id}</Link>
                              {c.customer && <div className="text-xs text-slate-400">{c.customer}</div>}
                            </td>
                            <td className="px-3 py-2 text-slate-500">{c.out_date ? fmtDate(c.out_date) : '—'}</td>
                            <td className="px-3 py-2 text-right tabular-nums text-slate-700">{aed2(c.rent_billed)}</td>
                            <td className="px-3 py-2 text-right tabular-nums text-slate-400">{c.discount ? `− ${aed2(c.discount)}` : '—'}</td>
                            <td className="px-3 py-2 text-right tabular-nums text-slate-700">{c.realized_usage ? `+ ${aed2(c.realized_usage)}` : '—'}</td>
                            <td className="px-3 py-2 text-right tabular-nums text-slate-400">{c.operating_cost ? `− ${aed2(c.operating_cost)}` : '—'}</td>
                            <td className={`px-3 py-2 text-right tabular-nums font-semibold ${Number(c.net) < 0 ? 'text-red-600' : 'text-emerald-600'}`}>{aed2(c.net)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
                <p className="mt-2 text-xs text-slate-400">
                  Per-contract Net = Rent − Discount + Usage − Operating. These sum to Gross Revenue − Operating above; subtract car-level Maintenance to reach Lifetime Net Profit.
                </p>
              </div>
            </div>
          );
        })()}
      </Modal>
    </div>
  );
}
