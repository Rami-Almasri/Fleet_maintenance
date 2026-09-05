// "My Queue" — the role-scoped maintenance dashboard, rebuilt on the Cockpit+ (.opx) command-center
// design language (theme-aware: light "Platinum" / dark "Cockpit"). Each role opens a focused view of
// only the work that is theirs to act on, fed by GET /maintenance-tickets/my-queue:
//
//   Abu Maroof (Inspector, maintenance.initiate):
//     · Pending Inspections   — Driver requests + in-progress diagnostics (Start test drive / Submit report)
//     · On-Site Service       — minor jobs done where the car is parked (Mark as Serviced)
//     · Final Re-inspections  — cars back from the garage, awaiting sign-off (Re-inspect)
//
//   Supervisor (maintenance.delegate): Awaiting Dispatch / Review the Video / Came Back Broken / Final Re-inspections
//   Driver (Logistics, maintenance.logistics): Active Trips / Cars Waiting for Follow-up / Return to Base / Back from Garage
//
// A user holding several roles sees a tab per role. Actions reuse the shared modal. Every visible
// string comes from the central i18n catalog via t() so the page stays fully bilingual + RTL.
//
// The surface — seat band, headline numbers, view bar, cards and per-ticket timeline — is composed
// ENTIRELY from real ticket data: fault_severity for the priority rail, `handoffs` for the timeline,
// workflow_status for the progress bar, `position` for the live location, and the section `counts`
// for the numbers. No fabricated telemetry.
//
// The right rail carries the seat's queue load and, in place of the old "Live Activity" feed, the
// NOTIFICATIONS RAISED FOR THIS ROLE (see components/workflow/QueueNotifications). Activity replayed
// the handoff stamps of the very cards listed beside it — it reported work you were already looking
// at. A notification is the opposite: the thing addressed to your role that nobody has picked up.
//
// The view bar (search · sort) NARROWS what is drawn and never what is counted: the headline numbers
// always describe the whole seat, and whenever the search hides a card the page says so.

import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { usePermissions } from '../hooks/usePermissions';
import { useAuth } from '../auth/AuthContext';
import { useI18n } from '../i18n/I18nContext';
import { useToast } from '../components/ui/Toast';
import Icon from '../components/ui/Icon';
import Modal from '../components/ui/Modal';
import Button from '../components/ui/Button';
import { Input } from '../components/ui/Field';
import {
  CommandPanel, JourneyMap, OpsClock, severityTone,
} from '../components/ops';
import TicketActionModal from '../components/workflow/TicketActionModal';
// The SAME full ticket the pipeline board opens — findings, suggested checks, parts, money, history
// and every action. A card on this page is the same ticket as a card on /maintenance-workflow, so it
// must open the same thing; anything less makes this page a list of stubs you have to leave to read.
import TicketDetailDrawer from '../components/workflow/TicketDetailDrawer';
// The rail that replaced "Live Activity": the alerts raised FOR the seat you are on, in the Action
// Center's own role lanes. Activity replayed what had already happened to cards you were looking at;
// this is the work nobody has picked up yet.
import QueueNotifications from '../components/workflow/QueueNotifications';
import TaskRoutingModal from '../components/workflow/TaskRoutingModal';
import SendCarInModal from '../components/workflow/SendCarInModal';
import BreakdownIntakeModal from '../components/workflow/BreakdownIntakeModal';
import ComplaintTriageModal from '../components/workflow/ComplaintTriageModal';
import { resolveAction, stageAge, stageSeconds, fmtDuration, ago, custodyBlocked, custodyHolderName, assignmentBlocked, assignedDriverName, ORIGIN_LABEL } from '../components/workflow/meta';
// The oil change is recorded identically wherever it is recorded from — one dialog, one write path.
import { OilChangeDialog } from './reminders/OilProjection';
import './MyMaintenanceQueue.css';

// Reason → chip class. The visible label comes from workflow.reasonShort.<value>.
const REASON_CHIP = { test_drive: 'reserved', customer_reported: 'paused', periodic: 'rented', driver_reported: 'paused' };

// The role sections, in display order, tagged with the role that owns them. A `readonly` section is
// for tracking only — its cards show a status, never an action button. Titles/hints come from
// queue.section.<key> in the catalog. `tone` drives the panel dot + card severity fallback.
const SECTIONS = [
  { key: 'pending_inspections',        role: 'inspector',  tone: '#8b5cf6' },
  { key: 'on_site',                    role: 'inspector',  tone: '#14b8a6' },
  { key: 'final_reinspections',        role: 'inspector',  tone: '#10b981' },
  // Scoped by NAME, not by role — the legs this person was personally handed. It appears on both the
  // dispatcher and the driver tab because a supervisor can now be assigned a collection himself, and he
  // reads the dispatcher tab; without it his own job would be lost among every driver's work below.
  // (Duplicating one key across two tabs is the existing pattern — see final_reinspections.)
  { key: 'assigned_to_me',             role: 'dispatcher', tone: '#2563eb', supplementary: true },
  { key: 'awaiting_dispatch_decision', role: 'dispatcher', tone: '#a855f7' },
  // The step AFTER the supervisor's call: garage picked, driver named (or left to the pool), the car
  // still standing with us. It used to be the driver's lane alone, so the person who made the
  // assignment could not see that nobody had acted on it — the one lane where a car sits still
  // *because* a decision has already been taken. He can take the pickup himself or hand it to
  // someone else (maySupersedeDriver), so it carries its real action, not a read-only status.
  { key: 'awaiting_pickup',            role: 'dispatcher', tone: '#f59e0b' },
  // Temporary Vehicle Release — a car being taken out of the workshop (or brought back) needs the same
  // two decisions a garage run does: where does it go, and who drives it. Its own section because the
  // repair behind it is frozen: nothing on that ticket can move until the car does.
  { key: 'release_dispatch',           role: 'dispatcher', tone: '#f59e0b' },
  { key: 'released_parked',            role: 'dispatcher', tone: '#f97316' },
  { key: 'reinspection_failed',        role: 'dispatcher', tone: '#dc2626' },
  { key: 'final_reinspections',        role: 'dispatcher', tone: '#10b981' },
  { key: 'assigned_to_me',             role: 'driver',     tone: '#2563eb', supplementary: true },
  { key: 'active_dispatches',          role: 'driver',     tone: '#3b82f6' },
  // The release legs open to this driver — collect a released car and take it where it's going, or
  // collect it from there and bring it home.
  { key: 'release_moves',              role: 'driver',     tone: '#f59e0b' },
  { key: 'waiting_followup',           role: 'driver',     tone: '#f97316' },
  { key: 'return_to_base',             role: 'driver',     tone: '#0ea5e9' },
  { key: 'back_from_garage',           role: 'driver',     tone: '#10b981', readonly: true },
];

// The role views, each shown as its own tab. Only the tabs the user's roles unlock are rendered.
// `icon` = the Icon component; `rail` colors the selected role tile.
const ROLE_TABS = [
  { key: 'inspector',  icon: Icon.Search, rail: '#a78bfa' },
  { key: 'dispatcher', icon: Icon.Route,  rail: '#8b5cf6' },
  { key: 'driver',     icon: Icon.Truck,  rail: '#60a5fa' },
];

