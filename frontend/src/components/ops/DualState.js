/* =======================================================================
   <DualState> — the canonical, app-wide way to show a vehicle's TWO
   independent dimensions at once: Rental Availability + Maintenance
   Lifecycle. Drop it on any surface (Vehicles, Vehicle Profile, boards,
   booking, dispatch) and every vehicle reads the same, with one fixed
   "Paused — Returned to Service" identity. Theme-agnostic (light + dark).

   It derives both dimensions from whatever fields a surface already has
   (operational_status, available/rented/reserved, is_deferred_maintenance,
   under_maintenance, workflow_status, av_state, fault_severity, …), so it
   works from a vehicle resource OR a maintenance ticket without adapters.
   ======================================================================= */
import './dualstate.css';
import { useI18n } from '../../i18n/I18nContext';

/* -- lifecycle label/tone map (mirrors ops/index WF_LIFECYCLE, kept local
      so this primitive has no scoped-CSS side-effects) -- */
const WF = {
  inspection_pending: ['Inspection', 'inspect'], inspection_requested: ['Inspection', 'inspect'],
  complaint_triage: ['Triage', 'inspect'], inspection_diagnostic: ['Diagnosis', 'inspect'],
  recommendation_pending: ['Needs Approval', 'inspect'],
  awaiting_dispatch: ['Dispatch', 'inspect'], in_transit: ['In Transit', 'transit'],
  under_repair: ['In Repair', 'repair'], repair_review: ['Review', 'repair'],
  ready_for_pickup: ['Ready', 'avail'], ready_for_reinspection: ['QA', 'inspect'],
  reinspection_failed: ['QA Failed', 'crit'], pending_qa: ['QA', 'inspect'],
  paused_for_rental: ['Paused', 'paused'], paused_returned_to_service: ['Paused', 'paused'],
  awaiting_invoice: ['Invoice Due', 'paused'], return_handover_pending: ['Return Handover', 'paused'],
  closed: ['Completed', 'avail'], completed: ['Completed', 'avail'],
};
const PAUSED_STATES = new Set(['paused_for_rental', 'paused_returned_to_service']);

export function isCritical(sev) {
  return ['critical', 'red', 'high'].includes(String(sev || '').toLowerCase());
}

// Rental dimension → { key, label }. Shows the car's STATUS, plain and simple — whatever the
// vehicles.status column holds (from the OM sync + the fleet Status sheet overlay). No derived
// "Blocked"/"Grounded"/condition wording. [key] only picks the chip colour.
export function rentalDimension(v = {}) {
  const op = String(v.operational_status || v.rental_state || v.av_state || '').toLowerCase();
  const st = String(v.status || '').toLowerCase();
  // status slug → [chip colour key, label]
  const STATUS = {
    ready: ['avail', 'Ready'],
    rented: ['rented', 'Rented'],
    office_use: ['blocked', 'Office Use'],
    sold: ['blocked', 'Sold'],
    disposed: ['blocked', 'Disposed'],
    returned: ['blocked', 'Returned'],
    suspended: ['blocked', 'Suspended'],
    out_of_order: ['blocked', 'Out of Order'],
    under_maintenance: ['blocked', 'Under Maintenance'],
  };
  if (STATUS[st]) return { key: STATUS[st][0], label: STATUS[st][1] };
  // Fallback only for payloads with no status column (e.g. a maintenance ticket): derive from movement.
  if (v.rented || op === 'rented' || op === 'on_rent') return { key: 'rented', label: 'Rented' };
  if (v.reserved || op === 'reserved' || op === 'booked') return { key: 'reserved', label: 'Reserved' };
  if (['in_transit', 'transfer', 'transit'].includes(op)) return { key: 'transit', label: 'In Transit' };
  return { key: 'avail', label: 'Available' };
}

