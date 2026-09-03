// One modal that drives every Fleet Maintenance Workflow transition. The `action`
// prop picks the form + the endpoint; on success it calls onDone(message) so the
// board can toast + reload. The server re-guards every move, so this UI only has
// to collect the handoff data for the step in front of the user.
//
// Every user-facing string comes from the central i18n catalog via `t()` (see
// i18n/labels.js + I18nContext) — never hardcoded — so the modal renders
// identically in English and Arabic and mirrors cleanly in RTL.
//
//   request   S0    Driver     → POST /maintenance-tickets/request
//   open      UC-1  Inspector  → POST /maintenance-tickets
//   start     S0→1  Inspector  → POST /maintenance-tickets/{id}/start
//   report    UC-2  Inspector  → POST /maintenance-tickets/{id}/report
//   assign    P2    Supervisor → POST /maintenance-tickets/{id}/assign-dispatch  (pick garage + driver)
//   dispatch  UC-3  Driver     → POST /maintenance-tickets/{id}/dispatch          (physical pickup)
//   receive   UC-4  Driver     → POST /maintenance-tickets/{id}/under-repair
//   followup  —     Driver     → POST /maintenance-tickets/{id}/follow-up
//   ready     UC-5  Driver     → POST /maintenance-tickets/{id}/ready
//   reinspect UC-6  Inspector  → POST .../close (pass) | .../reopen (fail)

import { useEffect, useMemo, useRef, useState } from 'react';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Modal from '../ui/Modal';
import Button from '../ui/Button';
import SearchSelect from '../ui/SearchSelect';
import VehicleStatusSelect from './VehicleStatusSelect';
import Icon from '../ui/Icon';
import { Input, Textarea, Select } from '../ui/Field';
import FindingsList from './FindingsList';
import FindingsPicker from './FindingsPicker';
import FaultDetailPicker from './FaultDetailPicker';
import SystemChecks, { buildCheckResults, checkFindings } from './SystemChecks';
import { buildDetails, findingsMissingLocation, withDetails } from '../../lib/faultLocations';
import RequiredPartsEditor, { cleanRequiredParts } from './RequiredPartsEditor';
import DispatchPlan from './DispatchPlan';
import DecisionCards from './DecisionCards';
import RootCausePicker, { rootCausesComplete } from './RootCausePicker';
import FaultHistoryInsight from './FaultHistoryInsight';
import RepairIntelligencePanel from '../knowledge/RepairIntelligencePanel';
import LineItemsEditor, { serializeLineItems, lineItemsUnlinked, invoiceVarianceBlocked, lineItemsHaveZeroCost } from './LineItemsEditor';
import { compressImage, formatBytes } from '../../lib/imageCompression';
import { evaluateContinuity, needsConfirm, needsNote, isHardBlocked, stageIgnoresTolerance, stageRequiresIncrease, STAGE } from '../../lib/odometerContinuity';
import OdometerContinuityHint from './OdometerContinuityHint';
import SignaturePad from './SignaturePad';
import { isPaused, ORIGIN_LABEL } from './meta';
import { useAuth } from '../../auth/AuthContext';

// Enterprise Handover Workflow — CONTRACT with backend/config/maintenance_handover.php. Small, fixed
// enums, so hardcoded client-side rather than fetched.
// Upper bound (km) for any odometer reading — mirrors the backend cap
// (MaintenanceWorkflowController::MAX_ODOMETER). The DB odometer columns are unsigned INT; a value above
// this overflows them, so we block it client-side with a clear message rather than round-trip a 422.
const MAX_ODOMETER = 9_999_999;

const FUEL_SCALE = ['E', '1/4', '1/2', '3/4', 'F'];
const CONDITION_PRESETS = ['Good', 'Minor scratches', 'Damaged'];
const ACCESSORY_KEYS = ['spare_tire', 'jack', 'first_aid_kit', 'warning_triangle', 'floor_mats', 'charging_cable'];
const DAMAGE_SEVERITY = ['routine', 'moderate', 'critical']; // reuses App\Models\Maintenance::FAULT_SEVERITIES vocabulary

// Temporary Vehicle Release — why the car left the shop mid-repair. CONTRACT with
// App\Models\MaintenanceTemporaryRelease::REASONS; visible labels resolve from the i18n catalog.
const TEMP_RELEASE_REASONS = ['road_test', 'customer_test', 'external_inspection', 'other'];

// Where a released car is usually sent. Quick-picks only — the destination is free text, because a car
// can go anywhere (a customer's address, a body shop, someone's house). CONTRACT with
// App\Models\LogisticsTask::COMMON_DESTINATIONS.
const RELEASE_DESTINATIONS = ['Parking Yard', 'Office', 'Showroom', 'Deals on Wheels'];

// The car's last known odometer reading for a ticket — the highest of the vehicle's authoritative
// odometer and every reading captured on the ticket's mileage chain (readings only go forward). Used to
// pre-fill the "Odometer out" field on a Temporary Vehicle Release so the operator sees + edits it.
function lastKnownOdometer(tk) {
  if (!tk) return null;
  const candidates = [
    tk.vehicle_odometer,
    tk.reinspect_odometer, tk.park_odometer, tk.return_odometer,
    tk.receive_odometer, tk.dispatch_odometer, tk.report_odometer, tk.test_odometer,
  ].map((v) => (v == null ? null : Number(v))).filter((v) => v != null && !Number.isNaN(v));
  return candidates.length ? Math.max(...candidates) : null;
}

// Post-Repair Inspection — the structured reasons a repair did not hold (Case B). CONTRACT with
// App\Models\RepairInspection::REASONS; visible labels resolve from the i18n catalog at render time.
// Why a supervisor sent the car somewhere other than the recommendation. Mirrors
// config/garage_recommendation.php `override_reasons` — the backend validates against that list and
// degrades anything unrecognised to `other`, so a stale entry here can never corrupt the counts.
//
// ⚠️ These are reasons, NOT excuses. Most overrides are sound operational judgement the engine has no
// way to see, and the wording has to stay neutral: the moment supervisors feel logged-and-judged they
// pick whichever option ends the conversation fastest and the data stops meaning anything.
const OVERRIDE_REASONS = [
  'lower_cost', 'faster_availability', 'faster_turnaround', 'customer_requested',
  'existing_relation', 'special_expertise', 'warranty_or_contract', 'location', 'other',
];

/**
 * Which axes the chosen garage was genuinely better on, from the same figures that were on screen.
 *
 * Recorded so a stated reason can be CHECKED later: "lower cost" against a garage our own data shows
 * was cheaper is evidence our weights undervalue cost; the same claim against one that was not cheaper
 * says something about perception or about our cost data — opposite problems, and only separable
 * because the advantage was measured rather than taken on trust.
 *
 * Fleet-basis figures are ignored: a number every garage shares cannot make one of them better.
 */
function measuredAdvantages(chosen, recommended) {
  const own = (s) => s && s.value != null && ['garage', 'garage_fault', 'garage_fault_model'].includes(s.basis);
  const a = chosen.outcomes || {};
  const b = recommended.outcomes || {};
  const out = [];
  if (own(a.cost_aed) && own(b.cost_aed) && b.cost_aed.value > 0
      && (b.cost_aed.value - a.cost_aed.value) / b.cost_aed.value >= 0.15) out.push('cost');
  if (own(a.duration_days) && own(b.duration_days)
      && b.duration_days.value - a.duration_days.value >= 0.5) out.push('speed');
  if (a.start_in_days != null && b.start_in_days != null
      && b.start_in_days - a.start_in_days >= 1) out.push('availability');
  if (own(a.success_pct) && own(b.success_pct)
      && a.success_pct.value - b.success_pct.value >= 5) out.push('reliability');
  return out;
}

const FAILURE_REASONS = ['wrong_diagnosis', 'part_failed', 'repair_incomplete', 'wrong_part', 'customer_complaint', 'unknown'];
// Why a repair could not be verified. Kept distinct from FAILURE_REASONS because an unverifiable
// inspection is not a failed one — it must never blame a garage or reach a quality statistic.
const UNVERIFIABLE_REASONS = ['vehicle_unavailable', 'not_reproducible', 'needs_road_test', 'no_access', 'unverifiable_other'];
const UNVERIFIABLE_FALLBACK = {
  vehicle_unavailable: 'Vehicle already out / with the customer',
  not_reproducible: 'Fault would not reproduce',
  needs_road_test: 'Needs a road test — not possible now',
  no_access: 'Could not access the component',
  unverifiable_other: 'Other',
};

// Persisted enum values — these are CONTRACT with the backend and never localize.
// Their visible labels are resolved from the i18n catalog at render time.
// Inspection trigger reasons offered in the human pickers. `customer_reported` is deliberately ABSENT: after
// the complaint split, that trigger is set ONLY by the Complaint entity's "send in" — a customer issue lives
// in the Complaint entity (Complaints Center, source driver_relayed) or a Driver Observation, never a
// hand-picked complaint-tagged inspection request. (Backend contract stays App\Models\Maintenance::TRIGGER_REASONS.)
const INSPECTION_TRIGGER_REASONS = ['test_drive', 'periodic'];
// `periodic` ("It's due for routine service") is deliberately ABSENT from the driver-voice forms for
// every role: routine upkeep is mileage/time-based and the system now raises it itself from the Service
// Reminders — nobody hand-files it. The planner-facing `open` form (UC-1) still offers it above.
//
// MOVED OUT: the Stage-0 "Request Inspection" form used to live in this modal — the driver-voice choices
// (test drive / observation / office call), the one-request-per-car in-flight check and the whole
// vehicle+reason+notes block. It is now SendCarInModal, which asks the two things this modal could not:
// which DOOR the car goes through (ask for a look, or straight to the garage with no test drive), and
// WHY as data — a named fault, a reason code, or a note, never two at once.
// Breakdown is the SOLE classification an operator ever picks. The backend still knows all six
// (App\Models\Maintenance::MAINTENANCE_TYPES) so historic rows keep their value and the API stays
// compatible, but the administrative types (Routine, Insurance / Non-Insurance Incident,
// Modification, Upgrade) are deliberately NOT offered here — none of them has ever been chosen on a
// live ticket. Do not re-add them to this list without asking; it has been reverted once already.
const MAINTENANCE_TYPES = [
  { value: 'breakdown', icon: '⚠️' },
]; // subset of App\Models\Maintenance::MAINTENANCE_TYPES — see note above

// Fault Severity (🔴/🟡/🟢) — CONTRACT with App\Models\Maintenance::FAULT_SEVERITIES. The inspector's
// mandatory diagnostic grade, assessed at the Decide step; it's the headline urgency on the board.
const FAULT_SEVERITY_OPTS = [
  { value: 'critical', emoji: '🔴' },
  { value: 'moderate', emoji: '🟡' },
  { value: 'routine', emoji: '🟢' },
];

// Submit-button tone per action (visual only). The footer further overrides this
// for the branching decisions (reinspect pass/fail, decide requires/clear).
// "2.5" / "3" — one decimal at most, trailing ".0" dropped, for the hours totals on the ready screen.
const round1 = (n) => String(Math.round(Number(n) * 10) / 10);

/**
 * "3h 00m" — the SAME wording FaultRepairTimeService uses in its rejection message, so the limit the
 * form quotes and the limit the server quotes read identically. A mechanic who sees two different
 * renderings of the same number stops trusting both.
 */
const fmtHm = (secs) => {
  const s = Math.max(0, Number(secs) || 0);
  if (s < 60) return `${Math.round(s)}s`;
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  return h > 0 ? `${h}h ${String(m).padStart(2, '0')}m` : `${m}m`;
};

const baseTone = (action) => (['ready', 'serviced', 'arriveAtDestination', 'returnFromRelease'].includes(action) ? 'success'
  : 'primary');

// Compact "who/when" timestamp for the follow-up log: relative for recent notes, an absolute
// date+time once they age past a day. `t` localizes the relative phrasing.
function fmtWhen(iso, t, lang) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const diff = (Date.now() - d.getTime()) / 1000;
  if (diff < 60) return t('time.justNow');
  if (diff < 3600) return t('time.minutesAgo', { n: Math.round(diff / 60) });
  if (diff < 86400) return t('time.hoursAgo', { n: Math.round(diff / 3600) });
  // Gregorian calendar + Latin digits under Arabic — a Hijri/Arabic-Indic stamp would be wrong here.
  const loc = lang === 'ar' ? 'ar-AE-u-ca-gregory-nu-latn' : undefined;
  return `${d.toLocaleDateString(loc, { month: 'short', day: 'numeric' })}, ${d.toLocaleTimeString(loc, { hour: '2-digit', minute: '2-digit' })}`;
}

// Up-to-two-letter avatar initials for the note's author.
const initialsOf = (name) =>
  (name || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?';

// One follow-up "bubble": author avatar + name, a compact timestamp, and the note body. The most
// recent note (just saved) gets a green confirmation highlight so the writer sees it landed.
function FollowUpBubble({ note, fresh, t, lang }) {
  return (
    <li className={`rounded-xl border p-3 shadow-sm transition ${fresh ? 'border-emerald-300 bg-emerald-50/60 ring-1 ring-emerald-200' : 'border-slate-200 bg-white'}`}>
      <div className="flex items-center justify-between gap-2">
        <span className="flex items-center gap-1.5 text-xs font-semibold text-slate-700">
          <span className="flex h-5 w-5 items-center justify-center rounded-full bg-indigo-100 text-[10px] font-bold text-indigo-600">{initialsOf(note.by)}</span>
          {note.by || t('common.unknown')}
        </span>
        <span className="shrink-0 text-[11px] text-slate-400">
          {fresh && <span className="me-1.5 font-semibold text-emerald-600">{t('time.saved')}</span>}
          {fmtWhen(note.at, t, lang)}
        </span>
      </div>
      <p className="mt-1.5 whitespace-pre-wrap break-words text-sm leading-relaxed text-slate-700">{note.text}</p>
    </li>
  );
}

// A span of seconds → a compact "3d 4h" / "15m" badge (top two non-zero units; sub-minute → "<1m").
// null whenever the span isn't measurable yet, so a stage that hasn't completed reads as nothing.
function fmtDuration(secs, t) {
  if (secs == null || !Number.isFinite(secs) || secs < 0) return null;
  const s = Math.floor(secs);
  const d = Math.floor(s / 86400);
  const h = Math.floor((s % 86400) / 3600);
  const m = Math.floor((s % 3600) / 60);
  const parts = [];
  if (d) parts.push(t('{n}d', { n: d }));
  if (h) parts.push(t('{n}h', { n: h }));
  if (m) parts.push(t('{n}m', { n: m }));
  if (!parts.length) return t('<1m');
  return parts.slice(0, 2).join(' ');
}

// Live elapsed seconds from an ISO instant to now — used to keep the IN-PROGRESS stage's clock
// ticking ("At Garage · running") instead of showing nothing until the car comes back.
const elapsedSecs = (iso) => (iso ? Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000) : null);

// One stage chip: the step name + a Duration badge. A completed stage shows its measured span; the
// step currently in progress shows the running clock (amber); an unreached step is skipped entirely.
function StageChip({ label, icon: Ico, secs, running, t }) {
  const text = fmtDuration(secs, t);
  if (text == null) return null;
  return (
    <span className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs">
      {Ico && <Ico className="h-3.5 w-3.5 text-slate-400" />}
      <span className="font-medium text-slate-600">{label}</span>
      <span className={`tabular-nums rounded-full px-1.5 py-0.5 text-[11px] font-semibold ${running ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-700'}`}>
        {text}{running ? ` · ${t('time.running')}` : ''}
      </span>
    </span>
  );
}

// The stage-timing strip shown at the top of an existing ticket: per-stage durations + total
// downtime, computed from the server's pre-baked seconds (completed stages) or measured live to
// now (the stage in progress). Renders nothing until the first anchor (test_started_at) exists.
function StageTimeline({ ticket, t }) {
  const tm = ticket?.stage_timing;
  if (!tm || !tm.test_started_at) return null;
  const dur = tm.durations || {};

  // Test Drive: test_started → dispatched. Running while we've started but not yet dispatched.
  const testRunning = !!tm.test_started_at && !tm.dispatched_at;
  const testSecs = dur.test_drive ?? (testRunning ? elapsedSecs(tm.test_started_at) : null);

  // At Garage: the CURRENT garage's stint — repair_started (arrival check-in) → returned. Running while
  // it's checked in but not yet back. Anchored on repair_started_at (reset on every transfer) so the clock
  // restarts per garage and never carries the previous garage's time; before check-in there's no stint yet.
  const garageRunning = !!tm.repair_started_at && !tm.returned_at;
  const garageSecs = dur.at_garage ?? (garageRunning ? elapsedSecs(tm.repair_started_at) : null);

  // Total downtime: test_started → returned, or live to now while the car is still out.
  const totalRunning = !tm.returned_at;
  const totalSecs = dur.total_downtime ?? elapsedSecs(tm.test_started_at);
  const totalText = fmtDuration(totalSecs, t);

  return (
    <div className="rounded-xl border border-slate-100 bg-slate-50/60 p-3">
      <div className="mb-2 flex items-center justify-between">
        <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">{t('workflow.stage.timing')}</p>
        {totalText && (
          <span className="inline-flex items-center gap-1.5 text-xs">
            <span className="text-slate-400">{t('workflow.stage.totalDowntime')}</span>
            <span className={`tabular-nums rounded-full px-1.5 py-0.5 text-[11px] font-bold ${totalRunning ? 'bg-amber-100 text-amber-700' : 'bg-indigo-100 text-indigo-700'}`}>
              {totalText}{totalRunning ? ` · ${t('time.running')}` : ''}
            </span>
          </span>
        )}
      </div>
      <div className="flex flex-wrap gap-2">
        <StageChip label={t('workflow.stage.testDrive')} icon={Icon.Gauge} secs={testSecs} running={testRunning} t={t} />
        <StageChip label={t('workflow.stage.atGarage')} icon={Icon.Wrench} secs={garageSecs} running={garageRunning} t={t} />
        {/* Test-drive distance — odometer at dispatch minus the inspector's start reading (km driven). */}
        {ticket?.test_drive_distance_km != null && (
          <span className="inline-flex items-center gap-1.5 rounded-lg border border-indigo-100 bg-indigo-50/60 px-2 py-1 text-xs">
            <Icon.Gauge className="h-3.5 w-3.5 text-indigo-400" />
            <span className="font-medium text-slate-600">{t('workflow.stage.testDriveDistance')}</span>
            <span className="tabular-nums rounded-full bg-indigo-100 px-1.5 py-0.5 text-[11px] font-semibold text-indigo-700">
              {Number(ticket.test_drive_distance_km).toLocaleString()} {t('workflow.stage.kmShort')}
            </span>
          </span>
        )}
      </div>
    </div>
  );
}

// A required-field asterisk.
const Req = () => <span className="text-red-500"> *</span>;

