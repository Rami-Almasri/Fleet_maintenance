// Shared vocabulary + API helpers for the Vehicle Inspection Workflow.
// Keep these enums in lock-step with the backend (App\Models\InspectionRecord).

import api from '../api/client';

// Damage classifications shown when flagging a zone. `id` is what persists.
export const DAMAGE_TYPES = [
  { id: 'scratch', label: 'Scratch' },
  { id: 'dent', label: 'Dent' },
  { id: 'glass_crack', label: 'Glass Crack' },
  { id: 'other', label: 'Other' },
];

// Three-level severity scale. `tone` drives the chips; `diagram` colours the zone.
export const SEVERITIES = [
  {
    id: 'low',
    label: 'Low',
    sub: 'Minor',
    tone: 'border-amber-300 bg-amber-50 text-amber-700',
    toneActive: 'border-amber-400 bg-amber-400 text-white',
    diagram: { fill: 'fill-amber-100', stroke: 'stroke-amber-400', dot: 'fill-amber-400' },
  },
  {
    id: 'medium',
    label: 'Medium',
    sub: 'Needs Repair',
    tone: 'border-orange-300 bg-orange-50 text-orange-700',
    toneActive: 'border-orange-500 bg-orange-500 text-white',
    diagram: { fill: 'fill-orange-100', stroke: 'stroke-orange-500', dot: 'fill-orange-500' },
  },
  {
    id: 'high',
    label: 'High',
    sub: 'Major / Unsafe',
    tone: 'border-rose-300 bg-rose-50 text-rose-700',
    toneActive: 'border-rose-600 bg-rose-600 text-white',
    diagram: { fill: 'fill-rose-100', stroke: 'stroke-rose-500', dot: 'fill-rose-500' },
  },
];

export const damageTypeLabel = (id) => DAMAGE_TYPES.find((t) => t.id === id)?.label || id || '—';
export const severityMeta = (id) => SEVERITIES.find((s) => s.id === id) || SEVERITIES[0];

// ── Damage status — the "dispute-killer" dimension ────────────────────────────
// A flag moves through three states:
//   existing → pre-existing at delivery; documented so it can NEVER be charged later.
//   new      → found at return; not in the delivery set; "Needs Assessment".
//   charged  → assessed & linked to an invoice; `invoiceId` is the proof.
// `invoiceId` wins: once a flag is linked to an invoice it reads as Charged
// regardless of origin.
export function damageStatus(damage) {
  if (!damage) return null;
  if (damage.invoiceId) return 'charged';
  return damage.origin === 'existing' ? 'existing' : 'new';
}

export const DAMAGE_STATUSES = [
  { id: 'existing', label: 'Existing',       sub: 'Pre-existing at delivery', tone: 'border-slate-300 bg-slate-100 text-slate-600', toneActive: 'border-slate-500 bg-slate-600 text-white',  ring: 'bg-slate-100 text-slate-600 ring-slate-200',  dot: 'bg-slate-400' },
  { id: 'new',      label: 'New',            sub: 'Needs assessment',         tone: 'border-amber-300 bg-amber-50 text-amber-700',  toneActive: 'border-amber-500 bg-amber-500 text-white',  ring: 'bg-amber-50 text-amber-700 ring-amber-200',   dot: 'bg-amber-500' },
  { id: 'charged',  label: 'Charged',        sub: 'Linked to invoice',        tone: 'border-rose-300 bg-rose-50 text-rose-700',     toneActive: 'border-rose-600 bg-rose-600 text-white',    ring: 'bg-rose-50 text-rose-700 ring-rose-200',      dot: 'bg-rose-500' },
];
export const damageStatusMeta = (id) => DAMAGE_STATUSES.find((s) => s.id === id) || DAMAGE_STATUSES[1];

// ── Fuel audit ────────────────────────────────────────────────────────────────
// Fuel level is recorded as a 0–100% reading that snaps to the eighths a physical
// dashboard gauge actually reads (E, ⅛ … ⅞, F). The defaults below are tunable;
// a full wiring would pull `tankCapacityL` from the vehicle and `pricePerL` from
// settings. The AED `charge` is a computed money figure, so the UI gates it behind
// SHOW_FINANCIALS — the shortage %/litres stay visible as operational facts.
export const FUEL = {
  tankCapacityL: 60,   // assumed usable tank size (litres)
  pricePerL: 2.99,     // pump price (AED / litre)
  currency: 'AED',
  steps: 8,            // gauge resolution — eighths
  tolerancePct: 0,     // ignore shortages at/below this (gauge slop), in %
};

const gcd = (a, b) => (b ? gcd(b, a % b) : a);

// Snap a raw 0–100 value to the nearest gauge eighth.
export function snapFuel(pct, steps = FUEL.steps) {
  const clamped = Math.max(0, Math.min(100, Number(pct) || 0));
  return Math.round((clamped / 100) * steps) / steps * 100;
}

