import { useState } from 'react';

const TONES = {
  gray: 'bg-gray-100 text-gray-600 ring-gray-500/20',
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

export default function Badge({ tone = 'gray', children, className = '' }) {
  return (
    <span
      className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ring-1 ring-inset ${TONES[tone] || TONES.gray} ${className}`}
    >
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
  return <Badge tone={VEHICLE_STATUS_TONE[status] || 'gray'}>{(status || 'unknown').replace(/_/g, ' ')}</Badge>;
}

// The car's CURRENT movement (operational_status), derived from its open contracts —
// distinct from the OM lifecycle `status`. This is the single live-state badge.
const OPERATIONAL = {
  available:   { tone: 'green',  label: '✅ Available' },
  rented:      { tone: 'blue',   label: '🔑 Rented' },
  maintenance: { tone: 'amber',  label: '🔧 In maintenance' },
  test:        { tone: 'cyan',   label: '🧪 Test drive' },
  transfer:    { tone: 'indigo', label: '🚚 Transfer' },
  sale_prep:   { tone: 'slate',  label: '🏷️ Sale prep' },
  in_transit:  { tone: 'violet', label: '🚚 In transit' },
};

// `destination` is only used for in_transit, to spell out "In transit → Deals on Wheels".
export function OperationalBadge({ status, destination }) {
  const m = OPERATIONAL[status];
  if (!m) return null;
  const label = status === 'in_transit' && destination ? `🚚 In transit → ${destination}` : m.label;
  return <Badge tone={m.tone}>{label}</Badge>;
}

// A STANDING safety warning for cars that must not be treated as rentable
// (left the fleet or flagged). Shown permanently ALONGSIDE the live status —
// never replaced by it — so staff can't mistakenly rent a sold/suspended car.
const VEHICLE_WARNING = {
  sold:         { tone: 'red',   label: '⛔ Sold' },
  disposed:     { tone: 'red',   label: '⛔ Disposed' },
  returned:     { tone: 'red',   label: '⛔ Returned' },
  out_of_order: { tone: 'red',   label: '⛔ Out of order' },
  suspended:    { tone: 'amber', label: '⚠️ Suspended' },
};

export function VehicleWarningTag({ status }) {
  const m = VEHICLE_WARNING[status];
  if (!m) return null;
  return <Badge tone={m.tone} className="font-semibold">{m.label}</Badge>;
}

// Deferred Maintenance — a standing warning for a car that was pulled out of the workshop
// early to satisfy a customer and still "owes" the garage a visit. Shown ALONGSIDE the live
// status the whole time it's out, so it can't be silently re-rented and forgotten.
export function DeferredMaintenanceBadge({ pending, note }) {
  if (!pending) return null;
  return (
    <span title={note ? `Owes maintenance — ${note}` : 'Pulled from the workshop for a customer — must go back to the garage once it returns.'}>
      <Badge tone="red" className="whitespace-nowrap font-semibold normal-case">🛠️↩️ Owes maintenance</Badge>
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
  const m = CONDITION_GRADE[grade];
  if (!m || (grade === 'green' && !showGreen)) return null;
  return <Badge tone={m.tone} className="font-semibold">{m.icon} {m.label}</Badge>;
}

// A tiny condition dot for dense grids (the dashboard Fleet Pulse). Hidden for Perfect.
export function ConditionDot({ grade, className = '' }) {
  const m = CONDITION_GRADE[grade];
  if (!m || grade === 'green') return null;
  return <span className={`inline-block h-2 w-2 rounded-full ${m.dot} ${className}`} title={m.label} />;
}

// A tiny "OM" toggle that reveals the stored OfficeManager lifecycle status on
// click, so staff can compare it against the live (contract-based) status. The
// OM status is always stored & synced — just hidden until requested.
export function OmStatusButton({ status }) {
  const [show, setShow] = useState(false);
  if (!status) return null;
  return (
    <span className="inline-flex items-center gap-1.5">
      <button
        type="button"
        onClick={(e) => { e.preventDefault(); e.stopPropagation(); setShow((s) => !s); }}
        className="rounded-md border border-gray-200 bg-white px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500 transition hover:bg-gray-50"
        title="Show the OfficeManager status to compare"
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
  return <Badge tone={CONTRACT_STATE_TONE[state] || 'blue'}>{state || '—'}</Badge>;
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
  const m = CONTRACT_TYPE[type];
  if (!m) return <Badge tone="gray">{type || '—'}</Badge>;
  return <Badge tone={m.tone}>{m.label}</Badge>;
}