// workflow_status → how far along the repair pipeline (%). Drives the card's progress bar — a real,
// data-backed indicator of ticket lifecycle position (replacing the mockup's invented health meters).
const STAGE_PCT = {
  complaint_triage: 8, inspection_requested: 12, inspection_diagnostic: 22,
  // Diagnosed but parked. Sits just past the diagnostic because that work IS done — what remains is a
  // wait, and the bar should not imply the repair has started.
  maintenance_deferred: 26,
  inspection_pending: 32, on_site_pending: 40, awaiting_dispatch: 44,
  in_transit: 56, under_repair: 68, repair_review: 78, ready_for_pickup: 90,
  ready_for_reinspection: 84, reinspection_failed: 52, paused_returned_to_service: 46,
};

// The ordered handoff stamps that make up a ticket's real audit trail (the WhatsApp replacement). Each
// present stamp becomes a completed timeline node; the current stage caps it with a pulsing "now" node.
const HANDOFF_STEPS = [
  'requested', 'reviewed', 'inspected', 'dispatched', 'repair_started', 'ready', 'picked_up_from_garage', 'park_arrived', 'closed',
];

const allows = (can, perm) => (Array.isArray(perm) ? perm.some(can) : can(perm));

// Severity tone for a ticket: prefer the resource's explicit fault_severity_tone (red/orange/amber/
// green), else derive from the grade. Maps to the .opx severity vocabulary (crit/paused/ok).
function sevOf(tk) {
  const tone = tk.fault_severity_tone;
  if (tone === 'red') return 'crit';
  if (tone === 'orange' || tone === 'amber') return 'paused';
  if (tone === 'green') return 'ok';
  return severityTone(tk.fault_severity || tk.severity);
}
const SEV_COLOR = { crit: '#fb7185', paused: '#f5a524', ok: '#34d399', info: '#60a5fa' };
// Priority order when the board is sorted by urgency: red first, then amber, then everything else.
const SEV_RANK = { crit: 0, paused: 1, info: 2, ok: 3 };

// Everything a card is worth searching by — the plate you were told on the phone, the car, the
// fault, the garage it sits in, the complaint someone typed. One haystack, matched case-insensitively.
const haystack = (tk) => [
  tk.plate, tk.car, tk.status_label, tk.fault_severity_label, tk.customer_complaint,
  tk.garage, tk.position?.label, `#${tk.id}`,
].filter(Boolean).join(' ').toLowerCase();

/**
 * A headline number on the strip. Clickable tiles double as the view's filter — the professional
 * shortcut on a busy queue is "show me only the four that are waiting on me", and the number that
 * says there are four is the natural place to press.
 */
function QMetric({ label, value, foot, tone = '', icon: IconCmp, pct = null, active = false, onClick, title }) {
  const clickable = !!onClick;
  return (
    <div
      className={`qm ${tone} ${clickable ? 'click' : ''} ${active ? 'on' : ''}`}
      title={title}
      role={clickable ? 'button' : undefined}
      tabIndex={clickable ? 0 : undefined}
      onClick={onClick}
      onKeyDown={clickable ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onClick(); } } : undefined}
    >
      <div className="qm-hd">
        {IconCmp ? <span className="qm-ic"><IconCmp className="h-4 w-4" /></span> : null}
        <span className="qm-lbl">{label}</span>
      </div>
      <div className="qm-v tnum">{value}</div>
      {pct != null && (
        <div className="qm-bar"><i style={{ width: `${Math.max(2, Math.min(100, pct))}%` }} /></div>
      )}
      {foot ? <div className="qm-foot">{foot}</div> : null}
    </div>
  );
}

// How long the card has sat in its current stage — replaces the (misleading) creation date. Reddens
// once a stage overstays its SLA (e.g. Pending Dispatch > 4h) so the owner sees they're slacking.
function TimeInStage({ tk, t }) {
  const age = stageAge(tk, t);
  if (!age) return <span className="now" />;
  return (
    <span className="now" style={age.over ? { color: 'var(--crit)' } : undefined} title={t('queue.timeInStage')}>
      {age.label}
    </span>
  );
}

/**
 * CARS TO COLLECT FROM CUSTOMERS — the driver's own heading for a job that is not like the others.
 *
 * Every other card on this page is a car we already hold. These are cars still sitting at a paying
 * customer's address, waiting for someone to go and fetch them — raised by the oil follow-up once
 * Sales have agreed the return. The driver should never have to open the dispatch board to find
 * them, so they are shown here, where he already is, with the only two moves that exist:
 *
 *   Claim it            — the pooled job becomes his
 *   Car received        — he has the keys; the odometer at the doorstep is captured with it
 *
 * That reading is the whole reason for the trip: the oil follow-up has been chasing it by phone for
 * days, and the doorstep is the last moment it can be captured as a fact rather than a guess. It is
 * therefore mandatory, with its photo, exactly as on any other maintenance-linked leg.
 */
/**
 * Where a collection has actually got to, and therefore the ONE thing to do next.
 *
 * Read from the move's own status plus the oil follow-up behind it — never from a flag of its own,
 * so the card can't claim the car is "still with the customer" while the task says it was picked up
 * an hour ago. That contradiction is exactly what a derived stage prevents.
 */
function collectionStage(task, userId) {
  const st = task.status;
  const oil = task.oil_followup || null;

  // The oil is done but the customer has not got their car back — the last step, and the one the
  // 5-minute chase rings about. It outranks everything else: nothing else is owed.
  if (oil?.owes_return) return 'give_back';
  if (oil?.oil_changed) return 'done';
  if (!task.assigned_to_id) return 'to_claim';
  if (task.assigned_to_id !== userId) return 'someone_else';
  // Legacy phases mean the same thing as picked_up: the car is moving, with him.
  if (['picked_up', 'in_transit', 'to_destination'].includes(st)) return 'with_driver';
  if (['delivered', 'at_destination', 'returned', 'completed'].includes(st) || !task.is_open) return 'arrived';
  return 'to_collect';   // dispatched / en_route — claimed, car still at the customer's
}

