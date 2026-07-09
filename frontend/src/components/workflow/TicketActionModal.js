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
import RootCausePicker, { rootCausesComplete } from './RootCausePicker';
import FaultHistoryInsight from './FaultHistoryInsight';
import LineItemsEditor, { serializeLineItems, lineItemsUnlinked, invoiceVarianceBlocked, lineItemsHaveZeroCost } from './LineItemsEditor';
import { compressImage, formatBytes } from '../../lib/imageCompression';
import { evaluateContinuity, needsConfirm, needsNote, stageIgnoresTolerance, STAGE } from '../../lib/odometerContinuity';
import OdometerContinuityHint from './OdometerContinuityHint';

// Persisted enum values — these are CONTRACT with the backend and never localize.
// Their visible labels are resolved from the i18n catalog at render time.
const TRIGGER_REASON_VALUES = ['test_drive', 'customer_reported', 'periodic']; // App\Models\Maintenance::TRIGGER_REASONS
const MAINTENANCE_TYPES = [
  { value: 'routine', icon: '🔧' },
  { value: 'breakdown', icon: '⚠️' },
  { value: 'ins_incident', icon: '🛡️' },
  { value: 'non_ins_incident', icon: '💥' },
  { value: 'modification', icon: '⚙️' },
  { value: 'upgrade', icon: '⬆️' },
]; // App\Models\Maintenance::MAINTENANCE_TYPES

// Fault Severity (🔴/🟡/🟢) — CONTRACT with App\Models\Maintenance::FAULT_SEVERITIES. The inspector's
// mandatory diagnostic grade, assessed at the Decide step; it's the headline urgency on the board.
const FAULT_SEVERITY_OPTS = [
  { value: 'critical', emoji: '🔴' },
  { value: 'moderate', emoji: '🟡' },
  { value: 'routine', emoji: '🟢' },
];

// Submit-button tone per action (visual only). The footer further overrides this
// for the branching decisions (reinspect pass/fail, decide requires/clear).
const baseTone = (action) => (action === 'ready' ? 'success' : 'primary');

// Compact "who/when" timestamp for the follow-up log: relative for recent notes, an absolute
// date+time once they age past a day. `t` localizes the relative phrasing.
function fmtWhen(iso, t) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const diff = (Date.now() - d.getTime()) / 1000;
  if (diff < 60) return t('time.justNow');
  if (diff < 3600) return t('time.minutesAgo', { n: Math.round(diff / 60) });
  if (diff < 86400) return t('time.hoursAgo', { n: Math.round(diff / 3600) });
  return `${d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}, ${d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })}`;
}

// Up-to-two-letter avatar initials for the note's author.
const initialsOf = (name) =>
  (name || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?';

// One follow-up "bubble": author avatar + name, a compact timestamp, and the note body. The most
// recent note (just saved) gets a green confirmation highlight so the writer sees it landed.
function FollowUpBubble({ note, fresh, t }) {
  return (
    <li className={`rounded-xl border p-3 shadow-sm transition ${fresh ? 'border-emerald-300 bg-emerald-50/60 ring-1 ring-emerald-200' : 'border-slate-200 bg-white'}`}>
      <div className="flex items-center justify-between gap-2">
        <span className="flex items-center gap-1.5 text-xs font-semibold text-slate-700">
          <span className="flex h-5 w-5 items-center justify-center rounded-full bg-indigo-100 text-[10px] font-bold text-indigo-600">{initialsOf(note.by)}</span>
          {note.by || t('common.unknown')}
        </span>
        <span className="shrink-0 text-[11px] text-slate-400">
          {fresh && <span className="me-1.5 font-semibold text-emerald-600">{t('time.saved')}</span>}
          {fmtWhen(note.at, t)}
        </span>
      </div>
      <p className="mt-1.5 whitespace-pre-wrap break-words text-sm leading-relaxed text-slate-700">{note.text}</p>
    </li>
  );
}

// A span of seconds → a compact "3d 4h" / "15m" badge (top two non-zero units; sub-minute → "<1m").
// null whenever the span isn't measurable yet, so a stage that hasn't completed reads as nothing.
function fmtDuration(secs) {
  if (secs == null || !Number.isFinite(secs) || secs < 0) return null;
  const s = Math.floor(secs);
  const d = Math.floor(s / 86400);
  const h = Math.floor((s % 86400) / 3600);
  const m = Math.floor((s % 3600) / 60);
  const parts = [];
  if (d) parts.push(`${d}d`);
  if (h) parts.push(`${h}h`);
  if (m) parts.push(`${m}m`);
  if (!parts.length) return '<1m';
  return parts.slice(0, 2).join(' ');
}

// Live elapsed seconds from an ISO instant to now — used to keep the IN-PROGRESS stage's clock
// ticking ("At Garage · running") instead of showing nothing until the car comes back.
const elapsedSecs = (iso) => (iso ? Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000) : null);

