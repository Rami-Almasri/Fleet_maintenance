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
};

export function OperationalBadge({ status }) {
  const m = OPERATIONAL[status];
  if (!m) return null;
  return <Badge tone={m.tone}>{m.label}</Badge>;
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