// Maintenance dimension → { key, label, active, paused }
export function maintenanceDimension(v = {}) {
  const raw = String(v.maintenance_state || v.workflow_status || '').toLowerCase();
  if (v.is_deferred_maintenance || PAUSED_STATES.has(raw)) return { key: 'paused', label: 'Paused', active: true, paused: true };
  if (raw && WF[raw]) { const [label, key] = WF[raw]; return { key, label, active: !['avail'].includes(key) || label !== 'Completed', paused: false }; }
  if (raw && raw !== 'none') return { key: 'repair', label: v.status_label || 'In Workshop', active: true, paused: false };
  const opMaint = String(v.operational_status || v.av_state || '').toLowerCase() === 'maintenance';
  if (v.under_maintenance || opMaint) return { key: 'repair', label: 'In Workshop', active: true, paused: false };
  if (v.pending_on_site_maintenance) return { key: 'inspect', label: 'On-site', active: true, paused: false };
  return { key: 'none', label: 'No maintenance', active: false, paused: false };
}

export default function DualState({ vehicle = {}, size = 'sm', stack = false, showNone = true }) {
  const { t } = useI18n();
  const r = rentalDimension(vehicle);
  const m = maintenanceDimension(vehicle);
  const crit = isCritical(vehicle.fault_severity || vehicle.severity)
    || (m.paused && ['red', 'yellow'].includes(String(vehicle.condition_grade || '').toLowerCase()));
  const showM = m.active || showNone;
  const mTone = m.paused && crit ? 'paused' : m.key; // paused stays amber even when critical; crit marked by the dot
  return (
    <span className={`ds-dual ${stack ? 'stack' : ''}`}>
      <span className={`ds-chip ${size} ds-${r.key}`}><span className="ds-dot" />{t(r.label)}</span>
      {showM && (
        <span className={`ds-chip ${size} ds-${mTone}`} title={m.paused ? t('Repair paused — vehicle returned to service') : undefined}>
          {m.paused ? <span className="ds-dot" style={{ marginRight: 1 }} /> : <span className="ds-dot" />}
          {m.paused ? '⏸ ' : ''}{t(m.label)}
          {crit && m.paused ? <span className="ds-crit-dot" title={t('Critical fault')} /> : null}
        </span>
      )}
    </span>
  );
}

/* -----------------------------------------------------------------------
   <RegisterStatus> — what the fleet register (the "Faster" tab) calls this
   car, in the register's own words: Active / For sale / Office / Under
   process / Insurance claim / Sold.

   This is NOT another view of vehicles.status. It is the sheet's claim, held
   separately in `sheet_status`, and the two can legitimately disagree — a car
   the register calls "Office" can be out on a live rental right now. That
   disagreement used to be invisible: the register's word was mapped into a
   status slug and discarded, so the only way to find a conflict was to open
   Google and read across 247 rows by hand.

   So the chip goes quiet when the two agree and turns amber when they don't.
   Opt-in, not folded into <DualState>, because most boards want the car's
   operational state and nothing else; this belongs where someone is
   reconciling the fleet against the register.
   ----------------------------------------------------------------------- */

// True when the register's verdict and our live state point opposite ways.
// "Active" on the register should mean a car that is earning or in the shop
// to get back to earning; anything else should mean a car we are not counting.
export function registerConflict(vehicle = {}) {
  const sheet = String(vehicle.sheet_status || '').trim();
  if (!sheet) return false;
  const registerSaysActive = sheet.toLowerCase() === 'active';
  const st = String(vehicle.status || '').toLowerCase();
  // Live paperwork counts as "in use" even when the status column disagrees — the
  // dashboard's own donut classifies from contracts first, so this must match it.
  const onPaper = (vehicle.contract_lines || []).some((l) => l.kind !== 'note' && (l.type === 'C' || l.type === 'U'));
  const weTreatAsActive = ['ready', 'rented', 'under_maintenance'].includes(st) || onPaper;
  return registerSaysActive !== weTreatAsActive;
}

