import { Input, Select, Textarea } from '../../components/ui/Field';
import { useI18n } from '../../i18n/I18nContext';

function Section({ title, children }) {
  return (
    <div>
      <h4 className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">{title}</h4>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">{children}</div>
    </div>
  );
}

export default function CustomerForm({ values, onChange, errors = {} }) {
  const { t } = useI18n();
  const set = (field) => (e) => onChange(field, e.target.value);
  const err = (f) => (errors[f] ? errors[f][0] : '');

  return (
    <div className="space-y-6">
      <Section title={t('Identity')}>
        <Input label={t('Name (EN)')} value={values.name_en || ''} onChange={set('name_en')} error={err('name_en')} placeholder="John Smith" />
        <Input label={t('Name (AR)')} value={values.name_ar || ''} onChange={set('name_ar')} error={err('name_ar')} dir="rtl" />
        <Input label={t('Customer No.')} value={values.customer_no || ''} onChange={set('customer_no')} error={err('customer_no')} />
        <Input label={t('Nationality')} value={values.nationality || ''} onChange={set('nationality')} error={err('nationality')} />
        <Select label={t('Sex')} value={values.sex || ''} onChange={set('sex')} error={err('sex')}>
          <option value="">—</option>
          <option value="male">{t('male')}</option>
          <option value="female">{t('female')}</option>
        </Select>
        <Input label={t('Date of Birth')} type="date" value={values.date_of_birth || ''} onChange={set('date_of_birth')} error={err('date_of_birth')} />
      </Section>

      <Section title={t('Contact')}>
        <Input label={t('Mobile 1')} value={values.mobile1 || ''} onChange={set('mobile1')} error={err('mobile1')} />
        <Input label={t('Mobile 2')} value={values.mobile2 || ''} onChange={set('mobile2')} error={err('mobile2')} />
        <Input label="WhatsApp" value={values.whatsapp || ''} onChange={set('whatsapp')} error={err('whatsapp')} />
        <Input label={t('Email')} type="email" value={values.email || ''} onChange={set('email')} error={err('email')} />
        <Input label={t('City')} value={values.city || ''} onChange={set('city')} error={err('city')} />
        <Input label={t('P.O. Box')} value={values.po_box || ''} onChange={set('po_box')} error={err('po_box')} />
        <Textarea label={t('Address')} className="sm:col-span-2" value={values.address || ''} onChange={set('address')} error={err('address')} rows={2} />
      </Section>

      <Section title={t('Documents')}>
        <Input label={t('Passport No.')} value={values.passport_no || ''} onChange={set('passport_no')} error={err('passport_no')} />
        <Input label={t('Passport Expiry')} type="date" value={values.passport_expiry || ''} onChange={set('passport_expiry')} error={err('passport_expiry')} />
        <Input label={t('Licence No.')} value={values.license_no || ''} onChange={set('license_no')} error={err('license_no')} />
        <Input label={t('Licence Expiry')} type="date" value={values.license_expiry || ''} onChange={set('license_expiry')} error={err('license_expiry')} />
        <Input label={t('Emirates ID No.')} value={values.id_no || ''} onChange={set('id_no')} error={err('id_no')} />
        <Input label={t('Emirates ID Expiry')} type="date" value={values.id_expiry || ''} onChange={set('id_expiry')} error={err('id_expiry')} />
        <Input label={t('Residency No.')} value={values.residency_no || ''} onChange={set('residency_no')} error={err('residency_no')} />
        <Input label={t('Residency Expiry')} type="date" value={values.residency_expiry || ''} onChange={set('residency_expiry')} error={err('residency_expiry')} />
        <Input label={t('Traffic File No.')} value={values.traffic_file_no || ''} onChange={set('traffic_file_no')} error={err('traffic_file_no')} />
        <Input label={t('VAT Number')} value={values.vat_number || ''} onChange={set('vat_number')} error={err('vat_number')} />
      </Section>
    </div>
  );
}

// Editable identity/contact/document fields (dates -> yyyy-mm-dd for <input type=date>).
// Financials (debit/credit/balance/deposit) are intentionally omitted — they're kept
// in sync with the customer's contracts by the backend ContractObserver.
export function customerToForm(c) {
  const date = (x) => (x ? String(x).slice(0, 10) : '');
  return {
    name_en: c?.name_en || '', name_ar: c?.name_ar || '', customer_no: c?.customer_no || '',
    nationality: c?.nationality || '', sex: c?.sex || '', date_of_birth: date(c?.date_of_birth),
    mobile1: c?.mobile1 || '', mobile2: c?.mobile2 || '', whatsapp: c?.whatsapp || '',
    email: c?.email || '', city: c?.city || '', po_box: c?.po_box || '', address: c?.address || '',
    passport_no: c?.passport_no || '', passport_expiry: date(c?.passport_expiry),
    license_no: c?.license_no || '', license_expiry: date(c?.license_expiry),
    id_no: c?.id_no || '', id_expiry: date(c?.id_expiry),
    residency_no: c?.residency_no || '', residency_expiry: date(c?.residency_expiry),
    traffic_file_no: c?.traffic_file_no || '', vat_number: c?.vat_number || '',
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
