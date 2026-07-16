import { Input, Select, Textarea } from '../../components/ui/Field';

export const VEHICLE_STATUSES = [
  'ready', 'rented', 'under_maintenance', 'out_of_order',
  'suspended', 'office_use', 'returned', 'disposed', 'sold',
];
const SOURCES = ['new', 'used', 'auction', 'accident'];

// Odometer Modification Approval — a change bigger than this (km, either direction) from the car's
// current reading needs a mandatory reason note and goes to admin review instead of applying right
// away. MUST stay in step with OdometerChangeRequest::SIGNIFICANT_DELTA_KM (backend).
export const SIGNIFICANT_ODOMETER_DELTA_KM = 10;

export function isSignificantOdometerChange(original, next) {
  if (original == null || next === '' || next == null) return false;
  const o = Number(original);
  const n = Number(next);
  if (!Number.isFinite(o) || !Number.isFinite(n)) return false;
  return Math.abs(n - o) > SIGNIFICANT_ODOMETER_DELTA_KM;
}

function Section({ title, children }) {
  return (
    <div>
      <h4 className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">{title}</h4>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">{children}</div>
    </div>
  );
}

export default function VehicleForm({ values, onChange, errors = {} }) {
  const set = (field) => (e) => onChange(field, e.target.value);
  const err = (f) => (errors[f] ? errors[f][0] : '');

  const needsOdometerNote = isSignificantOdometerChange(values._original_odometer, values.odometer);

  return (
    <div className="space-y-6">
      <Section title="Identification">
        <Input label="VIN / Chassis No." required value={values.vin || ''} onChange={set('vin')} error={err('vin')} placeholder="WAUZZZ..." />
        <Input label="Plate No." value={values.plate_no || ''} onChange={set('plate_no')} error={err('plate_no')} placeholder="DD 78126" />
        <Input label="Code" value={values.code || ''} onChange={set('code')} error={err('code')} />
        <Input label="Category" value={values.category || ''} onChange={set('category')} error={err('category')} placeholder="Premium Sedan" />
      </Section>

      <Section title="Details">
        <Input label="Make" value={values.make || ''} onChange={set('make')} error={err('make')} placeholder="AUDI" />
        <Input label="Model" value={values.model || ''} onChange={set('model')} error={err('model')} placeholder="A5" />
        <Input label="Year" type="number" min={1950} max={new Date().getFullYear() + 1} value={values.year || ''} onChange={set('year')} error={err('year')} placeholder="2025" />
        <Input label="Color" value={values.color || ''} onChange={set('color')} error={err('color')} placeholder="Black" />
      </Section>

      <Section title="Status & Usage">
        <Select label="Status" value={values.status || 'ready'} onChange={set('status')} error={err('status')}>
          {VEHICLE_STATUSES.map((s) => (
            <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>
          ))}
        </Select>
        <Select label="For Sale" value={values.for_sale ?? '0'} onChange={set('for_sale')} error={err('for_sale')}>
          <option value="0">No</option>
          <option value="1">Yes</option>
        </Select>
        <Select label="Source" value={values.source || ''} onChange={set('source')} error={err('source')}>
          <option value="">—</option>
          {SOURCES.map((s) => (
            <option key={s} value={s}>{s}</option>
          ))}
        </Select>
        <Input label="Odometer (km)" type="number" min="0" value={values.odometer ?? ''} onChange={set('odometer')} error={err('odometer')} />
        <Input label="Engine Hours" type="number" min="0" step="0.1" value={values.engine_hours ?? ''} onChange={set('engine_hours')} error={err('engine_hours')} />
      </Section>

      {needsOdometerNote && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4">
          <p className="text-sm font-medium text-amber-800">
            ⚠️ This is a significant odometer change (more than {SIGNIFICANT_ODOMETER_DELTA_KM} km from{' '}
            {Number(values._original_odometer).toLocaleString()} km).
          </p>
          <p className="mt-1 text-xs text-amber-700">
            It won't apply immediately — it will be sent to an admin for approval, along with the note below.
          </p>
          <div className="mt-3">
            <Textarea
              label="Reason for odometer change"
              required
              value={values.odometer_change_note || ''}
              onChange={set('odometer_change_note')}
              error={err('odometer_change_note')}
              placeholder="e.g. corrected a mis-typed reading from the last handover, dial was misread, etc."
            />
          </div>
        </div>
      )}

      <Section title="Financial & Warranty">
        <Input label="Purchase Price" type="number" step="0.01" value={values.purchase_price ?? ''} onChange={set('purchase_price')} error={err('purchase_price')} />
        <Input label="Purchase Date" type="date" value={values.purchase_date || ''} onChange={set('purchase_date')} error={err('purchase_date')} />
        <Input label="Warranty End Date" type="date" value={values.warranty_end_date || ''} onChange={set('warranty_end_date')} error={err('warranty_end_date')} />
        <Input label="Warranty End (km)" type="number" value={values.warranty_end_km ?? ''} onChange={set('warranty_end_km')} error={err('warranty_end_km')} />
      </Section>

      <Textarea label="Notes" value={values.notes || ''} onChange={set('notes')} error={err('notes')} />
    </div>
  );
}

// Trims a vehicle resource down to the editable fields (dates -> yyyy-mm-dd for <input type=date>).
export function vehicleToForm(v) {
  const d = (x) => (x ? String(x).slice(0, 10) : '');
  return {
    vin: v?.vin || '', plate_no: v?.plate_no || '', code: v?.code || '', category: v?.category || '',
    make: v?.make || '', model: v?.model || '', year: v?.year || '', color: v?.color || '',
    status: v?.status || 'ready', for_sale: v?.for_sale ? '1' : '0', source: v?.source || '',
    odometer: v?.odometer ?? '', engine_hours: v?.engine_hours ?? '',
    purchase_price: v?.purchase_price ?? '', purchase_date: d(v?.purchase_date),
    warranty_end_date: d(v?.warranty_end_date), warranty_end_km: v?.warranty_end_km ?? '',
    notes: v?.notes || '',
    // Not submitted — the odometer as it stood when the form opened, so the UI can tell whether an
    // edit is a "significant" change that needs a reason note (see isSignificantOdometerChange).
    _original_odometer: v?.odometer ?? null,
    odometer_change_note: '',
  };
}

// Drops empty strings so we don't send "" for nullable fields, and internal (_-prefixed) UI-only fields.
export function cleanPayload(values) {
  const out = {};
  Object.entries(values).forEach(([k, v]) => {
    if (k.startsWith('_')) return;
    if (v !== '' && v !== null && v !== undefined) out[k] = v;
  });
  return out;
}
