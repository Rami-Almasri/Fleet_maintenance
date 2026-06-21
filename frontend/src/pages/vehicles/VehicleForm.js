import { Input, Select, Textarea } from '../../components/ui/Field';

export const VEHICLE_STATUSES = [
  'ready', 'rented', 'under_maintenance', 'out_of_order',
  'suspended', 'office_use', 'returned', 'disposed', 'sold',
];
const SOURCES = ['new', 'used', 'auction', 'accident'];

function Section({ title, children }) {
  return (
    <div>
      <h4 className="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-400">{title}</h4>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">{children}</div>
    </div>
  );
}

export default function VehicleForm({ values, onChange, errors = {} }) {
  const set = (field) => (e) => onChange(field, e.target.value);
  const err = (f) => (errors[f] ? errors[f][0] : '');

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
        <Input label="Year" type="number" value={values.year || ''} onChange={set('year')} error={err('year')} placeholder="2025" />
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
        <Input label="Odometer (km)" type="number" value={values.odometer ?? ''} onChange={set('odometer')} error={err('odometer')} />
        <Input label="Engine Hours" type="number" step="0.1" value={values.engine_hours ?? ''} onChange={set('engine_hours')} error={err('engine_hours')} />
      </Section>

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
  };
}

// Drops empty strings so we don't send "" for nullable fields.
export function cleanPayload(values) {
  const out = {};
  Object.entries(values).forEach(([k, v]) => {
    if (v !== '' && v !== null && v !== undefined) out[k] = v;
  });
  return out;
}
