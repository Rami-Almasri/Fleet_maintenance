import { Input, Select } from '../../components/ui/Field';

export const VENDOR_TYPES = ['garage', 'parts_supplier', 'insurance', 'service_center', 'fuel_station', 'other'];

function Section({ title, children }) {
  return (
    <div>
      <h4 className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">{title}</h4>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">{children}</div>
    </div>
  );
}

export default function VendorForm({ values, onChange, errors = {} }) {
  const set = (field) => (e) => onChange(field, e.target.value);
  const err = (f) => (errors[f] ? errors[f][0] : '');

  return (
    <div className="space-y-6">
      <Section title="Vendor">
        <Input label="Name" required value={values.name || ''} onChange={set('name')} error={err('name')} placeholder="Al Futtaim Garage" />
        <Select label="Type" required value={values.type || 'garage'} onChange={set('type')} error={err('type')}>
          {VENDOR_TYPES.map((t) => (
            <option key={t} value={t}>{t.replace(/_/g, ' ')}</option>
          ))}
        </Select>
        <Input label="Phone" value={values.phone || ''} onChange={set('phone')} error={err('phone')} />
        <Input label="Email" type="email" value={values.email || ''} onChange={set('email')} error={err('email')} />
        <Input label="Rating (0–5)" type="number" step="0.1" min="0" max="5" value={values.rating ?? ''} onChange={set('rating')} error={err('rating')} />
        <Select label="Status" value={values.active ?? '1'} onChange={set('active')} error={err('active')}>
          <option value="1">Active</option>
          <option value="0">Inactive</option>
        </Select>
      </Section>
    </div>
  );
}

// Trims a vendor resource down to the editable fields (notes is read-only / sheet-sourced).
export function vendorToForm(v) {
  return {
    name: v?.name || '',
    type: v?.type || 'garage',
    phone: v?.phone || '',
    email: v?.email || '',
    rating: v?.rating ?? '',
    // new vendors default to active; existing ones keep their flag
    active: v ? (v.active ? '1' : '0') : '1',
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
