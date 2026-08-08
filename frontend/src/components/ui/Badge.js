import { useState } from 'react';
import { useI18n } from '../../i18n/I18nContext';

// LOCALIZATION — every badge below is a fixed vocabulary, so the English stays here beside the
// status code it labels and the Arabic lives in labels.js under `ar.badge.*`, resolved with tf().

const TONES = {
  gray: 'bg-slate-100 text-slate-600 ring-slate-500/20',
  green: 'bg-emerald-100 text-emerald-700 ring-emerald-600/20',
  emerald: 'bg-emerald-100 text-emerald-700 ring-emerald-600/20',
  red: 'bg-red-100 text-red-700 ring-red-600/20',
  amber: 'bg-amber-100 text-amber-700 ring-amber-600/20',
  blue: 'bg-blue-100 text-blue-700 ring-blue-600/20',
  violet: 'bg-violet-100 text-violet-700 ring-violet-600/20',
  cyan: 'bg-cyan-100 text-cyan-700 ring-cyan-600/20',
  slate: 'bg-slate-100 text-slate-600 ring-slate-500/20',
  indigo: 'bg-indigo-100 text-indigo-700 ring-indigo-600/20',
  orange: 'bg-orange-100 text-orange-700 ring-orange-600/20',
  yellow: 'bg-yellow-100 text-yellow-800 ring-yellow-600/20',
};

// Solid dot colour per tone — the enterprise status affordance that replaces the
// old emoji. Rendered in the tone's own hue so the chip reads at a glance.
const DOTS = {
  gray: 'bg-slate-400', slate: 'bg-slate-400', green: 'bg-emerald-500', emerald: 'bg-emerald-500',
  red: 'bg-red-500', amber: 'bg-amber-500', blue: 'bg-blue-500', violet: 'bg-violet-500',
  cyan: 'bg-cyan-500', indigo: 'bg-indigo-500', orange: 'bg-orange-500', yellow: 'bg-yellow-500',
};

export default function Badge({ tone = 'gray', dot = false, children, className = '' }) {
  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ring-1 ring-inset ${TONES[tone] || TONES.gray} ${className}`}
    >
      {dot && <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${DOTS[tone] || DOTS.gray}`} />}
      {children}
    </span>
  );
}

// Maps a vehicle `status` (OfficeManager AssetStatusNo) to a tone.
const VEHICLE_STATUS_TONE = {
  ready: 'green',
  rented: 'blue',
  under_maintenance: 'amber',
  out_of_order: 'red',
  suspended: 'amber',
  office_use: 'cyan',
  returned: 'violet',
  disposed: 'gray',
  sold: 'gray',
};

export function VehicleStatusBadge({ status }) {
  const { tf } = useI18n();
  const key = status || 'unknown';
  return (
    <Badge tone={VEHICLE_STATUS_TONE[status] || 'gray'}>
      {tf(`badge.vehicleStatus.${key}`, key.replace(/_/g, ' '))}
    </Badge>
  );
}

// The car's CURRENT movement (operational_status), derived from its open contracts —
// distinct from the OM lifecycle `status`. This is the single live-state badge.
const OPERATIONAL = {
  available:   { tone: 'green',  label: 'Available' },
  rented:      { tone: 'blue',   label: 'Rented' },
  maintenance: { tone: 'amber',  label: 'In maintenance' },
  test:        { tone: 'cyan',   label: 'Test drive' },
  transfer:    { tone: 'indigo', label: 'Transfer' },
  sale_prep:   { tone: 'slate',  label: 'Sale prep' },
  in_transit:  { tone: 'violet', label: 'In transit' },
};

// `destination` is only used for in_transit, to spell out "In transit → Deals on Wheels".
export function OperationalBadge({ status, destination }) {
  const { tf } = useI18n();
  const m = OPERATIONAL[status];
  if (!m) return null;
  // The destination is a garage/branch name — data, so it is never translated, only the frame is.
  const label = status === 'in_transit' && destination
    ? tf('badge.operational.inTransitTo', `In transit → ${destination}`, { destination })
    : tf(`badge.operational.${status}`, m.label);
  return <Badge tone={m.tone} dot>{label}</Badge>;
}

// A STANDING safety warning for cars that must not be treated as rentable
// (left the fleet or flagged). Shown permanently ALONGSIDE the live status —
// never replaced by it — so staff can't mistakenly rent a sold/suspended car.
const VEHICLE_WARNING = {
  sold:         { tone: 'red',   label: 'Sold' },
  disposed:     { tone: 'red',   label: 'Disposed' },
  returned:     { tone: 'red',   label: 'Returned' },
  out_of_order: { tone: 'red',   label: 'Out of order' },
  suspended:    { tone: 'amber', label: 'Suspended' },
};

export function VehicleWarningTag({ status }) {
  const { tf } = useI18n();
  const m = VEHICLE_WARNING[status];
  if (!m) return null;
  return (
    <Badge tone={m.tone} dot className="font-semibold">
      {tf(`badge.vehicleWarning.${status}`, m.label)}
    </Badge>
  );
}