// A numbered section of a long form — used to break the Inspector's test-drive report ('decide') into
// four readable steps (mileage → findings → diagnosis → decision) instead of one undifferentiated
// scroll. `done` flips the step number to a green tick so progress through the report is visible.
// One step of the test-drive report ('decide').
//
// The report is five questions long, and rendering all five expanded is what made this screen read as a
// wall: the inspector met every field of every question at once, including the routing decisions he can
// only answer after he's finished diagnosing. So only ONE step is open at a time. The others collapse to
// a single row that still carries its own answer ("3 selected", "87,101 km", "Requires maintenance"), so
// the whole report stays readable at a glance and nothing is hidden — a collapsed row is a summary, never
// a black box. Tapping any row opens it; "Continue" walks forward.
//
// `open` defaults to true, so a caller that doesn't drive the accordion gets the old always-expanded card.
function Step({ n, title, hint, done = false, children, open = true, onOpen, summary, onNext, nextLabel }) {
  const badge = (
    <span
      className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold ${
        done ? 'bg-emerald-500 text-white' : 'bg-slate-100 text-slate-500'
      }`}
      aria-hidden
    >
      {done ? '✓' : n}
    </span>
  );

  if (!open) {
    return (
      <button
        type="button"
        onClick={onOpen}
        className="flex w-full items-center gap-2.5 rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-start shadow-sm transition hover:border-slate-300 hover:bg-slate-50"
      >
        {badge}
        <span className="min-w-0 flex-1 truncate text-sm font-semibold text-slate-700">{title}</span>
        {summary && <span className="min-w-0 max-w-[45%] shrink-0 truncate text-xs text-slate-500">{summary}</span>}
        <Icon.ChevronDown className="h-4 w-4 shrink-0 -rotate-90 text-slate-300 rtl:rotate-90" aria-hidden />
      </button>
    );
  }

  return (
    <section className="rounded-2xl border border-indigo-200 bg-white shadow-sm ring-1 ring-indigo-100">
      <header className="flex items-start gap-2.5 border-b border-slate-100 px-4 py-3">
        <span className="mt-0.5">{badge}</span>
        <span className="min-w-0">
          <span className="block text-sm font-semibold text-slate-800">{title}</span>
          {hint && <span className="mt-0.5 block text-xs text-slate-500">{hint}</span>}
        </span>
      </header>
      <div className="space-y-3 p-4">{children}</div>
      {onNext && (
        <div className="flex justify-end border-t border-slate-100 px-4 py-2.5">
          <button
            type="button"
            onClick={onNext}
            className="inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-700"
          >
            {nextLabel}
            <Icon.ChevronDown className="h-4 w-4 -rotate-90 rtl:rotate-90" aria-hidden />
          </button>
        </div>
      )}
    </section>
  );
}

// The 🔴/🟡/🟢 Fault Severity chooser — the inspector's mandatory diagnostic grade on the 'decide'
// step. Clicking the active level clears it. Colour-codes the selected level so the grade reads at
// a glance (matching the board chip the supervisor sees downstream).
const SEVERITY_STYLE = {
  critical: 'border-red-500 bg-red-50 text-red-800 ring-1 ring-red-500',
  high: 'border-orange-500 bg-orange-50 text-orange-800 ring-1 ring-orange-500',
  moderate: 'border-amber-500 bg-amber-50 text-amber-800 ring-1 ring-amber-500',
  routine: 'border-emerald-500 bg-emerald-50 text-emerald-800 ring-1 ring-emerald-500',
};
// `locked` — when the classification forces the grade (a Breakdown is always 🔴 critical), the picker
// is rendered read-only: the forced level keeps its colour, the others dim, and none respond to clicks.
/**
 * What the system would grade this fault, and WHY — a lookup, never a guess.
 *
 * Every keyword in the admin-curated risk library carries a grade in exactly the same vocabulary as
 * fault severity (critical / moderate / routine), so the suggestion is that stored grade, not a model's
 * opinion: the most serious grade among the findings the inspector actually ticked, named together with
 * the keyword it came from so the reader can check it instead of trusting it.
 *
 * It never writes the field. Severity is the inspector's call and stays mandatory — this only saves them
 * re-deriving what the library already knows, and makes disagreeing with it a deliberate act.
 */
export function severitySuggestion(symptoms = [], keywordMeta = {}) {
  let best = null;
  symptoms.forEach((s) => {
    const key = typeof s === 'string' ? s : s?.text;
    const meta = key ? keywordMeta[key] : null;
    if (!meta?.risk) return;
    if (!best || (meta.rank || 0) > (best.rank || 0)) best = { ...meta, keyword: key };
  });
  return best;
}

function FaultSeverityPicker({ value, onChange, t, locked = false, suggestion = null }) {
  return (
    <div className="grid grid-cols-3 gap-2">
      {FAULT_SEVERITY_OPTS.map((p) => {
        const active = value === p.value;
        const isSuggested = !locked && suggestion?.risk === p.value;
        return (
          <button
            key={p.value}
            type="button"
            disabled={locked}
            aria-disabled={locked}
            onClick={() => onChange(active ? '' : p.value)}
            className={`relative flex items-center justify-center gap-1.5 rounded-xl border px-3 py-2.5 text-sm font-semibold transition ${active ? SEVERITY_STYLE[p.value] : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'} ${!active && isSuggested ? ' border-indigo-300 ring-1 ring-indigo-200' : ''} ${locked ? `cursor-not-allowed${active ? '' : ' opacity-40'}` : ''}`}
          >
            <span className="text-base leading-none" aria-hidden>{p.emoji}</span>
            {t(`workflow.faultSeverity.${p.value}`)}
            {isSuggested && !active && (
              <span className="absolute -top-2 rounded-full bg-indigo-600 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-white">
                {t('workflow.faultSeverity.suggested')}
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
}

// The suggestion line under the picker: what the library says, which keyword said it, and a one-tap way
// to accept it. Once the inspector has graded it differently, the line says so plainly rather than
// disappearing — a disagreement with the library is worth seeing.
function SeveritySuggestionNote({ suggestion, value, onApply, t }) {
  if (!suggestion) return null;
  const agreed = value === suggestion.risk;
  const label = t(`workflow.faultSeverity.${suggestion.risk}`);

  return (
    <div className={`mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg px-2.5 py-1.5 text-xs ring-1 ring-inset ${agreed
      ? 'bg-emerald-50 text-emerald-800 ring-emerald-600/15'
      : 'bg-indigo-50 text-indigo-800 ring-indigo-600/15'}`}
    >
      <span>
        {t('workflow.faultSeverity.suggestionLine', {
          emoji: suggestion.emoji || '',
          severity: label,
          keyword: suggestion.ar && document?.documentElement?.dir === 'rtl' ? suggestion.ar : suggestion.keyword,
        })}
      </span>
      {!agreed && !value && (
        <button type="button" onClick={() => onApply(suggestion.risk)} className="font-semibold underline underline-offset-2">
          {t('workflow.faultSeverity.useSuggestion')}
        </button>
      )}
      {!agreed && value && <span className="font-semibold">{t('workflow.faultSeverity.overridden')}</span>}
    </div>
  );
}

// Maintenance-type picker. With Breakdown the sole classification, render each option as a full-width,
// self-explanatory card (amber-toned to signal it grounds the car) rather than a cramped icon grid —
// it reads as a clear, deliberate choice even when there's only one of it.
function MaintenanceTypeCards({ types, value, onChange, t }) {
  return (
    <div className="grid gap-2 sm:grid-cols-2">
      {types.map((ty) => {
        const active = value === ty.value;
        const desc = t(`workflow.type.${ty.value}Desc`);
        const hasDesc = desc !== `workflow.type.${ty.value}Desc`;
        return (
          <button
            key={ty.value}
            type="button"
            onClick={() => onChange(active ? '' : ty.value)}
            className={`flex items-start gap-3 rounded-xl border px-4 py-3 text-start transition ${active ? 'border-amber-500 bg-amber-50 ring-1 ring-amber-500' : 'border-slate-200 bg-white hover:border-slate-300'}`}
          >
            <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-lg leading-none ${active ? 'bg-amber-100' : 'bg-slate-100'}`} aria-hidden>{ty.icon}</span>
            <span className="min-w-0 flex-1">
              <span className={`block text-sm font-semibold ${active ? 'text-amber-900' : 'text-slate-800'}`}>{t(`workflow.type.${ty.value}`)}</span>
              {hasDesc && <span className="mt-0.5 block text-xs leading-snug text-slate-500">{desc}</span>}
            </span>
            {active && <span className="mt-0.5 shrink-0 text-sm font-bold text-amber-600" aria-hidden>✓</span>}
          </button>
        );
      })}
    </div>
  );
}

// Enterprise Handover Workflow — the shared custody-handover field set captured on BOTH the pause and
// resume legs (odometer + photo already rendered by the caller via `odometerBlock`). Kept as one
// component so the two legs can never visually drift apart.
function HandoverFields({
  fuelLevel, onFuelLevel, exteriorCondition, onExteriorCondition, interiorCondition, onInteriorCondition,
  damageFindings, onDamageFindings, missingAccessories, onMissingAccessories,
  handoverNotes, onHandoverNotes, signatureRef, onSignatureChange, t,
}) {
  const addDamage = () => onDamageFindings([...damageFindings, { location: '', severity: 'routine', note: '' }]);
  const updateDamage = (i, patch) => onDamageFindings(damageFindings.map((d, idx) => (idx === i ? { ...d, ...patch } : d)));
  const removeDamage = (i) => onDamageFindings(damageFindings.filter((_, idx) => idx !== i));
  const toggleAccessory = (key) => onMissingAccessories(
    missingAccessories.includes(key) ? missingAccessories.filter((a) => a !== key) : [...missingAccessories, key],
  );

  return (
    <>
      {/* Fuel level — 5-option segmented scale, CONTRACT with config/maintenance_handover.php fuel_scale */}
      <div>
        <span className="mb-1.5 block text-sm font-medium text-slate-700">{t('workflow.handover.fuelLabel')}<Req /></span>
        <div className="grid grid-cols-5 gap-1.5">
          {FUEL_SCALE.map((f) => {
            const active = fuelLevel === f;
            return (
              <button
                key={f}
                type="button"
                onClick={() => onFuelLevel(f)}
                className={`rounded-lg border px-2 py-2 text-sm font-semibold transition ${active ? 'border-indigo-500 bg-indigo-50 text-indigo-800 ring-1 ring-indigo-500' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'}`}
              >
                {f}
              </button>
            );
          })}
        </div>
      </div>

      {/* Exterior / Interior condition — free text with quick presets */}
      <div className="grid gap-3 sm:grid-cols-2">
        {[
          { label: t('workflow.handover.exteriorLabel'), value: exteriorCondition, onChange: onExteriorCondition },
          { label: t('workflow.handover.interiorLabel'), value: interiorCondition, onChange: onInteriorCondition },
        ].map((f, i) => (
          <div key={i}>
            <span className="mb-1 block text-sm font-medium text-slate-700">{f.label}<Req /></span>
            <div className="mb-1.5 flex flex-wrap gap-1.5">
              {CONDITION_PRESETS.map((p) => (
                <button
                  key={p}
                  type="button"
                  onClick={() => f.onChange(p)}
                  className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 transition ${f.value === p ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white text-slate-500 ring-slate-300 hover:bg-slate-50'}`}
                >
                  {p === 'Good' ? t('workflow.handover.conditionGood') : p === 'Minor scratches' ? t('workflow.handover.conditionMinor') : t('workflow.handover.conditionDamaged')}
                </button>
              ))}
            </div>
            <Input value={f.value} onChange={(e) => f.onChange(e.target.value)} placeholder={f.label} />
          </div>
        ))}
      </div>

      {/* Damage findings — a small repeatable list (location / severity / note); optional */}
      <div>
        <div className="mb-1.5 flex items-center justify-between">
          <span className="text-sm font-medium text-slate-700">{t('workflow.handover.damageLabel')}</span>
          <button type="button" onClick={addDamage} className="rounded-lg px-2 py-1 text-xs font-semibold text-indigo-600 hover:bg-indigo-50">
            + {t('workflow.handover.damageAdd')}
          </button>
        </div>
        {damageFindings.length > 0 && (
          <div className="space-y-2">
            {damageFindings.map((d, i) => (
              <div key={i} className="grid grid-cols-12 items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50/60 p-2">
                <input
                  type="text"
                  value={d.location}
                  onChange={(e) => updateDamage(i, { location: e.target.value })}
                  placeholder={t('workflow.handover.damageLocationPh')}
                  aria-label={t('workflow.handover.damageLocation')}
                  className="col-span-4 rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-indigo-500"
                />
                <select
                  value={d.severity}
                  onChange={(e) => updateDamage(i, { severity: e.target.value })}
                  aria-label={t('workflow.handover.damageSeverity')}
                  className="col-span-3 rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-indigo-500"
                >
                  {DAMAGE_SEVERITY.map((s) => <option key={s} value={s}>{t(`workflow.faultSeverity.${s}`)}</option>)}
                </select>
                <input
                  type="text"
                  value={d.note}
                  onChange={(e) => updateDamage(i, { note: e.target.value })}
                  placeholder={t('workflow.handover.damageNote')}
                  aria-label={t('workflow.handover.damageNote')}
                  className="col-span-4 rounded-lg border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-indigo-500"
                />
                <button type="button" onClick={() => removeDamage(i)} className="col-span-1 rounded-lg px-1 py-1.5 text-xs font-medium text-red-500 hover:bg-red-50">
                  {t('workflow.handover.damageRemove')}
                </button>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Missing accessories — fixed checklist, CONTRACT with config/maintenance_handover.php accessories */}
      <div>
        <span className="mb-1.5 block text-sm font-medium text-slate-700">{t('workflow.handover.accessoriesLabel')}</span>
        <div className="grid grid-cols-2 gap-1.5 sm:grid-cols-3">
          {ACCESSORY_KEYS.map((key) => (
            <label key={key} className="flex cursor-pointer items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs">
              <input
                type="checkbox"
                checked={missingAccessories.includes(key)}
                onChange={() => toggleAccessory(key)}
                className="h-3.5 w-3.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
              />
              {t(`workflow.handover.accessory.${key}`)}
            </label>
          ))}
        </div>
      </div>

      <Textarea label={t('workflow.handover.notesLabel')} value={handoverNotes} onChange={(e) => onHandoverNotes(e.target.value)} rows={2} />

      {/* Signature — mandatory, plain canvas capture (no library) */}
      <div>
        <span className="mb-1.5 block text-sm font-medium text-slate-700">{t('workflow.handover.signatureLabel')}<Req /></span>
        <SignaturePad ref={signatureRef} onChange={onSignatureChange} />
      </div>
    </>
  );
}

export default function TicketActionModal({ action, ticket, vehicles = [], garages = [], findingsCatalog = [], keywordMeta = {}, faultCausesCatalog = {}, locationCatalog = { groups: [], policy: {}, maxQuantity: 40 }, assignableDrivers = [], allowedTypes = null, onClose, onDone }) {
  const { t, tf, lang } = useI18n();
  const { user: currentUser } = useAuth();
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const [stale, setStale] = useState(false); // the ticket moved on under us (concurrent edit) → offer a refresh, not a red error

  // SYSTEM CHECKS carried by a ticket that did not arrive hydrated (opened from the board). See the
  // long note beside `requiredChecks` below for why this fallback has to exist at all.
  // `null` = still loading, `[]` = loaded and there are none.
  const [hydratedChecks, setHydratedChecks] = useState(null);
  const checksReady = Array.isArray(ticket?.required_checks) || hydratedChecks !== null;

  useEffect(() => {
    if (action !== 'decide' || !ticket?.id) return undefined;
    if (Array.isArray(ticket.required_checks)) return undefined;   // already hydrated by the caller

    let alive = true;
    api.get(`/maintenance-tickets/${ticket.id}`)
      .then((r) => { if (alive) setHydratedChecks(r.data?.data?.required_checks || []); })
      // A failed hydrate must NOT silently become "no checks" — that is the dead end this exists to
      // close. Leaving it null keeps submit disabled and shows the blocker below, so the inspector is
      // told the screen is incomplete rather than discovering it from a rejected report.
      .catch(() => { if (alive) setHydratedChecks(null); });
    return () => { alive = false; };
  }, [action, ticket?.id, ticket?.required_checks]);

  // Never dismiss the modal (backdrop / ESC / X) while a request is in flight: closing mid-submit lets
  // the operator reopen and fire the same non-idempotent action again (duplicate dispatch / notification).
  const guardedClose = () => { if (!busy) onClose?.(); };

  // Context-Aware Classification — the maintenance types this user may actually pick. `allowedTypes`
  // is the permission-scoped value list from the findings catalog (a technician gets only routine +
  // breakdown; a manager gets all six). When it's absent we fall back to the full local list so the
  // modal degrades safely. Used for BOTH the inspector's Decide grid and the manager reclassify grid.
  const visibleTypes = useMemo(
    () => (Array.isArray(allowedTypes) && allowedTypes.length
      ? MAINTENANCE_TYPES.filter((ty) => allowedTypes.includes(ty.value))
      : MAINTENANCE_TYPES),
    [allowedTypes],
  );

  // Data-purity lock: every finding already on the ticket (inspector's + any earlier garage ones) is
  // passed to the picker so it renders them "Already reported" — disabled and un-selectable — closing
  // the door on duplicates. Derived from the live ticket, so it's correct the moment the picker opens.
  const lockedFindings = useMemo(
    () => (ticket?.findings || []).map((f) => f.text).filter(Boolean),
    [ticket],
  );

  // ── REQUIRED findings — settled before this screen, and not the inspector's to drop ────────
  // An oil recall interrupted a paying customer's rental BECAUSE the car needs an oil change. By the
  // time the car reaches this report that is a decision already taken, with a driver sent and a
  // customer inconvenienced for it — so "Oil Change" arrives ticked and cannot be unticked here.
  // It stops being required only once it has actually been recorded as done.
  const requiredFindings = useMemo(() => {
    const oil = ticket?.oil_context?.recall?.required_actions?.oil_change;
    return oil?.required && !oil?.done ? ['Oil Change'] : [];
  }, [ticket]);

  // Reality-check data — the car's LIVE oil/battery/tyre status, so the Decide/Garage-finding step can
  // warn the moment a routine keyword (Oil Change, Battery Replacement, Tire Rotation/Change) is tapped
  // while the car's own status says it isn't actually due. Best-effort: only fetched for the two steps
  // that render FindingsPicker, and a fetch failure just means the warning silently stays off.
  const [diagConditions, setDiagConditions] = useState([]);
  useEffect(() => {
    if (!ticket?.id || (action !== 'decide' && action !== 'finding')) return undefined;
    let alive = true;
    api.get(`/maintenance-tickets/${ticket.id}/diagnostic-context`)
      .then((r) => { if (alive) setDiagConditions(r.data?.data?.conditions || []); })
      .catch(() => { if (alive) setDiagConditions([]); });
    return () => { alive = false; };
  }, [ticket?.id, action]);

  // Post-downtime INSPECTION checklist vs. data-driven SUGGESTED FINDINGS — two different things that
  // must never be conflated. A long-idle car carries a fixed "look these over" list (Battery / Fluids /
  // Brakes); that's an agenda to INSPECT, not a verdict that anything is due. So we split the ticket's
  // trigger_detail: the downtime condition's items render as a read-only checklist, and its keywords are
  // stripped from `suggested` so they never appear as a "tap to confirm" finding (which would ask the
  // inspector to confirm a replacement the car's data never called for). Only genuinely-due routines
  // (oil / battery / tyres over their limit) stay in the suggested row.
  const inspectChecklist = useMemo(() => {
    const items = [];
    (ticket?.trigger_detail?.rules || []).forEach((c) => {
      if (c?.directive !== 'post_downtime') return;
      (c.checklist || []).forEach((it) => { if (it && !items.includes(it)) items.push(it); });
    });
    return items;
  }, [ticket]);
  const dataSuggested = useMemo(() => {
    // A keyword flagged by the post-downtime rule is a checklist item, NOT a due verdict — strip it from
    // the tap-to-confirm row UNLESS a data-driven rule flagged the same keyword (then it's genuinely due).
    const downtime = new Set();
    const dataDriven = new Set();
    (ticket?.trigger_detail?.rules || []).forEach((c) => {
      const bucket = c?.directive === 'post_downtime' ? downtime : dataDriven;
      (c.finding_keywords || []).forEach((k) => bucket.add(String(k).toLowerCase()));
    });
    return (ticket?.suggested_findings || []).filter((k) => {
      const low = String(k).toLowerCase();
      return !downtime.has(low) || dataDriven.has(low);
    });
  }, [ticket]);

  // ---- form state (one bag; only the relevant keys are read per action) ----
  const [vehicleId, setVehicleId] = useState('');
  const [reason, setReason] = useState('test_drive');
  // The maintenance classification. On the inspector's decision ('decide') a test ALWAYS yields a
  // type — routine is the common case, so it's preselected; the inspector confirms or changes it.
  const [maintType, setMaintType] = useState(() => ticket?.maintenance_type || '');
  // NOTE: the `complaint` field is gone with the request form. The `open` (UC-1) path used to send one
  // alongside trigger_reason = customer_reported, but that reason is no longer offered anywhere in this
  // modal (INSPECTION_TRIGGER_REASONS is test_drive | periodic) — a customer issue is a Complaint now.
  const [symptoms, setSymptoms] = useState([]); // selected finding tags (library picks + custom)
  // SYSTEM CHECKS — the inspector's structured answer to each obligation the platform raised on this
  // car: { [requirementId]: { result_code, decision_code, finding_keyword } }. Radios only; there is
  // deliberately nothing here he has to type. See [[SystemChecks]] and VehicleCheckRequirement.
  const [checkAnswers, setCheckAnswers] = useState({});
  // Symptom → Root-Cause diagnosis: { [symptomText]: { root_cause, root_cause_id } }. Shared by the
  // 'decide' (inspector symptoms) and 'finding' (garage tags) steps — only one action is live at a time.
  const [causes, setCauses] = useState({});
  // WHAT IS WRONG, HOW MANY, AND WHERE — the structured detail per fault:
  //   { [symptomText]: { quantity: number, locations: string[] } }
  // Shared by the two intake steps the same way `causes` is, since only one action is live at a
  // time. Empty for every fault the inspector does not localise, which is the normal case for a
  // type whose policy says it has nowhere to point at.
  const [details, setDetails] = useState({});
  const [driverId, setDriverId] = useState('');          // delegate: chosen logistics driver
  const [recommended, setRecommended] = useState('');
  // "Requires Parts" — the inspector's TECHNICAL list of what the repair will need. Recorded with the
  // report but inert: it starts no procurement. The coordinator converts these into real part requests
  // later, once the garage is chosen. See RequiredPartsEditor.
  const [requiresParts, setRequiresParts] = useState(false);
  const [requiredParts, setRequiredParts] = useState([]);
  const [notes, setNotes] = useState('');
  // Pre-fill the reading for the release legs that take one (the pickup out, the arrival back) with the
  // car's last known odometer — the operator sees it and edits it rather than typing from scratch.
  const [odometer, setOdometer] = useState(() => {
    if (action === 'startReleaseMove' || action === 'returnFromRelease') {
      const last = lastKnownOdometer(ticket);
      return last != null ? String(last) : '';
    }
    return '';
  });
  // Temporary release, out dispatch — WHERE the car is going (free text + quick-picks).
  const [releaseDestination, setReleaseDestination] = useState('');
  // The return dispatch defaults to the garage the car LEFT — the supervisor confirms it or points the
  // car at a different shop. Everything else about the repair stays exactly where it was.
  const [vendorId, setVendorId] = useState(() => {
    if (action === 'assignReleaseReturn') {
      const back = ticket?.active_temporary_release?.return_vendor_id ?? ticket?.active_temporary_release?.vendor_id_snapshot;
      if (back) return String(back);
    }
    return ticket?.vendor_id ? String(ticket.vendor_id) : '';
  });
  const [onsiteVendor, setOnsiteVendor] = useState(''); // serviced: free-text on-site vendor/mechanic (NOT a garage from the list)
  const [assignNote, setAssignNote] = useState(''); // assign: supervisor's reason/note when (re)assigning the garage
  const [recoveryUnit, setRecoveryUnit] = useState('');  // recovery: towing unit name/ID
  const [recoveryPhone, setRecoveryPhone] = useState(''); // recovery: operator mobile
  const [outDate] = useState('');                   // dispatch: date the car left (blank → today)
  const [inDate] = useState('');                    // reinspect-pass: date the car came back (blank → today)
  const [returnDate, setReturnDate] = useState('');
  const [feedback, setFeedback] = useState('');
  const [cost, setCost] = useState('');
  // reinspect-pass: defer the invoice — the car returns to service but the ticket parks in awaiting_invoice
  // (the paper invoice isn't ready). Default ON so the workflow never blocks on late paperwork.
  const [deferInvoice, setDeferInvoice] = useState(true);
  const [outcome, setOutcome] = useState('pass'); // reinspect: pass | fail (legacy no-task tickets)
  // Per-fault re-inspection (Quality-Control): which still-open faults are STILL BROKEN, + a per-fault
  // note the supervisor reads. A car back from the garage may have some faults fixed and some not.
  const [broken, setBroken] = useState({});        // { [taskId]: true } → this fault is not fixed
  const [brokenNote, setBrokenNote] = useState({}); // { [taskId]: 'what is still wrong' }
  const [brokenReason, setBrokenReason] = useState({}); // { [taskId]: 'wrong_diagnosis' } → structured failure reason (Case B)
  // Case D — the inspector attended but genuinely could not tell (car gone, fault would not reproduce,
  // needed a road test). Kept separate from `broken` because it is NOT a failure: it must never blame a
  // garage. Recording it honestly beats guessing "fixed", which would put a false positive into the only
  // trustworthy dataset the platform has.
  const [unverifiable, setUnverifiable] = useState({});       // { [taskId]: true }
  const [unverifiableReason, setUnverifiableReason] = useState({}); // { [taskId]: 'vehicle_unavailable' }
  const [requiresMaintenance, setRequiresMaintenance] = useState(true); // decide: open ticket | clear diagnostic
  // decide: which of the five report steps is expanded. One question at a time — see <Step>.
  const [activeStep, setActiveStep] = useState(1);
  const [faultSeverity, setFaultSeverity] = useState(() => ticket?.fault_severity || ''); // decide: mandatory fault-severity grade
  // decide: Repair Location — 'in_shop' (garage → alerts Waleed & Abdullah) | 'on_site' (mobile; car stays free).
  const [repairLocation, setRepairLocation] = useState('in_shop');
  // decide: Rental Eligibility — the inspector's ONE-TIME call. false (default) = mandatory maintenance
  // (car grounded until the workshop finishes); true = deferrable (a customer may still take it — the
  // rental pauses this ticket and it resumes on return). Carried by the ticket for its whole life.
  const [deferrableForRental, setDeferrableForRental] = useState(() => !!ticket?.deferrable_for_rental);
  // assign: the data-driven garage recommendation result (for the audit trail — what was suggested vs chosen).
  const [recoResult, setRecoResult] = useState(null);
  // assign: when the supervisor picks a different garage, WHY. Captured as structured data because free
  // text cannot be counted, and an override with no reason teaches nothing except that someone disagreed.
  const [overrideReason, setOverrideReason] = useState('');
  const [overrideNote, setOverrideNote] = useState('');

  // Hard coupling (Rev. 11 Gate 1): a Breakdown is, by definition, 🔴 critical. The moment the inspector
  // classifies a ticket as Breakdown at the Decide step, force the grade to critical and lock the picker
  // so an undriveable car can never be filed as moderate/routine. The backend enforces the same rule in
  // applyBreakdownConsequences(), so this is purely the UX half of a two-sided, no-exceptions guard.
  // The supervisor has picked a garage, an engine recommendation exists, and the two differ — the one
  // situation where asking "why?" produces something learnable.
  const overrideActive = action === 'assign'
    && !!vendorId
    && (recoResult?.primary?.length || 0) > 0
    && String(recoResult.primary[0].vendor_id) !== String(vendorId);

  const severityLocked = action === 'decide' && maintType === 'breakdown';
  useEffect(() => {
    if (severityLocked && faultSeverity !== 'critical') setFaultSeverity('critical');
  }, [severityLocked, faultSeverity]);
  // The grade the risk library already holds for the findings that were ticked — offered to the
  // inspector, never written for them (see severitySuggestion).
  const severityHint = useMemo(() => severitySuggestion(symptoms, keywordMeta), [symptoms, keywordMeta]);
  // A Breakdown must be repaired in-shop (it grounds the car) — force + lock the location so it can never
  // be filed on-site (the backend rejects the contradiction too).
  const locationLocked = action === 'decide' && maintType === 'breakdown';
  useEffect(() => {
    if (locationLocked && repairLocation !== 'in_shop') setRepairLocation('in_shop');
  }, [locationLocked, repairLocation]);
  // A Breakdown grounds the car — it can never be deferred for a rental. Force + lock Mandatory (the
  // backend keeps deferrable_for_rental off for a grounded car too).
  useEffect(() => {
    if (locationLocked && deferrableForRental) setDeferrableForRental(false);
  }, [locationLocked, deferrableForRental]);
  const [photo, setPhoto] = useState(null);       // dispatch odometer: compressed { blob, url, width, height, compressedSize }
  const [compressing, setCompressing] = useState(false);
  const [findingTags, setFindingTags] = useState([]); // 'finding': selected garage-finding tags
  const [findingSeverity, setFindingSeverity] = useState('');
  // 'lineitems': the structured Parts + Labor breakdown (auto-sums into the ticket cost).
  // Seeded from the ticket on the deferred-edit ('lineitems') path so it opens with the current set.
  // Only the WORK lines. The ticket's line set also carries the VAT / discount / adjustment rows its
  // invoices wrote; those belong to a document, not to a fault, and seeding one here turned it into an
  // unlinkable "part" called VAT that tripped the Diagnosis-First gate — leaving Save disabled with
  // nothing on screen saying why, on any ticket whose bill had VAT.
  const [lineItems, setLineItems] = useState(() => (action === 'lineitems'
    ? (ticket?.line_items ?? []).filter((li) => li?.kind === 'part' || li?.kind === 'labor')
    : []));
  // Garage Invoice Validation ('lineitems'): the garage's printed receipt total + the explanation for any
  // gap between it and the itemised sum. Seeded from the ticket so a re-open shows what was recorded.
  const [receiptTotal, setReceiptTotal] = useState(() => (action === 'lineitems' && ticket?.receipt_total != null ? String(ticket.receipt_total) : ''));
  const [varianceExplanation, setVarianceExplanation] = useState(() => (action === 'lineitems' ? (ticket?.variance_explanation ?? '') : ''));
  const [followNote, setFollowNote] = useState(''); // 'followup': the Driver's update line
  const [followLog, setFollowLog] = useState(() => ticket?.follow_ups ?? []); // live note log (newest-first below)
  const [savedAt, setSavedAt] = useState(null); // `at` of the just-saved note → green "Saved ✓" highlight

  // Enterprise Handover Workflow — the full custody handover captured on BOTH 'pause' and 'resume'.
  // `notes` (above) doubles as the pause 'reason' field; `handoverNotes` is the separate optional
  // 'notes' field the backend also accepts on both legs.
  const [fuelLevel, setFuelLevel] = useState('');
  const [exteriorCondition, setExteriorCondition] = useState('');
  const [interiorCondition, setInteriorCondition] = useState('');
  const [damageFindings, setDamageFindings] = useState([]); // [{ location, severity, note }]
  const [missingAccessories, setMissingAccessories] = useState([]); // [accessory_key, ...]
  const [handoverNotes, setHandoverNotes] = useState('');
  const signatureRef = useRef(null);
  const [signatureReady, setSignatureReady] = useState(false); // re-rendered on stroke so invalid() reacts

  // Temporary Vehicle Release — the reason the car left the shop + who took it. Reuses `odometer` for the
  // reading (out on release, in on return) and `notes` for the reason detail / return note. `takenBy`
  // defaults to the signed-in user (they're the one taking custody); still editable if someone else does.
  const [releaseReason, setReleaseReason] = useState('road_test');
  const [takenBy, setTakenBy] = useState(() => currentUser?.name || '');

  const garageOptions = useMemo(
    () => garages.map((g) => ({ id: g.id, label: g.name, sub: g.phone || g.type })),
    [garages],
  );

  // Drivers first, then the supervisors — who are now assignable too, for the days no driver is free.
  // The role rides in the sub-line so the supervisor can see he is booking a colleague off his own bench
  // rather than picking a driver by mistake. The list already arrives sorted; we only label it.
  const driverOptions = useMemo(
    () => assignableDrivers.map((d) => ({
      id: d.id,
      label: d.name,
      sub: d.role === 'supervisor'
        ? [t('workflow.assign.supervisor'), d.email].filter(Boolean).join(' · ')
        : d.email,
    })),
    [assignableDrivers, t],
  );

  // ── Odometer Continuity — the "Previous Odometer" the driver should expect + the live verdict ──
  // The previous reading depends on the step: dispatch compares to the inspector's test anchor, receive
  // to the pickup, ready to the garage intake; the test-drive steps compare to the car's live odometer.
  const [odoConfirmed, setOdoConfirmed] = useState(false);
  const [odoNote, setOdoNote] = useState(''); // mandatory explanation when the reading is >10 km off the previous
  // The car's live authoritative odometer — the anchor of last resort for a ticket that carries no
  // reading of its own. A ticket born on the "straight to the garage" door skips the test drive
  // entirely, so it has neither a start-of-drive nor an end-of-drive reading: without this fallback the
  // pickup screen showed NO "Previous Odometer" at all, while the server still measured the driver's
  // entry against the vehicle's live reading and could hard-block a pickup the screen called fine.
  // `vehicle_odometer` (on the ticket resource) first, since the drivers' queue doesn't pass `vehicles`.
  const liveOdometer = useMemo(() => {
    if (ticket?.vehicle_odometer != null) return Number(ticket.vehicle_odometer);
    const v = vehicles.find((x) => String(x.id) === String(ticket?.vehicle_id));
    return v?.odometer ?? null;
  }, [ticket, vehicles]);

  const prevOdometer = useMemo(() => {
    // Dispatch (pickup FROM our park): compare against the end-of-test-drive reading when the inspector
    // logged one — using the pre-drive anchor here would wrongly hard-block every pickup after a real
    // test drive moved the car more than the ±5 km buffer. No test drive happened at all on a
    // direct-dispatch ticket, so we fall through to the car's live reading — same chain the backend's
    // dispatch() strict-match gate walks; keep the two in step.
    if (action === 'dispatch') return ticket?.report_odometer ?? ticket?.test_odometer ?? liveOdometer;
    // A tow can't change the mileage, so the field is LOCKED to this value — which means an absent
    // anchor didn't just hide the hint, it unlocked the field. Mirrors dispatchRecovery()'s chain.
    if (action === 'recovery') return ticket?.test_odometer ?? liveOdometer;
    // Decide (end-of-test-drive reading) — compared to the inspector's start-of-drive anchor, so a normal
    // short test reads as clean forward travel; a big loop trips the >10 km note.
    if (action === 'decide') return ticket?.test_odometer ?? null;
    // Collect-from-garage (garage-OUT reading) — vs the garage-arrival reading, so a forward delta reads as
    // the garage having road-tested it (garage-transfer stage → tolerance waived; backward still flags).
    if (action === 'collectFromGarage') return ticket?.receive_odometer ?? ticket?.dispatch_odometer ?? ticket?.test_odometer ?? null;
    // Arrival at our park (return-leg checkpoint 2) — compared to the garage-OUT collect reading, so a
    // short garage→base drive reads as clean forward travel; a decrease flags a Discrepancy.
    if (action === 'arriveAtPark') return ticket?.return_odometer ?? ticket?.receive_odometer ?? ticket?.dispatch_odometer ?? ticket?.test_odometer ?? null;
    if (action === 'receive') return ticket?.dispatch_odometer ?? null;
    // Re-inspection sign-off — the QC reading is compared to the last one on the chain (the garage-out
    // return reading, else the earlier captures), so it reads as clean forward continuity by default.
    if (action === 'reinspect') return ticket?.park_odometer ?? ticket?.return_odometer ?? ticket?.receive_odometer ?? ticket?.dispatch_odometer ?? ticket?.test_odometer ?? null;
    if (action === 'start') return liveOdometer;
    // 'open' anchors on the car being PICKED in this form, not the ticket's — so it reads the dropdown.
    if (action === 'open') return vehicles.find((v) => String(v.id) === String(vehicleId))?.odometer ?? null;
    return null;
  }, [action, ticket, vehicles, vehicleId, liveOdometer]);

  const contStage = useMemo(() => {
    switch (action) {
      case 'open':
      case 'start': return STAGE.TEST;
      // Decide — the end-of-test-drive reading, forward from the start anchor. The car WAS driven, so a
      // forward jump is expected (not a strict match like the start reading).
      case 'decide': return STAGE.TEST_END;
      // Awaiting Pickup — the driver collects the car from OUR PARK, so it shouldn't have moved: strict match.
      case 'dispatch': return STAGE.PARK_PICKUP;
      // Recovery is an emergency tow of a broken-down car (often from off-site) — keep the generic pickup rule.
      case 'recovery': return STAGE.PICKUP;
      // A recovery-towed leg (ticket.is_recovery — always reflects the CURRENT pickup, never a stale
      // earlier one, since dispatch() clears the recovery flag when a driver takes over) doesn't
      // accumulate mileage under its own power: the same reading at arrival is legitimate, only a
      // decrease isn't. A driven leg keeps the strict "must be higher" rule.
      case 'receive': return ticket?.is_recovery ? STAGE.GARAGE_IN_RECOVERY : STAGE.GARAGE_IN;
      // Collect-from-garage — the car leaves the garage: garage-OUT continuity (forward = a road test,
      // tolerance waived; backward still flags a Discrepancy).
      case 'collectFromGarage': return STAGE.GARAGE_OUT;
      // Arrival at our park — the car was DRIVEN back from the garage, so the reading must be strictly
      // higher than the garage-OUT reading (equal is impossible: a driven car can't arrive on the same
      // odometer it left on). Hard-blocks an equal/backward value, mirroring the backend arriveAtPark().
      case 'arriveAtPark': return STAGE.PARK_ARRIVAL;
      // 'ready' captures no odometer (car doesn't move in the workshop) → no continuity stage.
      // Re-inspection sign-off — an at-our-park spot check: once the car is back at base (the park-arrival
      // reading) it shouldn't have moved, so a strict ±5 km cap applies. That cap needs an at-base anchor:
      // with a park-arrival reading we compare to it strictly (STAGE.REINSPECT); without one we can't tell
      // a typo from the legitimate garage→base drive, so we keep the lenient forward-continuity (STAGE.RETURN).
      // Mirrors the backend close() branch — keep the prevOdometer chain (park_odometer first) in step.
      case 'reinspect': return ticket?.park_odometer != null ? STAGE.REINSPECT : STAGE.RETURN;
      default: return null;
    }
  }, [action, ticket]);

  // ── Mark ready: time-per-fault (READ-ONLY) ────────────────────────────────────────────────────
  // Nothing is entered here. The mechanic's time is already on the record by the time this screen
  // opens — clocked by the work sessions while the fault was being worked, or booked when the fault
  // was marked fixed. This gate REPORTS that time; it never asks for it again, because a second entry
  // point for the same number is a second chance to disagree with the clock. Cancelled faults
  // (non-issues) are excluded — nobody spent mechanic time on them.
  const readyFaults = useMemo(() => {
    if (action !== 'ready') return [];
    return (ticket?.tasks || [])
      .filter((f) => f.status !== 'cancelled' && !f.is_incorrect)
      .map((f) => {
        // What the clock actually measured for this fault, computed server-side by
        // FaultRepairTimeService. Hands-on session time first (waiting for a part is excluded); the
        // weaker start→release window when there are no sessions; null when the fault was never
        // clocked at all — in which case we say so rather than printing a zero that looks measured.
        const rt = f.repair_time || null;
        return {
          id: f.id,
          symptom: f.symptom,
          severity_emoji: f.severity_emoji,
          recorded: f.repair_hours != null && Number(f.repair_hours) > 0 ? Number(f.repair_hours) : null,
          measuredSeconds: rt?.cumulative_active_seconds ?? rt?.cumulative_work_seconds ?? null,
          measuredBasis: rt?.basis ?? null,
        };
      });
  }, [action, ticket]);

  // Hands-on work across every fault on the ticket — the sum of what was measured, not of what anyone
  // typed. Zero when nothing was clocked, in which case the line is simply not shown.
  const measuredTotalSeconds = useMemo(
    () => readyFaults.reduce((sum, f) => sum + (f.measuredSeconds ?? 0), 0),
    [readyFaults],
  );

  // How long the car has actually been in this workshop — from the arrival check-in until now (the
  // moment this screen is being submitted). Null when there's no arrival stamp to measure from, in
  // which case we simply don't show the line rather than guessing at one.
  const workshopSeconds = useMemo(() => {
    const startedAt = ticket?.stage_timing?.repair_started_at;
    if (action !== 'ready' || !startedAt) return null;
    const secs = (Date.now() - new Date(startedAt).getTime()) / 1000;
    return secs > 0 ? secs : null;
  }, [action, ticket]);

  const continuity = useMemo(
    () => (contStage ? evaluateContinuity(odometer, prevOdometer, contStage) : null),
    [contStage, odometer, prevOdometer],
  );
  // Context-aware tolerance: a site↔garage move (receive) is a deliberate road trip, so the mileage
  // INCREASE is expected — we waive the ±10 km note/confirm nag for it. Internal checks (test/pickup/
  // re-inspection) keep the rule. A backward reading still flags a Discrepancy on every stage.
  // Collect-from-garage is the EXCEPTION: although it uses the garage-out classification (so a forward
  // delta still reads "Garage test drive"), we keep it STRICT — the driver captures this reading by hand,
  // so a big/typo gap (e.g. 849991 vs 84999) must force the confirm + note, never be silently waived.
  const ignoreOdoTolerance = stageIgnoresTolerance(contStage) && action !== 'collectFromGarage';
  const odoNeedsConfirm = needsConfirm(continuity?.status, ignoreOdoTolerance, continuity?.stage);
  // A reading more than 10 km off the previous one (either direction) demands a written note — UNLESS this
  // is a garage transfer, where a big forward gap is the whole point of the trip. This also forces the
  // acknowledgment checkbox (see OdometerContinuityHint), so the ack requirement is the union.
  const odoNoteRequired = needsNote(continuity, ignoreOdoTolerance);
  const odoAckRequired = odoNeedsConfirm || odoNoteRequired;
  // The odometer gate for the current step: the acknowledgment (when required) AND the note (when a big
  // gap demands it) must both be satisfied before the step can submit. Steps with no odometer capture
  // have continuity === null, so both requirements are false and this is a no-op.
  // A strict-increase violation (garage intake ≤ pickup) is a hard block — it can't be acked away, so it
  // gates submit on its own regardless of the soft ack/note requirements. Mirrors the backend guard.
  const odoGateBlocked = isHardBlocked(continuity) || (odoAckRequired && !odoConfirmed) || (odoNoteRequired && !odoNote.trim());
  // Guard against an odometer reading that would overflow the DB column (unsigned INT). Only meaningful
  // when a value is actually entered; steps with no odometer input never trip it.
  const odoOutOfRange = odometer !== '' && odometer != null && Number(odometer) > MAX_ODOMETER;

  // A changed reading invalidates a prior acknowledgment + note — re-confirm/re-explain the new value.
  useEffect(() => { setOdoConfirmed(false); setOdoNote(''); }, [odometer]);

  // Auto-complete the reading on EVERY odometer-capturing stage (contStage != null): pre-fill the field
  // with the "Previous Odometer" the system expects so the step opens reading 0 km delta / Verified by
  // default. On test-drive START the car genuinely hasn't moved, so that's the true value; on the later
  // handoffs (dispatch, arrival, garage-out, re-inspection, end-of-test-drive) it's a convenient starting
  // point the operator confirms or overrides — a typed change immediately re-runs the continuity verdict.
  // Seeds once per (action, vehicle) via a ref, so switching cars re-seeds but typing/clearing is never
  // clobbered.
  const seededOdoFor = useRef(null);
  useEffect(() => {
    // A strict-increase stage (garage intake) is the exception: seeding reading = previous would open the
    // step on an invalid equal value (an instant hard block), so we leave it blank and let the driver type
    // the true, higher arrival reading — the "Previous Odometer" hint still shows what it must exceed.
    if (contStage && prevOdometer != null && !stageRequiresIncrease(contStage)) {
      const key = `${action}:${ticket?.vehicle_id ?? vehicleId ?? ''}`;
      if (seededOdoFor.current !== key) {
        seededOdoFor.current = key;
        setOdometer(String(prevOdometer));
      }
    }
  }, [action, contStage, prevOdometer, ticket, vehicleId]);

  // Re-inspection, per-fault. The checklist is EVERY fault this sign-off judges — mirroring the backend's
  // `$allFaults` (reopen: status != cancelled). Critically this INCLUDES already-`completed` faults: an
  // in-shop repair marks each fault fixed at the garage gate, so the faults arrive here `completed`
  // (terminal). Filtering those out (the old `!is_terminal`) left the checklist empty on every in-shop
  // ticket, dropping the modal to the legacy pass/fail toggle with no fault to flag — so "Fail" posted an
  // empty failed_task_ids and the backend rejected it ("Flag at least one fault…"). We only exclude
  // cancelled faults (non-issues), exactly as the backend does. With faults present the outcome is DERIVED
  // (any fault still broken → fail); a legacy ticket with no faults keeps the manual pass/fail toggle.
  const openFaults = useMemo(
    () => (ticket?.tasks || []).filter((tk) => tk.status !== 'cancelled'),
    [ticket],
  );
  const perFault = action === 'reinspect' && openFaults.length > 0;
  const brokenIds = openFaults.filter((tk) => broken[tk.id]).map((tk) => tk.id);
  const unverifiableIds = openFaults.filter((tk) => unverifiable[tk.id]).map((tk) => tk.id);
  // Sent on BOTH branches: a ticket can have some faults still broken and others unverifiable.
  const unverifiablePayload = () => ({
    unverifiable_task_ids: unverifiableIds,
    unverifiable_reasons: Object.fromEntries(
      unverifiableIds.map((id) => [id, unverifiableReason[id] || 'unverifiable_other']),
    ),
  });
  const reFail = perFault ? brokenIds.length > 0 : outcome === 'fail';
  // On a FAILED re-inspection the inspector sees the garage the car came back broken from and may
  // re-route it to a DIFFERENT garage for the supervisor's re-dispatch — changing it warns and
  // makes the reason note mandatory.
  const reFailGarageChanged = action === 'reinspect' && reFail && !!vendorId && String(vendorId) !== String(ticket?.vendor_id || '');

  // Compress the odometer photo on-device (6–12 MP phone shot → ~200–600 KB) before it
  // rides along with the dispatch POST. Optional — the dispatch works fine without it.
  async function onOdometerPhoto(e) {
    const file = e.target.files?.[0];
    e.target.value = ''; // let the same file be re-picked after a Remove
    if (!file) return;
    setCompressing(true);
    setErr('');
    try {
      setPhoto(await compressImage(file, { maxDimension: 1400, quality: 0.7 }));
    } catch {
      setErr(t('workflow.photo.readError'));
    } finally {
      setCompressing(false);
    }
  }

  // Symptom → Root-Cause payload: only symptoms with a chosen cause are sent. A null root_cause_id
  // marks a custom cause the server will record as 'pending' for admin review.
  const buildCauses = (syms) =>
    (syms || [])
      .filter((s) => causes[s]?.root_cause)
      .map((s) => ({ symptom: s, root_cause: causes[s].root_cause, root_cause_id: causes[s].root_cause_id ?? null }));

  // ---- build (endpoint, body) for the current action ----
  function resolve() {
    const base = `/maintenance-tickets`;
    switch (action) {
      // 'request' is NOT handled here — it is SendCarInModal's, endpoint and all.
      case 'start':
        // Inspector's odometer at test-drive start (the chain anchor) — posted multipart with the photo.
        return { url: `${base}/${ticket.id}/start`, body: { test_odometer: Number(odometer) } };
      case 'followup':
        return { url: `${base}/${ticket.id}/follow-up`, body: { note: followNote } };
      case 'open':
        // No maintenance_type here — the inspection isn't done yet. Classification is set later in
        // the Submit Report ('decide') step, once the inspector has actually diagnosed the car.
        // test_odometer (+ photo) is captured up front and posted multipart (see submit()).
        return { url: base, body: { vehicle_id: Number(vehicleId), trigger_reason: reason, test_odometer: Number(odometer) } };
      case 'typechange':
        return { url: `${base}/${ticket.id}/type`, body: { maintenance_type: maintType }, method: 'patch' };
      case 'decide':
        return { url: `${base}/${ticket.id}/report`, body: { requires_maintenance: requiresMaintenance, symptoms, causes: buildCauses(symptoms), details: buildDetails(symptoms, details, locationCatalog.policy), fault_severity: requiresMaintenance ? (faultSeverity || null) : null, recommended_action: recommended || null, notes: notes || null, maintenance_type: maintType || null, repair_location: requiresMaintenance ? repairLocation : null, report_odometer: odometer ? Number(odometer) : null, odometer_note: odoNote.trim() || null, odometer_confirmed: odoAckRequired ? odoConfirmed : null, required_parts: requiresMaintenance && requiresParts ? cleanRequiredParts(requiredParts) : null, check_results: checkPlan.rows } };
      case 'delegate':
        // No task is sent — the backend derives pick-up vs drop-off from where the car physically is.
        return { url: `${base}/${ticket.id}/delegate`, body: { driver_id: Number(driverId) } };
      case 'assign': {
        // Decision-support audit: record what the recommendation engine suggested and whether the
        // supervisor followed it, so the garage_assigned event can answer "why this garage?".
        let recommendation = null;
        const recPrimary = recoResult?.primary || [];
        if (recPrimary.length) {
          const chosen = Number(vendorId);
          const match = recPrimary.find((p) => Number(p.vendor_id) === chosen);
          const top = recPrimary[0];
          const src = match || top;
          const isOverride = Number(top.vendor_id) !== chosen;
          recommendation = {
            recommended_vendor_id: top.vendor_id,
            accepted: !!match,
            rank: match ? match.rank : null,
            score: src.score,
            // ── Feedback loop ────────────────────────────────────────────────────────────────────
            // An override is only learnable next to WHERE the chosen garage stood. Both scores are
            // sent explicitly: `match_score` below is the chosen garage's (what the supervisor acted
            // on), so the gap needs the recommended one stated separately or it computes to zero on
            // every override — which would make every disagreement look like a tie.
            recommended_match_score: top.match_score ?? null,
            chosen_match_score: match ? (match.match_score ?? null) : null,
            chosen_rank: match ? match.rank : null,
            override_reason: isOverride ? (overrideReason || null) : null,
            // The note is offered on ANY override, not only "Other". The taxonomy says which axis the
            // supervisor traded on; the note is where the specific fact lives ("they had the part in
            // stock"), and that is usually the thing worth acting on.
            override_note: isOverride ? (overrideNote.trim() || null) : null,
            // What the chosen garage was MEASURABLY better at, judged from the same figures on
            // screen — so the stated reason can later be checked against the data rather than taken
            // on trust. Only garage-grain figures count; a fleet fallback is the same number for
            // everyone and can never make one garage better than another.
            chosen_advantages: isOverride && match ? measuredAdvantages(match, top) : null,
            // The number the supervisor actually saw, plus the factor breakdown behind it and the
            // one-garage-vs-split call — so "why this garage?" stays answerable months later.
            match_score: src.match_score ?? null,
            breakdown: src.breakdown || null,
            strategy: recoResult?.strategy || null,
            // The forecast that was on screen when the call was made — recorded so it can later be
            // scored against what actually happened.
            expected_outcomes: src.outcomes || null,
            fault_criticality: recoResult?.criteria?.fault_criticality || null,
            // Engine build, policy version, tuning fingerprint and data date — so this decision stays
            // explainable after all three have moved on.
            provenance: recoResult?.provenance || null,
            confidence: src.confidence || null,
            reason: (src.reasons || []).map((r) => r.t).slice(0, 3).join('; ') || null,
            reasons: (src.reasons || []).slice(0, 4),
            criteria: recoResult?.criteria || null,
            source: 'experience_engine',
          };
        }
        return { url: `${base}/${ticket.id}/assign-dispatch`, body: { vendor_id: Number(vendorId), driver_id: driverId ? Number(driverId) : null, expected_return_date: returnDate || null, note: assignNote.trim() || null, recommendation } };
      }
      case 'dispatch':
        // Garage is the supervisor's pre-assigned choice — the driver doesn't send it.
        return { url: `${base}/${ticket.id}/dispatch`, body: { dispatch_odometer: Number(odometer), out_date: outDate || null, expected_return_date: returnDate || null } };
      case 'recovery':
        // Towing variant — multipart (odometer + mandatory photo + the recovery unit) is built in submit().
        return { url: `${base}/${ticket.id}/recovery-dispatch`, body: {} };
      case 'receive':
        return { url: `${base}/${ticket.id}/under-repair`, body: { receive_odometer: odometer ? Number(odometer) : null, garage_feedback: feedback || null, expected_return_date: returnDate || null } };
      case 'ready':
        return { url: `${base}/${ticket.id}/ready`, body: { garage_feedback: feedback || null } };
      case 'lineitems':
        // Deferred edit / Garage Invoice Validation — replace the whole Parts + Labor set (JSON PUT, any
        // state), reconciled against the receipt total with a mandatory explanation for any variance.
        return {
          url: `${base}/${ticket.id}/line-items`,
          method: 'put',
          body: {
            line_items: serializeLineItems(lineItems),
            receipt_total: receiptTotal === '' ? null : Number(receiptTotal),
            variance_explanation: varianceExplanation.trim() || null,
          },
        };
      case 'serviced':
        // On-Site (mobile) lane — the entire back-half of the workflow collapsed into one step:
        // no garage, no re-inspection, no QA. Cost/vendor/notes are all optional. The vendor is a
        // free-text on-site mechanic name (no garage picker — the car never left).
        return { url: `${base}/${ticket.id}/mark-serviced`, body: { notes: notes || null, cost: cost === '' ? null : Number(cost), vendor_name: onsiteVendor.trim() || null } };
      case 'requestinvoice':
        // Path A — ask the garage for an itemised invoice (the team is alerted to chase it).
        return { url: `${base}/${ticket.id}/request-invoice`, body: {} };
      case 'pause':
      case 'resume':
        // Pause / Resume — Enterprise Handover Workflow: both legs are always multipart (mandatory
        // odometer photo + signature), so the JSON body here is unused — see submit().
        return { url: `${base}/${ticket.id}/${action}`, body: {} };
      case 'markReturned':
        // Vehicle Physically Returned — a light checkpoint, no odometer/handover required.
        return { url: `${base}/${ticket.id}/mark-returned`, body: { note: notes.trim() || null } };
      case 'temporarilyRelease':
        // Temporary Vehicle Release — the DECISION to let the car leave mid-repair (the ticket stays at
        // its stage). Nothing moves yet: reason + who it's for. `notes` carries the free-text detail
        // (required for "other"). The trip's legs are the six cases below.
        return { url: `${base}/${ticket.id}/temporary-release`, body: { reason: releaseReason, reason_note: notes.trim() || null, taken_by: takenBy.trim() } };
      case 'cancelRelease':
        // Taking the release back before the car ever moved — an optional word on why.
        return { url: `${base}/${ticket.id}/temporary-release/cancel`, body: { reason: notes.trim() || null } };
      case 'assignReleaseMove':
        // Out dispatch — where the car goes, and who drives it (blank = open to the driver pool).
        return { url: `${base}/${ticket.id}/temporary-release/assign`, body: { destination: releaseDestination.trim(), driver_id: driverId ? Number(driverId) : null, note: notes.trim() || null } };
      case 'startReleaseMove':
        // Out pickup — the car physically leaves the garage now, so THIS is where the OUT reading is taken.
        return { url: `${base}/${ticket.id}/temporary-release/pickup`, body: { release_odometer: Number(odometer) } };
      case 'arriveAtDestination':
        // Out arrival — parked at the destination; the ticket waits there until it's called back.
        return { url: `${base}/${ticket.id}/temporary-release/arrive`, body: { note: notes.trim() || null } };
      case 'requestReleaseReturn':
        // Call it back — re-opens the dispatch queue for the return leg. No fields.
        return { url: `${base}/${ticket.id}/temporary-release/request-return`, body: {} };
      case 'assignReleaseReturn':
        // Return dispatch — confirm or change the garage the car goes back to, and pick a driver.
        return { url: `${base}/${ticket.id}/temporary-release/assign-return`, body: { vendor_id: vendorId ? Number(vendorId) : null, driver_id: driverId ? Number(driverId) : null, note: notes.trim() || null } };
      case 'startReleaseReturn':
        // Return pickup — collected from where it was parked, heading to the garage. No fields.
        return { url: `${base}/${ticket.id}/temporary-release/return-pickup`, body: {} };
      case 'returnFromRelease':
        // Back in the workshop — the odometer IN (distance is computed server-side) + an optional note.
        return { url: `${base}/${ticket.id}/return-from-release`, body: { return_odometer: Number(odometer), return_note: notes.trim() || null } };
      case 'approveRepair':
        // Supervisor Video-Review — APPROVE (video reviewed) → advance to re-inspection. No fields.
        return { url: `${base}/${ticket.id}/approve-repair`, body: {} };
      case 'requestRefix':
        // Supervisor Video-Review — REQUEST A RE-FIX → back to the same garage, carrying the reason.
        return { url: `${base}/${ticket.id}/request-refix`, body: { reason: notes } };
      case 'collectFromGarage':
        // Driver's RETURN leg, first checkpoint — just the mandatory "received from garage" photo.
        return { url: `${base}/${ticket.id}/collect-from-garage`, body: {} };
      case 'arriveAtPark':
        // Driver's RETURN leg, second checkpoint — mandatory arrival photo; the server auto-branches by
        // repair severity (minor auto-closes, major routes to the final QA re-inspection).
        return { url: `${base}/${ticket.id}/arrive-at-park`, body: { notes: notes || null } };
      case 'finding': {
        // Each finding carries its own count + places (withDetails leaves a fault that has neither
        // exactly as it was, so a mechanic who skips the detail step files what he always did).
        const list = withDetails(
          findingTags.map((text) => ({ text, severity: findingSeverity || null, root_cause: causes[text]?.root_cause || null, root_cause_id: causes[text]?.root_cause_id ?? null })),
          details,
          locationCatalog.policy,
        );
        return { url: `${base}/${ticket.id}/findings`, body: { findings: list } };
      }
      case 'reinspect': {
        if (!reFail) {
          // PASS — every fault verified fixed → car returns to service. If the invoice isn't ready, defer
          // it: the car still goes back, the ticket parks in awaiting_invoice (never blocks on paperwork).
          return { url: `${base}/${ticket.id}/close`, body: { cost: cost === '' ? null : Number(cost), vendor_id: vendorId ? Number(vendorId) : null, actual_in_date: inDate || null, notes: notes || null, defer_invoice: deferInvoice, final_odometer: odometer ? Number(odometer) : null, odometer_note: odoNote.trim() || null, odometer_confirmed: odoAckRequired ? odoConfirmed : null, ...unverifiablePayload() } };
        }
        // FAIL — some faults still broken → back to the supervisor for re-dispatch, with per-fault blame.
        // Each still-broken fault carries a STRUCTURED failure reason (Case B) so a failed repair is
        // never just a free-text note — it feeds the repeated-failure / part-failure intelligence.
        const failed_notes = {};
        const failure_reasons = {};
        brokenIds.forEach((id) => {
          if (brokenNote[id]?.trim()) failed_notes[id] = brokenNote[id].trim();
          failure_reasons[id] = brokenReason[id] || 'unknown';
        });
        return { url: `${base}/${ticket.id}/reopen`, body: { reason: notes || null, failed_task_ids: brokenIds, failed_notes, failure_reasons, redispatch_vendor_id: reFailGarageChanged ? Number(vendorId) : null, ...unverifiablePayload() } };
      }
      default:
        return null;
    }
  }

  // A report may not contradict itself. Findings ARE the reason a car needs a ticket, so tapping faults
  // and then filing "no maintenance needed" states two opposite things at once. It used to save happily
  // and lose them: the findings are written to the ticket either way, but a cleared diagnostic is
  // terminal and never promotes them into fault tasks, so nobody would ever see those faults again.
  // The server enforces the same rule (MaintenanceWorkflowService::submitReport) — this is the readable half.
  const clearanceWithFindings = action === 'decide' && !requiresMaintenance && symptoms.length > 0;

  // ---- minimal client guard (the server is the source of truth) ----
  function invalid() {
    // An out-of-range odometer would overflow the DB column — block every step that captures one.
    if (odoOutOfRange) return true;
    // Re-inspection: on FAIL, re-routing to a different garage needs a written reason (same-garage fail
    // is unaffected). On PASS the car is physically back, so the final QC odometer is mandatory — plus the
    // shared >10 km ack/note gate. No odometer is asked on the FAIL branch (the car goes back out).
    if (action === 'reinspect') {
      if (reFail) {
        // Every still-broken fault needs a structured failure reason (Case B). Re-routing to a different
        // garage additionally needs a written reason (same-garage fail is unaffected).
        const missingReason = perFault && brokenIds.some((id) => !brokenReason[id]);
        // An unverifiable fault without a stated reason is the same hole in the data as no verdict at
        // all — "we could not check" is only useful evidence when it says why.
        const missingUnverifiable = unverifiableIds.some((id) => !unverifiableReason[id]);
        return missingReason || missingUnverifiable || (reFailGarageChanged && !notes.trim());
      }
      // Same rule on the PASS branch: some faults may be signed off while others could not be checked.
      if (unverifiableIds.some((id) => !unverifiableReason[id])) return true;
      return !odometer || Number(odometer) < 1 || odoGateBlocked;
    }
    // Open & Start: the inspector captures the odometer reading + photo BEFORE the test drive (both mandatory).
    if (action === 'open') return !vehicleId || !odometer || Number(odometer) < 1 || !photo || compressing || odoGateBlocked;
    if (action === 'start') return !odometer || Number(odometer) < 1 || !photo || compressing || odoGateBlocked;
    if (action === 'typechange') return !maintType;
    // Opening a ticket requires a mandatory fault-severity grade (a cleared diagnostic needs none).
    // When it opens a ticket, every symptom that has a preset cause-list must also be diagnosed
    // (Symptom → Root-Cause). A cleared diagnostic skips the cause gate. Classification (maintType)
    // is no longer gated here — it can be set later. The end-of-test-drive odometer is OPTIONAL here
    // (the inspector may record it), but if entered it must clear the same continuity/>10 km note gate
    // as every other capture (odoGateBlocked is false when blank).
    // …and every system check the platform raised must carry an explicit result. This is the client
    // half of the rule that "nobody looked" stops being a possible outcome — the server refuses the
    // same report independently (planCheckAnswers), so the two cannot drift apart silently.
    // `!checksReady` — we do not yet know whether this ticket owes any system checks. Submitting
    // blind is exactly how a report gets rejected by the server-side gate with nothing on screen to
    // fix, so hold the button until the answer is in.
    if (action === 'decide') return !checksReady || (requiresMaintenance && (!faultSeverity || !rootCausesComplete(symptoms, faultCausesCatalog, causes) || missingLocations.length > 0)) || clearanceWithFindings || odoGateBlocked || checkPlan.unanswered.length > 0 || (!requiresMaintenance && checkBornFindings.length > 0);
    if (action === 'followup') return !followNote.trim();
    if (action === 'dispatch') return !odometer || Number(odometer) < 1 || !photo || compressing || odoGateBlocked;
    // Recovery (towing): the odometer + its photo AND the recovery unit name are all mandatory (the
    // operator phone is optional). A garage is also mandatory — either the ticket already has one, or
    // it's picked right here (decoupled from the classic "Assign Garage" screen). Same odometer-continuity
    // acknowledgment as a normal pickup.
    if (action === 'recovery') return !odometer || Number(odometer) < 1 || !photo || compressing || !recoveryUnit.trim() || (!ticket?.vendor_id && !vendorId) || odoGateBlocked;
    // Arrival check-in ("Now at Garage"): the arrival odometer AND its photo are BOTH mandatory (the
    // integrity gate before the car enters the workshop); acknowledge abnormal continuity too.
    if (action === 'receive') return !odometer || Number(odometer) < 1 || !photo || compressing || odoGateBlocked;
    // Mark ready ("Maintenance complete"): no odometer/photo, no cost/parts, and no time entry — the
    // mechanic's hours are already on the record. Nothing on this screen can block the confirmation.
    if (action === 'ready') return false;
    // Cost, parts & labor are itemised later through the invoice link.
    // Garage Invoice Validation: every line linked to a finding (Diagnosis-First), AND the itemised sum
    // reconciled against the receipt total (a mismatch needs a variance explanation before saving).
    if (action === 'lineitems') {
      // Check against the SERIALIZED rows — the exact payload the server validates (same qty/price defaults).
      const rows = serializeLineItems(lineItems);
      return lineItemsUnlinked(lineItems)
        || lineItemsHaveZeroCost(rows)
        || invoiceVarianceBlocked({ rows, receiptTotal, variance: varianceExplanation });
    }
    // Garage findings: at least one tag, and every tag with preset causes must carry a diagnosed cause.
    if (action === 'finding') return findingTags.length === 0 || !rootCausesComplete(findingTags, faultCausesCatalog, causes) || missingFindingLocations.length > 0;
    if (action === 'delegate') return !driverId;
    if (action === 'assign') return !vendorId; // garage required; driver is optional (may go to the pool)
    // Supervisor Video-Review: a re-fix needs a reason; approval is blocked only when we KNOW there's no
    // video yet (has_video === false). When it's unknown (null) we let the server enforce the video rule.
    if (action === 'requestRefix') return !notes.trim();
    if (action === 'approveRepair') return ticket?.has_video === false;
    // Both return-leg checkpoints CANNOT complete without their mandatory photo.
    // Collect from garage: the garage-OUT odometer AND the "received from garage" photo are both mandatory.
    if (action === 'collectFromGarage') return !odometer || Number(odometer) < 1 || !photo || compressing || odoGateBlocked;
    // Arrival at park: the arrival odometer AND its photo are both mandatory (the reading is the at-base
    // anchor the final QA sign-off's ±5 km cap needs), and the reading must clear the continuity/>10 km gate.
    if (action === 'arriveAtPark') return !odometer || Number(odometer) < 1 || !photo || compressing || odoGateBlocked;
    // Pause / Resume — Enterprise Handover Workflow: odometer + photo + fuel + both conditions +
    // signature are all mandatory (matches the backend's `required` validation on both legs).
    if (action === 'pause' || action === 'resume') {
      return !odometer || Number(odometer) < 1 || !photo || compressing
        || !fuelLevel || !exteriorCondition.trim() || !interiorCondition.trim()
        || !signatureReady;
    }
    // Temporary release: a reason is mandatory and "Other" needs a detail. Nothing else — the car hasn't
    // moved yet, so there is no reading to take. `taken_by` is NOT gated (it defaults to the signed-in
    // user server-side when left blank).
    if (action === 'temporarilyRelease') {
      return !releaseReason || (releaseReason === 'other' && !notes.trim());
    }
    // Out dispatch: a destination is mandatory (the driver has to be told where to go); the driver is
    // optional — leaving it blank opens the pickup to the whole pool, like a garage dispatch.
    if (action === 'assignReleaseMove') return !releaseDestination.trim();
    // Out pickup: the reading as the car leaves the garage is the trip's start anchor.
    if (action === 'startReleaseMove') return !odometer || Number(odometer) < 1;
    // Return dispatch: a garage is mandatory (it defaults to the one the car left).
    if (action === 'assignReleaseReturn') return !vendorId;
    // Back in the workshop: the IN odometer is mandatory and can't be below the recorded OUT reading.
    if (action === 'returnFromRelease') {
      const outKm = ticket?.active_temporary_release?.odometer_out;
      return !odometer || Number(odometer) < 1 || (outKm != null && Number(odometer) < Number(outKm));
    }
    return false;
  }

  // The odometer Capture/Upload tile, shared by Dispatch (pre) and Mark-ready (post).
  const photoTile = photo ? (
    <div className="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 p-2">
      <img src={photo.url} alt={t('workflow.field.odometerPhoto')} className="h-16 w-16 rounded-lg object-cover ring-1 ring-slate-200" />
      <div className="min-w-0 text-xs text-slate-500">
        <p className="font-semibold text-slate-700">{t('workflow.photo.ready')}</p>
        <p className="tabular-nums">{formatBytes(photo.compressedSize)} · {photo.width}×{photo.height}</p>
      </div>
      <button type="button" onClick={() => setPhoto(null)} className="ms-auto rounded-lg px-2 py-1 text-xs font-medium text-red-500 hover:bg-red-50">{t('common.remove')}</button>
    </div>
  ) : (
    <label className={`flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed px-4 py-4 text-sm transition ${compressing ? 'border-slate-200 text-slate-400' : 'border-slate-300 text-slate-500 hover:border-indigo-400 hover:text-indigo-600'}`}>
      <Icon.Gauge className="h-5 w-5" />
      {compressing ? t('workflow.photo.processing') : t('workflow.photo.scan')}
      <input type="file" accept="image/*" capture="environment" className="hidden" onChange={onOdometerPhoto} disabled={compressing} />
    </label>
  );

  // High-consequence, hard-to-undo steps get a tailored confirmation before they fire (a mis-click on
  // the re-inspection FAIL wrongly blames a garage and re-queues the car; a PASS returns it to the
  // rentable fleet). Returns the confirm message, or null when no confirmation is needed.
  function confirmPrompt() {
    const who = ticket ? (ticket.plate || `#${ticket.id}`) : '';
    if (action === 'reinspect') {
      if (reFail) {
        const n = Object.values(broken).filter(Boolean).length;
        return t('workflow.confirm.reinspectFail', { who, count: n });
      }
      return t('workflow.confirm.reinspectPass', { who });
    }
    return null;
  }

  async function submit() {
    const r = resolve();
    if (!r) return;
    const ask = confirmPrompt();
    if (ask && !window.confirm(ask)) return;
    setBusy(true);
    setErr('');
    setStale(false);
    try {
      // Steps that carry the odometer image go as multipart (ingested server-side): the test-drive
      // start (open/start), dispatch, and ready — all carry the mandatory odometer photo. JSON otherwise.
      let resp;
      const isTestStart = action === 'start' || action === 'open';
      if (r.method === 'patch') {
        resp = await api.patch(r.url, r.body);
      } else if (r.method === 'put') {
        resp = await api.put(r.url, r.body);
      } else if ((action === 'dispatch' && photo?.blob) || (action === 'recovery' && photo?.blob) || (action === 'receive' && photo?.blob) || action === 'ready' || (isTestStart && photo?.blob) || (action === 'collectFromGarage' && photo?.blob) || (action === 'arriveAtPark' && photo?.blob) || (action === 'decide' && photo?.blob) || ((action === 'pause' || action === 'resume') && photo?.blob)) {
        const fd = new FormData();
        if (action === 'dispatch') {
          fd.append('dispatch_odometer', String(Number(odometer)));
          if (outDate) fd.append('out_date', outDate);
          if (returnDate) fd.append('expected_return_date', returnDate);
        } else if (action === 'recovery') {
          // Towing pickup — the odometer/condition photo gate still applies; the entity is a Recovery Unit.
          // Decoupled from the classic "Assign Garage" screen: when the ticket has no garage yet, the
          // destination is picked right here and sent along in the same request.
          fd.append('dispatch_odometer', String(Number(odometer)));
          fd.append('recovery_unit_name', recoveryUnit.trim());
          if (recoveryPhone.trim()) fd.append('recovery_unit_phone', recoveryPhone.trim());
          if (!ticket?.vendor_id && vendorId) fd.append('vendor_id', String(Number(vendorId)));
        } else if (action === 'receive') {
          // Arrival check-in — the mandatory arrival odometer + its photo, plus optional intake notes.
          fd.append('receive_odometer', String(Number(odometer)));
          if (feedback) fd.append('garage_feedback', feedback);
          if (returnDate) fd.append('expected_return_date', returnDate);
        } else if (action === 'ready') {
          // No odometer here — the car doesn't move inside the workshop. Cost, parts & labor are NOT
          // captured at this step; they're itemised later through the invoice link.
          if (feedback) fd.append('garage_feedback', feedback);
          // No `repair_times` — this screen no longer collects labor hours. Each fault's time is
          // already written by the work-session clock (or when the fault was marked fixed), so
          // re-posting it here could only ever contradict the record it duplicates.
        } else if (action === 'collectFromGarage') {
          // Garage-OUT reading (car leaves the garage) + the mandatory "received from garage" photo below.
          fd.append('return_odometer', String(Number(odometer)));
        } else if (action === 'arriveAtPark') {
          // Optional arrival odometer — only sent when the driver entered one; the shared odometer_note /
          // odometer_confirmed appenders below carry the >10 km gap ack when the gate asked for it.
          if (odometer) fd.append('park_odometer', String(Number(odometer)));
          if (notes) fd.append('notes', notes);
        } else if (action === 'pause' || action === 'resume') {
          // Enterprise Handover Workflow — the full custody handover, mirrored field-for-field against
          // MaintenanceWorkflowController::pause()/resume(). `notes` (state) doubles as pause's optional
          // 'reason'; `handoverNotes` is the separate optional 'notes' field both legs accept.
          fd.append(action === 'pause' ? 'pause_odometer' : 'resume_odometer', String(Number(odometer)));
          fd.append('fuel_level', fuelLevel);
          fd.append('exterior_condition', exteriorCondition.trim());
          fd.append('interior_condition', interiorCondition.trim());
          damageFindings.forEach((d, i) => {
            if (!d.location?.trim()) return;
            fd.append(`damage_findings[${i}][location]`, d.location.trim());
            fd.append(`damage_findings[${i}][severity]`, d.severity || 'routine');
            if (d.note?.trim()) fd.append(`damage_findings[${i}][note]`, d.note.trim());
          });
          missingAccessories.forEach((a) => fd.append('missing_accessories[]', a));
          if (action === 'pause' && notes.trim()) fd.append('reason', notes.trim());
          if (handoverNotes.trim()) fd.append('notes', handoverNotes.trim());
          const sigBlob = await signatureRef.current?.getBlob();
          if (sigBlob) fd.append('signature', sigBlob, 'signature.png');
        } else if (action === 'decide') {
          // Submit Report — same fields as the JSON body in resolve(), multipart only because the
          // odometer photo rides along. Laravel's 'boolean' rule accepts 1/0/"1"/"0"/true/false but NOT
          // the strings "true"/"false" that String(bool) yields — and FormData can't carry a real bool —
          // so send '1'/'0' (mirrors odometer_confirmed below).
          fd.append('requires_maintenance', requiresMaintenance ? '1' : '0');
          symptoms.forEach((s) => fd.append('symptoms[]', s));
          buildDetails(symptoms, details, locationCatalog.policy).forEach((d, i) => {
            fd.append(`details[${i}][symptom]`, d.symptom);
            fd.append(`details[${i}][quantity]`, String(d.quantity));
            d.locations.forEach((slug, j) => fd.append(`details[${i}][locations][${j}]`, slug));
          });
          buildCauses(symptoms).forEach((c, i) => {
            fd.append(`causes[${i}][symptom]`, c.symptom);
            if (c.root_cause) fd.append(`causes[${i}][root_cause]`, c.root_cause);
            if (c.root_cause_id != null) fd.append(`causes[${i}][root_cause_id]`, String(c.root_cause_id));
          });
          // SYSTEM CHECKS — carried on the multipart path too. A report filed WITH an odometer photo
          // must answer the same obligations as one filed without; the server refuses either way, and
          // dropping them here would surface that refusal as a mysterious 422 on the photo branch only.
          checkPlan.rows.forEach((c, i) => {
            fd.append(`check_results[${i}][id]`, String(c.id));
            fd.append(`check_results[${i}][result_code]`, c.result_code);
            if (c.decision_code) fd.append(`check_results[${i}][decision_code]`, c.decision_code);
            if (c.finding_keyword) fd.append(`check_results[${i}][finding_keyword]`, c.finding_keyword);
          });
          if (requiresMaintenance && faultSeverity) fd.append('fault_severity', faultSeverity);
          if (recommended) fd.append('recommended_action', recommended);
          if (notes) fd.append('notes', notes);
          if (maintType) fd.append('maintenance_type', maintType);
          if (requiresMaintenance && repairLocation) fd.append('repair_location', repairLocation);
          if (requiresMaintenance) fd.append('deferrable_for_rental', deferrableForRental ? '1' : '0');
          // Required parts — technical only, and only when a ticket is actually opened (a cleared
          // diagnostic needed no work, so it needs no parts).
          if (requiresMaintenance && requiresParts) {
            cleanRequiredParts(requiredParts).forEach((p, i) => {
              fd.append(`required_parts[${i}][part_name]`, p.part_name);
              fd.append(`required_parts[${i}][quantity]`, String(p.quantity));
              fd.append(`required_parts[${i}][priority]`, p.priority);
              if (p.notes) fd.append(`required_parts[${i}][notes]`, p.notes);
              if (p.finding) fd.append(`required_parts[${i}][finding]`, p.finding);
            });
          }
          if (odometer) fd.append('report_odometer', String(Number(odometer)));
        } else {
          // start | open — the inspector's odometer reading at test-drive start (the chain anchor).
          fd.append('test_odometer', String(Number(odometer)));
          if (action === 'open') {
            fd.append('vehicle_id', String(Number(vehicleId)));
            fd.append('trigger_reason', reason);
          }
        }
        // Odometer note — the mandatory explanation for a >10 km gap from the previous reading. Applies
        // to every odometer-capturing step; only ever set when the gate demanded it, so it's blank otherwise.
        if (odoNote.trim()) fd.append('odometer_note', odoNote.trim());
        // The "I've checked — this reading is correct" tick — sent whenever the continuity nag asked for
        // it, so the Mileage oversight board (/oversight/mileage) can show it was actively acknowledged.
        // Laravel's 'boolean' rule only accepts 1/0/"1"/"0"/true/false — NOT the strings "true"/"false" that
        // String(bool) would produce, which FormData is otherwise limited to since it can't carry a real bool.
        if (odoAckRequired) fd.append('odometer_confirmed', odoConfirmed ? '1' : '0');
        if (photo?.blob) fd.append('odometer_photo', photo.blob, 'odometer.jpg');
        resp = await api.post(r.url, fd);
      } else {
        resp = await api.post(r.url, r.body);
      }

      // Resume may come back with blocked_by_incident: true — the handover was saved and the request
      // is a 200, but the transition is HELD pending a supervisor's acknowledgement (a discrepancy
      // exceeded the configured thresholds). This is an expected, non-error outcome: surface it as a
      // distinct message instead of the normal "resumed" success toast; the ticket stays paused.
      if (action === 'resume' && resp?.data?.data?.blocked_by_incident) {
        const who = ticket ? (ticket.plate || `#${ticket.id}`) : 'Ticket';
        onDone?.(t('workflow.success.resumeBlockedByIncident', { who }));
        return;
      }

      // Follow-up is a running log, not a one-shot transition: keep the modal open so the writer sees
      // the new note land as a fresh bubble at the top, clear the input, and stay ready for the next.
      if (action === 'followup') {
        const updated = resp?.data?.data;
        const log = Array.isArray(updated?.follow_ups) ? updated.follow_ups : followLog;
        setFollowLog(log);
        setSavedAt(log.length ? log[log.length - 1].at : null);
        setFollowNote('');
        return;
      }

      onDone?.(successMessage());
    } catch (e) {
      // Distinguish a STALE-STATE conflict (someone else advanced this ticket while the modal was open —
      // the server's transition guard rejects it with from/to/allowed context) from a normal, fixable
      // error. A stale conflict isn't the operator's fault, so we prompt a refresh instead of a red error.
      const ctx = e.response?.data?.data;
      const staleConflict = e.response?.status === 422 && ctx && ctx.from && Array.isArray(ctx.allowed);
      if (staleConflict) {
        setStale(true);
        setErr(t('workflow.error.stale'));
      } else {
        setStale(false);
        setErr(e.response?.data?.message || t('workflow.error.generic'));
      }
    } finally {
      setBusy(false);
    }
  }

  function successMessage() {
    const who = ticket ? (ticket.plate || `#${ticket.id}`) : 'Ticket';
    switch (action) {
      case 'start': return t('workflow.success.start', { who });
      case 'followup': return t('workflow.success.followup', { who });
      case 'open': return t('workflow.success.open');
      case 'decide': return requiresMaintenance ? t('workflow.success.decideRequires', { who }) : t('workflow.success.decideClear', { who });
      case 'dispatch': return photo ? t('workflow.success.dispatchPhoto', { who }) : t('workflow.success.dispatch', { who });
      case 'recovery': return t('workflow.success.recovery', { who });
      case 'receive': return t('workflow.success.receive', { who });
      case 'ready': return t('workflow.success.ready', { who });
      case 'serviced': return t('workflow.success.serviced', { who });
      case 'finding': return t('workflow.success.finding', { who });
      case 'reinspect': return reFail ? t('workflow.success.reinspectFail', { who }) : t(deferInvoice ? 'workflow.success.reinspectAwaitInvoice' : 'workflow.success.reinspectPass', { who });
      case 'typechange': return t('workflow.success.typechange', { who, type: t(`workflow.type.${maintType}`) });
      case 'delegate': return t('workflow.success.delegate', { who });
      case 'assign': return t('workflow.success.assign', { who });
      case 'lineitems': return t('workflow.success.lineitems', { who });
      case 'requestinvoice': return t('workflow.success.requestinvoice', { who });
      case 'pause': return t('workflow.success.pause', { who });
      case 'resume': return t('workflow.success.resume', { who });
      case 'markReturned': return t('workflow.success.markReturned', { who });
      case 'temporarilyRelease': return t('workflow.success.temporarilyRelease', { who });
      // Every leg of the release round trip confirms itself — a step that closes silently reads as a
      // step that didn't happen, and this trip has seven of them.
      case 'assignReleaseMove': return t('workflow.success.assignReleaseMove', { who });
      case 'startReleaseMove': return t('workflow.success.startReleaseMove', { who });
      case 'arriveAtDestination': return t('workflow.success.arriveAtDestination', { who });
      case 'requestReleaseReturn': return t('workflow.success.requestReleaseReturn', { who });
      case 'assignReleaseReturn': return t('workflow.success.assignReleaseReturn', { who });
      case 'startReleaseReturn': return t('workflow.success.startReleaseReturn', { who });
      case 'cancelRelease': return t('workflow.success.cancelRelease', { who });
      case 'returnFromRelease': return t('workflow.success.returnFromRelease', { who });
      case 'approveRepair': return t('workflow.success.approveRepair', { who });
      case 'requestRefix': return t('workflow.success.requestRefix', { who });
      case 'collectFromGarage': return t('workflow.success.collectFromGarage', { who });
      case 'arriveAtPark': return t('workflow.success.arriveAtPark', { who });
      default: return '';
    }
  }

  // ── The test-drive report, walked one step at a time ──────────────────────────────────────────
  // Four steps. The last one is the decision AND its routing: they are one question ("does this car
  // need the workshop, and if so on what terms"), and splitting them made the routing half vanish and
  // reappear as the inspector toggled the answer above it. Each step reports whether it's answered AND
  // what the answer was, so the collapsed rows read as a running summary of the report.
  const decideSteps = [1, 2, 3, 4];
  const lastDecideStep = decideSteps[decideSteps.length - 1];
  const causesComplete = rootCausesComplete(symptoms, faultCausesCatalog, causes);
  // The client half of the location gate — the same rule the API enforces, applied here so the
  // inspector is stopped on the screen where he can still fix it rather than by a rejected submit.
  const missingLocations = findingsMissingLocation(symptoms, locationCatalog.policy, details);
  // The client half of the system-check gate — the same rule submitReport() enforces server-side,
  // applied here so an unanswered check is a named, tappable blocker rather than a rejected submit.
  //
  // ── WHY THIS FALLS BACK TO A FETCH ─────────────────────────────────────────────────────────────
  // The BOARD's query deliberately does not eager-load checkRequirements — 65+ cards must not each
  // pay to build option lists nobody renders there. Sound for the board. But Decide can be opened
  // from the board as well as from the hydrated ticket page, and the gate that refuses an unanswered
  // check lives on the SERVER. So a modal opened from the board rendered no SYSTEM CHECKS panel,
  // gave the inspector nothing to tap, and then had the finished report rejected with a 422 he had
  // no way to satisfy. A dead end — and the reason 191 raised checks had never once been answered.
  //
  // So: use what we were handed when it is there, and otherwise pull the ticket once, on open. One
  // request, only for the Decide step, only when the checks are actually missing.
  const requiredChecks = Array.isArray(ticket?.required_checks)
    ? ticket.required_checks
    : (hydratedChecks || []);
  const checkPlan = buildCheckResults(requiredChecks, checkAnswers);
  // Findings an approved check will contribute, shown in the findings step so a fault the inspector
  // did not tap never appears unexplained.
  const checkBornFindings = checkFindings(requiredChecks, checkAnswers);
  // Flat catalog vocabulary, for the one check type whose catalog cannot name the fault itself.
  const findingKeywordList = useMemo(
    () => (findingsCatalog || []).flatMap((c) => c.keywords || []),
    [findingsCatalog],
  );
  const missingFindingLocations = findingsMissingLocation(findingTags, locationCatalog.policy, details);
  const openStep = decideSteps.includes(activeStep) ? activeStep : lastDecideStep;
  const stepProps = (n, summary, done) => ({
    n,
    done,
    summary,
    open: openStep === n,
    onOpen: () => setActiveStep(n),
    onNext: n === lastDecideStep ? undefined : () => setActiveStep(n + 1),
    nextLabel: t('workflow.decideStep.continue'),
  });

  // What still blocks the submit button, and which step to open to fix it. The gate already existed —
  // it just expressed itself as a greyed-out button with no explanation, which is unreadable once the
  // offending field is collapsed inside another step.
  const decideBlockers = action !== 'decide' ? [] : [
    // Named rather than silent: without this the button is simply dead while the checks load (or if
    // the load failed), which reads as a broken screen.
    !checksReady && { step: 2, label: t('checks.loading') },
    odoGateBlocked && { step: 1, label: t('workflow.decideStep.needOdometerCheck') },
    requiresMaintenance && !causesComplete && { step: 3, label: t('workflow.decideStep.needCauses') },
    requiresMaintenance && !faultSeverity && { step: 4, label: t('workflow.decideStep.needSeverity') },
    // The two halves of this report may not contradict each other: findings ARE the reason a car needs
    // a ticket, so a report that lists them cannot also say "no maintenance needed". Points at step 2,
    // because untick-the-findings is the fix when the inspector really means the car is clear.
    clearanceWithFindings && { step: 2, label: t('workflow.decideStep.needNoFindings', { n: symptoms.length }) },
    // A system check may not be silently skipped. Named individually rather than as a count, because
    // "answer the battery check" is actionable and "2 checks unanswered" is a scavenger hunt.
    checkPlan.unanswered.length > 0 && { step: 2, label: t('checks.blocking', { list: checkPlan.unanswered.join(', ') }) },
    // A report cannot approve work and simultaneously say the car needs none.
    !requiresMaintenance && checkBornFindings.length > 0
      && { step: 2, label: t('checks.blockingClearance', { n: checkBornFindings.length }) },
  ].filter(Boolean);

  // Submit-button label: the branching steps spell out their decision; the rest use the action's submit verb.
  const submitLabel = action === 'reinspect'
    ? (reFail ? t('workflow.reinspect.failBack') : t('workflow.reinspect.passClose'))
    : action === 'decide'
      ? (requiresMaintenance ? t('workflow.decision.requiresOpen') : t('workflow.decision.noClose'))
      : t(`workflow.meta.${action}.submit`);

  const submitVariant = (action === 'reinspect' && reFail) ? 'danger'
    : (action === 'decide' && !requiresMaintenance) ? 'success'
    : baseTone(action);

  return (
    <Modal
      open
      onClose={guardedClose}
      // `assign` is the widest step in the app: it carries the dispatch plan, the impact strip and the
      // full fault-by-fault evidence. At `md` (512px) the comparison cards stack into a single column
      // and every table wraps — which is what made this step feel cramped no matter how it was laid out.
      size={action === 'assign' ? 'xl' : ['decide', 'ready', 'lineitems', 'pause', 'resume'].includes(action) ? 'lg' : 'md'}
      title={t(`workflow.meta.${action}.title`)}
      subtitle={ticket ? `${ticket.plate || `#${ticket.id}`}${ticket.car ? ` · ${ticket.car}` : ''}` : t(`workflow.meta.${action}.sub`)}
      footer={
        <>
          <Button variant="secondary" onClick={guardedClose} disabled={busy}>{t('common.cancel')}</Button>
          <Button variant={submitVariant} onClick={submit} loading={busy} disabled={invalid() || stale}>
            {submitLabel}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {stale ? (
          <div className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-inset ring-amber-500/30">
            <p>{err}</p>
            <button
              type="button"
              className="mt-2 rounded-md bg-amber-600 px-3 py-1 text-xs font-semibold text-white transition hover:bg-amber-700"
              onClick={() => onDone?.()}
            >
              {t('common.refresh')}
            </button>
          </div>
        ) : (
          err && <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{err}</div>
        )}

        {/* Proactive out-of-range guard — explains why submit is disabled before the server round-trip. */}
        {odoOutOfRange && (
          <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">
            {t('workflow.error.odometerTooLarge', { max: MAX_ODOMETER.toLocaleString() })}
          </div>
        )}

        {/* Stage timing — per-stage durations + total downtime, at a glance on any existing ticket */}
        {ticket && <StageTimeline ticket={ticket} t={t} />}

        {/* Original findings — inherited and shown at every stage, grouped by source. Temporary Release
            renders its OWN findings panel below (with explicit "Not fixed" badges), so it's excluded here
            to avoid showing the list twice. */}
        {ticket?.findings?.length > 0 && action !== 'decide' && action !== 'temporarilyRelease' && (
          <div className="rounded-xl border border-slate-100 bg-slate-50/60 p-3">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{t('workflow.field.findings')}</p>
            <FindingsList findings={ticket.findings} tasks={ticket.tasks} paused={isPaused(ticket)} />
          </div>
        )}

        {/* Maintenance type picker — manager reclassification ('typechange') only. Deliberately
            excluded from 'open': the inspector hasn't finished the test drive yet, so there's nothing
            to classify. Classification happens in the Submit Report ('decide') step below. */}
        {action === 'typechange' && (
          <div>
            <span className="mb-1.5 block text-sm font-medium text-slate-700">
              {t('workflow.type.label')}<Req />
            </span>
            <MaintenanceTypeCards types={visibleTypes} value={maintType} onChange={setMaintType} t={t} />
            {ticket?.maintenance_type && ticket.maintenance_type !== maintType && (
              <p className="mt-1.5 text-xs text-slate-400">
                {t('workflow.type.current')} <span className="font-medium text-slate-600">{t(`workflow.type.${ticket.maintenance_type}`)}</span>
              </p>
            )}
          </div>
        )}

        {/* UC-1 OPEN */}
        {action === 'open' && (
          <>
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.field.vehicle')}<Req /></span>
              <VehicleStatusSelect value={vehicleId} onChange={setVehicleId} vehicles={vehicles} placeholder={t('workflow.ph.searchVehicle')} />
            </div>
            <div>
              <span className="mb-1.5 block text-sm font-medium text-slate-700">{t('workflow.reason.label')}</span>
              <div className="grid gap-2">
                {INSPECTION_TRIGGER_REASONS.map((rv) => {
                  const active = reason === rv;
                  return (
                    <button
                      key={rv}
                      type="button"
                      onClick={() => setReason(rv)}
                      className={`flex items-start gap-3 rounded-xl border px-3 py-2.5 text-start transition ${active ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500' : 'border-slate-200 bg-white hover:border-slate-300'}`}
                    >
                      <span className={`mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border ${active ? 'border-indigo-600' : 'border-slate-300'}`}>
                        {active && <span className="h-2 w-2 rounded-full bg-indigo-600" />}
                      </span>
                      <span className="min-w-0">
                        <span className="block text-sm font-semibold text-slate-800">{t(`workflow.reason.${rv}.label`)}</span>
                        <span className="block text-xs text-slate-500">{t(`workflow.reason.${rv}.sub`)}</span>
                      </span>
                    </button>
                  );
                })}
              </div>
              {/* A customer complaint is not opened here — it lives in the Complaints Center. */}
              <p className="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-[11px] leading-relaxed text-slate-500 ring-1 ring-inset ring-slate-100">
                {t('Customer complaint? Log it in the Complaints Center instead.')}{' '}
                <a href="/complaints" className="font-semibold text-indigo-600 hover:underline">{t('Open the Complaints Center')}</a>
              </p>
            </div>

            {/* Odometer at test-drive start — mandatory reading + photo, the chain's start anchor */}
            <div className="border-t border-slate-100 pt-4">
              <Input label={t('workflow.field.startOdometerKm')} type="number" min="1" required value={odometer} onChange={(e) => setOdometer(e.target.value)} placeholder={t('workflow.ph.odometerExample')} />
              <OdometerContinuityHint previous={prevOdometer} continuity={continuity} confirmed={odoConfirmed} onConfirm={setOdoConfirmed} noteRequired={odoNoteRequired} note={odoNote} onNote={setOdoNote} ignoreTolerance={ignoreOdoTolerance} t={t} />
              <div className="mt-3">
                <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.field.odometerPhoto')}<Req /></span>
                {photoTile}
              </div>
              <p className="mt-1.5 text-xs text-slate-400">{t('workflow.hint.testOdometer')}</p>
            </div>
          </>
        )}

        {/* STAGE 2 — test-drive report + the repair decision.
            Laid out as four numbered steps (mileage → findings → diagnosis → decision + routing) so a long form
            reads as a sequence the inspector works down, not one undifferentiated scroll. */}
        {action === 'decide' && (
          <>
            {/* STEP 1 — end-of-test-drive odometer. Optional here, so the inspector can log the reading when
                they step out of the car. Runs the same continuity + >10 km note gate as every other capture
                and lands as its own "End of test drive" row in the mileage timeline. */}
            <Step
              {...stepProps(
                1,
                odometer ? `${Number(odometer).toLocaleString()} km` : t('workflow.decideStep.notRecorded'),
                !!odometer,
              )}
              title={t('workflow.decideStep.mileageTitle')}
              hint={t('workflow.decideStep.mileageHint')}
            >
              <Input label={t('workflow.field.reportOdometerKm')} type="number" min="1" value={odometer} onChange={(e) => setOdometer(e.target.value)} placeholder={ticket?.test_odometer ? t('workflow.ph.startedAt', { km: Number(ticket.test_odometer).toLocaleString() }) : t('workflow.ph.odometerExample')} />
              <OdometerContinuityHint previous={prevOdometer} continuity={continuity} confirmed={odoConfirmed} onConfirm={setOdoConfirmed} noteRequired={odoNoteRequired} note={odoNote} onNote={setOdoNote} ignoreTolerance={ignoreOdoTolerance} t={t} />
              <div>
                <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.field.odometerPhoto')}</span>
                {photoTile}
              </div>
            </Step>

            {/* STEP 2 — the findings themselves. The post-downtime inspection checklist rides INSIDE this
                step (it tells the inspector what to look over; it never pre-logs a finding). */}
            <Step
              {...stepProps(
                2,
                symptoms.length > 0
                  ? t('findingsPicker.selectedCount', { count: symptoms.length })
                  : t('workflow.decideStep.notAnswered'),
                // "Answered" cuts both ways: a ticket needs at least one finding, and a clearance needs
                // none — a step showing 3 findings under a "no maintenance" decision is not complete.
                // Every system check must also have a result, or the step is not done however the
                // findings look: an unanswered obligation is the one thing this screen must not allow
                // to slide past ([[VehicleCheckRequirement]]).
                (requiresMaintenance ? symptoms.length > 0 : symptoms.length === 0)
                  && checkPlan.unanswered.length === 0,
              )}
              title={t('workflow.decideStep.findingsTitle')}
              hint={t('workflow.decideStep.findingsHint')}
            >
              {/* SYSTEM CHECKS — above the findings picker, deliberately. These are the questions the
                  platform ASKED; the picker below is what the inspector found on his own. Answering a
                  check "OK" resolves it and adds nothing, which is the outcome the old screen could
                  only express by inventing a fault. */}
              <SystemChecks
                checks={requiredChecks}
                value={checkAnswers}
                onChange={setCheckAnswers}
                findingKeywords={findingKeywordList}
                t={t}
                lang={lang}
              />

              {inspectChecklist.length > 0 && (
                <div className="rounded-xl border border-sky-200 bg-sky-50/70 p-3">
                  <p className="mb-1 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-sky-700">
                    <Icon.Shield className="h-3.5 w-3.5" /> {t('workflow.field.inspectChecklistTitle')}
                  </p>
                  <p className="mb-2 text-xs text-sky-800/80">{t('workflow.field.inspectChecklistHint')}</p>
                  <ul className="flex flex-wrap gap-1.5">
                    {inspectChecklist.map((item) => (
                      <li key={item} className="inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1 text-xs font-medium text-sky-800 ring-1 ring-sky-200">
                        <Icon.Search className="h-3 w-3 text-sky-500" /> {item}
                      </li>
                    ))}
                  </ul>
                </div>
              )}
              <FindingsPicker catalog={findingsCatalog} keywordMeta={keywordMeta} value={symptoms} onChange={setSymptoms} locked={lockedFindings} required={requiredFindings} requiredNote={t('Required by the oil follow-up — the recall exists because this car needs an oil change.')} suggested={dataSuggested} statusConditions={diagConditions} ticketId={ticket?.id ?? null} vehicleId={ticket?.vehicle_id ?? vehicleId ?? null} aiContext="test_findings" />
              {/* Faults the answered system checks will add on submit. Shown because they are NOT in
                  the picker above — the inspector never tapped them — and a fault appearing on the
                  ticket that nobody selected reads as a bug rather than as his own decision. */}
              {checkBornFindings.length > 0 && (
                <p className="mt-2 flex items-start gap-1.5 rounded-lg bg-teal-50 px-2.5 py-1.5 text-[11px] text-teal-800 ring-1 ring-inset ring-teal-200">
                  <Icon.Shield className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                  {t('checks.willAddFindings', { list: checkBornFindings.join(', ') })}
                </p>
              )}
              {/* WHAT IS WRONG, HOW MANY, AND WHERE. Sits inside the findings step rather than as a step of
                  its own: it is the same question continued — you said Scratch, now say how many and where on
                  the car. Renders only the faults that HAVE a place (the policy comes from the catalog, so a
                  new fault type is covered automatically and one with nowhere to point at is skipped). */}
              {symptoms.length > 0 && (
                <div className="border-t border-slate-100 pt-3">
                  <span className="mb-1.5 block text-sm font-medium text-slate-700">{t('faultDetail.title')}</span>
                  <FaultDetailPicker
                    symptoms={symptoms}
                    groups={locationCatalog.groups}
                    policy={locationCatalog.policy}
                    maxQuantity={locationCatalog.maxQuantity}
                    value={details}
                    onChange={setDetails}
                  />
                  {missingLocations.length > 0 && (
                    <p className="mt-2 flex items-start gap-1.5 rounded-lg bg-amber-50 px-2.5 py-1.5 text-[11px] text-amber-800 ring-1 ring-inset ring-amber-300">
                      <Icon.Alert className="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-500" />
                      {t('faultDetail.blocking', { list: missingLocations.join(', ') })}
                    </p>
                  )}
                </div>
              )}
            </Step>

            {/* STEP 3 — diagnosis: probable cause per symptom, the chronic-fault + prior-repair intelligence
                that reacts to those picks, and the inspector's own recommendation. */}
            <Step
              {...stepProps(
                3,
                symptoms.length > 0 && causesComplete
                  ? (recommended.trim() || t('workflow.decideStep.diagnosed'))
                  : t('workflow.decideStep.notAnswered'),
                symptoms.length > 0 && causesComplete,
              )}
              title={t('workflow.decideStep.diagnosisTitle')}
              hint={t('workflow.decideStep.diagnosisHint')}
            >
              {symptoms.length > 0 ? (
                <div>
                  <span className="mb-1.5 block text-sm font-medium text-slate-700">{t('Probable root cause')}<Req /></span>
                  <RootCausePicker symptoms={symptoms} catalog={faultCausesCatalog} value={causes} onChange={setCauses} />
                </div>
              ) : (
                <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-400 ring-1 ring-inset ring-slate-100">
                  {t('workflow.decideStep.noFindings')}
                </p>
              )}
              {/* Chronic Fault Watchdog — warns instantly if any picked fault was repaired before */}
              <FaultHistoryInsight
                vehicleId={ticket?.vehicle_id}
                tags={Array.from(new Set([...lockedFindings, ...symptoms]))}
                excludeTicketId={ticket?.id}
              />
              {/* Previous Similar Repairs + Recommendation — live PREVIEW as the inspector picks a symptom
                  (before the fault exists). Same reusable panel + frozen contract as the drawer/checkpoint. */}
              {symptoms.length > 0 && ticket?.vehicle_id && (
                <RepairIntelligencePanel preview={{ vehicleId: ticket.vehicle_id, symptom: symptoms[symptoms.length - 1] }} />
              )}
              <Input label={t('workflow.field.recommendedAction')} value={recommended} onChange={(e) => setRecommended(e.target.value)} placeholder={t('workflow.ph.replacePads')} />
              <Textarea label={t('common.notes')} value={notes} onChange={(e) => setNotes(e.target.value)} rows={3} placeholder={t('workflow.ph.testDriveNotes')} />
              {/* "Requires Parts" — the technical requirement, still part of DIAGNOSIS. It creates no part
                  request: the coordinator sources these once the garage is picked. Only offered when the
                  car is actually going in for work — a cleared diagnostic needs nothing. */}
              {requiresMaintenance && (
                <RequiredPartsEditor
                  enabled={requiresParts}
                  onToggle={setRequiresParts}
                  value={requiredParts}
                  onChange={setRequiredParts}
                  findings={Array.from(new Set([...lockedFindings, ...symptoms]))}
                />
              )}
            </Step>

            {/* STEP 4 — the decision AND the terms it is made on. The branch comes FIRST inside the step;
                the classification and the routing block below only appear once the answer is "requires
                maintenance". Kept as one step because the routing questions are not a separate decision —
                they are the same one, spelled out. */}
            <Step
              {...stepProps(
                4,
                !requiresMaintenance
                  ? t('workflow.decision.noNeed')
                  : faultSeverity
                  ? `${t('workflow.decision.requires')} · ${t(`workflow.faultSeverity.${faultSeverity}`)}`
                  : t('workflow.decision.requires'),
                requiresMaintenance ? !!faultSeverity : true,
              )}
              title={t('workflow.decideStep.decisionTitle')}
              hint={t('workflow.decideStep.decisionHint')}
            >
              <div className="grid grid-cols-2 gap-2">
                <button
                  type="button"
                  onClick={() => setRequiresMaintenance(true)}
                  className={`rounded-xl px-4 py-3 text-sm font-semibold ring-1 transition ${requiresMaintenance ? 'bg-indigo-600 text-white ring-indigo-600' : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'}`}
                >
                  {t('workflow.decision.requires')}
                </button>
                <button
                  type="button"
                  onClick={() => setRequiresMaintenance(false)}
                  className={`rounded-xl px-4 py-3 text-sm font-semibold ring-1 transition ${!requiresMaintenance ? 'bg-emerald-600 text-white ring-emerald-600' : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'}`}
                >
                  {t('workflow.decision.noNeed')}
                </button>
              </div>
              {/* The contradiction, said where it is made. A refusal the inspector only meets at a greyed-out
                  submit button is a puzzle; here it names the faults that are in the way and what to do. */}
              {clearanceWithFindings ? (
                <div className="rounded-xl bg-amber-50 px-3 py-2.5 text-xs text-amber-800 ring-1 ring-inset ring-amber-200">
                  <p className="font-semibold">{t('workflow.hint.clearanceWithFindingsTitle', { n: symptoms.length })}</p>
                  <p className="mt-0.5 leading-snug">{t('workflow.hint.clearanceWithFindingsBody')}</p>
                  <ul className="mt-1.5 flex flex-wrap gap-1">
                    {symptoms.map((s) => (
                      <li key={s} className="rounded-full bg-white px-2 py-0.5 font-medium text-amber-900 ring-1 ring-amber-200">{s}</li>
                    ))}
                  </ul>
                  <button
                    type="button"
                    onClick={() => setActiveStep(2)}
                    className="mt-2 rounded-lg bg-amber-600 px-3 py-1.5 text-[11px] font-semibold text-white hover:bg-amber-700"
                  >
                    {t('workflow.hint.clearanceWithFindingsCta')}
                  </button>
                </div>
              ) : (
                <p className="text-xs text-slate-400">
                  {requiresMaintenance ? t('workflow.hint.requiresMaintenance') : t('workflow.hint.noMaintenance')}
                </p>
              )}

              {/* Inspector's official classification — the authoritative source; Driver's request carries none.
                  Only relevant when the car actually needs work: hidden once "No maintenance needed" is chosen. */}
              {requiresMaintenance && (
                <div className="border-t border-slate-100 pt-3">
                  <span className="mb-1.5 block text-sm font-medium text-slate-700">
                    {t('workflow.type.label')}<Req />
                  </span>
                  <MaintenanceTypeCards types={visibleTypes} value={maintType} onChange={setMaintType} t={t} />
                </div>
              )}

              {/* ROUTING — how urgent, where it's repaired, and whether the car may still be rented.
                  All three only exist once the car is actually going into maintenance, so the whole
                  block is folded away behind "No maintenance needed". */}
              {requiresMaintenance && (
                <div className="space-y-3 border-t border-slate-100 pt-3">
                  <div>
                    <span className="block text-sm font-semibold text-slate-700">{t('workflow.decideStep.routingTitle')}</span>
                    <span className="mt-0.5 block text-xs text-slate-400">{t('workflow.decideStep.routingHint')}</span>
                  </div>
            {/* Fault Severity — the inspector's MANDATORY diagnostic grade, gating "Requires maintenance".
                It becomes the headline urgency the supervisor reads first on the dispatch board. */}
              <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-3">
                <span className="mb-1.5 block text-sm font-semibold text-slate-700">{t('workflow.faultSeverity.label')}<Req /></span>
                <FaultSeverityPicker value={faultSeverity} onChange={setFaultSeverity} t={t} locked={severityLocked} suggestion={severityHint} />
                <p className="mt-1.5 text-xs text-slate-400">
                  {severityLocked ? t('workflow.faultSeverity.breakdownLocked') : t('workflow.faultSeverity.hint')}
                </p>
                {/* What the risk library grades the findings that were ticked — shown, never applied. */}
                {!severityLocked && (
                  <SeveritySuggestionNote suggestion={severityHint} value={faultSeverity} onApply={setFaultSeverity} t={t} />
                )}
              </div>

            {/* Repair Location — where does this repair happen? On-Site (mobile — car stays available) or
                In-Shop (goes to a garage → Waleed & Abdullah are alerted to assign one). A breakdown is
                locked to In-Shop (it grounds the car). */}
              <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-3">
                <span className="mb-1.5 block text-sm font-semibold text-slate-700">{t('workflow.repairLocation.label')}<Req /></span>
                <div className="flex gap-2">
                  {[
                    { value: 'on_site', icon: '🧰', title: t('workflow.repairLocation.onSite'), sub: t('workflow.repairLocation.onSiteSub') },
                    { value: 'in_shop', icon: '🔧', title: t('workflow.repairLocation.inShop'), sub: t('workflow.repairLocation.inShopSub') },
                  ].map((opt) => {
                    const active = repairLocation === opt.value;
                    const optDisabled = locationLocked && opt.value === 'on_site';
                    return (
                      <button
                        key={opt.value}
                        type="button"
                        disabled={optDisabled}
                        onClick={() => setRepairLocation(opt.value)}
                        className={`flex-1 rounded-xl border px-3 py-2.5 text-start transition ${
                          active ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500' : 'border-slate-200 bg-white hover:border-slate-300'
                        } ${optDisabled ? 'cursor-not-allowed opacity-40' : ''}`}
                      >
                        <span className="block text-sm font-semibold text-slate-800"><span aria-hidden>{opt.icon}</span> {opt.title}</span>
                        <span className="mt-0.5 block text-xs text-slate-500">{opt.sub}</span>
                      </button>
                    );
                  })}
                </div>
                <p className="mt-1.5 text-xs text-slate-400">
                  {locationLocked
                    ? t('workflow.repairLocation.breakdownLocked')
                    : (repairLocation === 'in_shop' ? t('workflow.repairLocation.inShopHint') : t('workflow.repairLocation.onSiteHint'))}
                </p>
              </div>

            {/* Rental Eligibility — the inspector's ONE-TIME call, decided here right after the faults are
                identified and then respected by every later rental decision. Deferrable = a customer may
                still take the car (the rental pauses this ticket, it resumes on return); Mandatory = the
                car is grounded until the workshop finishes. A breakdown is locked to Mandatory (grounded). */}
              <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-3">
                <span className="mb-1.5 block text-sm font-semibold text-slate-700">{t('workflow.rentalEligibility.label')}<Req /></span>
                <div className="flex gap-2">
                  {[
                    { value: false, icon: '🔒', title: t('workflow.rentalEligibility.mandatory'), sub: t('workflow.rentalEligibility.mandatorySub') },
                    { value: true, icon: '🔄', title: t('workflow.rentalEligibility.deferrable'), sub: t('workflow.rentalEligibility.deferrableSub') },
                  ].map((opt) => {
                    const active = deferrableForRental === opt.value;
                    // A breakdown grounds the car — it can never be deferred for a rental.
                    const optDisabled = locationLocked && opt.value === true;
                    return (
                      <button
                        key={String(opt.value)}
                        type="button"
                        disabled={optDisabled}
                        onClick={() => setDeferrableForRental(opt.value)}
                        className={`flex-1 rounded-xl border px-3 py-2.5 text-start transition ${
                          active ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500' : 'border-slate-200 bg-white hover:border-slate-300'
                        } ${optDisabled ? 'cursor-not-allowed opacity-40' : ''}`}
                      >
                        <span className="block text-sm font-semibold text-slate-800"><span aria-hidden>{opt.icon}</span> {opt.title}</span>
                        <span className="mt-0.5 block text-xs text-slate-500">{opt.sub}</span>
                      </button>
                    );
                  })}
                </div>
                <p className="mt-1.5 text-xs text-slate-400">
                  {locationLocked
                    ? t('workflow.rentalEligibility.breakdownLocked')
                    : (deferrableForRental ? t('workflow.rentalEligibility.deferrableHint') : t('workflow.rentalEligibility.mandatoryHint'))}
                </p>
              </div>
                </div>
              )}
            </Step>

            {/* Why the submit button is still grey, in words, with a tap straight to the step that fixes it.
                Collapsing the steps is what makes this necessary: a missing severity grade is now two rows
                below the fold, and "disabled button, no reason given" is exactly the dead end the accordion
                would otherwise create. */}
            {decideBlockers.length > 0 && (
              <div className="rounded-xl bg-amber-50 px-3 py-2.5 text-xs text-amber-800 ring-1 ring-inset ring-amber-500/25">
                <p className="font-semibold">{t('workflow.decideStep.stillNeeded')}</p>
                <ul className="mt-1 space-y-0.5">
                  {decideBlockers.map((b) => (
                    <li key={b.step}>
                      <button
                        type="button"
                        onClick={() => setActiveStep(b.step)}
                        className="text-start underline decoration-amber-400 underline-offset-2 transition hover:text-amber-900"
                      >
                        {b.label}
                      </button>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </>
        )}

        {/* PHASE 2 — Supervisor (dispatcher) picks the garage; the whole Driver pool is then notified to collect */}
        {action === 'assign' && (
          <>
            <div className="rounded-lg bg-amber-50/70 px-3 py-2 text-xs text-amber-700 ring-1 ring-inset ring-amber-600/10">
              {t('workflow.hint.assignBanner')}
            </div>
            {/* DECISION CARDS — the intelligence platform, at the one moment it can still change the
                outcome: the car has not been committed to a garage yet. Deliberately ABOVE the garage
                picker, because the comeback card's whole argument is that re-dispatching without
                re-diagnosing is what produces a third visit — advice that arrives after the garage is
                chosen is a report, not a recommendation. Renders nothing when history is quiet. */}
            <DecisionCards ticketId={ticket?.id} />
            {/* Re-dispatch after a failed re-inspection ("came back broken"): the last garage is kept and
                pre-selected, since a botched repair usually goes back to the same shop — but the supervisor
                can still send it elsewhere (and the failure badge shows who to blame). */}
            {ticket?.workflow_status === 'reinspection_failed' && ticket?.garage && (
              <div className="rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700 ring-1 ring-inset ring-rose-600/15">
                <span aria-hidden>⛔</span> {t('workflow.hint.redispatchSameGarage', { garage: ticket.garage })}
              </div>
            )}
            {/* Data-driven suggestion: which garages have proven experience with this vehicle + fault.
                Tapping one pre-fills the picker; the supervisor may still choose any garage manually.
                Skipped on a "came back broken" re-dispatch (reinspection_failed): the car is going back
                to the SAME garage that botched the repair, so a "find best garage" pick is noise here. */}
            {ticket?.workflow_status !== 'reinspection_failed' && (
              <DispatchPlan
                ticketId={ticket?.id}
                garages={garages}
                selectedVendorId={vendorId}
                onPick={(id) => setVendorId(id)}
                onResult={setRecoResult}
              />
            )}
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.field.destinationGarage')}<Req /></span>
              <SearchSelect value={vendorId} onChange={setVendorId} options={garageOptions} placeholder={t('workflow.ph.pickGarage')} />
              {vendorId && recoResult?.primary?.some((p) => String(p.vendor_id) === String(vendorId)) && (
                <p className="mt-1.5 flex items-center gap-1 text-xs font-semibold text-emerald-700">
                  <Icon.Check className="h-3.5 w-3.5" /> {t('workflow.garageRec.prefillNote')}
                </p>
              )}
              <p className="mt-1.5 text-xs text-slate-400">{t('workflow.hint.notifyAllDrivers')}</p>
            </div>

            {/* OVERRIDE CAPTURE — the feedback loop. Shown only when the supervisor has actually chosen
                a different garage from the one recommended.

                Framed as a question, never as a challenge: the copy says the choice is recorded as a
                decision, not questioned, because a supervisor who feels second-guessed picks whatever
                option closes the dialog fastest and every number downstream becomes fiction. A reason is
                asked for but never blocks the dispatch — a car waiting on a dropdown is a worse failure
                than an unlabelled row, and "no reason captured" is itself reported as a gap. */}
            {overrideActive && (
              <div className="rounded-lg bg-indigo-50/60 p-3 ring-1 ring-inset ring-indigo-200">
                <p className="text-sm font-semibold text-slate-700">{t('workflow.override.title')}</p>
                <p className="mt-0.5 text-xs leading-snug text-slate-500">{t('workflow.override.intro')}</p>
                <div className="mt-2 flex flex-wrap gap-1.5">
                  {OVERRIDE_REASONS.map((r) => (
                    <button
                      key={r}
                      type="button"
                      onClick={() => setOverrideReason((v) => (v === r ? '' : r))}
                      className={`rounded-lg px-2.5 py-1 text-xs font-medium transition ${
                        overrideReason === r
                          ? 'bg-indigo-600 text-white'
                          : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-300 hover:bg-slate-50'}`}
                    >
                      {t(`workflow.override.reason.${r}`)}
                    </button>
                  ))}
                </div>
                {/* Offered on ANY reason, not just "Other". The chip says which axis was traded; the
                    note is where the specific fact lives ("they had the part in stock"), and that is
                    usually the detail worth acting on. */}
                <input
                  type="text"
                  value={overrideNote}
                  onChange={(e) => setOverrideNote(e.target.value)}
                  placeholder={t('workflow.override.notePlaceholder')}
                  className="mt-2 w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm"
                />
                {overrideReason === 'other' && !overrideNote.trim() && (
                  <p className="mt-1 text-[11px] text-amber-700">{t('workflow.override.otherNeedsNote')}</p>
                )}
                <p className="mt-2 text-[11px] leading-snug text-slate-400">{t('workflow.override.footnote')}</p>
              </div>
            )}
            {/* Note — ONLY offered when the supervisor is actually CHANGING the garage on a re-dispatch
                (a different garage than the one the car came back broken from). Saved to the car's
                maintenance history + the ticket's follow-up log. */}
            {ticket?.workflow_status === 'reinspection_failed' && vendorId && String(vendorId) !== String(ticket?.vendor_id || '') && (
              <Textarea
                label={t('workflow.field.assignNoteChange')}
                value={assignNote}
                onChange={(e) => setAssignNote(e.target.value)}
              />
            )}
            <div className="rounded-lg bg-indigo-50/70 px-3 py-2 text-xs text-indigo-700 ring-1 ring-inset ring-indigo-600/10">
              <span aria-hidden>🔮</span> {t('workflow.hint.autoReturnEstimate')}
            </div>
          </>
        )}

        {/* UC-3 DISPATCH — the Driver executes the pickup; the garage was chosen by the Supervisor */}
        {action === 'dispatch' && (
          <>
            <Input label={t('workflow.field.odometerKm')} type="number" min="1" required value={odometer} onChange={(e) => setOdometer(e.target.value)} placeholder={t('workflow.ph.odometerExample')} />
            <OdometerContinuityHint previous={prevOdometer} continuity={continuity} confirmed={odoConfirmed} onConfirm={setOdoConfirmed} noteRequired={odoNoteRequired} note={odoNote} onNote={setOdoNote} ignoreTolerance={ignoreOdoTolerance} t={t} />

            {/* Odometer photo — captured straight here and ingested server-side, so the Driver
                needs no inspections permission. */}
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.field.odometerPhoto')}<Req /></span>
              {photoTile}
            </div>

            {/* Garage is read-only here — it's the supervisor's pre-assigned choice; the driver cannot change it.
                On a garage-to-garage transfer the car's current garage stays in `garage` (where it physically
                is) while the supervisor's chosen destination sits in `transfer_to_garage` — the driver must
                drive to that NEW garage, so it wins here. */}
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.field.destinationGarage')}</span>
              <div className="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm">
                <Icon.Wrench className="h-4 w-4 shrink-0 text-slate-400" />
                <span className="font-medium text-slate-700">{ticket?.transfer_to_garage || ticket?.garage || t('workflow.hint.garageNotAssigned')}</span>
                {ticket?.transfer_to_garage && ticket?.garage && (
                  <span className="text-[11px] text-slate-400">{t('workflow.hint.movingFromGarage', { garage: ticket.garage })}</span>
                )}
                <span className="ms-auto text-[11px] text-slate-400">{t('workflow.hint.garageBySupervisor')}</span>
              </div>
            </div>
            {/* Dates are handled automatically: the car-left date defaults to today (server-side)
                and the driver no longer picks an expected-return date at pickup — the supervisor
                owns that at the garage-receive step. Both fields stay in state (blank) so the
                dispatch POST still sends out_date/expected_return as null → today. */}
          </>
        )}

        {/* UC-3 RECOVERY — a Recovery Truck (winch) tows a broken-down car in. NOT a driver transport:
            captures the towing unit + operator phone instead of assigning a driver. Same mandatory
            odometer/condition-photo gate as a normal pickup. */}
        {action === 'recovery' && (
          <>
            <div className="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 ring-1 ring-inset ring-red-100">
              <span aria-hidden>🛻</span> {t('workflow.recovery.banner')}
            </div>

            {/* The towing unit — a winch/recovery truck or an external towing company, NOT a person. */}
            <Input
              label={t('workflow.recovery.unitLabel')}
              required
              value={recoveryUnit}
              onChange={(e) => setRecoveryUnit(e.target.value)}
              placeholder={t('workflow.recovery.unitPlaceholder')}
              maxLength={191}
            />
            <Input
              label={t('workflow.recovery.phoneLabel')}
              type="tel"
              value={recoveryPhone}
              onChange={(e) => setRecoveryPhone(e.target.value)}
              placeholder={t('workflow.recovery.phonePlaceholder')}
              maxLength={40}
            />

            {/* The car is DISABLED and being towed — it isn't driven onto the truck, so the mileage can't
                change between the last recorded reading and this moment. Locked to that value (no manual
                entry) instead of an editable field with a continuity hint, since there's nothing to confirm.
                Falls back to a plain editable field on a ticket with no prior reading to lock to. */}
            {prevOdometer != null ? (
              <div>
                <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.field.odometerKm')}</span>
                <div className="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm">
                  <Icon.Gauge className="h-4 w-4 shrink-0 text-slate-400" />
                  <span className="font-mono font-semibold text-slate-700">{odometer ? Number(odometer).toLocaleString() : '—'} {t('workflow.stage.kmShort')}</span>
                  <span className="ms-auto text-[11px] text-slate-400">{t('workflow.recovery.odometerLocked')}</span>
                </div>
              </div>
            ) : (
              <Input label={t('workflow.field.odometerKm')} type="number" min="1" required value={odometer} onChange={(e) => setOdometer(e.target.value)} placeholder={t('workflow.ph.odometerExample')} />
            )}
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.recovery.photoLabel')}<Req /></span>
              {photoTile}
            </div>

            {/* Garage — decoupled from the classic "Assign Garage" screen: when the ticket doesn't have one
                yet (the common breakdown case, dispatched straight from inspection_pending), pick it right
                here as part of this same action. Once a garage IS already set (the classic assign step ran,
                or a re-dispatch), it's shown read-only — this form only ever tows to the assigned garage.
                Same transfer-aware display as the driver dispatch form above: if a supervisor already moved
                the car's destination (transfer_to_garage differs from its current garage), that wins here
                too, with an explicit "from {garage} →" hint — never a bare garage name with no context. */}
            {ticket?.vendor_id ? (
              <div>
                <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.field.destinationGarage')}</span>
                <div className="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm">
                  <Icon.Wrench className="h-4 w-4 shrink-0 text-slate-400" />
                  <span className="font-medium text-slate-700">{ticket.transfer_to_garage || ticket.garage}</span>
                  {ticket.transfer_to_garage && ticket.garage && (
                    <span className="text-[11px] text-slate-400">{t('workflow.hint.movingFromGarage', { garage: ticket.garage })}</span>
                  )}
                  <span className="ms-auto text-[11px] text-slate-400">{t('workflow.hint.garageBySupervisor')}</span>
                </div>
              </div>
            ) : (
              <div>
                <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.field.destinationGarage')}<Req /></span>
                <SearchSelect value={vendorId} onChange={setVendorId} options={garageOptions} placeholder={t('workflow.ph.pickGarage')} />
                <p className="mt-1.5 text-xs text-slate-400">{t('workflow.recovery.pickGarageHint')}</p>
              </div>
            )}
            <p className="text-xs text-slate-400">{t('workflow.recovery.statusNote')}</p>
          </>
        )}

        {/* UC-4 RECEIVE — "Now at Garage" arrival checkpoint: arrival odometer + photo are MANDATORY;
            no cost/repair here (that lives in the workshop stage). */}
        {action === 'receive' && (
          <>
            <div className="rounded-lg bg-amber-50/70 px-3 py-2 text-xs text-amber-700 ring-1 ring-inset ring-amber-600/10">
              {t('workflow.hint.arrivalGate')}
            </div>
            <Input
              label={t('workflow.field.arrivalOdometerKm')}
              type="number"
              min="1"
              required
              value={odometer}
              onChange={(e) => setOdometer(e.target.value)}
              placeholder={ticket?.dispatch_odometer ? t('workflow.ph.dispatchedAt', { km: Number(ticket.dispatch_odometer).toLocaleString() }) : t('workflow.ph.odometerExample2')}
            />
            <OdometerContinuityHint previous={prevOdometer} continuity={continuity} confirmed={odoConfirmed} onConfirm={setOdoConfirmed} noteRequired={odoNoteRequired} note={odoNote} onNote={setOdoNote} ignoreTolerance={ignoreOdoTolerance} t={t} />
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.field.odometerPhoto')}<Req /></span>
              {photoTile}
            </div>
            <Textarea label={t('workflow.field.garageFeedback')} value={feedback} onChange={(e) => setFeedback(e.target.value)} placeholder={t('workflow.ph.garageIntake')} />
            {/* Expected return can't be in the past — the car is being checked in now, so the earliest
                meaningful return is today. `min` (local YYYY-MM-DD) blocks earlier dates in the picker. */}
            <Input label={t('workflow.field.expectedReturn')} type="date" min={new Date().toLocaleDateString('en-CA')} value={returnDate} onChange={(e) => setReturnDate(e.target.value)} />
          </>
        )}

        {/* UC-5 READY — "Maintenance complete". No odometer is captured here: the car doesn't move inside
            the workshop, so the reading would just duplicate the garage-arrival (receive) one. The return-
            to-service reading is taken later at the re-inspection sign-off. */}
        {action === 'ready' && (
          <>
            <div className="rounded-lg bg-indigo-50/60 px-3 py-2 text-xs text-indigo-700 ring-1 ring-inset ring-indigo-600/10">
              {t('workflow.hint.readyGate')}
            </div>

            {/* TIME PER FAULT — a READ-OUT, not a form. The mechanic's time is already on the record
                by the time this screen opens: clocked by the work sessions while the fault was worked,
                or booked when it was marked fixed. Showing it here closes the loop ("this is what the
                job cost in time") without offering a second place to type the same number — which
                could only ever disagree with the clock it duplicates. */}
            {readyFaults.length > 0 && (
              <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-3">
                <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                  {t('workflow.hint.readyTimeTitle')}
                </p>
                <p className="mt-0.5 text-[11px] text-slate-400">{t('workflow.hint.readyTimeHint')}</p>

                <ul className="mt-2.5 space-y-1.5">
                  {readyFaults.map((f) => (
                    <li key={f.id} className="flex items-center gap-2">
                      <span className="min-w-0 flex-1 truncate text-[13px] text-slate-700" title={f.symptom}>
                        {f.severity_emoji ? `${f.severity_emoji} ` : ''}{f.symptom}
                      </span>
                      {/* Measured beats booked: the clock is the stronger evidence. A fault with only a
                          booked figure shows that instead, and a fault with neither says so plainly —
                          never a "0h" that reads as "measured, took no time". */}
                      {f.measuredSeconds != null ? (
                        <span className="shrink-0 rounded-lg bg-white px-2.5 py-1.5 text-[12px] font-semibold text-slate-600 ring-1 ring-inset ring-slate-200">
                          {fmtHm(f.measuredSeconds)}
                          {f.recorded != null && (
                            <span className="ms-1 font-normal text-slate-400">
                              {t('workflow.hint.readyTimeBooked', { hours: round1(f.recorded) })}
                            </span>
                          )}
                        </span>
                      ) : f.recorded != null ? (
                        <span className="shrink-0 rounded-lg bg-white px-2.5 py-1.5 text-[12px] font-semibold text-slate-600 ring-1 ring-inset ring-slate-200">
                          {round1(f.recorded)}h {t('workflow.hint.readyAlreadyRecorded')}
                        </span>
                      ) : (
                        <span className="shrink-0 rounded-lg px-2.5 py-1.5 text-[12px] text-slate-400 ring-1 ring-inset ring-slate-200">
                          {t('workflow.hint.readyTimeNotClocked')}
                        </span>
                      )}
                    </li>
                  ))}
                </ul>

                {/* The two totals, each stated only when it was actually measured: hands-on work across
                    the faults, and how long the car has been in the workshop. They are different
                    numbers on purpose — waiting for a part is workshop time, not work. */}
                {(measuredTotalSeconds > 0 || workshopSeconds != null) && (
                  <div className="mt-2.5 space-y-0.5 rounded-lg bg-white px-3 py-2 text-[11px] text-slate-500 ring-1 ring-inset ring-slate-200">
                    {measuredTotalSeconds > 0 && (
                      <p>{t('workflow.hint.readyTimeWorked', { dur: fmtHm(measuredTotalSeconds) })}</p>
                    )}
                    {workshopSeconds != null && (
                      <p>{t('workflow.hint.readyTimeVisit', { dur: fmtHm(workshopSeconds) })}</p>
                    )}
                  </div>
                )}
              </div>
            )}

            <Textarea label={t('workflow.field.garageFeedback')} value={feedback} onChange={(e) => setFeedback(e.target.value)} placeholder={t('workflow.ph.whatWasDone')} />
            {/* Cost, parts & labor are NOT captured here — they're itemised later via the invoice link. */}
          </>
        )}

        {/* On-Site (mobile) lane — the single completion step: no garage, no re-inspection, no QA.
            Cost, vendor and notes are all optional (there may be no vendor at all). */}
        {action === 'serviced' && (
          <div className="space-y-3">
            <div className="rounded-lg bg-teal-50/70 px-3 py-2 text-sm text-teal-700 ring-1 ring-inset ring-teal-600/10">
              {t('workflow.serviced.hint')}
            </div>
            <Input
              label={t('workflow.serviced.costLabel')}
              type="number"
              min="0"
              value={cost}
              onChange={(e) => setCost(e.target.value)}
              placeholder={t('workflow.ph.costExample')}
            />
            {/* On-site work never goes to a garage, so this is a free-text vendor/mechanic name,
                NOT a pick from the garage list. */}
            <Input
              label={t('workflow.serviced.vendorLabel')}
              value={onsiteVendor}
              onChange={(e) => setOnsiteVendor(e.target.value)}
              placeholder={t('workflow.serviced.vendorPlaceholder')}
            />
            <Textarea label={t('workflow.field.notesOptional')} value={notes} onChange={(e) => setNotes(e.target.value)} />
          </div>
        )}

        {/* PATH A — ask the garage for an itemised invoice. Garages aren't app users, so this alerts
            the internal team (who phone/email the garage) and stamps the request on the ticket. */}
        {action === 'requestinvoice' && (
          <div className="space-y-3">
            <div className="rounded-lg bg-indigo-50/60 px-3 py-2 text-sm text-indigo-700 ring-1 ring-inset ring-indigo-600/10">
              {t('workflow.requestInvoice.hint')}
            </div>
            <div className="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm">
              <Icon.Wrench className="h-4 w-4 shrink-0 text-slate-400" />
              <span className="font-medium text-slate-700">{ticket?.garage || t('workflow.hint.garageNotAssigned')}</span>
            </div>
            {ticket?.invoice_requested_at && (
              <p className="text-xs text-amber-600">{t('workflow.requestInvoice.already')}</p>
            )}
          </div>
        )}

        {/* Pause Maintenance & Return to Service — pull a mid-repair car out for a customer. The ticket
            keeps ALL its state and remembers the current stage; Resume continues from exactly here. */}
        {action === 'pause' && (
          <div className="space-y-3">
            <div className="rounded-lg bg-amber-50/70 px-3 py-2 text-sm text-amber-700 ring-1 ring-inset ring-amber-600/10">
              {t('workflow.pause.banner')}
            </div>
            {ticket?.position?.label && (
              <div className="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm">
                <Icon.Wrench className="h-4 w-4 shrink-0 text-slate-400" />
                <span className="text-slate-500">{t('workflow.pause.willResumeAt')}</span>
                <span className="font-semibold text-slate-700">{ticket.position.label}</span>
              </div>
            )}
            <Textarea
              label={t('workflow.pause.reasonLabel')}
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              rows={2}
              placeholder={t('workflow.pause.reasonPlaceholder')}
            />

            {/* Enterprise Handover Workflow — the outbound custody handover: odometer + photo mandatory */}
            <div className="border-t border-slate-100 pt-3">
              <Input label={t('workflow.handover.odometerLabel')} type="number" min="1" required value={odometer} onChange={(e) => setOdometer(e.target.value)} placeholder={t('workflow.ph.odometerExample')} />
              <div className="mt-3">
                <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.field.odometerPhoto')}<Req /></span>
                {photoTile}
              </div>
            </div>
            <HandoverFields
              fuelLevel={fuelLevel} onFuelLevel={setFuelLevel}
              exteriorCondition={exteriorCondition} onExteriorCondition={setExteriorCondition}
              interiorCondition={interiorCondition} onInteriorCondition={setInteriorCondition}
              damageFindings={damageFindings} onDamageFindings={setDamageFindings}
              missingAccessories={missingAccessories} onMissingAccessories={setMissingAccessories}
              handoverNotes={handoverNotes} onHandoverNotes={setHandoverNotes}
              signatureRef={signatureRef} onSignatureChange={setSignatureReady}
              t={t}
            />
          </div>
        )}

        {/* Resume Maintenance — the car is back; the SAME ticket continues from the exact paused stage.
            Enterprise Handover Workflow: the return handover is mandatory before it can resume. */}
        {action === 'resume' && (
          <div className="space-y-3">
            <div className="rounded-lg bg-indigo-50/70 px-3 py-2 text-sm text-indigo-700 ring-1 ring-inset ring-indigo-600/10">
              {t('workflow.resume.banner', { stage: ticket?.paused_from_status_label || t('workflow.resume.previousStage') })}
            </div>
            {ticket?.paused_reason && (
              <div className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm">
                <span className="text-slate-500">{t('workflow.pause.reasonWas')} </span>
                <span className="font-medium text-slate-700">{ticket.paused_reason}</span>
              </div>
            )}

            {/* Enterprise Handover Workflow — the return custody handover: odometer + photo mandatory */}
            <div className="border-t border-slate-100 pt-3">
              <Input label={t('workflow.handover.odometerLabel')} type="number" min="1" required value={odometer} onChange={(e) => setOdometer(e.target.value)} placeholder={ticket?.last_pause_handover?.odometer_reading ? t('workflow.ph.startedAt', { km: Number(ticket.last_pause_handover.odometer_reading).toLocaleString() }) : t('workflow.ph.odometerExample')} />
              <div className="mt-3">
                <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.field.odometerPhoto')}<Req /></span>
                {photoTile}
              </div>
            </div>
            <HandoverFields
              fuelLevel={fuelLevel} onFuelLevel={setFuelLevel}
              exteriorCondition={exteriorCondition} onExteriorCondition={setExteriorCondition}
              interiorCondition={interiorCondition} onInteriorCondition={setInteriorCondition}
              damageFindings={damageFindings} onDamageFindings={setDamageFindings}
              missingAccessories={missingAccessories} onMissingAccessories={setMissingAccessories}
              handoverNotes={handoverNotes} onHandoverNotes={setHandoverNotes}
              signatureRef={signatureRef} onSignatureChange={setSignatureReady}
              t={t}
            />
          </div>
        )}

        {/* Vehicle Physically Returned — a light checkpoint, no odometer/handover required yet. */}
        {action === 'markReturned' && (
          <div className="space-y-3">
            <div className="rounded-lg bg-amber-50/70 px-3 py-2 text-sm text-amber-700 ring-1 ring-inset ring-amber-600/10">
              {t('workflow.markReturned.hint')}
            </div>
            <Textarea
              label={t('workflow.markReturned.noteLabel')}
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              rows={3}
            />
          </div>
        )}

        {/* Temporary Vehicle Release — take the car OUT of the workshop mid-repair (road test / customer
            test / external inspection / storage). The ticket stays at its stage; only the OUT odometer +
            who/why are captured now. */}
        {action === 'temporarilyRelease' && (
          <div className="space-y-3">
            <div className="rounded-lg bg-amber-50/70 px-3 py-2 text-sm text-amber-700 ring-1 ring-inset ring-amber-600/10">
              {t('workflow.tempRelease.hint')}
            </div>
            {/* What we found + what's still open — the car is going out mid-repair, so surface every fault
                with an explicit Fixed / Not-fixed badge before it leaves. */}
            {ticket?.findings?.length > 0 && (
              <div className="rounded-xl border border-slate-200 bg-white p-3">
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{t('workflow.tempRelease.faultsTitle')}</p>
                <FindingsList findings={ticket.findings} tasks={ticket.tasks} showPending />
                <p className="mt-2 text-xs text-slate-500">{t('workflow.tempRelease.faultsHint')}</p>
              </div>
            )}
            <Select label={t('workflow.tempRelease.reasonLabel')} value={releaseReason} onChange={(e) => setReleaseReason(e.target.value)} required>
              {TEMP_RELEASE_REASONS.map((r) => (
                <option key={r} value={r}>{t(`workflow.tempRelease.reason.${r}`)}</option>
              ))}
            </Select>
            <Textarea
              label={releaseReason === 'other' ? t('workflow.tempRelease.detailLabelReq') : t('workflow.tempRelease.detailLabel')}
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              rows={2}
              placeholder={t('workflow.tempRelease.detailPlaceholder')}
            />
            <div>
              <Input label={t('workflow.tempRelease.takenByLabel')} value={takenBy} onChange={(e) => setTakenBy(e.target.value)} placeholder={currentUser?.name || t('workflow.tempRelease.takenByPlaceholder')} />
              <p className="mt-1 text-xs text-slate-400">{t('workflow.tempRelease.takenByHint')}</p>
            </div>
            {/* What happens NEXT, said plainly — nobody signs off on a car leaving the workshop without
                knowing who drives it and how it gets back. */}
            <div className="rounded-lg bg-slate-50 px-3 py-2.5 text-xs leading-relaxed text-slate-500 ring-1 ring-inset ring-slate-200">
              {t('workflow.tempRelease.whatNext')}
            </div>
          </div>
        )}

        {/* Release OUT DISPATCH — the supervisor says where the released car goes and who takes it.
            Mirrors the garage dispatch, except the destination is a plain place, not a garage. */}
        {action === 'assignReleaseMove' && (
          <div className="space-y-3">
            <div className="rounded-lg bg-amber-50/70 px-3 py-2 text-sm text-amber-700 ring-1 ring-inset ring-amber-600/10">
              {t('workflow.tempRelease.assignHint', {
                garage: ticket?.active_temporary_release?.garage_snapshot || ticket?.garage || t('workflow.hint.garageNotAssigned'),
                why: ticket?.active_temporary_release?.reason_label || '',
              })}
            </div>
            <div>
              <Input
                label={t('workflow.tempRelease.destinationLabel')}
                value={releaseDestination}
                onChange={(e) => setReleaseDestination(e.target.value)}
                required
                placeholder={t('workflow.tempRelease.destinationPlaceholder')}
              />
              <div className="mt-2 flex flex-wrap gap-1.5">
                {RELEASE_DESTINATIONS.map((d) => (
                  <button
                    key={d}
                    type="button"
                    onClick={() => setReleaseDestination(d)}
                    className={`rounded-lg px-2.5 py-1 text-xs font-medium transition ${
                      releaseDestination === d
                        ? 'bg-indigo-600 text-white'
                        : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-300 hover:bg-slate-50'}`}
                  >
                    {t(`workflow.tempRelease.destination.${d}`)}
                  </button>
                ))}
              </div>
            </div>
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.tempRelease.driverLabel')}</span>
              <SearchSelect value={driverId} onChange={setDriverId} options={driverOptions} placeholder={t('workflow.ph.searchDriver')} />
              <p className="mt-1 text-xs text-slate-400">{t('workflow.tempRelease.driverHint')}</p>
            </div>
            <Textarea label={t('workflow.tempRelease.moveNoteLabel')} value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} />
          </div>
        )}

        {/* Release OUT PICKUP — the driver has the keys. This is the moment the car actually leaves the
            garage, so the OUT reading is captured here and nowhere else. */}
        {action === 'startReleaseMove' && (
          <div className="space-y-3">
            <div className="rounded-lg bg-amber-50/70 px-3 py-2 text-sm text-amber-700 ring-1 ring-inset ring-amber-600/10">
              {t('workflow.tempRelease.pickupHint', {
                garage: ticket?.active_temporary_release?.garage_snapshot || ticket?.garage || t('workflow.hint.garageNotAssigned'),
                destination: ticket?.active_temporary_release?.destination || '',
              })}
            </div>
            <div>
              <Input label={t('workflow.tempRelease.odometerOutLabel')} type="number" min="1" required value={odometer} onChange={(e) => setOdometer(e.target.value)} placeholder={t('workflow.ph.odometerExample')} />
              {lastKnownOdometer(ticket) != null && (
                <p className="mt-1 text-xs text-slate-400">{t('workflow.tempRelease.odometerOutHint', { km: lastKnownOdometer(ticket).toLocaleString() })}</p>
              )}
            </div>
          </div>
        )}

        {/* Release OUT ARRIVAL — the car is parked where it was sent. */}
        {action === 'arriveAtDestination' && (
          <div className="space-y-3">
            <div className="rounded-lg bg-emerald-50/70 px-3 py-2 text-sm text-emerald-700 ring-1 ring-inset ring-emerald-600/10">
              {t('workflow.tempRelease.arriveHint', { destination: ticket?.active_temporary_release?.destination || '' })}
            </div>
            <Textarea label={t('workflow.tempRelease.arriveNoteLabel')} value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} />
          </div>
        )}

        {/* CALL IT BACK — the repair wants the car again. Nothing to fill in: this only re-opens the
            dispatch queue, where the garage and the driver are settled. */}
        {action === 'requestReleaseReturn' && (
          <div className="space-y-3">
            <div className="rounded-lg bg-indigo-50/70 px-3 py-2 text-sm text-indigo-700 ring-1 ring-inset ring-indigo-600/10">
              {t('workflow.tempRelease.callBackHint', {
                destination: ticket?.active_temporary_release?.destination || '',
                garage: ticket?.active_temporary_release?.return_garage || ticket?.active_temporary_release?.garage_snapshot || ticket?.garage || '',
              })}
            </div>
            {/* The faults are still open exactly as they were — that is the whole point of a release
                over a pause, and it's worth showing at the moment the car is called back in. */}
            {ticket?.findings?.length > 0 && (
              <div className="rounded-xl border border-slate-200 bg-white p-3">
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{t('workflow.tempRelease.faultsTitle')}</p>
                <FindingsList findings={ticket.findings} tasks={ticket.tasks} showPending />
                <p className="mt-2 text-xs text-slate-500">{t('workflow.tempRelease.faultsStayHint')}</p>
              </div>
            )}
          </div>
        )}

        {/* Release RETURN DISPATCH — the garage it left, pre-selected and changeable, plus a driver. */}
        {action === 'assignReleaseReturn' && (
          <div className="space-y-3">
            <div className="rounded-lg bg-amber-50/70 px-3 py-2 text-sm text-amber-700 ring-1 ring-inset ring-amber-600/10">
              {t('workflow.tempRelease.returnAssignHint', { destination: ticket?.active_temporary_release?.destination || '' })}
            </div>
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.tempRelease.returnGarageLabel')}<Req /></span>
              <SearchSelect value={vendorId} onChange={setVendorId} options={garageOptions} placeholder={t('workflow.ph.confirmGarage')} />
              {ticket?.active_temporary_release?.garage_snapshot && (
                <p className="mt-1.5 text-xs text-slate-400">
                  {String(vendorId) === String(ticket.active_temporary_release.vendor_id_snapshot || '')
                    ? t('workflow.tempRelease.sameGarageNote', { garage: ticket.active_temporary_release.garage_snapshot })
                    : t('workflow.tempRelease.changedGarageNote', { garage: ticket.active_temporary_release.garage_snapshot })}
                </p>
              )}
            </div>
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.tempRelease.driverLabel')}</span>
              <SearchSelect value={driverId} onChange={setDriverId} options={driverOptions} placeholder={t('workflow.ph.searchDriver')} />
              <p className="mt-1 text-xs text-slate-400">{t('workflow.tempRelease.driverHint')}</p>
            </div>
            <Textarea label={t('workflow.tempRelease.moveNoteLabel')} value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} />
          </div>
        )}

        {/* CANCEL THE RELEASE — the car never left; close it out with nothing recorded against it. */}
        {action === 'cancelRelease' && (
          <div className="space-y-3">
            <div className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600 ring-1 ring-inset ring-slate-200">
              {t('workflow.tempRelease.cancelHint', {
                garage: ticket?.active_temporary_release?.garage_snapshot || ticket?.garage || '',
              })}
            </div>
            <Textarea label={t('workflow.tempRelease.cancelReasonLabel')} value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} />
          </div>
        )}

        {/* Release RETURN PICKUP — collected from where it was parked. */}
        {action === 'startReleaseReturn' && (
          <div className="rounded-lg bg-amber-50/70 px-3 py-2 text-sm text-amber-700 ring-1 ring-inset ring-amber-600/10">
            {t('workflow.tempRelease.returnPickupHint', {
              destination: ticket?.active_temporary_release?.destination || '',
              garage: ticket?.active_temporary_release?.return_garage || ticket?.active_temporary_release?.garage_snapshot || '',
            })}
          </div>
        )}

        {/* Return Vehicle to Workshop — the temporarily-released car is back; capture the IN odometer.
            The distance driven while out is computed and shown live. */}
        {action === 'returnFromRelease' && (() => {
          const rel = ticket?.active_temporary_release;
          const outKm = rel?.odometer_out;
          const dist = outKm != null && odometer !== '' && Number(odometer) >= Number(outKm)
            ? Number(odometer) - Number(outKm) : null;
          return (
            <div className="space-y-3">
              <div className="rounded-lg bg-emerald-50/70 px-3 py-2 text-sm text-emerald-700 ring-1 ring-inset ring-emerald-600/10">
                {t('workflow.tempRelease.backHint', { garage: rel?.return_garage || rel?.garage_snapshot || ticket?.garage || '' })}
              </div>
              {rel && (
                <div className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm">
                  <div className="flex items-center justify-between">
                    <span className="text-slate-500">{t('workflow.tempRelease.outFor')}</span>
                    <span className="font-semibold text-slate-700">{rel.reason_label}{rel.taken_by ? ` · ${rel.taken_by}` : ''}</span>
                  </div>
                  {rel.destination && (
                    <div className="mt-1 flex items-center justify-between">
                      <span className="text-slate-500">{t('workflow.tempRelease.destinationLabel')}</span>
                      <span className="font-semibold text-slate-700">{rel.destination}</span>
                    </div>
                  )}
                  {outKm != null && (
                    <div className="mt-1 flex items-center justify-between">
                      <span className="text-slate-500">{t('workflow.tempRelease.odometerOutLabel')}</span>
                      <span className="font-semibold tabular-nums text-slate-700">{Number(outKm).toLocaleString()} km</span>
                    </div>
                  )}
                </div>
              )}
              <Input label={t('workflow.tempRelease.odometerInLabel')} type="number" min="1" required value={odometer} onChange={(e) => setOdometer(e.target.value)} placeholder={outKm != null ? t('workflow.ph.startedAt', { km: Number(outKm).toLocaleString() }) : t('workflow.ph.odometerExample')} />
              {dist != null && (
                <div className="rounded-lg bg-emerald-50/70 px-3 py-2 text-sm text-emerald-700 ring-1 ring-inset ring-emerald-600/10">
                  {t('workflow.tempRelease.distanceDriven', { km: dist.toLocaleString() })}
                </div>
              )}
              <Textarea label={t('workflow.tempRelease.returnNoteLabel')} value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} />
            </div>
          );
        })()}

        {/* UC-5a — Supervisor Video-Review: APPROVE. Waleed/Abdullah confirm the garage's video looks
            good → the car advances to the final re-inspection. A video must exist first. */}
        {action === 'approveRepair' && (
          <div className="space-y-3">
            <div className="rounded-lg bg-emerald-50/70 px-3 py-2 text-sm text-emerald-700 ring-1 ring-inset ring-emerald-600/10">
              {t('workflow.approveRepair.hint')}
            </div>
            {ticket?.has_video === false ? (
              <p className="text-xs text-amber-600">{t('workflow.approveRepair.needVideo')}</p>
            ) : typeof ticket?.video_count === 'number' && ticket.video_count > 0 ? (
              <div className="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm">
                <Icon.Check className="h-4 w-4 shrink-0 text-emerald-500" />
                <span className="font-medium text-slate-700">{t('workflow.approveRepair.videoCount', { n: ticket.video_count })}</span>
              </div>
            ) : null}
          </div>
        )}
        {/* UC-5a — Supervisor Video-Review: REQUEST A RE-FIX. Not satisfied → back to the same garage,
            with a reason the garage acts on (logged to the follow-up trail). */}
        {action === 'requestRefix' && (
          <div className="space-y-3">
            <div className="rounded-lg bg-amber-50/70 px-3 py-2 text-sm text-amber-700 ring-1 ring-inset ring-amber-600/10">
              {t('workflow.requestRefix.hint')}
            </div>
            <Textarea
              label={t('workflow.requestRefix.reasonLabel')}
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              rows={4}
              placeholder={t('workflow.requestRefix.placeholder')}
            />
          </div>
        )}

        {/* UC-5b — Driver's RETURN leg, checkpoint 1: collect the car FROM the garage. Just the
            mandatory "received from garage" photo — no status change (still ready_for_pickup). */}
        {action === 'collectFromGarage' && (
          <div className="space-y-3">
            <div className="rounded-lg bg-sky-50/70 px-3 py-2 text-xs text-sky-700 ring-1 ring-inset ring-sky-600/10">
              {t('workflow.hint.collectFromGarage')}
            </div>
            {/* Garage-OUT odometer — the reading as the car leaves the garage (mandatory) */}
            <div>
              <Input label={t('workflow.field.collectOdometerKm')} type="number" min="1" required value={odometer} onChange={(e) => setOdometer(e.target.value)} placeholder={ticket?.receive_odometer ? t('workflow.ph.arrivedAt', { km: Number(ticket.receive_odometer).toLocaleString() }) : t('workflow.ph.odometerExample')} />
              <OdometerContinuityHint previous={prevOdometer} continuity={continuity} confirmed={odoConfirmed} onConfirm={setOdoConfirmed} noteRequired={odoNoteRequired} note={odoNote} onNote={setOdoNote} ignoreTolerance={ignoreOdoTolerance} t={t} />
            </div>
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.field.pickupPhoto')}<Req /></span>
              {photoTile}
            </div>
          </div>
        )}

        {/* UC-5c — Driver's RETURN leg, checkpoint 2: arrival at our park. Mandatory arrival photo —
            the server auto-branches by repair severity (minor auto-closes, major → final QA). */}
        {action === 'arriveAtPark' && (
          <div className="space-y-3">
            <div className="rounded-lg bg-emerald-50/70 px-3 py-2 text-xs text-emerald-700 ring-1 ring-inset ring-emerald-600/10">
              {t('workflow.hint.arriveAtPark')}
            </div>
            {/* Arrival odometer — the reading as the car lands back at base (mandatory). Continuity-checked
                vs the garage-OUT collect reading; becomes the at-base anchor for the final QA ±5 km cap. */}
            <div>
              <Input label={t('workflow.field.arrivalOdometerKm')} type="number" min="1" required value={odometer} onChange={(e) => setOdometer(e.target.value)} placeholder={ticket?.return_odometer ? t('workflow.ph.collectedAt', { km: Number(ticket.return_odometer).toLocaleString() }) : t('workflow.ph.odometerExample')} />
              <OdometerContinuityHint previous={prevOdometer} continuity={continuity} confirmed={odoConfirmed} onConfirm={setOdoConfirmed} noteRequired={odoNoteRequired} note={odoNote} onNote={setOdoNote} ignoreTolerance={ignoreOdoTolerance} t={t} />
            </div>
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.field.arrivalPhoto')}<Req /></span>
              {photoTile}
            </div>
            <Textarea label={t('workflow.field.notesOptional')} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder={t('workflow.ph.arrivalNotes')} />
          </div>
        )}

        {/* DEFERRED EDIT — record/replace the structured Parts + Labor breakdown after the fact
            (e.g. once the invoice paperwork lands). Works on a ticket in any state. */}
        {action === 'lineitems' && (
          <>
            <div className="rounded-lg bg-indigo-50/60 px-3 py-2 text-xs text-indigo-700 ring-1 ring-inset ring-indigo-600/10">
              {t('workflow.lineItem.hint')}
            </div>
            <LineItemsEditor
              value={lineItems}
              onChange={setLineItems}
              catalog={findingsCatalog}
              findings={ticket?.findings || []}
              ticketId={ticket?.id}
              requireReceipt
              receiptTotal={receiptTotal}
              onReceiptTotalChange={setReceiptTotal}
              variance={varianceExplanation}
              onVarianceChange={setVarianceExplanation}
            />
          </>
        )}

        {/* UC-6 RE-INSPECT — Quality-Control. Per fault, sign off Fixed or flag Still Broken. A car may
            come back with some faults fixed and some not; any still-broken fault returns it to the
            supervisor for re-dispatch (blaming the garage). Legacy tickets with no faults keep the
            simple pass/fail toggle. */}
        {action === 'reinspect' && (
          <>
            {perFault ? (
              <div>
                <div className="mb-1.5 flex items-center justify-between">
                  <span className="text-sm font-medium text-slate-700">{t('workflow.reinspect.checkEachFault')}</span>
                  <span className="text-[11px] text-slate-400">{t('workflow.reinspect.stillBrokenCount', { n: brokenIds.length })}</span>
                </div>
                <div className="space-y-1.5">
                  {openFaults.map((task) => {
                    const isBroken = !!broken[task.id];
                    const isUnverifiable = !!unverifiable[task.id];
                    const failCount = Number(task.reinspection_failures) || 0;
                    return (
                      <div key={task.id} className={`rounded-xl border px-3 py-2.5 transition ${isBroken ? 'border-red-300 bg-red-50/60' : 'border-slate-200 bg-white'}`}>
                        <div className="flex items-center gap-2">
                          <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm font-medium text-slate-800" title={task.symptom}>{task.symptom}</span>
                            {/* Prior blame — this fault already flunked a re-inspection at a garage before. */}
                            {failCount > 0 && (
                              <span className="mt-0.5 inline-flex items-center gap-1 rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-700 ring-1 ring-inset ring-red-600/20">
                                <span aria-hidden>⛔</span> {t('workflow.reinspect.unresolvedBadge', { n: failCount, garage: task.last_failed_garage || t('workflow.task.unassigned') })}
                              </span>
                            )}
                          </span>
                          <div className="flex shrink-0 gap-1">
                            <button
                              type="button"
                              onClick={() => setBroken((p) => ({ ...p, [task.id]: false }))}
                              className={`rounded-lg px-2.5 py-1.5 text-xs font-semibold ring-1 transition ${!isBroken ? 'bg-emerald-600 text-white ring-emerald-600' : 'bg-white text-slate-500 ring-slate-300 hover:bg-slate-50'}`}
                            >
                              {t('workflow.reinspect.markFixed')}
                            </button>
                            <button
                              type="button"
                              onClick={() => { setBroken((p) => ({ ...p, [task.id]: true })); setUnverifiable((p) => ({ ...p, [task.id]: false })); }}
                              className={`rounded-lg px-2.5 py-1.5 text-xs font-semibold ring-1 transition ${isBroken ? 'bg-red-600 text-white ring-red-600' : 'bg-white text-slate-500 ring-slate-300 hover:bg-slate-50'}`}
                            >
                              {t('workflow.reinspect.markBroken')}
                            </button>
                            {/* The honest third option. Without it an inspector who cannot check the car
                                must either guess "fixed" — a false positive in the platform's only
                                trustworthy dataset — or record nothing at all, which looks identical to
                                a skipped queue. */}
                            <button
                              type="button"
                              onClick={() => { setUnverifiable((p) => ({ ...p, [task.id]: !p[task.id] })); setBroken((p) => ({ ...p, [task.id]: false })); }}
                              className={`rounded-lg px-2.5 py-1.5 text-xs font-semibold ring-1 transition ${isUnverifiable ? 'bg-slate-600 text-white ring-slate-600' : 'bg-white text-slate-500 ring-slate-300 hover:bg-slate-50'}`}
                            >
                              {tf('workflow.reinspect.markUnverifiable', "Can't verify")}
                            </button>
                          </div>
                        </div>
                        {isUnverifiable && (
                          // Why it could not be checked, because "no usable verdict" is not one problem:
                          // a car the customer drove away in is a scheduling failure, a fault that will
                          // not reproduce is a diagnostic one, and they need different fixes.
                          <select
                            value={unverifiableReason[task.id] || ''}
                            onChange={(e) => setUnverifiableReason((p) => ({ ...p, [task.id]: e.target.value }))}
                            aria-label={tf('workflow.reinspect.unverifiableLabel', 'Why could it not be verified?')}
                            className={`mt-2 w-full rounded-lg border bg-white px-2.5 py-1.5 text-sm outline-none transition focus:ring-2 focus:ring-slate-400/20 ${unverifiableReason[task.id] ? 'border-slate-200 text-slate-700' : 'border-slate-300 text-slate-400'}`}
                          >
                            <option value="">{tf('workflow.reinspect.unverifiablePlaceholder', 'Why could it not be verified?')}</option>
                            {UNVERIFIABLE_REASONS.map((r) => (
                              <option key={r} value={r}>{tf(`workflow.reinspect.unverifiable.${r}`, UNVERIFIABLE_FALLBACK[r])}</option>
                            ))}
                          </select>
                        )}
                        {isBroken && (
                          <>
                            {/* Structured failure reason (Case B) — mandatory so a failed repair is never
                                just a free-text note. Feeds the repeated-failure / part-failure intelligence. */}
                            <select
                              value={brokenReason[task.id] || ''}
                              onChange={(e) => setBrokenReason((p) => ({ ...p, [task.id]: e.target.value }))}
                              aria-label={t('workflow.reinspect.reasonLabel')}
                              className={`mt-2 w-full rounded-lg border bg-white px-2.5 py-1.5 text-sm outline-none transition focus:ring-2 focus:ring-red-400/20 ${brokenReason[task.id] ? 'border-red-200 text-slate-700' : 'border-red-300 text-slate-400'}`}
                            >
                              <option value="">{t('workflow.reinspect.reasonPlaceholder')}</option>
                              {FAILURE_REASONS.map((r) => (
                                <option key={r} value={r}>{t(`workflow.reinspect.reason.${r}`)}</option>
                              ))}
                            </select>
                            <input
                              type="text"
                              value={brokenNote[task.id] ?? ''}
                              onChange={(e) => setBrokenNote((p) => ({ ...p, [task.id]: e.target.value }))}
                              placeholder={t('workflow.reinspect.faultNotePh')}
                              aria-label={t('workflow.reinspect.faultNotePh')}
                              className="mt-2 w-full rounded-lg border border-red-200 bg-white px-2.5 py-1.5 text-sm text-slate-700 outline-none transition focus:border-red-400 focus:ring-2 focus:ring-red-400/20"
                            />
                          </>
                        )}
                      </div>
                    );
                  })}
                </div>
              </div>
            ) : (
              <div className="flex gap-2">
                <button type="button" onClick={() => setOutcome('pass')} className={`flex-1 rounded-xl px-4 py-3 text-sm font-semibold ring-1 transition ${outcome === 'pass' ? 'bg-emerald-600 text-white ring-emerald-600' : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'}`}>{t('workflow.reinspect.pass')}</button>
                <button type="button" onClick={() => setOutcome('fail')} className={`flex-1 rounded-xl px-4 py-3 text-sm font-semibold ring-1 transition ${outcome === 'fail' ? 'bg-red-600 text-white ring-red-600' : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'}`}>{t('workflow.reinspect.fail')}</button>
              </div>
            )}

            {!reFail ? (
              <>
                {/* Final QC odometer — captured at sign-off, when the car is physically back for the pass
                    check. Mandatory; runs the same continuity + >10 km note gate as every other capture. */}
                <div>
                  <Input label={t('workflow.field.reinspectOdometerKm')} type="number" min="1" required value={odometer} onChange={(e) => setOdometer(e.target.value)} placeholder={t('workflow.ph.odometerExample')} />
                  <OdometerContinuityHint previous={prevOdometer} continuity={continuity} confirmed={odoConfirmed} onConfirm={setOdoConfirmed} noteRequired={odoNoteRequired} note={odoNote} onNote={setOdoNote} ignoreTolerance={ignoreOdoTolerance} t={t} />
                </div>

                {/* PASS closes the ticket clean — the garage is already recorded on the ticket, the
                    repair cost lands later via the deferred invoice, and the return date is stamped
                    automatically (today) on close, so the inspector picks none of them here. */}
                <Textarea label={t('workflow.field.signOffNotes')} value={notes} onChange={(e) => setNotes(e.target.value)} />

                {/* Deferred-invoice: the car returns to service either way — this only decides whether the
                    ticket closes now or parks in "awaiting invoice" until the paperwork lands. */}
                <label className="flex cursor-pointer items-start gap-2.5 rounded-xl border border-slate-200 bg-slate-50/70 px-3 py-2.5">
                  <input type="checkbox" checked={deferInvoice} onChange={(e) => setDeferInvoice(e.target.checked)} className="mt-0.5 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                  <span className="min-w-0">
                    <span className="block text-sm font-medium text-slate-800">{t('workflow.reinspect.deferInvoice')}</span>
                    <span className="block text-xs text-slate-500">{t('workflow.reinspect.deferInvoiceHint')}</span>
                  </span>
                </label>
              </>
            ) : (
              <>
                <div className="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 ring-1 ring-inset ring-red-600/10">
                  {t('workflow.reinspect.failBanner')}
                </div>
                {/* The garage the car came back broken from (blame, pre-selected). The inspector may
                    re-route it to a different garage for the supervisor's re-dispatch — that warns and
                    makes the reason mandatory. */}
                <div>
                  <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.field.garage')}</span>
                  <SearchSelect value={vendorId} onChange={setVendorId} options={garageOptions} placeholder={t('workflow.ph.confirmGarage')} />
                </div>
                {reFailGarageChanged && (
                  <div className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 ring-1 ring-inset ring-amber-600/15">
                    <span aria-hidden>⚠️</span> {t('workflow.reinspect.garageChangeAlert')}
                  </div>
                )}
                <Textarea
                  label={reFailGarageChanged ? t('workflow.reinspect.changeReason') : t('workflow.field.messageToSupervisor')}
                  required={reFailGarageChanged}
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  placeholder={t('workflow.ph.brakeNoise')}
                />
              </>
            )}
          </>
        )}

        {/* STAGE 3 — add GARAGE-identified findings during the repair */}
        {action === 'finding' && (
          <>
            <div>
              <span className="mb-1.5 block text-sm font-medium text-slate-700">{t('workflow.field.newGarageIssues')}</span>
              <FindingsPicker catalog={findingsCatalog} keywordMeta={keywordMeta} value={findingTags} onChange={setFindingTags} locked={lockedFindings} required={requiredFindings} requiredNote={t('Required by the oil follow-up — the recall exists because this car needs an oil change.')} statusConditions={diagConditions} ticketId={ticket?.id ?? null} vehicleId={ticket?.vehicle_id ?? vehicleId ?? null} aiContext="garage_findings" />
                {/* WHAT IS WRONG, HOW MANY, AND WHERE. Sits inside the findings step rather than as a step of
                    its own: it is the same question continued — you said Scratch, now say how many and where on
                    the car. Renders only the faults that HAVE a place (the policy comes from the catalog, so a
                    new fault type is covered automatically and one with nowhere to point at is skipped). */}
                {findingTags.length > 0 && (
                  <div className="border-t border-slate-100 pt-3">
                    <span className="mb-1.5 block text-sm font-medium text-slate-700">{t('faultDetail.title')}</span>
                    <FaultDetailPicker
                      symptoms={findingTags}
                      groups={locationCatalog.groups}
                      policy={locationCatalog.policy}
                      maxQuantity={locationCatalog.maxQuantity}
                      value={details}
                      onChange={setDetails}
                    />
                    {missingFindingLocations.length > 0 && (
                      <p className="mt-2 flex items-start gap-1.5 rounded-lg bg-amber-50 px-2.5 py-1.5 text-[11px] text-amber-800 ring-1 ring-inset ring-amber-300">
                        <Icon.Alert className="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-500" />
                        {t('faultDetail.blocking', { list: missingFindingLocations.join(', ') })}
                      </p>
                    )}
                  </div>
                )}
            </div>
            {/* Symptom → Root-Cause — diagnose each garage-found issue (mandatory where a cause-list exists) */}
            {findingTags.length > 0 && (
              <div>
                <span className="mb-1.5 block text-sm font-medium text-slate-700">{t('Probable root cause')}<Req /></span>
                <RootCausePicker symptoms={findingTags} catalog={faultCausesCatalog} value={causes} onChange={setCauses} />
              </div>
            )}
            <Select label={t('workflow.field.findingSeverity')} value={findingSeverity} onChange={(e) => setFindingSeverity(e.target.value)}>
              <option value="">{t('common.none')}</option>
              <option value="low">{t('common.low')}</option>
              <option value="medium">{t('common.medium')}</option>
              <option value="high">{t('common.high')}</option>
            </Select>
            <p className="text-xs text-slate-400">{t('workflow.hint.garageIdentified', { tag: t('workflow.hint.garageIdentifiedTag') })}</p>
          </>
        )}

        {/* STAGE 0 → 1 — Inspector picks up the request and starts the test drive */}
        {action === 'start' && (
          <div className="space-y-3">
            <div className="rounded-lg bg-indigo-50/60 px-3 py-2 text-xs text-indigo-700 ring-1 ring-inset ring-indigo-600/10">
              {t('workflow.hint.startBanner')}
            </div>
            {ticket?.requested_by_name && (
              <p className="text-sm text-slate-600">{t('workflow.hint.requestedBy')} <span className="font-semibold text-slate-800">{ticket.requested_by_name}</span></p>
            )}
            {/* What was reported — the reason this car is in front of him. Labelled by WHERE it came
                from (request_origin) so the inspector reads a driver's observation as an observation,
                not as an anonymous quote he has to guess the weight of. */}
            {ticket?.customer_complaint && (
              <div className="rounded-lg bg-slate-50 px-3 py-2 ring-1 ring-inset ring-slate-100">
                <p className="mb-0.5 flex flex-wrap items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                  <Icon.Flag className="h-3 w-3" />
                  {t(ticket.trigger_reason === 'customer_reported'
                    ? 'workflow.detail.complaint'
                    : ticket.request_origin === 'system_schedule'
                      ? 'workflow.detail.agenda'
                      : 'workflow.detail.driverNote')}
                  {(ticket.request_origin_label || ORIGIN_LABEL[ticket.request_origin]) && (
                    <span className="font-normal normal-case text-slate-400">
                      · {ticket.request_origin_label || ORIGIN_LABEL[ticket.request_origin]}
                      {ticket.requested_by_name ? ` — ${ticket.requested_by_name}` : ''}
                    </span>
                  )}
                </p>
                <p className="text-sm text-slate-600">“{ticket.customer_complaint}”</p>
              </div>
            )}

            {/* Reviewer's hand-off note — the office's optional message typed when the request was
                approved (see /inspection-review). Shown here so the inspector reads it before the drive. */}
            {ticket?.review?.notes && (
              <div className="rounded-lg border border-amber-200 bg-amber-50/70 px-3 py-2">
                <p className="mb-0.5 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-amber-700">
                  <Icon.Info className="h-3.5 w-3.5" /> {t('workflow.hint.reviewNote')}
                  {ticket.review.reviewer_name && <span className="font-normal normal-case text-amber-600/80">· {ticket.review.reviewer_name}</span>}
                </p>
                <p className="text-sm text-amber-900">“{ticket.review.notes}”</p>
              </div>
            )}

            {/* Odometer at test-drive start — mandatory reading + photo, the chain's start anchor */}
            <Input label={t('workflow.field.startOdometerKm')} type="number" min="1" required value={odometer} onChange={(e) => setOdometer(e.target.value)} placeholder={t('workflow.ph.odometerExample')} />
            <OdometerContinuityHint previous={prevOdometer} continuity={continuity} confirmed={odoConfirmed} onConfirm={setOdoConfirmed} noteRequired={odoNoteRequired} note={odoNote} onNote={setOdoNote} ignoreTolerance={ignoreOdoTolerance} t={t} />
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.field.odometerPhoto')}<Req /></span>
              {photoTile}
            </div>
            <p className="text-xs text-slate-400">{t('workflow.hint.testOdometer')}</p>
          </div>
        )}

        {/* DELEGATE — supervisor assigns a specific driver to pick up / drop off the car */}
        {action === 'delegate' && (
          <>
            <div className="rounded-lg bg-violet-50/70 px-3 py-2 text-xs text-violet-700 ring-1 ring-inset ring-violet-600/10">
              {t('workflow.hint.delegateBanner')}
            </div>
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.field.assignDriver')}<Req /></span>
              <SearchSelect value={driverId} onChange={setDriverId} options={driverOptions} placeholder={t('workflow.ph.searchDriver')} />
            </div>
          </>
        )}

        {/* Follow-up log — Driver records updates while the car is out. A running "message log":
            type at the top, and each saved note drops in as its own bubble (newest first) below. */}
        {action === 'followup' && (
          <>
            <Textarea label={t('workflow.field.followNote')} value={followNote} onChange={(e) => setFollowNote(e.target.value)} rows={3} placeholder={t('workflow.ph.partsTomorrow')} />

            {followLog.length > 0 && (
              <div>
                <p className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-400">
                  <Icon.Wrench className="h-3.5 w-3.5" />
                  {t('workflow.followLog.title')}
                  <span className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-500">{followLog.length}</span>
                </p>
                <ul className="max-h-72 space-y-2 overflow-y-auto pe-1">
                  {[...followLog]
                    .sort((a, b) => new Date(b.at || 0).getTime() - new Date(a.at || 0).getTime())
                    .map((f, i) => (
                      <FollowUpBubble key={f.at ? `${f.at}-${i}` : i} note={f} fresh={!!savedAt && f.at === savedAt} t={t} lang={lang} />
                    ))}
                </ul>
              </div>
            )}
          </>
        )}
      </div>
    </Modal>
  );
}
