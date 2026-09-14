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
import VehicleCheckpointsPanel from '../../components/vehicles/VehicleCheckpointsPanel';
import VehicleComplaintsPanel from '../../components/vehicles/VehicleComplaintsPanel';
import VehicleAccidentsPanel from '../../components/vehicles/VehicleAccidentsPanel';
import VehicleInvestigationTimeline from '../../components/vehicles/VehicleInvestigationTimeline';
import VehicleComponentsPanel from '../../components/vehicles/VehicleComponentsPanel';
import VehicleSpareKeysPanel from '../../components/vehicles/VehicleSpareKeysPanel';
import FinancialPanel from '../../components/maintenance/FinancialPanel';
import VehicleSpecSheet from '../../components/vehicles/VehicleSpecSheet';
import { usePermissions } from '../../hooks/usePermissions';
import ComponentRepeatAlert from '../../components/vehicles/ComponentRepeatAlert';
import MulkiyaCard from '../../components/vehicles/MulkiyaCard';
import VehicleWarrantyCard from '../../components/warranties/VehicleWarrantyCard';
import VehicleServiceContractCard from '../../components/warranties/VehicleServiceContractCard';
import VehicleCostIntelligence from './VehicleCostIntelligence';
import { aed2, fmtDate, fmtClock, fmtSeconds, num } from '../../lib/format';
import CompositionDonut from '../../components/ui/CompositionDonut';
import { faultTagSegments, isServiceOnlyVisit, visitsForFault } from '../../lib/faultCategories';
import { useI18n } from '../../i18n/I18nContext';
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
  // The test that never happened, because somebody decided it was not needed. Same column as the other
  // test rows — it is an answer to the same question — and the row's own words say which answer.
  sent_straight_to_garage: 'Test',
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
  // Amber, like every other "waiting on a moment rather than on a person" state. Deliberately NOT slate:
  // a deferred fault is open work the car is still carrying, not a closed chapter.
  maintenance_deferred:   { label: 'Deferred — Later',   tone: 'amber',  icon: 'Follow up' },
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