// One stage chip: the step name + a Duration badge. A completed stage shows its measured span; the
// step currently in progress shows the running clock (amber); an unreached step is skipped entirely.
function StageChip({ label, icon: Ico, secs, running, t }) {
  const text = fmtDuration(secs);
  if (text == null) return null;
  return (
    <span className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs">
      {Ico && <Ico className="h-3.5 w-3.5 text-slate-400" />}
      <span className="font-medium text-slate-600">{label}</span>
      <span className={`tabular-nums rounded-md px-1.5 py-0.5 text-[11px] font-semibold ${running ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-700'}`}>
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
  const totalText = fmtDuration(totalSecs);

  return (
    <div className="rounded-xl border border-slate-100 bg-slate-50/60 p-3">
      <div className="mb-2 flex items-center justify-between">
        <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">{t('workflow.stage.timing')}</p>
        {totalText && (
          <span className="inline-flex items-center gap-1.5 text-xs">
            <span className="text-slate-400">{t('workflow.stage.totalDowntime')}</span>
            <span className={`tabular-nums rounded-md px-1.5 py-0.5 text-[11px] font-bold ${totalRunning ? 'bg-amber-100 text-amber-700' : 'bg-indigo-100 text-indigo-700'}`}>
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
            <span className="tabular-nums rounded-md bg-indigo-100 px-1.5 py-0.5 text-[11px] font-semibold text-indigo-700">
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

// The 🔴/🟡/🟢 Fault Severity chooser — the inspector's mandatory diagnostic grade on the 'decide'
// step. Clicking the active level clears it. Colour-codes the selected level so the grade reads at
// a glance (matching the board chip the supervisor sees downstream).
const SEVERITY_STYLE = {
  critical: 'border-red-500 bg-red-50 text-red-800 ring-1 ring-red-500',
  moderate: 'border-amber-500 bg-amber-50 text-amber-800 ring-1 ring-amber-500',
  routine: 'border-emerald-500 bg-emerald-50 text-emerald-800 ring-1 ring-emerald-500',
};
// `locked` — when the classification forces the grade (a Breakdown is always 🔴 critical), the picker
// is rendered read-only: the forced level keeps its colour, the others dim, and none respond to clicks.
function FaultSeverityPicker({ value, onChange, t, locked = false }) {
  return (
    <div className="grid grid-cols-3 gap-2">
      {FAULT_SEVERITY_OPTS.map((p) => {
        const active = value === p.value;
        return (
          <button
            key={p.value}
            type="button"
            disabled={locked}
            aria-disabled={locked}
            onClick={() => onChange(active ? '' : p.value)}
            className={`flex items-center justify-center gap-1.5 rounded-xl border px-3 py-2.5 text-sm font-semibold transition ${active ? SEVERITY_STYLE[p.value] : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'} ${locked ? `cursor-not-allowed${active ? '' : ' opacity-40'}` : ''}`}
          >
            <span className="text-base leading-none">{p.emoji}</span>
            {t(`workflow.faultSeverity.${p.value}`)}
          </button>
        );
      })}
    </div>
  );
}

export default function TicketActionModal({ action, ticket, vehicles = [], garages = [], findingsCatalog = [], keywordMeta = {}, faultCausesCatalog = {}, assignableDrivers = [], allowedTypes = null, onClose, onDone }) {
  const { t } = useI18n();
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');

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

  // ---- form state (one bag; only the relevant keys are read per action) ----
  const [vehicleId, setVehicleId] = useState('');
  const [reason, setReason] = useState('test_drive');
  // The maintenance classification. On the inspector's decision ('decide') a test ALWAYS yields a
  // type — routine is the common case, so it's preselected; the inspector confirms or changes it.
  const [maintType, setMaintType] = useState(() => ticket?.maintenance_type || (action === 'decide' ? 'routine' : ''));
  const [complaint, setComplaint] = useState('');
  const [symptoms, setSymptoms] = useState([]); // selected finding tags (library picks + custom)
  // Symptom → Root-Cause diagnosis: { [symptomText]: { root_cause, root_cause_id } }. Shared by the
  // 'decide' (inspector symptoms) and 'finding' (garage tags) steps — only one action is live at a time.
  const [causes, setCauses] = useState({});
  const [driverId, setDriverId] = useState('');          // delegate: chosen logistics driver
  const [delegationTask, setDelegationTask] = useState('pickup'); // delegate: pickup | dropoff
  const [recommended, setRecommended] = useState('');
  const [notes, setNotes] = useState('');
  const [odometer, setOdometer] = useState('');
  const [vendorId, setVendorId] = useState(ticket?.vendor_id ? String(ticket.vendor_id) : '');
  const [assignNote, setAssignNote] = useState(''); // assign: supervisor's reason/note when (re)assigning the garage
  const [recoveryUnit, setRecoveryUnit] = useState('');  // recovery: towing unit name/ID
  const [recoveryPhone, setRecoveryPhone] = useState(''); // recovery: operator mobile
  const [outDate, setOutDate] = useState('');       // dispatch: date the car left (blank → today)
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
  const [requiresMaintenance, setRequiresMaintenance] = useState(true); // decide: open ticket | clear diagnostic
  const [faultSeverity, setFaultSeverity] = useState(() => ticket?.fault_severity || ''); // decide: mandatory fault-severity grade
  // decide: Repair Location — 'in_shop' (garage → alerts Waleed & Abdullah) | 'on_site' (mobile; car stays free).
  const [repairLocation, setRepairLocation] = useState('in_shop');

  // Hard coupling (Rev. 11 Gate 1): a Breakdown is, by definition, 🔴 critical. The moment the inspector
  // classifies a ticket as Breakdown at the Decide step, force the grade to critical and lock the picker
  // so an undriveable car can never be filed as moderate/routine. The backend enforces the same rule in
  // applyBreakdownConsequences(), so this is purely the UX half of a two-sided, no-exceptions guard.
  const severityLocked = action === 'decide' && maintType === 'breakdown';
  useEffect(() => {
    if (severityLocked && faultSeverity !== 'critical') setFaultSeverity('critical');
  }, [severityLocked, faultSeverity]);
  // A Breakdown must be repaired in-shop (it grounds the car) — force + lock the location so it can never
  // be filed on-site (the backend rejects the contradiction too).
  const locationLocked = action === 'decide' && maintType === 'breakdown';
  useEffect(() => {
    if (locationLocked && repairLocation !== 'in_shop') setRepairLocation('in_shop');
  }, [locationLocked, repairLocation]);
  const [photo, setPhoto] = useState(null);       // dispatch odometer: compressed { blob, url, width, height, compressedSize }
  const [compressing, setCompressing] = useState(false);
  const [findingTags, setFindingTags] = useState([]); // 'finding': selected garage-finding tags
  const [findingSeverity, setFindingSeverity] = useState('');
  // 'ready': actual repair time (hours) attributed to each fault tag, keyed by the finding text.
  const [repairTimes, setRepairTimes] = useState({});
  // 'ready': actual COST (AED) attributed to each fault, keyed by the finding text. A quick, per-fault
  // alternative to the full Parts & Labor editor — each becomes a finding-linked labor line on submit,
  // so it rolls into the ticket total + per-fault cost reports (Diagnosis-First stays satisfied).
  const [repairCosts, setRepairCosts] = useState({});
  // 'ready' & 'lineitems': the structured Parts + Labor breakdown (auto-sums into the ticket cost).
  // Seeded from the ticket on the deferred-edit ('lineitems') path so it opens with the current set.
  const [lineItems, setLineItems] = useState(() => (action === 'lineitems' ? (ticket?.line_items ?? []) : []));
  // Garage Invoice Validation ('lineitems'): the garage's printed receipt total + the explanation for any
  // gap between it and the itemised sum. Seeded from the ticket so a re-open shows what was recorded.
  const [receiptTotal, setReceiptTotal] = useState(() => (action === 'lineitems' && ticket?.receipt_total != null ? String(ticket.receipt_total) : ''));
  const [varianceExplanation, setVarianceExplanation] = useState(() => (action === 'lineitems' ? (ticket?.variance_explanation ?? '') : ''));
  const [followNote, setFollowNote] = useState(''); // 'followup': the Driver's update line
  const [followLog, setFollowLog] = useState(() => ticket?.follow_ups ?? []); // live note log (newest-first below)
  const [savedAt, setSavedAt] = useState(null); // `at` of the just-saved note → green "Saved ✓" highlight

  const garageOptions = useMemo(
    () => garages.map((g) => ({ id: g.id, label: g.name, sub: g.phone || g.type })),
    [garages],
  );

  const driverOptions = useMemo(
    () => assignableDrivers.map((d) => ({ id: d.id, label: d.name, sub: d.email })),
    [assignableDrivers],
  );

  // ── Odometer Continuity — the "Previous Odometer" the driver should expect + the live verdict ──
  // The previous reading depends on the step: dispatch compares to the inspector's test anchor, receive
  // to the pickup, ready to the garage intake; the test-drive steps compare to the car's live odometer.
  const [odoConfirmed, setOdoConfirmed] = useState(false);
  const [odoNote, setOdoNote] = useState(''); // mandatory explanation when the reading is >10 km off the previous
  const prevOdometer = useMemo(() => {
    if (action === 'dispatch' || action === 'recovery') return ticket?.test_odometer ?? null;
    // Decide (end-of-test-drive reading) — compared to the inspector's start-of-drive anchor, so a normal
    // short test reads as clean forward travel; a big loop trips the >10 km note.
    if (action === 'decide') return ticket?.test_odometer ?? null;
    // Collect-from-garage (garage-OUT reading) — vs the garage-arrival reading, so a forward delta reads as
    // the garage having road-tested it (garage-transfer stage → tolerance waived; backward still flags).
    if (action === 'collectFromGarage') return ticket?.receive_odometer ?? ticket?.dispatch_odometer ?? ticket?.test_odometer ?? null;
    if (action === 'receive') return ticket?.dispatch_odometer ?? null;
    // Re-inspection sign-off — the QC reading is compared to the last one on the chain (the garage-out
    // return reading, else the earlier captures), so it reads as clean forward continuity by default.
    if (action === 'reinspect') return ticket?.return_odometer ?? ticket?.receive_odometer ?? ticket?.dispatch_odometer ?? ticket?.test_odometer ?? null;
    if (action === 'start') return vehicles.find((v) => v.id === ticket?.vehicle_id)?.odometer ?? null;
    if (action === 'open') return vehicles.find((v) => String(v.id) === String(vehicleId))?.odometer ?? null;
    return null;
  }, [action, ticket, vehicles, vehicleId]);

  const contStage = useMemo(() => {
    switch (action) {
      case 'open':
      case 'start': return STAGE.TEST;
      // Decide — the end-of-test-drive reading, forward from the start anchor (same TEST rule).
      case 'decide': return STAGE.TEST;
      case 'dispatch':
      case 'recovery': return STAGE.PICKUP;
      case 'receive': return STAGE.GARAGE_IN;
      // Collect-from-garage — the car leaves the garage: garage-OUT continuity (forward = a road test,
      // tolerance waived; backward still flags a Discrepancy).
      case 'collectFromGarage': return STAGE.GARAGE_OUT;
      // 'ready' captures no odometer (car doesn't move in the workshop) → no continuity stage.
      // Re-inspection sign-off — plain forward-continuity vs the last recorded reading.
      case 'reinspect': return STAGE.RETURN;
      default: return null;
    }
  }, [action, ticket]);

  const continuity = useMemo(
    () => (contStage ? evaluateContinuity(odometer, prevOdometer, contStage) : null),
    [contStage, odometer, prevOdometer],
  );
  // Context-aware tolerance: a site↔garage move (receive/ready) is a deliberate road trip, so the mileage
  // INCREASE is expected — we waive the ±10 km note/confirm nag for it. Internal checks (test/pickup/
  // re-inspection) keep the rule. A backward reading still flags a Discrepancy on every stage.
  const ignoreOdoTolerance = stageIgnoresTolerance(contStage);
  const odoNeedsConfirm = needsConfirm(continuity?.status, ignoreOdoTolerance);
  // A reading more than 10 km off the previous one (either direction) demands a written note — UNLESS this
  // is a garage transfer, where a big forward gap is the whole point of the trip. This also forces the
  // acknowledgment checkbox (see OdometerContinuityHint), so the ack requirement is the union.
  const odoNoteRequired = needsNote(continuity, ignoreOdoTolerance);
  const odoAckRequired = odoNeedsConfirm || odoNoteRequired;
  // The odometer gate for the current step: the acknowledgment (when required) AND the note (when a big
  // gap demands it) must both be satisfied before the step can submit. Steps with no odometer capture
  // have continuity === null, so both requirements are false and this is a no-op.
  const odoGateBlocked = (odoAckRequired && !odoConfirmed) || (odoNoteRequired && !odoNote.trim());

  // A changed reading invalidates a prior acknowledgment + note — re-confirm/re-explain the new value.
  useEffect(() => { setOdoConfirmed(false); setOdoNote(''); }, [odometer]);

  // Test-drive START (open / start): the car hasn't been driven yet, so the reading at this moment
  // IS the car's last recorded mileage. Pre-fill the field with the "Previous Odometer" so it reads
  // 0 km driven / Verified by default; the inspector only overrides it if the dashboard differs.
  // Seeds once per (action, vehicle) via a ref, so switching cars re-seeds but typing/clearing is
  // never clobbered.
  const seededOdoFor = useRef(null);
  useEffect(() => {
    if ((action === 'start' || action === 'open') && prevOdometer != null) {
      const key = `${action}:${ticket?.vehicle_id ?? vehicleId ?? ''}`;
      if (seededOdoFor.current !== key) {
        seededOdoFor.current = key;
        setOdometer(String(prevOdometer));
      }
    }
  }, [action, prevOdometer, ticket, vehicleId]);

  // Re-inspection, per-fault. Open faults (not completed/cancelled) become the checklist the inspector
  // signs off. With faults present, the outcome is DERIVED (any fault still broken → fail); a legacy
  // ticket with no faults falls back to the manual pass/fail toggle.
  const openFaults = useMemo(
    () => (ticket?.tasks || []).filter((tk) => !tk.is_terminal),
    [ticket],
  );
  const perFault = action === 'reinspect' && openFaults.length > 0;
  const brokenIds = openFaults.filter((tk) => broken[tk.id]).map((tk) => tk.id);
  const reFail = perFault ? brokenIds.length > 0 : outcome === 'fail';
  // On a FAILED re-inspection the inspector sees the garage the car came back broken from and may
  // re-route it to a DIFFERENT garage for the supervisor's re-dispatch — changing it warns and
  // makes the reason note mandatory.
  const reFailGarageChanged = action === 'reinspect' && reFail && !!vendorId && String(vendorId) !== String(ticket?.vendor_id || '');

  // 'ready' step — per-fault cost boxes turn into finding-linked labor lines (quantity 1 × the amount),
  // merged with any lines from the Parts & Labor editor. When present they own the cost, so the
  // lump-sum fallback below is hidden (same rule as when the editor has lines).
  const perFaultCostLines = () =>
    Object.entries(repairCosts)
      .filter(([, v]) => v !== '' && v != null && !Number.isNaN(Number(v)) && Number(v) > 0)
      .map(([text, v]) => ({ kind: 'labor', finding_text: text, description: text, quantity: 1, unit_price: Number(v) }));
  const hasPerFaultCost = perFaultCostLines().length > 0;

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
      case 'request':
        return { url: `${base}/request`, body: { vehicle_id: Number(vehicleId), trigger_reason: reason, customer_complaint: complaint || null } };
      case 'start':
        // Inspector's odometer at test-drive start (the chain anchor) — posted multipart with the photo.
        return { url: `${base}/${ticket.id}/start`, body: { test_odometer: Number(odometer) } };
      case 'followup':
        return { url: `${base}/${ticket.id}/follow-up`, body: { note: followNote } };
      case 'open':
        // No maintenance_type here — the inspection isn't done yet. Classification is set later in
        // the Submit Report ('decide') step, once the inspector has actually diagnosed the car.
        // test_odometer (+ photo) is captured up front and posted multipart (see submit()).
        return { url: base, body: { vehicle_id: Number(vehicleId), trigger_reason: reason, customer_complaint: reason === 'customer_reported' ? complaint : null, test_odometer: Number(odometer) } };
      case 'typechange':
        return { url: `${base}/${ticket.id}/type`, body: { maintenance_type: maintType }, method: 'patch' };
      case 'decide':
        return { url: `${base}/${ticket.id}/report`, body: { requires_maintenance: requiresMaintenance, symptoms, causes: buildCauses(symptoms), fault_severity: requiresMaintenance ? (faultSeverity || null) : null, recommended_action: recommended || null, notes: notes || null, maintenance_type: maintType || null, repair_location: requiresMaintenance ? repairLocation : null, report_odometer: odometer ? Number(odometer) : null, odometer_note: odoNote.trim() || null } };
      case 'delegate':
        return { url: `${base}/${ticket.id}/delegate`, body: { driver_id: Number(driverId), delegation_task: delegationTask } };
      case 'assign':
        return { url: `${base}/${ticket.id}/assign-dispatch`, body: { vendor_id: Number(vendorId), driver_id: driverId ? Number(driverId) : null, expected_return_date: returnDate || null, note: assignNote.trim() || null } };
      case 'dispatch':
        // Garage is the supervisor's pre-assigned choice — the driver doesn't send it.
        return { url: `${base}/${ticket.id}/dispatch`, body: { dispatch_odometer: Number(odometer), out_date: outDate || null, expected_return_date: returnDate || null } };
      case 'recovery':
        // Towing variant — multipart (odometer + mandatory photo + the recovery unit) is built in submit().
        return { url: `${base}/${ticket.id}/recovery-dispatch`, body: {} };
      case 'receive':
        return { url: `${base}/${ticket.id}/under-repair`, body: { receive_odometer: odometer ? Number(odometer) : null, garage_feedback: feedback || null, expected_return_date: returnDate || null } };
      case 'ready':
        return { url: `${base}/${ticket.id}/ready`, body: { garage_feedback: feedback || null, cost: cost === '' ? null : Number(cost) } };
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
      case 'requestinvoice':
        // Path A — ask the garage for an itemised invoice (the team is alerted to chase it).
        return { url: `${base}/${ticket.id}/request-invoice`, body: {} };
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
        const list = findingTags.map((text) => ({ text, severity: findingSeverity || null, root_cause: causes[text]?.root_cause || null, root_cause_id: causes[text]?.root_cause_id ?? null }));
        return { url: `${base}/${ticket.id}/findings`, body: { findings: list } };
      }
      case 'reinspect': {
        if (!reFail) {
          // PASS — every fault verified fixed → car returns to service. If the invoice isn't ready, defer
          // it: the car still goes back, the ticket parks in awaiting_invoice (never blocks on paperwork).
          return { url: `${base}/${ticket.id}/close`, body: { cost: cost === '' ? null : Number(cost), vendor_id: vendorId ? Number(vendorId) : null, actual_in_date: inDate || null, notes: notes || null, defer_invoice: deferInvoice, final_odometer: odometer ? Number(odometer) : null, odometer_note: odoNote.trim() || null } };
        }
        // FAIL — some faults still broken → back to the supervisor for re-dispatch, with per-fault blame.
        const failed_notes = {};
        brokenIds.forEach((id) => { if (brokenNote[id]?.trim()) failed_notes[id] = brokenNote[id].trim(); });
        return { url: `${base}/${ticket.id}/reopen`, body: { reason: notes || null, failed_task_ids: brokenIds, failed_notes, redispatch_vendor_id: reFailGarageChanged ? Number(vendorId) : null } };
      }
      default:
        return null;
    }
  }

  // ---- minimal client guard (the server is the source of truth) ----
  function invalid() {
    if (action === 'request') return !vehicleId;
    // Re-inspection: on FAIL, re-routing to a different garage needs a written reason (same-garage fail
    // is unaffected). On PASS the car is physically back, so the final QC odometer is mandatory — plus the
    // shared >10 km ack/note gate. No odometer is asked on the FAIL branch (the car goes back out).
    if (action === 'reinspect') {
      if (reFail) return reFailGarageChanged && !notes.trim();
      return !odometer || Number(odometer) < 1 || odoGateBlocked;
    }
    // Open & Start: the inspector captures the odometer reading + photo BEFORE the test drive (both mandatory).
    if (action === 'open') return !vehicleId || !odometer || Number(odometer) < 1 || !photo || compressing || odoGateBlocked;
    if (action === 'start') return !odometer || Number(odometer) < 1 || !photo || compressing || odoGateBlocked;
    if (action === 'typechange') return !maintType;
    // Opening a ticket requires BOTH a classification and a mandatory fault-severity grade
    // (a cleared diagnostic needs neither). When it opens a ticket, every symptom that has a preset
    // cause-list must also be diagnosed (Symptom → Root-Cause). A cleared diagnostic skips the cause gate.
    // The end-of-test-drive odometer is OPTIONAL here (the inspector may record it), but if entered it must
    // clear the same continuity/>10 km note gate as every other capture (odoGateBlocked is false when blank).
    if (action === 'decide') return (requiresMaintenance && (!maintType || !faultSeverity || !rootCausesComplete(symptoms, faultCausesCatalog, causes))) || odoGateBlocked;
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
    // Mark ready: final odometer AND its photo are both mandatory (data-integrity gate). Diagnosis-First:
    // any parts/labor line must be linked to a finding (line items themselves stay optional here).
    // Mark ready ("Maintenance complete"): no odometer/photo here (the car didn't move in the workshop).
    // Diagnosis-First still holds — any parts/labor line must be linked to a finding.
    if (action === 'ready') return lineItemsUnlinked(lineItems);
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
    if (action === 'finding') return findingTags.length === 0 || !rootCausesComplete(findingTags, faultCausesCatalog, causes);
    if (action === 'delegate') return !driverId || !delegationTask;
    if (action === 'assign') return !vendorId; // garage required; driver is optional (may go to the pool)
    // Supervisor Video-Review: a re-fix needs a reason; approval is blocked only when we KNOW there's no
    // video yet (has_video === false). When it's unknown (null) we let the server enforce the video rule.
    if (action === 'requestRefix') return !notes.trim();
    if (action === 'approveRepair') return ticket?.has_video === false;
    // Both return-leg checkpoints CANNOT complete without their mandatory photo.
    // Collect from garage: the garage-OUT odometer AND the "received from garage" photo are both mandatory.
    if (action === 'collectFromGarage') return !odometer || Number(odometer) < 1 || !photo || compressing || odoGateBlocked;
    if (action === 'arriveAtPark') return !photo || compressing;
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

  async function submit() {
    const r = resolve();
    if (!r) return;
    setBusy(true);
    setErr('');
    try {
      // Steps that carry the odometer image go as multipart (ingested server-side): the test-drive
      // start (open/start), dispatch, and ready — all carry the mandatory odometer photo. JSON otherwise.
      let resp;
      const isTestStart = action === 'start' || action === 'open';
      if (r.method === 'patch') {
        resp = await api.patch(r.url, r.body);
      } else if (r.method === 'put') {
        resp = await api.put(r.url, r.body);
      } else if ((action === 'dispatch' && photo?.blob) || (action === 'recovery' && photo?.blob) || (action === 'receive' && photo?.blob) || action === 'ready' || (isTestStart && photo?.blob) || (action === 'collectFromGarage' && photo?.blob) || (action === 'arriveAtPark' && photo?.blob)) {
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
          if (outDate) fd.append('out_date', outDate);
          if (returnDate) fd.append('expected_return_date', returnDate);
        } else if (action === 'receive') {
          // Arrival check-in — the mandatory arrival odometer + its photo, plus optional intake notes.
          fd.append('receive_odometer', String(Number(odometer)));
          if (feedback) fd.append('garage_feedback', feedback);
          if (returnDate) fd.append('expected_return_date', returnDate);
        } else if (action === 'ready') {
          // No odometer here — the car doesn't move inside the workshop (removed the final-reading capture).
          if (feedback) fd.append('garage_feedback', feedback);
          if (cost !== '') fd.append('cost', String(Number(cost)));
          // Per-fault repair time: only the faults the user gave a (valid, non-negative) number.
          const rt = Object.entries(repairTimes)
            .filter(([, v]) => v !== '' && v != null && !Number.isNaN(Number(v)) && Number(v) >= 0)
            .map(([text, v]) => ({ text, hours: Number(v) }));
          if (rt.length) fd.append('repair_times', JSON.stringify(rt));
          // Structured Parts + Labor breakdown — the editor's lines PLUS any quick per-fault cost boxes
          // (each a finding-linked labor line). Both feed the one line_items channel the backend re-sums.
          const li = [...serializeLineItems(lineItems), ...perFaultCostLines()];
          if (li.length) fd.append('line_items', JSON.stringify(li));
        } else if (action === 'collectFromGarage') {
          // Garage-OUT reading (car leaves the garage) + the mandatory "received from garage" photo below.
          fd.append('return_odometer', String(Number(odometer)));
        } else if (action === 'arriveAtPark') {
          if (notes) fd.append('notes', notes);
        } else {
          // start | open — the inspector's odometer reading at test-drive start (the chain anchor).
          fd.append('test_odometer', String(Number(odometer)));
          if (action === 'open') {
            fd.append('vehicle_id', String(Number(vehicleId)));
            fd.append('trigger_reason', reason);
            if (reason === 'customer_reported' && complaint) fd.append('customer_complaint', complaint);
          }
        }
        // Odometer note — the mandatory explanation for a >10 km gap from the previous reading. Applies
        // to every odometer-capturing step; only ever set when the gate demanded it, so it's blank otherwise.
        if (odoNote.trim()) fd.append('odometer_note', odoNote.trim());
        if (photo?.blob) fd.append('odometer_photo', photo.blob, 'odometer.jpg');
        resp = await api.post(r.url, fd);
      } else {
        resp = await api.post(r.url, r.body);
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
      setErr(e.response?.data?.message || t('workflow.error.generic'));
    } finally {
      setBusy(false);
    }
  }

  function successMessage() {
    const who = ticket ? (ticket.plate || `#${ticket.id}`) : 'Ticket';
    switch (action) {
      case 'request': return t('workflow.success.request', { who });
      case 'start': return t('workflow.success.start', { who });
      case 'followup': return t('workflow.success.followup', { who });
      case 'open': return t('workflow.success.open');
      case 'decide': return requiresMaintenance ? t('workflow.success.decideRequires', { who }) : t('workflow.success.decideClear', { who });
      case 'dispatch': return photo ? t('workflow.success.dispatchPhoto', { who }) : t('workflow.success.dispatch', { who });
      case 'recovery': return t('workflow.success.recovery', { who });
      case 'receive': return t('workflow.success.receive', { who });
      case 'ready': return t('workflow.success.ready', { who });
      case 'finding': return t('workflow.success.finding', { who });
      case 'reinspect': return reFail ? t('workflow.success.reinspectFail', { who }) : t(deferInvoice ? 'workflow.success.reinspectAwaitInvoice' : 'workflow.success.reinspectPass', { who });
      case 'typechange': return t('workflow.success.typechange', { who, type: t(`workflow.type.${maintType}`) });
      case 'delegate': return t('workflow.success.delegate', { who });
      case 'assign': return t('workflow.success.assign', { who });
      case 'lineitems': return t('workflow.success.lineitems', { who });
      case 'requestinvoice': return t('workflow.success.requestinvoice', { who });
      case 'approveRepair': return t('workflow.success.approveRepair', { who });
      case 'requestRefix': return t('workflow.success.requestRefix', { who });
      case 'collectFromGarage': return t('workflow.success.collectFromGarage', { who });
      case 'arriveAtPark': return t('workflow.success.arriveAtPark', { who });
      default: return '';
    }
  }

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
      onClose={onClose}
      size={['decide', 'ready', 'lineitems'].includes(action) ? 'lg' : 'md'}
      title={t(`workflow.meta.${action}.title`)}
      subtitle={ticket ? `${ticket.plate || `#${ticket.id}`}${ticket.car ? ` · ${ticket.car}` : ''}` : t(`workflow.meta.${action}.sub`)}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>{t('common.cancel')}</Button>
          <Button variant={submitVariant} onClick={submit} loading={busy} disabled={invalid()}>
            {submitLabel}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {err && <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{err}</div>}

        {/* Stage timing — per-stage durations + total downtime, at a glance on any existing ticket */}
        {ticket && <StageTimeline ticket={ticket} t={t} />}

        {/* Original findings — inherited and shown at every stage, grouped by source */}
        {ticket?.findings?.length > 0 && action !== 'decide' && (
          <div className="rounded-xl border border-slate-100 bg-slate-50/60 p-3">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{t('workflow.field.findings')}</p>
            <FindingsList findings={ticket.findings} />
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
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
              {visibleTypes.map((ty) => {
                const active = maintType === ty.value;
                return (
                  <button
                    key={ty.value}
                    type="button"
                    onClick={() => setMaintType(ty.value)}
                    className={`flex items-center gap-2 rounded-xl border px-3 py-2 text-start transition ${active ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500' : 'border-slate-200 bg-white hover:border-slate-300'}`}
                  >
                    <span className="shrink-0 text-base leading-none">{ty.icon}</span>
                    <span className={`min-w-0 truncate text-xs font-medium ${active ? 'text-indigo-800' : 'text-slate-700'}`}>{t(`workflow.type.${ty.value}`)}</span>
                  </button>
                );
              })}
            </div>
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
                {TRIGGER_REASON_VALUES.map((rv) => {
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
            </div>
            {reason === 'customer_reported' && (
              <Textarea label={t('workflow.field.customerReport')} value={complaint} onChange={(e) => setComplaint(e.target.value)} placeholder={t('workflow.ph.brakePull')} />
            )}

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

        {/* STAGE 2 — test-drive report + the repair decision */}
        {action === 'decide' && (
          <>
            {/* End-of-test-drive odometer — optional here, so the inspector can log the reading when they
                step out of the car. Runs the same continuity + >10 km note gate as every other capture and
                lands as its own "End of test drive" row in the mileage timeline. */}
            <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-3">
              <Input label={t('workflow.field.reportOdometerKm')} type="number" min="1" value={odometer} onChange={(e) => setOdometer(e.target.value)} placeholder={ticket?.test_odometer ? t('workflow.ph.startedAt', { km: Number(ticket.test_odometer).toLocaleString() }) : t('workflow.ph.odometerExample')} />
              <OdometerContinuityHint previous={prevOdometer} continuity={continuity} confirmed={odoConfirmed} onConfirm={setOdoConfirmed} noteRequired={odoNoteRequired} note={odoNote} onNote={setOdoNote} ignoreTolerance={ignoreOdoTolerance} t={t} />
              <p className="mt-1.5 text-xs text-slate-400">{t('workflow.hint.reportOdometer')}</p>
            </div>
            <div>
              <span className="mb-1.5 block text-sm font-medium text-slate-700">{t('workflow.field.findingsTapAll')}</span>
              <FindingsPicker catalog={findingsCatalog} keywordMeta={keywordMeta} value={symptoms} onChange={setSymptoms} locked={lockedFindings} />
            </div>
            {/* Symptom → Root-Cause — the mandatory diagnostic step: name the probable cause per symptom */}
            {symptoms.length > 0 && (
              <div>
                <span className="mb-1.5 block text-sm font-medium text-slate-700">Probable root cause<Req /></span>
                <RootCausePicker symptoms={symptoms} catalog={faultCausesCatalog} value={causes} onChange={setCauses} />
              </div>
            )}
            {/* Chronic Fault Watchdog — warns instantly if any picked fault was repaired before */}
            <FaultHistoryInsight
              vehicleId={ticket?.vehicle_id}
              tags={Array.from(new Set([...lockedFindings, ...symptoms]))}
              excludeTicketId={ticket?.id}
            />
            <Input label={t('workflow.field.recommendedAction')} value={recommended} onChange={(e) => setRecommended(e.target.value)} placeholder={t('workflow.ph.replacePads')} />
            <Textarea label={t('common.notes')} value={notes} onChange={(e) => setNotes(e.target.value)} rows={3} placeholder={t('workflow.ph.testDriveNotes')} />

            {/* Inspector's official classification — the authoritative source; Driver's request carries none */}
            <div>
              <span className="mb-1.5 block text-sm font-medium text-slate-700">
                {t('workflow.type.label')}<Req />
              </span>
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                {visibleTypes.map((ty) => {
                  const active = maintType === ty.value;
                  return (
                    <button
                      key={ty.value}
                      type="button"
                      onClick={() => setMaintType(ty.value)}
                      className={`flex items-center gap-2 rounded-xl border px-3 py-2 text-start transition ${active ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500' : 'border-slate-200 bg-white hover:border-slate-300'}`}
                    >
                      <span className="shrink-0 text-base leading-none">{ty.icon}</span>
                      <span className={`min-w-0 truncate text-xs font-medium ${active ? 'text-indigo-800' : 'text-slate-700'}`}>{t(`workflow.type.${ty.value}`)}</span>
                    </button>
                  );
                })}
              </div>
            </div>

            {/* The decision — only this turns the diagnostic into a real ticket */}
            <div className="border-t border-slate-100 pt-4">
              <span className="mb-1.5 block text-sm font-medium text-slate-700">{t('workflow.decision.label')}</span>
              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={() => setRequiresMaintenance(true)}
                  className={`flex-1 rounded-xl px-4 py-3 text-sm font-semibold ring-1 transition ${requiresMaintenance ? 'bg-indigo-600 text-white ring-indigo-600' : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'}`}
                >
                  {t('workflow.decision.requires')}
                </button>
                <button
                  type="button"
                  onClick={() => setRequiresMaintenance(false)}
                  className={`flex-1 rounded-xl px-4 py-3 text-sm font-semibold ring-1 transition ${!requiresMaintenance ? 'bg-emerald-600 text-white ring-emerald-600' : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'}`}
                >
                  {t('workflow.decision.noNeed')}
                </button>
              </div>
              <p className="mt-1.5 text-xs text-slate-400">
                {requiresMaintenance ? t('workflow.hint.requiresMaintenance') : t('workflow.hint.noMaintenance')}
              </p>
            </div>

            {/* Fault Severity — the inspector's MANDATORY diagnostic grade, gating "Requires maintenance".
                It becomes the headline urgency the supervisor reads first on the dispatch board. */}
            {requiresMaintenance && (
              <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-3">
                <span className="mb-1.5 block text-sm font-semibold text-slate-700">{t('workflow.faultSeverity.label')}<Req /></span>
                <FaultSeverityPicker value={faultSeverity} onChange={setFaultSeverity} t={t} locked={severityLocked} />
                <p className="mt-1.5 text-xs text-slate-400">
                  {severityLocked ? t('workflow.faultSeverity.breakdownLocked') : t('workflow.faultSeverity.hint')}
                </p>
              </div>
            )}

            {/* Repair Location — where does this repair happen? On-Site (mobile — car stays available) or
                In-Shop (goes to a garage → Waleed & Abdullah are alerted to assign one). A breakdown is
                locked to In-Shop (it grounds the car). */}
            {requiresMaintenance && (
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
            )}
          </>
        )}

        {/* PHASE 2 — Supervisor (dispatcher) picks the garage; the whole Driver pool is then notified to collect */}
        {action === 'assign' && (
          <>
            <div className="rounded-lg bg-amber-50/70 px-3 py-2 text-xs text-amber-700 ring-1 ring-inset ring-amber-600/10">
              {t('workflow.hint.assignBanner')}
            </div>
            {/* Re-dispatch after a failed re-inspection ("came back broken"): the last garage is kept and
                pre-selected, since a botched repair usually goes back to the same shop — but the supervisor
                can still send it elsewhere (and the failure badge shows who to blame). */}
            {ticket?.workflow_status === 'reinspection_failed' && ticket?.garage && (
              <div className="rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700 ring-1 ring-inset ring-rose-600/15">
                ⛔ {t('workflow.hint.redispatchSameGarage', { garage: ticket.garage })}
              </div>
            )}
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.field.destinationGarage')}<Req /></span>
              <SearchSelect value={vendorId} onChange={setVendorId} options={garageOptions} placeholder={t('workflow.ph.pickGarage')} />
              <p className="mt-1.5 text-xs text-slate-400">{t('workflow.hint.notifyAllDrivers')}</p>
            </div>
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
              🔮 {t('workflow.hint.autoReturnEstimate')}
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

            {/* Garage is read-only here — it's the supervisor's pre-assigned choice; the driver cannot change it. */}
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.field.destinationGarage')}</span>
              <div className="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm">
                <Icon.Wrench className="h-4 w-4 shrink-0 text-slate-400" />
                <span className="font-medium text-slate-700">{ticket?.garage || t('workflow.hint.garageNotAssigned')}</span>
                <span className="ms-auto text-[11px] text-slate-400">{t('workflow.hint.garageBySupervisor')}</span>
              </div>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <Input label={t('workflow.field.dateLeft')} type="date" value={outDate} onChange={(e) => setOutDate(e.target.value)} />
              <Input label={t('workflow.field.expectedReturn')} type="date" value={returnDate} onChange={(e) => setReturnDate(e.target.value)} />
            </div>
            <p className="text-xs text-slate-400">{t('workflow.hint.blankDateToday')}</p>
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

            {/* The odometer/condition gate still applies before the car leaves — exactly as for a driver. */}
            <Input label={t('workflow.field.odometerKm')} type="number" min="1" required value={odometer} onChange={(e) => setOdometer(e.target.value)} placeholder={t('workflow.ph.odometerExample')} />
            <OdometerContinuityHint previous={prevOdometer} continuity={continuity} confirmed={odoConfirmed} onConfirm={setOdoConfirmed} noteRequired={odoNoteRequired} note={odoNote} onNote={setOdoNote} ignoreTolerance={ignoreOdoTolerance} t={t} />
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.recovery.photoLabel')}<Req /></span>
              {photoTile}
            </div>

            {/* Garage — decoupled from the classic "Assign Garage" screen: when the ticket doesn't have one
                yet (the common breakdown case, dispatched straight from inspection_pending), pick it right
                here as part of this same action. Once a garage IS already set (the classic assign step ran,
                or a re-dispatch), it's shown read-only — this form only ever tows to the assigned garage. */}
            {ticket?.vendor_id ? (
              <div>
                <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.field.destinationGarage')}</span>
                <div className="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm">
                  <Icon.Wrench className="h-4 w-4 shrink-0 text-slate-400" />
                  <span className="font-medium text-slate-700">{ticket.garage}</span>
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
            <div className="grid gap-4 sm:grid-cols-2">
              <Input label={t('workflow.field.dateLeft')} type="date" value={outDate} onChange={(e) => setOutDate(e.target.value)} />
              <Input label={t('workflow.field.expectedReturn')} type="date" value={returnDate} onChange={(e) => setReturnDate(e.target.value)} />
            </div>
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
            <Input label={t('workflow.field.expectedReturn')} type="date" value={returnDate} onChange={(e) => setReturnDate(e.target.value)} />
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
            <Textarea label={t('workflow.field.garageFeedback')} value={feedback} onChange={(e) => setFeedback(e.target.value)} placeholder={t('workflow.ph.whatWasDone')} />

            {/* Granular per-fault time + cost: attribute the ACTUAL repair time (hours) AND cost (AED) to
                each fault. Both optional — hours powers the Garage Efficiency & Fault Recurrence reports;
                each cost becomes a finding-linked labor line that rolls into the ticket total. */}
            {ticket?.findings?.length > 0 && (
              <div>
                <span className="mb-1.5 block text-sm font-medium text-slate-700">{t('workflow.field.timeCostPerFault')}</span>
                <div className="space-y-1.5">
                  {ticket.findings.map((f, i) => (
                    <div key={f.text ? `${f.text}-${i}` : i} className="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5">
                      <span className="min-w-0 flex-1 truncate text-sm text-slate-700">
                        {f.text}
                        {f.source && <span className="ms-1.5 text-[10px] font-medium uppercase tracking-wide text-slate-400">{f.source}</span>}
                      </span>
                      {/* Hours */}
                      <input
                        type="number"
                        min="0"
                        step="0.5"
                        value={repairTimes[f.text] ?? ''}
                        onChange={(e) => setRepairTimes((p) => ({ ...p, [f.text]: e.target.value }))}
                        placeholder="0"
                        className="w-16 rounded-lg border border-slate-300 px-2 py-1 text-sm tabular-nums text-slate-700 focus:border-indigo-400 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                      />
                      <span className="text-xs text-slate-400">{t('workflow.field.hoursShort')}</span>
                      {/* Cost (AED) */}
                      <input
                        type="number"
                        min="0"
                        step="0.01"
                        value={repairCosts[f.text] ?? ''}
                        onChange={(e) => setRepairCosts((p) => ({ ...p, [f.text]: e.target.value }))}
                        placeholder="0.00"
                        className="w-24 rounded-lg border border-slate-300 px-2 py-1 text-sm tabular-nums text-slate-700 focus:border-indigo-400 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                      />
                      <span className="text-xs text-slate-400">{t('workflow.field.aedShort')}</span>
                    </div>
                  ))}
                </div>
                <p className="mt-1.5 text-xs text-slate-400">{t('workflow.hint.timeCostHint')}</p>
              </div>
            )}

            {/* Structured Parts + Labor breakdown — itemise the bill so cost is tracked per part and
                per repair (auto-summed). When any line is added it OWNS the cost, so the lump-sum
                field below is only the fallback for a quick total with no breakdown. */}
            <div className="border-t border-slate-100 pt-4">
              <span className="mb-1.5 block text-sm font-semibold text-slate-700">{t('workflow.lineItem.heading')}</span>
              <LineItemsEditor value={lineItems} onChange={setLineItems} catalog={findingsCatalog} findings={ticket?.findings || []} />
            </div>

            {/* Lump-sum fallback — only when there's no itemised cost at all (no editor lines AND no
                per-fault cost boxes), so we never double up the total. */}
            {lineItems.length === 0 && !hasPerFaultCost && (
              <Input label={t('workflow.field.repairCostAed')} type="number" min="0" value={cost} onChange={(e) => setCost(e.target.value)} placeholder={t('workflow.ph.costExample')} />
            )}
          </>
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
                    const failCount = Number(task.reinspection_failures) || 0;
                    return (
                      <div key={task.id} className={`rounded-xl border px-3 py-2.5 transition ${isBroken ? 'border-red-300 bg-red-50/60' : 'border-slate-200 bg-white'}`}>
                        <div className="flex items-center gap-2">
                          <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm font-medium text-slate-800" title={task.symptom}>{task.symptom}</span>
                            {/* Prior blame — this fault already flunked a re-inspection at a garage before. */}
                            {failCount > 0 && (
                              <span className="mt-0.5 inline-flex items-center gap-1 rounded-md bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-700">
                                ⛔ {t('workflow.reinspect.unresolvedBadge', { n: failCount, garage: task.last_failed_garage || t('workflow.task.unassigned') })}
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
                              onClick={() => setBroken((p) => ({ ...p, [task.id]: true }))}
                              className={`rounded-lg px-2.5 py-1.5 text-xs font-semibold ring-1 transition ${isBroken ? 'bg-red-600 text-white ring-red-600' : 'bg-white text-slate-500 ring-slate-300 hover:bg-slate-50'}`}
                            >
                              {t('workflow.reinspect.markBroken')}
                            </button>
                          </div>
                        </div>
                        {isBroken && (
                          <input
                            type="text"
                            value={brokenNote[task.id] ?? ''}
                            onChange={(e) => setBrokenNote((p) => ({ ...p, [task.id]: e.target.value }))}
                            placeholder={t('workflow.reinspect.faultNotePh')}
                            className="mt-2 w-full rounded-lg border border-red-200 bg-white px-2.5 py-1.5 text-sm text-slate-700 focus:border-red-400 focus:outline-none focus:ring-1 focus:ring-red-400"
                          />
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
                    ⚠️ {t('workflow.reinspect.garageChangeAlert')}
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
              <FindingsPicker catalog={findingsCatalog} keywordMeta={keywordMeta} value={findingTags} onChange={setFindingTags} locked={lockedFindings} />
            </div>
            {/* Symptom → Root-Cause — diagnose each garage-found issue (mandatory where a cause-list exists) */}
            {findingTags.length > 0 && (
              <div>
                <span className="mb-1.5 block text-sm font-medium text-slate-700">Probable root cause<Req /></span>
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

        {/* STAGE 0 — Driver requests an inspection (self-contained: vehicle + reason + notes) */}
        {action === 'request' && (
          <>
            <div className="rounded-lg bg-violet-50/70 px-3 py-2 text-xs text-violet-700 ring-1 ring-inset ring-violet-600/10">
              {t('workflow.hint.requestBanner')}
            </div>
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">{t('workflow.field.vehicle')}<Req /></span>
              <VehicleStatusSelect value={vehicleId} onChange={setVehicleId} vehicles={vehicles} placeholder={t('workflow.ph.searchVehicle')} />
            </div>
            <div>
              <span className="mb-1.5 block text-sm font-medium text-slate-700">{t('workflow.reason.labelShort')}</span>
              <div className="grid gap-2">
                {TRIGGER_REASON_VALUES.map((rv) => {
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
            </div>
            <Textarea label={t('workflow.field.notesForInspector')} value={complaint} onChange={(e) => setComplaint(e.target.value)} placeholder={t('workflow.ph.customerPullLeft')} />
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
            {ticket?.customer_complaint && (
              <p className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">“{ticket.customer_complaint}”</p>
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
            <div>
              <span className="mb-1.5 block text-sm font-medium text-slate-700">{t('workflow.field.delegationTask')}<Req /></span>
              <div className="grid grid-cols-2 gap-2">
                {['pickup', 'dropoff'].map((tk) => {
                  const active = delegationTask === tk;
                  return (
                    <button
                      key={tk}
                      type="button"
                      onClick={() => setDelegationTask(tk)}
                      className={`rounded-xl border px-3 py-2.5 text-sm font-semibold transition ${active ? 'border-indigo-500 bg-indigo-50 text-indigo-800 ring-1 ring-indigo-500' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'}`}
                    >
                      {t(`workflow.delegation.${tk}`)}
                    </button>
                  );
                })}
              </div>
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
                      <FollowUpBubble key={f.at ? `${f.at}-${i}` : i} note={f} fresh={!!savedAt && f.at === savedAt} t={t} />
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