// Deferred Maintenance — a standing warning for a car that was pulled out of the workshop
// early to satisfy a customer and still "owes" the garage a visit. Shown ALONGSIDE the live
// status the whole time it's out, so it can't be silently re-rented and forgotten.
export function DeferredMaintenanceBadge({ pending, note }) {
  const { tf } = useI18n();
  if (!pending) return null;
  // `note` is whatever ops typed when they released the car — data, kept verbatim.
  const title = note
    ? tf('badge.deferred.titleWithNote', `Owes maintenance — ${note}`, { note })
    : tf('badge.deferred.title', 'Pulled from the workshop for a customer — must go back to the garage once it returns.');
  return (
    <span title={title}>
      <Badge tone="red" dot className="whitespace-nowrap font-semibold normal-case">
        {tf('badge.deferred.label', 'Owes maintenance')}
      </Badge>
    </span>
  );
}

// Visual Condition Grade (Abu Marouf) — a manual cosmetic/condition assessment, kept
// separate from the OM lifecycle status and the live movement:
//   Green  = perfect (fully available)
//   Orange = cosmetic / serviceable (still rentable — warn the customer at handover)
//   Yellow = maintenance needed (blocked from renting, hidden from Available — route to garage)
//   Red    = critical / grounded (blocked from renting, hidden from Available)
export const CONDITION_GRADE = {
  green:  { tone: 'green',  label: 'Perfect',             icon: '✅', dot: 'bg-emerald-500' },
  orange: { tone: 'orange', label: 'Cosmetic issues',    icon: '⚠️', dot: 'bg-orange-500' },
  yellow: { tone: 'yellow', label: 'Maintenance needed', icon: '🔧', dot: 'bg-yellow-500' },
  red:    { tone: 'red',    label: 'Critical — grounded', icon: '⛔', dot: 'bg-red-500' },
};

// The condition badge. Perfect is hidden by default (no news is good news) unless
// `showGreen` is set, so the list only calls out cars that need attention.
export function ConditionBadge({ grade, showGreen = false }) {
  const { tf } = useI18n();
  const m = CONDITION_GRADE[grade];
  if (!m || (grade === 'green' && !showGreen)) return null;
  return (
    <Badge tone={m.tone} dot className="font-semibold">
      {tf(`badge.conditionGrade.${grade}`, m.label)}
    </Badge>
  );
}

// A tiny condition dot for dense grids (the dashboard Fleet Pulse). Hidden for Perfect.
export function ConditionDot({ grade, className = '' }) {
  const { tf } = useI18n();
  const m = CONDITION_GRADE[grade];
  if (!m || grade === 'green') return null;
  return (
    <span
      className={`inline-block h-2 w-2 rounded-full ${m.dot} ${className}`}
      title={tf(`badge.conditionGrade.${grade}`, m.label)}
    />
  );
}

// A tiny "OM" toggle that reveals the stored OfficeManager lifecycle status on
// click, so staff can compare it against the live (contract-based) status. The
// OM status is always stored & synced — just hidden until requested.
export function OmStatusButton({ status }) {
  const { tf } = useI18n();
  const [show, setShow] = useState(false);
  if (!status) return null;
  return (
    <span className="inline-flex items-center gap-1.5">
      <button
        type="button"
        onClick={(e) => { e.preventDefault(); e.stopPropagation(); setShow((s) => !s); }}
        className="rounded-lg border border-slate-200 bg-white px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-500 transition hover:bg-slate-50"
        title={tf('badge.omStatus.tip', 'Show the OfficeManager status to compare')}
      >
        OM
      </button>
      {show && <VehicleStatusBadge status={status} />}
    </span>
  );
}

// Maps a contract `state` to a tone.
const CONTRACT_STATE_TONE = {
  open: 'green',
  closed: 'gray',
  draft: 'amber',
  cancelled: 'red',
};

export function ContractStateBadge({ state }) {
  const { tf } = useI18n();
  return (
    <Badge tone={CONTRACT_STATE_TONE[state] || 'blue'}>
      {state ? tf(`badge.contractState.${state}`, state) : '—'}
    </Badge>
  );
}

// The real contract classification lives in the `contract_type` code letter.
// There are exactly three contract types:
//   C = Rental · U = Maintenance · R = Booking (car reserved + deposit paid)
export const CONTRACT_TYPE = {
  C: { label: 'Rental', tone: 'blue' },
  U: { label: 'Maintenance', tone: 'amber' },
  R: { label: 'Booking', tone: 'violet' },
};

export function ContractTypeBadge({ type }) {
  const { tf } = useI18n();
  const m = CONTRACT_TYPE[type];
  if (!m) return <Badge tone="gray">{type || '—'}</Badge>;
  return <Badge tone={m.tone}>{tf(`badge.contractType.${type}`, m.label)}</Badge>;
}