// Humanise a stage duration (seconds) into the two most significant units: "2d 3h", "4h 12m",
// "35m", "48s" — via the shared, locale-aware fmtSeconds. A running/open stage passes null and
// renders as a live ticking-style "so far" label upstream, so here we only format finished spans.
const fmtDuration = (seconds) => (seconds == null ? null : fmtSeconds(seconds));

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
  const { t } = useI18n();
  if (!journeys.length) {
    return (
      <Card className="p-10 text-center">
        <p className="text-sm text-slate-500">{t('vehicleProfile.journeys.empty')}</p>
        <p className="mt-1 text-xs text-slate-400">{t('vehicleProfile.journeys.emptyHint')}</p>
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
                  <h3 className="text-sm font-semibold text-slate-900">{t('Ticket #{id}', { id: j.ticket_id })}</h3>
                  <p className="text-xs text-slate-400">
                    {t('Opened {date}', { date: fmtDate(j.opened_at) })} · {j.stage_count === 1 ? t('1 stage') : t('{n} stages', { n: num(j.stage_count) })}
                  </p>
                </div>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                {/* All days — the whole ticket lifespan (open → close / now). */}
                {j.is_open
                  ? <Badge tone="blue">{t('Live · {d}', { d: fmtSeconds(total) })}</Badge>
                  : <Badge tone="green">{t('Closed · {d} total', { d: fmtSeconds(total) })}</Badge>}
                {/* Days in maintenance — only the time actually spent at the workshop. */}
                <Badge tone="amber">{t('In maintenance · {d}', { d: fmtSeconds(shopSeconds) })}</Badge>
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
                      title={`${t(label)} · ${fmtDuration(stg.seconds) || t('in progress')}`}
                    />
                  );
                })}
              </div>
              <p className="mb-4 text-end text-[11px] text-slate-400">{t('time in each stage (proportional)')}</p>

              {/* Stage-by-stage detail — a vertical rail, the current stage still ticking */}
              <ol className="relative space-y-3 border-s border-slate-200 ps-5">
                {j.stages.map((stg, i) => {
                  const { label, tone, style } = stageMeta(stg);
                  const isCurrent = j.is_open && i === j.stages.length - 1;
                  const dur = fmtDuration(stg.seconds);
                  return (
                    <li key={i} className="relative">
                      <span className={`absolute -start-[27px] mt-1 flex h-3.5 w-3.5 items-center justify-center rounded-full ring-4 ring-white ${style.dot}`} />
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                          <Badge tone={tone}>{t(label)}</Badge>
                          {isCurrent && <span className="text-xs font-medium text-blue-600">{t('vehicleProfile.journeys.currentStage')}</span>}
                        </div>
                        <span className={`text-xs font-semibold tabular-nums ${isCurrent ? 'text-blue-600' : 'text-slate-600'}`}>
                          {isCurrent ? t('{d} so far', { d: dur || fmtSeconds(0) }) : (dur || '—')}
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
  const { t } = useI18n();
  const lines = String(text || '').split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  if (lines.length === 0) return <span className="text-sm text-slate-400">{t('vehicleProfile.notes.none')}</span>;
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

// OfficeManager's contract letters in the words the office uses for them. Anything else keeps its
// raw letter rather than being hidden behind an "Other" bucket.
const CONTRACT_TYPE_LABEL = { C: 'Rental', U: 'Maintenance', R: 'Booking' };

// Top-level tabs for the profile. Keys are also the ?tab= URL value (deep-linkable / shareable).
// 'timeline' (the old Maintenance Log feed) was merged into 'activity' — the one Timeline tab. It stays
// in the key list so old ?tab=timeline links still resolve; changeTab() redirects them to 'activity'.
const TAB_KEYS = ['overview', 'plate', 'visits', 'components', 'journey', 'activity', 'checkpoints', 'complaints', 'accidents', 'financials', 'media'];
const TAB_ALIASES = { timeline: 'activity' };
const resolveTab = (key) => TAB_ALIASES[key] || key;

// Per-tab "Data Origin" line — the standing traceability rule: every surface names where its
// numbers come from, so a manager on any tab still sees the source (no black boxes).
const TAB_ORIGIN = {
  overview: 'Live figures derived from OfficeManager contracts via RealProfitService. Outstanding fines from the F RTA source. Cost of Ownership adds the purchase price from the FASTER Asset sheet.',
  maintenance: 'Health, findings & workflow tickets from the in-app maintenance workflow. Service log & tyre brand/DOT/tread/warranty from the ticket line items.',
  visits: 'Each maintenance visit is an OfficeManager type-U contract, enriched with its workshop events from the N-Maintenance sheet log (garage, issues, priority, cost).',
  journey: 'The same in-app maintenance-workflow audit trail (vehicle event log), reshaped per ticket: each workflow_status transition marks a stage, timed to the next transition — so you see every stage the car went through and how long it sat in each.',
  checkpoints: 'Workshop progress updates filed by the responsible follow-up owners (Waleed/Abdullah, or a ticket’s assigned users): the revised completion date, the reason it moved, a progress note and photos/videos. The On Schedule / Overdue status is derived automatically from the promised date; reminders escalate before a job goes overdue.',
  activity: 'The car’s whole history as an investigation tool — search, filters, KPIs, grouping and sorting over every source unified: the N-Maintenance sheet workshop visits (click one for its full record — garage, cost, issues, notes), the maintenance-workflow audit trail (inspections, dispatch, repair, re-inspection, parts, approvals & follow-ups), the logistics movement log, and inspection records. Every row carries who acted and when; nothing is editable, and the exact filtered view is captured in the URL to share.',
  financials: 'Every contract OfficeManager holds against this car — rental (C), maintenance (U) and booking (R) — listed newest first and filterable by type. The money on each line (debit, credit, balance) is the contract’s own billing; the cost analysis below it is reverse-engineered from that billing via RealProfitService: rent − discount + realized usage − operating − car-level maintenance.',
  media: 'Pre/post condition & odometer photos captured during the maintenance workflow (inspection & garage steps).',
  accidents: 'Accident cases opened against this car — a first-class workflow, not a maintenance note. Each row names WHO HAD THE CAR at the moment of the crash, frozen when the accident was reported: the customer, the rental contract and the rental window are copied onto the case then and never recomputed, so a case still names the right person after that contract closes and the car is let again. The stage, the police-report status and the liability verdict are the case’s own state — liability is never inferred from who was driving, and starts undecided until a named person rules on it. The rental-hold banner reads the same authority the booking gate reads (ContractEligibilityService), so it can never disagree with what happens at the counter. Repairs are ordinary maintenance tickets parented to the case; the money lives on the case as separate estimate / approved / actual / paid figures.',
  components: 'The vehicle’s physical configuration, DERIVED from the maintenance workflow — never typed in. A component appears here through one of two doors, and the row says which. PURCHASED: a ticket reached its install step (part purchased → received → installed); identity, supplier, cost, warranty and odometer are FACTS copied from the purchase order. REPORTED: a technician recorded “replaced X” at repair capture with no purchase behind it — the part is genuinely fitted, but there is no paperwork, so cost and supplier are blank rather than zero, and no warranty is claimed. Either way the install retires the part it replaced and writes both to the timeline. Age, life-used, warranty standing and cost/km are DERIVED at read time. Money figures count only the parts whose cost is known, and say how many that is. Consumables refreshed by routine servicing (oil, filters bundled with an oil change) are merged in from the service log and tagged “Service”, because they are performed work rather than tracked assets. SPARE KEYS are the same asset ledger read separately, because they are the one part whose two counts routinely differ: “keys on this car” is what the ledger holds today, while “requirements raised” and “keys ever received” come from the spare-key requirements and the purchases behind them. History imported from the “NEED SPARE KEY” sheet records the requirement and its dates only — it is not evidence that a key exists, so it never adds to the current count.',
};

// A muted provenance caption shown at the foot of each tab. The English paragraphs stay in
// TAB_ORIGIN above (they document each tab beside its key); the Arabic is in ar.vehicleProfile.origin.
function DataOrigin({ tab }) {
  const { t, tf } = useI18n();
  const text = TAB_ORIGIN[tab];
  if (!text) return null;
  return (
    <p className="px-1 pt-1 text-xs leading-relaxed text-slate-400">
      <span className="font-semibold text-slate-500">{t('vehicleProfile.dataOrigin')}</span> · {tf(`vehicleProfile.origin.${tab}`, text)}
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
      <span className="text-end font-medium text-slate-900">{value ?? '—'}</span>
    </div>
  );
}

// One line of the Lifetime Net Profit working: a label (+ optional hint) and a right-aligned amount.
function BridgeLine({ label, hint, value, labelClass = 'text-slate-600', valueClass = 'text-slate-900' }) {
  return (
    <div className="flex items-baseline justify-between gap-4">
      <dt className={labelClass}>
        {label}
        {hint && <span className="ms-2 hidden text-xs font-normal text-slate-400 sm:inline">{hint}</span>}
      </dt>
      <dd className={`tabular-nums font-medium ${valueClass}`}>{value}</dd>
    </div>
  );
}

// A quiet "fact" chip for the flat header.
// Count-up telemetry tile on the dossier command deck.
// `note` carries the tile's Data Origin (e.g. which source supplied the mileage) so the number
// is never a bare figure the reader has to take on faith.
function HeroStat({ label, value, unit, note }) {
  const n = useCountUp(Number(value) || 0, 1400);
  return (
    <div className="vhero-stat">
      <div className="lbl">{label}</div>
      <div className="num">
        {num(Math.round(n))}
        {unit && <span className="unit">{unit}</span>}
      </div>
      {note && <div className="vhero-stat-note">{note}</div>}
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
  const { t, tf, tp } = useI18n();
  const { id } = useParams();
  // Stating what a car takes is a claim about the vehicle, so it sits behind the same permission
  // as any other change to it. Everyone else still SEES the spec sheet — knowing which oil the car
  // needs is exactly the thing a driver or a buyer should be able to look up.
  const { can } = usePermissions();
  const canManageVehicle = can('vehicles.manage');
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
  const [showAllContracts, setShowAllContracts] = useState(false); // collapse the Contract History table by default
  const [contractType, setContractType] = useState('all'); // Contract History type filter: all | C | U | R
  const [bridgeOpen, setBridgeOpen] = useState(false); // "how is Lifetime Net Profit calculated" drill-down
  // Fault-distribution drill-down: a slice of the hero donut, opened to the maintenance contracts
  // that fault was recorded on. { label, keys } — `keys` are RAW fault labels, never the localized ones.
  const [faultDrill, setFaultDrill] = useState(null);

  const VISITS_PREVIEW = 5;    // rows shown before "Show all"
  const CONTRACTS_PREVIEW = 5; // contract rows shown before "Show all"

  const toggleVisit = (vid) => setOpenVisits((o) => ({ ...o, [vid]: !o[vid] }));

  // Deep-dive: arriving from Maintenance Foresight with ?event=<id> highlights that exact
  // workshop event (the worst-case offender) so the user can see what actually happened.
  const [searchParams, setSearchParams] = useSearchParams();
  const highlightEventId = searchParams.get('event');
  // Arriving from Fleet Utilization with ?focus=maintenance jumps straight to the Timeline.
  const focus = searchParams.get('focus');

  // Which tab is showing. Deep-links win: an ?event= (Foresight) or ?focus=maintenance (Fleet
  // Utilization) link lands on the Timeline tab — the single unified history — so the existing
  // scroll-into-view still finds its target; otherwise honour ?tab=, else default to Overview.
  const deepLinksTimeline = !!highlightEventId || focus === 'maintenance';
  const tabParam = resolveTab(searchParams.get('tab'));
  const [activeTab, setActiveTab] = useState(
    deepLinksTimeline ? 'activity' : (TAB_KEYS.includes(tabParam) ? tabParam : 'overview')
  );

  // Switch tab + reflect it in the URL (shareable/back-button), preserving any other params.
  const changeTab = (key) => {
    const tab = resolveTab(key);
    setActiveTab(tab);
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      next.set('tab', tab);
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

  // Scroll a deep-linked anchor into view and KEEP it there. Two races make a one-shot setTimeout
  // unreliable here: the Timeline renders the legacy rows immediately but only fetches its own activity
  // feed afterwards, and that second render rebuilds the whole list — throwing away any scroll we'd
  // already done. So poll: re-scroll whenever the anchor is off-screen, and only stop once it has
  // stayed put for a few ticks. Gives up after ~10s rather than spinning on an id this car lacks.
  const scrollToAnchor = useCallback((elementId, block) => {
    let tries = 0;
    let settled = 0;
    const tick = setInterval(() => {
      const el = document.getElementById(elementId);
      if (el) {
        const { top } = el.getBoundingClientRect();
        if (top > 0 && top < window.innerHeight * 0.8) {
          if (++settled >= 3) clearInterval(tick);
        } else {
          settled = 0;
          // 'auto', NOT 'smooth': re-issuing a smooth scroll each tick cancels and restarts the
          // in-flight animation, so a long jump never actually advances. Instant lands every time.
          el.scrollIntoView({ block });
        }
      }
      if (++tries > 40) clearInterval(tick);
    }, 250);
    return () => clearInterval(tick);
  }, []);

  // When deep-diving to a specific event, make sure the Timeline tab is showing and scroll the row
  // into view. The row itself renders the red "investigate" highlight.
  useEffect(() => {
    if (!highlightEventId || !data) return undefined;
    setActiveTab('activity');
    return scrollToAnchor(`log-event-${highlightEventId}`, 'center');
  }, [highlightEventId, data, scrollToAnchor]);

  useEffect(() => {
    if (focus !== 'maintenance' || !data) return undefined;
    setActiveTab('activity');
    return scrollToAnchor('maintenance-log', 'start');
  }, [focus, data, scrollToAnchor]);

  // Arriving from the Recurring Faults analytics ("Cars that keep coming back") with
  // ?focus=repeat-faults: the ranking answers WHICH car, this panel answers WHICH faults — so land
  // on the Overview tab and scroll the "Keeps breaking down" panel into view.
  useEffect(() => {
    if (focus !== 'repeat-faults' || !data) return undefined;
    setActiveTab('overview');
    return scrollToAnchor('repeat-faults', 'start');
  }, [focus, data, scrollToAnchor]);

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
      toast.success(t('Maintenance ticket #{id} opened — perform the service on the ticket', { id: ticket?.id }));
      if (ticket?.url) navigate(ticket.url);
    } catch (e) {
      toast.error(e?.response?.data?.message || t('Could not open a maintenance ticket'));
    } finally {
      setBusy(false);
    }
  }, [id, navigate, toast, t]);

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
      toast.success(t('Car sent to maintenance'));
      closeMaint();
      setMaintForm({ vendor_id: '', expected_return_date: '' });
      reload();
    } catch (e) {
      const res = e.response;
      if (res?.status === 409 && res.data?.data?.conflict) {
        // car is reserved and the timing clashes — show it and let the operator override
        setConflict({ message: res.data.message, reservations: res.data.data.reservations || [] });
      } else {
        toast.error(res?.data?.message || t('Could not update status'));
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
        <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error || t('vehicleProfile.notFound')}</div>
        <Link to="/vehicles" className="mt-4 inline-block text-sm font-medium text-indigo-600">{t('vehicleProfile.backToVehicles')}</Link>
      </div>
    );
  }

  const v = data.vehicle;
  const reg = data.registration;
  const av = data.availability || {};
  const contracts = data.contracts || [];
  // Newest first — sort by the most recent date on the contract (out, falling back to in).
  const contractTime = (c) => { const ms = new Date(c.out_date || c.in_date || 0).getTime(); return isNaN(ms) ? 0 : ms; };
  const sortedContracts = [...contracts].sort((a, b) => contractTime(b) - contractTime(a));
  // Contract-type filter — only offer the types this vehicle actually has. Any type OfficeManager
  // sends that we have no name for still gets its own chip (keyed by the raw letter), so a contract
  // can never be filtered out of existence by a label we forgot to add.
  const contractTypeCounts = contracts.reduce((acc, c) => {
    if (!c.contract_type) return acc; // untyped rows stay reachable under "All" rather than under a "null" chip
    acc[c.contract_type] = (acc[c.contract_type] || 0) + 1;
    return acc;
  }, {});
  const KNOWN_CONTRACT_TYPES = ['C', 'U', 'R'];
  const contractFilters = [
    { key: 'all', label: t('All'), count: contracts.length },
    ...[...KNOWN_CONTRACT_TYPES, ...Object.keys(contractTypeCounts).filter((k) => !KNOWN_CONTRACT_TYPES.includes(k))]
      .map((key) => ({ key, label: t(CONTRACT_TYPE_LABEL[key] || key), count: contractTypeCounts[key] || 0 }))
      .filter((f) => f.count > 0),
  ];
  const filteredContracts = contractType === 'all' ? sortedContracts : sortedContracts.filter((c) => c.contract_type === contractType);
  const maintenance = data.maintenance || [];
  // Per-fault distribution for the hero telemetry card — each individual fault by its share of every
  // fault ever logged on this car (slices sum to 100%). Same source/shape as the Overview donut.
  // FAULTS ONLY: planned services (oil & filter, periodic maintenance, cleaning) are a different kind
  // of event and are counted out — see faultCategories.visitFaults. What was removed is stated on the
  // card rather than silently dropped, so the chart's scope is readable off the chart itself.
  const faultSegments = faultTagSegments(maintenance, { top: 8, tf, tp });
  const serviceOnlyVisits = maintenance.filter(isServiceOnlyVisit).length;
  const maintenanceLog = data.maintenance_log || [];
  // One unified history: legacy sheet workshop events + the manual workflow audit trail + follow-ups.
  const timeline = data.timeline || maintenanceLog;
  // Per-ticket stage meter — every workflow stage the car passed through + time in each.
  const journeys = data.workflow_journeys || [];
  const analytics = data.maintenance_analytics || [];
  const stats = data.stats || {};

  // ── Command-deck telemetry ── everything below is derived from the payload already loaded.
  const totalFaults = faultSegments.reduce((a, s) => a + (s.value || 0), 0);
  // Clicking a slice answers "which visits is that fault in?" — every maintenance contract the
  // label was recorded on. A folded "Other" slice drills to all the types it hides at once; its
  // legend row still expands, so each individual type inside it can be opened on its own.
  const openFaultDrill = (seg) => {
    const kids = Array.isArray(seg.children) ? seg.children : [];
    setFaultDrill({
      label: seg.label,
      keys: kids.length ? kids.map((k) => k.key) : [seg.key],
    });
  };
  // Resolved live off the loaded payload — the drill-down is a view of the same visits the donut
  // counted, never a second fetch. Newest visit first, matching the Maintenance History table.
  const faultDrillVisits = faultDrill
    ? visitsForFault(maintenance, faultDrill.keys)
        .slice()
        .sort((a, b) => String(b.date || '').localeCompare(String(a.date || '')))
    : [];
  // Health LEDs — same thresholds as the Overview health card (30-day amber window).
  const ledFromDays = (label, d) => ({
    label,
    status: d == null ? 'unknown' : d < 0 ? 'bad' : d < 30 ? 'warn' : 'good',
    detail: d == null ? t('no record')
      : d < 0 ? t('expired {n}d ago', { n: num(Math.abs(d)) })
      : t('{n}d left', { n: num(d) }),
  });
  const svcStatus = v.service_status;
  const heroLeds = [
    ledFromDays(t('Registration'), reg ? reg.registration_days_left : null),
    ledFromDays(t('Insurance'), reg ? reg.insurance_days_left : null),
    {
      label: t('Service'),
      status: !svcStatus || svcStatus.status === 'no_data' ? 'unknown' : svcStatus.status === 'service_due' ? 'bad' : 'good',
      detail: !svcStatus || svcStatus.status === 'no_data' ? t('no data')
        : svcStatus.status === 'service_due' ? t('{n} km overdue', { n: num(svcStatus.overdue_km) }) : t('{n} km left', { n: num(svcStatus.remaining) }),
    },
  ];
  // Latest-activity ticker — newest unified-timeline entry, named with the same stage vocabulary.
  const lastEvent = timeline[0] || null;
  const lastEventLabel = !lastEvent ? null
    : lastEvent.kind === 'workflow'
      ? t(WF_STATUS_META[lastEvent.workflow_status]?.label || wfStage(lastEvent.event_type))
      : (lastEvent.event || t('Workshop event'));

  return (
    <div className="opx py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        {/* Back */}
        <Link to="/vehicles" className="inline-flex items-center gap-1 text-sm font-medium text-slate-500 transition hover:text-slate-700">
          <svg className="h-4 w-4 rtl:-scale-x-100" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M15 19l-7-7 7-7" /></svg>
          {t('Vehicles')}
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
                    <span style={{ fontSize: 10, fontWeight: 600, letterSpacing: '.2em', textTransform: 'uppercase', color: '#7f92b8' }}>{t('vehicleProfile.hero.dossier')}</span>
                    <span className="vhero-live"><span className="dot" />{t('LIVE')}</span>
                  </div>
                  <h1 className="mt-1.5 font-display text-2xl font-bold tracking-tight text-white sm:text-3xl" style={{ letterSpacing: '-.02em', textShadow: '0 2px 24px rgba(34,211,238,.25)' }}>
                    {[v.make, v.model].filter(Boolean).join(' ') || t('Vehicle')}
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
                    {v.for_sale && <Badge tone="amber" dot>{t('vehicleProfile.hero.forSale')}</Badge>}
                  </div>
                </div>
              </div>

              {/* Telemetry tiles — the numbers count up on load */}
              <div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3">
                <HeroStat
                  label={t('vehicleProfile.hero.odometer')}
                  value={v.odometer}
                  unit="km"
                  note={v.odometer_source ? t(`vehicleProfile.hero.odoSource.${v.odometer_source}`) : null}
                />
                <HeroStat label={t('vehicleProfile.hero.visits')} value={maintenance.length} />
                <HeroStat label={t('vehicleProfile.hero.faults')} value={totalFaults} />
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
                    {t('vehicleProfile.hero.latestActivity')} <span className="what">{lastEventLabel}</span>
                    {lastEvent.garage ? <> {t('vehicleProfile.hero.at')} <span className="what">{lastEvent.garage}</span></> : null}
                    {lastEvent.date ? <span style={{ color: '#7f92b8' }}> · {fmtDate(lastEvent.date)}</span> : null}
                  </span>
                </div>
              )}
            </div>

            {/* Fault distribution — frosted glass telemetry card */}
            <div className="vhero-glass w-full shrink-0 p-5 lg:w-96">
              <div className="text-[10px] font-semibold uppercase text-slate-400" style={{ letterSpacing: '.14em', marginBottom: 4 }}>{t('vehicleProfile.faults.title')}</div>
              <p className="mb-1 text-xs text-slate-500">{t('vehicleProfile.faults.subtitle')}</p>
              <p className="mb-4 text-[11px] text-slate-400">
                {t('vehicleProfile.faults.faultsOnly')}
                {serviceOnlyVisits > 0 && <> · {tp('vehicleProfile.faults.serviceExcluded', serviceOnlyVisits, { n: num(serviceOnlyVisits) })}</>}
              </p>
              {faultSegments.length ? (
                <CompositionDonut
                  className="!flex-col !gap-5"
                  segments={faultSegments}
                  centerLabel={t('Faults')}
                  size={168}
                  stroke={24}
                  format={(n) => num(Math.round(n))}
                  onSelect={openFaultDrill}
                />
              ) : (
                <div className="flex h-[168px] items-center justify-center text-xs text-slate-400">
                  {t('No fault history recorded yet.')}
                </div>
              )}
              {faultSegments.length > 0 && (
                <p className="mt-3 text-[11px] text-slate-400">{t('vehicleProfile.faults.drillHint')}</p>
              )}
              {/*
                ONE DOOR. This card used to offer three ways out — a printable dossier of what the car
                IS, a system dashboard of what it has SUFFERED (which opened on the engine, so a reader
                had to choose a system before the page would tell them anything), and later both at
                once. The Vehicle Report is all of it: the ranked problem list first, the per-system
                evidence one click in, and the dossier PDF as a button ON that page — which is where a
                reader deciding what to print already is.
              */}
              <div className="mt-5">
                <Link to={`/reports/vehicle/${v.id}`} className="block">
                  <Button variant="secondary" className="w-full justify-center">
                    <Icon.Activity className="h-4 w-4" /> {t('Vehicle Report')}
                  </Button>
                </Link>
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
            ariaLabel={t('Vehicle profile sections')}
            tabs={[
              { key: 'overview', label: t('Overview') },
              // Plate History is a first-class tab, but only when this plate was actually re-issued
              // across more than one physical vehicle (self-hides for a single-holder plate).
              ...(plateReused ? [{ key: 'plate', label: t('Plate History') }] : []),
              { key: 'financials', label: t('Contracts'), badge: num(contracts.length) },
              { key: 'visits', label: t('Visits'), badge: num(maintenance.length) },
              // The rolling-asset view: what is physically fitted to this car right now. No badge —
              // the count comes from the components API, which the profile payload does not carry.
              { key: 'components', label: t('Installed Components') },
              // One unified history: the sheet Maintenance Log and the workflow audit trail live here.
              // No badge — the profile only knows the legacy row count; the panel itself reports the
              // true merged total ("Showing N of M events") once the activity feed lands.
              { key: 'activity', label: t('Timeline') },
              { key: 'journey', label: t('Journey'), badge: num(journeys.length) },
              { key: 'checkpoints', label: t('Progress') },
              { key: 'complaints', label: t('Complaints') },
              // Its own tab rather than a section of Complaints: what a customer said about the car
              // and what happened TO the car are different kinds of fact, and an unresolved accident
              // is the one that stops it being let.
              { key: 'accidents', label: t('Accidents') },
              { key: 'media', label: t('Media') },
            ]}
          />
        </div>

        {/* ── OVERVIEW ─────────────────────────────────────────────── core KPIs at a glance */}
        {activeTab === 'overview' && (
        <div role="tabpanel" id="panel-overview" aria-labelledby="tab-overview" className="space-y-6">
        {/* Repeat replacements — the one thing about this car's hardware a manager must see WITHOUT
            opening a tab: the same part has been fitted here more than once. Renders nothing when
            there is no repeat, so it costs no space on a healthy car. */}
        <ComponentRepeatAlert vehicleId={id} onOpenComponents={() => changeTab('components')} />

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

        {/* The Mulkiya scan — the car's UAE Vehicle Licence, add/changeable. Sits directly under the
            Registration & Insurance block above so the dates and the document that carries them read
            together. Self-fetching (the scan is not in the profile payload). */}
        <MulkiyaCard vehicleId={id} plateHint={v?.plate_display || v?.plate_no} />

        {/* WARRANTY — "before we spend money on this car, could the manufacturer be responsible?".
            Placed beside the registration documents because it is the same kind of fact: a piece of
            paper that changes what we are allowed to do with the car. The requirement was that the
            status be visible WITHOUT opening another page, and that is not a convenience — the moment
            somebody decides to spend on a car is the moment they are looking at it, and a warranty
            one navigation away is a warranty nobody checks. Self-fetching, like the Mulkiya. */}
        <VehicleWarrantyCard vehicleId={id} />

        {/* The prepaid servicing bought with the car — a DIFFERENT promise from the warranty above:
            an allowance of scheduled work that is consumed, not a commitment to fix what breaks.
            Beside it, because a car routinely has one without the other. */}
        <VehicleServiceContractCard vehicleId={id} />

        {/* Financial Performance deep-dive — lifetime revenue vs. every cost this car has
            incurred: ROI ring + interactive cost-composition donut (drill-through).
            Hidden for now (set the leading `false &&` back to just SHOW_FINANCIALS to restore). */}
        {false && SHOW_FINANCIALS && (
          <div className="space-y-2">
            <FinancialsHero vehicle={v} stats={stats} onDrill={drillFinancial} />
            {!stats.is_new && (
              <button
                type="button"
                onClick={() => setBridgeOpen(true)}
                className="text-sm font-medium text-indigo-600 transition hover:text-indigo-700"
              >
                {t('View profit breakdown')} <span className="rtl:-scale-x-100 inline-block">→</span>
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
          title={t('vehicleProfile.history.title')}
          subtitle={t('vehicleProfile.history.subtitle')}
          actions={<Badge tone="gray">{tp('vehicleProfile.history.visitCount', maintenance.length, { n: num(maintenance.length) })}</Badge>}
        >
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm stagger-rows">
              <thead className="bg-slate-50/90">
                <tr className="text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <th className="whitespace-nowrap border-b border-slate-200 px-6 py-3">{t('vehicleProfile.history.cols.visit')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-6 py-3">{t('vehicleProfile.history.cols.outIn')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-6 py-3">{t('vehicleProfile.history.cols.priority')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-6 py-3">{t('vehicleProfile.history.cols.status')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-6 py-3">{t('vehicleProfile.history.cols.garage')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-6 py-3">{t('vehicleProfile.history.cols.issues')}</th>
                  {SHOW_FINANCIALS && <th className="whitespace-nowrap border-b border-slate-200 px-6 py-3 text-end">{t('vehicleProfile.history.cols.totalCost')}</th>}
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
                          <Badge tone={p.tone} dot title={m.reason || ''}>{t(p.label)}</Badge>
                        </td>
                        <td className="border-b border-slate-100 px-6 py-3.5">
                          {m.stage ? <Badge tone={EVENT_TONE[m.stage] || 'gray'}>{m.stage}</Badge> : <span className="text-xs text-slate-300">—</span>}
                          {m.event_count > 1 && <span className="ms-1 text-xs text-slate-400">×{m.event_count}</span>}
                        </td>
                        <td className="border-b border-slate-100 px-6 py-3.5 text-slate-700">{m.garage || '—'}</td>
                        <td className="border-b border-slate-100 px-6 py-3.5">
                          <div className="flex flex-wrap gap-1">
                            {(m.tags || []).slice(0, 4).map((tag) => <Badge key={tag} tone="indigo">{tag}</Badge>)}
                            {(m.tags || []).length > 4 && <span className="text-xs text-slate-400">+{m.tags.length - 4}</span>}
                            {(!m.tags || m.tags.length === 0) && <span className="text-xs text-slate-300">—</span>}
                          </div>
                        </td>
                        {SHOW_FINANCIALS && <td className={`border-b border-slate-100 px-6 py-3.5 text-end tabular-nums ${Number(m.total) > 0 ? 'font-semibold text-slate-900' : 'text-slate-300'}`}>{aed2(m.total)}</td>}
                      </tr>
                      {open && expandable && (
                        <tr className="bg-slate-50/60">
                          <td colSpan={SHOW_FINANCIALS ? 7 : 6} className="px-6 py-4">
                            <div className="space-y-2">
                              <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">{t('vehicleProfile.history.eventsForVisit')}</p>
                              {events.map((e) => (
                                <div key={e.id} className="flex flex-wrap items-start gap-x-4 gap-y-1 rounded-xl bg-white px-4 py-2.5 text-sm shadow-soft ring-1 ring-inset ring-slate-100">
                                  <Badge tone={EVENT_TONE[e.event] || 'gray'}>{e.event || '—'}</Badge>
                                  <span className="text-slate-500">
                                    {e.date ? fmtDate(e.date) : t('No date')}
                                    {e.actual_in && e.actual_in !== e.date && <span className="text-slate-400"> {t('→ returned {date}', { date: fmtDate(e.actual_in) })}</span>}
                                  </span>
                                  {e.garage && <span className="font-medium text-slate-600">{e.garage}</span>}
                                  {e.type && <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{e.type}</span>}
                                  {(e.issues || []).map((tag) => <Badge key={tag} tone="indigo">{tag}</Badge>)}
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
                  <tr><td colSpan={SHOW_FINANCIALS ? 7 : 6} className="px-6 py-12 text-center text-slate-400">{t('vehicleProfile.history.empty')}</td></tr>
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
                {showAllVisits ? t('Show less') : t('Show all {n} visits', { n: num(maintenance.length) })}
                <svg className={`h-4 w-4 transition-transform ${showAllVisits ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M19 9l-7 7-7-7" /></svg>
              </button>
            </div>
          )}
        </SectionCard>

        <DataOrigin tab="visits" />
        </div>
        )}


        {/* ── JOURNEY ─────────── per-ticket stage meter: every stage the car went through + time in each */}
        {activeTab === 'journey' && (
        <div role="tabpanel" id="panel-journey" aria-labelledby="tab-journey" className="space-y-6">
          <WorkflowJourneys journeys={journeys} />
          <DataOrigin tab="journey" />
        </div>
        )}

        {/* ── INSTALLED COMPONENTS ─── the car as a ROLLING ASSET: every part fitted to it today, plus
            the full replacement history of each slot. Read-only by design — the maintenance workflow is
            the only thing that can put a component on a car, so there is no "Add Component" control. */}
        {activeTab === 'components' && (
        <div role="tabpanel" id="panel-components" aria-labelledby="tab-components" className="space-y-6">
          {/* WHAT THE CAR TAKES, above what is fitted to it. The order is deliberate: the spec
              sheet is the standard and the components list is the reality, and a person checking
              whether the right battery is on the car needs the standard first. This one IS
              writable — unlike the panel below it, what a car takes is knowledge somebody holds
              rather than an event the workflow can derive. */}
          <VehicleSpecSheet vehicleId={id} canEdit={canManageVehicle} />
          {/* Spare keys sit above the general components list because "does this car have a spare
              key?" is asked far more often than any other question about its hardware — and because
              it is the one part whose CURRENT count and HISTORICAL count routinely differ, which
              needs its own explanation rather than a row in a table. */}
          <VehicleSpareKeysPanel vehicleId={id} />
          <VehicleComponentsPanel vehicleId={id} />
          {/* What the accounting system knows about THIS car's costs — fuel, washes, its registration
              renewal and the fares taken to move it, alongside the repairs. Here rather than on a
              finance page for the same reason it sits inside the maintenance ticket: the person asking
              "why has this not reached Odoo?" is already looking at the car. Renders nothing for a user
              without financial.view, and nothing when the car has raised no obligation. */}
          <FinancialPanel vehicleId={id} />
          <DataOrigin tab="components" />
        </div>
        )}

        {/* ── TIMELINE ─── the car's FULL history in one place: the sheet workshop log (the old separate
            "Maintenance Log" tab) folded into the workflow/logistics/inspection audit trail, with search,
            filters, KPIs and grouping over the lot. Clicking a workshop row opens its detail drawer. */}
        {activeTab === 'activity' && (
        <div role="tabpanel" id="panel-activity" aria-labelledby="tab-activity" className="space-y-6">
          <VehicleInvestigationTimeline
            vehicleId={id}
            legacyTimeline={timeline}
            onOpenEvent={setLogEvent}
            highlightEventId={highlightEventId}
          />
          <DataOrigin tab="activity" />
        </div>
        )}

        {/* ── CONTRACTS ────────────────────────── every contract this car has held, filterable by
            type (rental / maintenance / booking), then the maintenance cost analysis behind them. */}
        {activeTab === 'financials' && (
        <div role="tabpanel" id="panel-financials" aria-labelledby="tab-financials" className="space-y-6">
        {/* What this car costs to run — the /cost-intelligence figures for THIS car, each shown against
            the fleet's own, so "AED 0.42/km" reads as good or bad without leaving the page. */}
        <VehicleCostIntelligence vehicleId={id} />

        {/* Contract history */}
        <SectionCard
          id="contract-history"
          className="scroll-mt-28"
          title={t('vehicleProfile.contracts.title')}
          actions={(
            <div className="flex items-center gap-3">
              <Badge tone="gray">{t('vehicleProfile.contracts.total', { n: num(contracts.length) })}</Badge>
              {/* Type filter — pick which kind of contract you want to read. Always present so the
                  choice is visible even on a car that has only ever held one type. */}
              {contractFilters.length > 1 && (
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
            empty={contracts.length === 0 ? t('No contracts for this vehicle.') : t('No contracts of this type.')}
            highlightRow={(c) => !c.in_date}
            columns={[
              {
                key: 'contract', header: t('Contract'), cellClass: 'font-medium',
                render: (c) => <Link to={`/contracts/${c.id}`} className="text-indigo-600 hover:text-indigo-700">#{c.contract_no || c.id}</Link>,
              },
              {
                key: 'customer', header: t('Customer'),
                render: (c) => c.customer_id
                  ? <Link to={`/customers/${c.customer_id}`} className="text-indigo-600 hover:text-indigo-700">{c.customer || `#${c.customer_id}`}</Link>
                  : <span className="text-slate-400">—</span>,
              },
              { key: 'type', header: t('Type'), render: (c) => <ContractTypeBadge type={c.contract_type} /> },
              { key: 'state', header: t('State'), render: (c) => <ContractStateBadge state={c.state} /> },
              { key: 'out', header: t('Out'), cellClass: 'text-slate-500', render: (c) => fmtDate(c.out_date) },
              { key: 'in', header: t('In'), cellClass: 'text-slate-500', render: (c) => fmtDate(c.in_date) },
              { key: 'debit', header: t('Debit'), align: 'right', cellClass: 'tabular-nums text-slate-600', render: (c) => aed2(c.debit) },
              { key: 'credit', header: t('Credit'), align: 'right', cellClass: 'tabular-nums text-slate-600', render: (c) => aed2(c.credit) },
              {
                key: 'balance', header: t('Balance'), align: 'right',
                tooltip: t('Debit − credit on the contract. Red = customer owes, green = credit due to customer.'),
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
                {showAllContracts ? t('Show less') : t('Show all {n} contracts', { n: num(filteredContracts.length) })}
                <svg className={`h-4 w-4 transition-transform ${showAllContracts ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M19 9l-7 7-7-7" /></svg>
              </button>
            </div>
          )}
        </SectionCard>
        {/* Cost analysis — per-service price trend & vs-fleet comparison */}
        {analytics.length > 0 && (
          <SectionCard
            title={t('vehicleProfile.cost.title')}
            subtitle={t('vehicleProfile.cost.subtitle')}
          >
            <DataTable
              rows={analytics}
              rowKey={(s) => s.service}
              columns={[
                { key: 'service', header: t('Service'), cellClass: 'font-medium text-slate-900', render: (s) => s.service },
                { key: 'visits', header: t('Visits'), align: 'center', cellClass: 'text-slate-500', render: (s) => s.visits },
                { key: 'latest', header: t('Latest'), align: 'right', cellClass: 'tabular-nums font-medium text-slate-900', render: (s) => aed2(s.latest_cost) },
                {
                  key: 'trend', header: t('Trend (vs previous)'), align: 'right',
                  tooltip: t('Change from the previous recorded price for this service. Red = more expensive, green = cheaper.'),
                  cellClass: 'tabular-nums font-medium',
                  render: (s) => {
                    const arrow = s.trend === 'up' ? '▲' : s.trend === 'down' ? '▼' : '–';
                    const tone = s.trend === 'up' ? 'text-red-600' : s.trend === 'down' ? 'text-emerald-600' : 'text-slate-400';
                    return <span className={tone}>{s.delta != null ? `${arrow} ${aed2(Math.abs(s.delta))}` : '—'}</span>;
                  },
                },
                { key: 'avg', header: t('This car avg'), align: 'right', cellClass: 'tabular-nums text-slate-700', render: (s) => aed2(s.avg_cost) },
                {
                  key: 'fleet', header: t('Fleet avg'), align: 'right',
                  tooltip: t('Average cost of this service across the whole fleet — to spot a car being over- or under-charged.'),
                  cellClass: 'tabular-nums text-slate-700',
                  render: (s) => {
                    const vsFleet = (s.fleet_avg != null && s.avg_cost != null) ? s.avg_cost - s.fleet_avg : null;
                    return (
                      <>
                        {s.fleet_avg != null ? aed2(s.fleet_avg) : '—'}
                        {vsFleet != null && vsFleet !== 0 && (
                          <span className={`ms-1 text-xs ${vsFleet > 0 ? 'text-red-500' : 'text-emerald-600'}`}>
                            {vsFleet > 0 ? t('(above)') : t('(below)')}
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

        <DataOrigin tab="financials" />
        </div>
        )}

        {/* ── PROGRESS ─────────────────────────── workshop checkpoints (progress tracking) */}
        {activeTab === 'checkpoints' && (
        <div role="tabpanel" id="panel-checkpoints" aria-labelledby="tab-checkpoints" className="space-y-6">
          <VehicleCheckpointsPanel vehicleId={id} />
          <DataOrigin tab="checkpoints" />
        </div>
        )}

        {/* ── COMPLAINTS ─────────────────────── customer complaint history + triage timeline */}
        {activeTab === 'complaints' && (
        <div role="tabpanel" id="panel-complaints" aria-labelledby="tab-complaints" className="space-y-6">
          <VehicleComplaintsPanel vehicleId={id} />
        </div>
        )}

        {/* ── ACCIDENTS ──────────────────── every crash on file + the rental hold, if any */}
        {activeTab === 'accidents' && (
        <div role="tabpanel" id="panel-accidents" aria-labelledby="tab-accidents" className="space-y-6">
          <VehicleAccidentsPanel vehicleId={id} />
          <DataOrigin tab="accidents" />
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
        title={t('vehicleProfile.maint.title')}
        subtitle={v.plate_no || v.vin}
        footer={(
          <>
            <Button variant="secondary" onClick={closeMaint} disabled={busy}>{t('vehicleProfile.maint.cancel')}</Button>
            {conflict
              ? <Button variant="danger" onClick={() => sendToMaintenance({ force: true })} loading={busy}>{t('vehicleProfile.maint.sendAnyway')}</Button>
              : <Button onClick={() => sendToMaintenance({})} loading={busy}>{t('vehicleProfile.maint.title')}</Button>}
          </>
        )}
      >
        <div className="space-y-4">
          {conflict && (
            <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm">
              <p className="flex items-center gap-1.5 font-medium text-red-700">
                <svg className="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" /></svg>
                {t('This car is reserved')}
              </p>
              <p className="mt-1 text-red-600">{conflict.message}</p>
              {conflict.reservations?.length > 0 && (
                <ul className="mt-2 space-y-1 text-xs text-red-600">
                  {conflict.reservations.map((r, i) => (
                    <li key={i}>• {t('Reservation {label}', { label: r.label })}{r.customer ? ` — ${r.customer}` : ''} ({r.reason})</li>
                  ))}
                </ul>
              )}
              <p className="mt-2 text-xs text-slate-500">
                {t('vehicleProfile.maint.overrideBefore')}
                <span className="font-medium">{t('vehicleProfile.maint.sendAnyway')}</span>
                {t('vehicleProfile.maint.overrideAfter')}
              </p>
            </div>
          )}
          <p className="text-sm text-slate-500">{t('vehicleProfile.maint.explainer')}</p>
          <label className="block">
            <span className="mb-1 block text-sm font-medium text-slate-700">{t('vehicleProfile.maint.garage')}</span>
            <SearchSelect
              value={maintForm.vendor_id}
              onChange={(val) => setMaintForm((f) => ({ ...f, vendor_id: val }))}
              options={vendors.map((vn) => ({ id: vn.id, label: vn.name || `#${vn.id}`, sub: vn.type || '' }))}
              placeholder={t('vehicleProfile.maint.searchGarage')}
            />
          </label>
          <Input
            label={t('vehicleProfile.maint.expectedReturn')}
            type="date"
            value={maintForm.expected_return_date}
            onChange={(e) => { setMaintForm((f) => ({ ...f, expected_return_date: e.target.value })); setConflict(null); }}
          />
        </div>
      </Modal>

      {/* Fault distribution drill-down — the maintenance contracts one slice is made of.
          Pure view of the already-loaded `maintenance` payload: same visits, same fault rules
          (faultCategories.faultLabelsOf), so the row count here always equals the slice's count.
          Every row links to the contract itself, which is where the visit's full story lives. */}
      <Modal
        open={!!faultDrill}
        onClose={() => setFaultDrill(null)}
        size="lg"
        title={faultDrill ? t('vehicleProfile.faults.drillTitle', { fault: faultDrill.label }) : ''}
        subtitle={faultDrill
          ? `${tp('vehicleProfile.faults.drillCount', faultDrillVisits.length, { n: num(faultDrillVisits.length) })}${v.plate_display || v.plate_no ? ` · ${v.plate_display || v.plate_no}` : ''}`
          : ''}
        footer={<Button variant="secondary" onClick={() => setFaultDrill(null)}>{t('vehicleProfile.close')}</Button>}
      >
        {faultDrill && (
          faultDrillVisits.length ? (
            <div className="space-y-2">
              {faultDrillVisits.map((m) => {
                const p = PRIO[m.priority] || PRIO.routine;
                // Only the faults that put this visit in THIS slice are highlighted; the rest of
                // the visit's faults stay visible but muted, so a shared visit reads honestly.
                const wanted = new Set(faultDrill.keys.map(String));
                const faults = (Array.isArray(m.fault_tags) ? m.fault_tags : m.tags) || [];
                return (
                  <Link
                    key={m.id}
                    to={`/contracts/${m.id}`}
                    onClick={() => setFaultDrill(null)}
                    className="block rounded-xl p-3 ring-1 ring-inset ring-slate-200 transition hover:bg-indigo-50/40 hover:ring-indigo-200"
                  >
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="font-semibold text-indigo-600">#{m.contract_no || m.id}</span>
                      <Badge tone={p.tone} dot title={m.reason || ''}>{t(p.label)}</Badge>
                      {m.stage && <Badge tone={EVENT_TONE[m.stage] || 'gray'}>{m.stage}</Badge>}
                      <span className="text-xs text-slate-500">
                        {fmtDate(m.date)}
                        {m.in_date && <span className="text-slate-400"> → {fmtDate(m.in_date)}</span>}
                      </span>
                      {SHOW_FINANCIALS && Number(m.total) > 0 && (
                        <span className="ms-auto text-sm font-semibold tabular-nums text-slate-900">{aed2(m.total)}</span>
                      )}
                    </div>
                    <p className="mt-1 text-xs text-slate-500">
                      {m.garage || t('vehicleProfile.faults.drillNoGarage')}
                    </p>
                    <div className="mt-2 flex flex-wrap gap-1">
                      {faults.map((tag) => (
                        <Badge key={tag} tone={wanted.has(String(tag).trim()) ? 'indigo' : 'gray'}>{tag}</Badge>
                      ))}
                      {/* A visit with nothing recorded is exactly what "Unspecified" means — say so
                          rather than showing an empty strip that reads like a rendering bug. */}
                      {faults.length === 0 && (
                        <Badge tone="gray">{m.reason || tf('faultCategories.unspecified', 'Unspecified')}</Badge>
                      )}
                    </div>
                  </Link>
                );
              })}
            </div>
          ) : (
            <p className="py-8 text-center text-sm text-slate-400">{t('vehicleProfile.faults.drillEmpty')}</p>
          )
        )}
      </Modal>

      {/* Maintenance Log — event detail */}
      <Modal
        open={!!logEvent}
        onClose={() => setLogEvent(null)}
        size="lg"
        title={t('vehicleProfile.event.title')}
        subtitle={logEvent ? `${[v.make, v.model].filter(Boolean).join(' ')}${v.plate_no ? ` · ${v.plate_no}` : ''}` : ''}
        footer={<Button variant="secondary" onClick={() => setLogEvent(null)}>{t('vehicleProfile.close')}</Button>}
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
                    {logEvent.date ? fmtDate(logEvent.date) : t('No date recorded')}
                    {logEvent.actual_in && logEvent.actual_in !== logEvent.date && <span className="text-slate-400"> {t('→ returned {date}', { date: fmtDate(logEvent.actual_in) })}</span>}
                  </p>
                </div>
                {logEvent.cost != null && Number(logEvent.cost) > 0 && (
                  <div className="shrink-0 text-end">
                    <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">{t('vehicleProfile.event.cost')}</p>
                    <p className="text-lg font-bold text-slate-900">{aed2(logEvent.cost)}</p>
                  </div>
                )}
              </div>

              {/* the real fault — MAIN area(s) + SUP detail(s) recorded for the visit */}
              {(logEvent.main || logEvent.sup) && (
                <div className="rounded-xl bg-slate-50/70 p-4 ring-1 ring-inset ring-slate-100">
                  <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">{t('vehicleProfile.event.problem')}</p>
                  {logEvent.main && <p className="text-sm font-semibold text-slate-800">{logEvent.main}</p>}
                  {logEvent.sup && <p className="mt-0.5 text-sm text-slate-600">{logEvent.sup}</p>}
                </div>
              )}

              {/* facts grid */}
              <div className="grid grid-cols-1 gap-x-8 sm:grid-cols-2">
                <Field label={t('vehicleProfile.event.event')} value={logEvent.event} />
                <Field label={t('vehicleProfile.event.date')} value={logEvent.date ? fmtDate(logEvent.date) : '—'} />
                <Field label={t('vehicleProfile.event.garage')} value={logEvent.garage} />
                <Field label={t('vehicleProfile.event.returned')} value={logEvent.actual_in ? fmtDate(logEvent.actual_in) : '—'} />
                <Field label={t('vehicleProfile.event.type')} value={logEvent.type} />
                <Field label={t('vehicleProfile.event.damage')} value={logEvent.damage} />
                <Field label={t('vehicleProfile.event.severity')} value={logEvent.severity} />
                <Field label={t('vehicleProfile.event.cost')} value={logEvent.cost != null ? aed2(logEvent.cost) : '—'} />
                {logEvent.contract_id && (
                  <div className="flex justify-between gap-4 py-1.5 text-sm">
                    <span className="text-slate-500">{t('vehicleProfile.event.contract')}</span>
                    <Link to={`/contracts/${logEvent.contract_id}`} className="font-medium text-indigo-600 hover:text-indigo-700">#{logEvent.contract_no || logEvent.contract_id}</Link>
                  </div>
                )}
              </div>

              {/* issue tags */}
              {(logEvent.issues || logEvent.tags || []).length > 0 && (
                <div>
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{t('vehicleProfile.event.issues')}</p>
                  <div className="flex flex-wrap gap-1.5">
                    {(logEvent.issues || logEvent.tags).map((tag) => <Badge key={tag} tone="indigo">{tag}</Badge>)}
                  </div>
                </div>
              )}

              {/* full notes */}
              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{t('vehicleProfile.event.notes')}</p>
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
        title={t('vehicleProfile.bridge.title')}
        subtitle={`${[v.make, v.model].filter(Boolean).join(' ')}${v.plate_no ? ` · ${v.plate_no}` : ''}`}
        footer={<Button variant="secondary" onClick={() => setBridgeOpen(false)}>{t('vehicleProfile.close')}</Button>}
      >
        {(() => {
          const pb = stats.profit_bridge || {};
          const pc = stats.profit_contracts || [];
          return (
            <div className="space-y-6">
              {/* The gross→net chain, with every sub-sum shown. */}
              <div className="rounded-2xl border border-slate-200/60 bg-slate-50/60 p-5">
                <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">{t('vehicleProfile.bridge.formula')}</p>
                <dl className="space-y-2 text-sm">
                  <BridgeLine label={t('vehicleProfile.bridge.rentBilled')} hint={t('vehicleProfile.bridge.rentBilledHint')} value={aed2(pb.rent_billed)} />
                  <BridgeLine label={t('vehicleProfile.bridge.discount')} hint={t('vehicleProfile.bridge.discountHint')} value={`− ${aed2(pb.discount)}`} valueClass="text-slate-500" />
                  <BridgeLine label={t('vehicleProfile.bridge.usage')} hint={t('vehicleProfile.bridge.usageHint')} value={`+ ${aed2(pb.realized_usage)}`} valueClass="text-slate-700" />
                  <div className="!mt-2 border-t border-dashed border-slate-200 pt-2">
                    <BridgeLine label={t('vehicleProfile.bridge.gross')} value={aed2(pb.gross_revenue)} labelClass="font-semibold text-slate-900" valueClass="font-semibold text-slate-900" />
                  </div>
                  <BridgeLine label={t('vehicleProfile.bridge.operating')} hint={t('vehicleProfile.bridge.operatingHint')} value={`− ${aed2(pb.operating_cost)}`} valueClass="text-slate-500" />
                  <BridgeLine label={t('vehicleProfile.bridge.maintenance')} hint={pb.maintenance_visits ? tp('vehicleProfile.bridge.maintenanceHintVisits', pb.maintenance_visits, { n: num(pb.maintenance_visits) }) : t('vehicleProfile.bridge.maintenanceHint')} value={`− ${aed2(pb.maintenance)}`} valueClass="text-amber-600" />
                  <div className="!mt-3 border-t border-slate-300 pt-3">
                    <BridgeLine
                      label={t('vehicleProfile.bridge.net')}
                      labelClass="text-base font-bold text-slate-900"
                      value={aed2(pb.net_profit)}
                      valueClass={`text-base font-bold ${Number(pb.net_profit) < 0 ? 'text-red-600' : 'text-emerald-600'}`}
                    />
                  </div>
                </dl>
                <p className="mt-3 text-xs text-slate-400">
                  {t('vehicleProfile.bridge.footnote', { n: num(pb.rentals) })}
                </p>
              </div>

              {/* Every rental contract that summed into Gross Revenue − Operating. */}
              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
                  {tp('vehicleProfile.bridge.perContract', pc.length, { n: num(pc.length) })}
                </p>
                {pc.length === 0 ? (
                  <p className="rounded-xl bg-slate-50/70 p-4 text-sm text-slate-500 ring-1 ring-inset ring-slate-100">{t('vehicleProfile.bridge.noContracts')}</p>
                ) : (
                  <div className="max-h-[22rem] overflow-auto rounded-xl ring-1 ring-inset ring-slate-200">
                    <table className="min-w-full divide-y divide-slate-100 text-sm">
                      <thead className="sticky top-0 bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                        <tr>
                          <th className="px-3 py-2 text-start font-semibold">{t('vehicleProfile.bridge.cols.contract')}</th>
                          <th className="px-3 py-2 text-start font-semibold">{t('vehicleProfile.bridge.cols.out')}</th>
                          <th className="px-3 py-2 text-end font-semibold">{t('vehicleProfile.bridge.cols.rent')}</th>
                          <th className="px-3 py-2 text-end font-semibold">{t('vehicleProfile.bridge.cols.disc')}</th>
                          <th className="px-3 py-2 text-end font-semibold">{t('vehicleProfile.bridge.cols.usage')}</th>
                          <th className="px-3 py-2 text-end font-semibold">{t('vehicleProfile.bridge.cols.oper')}</th>
                          <th className="px-3 py-2 text-end font-semibold">{t('vehicleProfile.bridge.cols.net')}</th>
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
                            <td className="px-3 py-2 text-end tabular-nums text-slate-700">{aed2(c.rent_billed)}</td>
                            <td className="px-3 py-2 text-end tabular-nums text-slate-400">{c.discount ? `− ${aed2(c.discount)}` : '—'}</td>
                            <td className="px-3 py-2 text-end tabular-nums text-slate-700">{c.realized_usage ? `+ ${aed2(c.realized_usage)}` : '—'}</td>
                            <td className="px-3 py-2 text-end tabular-nums text-slate-400">{c.operating_cost ? `− ${aed2(c.operating_cost)}` : '—'}</td>
                            <td className={`px-3 py-2 text-end tabular-nums font-semibold ${Number(c.net) < 0 ? 'text-red-600' : 'text-emerald-600'}`}>{aed2(c.net)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
                <p className="mt-2 text-xs text-slate-400">
                  {t('Per-contract Net = Rent − Discount + Usage − Operating. These sum to Gross Revenue − Operating above; subtract car-level Maintenance to reach Lifetime Net Profit.')}
                </p>
              </div>
            </div>
          );
        })()}
      </Modal>
    </div>
  );
}