function CollectionsPanel({ tf, userId, onClaim, onReceive, onArrived, onOilChange, onReturned, tasks: allTasks, loading, busyId, query = '' }) {
  // The page-wide search narrows this panel too — a plate you were given on the phone should find
  // the car whether it is a ticket or a collection.
  const q = query.trim().toLowerCase();
  const tasks = q
    ? allTasks.filter((task) => [task.plate, task.car, task.destination, task.status_label, task.notes]
      .filter(Boolean).join(' ').toLowerCase().includes(q))
    : allTasks;

  return (
    <CommandPanel
      title={tf('queue.section.collections.title', 'Cars to collect from customers')}
      dotColor="#f43f5e"
      meta={q && tasks.length !== allTasks.length ? `${tasks.length}/${allTasks.length}` : (tasks.length || null)}
    >
      <p className="qsec-hint">
        {tf('queue.section.collections.hint',
          'The customer has agreed to give the car back. Go to them, take the keys, and read the odometer before you drive off.')}
      </p>
      <div className="qgrid">
        {loading ? (
          <div className="opx-skel" style={{ height: 128 }} />
        ) : tasks.length === 0 ? (
          <p className="opx-empty" style={{ gridColumn: '1 / -1', padding: '26px 10px' }}>
            {tf('queue.section.collections.none', 'No cars to collect right now.')}
          </p>
        ) : tasks.map((task) => {
          const busy = busyId === task.id;
          const stage = collectionStage(task, userId);
          const parking = task.oil_followup?.service_location === 'parking';
          // WHERE the car is, in the driver's own words — derived from the same stage that picks the
          // button, so the card can never say "still with the customer" under a "Picked up" chip.
          const whereIsIt = {
            to_claim:     tf('queue.collections.atCustomer', 'still with the customer'),
            to_collect:   tf('queue.collections.atCustomer', 'still with the customer'),
            someone_else: tf('queue.collections.atCustomer', 'still with the customer'),
            with_driver:  tf('queue.collections.withYou', 'with you — drive it in'),
            arrived:      parking
              ? tf('queue.collections.atParking', 'at the parking — oil change still owed')
              : tf('queue.collections.atWorkshop', 'at the workshop — oil change still owed'),
            give_back:    tf('queue.collections.giveBack', 'oil done — the customer is waiting for it'),
          }[stage] || '';

          return (
            <div key={task.id} className={`opx-card qc ${stage === 'give_back' ? 'sev-ok' : stage === 'arrived' ? 'sev-paused' : 'sev-crit'}`}>
              <span className="sev" />
              <div className="top">
                <Link to={`/vehicles/${task.vehicle_id}`} className="opx-plate">
                  {task.plate || `#${task.vehicle_id}`}
                </Link>
                <div className="model">
                  {task.car || '—'}
                  <span>{whereIsIt}</span>
                </div>
              </div>

              <div className="qc-chips">
                <span className="opx-chip crit"><span className="cd" />{task.status_label}</span>
                <span className="opx-chip paused">
                  <span className="cd" />
                  {tf('queue.collections.takeTo', 'Take it to {where}', { where: task.destination })}
                </span>
              </div>

              {/* The dispatcher's brief — who to collect from, and what the car owes on arrival.
                  Shown in full: it is the driver's only instruction. */}
              {task.notes && <p className="qc-quote" title={task.notes}>“{task.notes}”</p>}

              {(task.last_odometer?.km ?? task.previous_odometer) != null && (
                <div className="qc-pos">
                  <Icon.Gauge className="h-3.5 w-3.5" />
                  <span>
                    {tf('queue.collections.lastKnown', 'Last known odometer')}:{' '}
                    {(task.last_odometer?.km ?? task.previous_odometer).toLocaleString()} km
                    {task.last_odometer?.on ? ` · ${task.last_odometer.on}` : ''}
                  </span>
                </div>
              )}

              {/* ONE button, and it is whichever step this car is actually at. The job walks
                  Claim → Received → Arrived → Oil changed, and the card walks with it. */}
              <div className="qc-foot">
                {stage === 'to_claim' && (
                  <button type="button" className="opx-btn primary" disabled={busy} onClick={() => onClaim(task)}>
                    {tf('queue.collections.claim', 'Claim it')}
                  </button>
                )}
                {stage === 'to_collect' && (
                  <button type="button" className="opx-btn primary" disabled={busy} onClick={() => onReceive(task)}>
                    {tf('queue.collections.received', 'Car received from customer')}
                  </button>
                )}
                {stage === 'with_driver' && (
                  <button type="button" className="opx-btn primary" disabled={busy} onClick={() => onArrived(task)}>
                    {parking
                      ? tf('queue.collections.arrivedParking', 'Arrived at the parking')
                      : tf('queue.collections.arrivedWorkshop', 'Arrived at the workshop')}
                  </button>
                )}
                {stage === 'arrived' && (
                  task.oil_followup ? (
                    <button type="button" className="opx-btn primary" disabled={busy} onClick={() => onOilChange(task)}>
                      {tf('queue.collections.oilChanged', 'Oil changed — record it')}
                    </button>
                  ) : (
                    <span className="qc-locked">{tf('queue.collections.handedOver', 'Handed over — nothing left for you')}</span>
                  )
                )}
                {/* The last step. Green, because nothing is wrong — but it is still an action, and
                    the reminder keeps ringing every 5 minutes until it is pressed. */}
                {stage === 'give_back' && (
                  <button type="button" className="opx-btn primary" disabled={busy} onClick={() => onReturned(task)}>
                    {tf('queue.collections.returned', 'Returned to the customer')}
                  </button>
                )}
                {stage === 'someone_else' && (
                  <span className="qc-locked">
                    {tf('queue.collections.taken', '{name} is collecting this one', { name: task.assigned_to_name })}
                  </span>
                )}
              </div>
            </div>
          );
        })}
      </div>
    </CommandPanel>
  );
}

/**
 * "Car received from customer" — the moment custody passes, captured with the reading that proves it.
 *
 * The odometer and its photo are REQUIRED here and nowhere else in this flow can they be: the oil
 * follow-up has spent days asking the customer for this number over the phone, and the driver is
 * standing in front of the dashboard. Once he drives off, it is history.
 */