// Human fraction for a snapped level: 0→"E", 100→"F", else reduced "n/8".
export function fuelFraction(pct, steps = FUEL.steps) {
  const eighths = Math.round((Number(pct) || 0) / 100 * steps);
  if (eighths <= 0) return 'E';
  if (eighths >= steps) return 'F';
  const g = gcd(eighths, steps);
  return `${eighths / g}/${steps / g}`;
}

export const FUEL_STATUSES = {
  balanced: { id: 'balanced', label: 'Balanced', ring: 'bg-emerald-50 text-emerald-700 ring-emerald-200', dot: 'bg-emerald-500' },
  shortage: { id: 'shortage', label: 'Shortage', ring: 'bg-rose-50 text-rose-700 ring-rose-200',          dot: 'bg-rose-500' },
};
export const fuelStatusMeta = (id) => FUEL_STATUSES[id] || FUEL_STATUSES.balanced;

/**
 * Compare delivery vs return fuel and derive the audit + any Fuel Charge.
 * Returns null when either reading is missing (nothing to audit yet).
 *   { delivered, returned, shortagePct, litresShort, charge, status, surplus }
 * A shortage exists only when the return reading is below delivery by more than
 * the tolerance; `charge` is missing-litres × pump price (AED, rounded to fils).
 */
export function computeFuelAudit(deliveryLevel, returnLevel, opts = {}) {
  if (deliveryLevel == null || returnLevel == null) return null;
  const {
    tankCapacityL = FUEL.tankCapacityL,
    pricePerL = FUEL.pricePerL,
    tolerancePct = FUEL.tolerancePct,
  } = opts;

  const delivered = snapFuel(deliveryLevel);
  const returned = snapFuel(returnLevel);
  const shortagePct = Math.max(0, delivered - returned);
  const isShort = shortagePct > tolerancePct;
  const litresShort = Math.round(tankCapacityL * (shortagePct / 100) * 100) / 100;
  const charge = Math.round(litresShort * pricePerL * 100) / 100;

  return {
    delivered,
    returned,
    shortagePct,
    litresShort,
    charge,
    status: isShort ? 'shortage' : 'balanced',
    surplus: returned > delivered, // came back fuller — never a charge
  };
}

// ── API helpers ──────────────────────────────────────────────────────────────
// Flow: compress (lib/imageCompression) → presign → PUT blob straight to S3 → save
// the metadata row. The S3 PUT deliberately uses fetch (not the api client) so the
// Sanctum bearer token is NOT sent to the storage host.

export async function presignInspection({ contractId, vehicleId, phase, bodyPart, contentType, extension }) {
  const res = await api.post('/Inspections/presign', {
    contract_id: contractId ?? null,
    vehicle_id: vehicleId ?? null,
    phase,
    body_part: bodyPart,
    content_type: contentType,
    extension,
  });
  return res.data.data; // { disk, key, upload_url, headers, expires_in }
}

export async function putToS3(uploadUrl, blob, headers = {}) {
  const res = await fetch(uploadUrl, { method: 'PUT', body: blob, headers });
  if (!res.ok) throw new Error(`S3 upload failed (${res.status})`);
  return true;
}

export async function saveInspection(record) {
  const res = await api.post('/Inspections', record);
  return res.data.data; // InspectionRecordResource
}

export async function listInspections({ contractId, vehicleId } = {}) {
  const res = await api.get('/Inspections', {
    params: { contract_id: contractId, vehicle_id: vehicleId },
  });
  return res.data.data;
}

export async function deleteInspection(id) {
  await api.delete(`/Inspections/${id}`);
  return true;
}

/**
 * End-to-end capture: compress already done by caller; this presigns, uploads, and
 * persists the metadata row, returning the saved record. `damage` is the optional
 * { type, severity, note } flag for the zone.
 */
export async function uploadInspectionPhoto({ compressed, contractId, vehicleId, phase, bodyPart, damage }) {
  const ext = (compressed.blob.type.split('/')[1] || 'jpg').replace('jpeg', 'jpg');
  const { disk, key, upload_url, headers } = await presignInspection({
    contractId,
    vehicleId,
    phase,
    bodyPart,
    contentType: compressed.blob.type || 'image/jpeg',
    extension: ext,
  });
  await putToS3(upload_url, compressed.blob, headers);
  return saveInspection({
    contract_id: contractId ?? null,
    vehicle_id: vehicleId ?? null,
    phase,
    body_part: bodyPart,
    s3_disk: disk,
    s3_key: key,
    mime_type: compressed.blob.type || 'image/jpeg',
    file_size: compressed.compressedSize,
    width: compressed.width,
    height: compressed.height,
    captured_at: new Date().toISOString(),
    damage_flagged: !!damage,
    damage_type: damage?.type ?? null,
    severity: damage?.severity ?? null,
    note: damage?.note ?? null,
  });
}
