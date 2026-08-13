import { Input, Select } from '../../components/ui/Field';
import { useI18n } from '../../i18n/I18nContext';

export const DRIVER_STATUSES = ['active', 'suspended'];

function Section({ title, children }) {
  return (
    <div>
      <h4 className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">{title}</h4>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">{children}</div>
    </div>
  );
}

export default function DriverForm({ values, onChange, errors = {} }) {
  const { t } = useI18n();
  const set = (field) => (e) => onChange(field, e.target.value);
  const err = (f) => (errors[f] ? errors[f][0] : '');
  // The stored value stays the API enum; only the word the user reads is translated.
  const statusLabel = (s) => (s === 'active' ? t('Active') : t('Suspended'));

  return (
    <div className="space-y-6">
      <Section title={t('Identity')}>
        <Input label={t('Full Name')} required value={values.name || ''} onChange={set('name')} error={err('name')} placeholder={t('Ahmed Khan')} />
        <Input label={t('Phone')} value={values.phone || ''} onChange={set('phone')} error={err('phone')} placeholder={t('05x xxx xxxx')} />
      </Section>

      <Section title={t('Licence & Status')}>
        <Input label={t('Licence No.')} value={values.license_no || ''} onChange={set('license_no')} error={err('license_no')} />
        <Input label={t('Licence Expiry')} type="date" value={values.license_expiry || ''} onChange={set('license_expiry')} error={err('license_expiry')} />
        <Select label={t('Status')} value={values.status || 'active'} onChange={set('status')} error={err('status')}>
          {DRIVER_STATUSES.map((s) => (
            <option key={s} value={s}>{statusLabel(s)}</option>
          ))}
        </Select>
      </Section>
    </div>
  );
}

// Trims a driver resource down to the editable fields (dates -> yyyy-mm-dd for <input type=date>).
export function driverToForm(d) {
  const date = (x) => (x ? String(x).slice(0, 10) : '');
  return {
    name: d?.name || '',
    phone: d?.phone || '',
    license_no: d?.license_no || '',
    license_expiry: date(d?.license_expiry),
    status: d?.status || 'active',
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