function CollectionReceivedModal({ task, action = 'pickup', tf, onClose, onDone, onError }) {
  const arriving = action === 'deliver';
  const parking  = task.oil_followup?.service_location === 'parking';
  // Arriving is the POST-trip capture: the driver has just driven the car in, so the reading is a
  // few km on from the doorstep one. Pre-filling it makes the honest case one tap instead of a
  // second trip to the dashboard — he still has to correct it if it doesn't match.
  const [odometer, setOdometer] = useState(arriving && task.last_odometer?.km ? String(task.last_odometer.km) : '');
  const [photo, setPhoto] = useState(null);
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState('');

  // The freshest number we hold, with its provenance — a customer reading from last Tuesday beats
  // the car's stored odometer, which was last refreshed when it left the branch.
  const last = task.last_odometer || null;
  const previous = last?.km ?? task.previous_odometer ?? null;
  const entered = odometer === '' ? null : Number(odometer);
  // Backwards mileage is a data-entry problem, not a fact — say so before it is sent.
  const backwards = entered != null && previous != null && entered < previous;
  const sourceLabel = {
    customer: tf('queue.collections.srcCustomer', 'reported by the customer'),
    staff:    tf('queue.collections.srcStaff', 'recorded by us'),
    handover: tf('queue.collections.srcHandover', 'from the handover — not refreshed since'),
  }[last?.source] || null;

  const submit = async () => {
    if (!entered || Number.isNaN(entered)) {
      return onError(tf('queue.collections.needOdo', 'Enter the odometer reading from the dashboard'));
    }
    if (!photo) {
      return onError(tf('queue.collections.needPhoto', 'Take a photo of the odometer'));
    }
    // A reading BELOW what we already hold is not refused — the dashboard in front of the driver
    // outranks a number somebody read out over the phone — but it must not pass silently either.
    // The explanation rides onto the permanent audit trail beside the reading.
    if (backwards && note.trim().length < 3) {
      return onError(tf('queue.collections.needNote', 'Say why the reading is lower than what we hold'));
    }
    setBusy(true);
    try {
      const fd = new FormData();
      fd.append('odometer', entered);
      fd.append('odometer_photo', photo);
      if (note.trim()) fd.append('odometer_note', note.trim());
      // Same capture, two ends of the same trip: `pickup` at the customer's door, `deliver` on
      // arrival. Both are mandatory on a collection, which is why this dialog serves both — a
      // button that posts nothing just earns "The odometer field is required".
      await api.post(`/logistics/${task.id}/${arriving ? 'deliver' : 'pickup'}`, fd);
      onDone(arriving
        ? tf('queue.collections.arrivedOk', 'Arrived — now record the oil change')
        : tf('queue.collections.receivedOk', 'Car received — it is with you now'));
    } catch (e) {
      onError(e.response?.data?.message || tf('queue.collections.receiveFailed', 'Could not record that'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open
      onClose={() => !busy && onClose()}
      size="sm"
      title={arriving
        ? (parking
          ? tf('queue.collections.arrivedParking', 'Arrived at the parking')
          : tf('queue.collections.arrivedWorkshop', 'Arrived at the workshop'))
        : tf('queue.collections.receiveTitle', 'Car received from customer')}
      subtitle={`${task.plate || ''} — ${task.destination || ''}`}
      footer={(
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose} disabled={busy}>
            {tf('common.cancel', 'Cancel')}
          </Button>
          <Button onClick={submit} loading={busy}>
            {arriving
              ? tf('queue.collections.confirmArrived', 'It is here')
              : tf('queue.collections.confirmReceived', 'I have the car')}
          </Button>
        </div>
      )}
    >
      <div className="space-y-3">
        <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-inset ring-amber-200">
          {arriving
            ? tf('queue.collections.arrivedWhy',
              'Read the dashboard now you have parked it. This closes the trip and is the last reading before the oil change.')
            : tf('queue.collections.readingWhy',
              'Read the dashboard before you drive off. This number is the reason the car is being collected.')}
        </p>
        {/* THE NUMBER TO COMPARE AGAINST, stated before the empty box — a reading typed against
            nothing is a reading nobody can sanity-check. Source and date travel with it. */}
        {previous != null && (
          <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
            <div className="text-xs font-semibold text-slate-500">
              {tf('queue.collections.lastKnown', 'Last known odometer')}
            </div>
            <div className="text-lg font-bold tabular-nums text-slate-900">
              {previous.toLocaleString()} km
            </div>
            <div className="text-xs text-slate-500">
              {sourceLabel}
              {last?.on ? ` · ${last.on}` : ''}
              {last?.by ? ` · ${last.by}` : ''}
            </div>
          </div>
        )}
        <Input
          label={tf('queue.collections.odoLabel', 'Odometer now (km)')}
          type="number"
          required
          value={odometer}
          onChange={(e) => setOdometer(e.target.value)}
          placeholder={previous != null ? String(previous) : '20000'}
        />
        {previous != null && entered != null && (
          <p className={`text-xs ${backwards ? 'font-semibold text-rose-600' : 'text-slate-600'}`}>
            {backwards
              ? tf('queue.collections.backwards', 'That is below the last known reading ({km} km) — check the number.', { km: previous.toLocaleString() })
              : tf('queue.collections.driven', '{km} km driven since that reading.', { km: (entered - previous).toLocaleString() })}
          </p>
        )}
        {/* Not a block — the dash in front of you outranks a number given over the phone. But it is
            written down, next to the reading, forever. */}
        {backwards && (
          <Input
            label={tf('queue.collections.noteLabel', 'Why is it lower?')}
            value={note}
            onChange={(e) => setNote(e.target.value)}
            placeholder={tf('queue.collections.notePlaceholder', 'e.g. the customer read it wrong on the phone')}
          />
        )}
        <label className="block text-sm text-slate-700">
          <span className="mb-1 block font-medium">
            {tf('queue.collections.photoLabel', 'Photo of the odometer')}
          </span>
          <input
            type="file"
            accept="image/*"
            capture="environment"
            onChange={(e) => setPhoto(e.target.files?.[0] || null)}
            className="block w-full text-sm"
          />
        </label>
      </div>
    </Modal>
  );
}

// The primary CTA(s) for a ticket, honoring permissions + custody. Uses .opx buttons so the controls
// stay in the command-center theme; variant maps to the ops button palette.
function CardActions({ tk, can, userId, onAct, readonly, t }) {
  const act = resolveAction(tk);
  const allowed = act && allows(can, act.perm);
  const canFollowUp = ['in_transit', 'under_repair'].includes(tk.workflow_status) && can('maintenance.logistics');

  // Custody gate — a return/arrival leg may only be completed by the SAME driver who took the car
  // (garage check-in) / collected it from the garage (arrival at our park). The backend bounces anyone
  // else with a 422, so we hide the button and name the custodian instead of letting them tap and fail.
  const custodyLocked = allowed && custodyBlocked(tk, userId, can);
  const custodyHolder = custodyHolderName(tk, userId, can);
  const custodyHint = act?.action === 'arriveAtPark'
    ? t('queue.custody.arrive', { name: custodyHolder || t('queue.custody.theCollector') })
    : t('queue.custody.checkin', { name: custodyHolder || t('queue.custody.theDriver') });

  // Assigned pickup — the Supervisor named a driver, so the card is a status for everyone else. The
  // named driver keeps the Pick up button; the rest are told whose job it is instead of racing for it.
  const assignedElsewhere = allowed && assignmentBlocked(tk, userId, can);
  const assignedTo = assignedDriverName(tk, userId, can);

  if (readonly) {
    return (
      <span className="opx-chip avail"><span className="cd" /><Icon.Check className="h-3 w-3" /> {t('queue.awaitingInspector')}</span>
    );
  }
  return (
    <div className="qc-actions">
      {canFollowUp && (
        <button type="button" className="opx-btn" onClick={() => onAct('followup', tk)}>
          {t('workflow.cardAction.followup')}
        </button>
      )}
      {assignedElsewhere ? (
        <span className="opx-chip paused" title={t('queue.assignedToHint')}>
          <span className="cd" /><Icon.Truck className="h-3 w-3" />
          {t('queue.assignedTo', { name: assignedTo || t('queue.custody.theDriver') })}
        </span>
      ) : custodyLocked ? (
        <span className="opx-hint">{custodyHint}</span>
      ) : allowed ? (
        <button
          type="button"
          className={`opx-btn ${act.variant === 'danger' ? 'danger' : 'primary'}`}
          style={act.variant === 'success' ? { background: 'linear-gradient(135deg,#059669,#10b981)', color: '#04140c', border: 'none' } : undefined}
          onClick={() => onAct(act.action, tk)}
        >
          {t(`workflow.cardAction.${act.action}`)} <Icon.ArrowRight className="h-3.5 w-3.5" />
        </button>
      ) : act ? (
        <span className="opx-hint">{t(`workflow.cardAction.${act.action}`)} · {t('queue.noAccess')}</span>
      ) : null}
    </div>
  );
}

function QueueCard({ tk, can, userId, onAct, onSelect, active = false, readonly = false }) {
  const { t } = useI18n();
  const [open, setOpen] = useState(false);
  const sev = sevOf(tk);
  const sevClass = `sev-${sev === 'ok' ? 'ok' : sev === 'crit' ? 'crit' : sev === 'paused' ? 'paused' : 'info'}`;
  const reasonChip = REASON_CHIP[tk.trigger_reason];
  const reasonLabel = reasonChip ? t(`workflow.reasonShort.${tk.trigger_reason}`) : tk.trigger_reason;
  const pct = STAGE_PCT[tk.workflow_status] ?? 50;
  const pos = tk.position || {};
  // A stage that has overstayed its SLA is the one fact on the card that is nobody's fault but the
  // holder's — it gets a ribbon, not just a red number nobody reads.
  const age = stageAge(tk, t);

  // Real timeline nodes from the ticket's handoff audit trail, capped with a pulsing "now" node.
  const nodes = useMemo(() => {
    const hs = tk.handoffs || {};
    const done = HANDOFF_STEPS
      .filter((k) => hs[k] && (hs[k].at || hs[k].name))
      .map((k) => ({
        kind: 'done',
        title: t(`queue.step.${k}`),
        time: ago(hs[k].at, t),
        meta: [hs[k].name, hs[k].garage || hs[k].destination, hs[k].odometer != null ? `${Number(hs[k].odometer).toLocaleString()} km` : null]
          .filter(Boolean).map((x) => `<b>${x}</b>`).join(' · ') || null,
      }));
    if (!readonly) done.push({ kind: 'now', title: tk.status_label || t('queue.nowStage'), time: t('queue.nowStage') });
    return done;
  }, [tk, readonly, t]);

  return (
    // The whole card is the door to the ticket. The plate link, the action buttons and the timeline
    // toggle each stop the click so their own job still wins over opening the drawer.
    <div
      className={`opx-card qc ${sevClass} ${active ? 'qc-open' : ''} ${age?.over ? 'qc-late' : ''}`}
      role="button"
      tabIndex={0}
      title={t('queue.openTicket')}
      onClick={() => onSelect?.(tk)}
      onKeyDown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onSelect?.(tk); }
      }}
    >
      <span className="sev" />
      <div className="top">
        <Link to={`/vehicles/${tk.vehicle_id}`} className="opx-plate" onClick={(e) => e.stopPropagation()}>
          {tk.plate || `#${tk.id}`}
        </Link>
        <div className="model">{tk.car || t('common.none')}<span>{tk.fault_severity_label || tk.status_label}</span></div>
        <TimeInStage tk={tk} t={t} />
      </div>

      <div className="qc-chips">
        {tk.fault_severity_tone && (
          <span className={`opx-chip ${sev === 'ok' ? 'avail' : sev === 'crit' ? 'crit' : 'paused'}`}>
            <span className="cd" />{tk.fault_severity_emoji ? `${tk.fault_severity_emoji} ` : ''}{tk.fault_severity_label}
          </span>
        )}
        {reasonChip && <span className={`opx-chip ${reasonChip}`}><span className="cd" />{reasonLabel}</span>}
        {tk.is_recovery && <span className="opx-chip crit"><span className="cd" />{t('workflow.reasonShort.recovery') || 'Recovery'}</span>}
        {tk.temporarily_released && <span className="opx-chip paused"><span className="cd" />⤴</span>}
        {age?.over && (
          <span className="opx-chip crit" title={t('queue.timeInStage')}>
            <span className="cd" />{t('queue.late', { age: age.label })}
          </span>
        )}
      </div>

      {/* The reported note, attributed to its source — an escalated Driver Observation must not read
          as an anonymous quote on the inspector's own queue. */}
      {tk.customer_complaint && (
        <p className="qc-quote" title={tk.customer_complaint}>
          {(tk.request_origin_label || ORIGIN_LABEL[tk.request_origin]) && (
            <span className="qc-quote-src">{tk.request_origin_label || ORIGIN_LABEL[tk.request_origin]}: </span>
          )}
          “{tk.customer_complaint}”
        </p>
      )}

      {(pos.label || tk.status_label) && (
        <div className="qc-pos">
          <Icon.Gauge className="h-3.5 w-3.5" />
          <span>{pos.label || tk.status_label}{tk.garage ? ` · ${tk.garage}` : ''}</span>
        </div>
      )}

      <div className="qc-prog" title={t('queue.stageProgress')}>
        <div className="opx-prog"><i style={{ width: `${pct}%`, background: SEV_COLOR[sev] || SEV_COLOR.info }} /></div>
      </div>

      <div className="qc-foot" onClick={(e) => e.stopPropagation()}>
        <CardActions tk={tk} can={can} userId={userId} onAct={onAct} readonly={readonly} t={t} />
        {nodes.length > 0 && (
          <button type="button" className="qc-tl-toggle" onClick={(e) => { e.stopPropagation(); setOpen((v) => !v); }} aria-expanded={open}>
            <Icon.Clock className="h-3.5 w-3.5" /> {open ? t('queue.hideTimeline') : t('queue.showTimeline')}
          </button>
        )}
      </div>

      {open && nodes.length > 0 && (
        <div className="qc-timeline">
          <div className="qc-tl-h">{t('queue.timeline')}</div>
          <JourneyMap nodes={nodes} />
        </div>
      )}
    </div>
  );
}

