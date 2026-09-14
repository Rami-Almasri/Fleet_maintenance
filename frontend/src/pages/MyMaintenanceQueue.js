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
  CommandPanel, JourneyMap, severityTone,
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
import { resolveAction, stageAge, stageSeconds, ago, custodyBlocked, custodyHolderName, assignmentBlocked, assignedDriverName, ORIGIN_LABEL } from '../components/workflow/meta';
// The car's own photograph — the same registry the Fleet hub uses. A miss is silent and falls back
// to the marque logo, then to a neutral silhouette; it NEVER guesses a different car.
import { carPhoto, brandLogo, vehicleName } from '../lib/carAssets';
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

// Section → the glyph on its headline tile. The tile inherits the section's own `tone`, so a lane
// and the number that counts it read as the same colour of work.
const SECTION_ICON = {
  complaint_triage: Icon.Alert,
  pending_inspections: Icon.Search,
  on_site: Icon.Wrench,
  final_reinspections: Icon.Check,
  assigned_to_me: Icon.Users,
  awaiting_dispatch_decision: Icon.Route,
  awaiting_pickup: Icon.Truck,
  release_dispatch: Icon.Route,
  released_parked: Icon.Flag,
  reinspection_failed: Icon.Alert,
  active_dispatches: Icon.Truck,
  release_moves: Icon.Route,
  waiting_followup: Icon.Clock,
  return_to_base: Icon.Tow,
  back_from_garage: Icon.Check,
};

// Severity → the card's priority badge. Three words, because a queue is worked in the order a person
// can read at a glance; the full graded label stays on the chip below.
const PRIORITY_WORD = { crit: 'high', paused: 'medium', ok: 'low', info: 'low' };

// The marque, as a person would pick it from a menu. `car` arrives as one string ("NISSAN PATROL"),
// so the make is its first word — the same fold the photo registry does, kept deliberately dumb
// because nothing here repairs the sheet's data, it only reads it.
const makeOf = (tk) => String(tk.car || '').trim().split(/\s+/)[0]?.toUpperCase() || '';

// Everything a card is worth searching by — the plate you were told on the phone, the car, the
// fault, the garage it sits in, the complaint someone typed. One haystack, matched case-insensitively.
const haystack = (tk) => [
  tk.plate, tk.car, tk.status_label, tk.fault_severity_label, tk.customer_complaint,
  tk.garage, tk.position?.label, `#${tk.id}`,
].filter(Boolean).join(' ').toLowerCase();

/**
 * A headline tile: the number, what it counts, and the one link that takes you to it. Pressing
 * "View all" narrows the board below to exactly the cards the number describes — the professional
 * shortcut on a busy queue is "show me only the six waiting on me", and the number that says there
 * are six is the natural place to press.
 */
function QTile({ label, value, tone, icon: IconCmp, active = false, onClick, viewAll, title }) {
  const clickable = !!onClick;
  return (
    <div
      className={`qtile ${active ? 'on' : ''} ${clickable ? 'click' : ''}`}
      style={{ '--tone': tone }}
      title={title}
      role={clickable ? 'button' : undefined}
      tabIndex={clickable ? 0 : undefined}
      onClick={onClick}
      onKeyDown={clickable ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onClick(); } } : undefined}
    >
      <span className="qtile-ic">{IconCmp ? <IconCmp /> : null}</span>
      <div className="qtile-bd">
        <div className="qtile-v tnum">{value}</div>
        <div className="qtile-lbl">{label}</div>
        {viewAll ? <span className="qtile-all">{viewAll} <Icon.ArrowRight /></span> : null}
      </div>
      <span className="qtile-go"><Icon.ArrowUpRight /></span>
    </div>
  );
}

/**
 * The page's own clock: the day on one line, the time under it. Gregorian with Latin digits in
 * Arabic too, so the date never switches calendar under an RTL reader.
 */
