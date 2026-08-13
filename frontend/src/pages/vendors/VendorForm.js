import { Input, Select } from '../../components/ui/Field';
import { useI18n } from '../../i18n/I18nContext';

export const VENDOR_TYPES = ['garage', 'parts_supplier', 'insurance', 'service_center', 'fuel_station', 'other'];

// Display wording for each stored vendor type. The VALUE sent to the API never changes.
const VENDOR_TYPE_LABEL = {
  garage: 'Garage',
  parts_supplier: 'Parts supplier',
  insurance: 'Insurance',
  service_center: 'Service center',
  fuel_station: 'Fuel station',
  other: 'Other',
};

function Section({ title, children }) {
  return (
    <div>
      <h4 className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">{title}</h4>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">{children}</div>
    </div>
  );
}

export default function VendorForm({ values, onChange, errors = {} }) {
  const { t } = useI18n();
  const set = (field) => (e) => onChange(field, e.target.value);
  const err = (f) => (errors[f] ? errors[f][0] : '');

  return (
    <div className="space-y-6">
      <Section title={t('Vendor')}>
        <Input label={t('Name')} required value={values.name || ''} onChange={set('name')} error={err('name')} placeholder="Al Futtaim Garage" />
        <Select label={t('Type')} required value={values.type || 'garage'} onChange={set('type')} error={err('type')}>
          {VENDOR_TYPES.map((v) => (
            <option key={v} value={v}>{t(VENDOR_TYPE_LABEL[v] || v)}</option>
          ))}
        </Select>
        <Input label={t('Phone')} value={values.phone || ''} onChange={set('phone')} error={err('phone')} />
        <Input label={t('Email')} type="email" value={values.email || ''} onChange={set('email')} error={err('email')} />
        <Input label={t('Rating (0–5)')} type="number" step="0.1" min="0" max="5" value={values.rating ?? ''} onChange={set('rating')} error={err('rating')} />
        <Select label={t('Status')} value={values.active ?? '1'} onChange={set('active')} error={err('active')}>
          <option value="1">{t('Active')}</option>
          <option value="0">{t('Inactive')}</option>
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