export default function MyMaintenanceQueue() {
  const { t, tf } = useI18n();
  const toast = useToast();
  const { can } = usePermissions();
  const { user } = useAuth();

  const [modal, setModal] = useState(null);
  // The open ticket. Seeded from the card's own summary so the drawer paints instantly, then it
  // lazy-loads the full record itself. `detailReload` re-pulls it after any action lands.
  const [detail, setDetail] = useState(null);
  const [detailReload, setDetailReload] = useState(0);
  const [vehicles, setVehicles] = useState([]);
  const [garages, setGarages] = useState([]);
  const [findingsCatalog, setFindingsCatalog] = useState([]);
  const [maintTypes, setMaintTypes] = useState([]);
  const [keywordMeta, setKeywordMeta] = useState({});
  const [faultCausesCatalog, setFaultCausesCatalog] = useState({});
  // WHERE ON THE CAR — the shared location vocabulary + the per-fault-type policy that says which
  // findings take a place at all. Ships inside the findings catalog (one request), so the detail
  // editor can render the instant a fault chip is tapped. See lib/faultLocations.
  const [locationCatalog, setLocationCatalog] = useState({ groups: [], policy: {}, maxQuantity: 40 });
  const [drivers, setDrivers] = useState([]);
  const [activeTab, setActiveTab] = useState('');

  // ── The view controls ─────────────────────────────────────────────────────────────────────
  // A queue with forty cars on it is a list you scroll, not a queue you work. Search narrows WHAT IS
  // SHOWN and sort changes the ORDER; neither changes what is counted, so the headline numbers keep
  // telling the truth about the whole seat while the board underneath shows the slice you asked for.
  const [query, setQuery] = useState('');
  const [sortBy, setSortBy] = useState('priority'); // priority | waiting | plate

  // Role-scoped queue: silent background revalidation every 8s (no skeleton flash, tab & scroll
  // preserved), paused while a modal is open so an in-flight action never shifts under us.
  const fetcher = useCallback(async () => (await api.get('/maintenance-tickets/my-queue')).data.data, []);
  const { data, loading, error, reload } = useFetch(fetcher, [], {
    refreshInterval: 8000,
    // Also paused while the drawer is open — an 8s revalidation must not re-render the ticket you
    // are reading out from under you.
    paused: () => !!modal || !!detail,
  });

  const canDelegate = can('maintenance.delegate');

  useEffect(() => {
    let alive = true;
    Promise.all([api.get('/Vehicle'), api.get('/Vendor'), api.get('/maintenance-tickets/findings-catalog')])
      .then(([v, g, f]) => {
        if (!alive) return;
        const vlist = v.data?.data;
        const allVehicles = Array.isArray(vlist) ? vlist : vlist?.items || [];
        setVehicles(allVehicles.filter((veh) => ['ready', 'rented'].includes(veh.status)));
        const glist = g.data?.data;
        const all = Array.isArray(glist) ? glist : glist?.items || [];
        const shops = all.filter((x) => x.type === 'garage');
        setGarages(shops.length ? shops : all);
        setFindingsCatalog(f.data?.data?.categories || []);
        setKeywordMeta(f.data?.data?.keyword_risk || {});
        setFaultCausesCatalog(f.data?.data?.fault_causes || {});
        setLocationCatalog({
          groups: f.data?.data?.locations || [],
          policy: f.data?.data?.location_policy || {},
          maxQuantity: f.data?.data?.max_quantity || 40,
        });
        setMaintTypes((f.data?.data?.maintenance_types || []).map((x) => x.value));
      })
      .catch(() => { /* pickers stay empty */ });
    return () => { alive = false; };
  }, []);

  // The assign-dispatch picker's driver list — only a dispatcher (maintenance.delegate) may load it.
  useEffect(() => {
    if (!canDelegate) return undefined;
    let alive = true;
    api.get('/maintenance-tickets/assignable-drivers')
      .then((r) => { if (alive) setDrivers(Array.isArray(r.data?.data) ? r.data.data : []); })
      .catch(() => { /* leave the picker empty on failure */ });
    return () => { alive = false; };
  }, [canDelegate]);

  // ── Cars to collect from customers ────────────────────────────────────────────────────────
  // A collection lives in the logistics lane (it is a movement), but the driver works from THIS
  // page, so it is fetched here and shown as its own panel. Pool + my own, deduped: an unclaimed
  // job and one I have already claimed are the same card at two stages of the same job.
  const [collections, setCollections] = useState([]);
  const [collLoading, setCollLoading] = useState(true);
  const [collBusy, setCollBusy] = useState(null);
  const [receiving, setReceiving] = useState(null);
  const [oilChanging, setOilChanging] = useState(null);

  const loadCollections = useCallback(async () => {
    try {
      // One endpoint, because "what can I still do about this collection" is a server question: it
      // includes a trip already delivered whose oil change nobody has recorded yet.
      const res = await api.get('/logistics/my-collections');
      setCollections(res.data?.data?.tasks || []);
    } catch {
      setCollections([]);          // a failed side-panel must never take the queue down with it
    } finally {
      setCollLoading(false);
    }
  }, []);

  useEffect(() => { loadCollections(); }, [loadCollections]);

  const claimCollection = async (task) => {
    setCollBusy(task.id);
    try {
      await api.post(`/logistics/${task.id}/claim`);
      toast.success(tf('queue.collections.claimed', 'Yours — go and collect it'));
      await loadCollections();
    } catch (e) {
      toast.error(e.response?.data?.message || tf('queue.collections.claimFailed', 'Could not claim that job'));
    } finally {
      setCollBusy(null);
    }
  };

  // "I'm here" — the post-trip capture. It goes through the SAME dialog as the pick-up, because a
  // collection demands the odometer at both ends and a button that posts nothing just earns a
  // validation error. (It did exactly that until a real click found it.)
  const arriveCollection = (task) => setReceiving({ task, action: 'deliver' });

  // The last step, and the one the 5-minute chase exists for: the oil is done, the car is ours, and
  // the customer is still paying for it. Pressing this is what stops the reminder.
  const returnToCustomer = async (task) => {
    setCollBusy(task.id);
    try {
      await api.post(`/Contract/${task.oil_followup?.contract_id}/oil-returned`, {});
      toast.success(tf('queue.collections.returnedOk', 'Handed back — the reminder stops now'));
      await loadCollections();
    } catch (e) {
      toast.error(e.response?.data?.message || tf('queue.collections.returnFailed', 'Could not record that'));
    } finally {
      setCollBusy(null);
    }
  };

  const roles = useMemo(() => data?.roles || {}, [data]);
  const sections = useMemo(() => data?.sections || {}, [data]);
  const counts = useMemo(() => data?.counts || {}, [data]);

  const onDone = (message) => {
    setModal(null);
    if (message) toast.success(message);
    reload({ silent: true });
    setDetailReload((n) => n + 1);
  };

  const isInspector = !!roles.inspector;
  const isDispatcher = !!roles.dispatcher;
  const isDriver = !!roles.driver;
  // Either door of SendCarInModal qualifies: a driver asks for a look; an inspector or supervisor can
  // also send a car straight to a garage. The modal shows only the doors the caller may actually use.
  const canRequest = can('maintenance.logistics') || can('maintenance.initiate') || can('maintenance.manage');

  // Default the active tab to the user's first available role; keep it valid as roles load.
  useEffect(() => {
    const first = isInspector ? 'inspector' : isDispatcher ? 'dispatcher' : isDriver ? 'driver' : '';
    setActiveTab((cur) => {
      const stillValid = (cur === 'inspector' && isInspector) || (cur === 'dispatcher' && isDispatcher) || (cur === 'driver' && isDriver);
      return stillValid ? cur : first;
    });
  }, [isInspector, isDispatcher, isDriver]);

  const availableTabs = ROLE_TABS.filter((tab) => roles[tab.key]);
  const activeSections = SECTIONS.filter((s) => s.role === activeTab);
  const sectionCount = (s) => counts[s.key] ?? (sections[s.key]?.length || 0);
  // `supplementary` sections are a re-cut of tickets the other sections in the same tab already list
  // (assigned_to_me pulls YOUR cars back out of the driver lanes), so counting them would inflate the
  // tab badge by showing the same car twice.
  const tabCount = (roleKey) => SECTIONS
    .filter((s) => s.role === roleKey && !s.supplementary)
    .reduce((n, s) => n + sectionCount(s), 0);
  const hasAnyTicket = activeSections.some((s) => sectionCount(s) > 0);

  // Every ticket in the active tab (deduped by id — final_reinspections appears under two roles).
  const activeTickets = useMemo(() => {
    const seen = new Set();
    const out = [];
    activeSections.forEach((s) => (sections[s.key] || []).forEach((tk) => {
      if (!seen.has(tk.id)) { seen.add(tk.id); out.push({ tk, section: s }); }
    }));
    return out;
  }, [activeSections, sections]);

  // KPIs — all derived from real queue data (no fabricated fleet-wide numbers), and always over the
  // WHOLE seat, never the filtered view.
  const kpi = useMemo(() => {
    const tickets = activeTickets.map((x) => x.tk);
    const vehicleIds = new Set(tickets.map((tk) => tk.vehicle_id));
    const awaitingQa = (counts.final_reinspections ?? (sections.final_reinspections?.length || 0));
    // The longest anything on this seat has sat in its current stage. The one number that says
    // whether the queue is being worked or merely held.
    const oldest = tickets.reduce((max, tk) => Math.max(max, stageSeconds(tk) || 0), 0);
    const oldestTk = oldest ? (tickets.find((tk) => (stageSeconds(tk) || 0) === oldest) || null) : null;
    return { total: tickets.length, vehicles: vehicleIds.size, awaitingQa, oldest, oldestTk };
  }, [activeTickets, counts, sections]);

  // ── The view: search → sort. Search NARROWS what is drawn; it never edits a count. ──
  const matches = useCallback((tk) => {
    const q = query.trim().toLowerCase();
    return !q || haystack(tk).includes(q);
  }, [query]);

  const orderTickets = useCallback((list) => {
    const arr = [...list];
    if (sortBy === 'plate') {
      return arr.sort((a, b) => String(a.plate || '').localeCompare(String(b.plate || ''), undefined, { numeric: true }));
    }
    if (sortBy === 'waiting') {
      return arr.sort((a, b) => (stageSeconds(b) || 0) - (stageSeconds(a) || 0));
    }
    // Priority: severity first, then whoever has been waiting longest inside that severity.
    return arr.sort((a, b) => ((SEV_RANK[sevOf(a)] ?? 9) - (SEV_RANK[sevOf(b)] ?? 9))
      || ((stageSeconds(b) || 0) - (stageSeconds(a) || 0)));
  }, [sortBy]);

  const filtering = query.trim() !== '';
  // How many cards the search is currently hiding across the seat — so an empty-looking board can
  // never be mistaken for an empty queue.
  const shownCount = useMemo(
    () => activeTickets.filter(({ tk }) => matches(tk)).length,
    [activeTickets, matches]
  );

  const roleCount = [isInspector, isDispatcher, isDriver].filter(Boolean).length;
  const subtitleKey = roleCount > 1 ? 'both' : isInspector ? 'inspector' : isDispatcher ? 'dispatcher' : isDriver ? 'driver' : 'none';
  const maxLoad = Math.max(1, ...activeSections.map((s) => sectionCount(s)));

  // Red-graded work per role tab, so the role you are NOT looking at can still shout.
  const roleUrgent = (roleKey) => {
    const seen = new Set();
    let n = 0;
    SECTIONS.filter((s) => s.role === roleKey && !s.readonly).forEach((s) => (sections[s.key] || []).forEach((tk) => {
      if (seen.has(tk.id)) return;
      seen.add(tk.id);
      if (sevOf(tk) === 'crit') n += 1;
    }));
    return n;
  };

  return (
    <div className="opx qpage">
      {/* Command header */}
      <div className="opx-head">
        <h1>
          {t('queue.title')}
          <span className="live"><span className="d" /> {t('queue.live')}</span>
        </h1>
        <OpsClock />
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <button type="button" className="opx-btn" onClick={() => { reload(); loadCollections(); }}
            title={t('queue.refresh')} aria-label={t('queue.refresh')}>
            <Icon.Refresh className="h-4 w-4" />
          </button>
          <Link to="/maintenance-workflow" className="opx-btn">{t('queue.fullPipeline')}</Link>
          {canRequest && (
            <button type="button" className="opx-btn primary" onClick={() => setModal({ action: 'request' })}>
              <Icon.Plus className="h-4 w-4" /> {t('workflow.board.requestInspection')}
            </button>
          )}
        </div>
      </div>

      <div className="opx-body">
        {error && (
          <div className="opx-panel" style={{ marginBottom: 18 }}>
            <div className="opx-panel-bd" style={{ color: 'var(--crit)' }}>{error}</div>
          </div>
        )}

        {!loading && !error && availableTabs.length === 0 && (
          <div className="opx-panel">
            <div className="opx-empty">
              <div className="big">🔧</div>
              <div style={{ fontSize: 14, color: 'var(--ink-2)', fontWeight: 600 }}>{t('queue.noRoleTitle')}</div>
              <div style={{ marginTop: 6, maxWidth: 460, marginInline: 'auto' }}>{t('queue.noRoleMsg')}</div>
            </div>
          </div>
        )}

        {availableTabs.length > 0 && (
          <>
            {/* ── The seat you are sitting in ──────────────────────────────────────────────
                Who you are, what this page is for you, and — when you hold more than one role —
                the seat you are currently reading. The role selector lives here rather than on a
                row of its own because "which seat" is the single choice everything below obeys. */}
            <div className="qhero">
              <div className="qhero-id">
                <span className="qhero-av">{(user?.name || '?').charAt(0).toUpperCase()}</span>
                <div className="qhero-txt">
                  <div className="qhero-name">{user?.name || t('queue.tab.' + (activeTab || 'inspector'))}</div>
                  <p className="qhero-sub">{t(`queue.subtitle.${subtitleKey}`)}</p>
                </div>
              </div>
              <div className="qroles">
                {availableTabs.map((tab) => {
                  const active = activeTab === tab.key;
                  const IconCmp = tab.icon;
                  const urgent = roleUrgent(tab.key);
                  return (
                    <button key={tab.key} type="button" className={`qrole ${active ? 'on' : ''}`}
                      style={{ '--rail': tab.rail }} onClick={() => setActiveTab(tab.key)} aria-pressed={active}
                      title={urgent ? t('queue.roleUrgent', { n: urgent }) : undefined}>
                      <span className="qrole-ic">
                        <IconCmp className="h-5 w-5" />
                        {urgent > 0 && <span className="qrole-dot" />}
                      </span>
                      <span className="qrole-txt">{t(`queue.tab.${tab.key}`)}</span>
                      <span className="qrole-ct">{tabCount(tab.key)}</span>
                    </button>
                  );
                })}
              </div>
            </div>

            {/* Headline numbers for the WHOLE seat — counts, never a filter. The board is narrowed by
                the search box and ordered by the sort; the numbers just tell you what is there. */}
            <div className="qmetrics">
              <QMetric
                label={t('queue.kpi.inQueue')} value={kpi.total} icon={Icon.Car}
                foot={t('queue.kpi.inQueueFoot', { vehicles: kpi.vehicles })}
              />
              <QMetric
                label={t('queue.kpi.awaitingQa')} value={kpi.awaitingQa} tone="good" icon={Icon.Shield}
                foot={t('queue.kpi.awaitingQaFoot')}
              />
              {/* The one number that says whether this queue is being worked or merely held. */}
              <QMetric
                label={t('queue.kpi.oldest')} value={kpi.oldest ? fmtDuration(kpi.oldest) : '—'}
                icon={Icon.Clock} tone={kpi.oldest >= 86400 ? 'warm' : ''}
                foot={kpi.oldestTk ? (kpi.oldestTk.plate || `#${kpi.oldestTk.id}`) : t('queue.kpi.oldestFoot')}
                active={sortBy === 'waiting'} onClick={() => setSortBy(sortBy === 'waiting' ? 'priority' : 'waiting')}
                title={t('queue.kpi.oldestHint')}
              />
            </div>

            {/* ── The view bar ────────────────────────────────────────────────────────────
                Find a car, narrow to a slice, choose the order. None of it touches the numbers
                above, and whenever it hides anything the bar says exactly how much. */}
            <div className="qbar">
              <label className="qbar-search">
                <Icon.Search className="h-4 w-4" />
                <input
                  type="search"
                  value={query}
                  onChange={(e) => setQuery(e.target.value)}
                  placeholder={t('queue.searchPlaceholder')}
                  aria-label={t('queue.searchPlaceholder')}
                />
                {query && (
                  <button type="button" className="qbar-x" onClick={() => setQuery('')} aria-label={t('queue.clearFilter')}>
                    <Icon.X className="h-3.5 w-3.5" />
                  </button>
                )}
              </label>

              <div className="qbar-sort">
                <span className="qbar-sort-lbl">{t('queue.sortBy')}</span>
                <select value={sortBy} onChange={(e) => setSortBy(e.target.value)} aria-label={t('queue.sortBy')}>
                  <option value="priority">{t('queue.sort.priority')}</option>
                  <option value="waiting">{t('queue.sort.waiting')}</option>
                  <option value="plate">{t('queue.sort.plate')}</option>
                </select>
              </div>
            </div>

            {filtering && (
              <div className="qbar-note">
                <Icon.Filter className="h-3.5 w-3.5" />
                <span>{t('queue.showingOf', { shown: shownCount, total: kpi.total })}</span>
                <button type="button" onClick={() => setQuery('')}>{t('queue.clearFilter')}</button>
              </div>
            )}

            {/* Main split: role sections + live side rail */}
            <div className="opx-grid opx-c12">
              <div className="opx-span-8">
                <div className="qsections">
                  {/* Cars still at a customer's address. First panel on the driver's tab, because a
                      car nobody has fetched yet is the only job on this page that is standing still. */}
                  {/* Shown to the DRIVER (he fetches and returns the car) and to the INSPECTOR —
                      on a parking job Abu Maroof is the one who changes the oil, so the button that
                      records it has to be where he already works, not on a driver's screen.
                      ALWAYS rendered on BOTH tabs, empty or not, exactly like every other section
                      here. It was hidden when empty and the feature became unfindable — twice. The
                      second time was worse: this page defaults to the INSPECTOR tab for anyone
                      holding maintenance.initiate (which is Leen, and every admin), so the one tab
                      it was hidden on was the tab most people land on. A heading with "nothing
                      right now" is not noise; it is the difference between an empty queue and a
                      page that appears never to have shipped. */}
                  {['driver', 'inspector'].includes(activeTab) && (
                    <CollectionsPanel
                      tf={tf}
                      userId={user?.id}
                      tasks={collections}
                      loading={collLoading}
                      busyId={collBusy}
                      onClaim={claimCollection}
                      onReceive={(task) => setReceiving({ task, action: 'pickup' })}
                      onArrived={arriveCollection}
                      onOilChange={setOilChanging}
                      onReturned={returnToCustomer}
                      query={query}
                    />
                  )}
                  {activeSections.map((s) => {
                    const tickets = sections[s.key] || [];
                    const count = counts[s.key] ?? tickets.length;
                    // The view's slice of this section, in the order the sort asked for. The panel's
                    // meta shows shown/total the moment they differ, so a filtered section can never
                    // be read as an empty one.
                    const visible = orderTickets(tickets.filter((tk) => matches(tk)));
                    const hidden = tickets.length - visible.length;
                    return (
                      <CommandPanel key={`${s.role}-${s.key}`} title={t(`queue.section.${s.key}.title`)}
                        dotColor={s.tone} meta={hidden > 0 ? `${visible.length}/${count}` : (count || null)}>
                        <p className="qsec-hint">{t(`queue.section.${s.key}.hint`)}</p>
                        <div className="qgrid">
                          {loading ? (
                            <><div className="opx-skel" style={{ height: 128 }} /><div className="opx-skel" style={{ height: 128 }} /></>
                          ) : tickets.length === 0 ? (
                            <p className="opx-empty" style={{ gridColumn: '1 / -1', padding: '26px 10px' }}>{t('queue.nothingHere')}</p>
                          ) : visible.length === 0 ? (
                            <p className="opx-empty" style={{ gridColumn: '1 / -1', padding: '26px 10px' }}>
                              {t('queue.noMatch', { n: hidden })}
                            </p>
                          ) : (
                            visible.map((tk) => (
                              <QueueCard key={tk.id} tk={tk} can={can} userId={user?.id} readonly={s.readonly}
                                active={detail?.id === tk.id}
                                onSelect={(ticket) => setDetail({ id: ticket.id, summary: ticket })}
                                onAct={(action, ticket) => setModal({ action, ticket })} />
                            ))
                          )}
                        </div>
                      </CommandPanel>
                    );
                  })}
                  {!loading && !hasAnyTicket && (
                    <p style={{ textAlign: 'center', color: 'var(--ink-3)', fontSize: 13, padding: 12 }}>{t('queue.allCaught')}</p>
                  )}
                </div>
              </div>

              <div className="opx-span-4">
                <div className="qside">
                  {/* Queue load — real per-section counts as horizontal bars */}
                  <CommandPanel title={t('queue.queueLoad')} label={t(`queue.tab.${activeTab}`)} meta={kpi.total || null}>
                    <div className="qload">
                      {activeSections.map((s) => {
                        const c = sectionCount(s);
                        return (
                          <div className="qload-row" key={`${s.role}-${s.key}`}>
                            <span className="qload-lbl">{t(`queue.section.${s.key}.title`)}</span>
                            <div className="qload-track"><i style={{ width: `${(c / maxLoad) * 100}%`, background: s.tone }} /></div>
                            <span className="qload-ct tnum">{c}</span>
                          </div>
                        );
                      })}
                    </div>
                  </CommandPanel>

                  {/* WHAT HAS BEEN RAISED FOR THIS SEAT — the Action Center's own role lanes, on the
                      page where the role already works. This replaced the old Live Activity feed,
                      which replayed handoff stamps of the very cards listed alongside it: it told
                      you about work you were already looking at. A notification is the opposite —
                      the thing addressed to your role that nobody has picked up yet. */}
                  <QueueNotifications
                    role={activeTab}
                    can={can}
                    roleLabel={activeTab ? t(`queue.tab.${activeTab}`) : ''}
                  />
                </div>
              </div>
            </div>
          </>
        )}
      </div>

      {/* The full ticket — the same slide-over the pipeline board opens: journey rail, findings and
          their fix evidence, this car's own suggested checks, diagnostic context, parts, money,
          quality and history, plus every action the stage allows. Opening it here means a driver or
          an inspector never has to leave their own queue to read the car. */}
      {detail && (
        <TicketDetailDrawer
          ticketId={detail.id}
          summary={detail.summary}
          can={can}
          userId={user?.id}
          reloadKey={detailReload}
          garages={garages}
          findingsCatalog={findingsCatalog}
          onAct={(action, ticket) => setModal({ action, ticket })}
          onClose={() => setDetail(null)}
        />
      )}

      {/* Routing a fault container to a garage — the drawer's own "Manage faults" door. */}
      {modal?.action === 'route' && (
        <TaskRoutingModal
          ticket={modal.ticket}
          garages={garages}
          onClose={() => setModal(null)}
          onDone={() => { reload({ silent: true }); setDetailReload((n) => n + 1); }}
        />
      )}

      {/* "Car received from customer" — custody passes to us, and the odometer at the doorstep is
          captured with it. Mandatory: this reading is what the oil follow-up has been chasing by
          phone for days, and once the car is ours the moment has gone. */}
      {receiving && (
        <CollectionReceivedModal
          task={receiving.task}
          action={receiving.action}
          tf={tf}
          onClose={() => setReceiving(null)}
          onDone={async (message) => {
            setReceiving(null);
            toast.success(message);
            await loadCollections();
            reload({ silent: true });
          }}
          onError={(message) => toast.error(message)}
        />
      )}

      {/* The last step of a collection: the oil this whole trip existed for. Same dialog the Oil
          Follow-up board uses — one write path, so the car's service anchor moves identically
          whether Leen, Abu Maroof or the driver types the number. */}
      {oilChanging && (
        <OilChangeDialog
          row={{
            contract_id:         oilChanging.oil_followup?.contract_id,
            plate:               oilChanging.plate,
            car:                 oilChanging.car,
            service_interval_km: oilChanging.oil_followup?.service_interval_km,
            projection:          { expected: oilChanging.last_odometer?.km ?? oilChanging.previous_odometer },
          }}
          onClose={() => setOilChanging(null)}
          onSaved={async () => { await loadCollections(); reload({ silent: true }); }}
        />
      )}

      {/* Breakdown Intake — a technician reports a not-driveable car → grounded ticket in the dispatch queue. */}
      {modal?.action === 'breakdown' && (
        <BreakdownIntakeModal vehicles={vehicles} onClose={() => setModal(null)} onDone={onDone} />
      )}

      {/* Complaint Triage — Abu Maroof handles a logged complaint (talk / resolve on-site / send in). */}
      {modal?.action === 'triage' && (
        <ComplaintTriageModal ticket={modal.ticket} vehicles={vehicles} onClose={() => setModal(null)} onDone={onDone} />
      )}

      {/* Send a car in — its own two-door form (ask for a look / straight to the garage), so it is
          excluded from the generic action modal below. */}
      {modal?.action === 'request' && (
        <SendCarInModal vehicles={vehicles} onClose={() => setModal(null)} onDone={onDone} />
      )}
      {modal && !['breakdown', 'triage', 'request', 'route'].includes(modal.action) && (
        <TicketActionModal
          action={modal.action}
          ticket={modal.ticket || null}
          vehicles={vehicles}
          garages={garages}
          findingsCatalog={findingsCatalog}
          keywordMeta={keywordMeta}
          faultCausesCatalog={faultCausesCatalog}
          locationCatalog={locationCatalog}
          assignableDrivers={drivers}
          allowedTypes={maintTypes}
          onClose={() => setModal(null)}
          onDone={onDone}
        />
      )}
    </div>
  );
}