function QueueClock() {
  const { lang } = useI18n();
  const [now, setNow] = useState(() => new Date());
  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 1000);
    return () => clearInterval(id);
  }, []);
  const timeLoc = lang === 'ar' ? 'ar-AE-u-nu-latn' : undefined;
  const dateLoc = lang === 'ar' ? 'ar-AE-u-ca-gregory-nu-latn' : undefined;
  return (
    <div className="qhdr-when">
      <span className="qhdr-date">
        {now.toLocaleDateString(dateLoc, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })}
      </span>
      <strong className="qhdr-time tnum">
        {now.toLocaleTimeString(timeLoc, { hour: '2-digit', minute: '2-digit', hour12: true })}
      </strong>
    </div>
  );
}

/**
 * The car's photograph, or the honest fallbacks behind it. A picture is a claim about which vehicle
 * this card is — we hold no photo for roughly a third of the fleet, and those rows show the marque
 * logo or a silhouette rather than a car we do not own.
 */
function CarShot({ car }) {
  const photo = carPhoto(car, '');
  const logo = photo ? null : brandLogo(car, '');
  if (photo) return <img className="qcard-img" src={photo} alt="" loading="lazy" />;
  if (logo) return <img className="qcard-logo" src={logo} alt="" loading="lazy" />;
  return <Icon.Car className="qcard-silhouette" />;
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

function QueueCard({ tk, can, userId, onAct, onSelect, active = false, readonly = false, compact = false }) {
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

  const prio = PRIORITY_WORD[sev] || 'low';
  const action = resolveAction(tk);

  return (
    // The whole card is the door to the ticket. The plate link, the action buttons and the timeline
    // toggle each stop the click so their own job still wins over opening the drawer.
    <article
      className={`qcard ${sevClass} ${active ? 'qcard-open' : ''} ${age?.over ? 'qcard-late' : ''} ${compact ? 'qcard-row' : ''}`}
      role="button"
      tabIndex={0}
      title={t('queue.openTicket')}
      onClick={() => onSelect?.(tk)}
      onKeyDown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onSelect?.(tk); }
      }}
    >
      {/* Ticket reference · how urgent · the way in. */}
      <div className="qcard-top">
        <span className="qcard-ref mono">#{tk.id}</span>
        <span className={`qprio ${prio}`} title={tk.fault_severity_label || undefined}>
          {t(`queue.priority.${prio}`)}
        </span>
        <span className={`qcard-go ${prio}`}><Icon.ArrowRight /></span>
      </div>

      {/* The car itself. Photograph where we hold one, marque logo where we don't, silhouette where
          we hold neither — never a different car. */}
      <div className="qcard-shot"><CarShot car={tk.car} /></div>

      <div className="qcard-id">
        <h3 className="qcard-name" title={tk.car || ''}>{vehicleName(tk.car, '')}</h3>
        <Link
          to={`/vehicles/${tk.vehicle_id}`}
          className="qcard-plate mono"
          onClick={(e) => e.stopPropagation()}
        >
          {tk.plate || `#${tk.id}`}
        </Link>
      </div>

      <div className="qcard-chips">
        {reasonChip
          ? <span className={`qchip ${reasonChip}`}><i />{reasonLabel}</span>
          : tk.fault_severity_tone
            ? <span className={`qchip ${sev === 'ok' ? 'avail' : sev === 'crit' ? 'crit' : 'paused'}`}>
              <i />{tk.fault_severity_label}
            </span>
            : null}
        {tk.status_label && <span className={`qchip ${sev === 'crit' ? 'crit' : sev === 'paused' ? 'paused' : 'avail'}`}><i />{tk.status_label}</span>}
        {/* What the stage is asking for. On the mock-up this reads "Take it to Workshop" — it is the
            card's own next move, stated before you have to open anything. */}
        {!readonly && action && (
          <span className="qchip step"><i />{t(`workflow.cardAction.${action.action}`)}</span>
        )}
        {tk.is_recovery && <span className="qchip crit"><i />{t('workflow.reasonShort.recovery') || 'Recovery'}</span>}
        {tk.temporarily_released && <span className="qchip paused"><i />⤴</span>}
        {age?.over && (
          <span className="qchip crit" title={t('queue.timeInStage')}><i />{t('queue.late', { age: age.label })}</span>
        )}
      </div>

      {/* The reported note, attributed to its source — an escalated Driver Observation must not read
          as an anonymous quote on the inspector's own queue. */}
      {tk.customer_complaint && (
        <p className="qcard-quote" title={tk.customer_complaint}>
          {(tk.request_origin_label || ORIGIN_LABEL[tk.request_origin]) && (
            <span className="qcard-quote-src">{tk.request_origin_label || ORIGIN_LABEL[tk.request_origin]}: </span>
          )}
          “{tk.customer_complaint}”
        </p>
      )}

      {(pos.label || tk.garage) && (
        <div className="qcard-pos">
          <Icon.Gauge />
          <span>{pos.label || tk.status_label}{tk.garage ? ` · ${tk.garage}` : ''}</span>
          <TimeInStage tk={tk} t={t} />
        </div>
      )}

      <div className="qcard-prog" title={t('queue.stageProgress')}>
        <i style={{ width: `${pct}%`, background: SEV_COLOR[sev] || SEV_COLOR.info }} />
      </div>

      <div className="qcard-foot" onClick={(e) => e.stopPropagation()}>
        <CardActions tk={tk} can={can} userId={userId} onAct={onAct} readonly={readonly} t={t} />
        <div className="qcard-links">
          {nodes.length > 0 && (
            <button type="button" className="qcard-tl" title={t('queue.timeline')}
              onClick={(e) => { e.stopPropagation(); setOpen((v) => !v); }} aria-expanded={open}>
              <Icon.Clock />
            </button>
          )}
          <button type="button" className="qcard-open-btn" onClick={() => onSelect?.(tk)}>
            {t('queue.viewDetails')} <Icon.ArrowRight />
          </button>
        </div>
      </div>

      {open && nodes.length > 0 && (
        <div className="qc-timeline">
          <div className="qc-tl-h">{t('queue.timeline')}</div>
          <JourneyMap nodes={nodes} />
        </div>
      )}
    </article>
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
  // The lane pills across the top of the board: 'all', or one section key. The board is one flat
  // grid of cards, so the pill is what says WHICH work you are looking at.
  const [lane, setLane] = useState('all');
  // The three narrowing selects. Every option is read off the tickets actually in the seat — a
  // garage with nothing in it is not offered, so an empty result can only ever mean an empty queue.
  const [makeFilter, setMakeFilter] = useState('');
  const [prioFilter, setPrioFilter] = useState('');
  const [garageFilter, setGarageFilter] = useState('');
  const [view, setView] = useState('grid');        // grid | list

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
    if (q && !haystack(tk).includes(q)) return false;
    if (makeFilter && makeOf(tk) !== makeFilter) return false;
    if (prioFilter && sevOf(tk) !== prioFilter) return false;
    if (garageFilter && (tk.garage || '') !== garageFilter) return false;
    return true;
  }, [query, makeFilter, prioFilter, garageFilter]);

  // The select options, read off the seat's own tickets. Never a hard-coded list: a make or a garage
  // that holds nothing is not offered, so "no results" can only mean the filters, never a stale menu.
  const makeOptions = useMemo(
    () => [...new Set(activeTickets.map(({ tk }) => makeOf(tk)).filter(Boolean))].sort(),
    [activeTickets]
  );
  const garageOptions = useMemo(
    () => [...new Set(activeTickets.map(({ tk }) => tk.garage).filter(Boolean))].sort(),
    [activeTickets]
  );

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

  const filtering = query.trim() !== '' || !!makeFilter || !!prioFilter || !!garageFilter;
  // How many cards the view is currently hiding across the seat — so an empty-looking board can
  // never be mistaken for an empty queue.
  const shownCount = useMemo(
    () => activeTickets.filter(({ tk }) => matches(tk)).length,
    [activeTickets, matches]
  );
  const clearFilters = () => { setQuery(''); setMakeFilter(''); setPrioFilter(''); setGarageFilter(''); };

  // THE BOARD — one flat grid, because the lane pills above already say which work you are reading.
  // `lane` picks the section, the selects narrow it, the sort orders it.
  const board = useMemo(() => {
    const picked = lane === 'all'
      ? activeTickets
      : activeTickets.filter(({ section }) => section.key === lane);
    return orderTickets(picked.filter(({ tk }) => matches(tk)).map(({ tk }) => tk))
      .map((tk) => ({ tk, section: picked.find((x) => x.tk.id === tk.id).section }));
  }, [activeTickets, lane, matches, orderTickets]);

  // The cars this seat has been sitting on longest — the rail's one honest urgency signal. Whole
  // seat, never the filtered board: hiding a car behind a make filter does not un-strand it.
  const attention = useMemo(
    () => activeTickets
      .map(({ tk }) => tk)
      .filter((tk) => (stageSeconds(tk) || 0) > 0)
      .sort((a, b) => (stageSeconds(b) || 0) - (stageSeconds(a) || 0))
      .slice(0, 4),
    [activeTickets]
  );

  // A lane you selected on one seat does not exist on the next one.
  useEffect(() => { setLane('all'); clearFilters(); }, [activeTab]);   // eslint-disable-line react-hooks/exhaustive-deps

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
      <div className="opx-body qbody">
        {/* ── The page's own masthead: what this is, what time it is, and the one button that
            adds work to it. Everything else on the page is work that is already here. */}
        <header className="qhdr">
          <div className="qhdr-main">
            <h1>{t('queue.pageTitle')}</h1>
            <p>{t('queue.pageSub')}</p>
          </div>
          <QueueClock />
          <div className="qhdr-acts">
            <button type="button" className="qicon" onClick={() => { reload(); loadCollections(); }}
              title={t('queue.refresh')} aria-label={t('queue.refresh')}>
              <Icon.Refresh />
            </button>
            <Link to="/maintenance-workflow" className="qghost" title={t('queue.fullPipeline')}>
              <Icon.Route /> <span>{t('queue.fullPipeline')}</span>
            </Link>
            {canRequest && (
              <button type="button" className="qcta" onClick={() => setModal({ action: 'request' })}>
                <Icon.Plus /> {t('queue.addToQueue')}
              </button>
            )}
          </div>
        </header>

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
            {/* The seat selector. One role, no switch to make — it only appears for the people who
                genuinely hold more than one, and then it is the single choice everything below obeys. */}
            {availableTabs.length > 1 && (
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
                        <IconCmp />
                        {urgent > 0 && <span className="qrole-dot" />}
                      </span>
                      <span className="qrole-txt">{t(`queue.tab.${tab.key}`)}</span>
                      <span className="qrole-ct">{tabCount(tab.key)}</span>
                    </button>
                  );
                })}
              </div>
            )}

            {/* Main split: the board and its headline numbers, then the live side rail. */}
            <div className="qmain">
              <div className="qcol">
                {/* ── Headline numbers for the WHOLE seat. They are counts, never a view: the
                    board underneath is narrowed by the pills and selects below, and each tile's
                    "View all" is simply the shortcut that sets the matching pill. */}
                <div className="qtiles">
                  <QTile
                    label={t('queue.kpi.vehiclesInQueue')} value={kpi.total} tone="#2563eb" icon={Icon.Car}
                    active={lane === 'all'} onClick={() => setLane('all')} viewAll={t('queue.viewAll')}
                    title={t('queue.kpi.inQueueFoot', { vehicles: kpi.vehicles })}
                  />
                  {activeSections.filter((s) => !s.supplementary).map((s) => {
                    const IconCmp = SECTION_ICON[s.key] || Icon.Wrench;
                    return (
                      <QTile
                        key={`${s.role}-${s.key}`}
                        label={t(`queue.section.${s.key}.title`)} value={sectionCount(s)}
                        tone={s.tone} icon={IconCmp} active={lane === s.key}
                        onClick={() => setLane(lane === s.key ? 'all' : s.key)}
                        viewAll={t('queue.viewAll')} title={t(`queue.section.${s.key}.hint`)}
                      />
                    );
                  })}
                </div>

                {/* ── The view bar: which lane, then which slice of it, then how it is drawn.
                    None of it touches the numbers above, and whenever it hides anything the
                    line underneath says exactly how much. */}
                <div className="qfilters">
                  <div className="qpills">
                    <button type="button" className={`qpill ${lane === 'all' ? 'on' : ''}`} onClick={() => setLane('all')}>
                      {t('queue.filter.all')} <span className="tnum">({kpi.total})</span>
                    </button>
                    {activeSections.filter((s) => !s.supplementary).map((s) => (
                      <button key={`${s.role}-${s.key}`} type="button"
                        className={`qpill ${lane === s.key ? 'on' : ''}`}
                        title={t(`queue.section.${s.key}.hint`)}
                        onClick={() => setLane(s.key)}>
                        {t(`queue.section.${s.key}.title`)} <span className="tnum">({sectionCount(s)})</span>
                      </button>
                    ))}
                  </div>

                  <div className="qtools">
                    <label className="qsearch">
                      <Icon.Search />
                      <input
                        type="search" value={query} onChange={(e) => setQuery(e.target.value)}
                        placeholder={t('queue.searchPlaceholder')} aria-label={t('queue.searchPlaceholder')}
                      />
                      {query && (
                        <button type="button" className="qsearch-x" onClick={() => setQuery('')} aria-label={t('queue.clearFilter')}>
                          <Icon.X />
                        </button>
                      )}
                    </label>
                    <select className="qsel" value={makeFilter} onChange={(e) => setMakeFilter(e.target.value)}
                      aria-label={t('queue.filter.allMakes')}>
                      <option value="">{t('queue.filter.allMakes')}</option>
                      {makeOptions.map((m) => <option key={m} value={m}>{m}</option>)}
                    </select>
                    <select className="qsel" value={prioFilter} onChange={(e) => setPrioFilter(e.target.value)}
                      aria-label={t('queue.filter.priority')}>
                      <option value="">{t('queue.filter.priority')}</option>
                      <option value="crit">{t('queue.priority.high')}</option>
                      <option value="paused">{t('queue.priority.medium')}</option>
                      <option value="ok">{t('queue.priority.low')}</option>
                    </select>
                    <select className="qsel" value={garageFilter} onChange={(e) => setGarageFilter(e.target.value)}
                      aria-label={t('queue.filter.allGarages')}>
                      <option value="">{t('queue.filter.allGarages')}</option>
                      {garageOptions.map((g) => <option key={g} value={g}>{g}</option>)}
                    </select>
                    <select className="qsel" value={sortBy} onChange={(e) => setSortBy(e.target.value)}
                      aria-label={t('queue.sortBy')}>
                      <option value="priority">{t('queue.sort.priority')}</option>
                      <option value="waiting">{t('queue.sort.waiting')}</option>
                      <option value="plate">{t('queue.sort.plate')}</option>
                    </select>
                    <div className="qview" role="group" aria-label={t('queue.view.label')}>
                      <button type="button" className={view === 'grid' ? 'on' : ''} onClick={() => setView('grid')}
                        title={t('queue.view.grid')} aria-label={t('queue.view.grid')}>
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                          <rect x="3" y="3" width="7.5" height="7.5" rx="1.6" />
                          <rect x="13.5" y="3" width="7.5" height="7.5" rx="1.6" />
                          <rect x="3" y="13.5" width="7.5" height="7.5" rx="1.6" />
                          <rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.6" />
                        </svg>
                      </button>
                      <button type="button" className={view === 'list' ? 'on' : ''} onClick={() => setView('list')}
                        title={t('queue.view.list')} aria-label={t('queue.view.list')}>
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                          <rect x="3" y="4.5" width="18" height="2.6" rx="1.3" />
                          <rect x="3" y="10.7" width="18" height="2.6" rx="1.3" />
                          <rect x="3" y="16.9" width="18" height="2.6" rx="1.3" />
                        </svg>
                      </button>
                    </div>
                  </div>
                </div>

                {filtering && (
                  <div className="qbar-note">
                    <Icon.Filter />
                    <span>{t('queue.showingOf', { shown: shownCount, total: kpi.total })}</span>
                    <button type="button" onClick={clearFilters}>{t('queue.clearFilter')}</button>
                  </div>
                )}

                {/* Cars still at a customer's address. Its own block, because a car nobody has
                    fetched yet is the only job on this page that is standing still. */}
                {/* Shown to the DRIVER (he fetches and returns the car) and to the INSPECTOR —
                    on a parking job Abu Maroof is the one who changes the oil, so the button that
                    records it has to be where he already works, not on a driver's screen.
                    ALWAYS rendered on BOTH tabs, empty or not. It was hidden when empty and the
                    feature became unfindable — twice. The second time was worse: this page defaults
                    to the INSPECTOR tab for anyone holding maintenance.initiate (which is Leen, and
                    every admin), so the one tab it was hidden on was the tab most people land on.
                    A heading with "nothing right now" is not noise; it is the difference between an
                    empty queue and a page that appears never to have shipped. */}
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

                {/* THE BOARD — one flat grid of cars, ordered by the sort. The pill above says
                    which lane it is showing; the card says what the car is waiting for. */}
                <div className={`qboard ${view}`}>
                  {loading ? (
                    <>
                      <div className="opx-skel" style={{ height: 268, borderRadius: 16 }} />
                      <div className="opx-skel" style={{ height: 268, borderRadius: 16 }} />
                      <div className="opx-skel" style={{ height: 268, borderRadius: 16 }} />
                      <div className="opx-skel" style={{ height: 268, borderRadius: 16 }} />
                    </>
                  ) : board.length === 0 ? (
                    <p className="qboard-empty">
                      {!hasAnyTicket
                        ? t('queue.allCaught')
                        : filtering
                          ? t('queue.noMatch', { n: activeTickets.length - shownCount })
                          : t('queue.nothingHere')}
                    </p>
                  ) : (
                    board.map(({ tk, section }) => (
                      <QueueCard key={tk.id} tk={tk} can={can} userId={user?.id} readonly={section.readonly}
                        compact={view === 'list'}
                        active={detail?.id === tk.id}
                        onSelect={(ticket) => setDetail({ id: ticket.id, summary: ticket })}
                        onAct={(action, ticket) => setModal({ action, ticket })} />
                    ))
                  )}
                </div>
              </div>

              <aside className="qside">
                {/* Queue load — real per-section counts as horizontal bars. */}
                <section className="qpanel">
                  <div className="qpanel-hd">
                    <h3>{t('queue.queueLoad')}</h3>
                    <span className="qpanel-lbl">{user?.name ? `${user.name} · ` : ''}{t(`queue.tab.${activeTab}`)}</span>
                  </div>
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
                </section>

                {/* WAITING LONGEST — the cars this seat is holding up, newest stage first. Red not
                    because anything is broken, but because nobody has moved them. */}
                <section className="qpanel qpanel-alert">
                  <div className="qpanel-hd">
                    <h3><Icon.Alert /> {t('queue.attention.title')}</h3>
                    <span className="qpanel-ct">{attention.filter((tk) => stageAge(tk, t)?.over).length || attention.length}</span>
                  </div>
                  {attention.length === 0 ? (
                    <p className="qpanel-empty">{t('queue.attention.none')}</p>
                  ) : attention.map((tk) => (
                    <button key={tk.id} type="button" className="qalert-row"
                      onClick={() => setDetail({ id: tk.id, summary: tk })}>
                      <span className="qalert-ic"><Icon.Car /></span>
                      <span className="qalert-bd">
                        <span className="qalert-t"><b className="mono">#{tk.id}</b> {vehicleName(tk.car, '')}</span>
                        <span className="qalert-s">
                          {t('queue.attention.waiting', { age: stageAge(tk, t)?.label || '—' })}
                        </span>
                      </span>
                    </button>
                  ))}
                </section>

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

                {/* Where to go when the queue itself is the problem. */}
                <section className="qassist">
                  <span className="qassist-ic"><Icon.Users /></span>
                  <div className="qassist-bd">
                    <h3>{t('queue.assist.title')}</h3>
                    <p>{t('queue.assist.body')}</p>
                    <Link to="/control-desk" className="qassist-cta">{t('queue.assist.cta')}</Link>
                  </div>
                </section>
              </aside>
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