export function RegisterStatus({ vehicle = {}, size = 'sm' }) {
  const { t } = useI18n();
  const sheet = String(vehicle.sheet_status || '').trim();
  // No word recorded yet — the car predates the register capture, or was made on
  // the website (those are deliberately never touched by the sheet importers).
  if (!sheet) return null;
  const clash = registerConflict(vehicle);
  return (
    <span
      className={`ds-chip ${size} ds-reg${clash ? ' clash' : ''}`}
      title={clash
        ? t('The fleet register calls this car "{word}", which does not match how the system is currently treating it. One of the two is out of date.', { word: sheet })
        : t('The fleet register calls this car "{word}".', { word: sheet })}
    >
      <span className="ds-reg-k">{t('Register')}</span>
      {t(sheet)}
      {clash && <span className="ds-reg-warn">!</span>}
    </span>
  );
}

/* -----------------------------------------------------------------------
   <ContractLines> — the paperwork that sits under the status chips: one line
   per live contract on the car (Rental / Maintenance / Booking), each with its
   number and dates, linking straight to the contract.

   The one that matters most is the line that ISN'T a contract. When our own
   workflow raises a repair, no OfficeManager contract is ever opened for it —
   so that line renders as a NOTE ("Created by System — no OM contract"), in a
   different colour and deliberately not clickable. Leaving the slot blank would
   read as a number that failed to load and send someone hunting in OM for a
   document that never existed.

   Renders nothing when the car has no live paperwork, so it can be dropped in
   unconditionally. Fed by VehicleResource's `contract_lines`.
   ----------------------------------------------------------------------- */
const TYPE_TONE = { C: 'rent', U: 'maint', R: 'book' };

export function ContractLines({ vehicle = {}, linkTo }) {
  const { t } = useI18n();
  const lines = vehicle.contract_lines || [];
  if (!lines.length) return null;

  return (
    <div className="ds-docs">
      {lines.map((l, i) => {
        const note = l.kind === 'note';
        const dates = [
          l.since ? t('since {date}', { date: l.since }) : '',
          l.due ? t('due {date}', { date: l.due }) : '',
        ].filter(Boolean).join(' · ');

        // The TYPE leads — it's the thing being asked of the row ("what kind of contract is
        // this?"). The number follows as the lookup key.
        const chip = (
          <span
            className={`ds-doc ${note ? 'note' : TYPE_TONE[l.type] || 'rent'}`}
            title={note
              ? t('Raised by the Fleet maintenance workflow. No OfficeManager contract was opened for it — there is nothing to look up in OM.')
              : t('{kind} contract {number} — from OfficeManager. Click to open it.', { kind: l.label, number: l.contract_no ? `#${l.contract_no}` : '' })}
          >
            <span className="ds-doc-kind">{l.label}</span>
            {note
              ? <span className="ds-doc-note">{l.note}</span>
              : <b>{l.contract_no ? `#${l.contract_no}` : `#${l.contract_id}`}</b>}
          </span>
        );

        return (
          <div className="ds-doc-row" key={`${l.kind}-${l.contract_id || l.type}-${i}`}>
            {!note && l.contract_id && linkTo ? linkTo(l.contract_id, chip) : chip}
            {dates && <span className="ds-doc-dates">{dates}</span>}
          </div>
        );
      })}
    </div>
  );
}

/* Full-width "Paused — Returned to Service" ribbon for headers & drawers.
   Renders nothing unless the vehicle is actually paused, so it can be
   dropped in unconditionally. */
export function PausedRibbon({ vehicle = {}, reason }) {
  const { t } = useI18n();
  const m = maintenanceDimension(vehicle);
  if (!m.paused) return null;
  const crit = isCritical(vehicle.fault_severity || vehicle.severity)
    || ['red', 'yellow'].includes(String(vehicle.condition_grade || '').toLowerCase());
  const detail = reason || vehicle.deferred_maintenance_reason
    || vehicle.finding || vehicle.findings?.[0]?.text
    || t('Repair on hold — the vehicle is available for rental and will resume from its exact previous stage.');
  return (
    <div className={`ds-ribbon ${crit ? 'crit' : ''}`}>
      <span className="ds-ribbon-ic">⏸</span>
      <div className="ds-ribbon-tx">
        <b>{t('Maintenance Paused · Returned to Service')}</b>
        <span>{detail}</span>
      </div>
      <span className="ds-ribbon-tag">{crit ? t('Critical') : t('On hold')}</span>
    </div>
  );
}
