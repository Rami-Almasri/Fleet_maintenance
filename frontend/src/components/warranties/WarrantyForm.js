import { useEffect, useMemo, useState } from 'react';
import api from '../../api/client';
import Button from '../ui/Button';
import Modal from '../ui/Modal';
import { Input, Select, Textarea } from '../ui/Field';
import { useToast } from '../ui/Toast';
import { useI18n } from '../../i18n/I18nContext';

/**
 * Record or correct a warranty.
 *
 * TWO KINDS, DIFFERENT ANCHORS, and the form does not let them blur. A part warranty is owed by the
 * SUPPLIER and must point at the fitted component or the purchase; a repair warranty is owed by the
 * GARAGE and must point at the FAULT, which is the only thing that makes a comeback provable later.
 * The backend refuses a warranty with the wrong anchor for its kind — this form asks for the right
 * one instead of letting someone find out on submit.
 *
 * On EDIT the kind, the car and the anchor are all locked. Re-pointing a warranty at a different
 * part is not a correction: it silently rewrites the history of whatever it used to cover, and
 * leaves that thing uncovered. The backend strips them too; the form simply does not offer them.
 */
export default function WarrantyForm({ warranty, onClose, onSaved }) {
  const { t, lang } = useI18n();
  const toast = useToast();
  const editing = !!warranty;

  const [form, setForm] = useState(() => ({
    kind: warranty?.kind || 'part',
    vehicle_id: warranty?.vehicle_id || '',
    vehicle_component_id: warranty?.vehicle_component_id || '',
    part_purchase_id: warranty?.part_purchase_id || '',
    maintenance_id: warranty?.maintenance_id || '',
    component_catalog_id: warranty?.component_catalog_id || '',
    subject: warranty?.subject || '',
    provider_vendor_id: warranty?.provider_vendor_id || '',
    provider_name: warranty?.provider_name || '',
    reference_no: warranty?.reference_no || '',
    starts_on: warranty?.starts_on || new Date().toISOString().slice(0, 10),
    start_odometer: warranty?.start_odometer ?? '',
    duration_months: warranty?.duration_months ?? '',
    duration_km: warranty?.duration_km ?? '',
    notes: warranty?.notes || '',
  }));
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const [vehicles, setVehicles] = useState([]);
  const [vendors, setVendors] = useState([]);
  const [catalog, setCatalog] = useState([]);
  const [anchors, setAnchors] = useState({ components: [], purchases: [] });
  const [anchorsLoading, setAnchorsLoading] = useState(false);

  const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));

  useEffect(() => {
    if (editing) return; // nothing on an edit needs these lists
    Promise.all([
      api.get('/Vehicle').catch(() => null),
      api.get('/Vendor').catch(() => null),
      api.get('/parts-catalog').catch(() => null),
    ]).then(([v, vd, c]) => {
      setVehicles(v?.data?.data?.data || v?.data?.data || []);
      setVendors(vd?.data?.data?.data || vd?.data?.data || []);
      setCatalog((c?.data?.data?.parts || []).filter((p) => p.is_active));
    });
  }, [editing]);

  // The anchors a car can offer. Fetched only when a car is chosen, because they are per-vehicle.
  useEffect(() => {
    if (editing || !form.vehicle_id) return;
    let alive = true;
    setAnchorsLoading(true);
    Promise.all([
      api.get(`/Vehicle/${form.vehicle_id}/components`).catch(() => null),
      api.get(`/part-purchases/vehicle/${form.vehicle_id}/history`).catch(() => null),
    ]).then(([c, p]) => {
      if (!alive) return;
      setAnchors({
        components: c?.data?.data?.current || c?.data?.data?.components || [],
        purchases: p?.data?.data?.purchases || p?.data?.data || [],
      });
    }).finally(() => alive && setAnchorsLoading(false));
    return () => { alive = false; };
  }, [form.vehicle_id, editing]);

  const catalogLabel = (p) => (lang === 'ar' && p.name_ar ? p.name_ar : p.name);

  /** Copying the catalog's defaults is a STARTING POINT, not a live link — see WarrantyService. */
  const onCatalogPick = (id) => {
    set('component_catalog_id', id);
    const p = catalog.find((c) => String(c.id) === String(id));
    if (!p) return;
    if (!form.subject) set('subject', p.name);
    if (form.duration_months === '' && p.default_warranty_months) set('duration_months', p.default_warranty_months);
    if (form.duration_km === '' && p.default_warranty_km) set('duration_km', p.default_warranty_km);
  };

  const numOrNull = (v) => (v === '' || v === null ? null : Number(v));

  // Mirrors the backend rule so the user is told before submitting, not after.
  const kmWithoutOdometer = useMemo(
    () => Number(form.duration_km) > 0 && (form.start_odometer === '' || form.start_odometer === null),
    [form.duration_km, form.start_odometer],
  );
  const noWindow = form.duration_months === '' && form.duration_km === '';

  const save = async () => {
    setSaving(true);
    setErrors({});
    try {
      const payload = {
        subject: form.subject?.trim() || null,
        component_catalog_id: numOrNull(form.component_catalog_id),
        provider_vendor_id: numOrNull(form.provider_vendor_id),
        provider_name: form.provider_name?.trim() || null,
        reference_no: form.reference_no?.trim() || null,
        starts_on: form.starts_on,
        start_odometer: numOrNull(form.start_odometer),
        duration_months: numOrNull(form.duration_months),
        duration_km: numOrNull(form.duration_km),
        notes: form.notes?.trim() || null,
      };

      if (editing) {
        await api.post(`/warranties/${warranty.id}`, payload);
        toast.success(t('warranties.updated'));
      } else {
        await api.post('/warranties', {
          ...payload,
          kind: form.kind,
          vehicle_id: numOrNull(form.vehicle_id),
          vehicle_component_id: numOrNull(form.vehicle_component_id),
          part_purchase_id: numOrNull(form.part_purchase_id),
          maintenance_id: numOrNull(form.maintenance_id),
        });
        toast.success(t('warranties.created'));
      }
      onSaved();
    } catch (err) {
      const res = err?.response?.data;
      if (res?.errors) {
        setErrors(res.errors);
        toast.error(t('warranties.fixFields'));
      } else {
        toast.error(res?.message || t('warranties.saveError'));
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open
      onClose={() => !saving && onClose()}
      title={editing ? t('warranties.editTitle') : t('warranties.record')}
      subtitle={editing ? warranty.subject : t('warranties.recordSub')}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>{t('common.cancel')}</Button>
          <Button onClick={save} loading={saving}>{editing ? t('common.save') : t('warranties.record')}</Button>
        </>
      }
    >
      <div className="space-y-4">
        {!editing && (
          <>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Select label={t('warranties.fieldKind')} required value={form.kind} onChange={(e) => set('kind', e.target.value)}>
                <option value="part">{t('warranties.kind.part')}</option>
                <option value="repair">{t('warranties.kind.repair')}</option>
              </Select>
              <Select
                label={t('warranties.fieldVehicle')} required
                value={form.vehicle_id} error={errors.vehicle_id?.[0]}
                onChange={(e) => set('vehicle_id', e.target.value)}
              >
                <option value="">{t('warranties.selectVehicle')}</option>
                {vehicles.map((v) => <option key={v.id} value={v.id}>{v.plate_no || v.vin}</option>)}
              </Select>
            </div>

            {/* The anchor. Which one is required depends on the kind — the backend refuses the wrong
                pairing, so the form asks for the right one rather than letting it fail on submit. */}
            {form.vehicle_id && (
              <div className="rounded-lg border border-slate-200 p-3">
                <p className="mb-2 text-sm font-medium text-slate-700">{t('warranties.anchorSection')}</p>

                {form.kind === 'part' ? (
                  <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Select
                      label={t('warranties.fieldComponent')}
                      value={form.vehicle_component_id}
                      error={errors.part_purchase_id?.[0]}
                      onChange={(e) => set('vehicle_component_id', e.target.value)}
                    >
                      <option value="">{anchorsLoading ? t('warranties.loading') : t('warranties.selectComponent')}</option>
                      {anchors.components.map((c) => (
                        <option key={c.id} value={c.id}>{c.name || c.catalog_name} {c.serial_no ? `· ${c.serial_no}` : ''}</option>
                      ))}
                    </Select>
                    <Select
                      label={t('warranties.fieldPurchase')}
                      value={form.part_purchase_id}
                      onChange={(e) => set('part_purchase_id', e.target.value)}
                    >
                      <option value="">{t('warranties.selectPurchase')}</option>
                      {anchors.purchases.map((p) => (
                        <option key={p.id} value={p.id}>{p.part_name} · {p.purchased_at?.slice(0, 10)}</option>
                      ))}
                    </Select>
                  </div>
                ) : (
                  <Input
                    type="number"
                    label={t('warranties.fieldTicket')}
                    value={form.maintenance_id}
                    error={errors.maintenance_task_id?.[0]}
                    onChange={(e) => set('maintenance_id', e.target.value)}
                  />
                )}

                <p className="mt-2 text-xs text-slate-500">
                  {form.kind === 'part' ? t('warranties.anchorHintPart') : t('warranties.anchorHintRepair')}
                </p>
              </div>
            )}
          </>
        )}

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Select label={t('warranties.fieldPart')} value={form.component_catalog_id} onChange={(e) => onCatalogPick(e.target.value)}>
            <option value="">{t('warranties.selectPart')}</option>
            {catalog.map((p) => <option key={p.id} value={p.id}>{catalogLabel(p)}</option>)}
          </Select>
          <Input label={t('warranties.fieldSubject')} required value={form.subject} error={errors.subject?.[0]} onChange={(e) => set('subject', e.target.value)} />
        </div>

        {/* THE WINDOW — both legs, because a promise is time AND distance, whichever ends first. */}
        <div className="rounded-lg border border-slate-200 p-3">
          <p className="mb-2 text-sm font-medium text-slate-700">{t('warranties.windowSection')}</p>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Input type="date" label={t('warranties.fieldStartsOn')} required value={form.starts_on} error={errors.starts_on?.[0]} onChange={(e) => set('starts_on', e.target.value)} />
            <Input type="number" min="0" label={t('warranties.fieldStartOdometer')} value={form.start_odometer} error={errors.start_odometer?.[0]} onChange={(e) => set('start_odometer', e.target.value)} />
            <Input type="number" min="0" label={t('warranties.fieldMonths')} value={form.duration_months} error={errors.duration_months?.[0]} onChange={(e) => set('duration_months', e.target.value)} />
            <Input type="number" min="0" label={t('warranties.fieldKm')} value={form.duration_km} error={errors.duration_km?.[0]} onChange={(e) => set('duration_km', e.target.value)} />
          </div>
          <p className="mt-2 text-xs text-slate-500">{t('warranties.windowHint')}</p>

          {kmWithoutOdometer && (
            <p className="mt-2 text-xs text-amber-600">{t('warranties.needOdometer')}</p>
          )}
          {noWindow && (
            <p className="mt-2 text-xs text-amber-600">{t('warranties.needWindow')}</p>
          )}
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Select label={t('warranties.fieldProvider')} value={form.provider_vendor_id} onChange={(e) => set('provider_vendor_id', e.target.value)}>
            <option value="">{t('warranties.selectProvider')}</option>
            {vendors.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
          </Select>
          <Input label={t('warranties.fieldReference')} value={form.reference_no} onChange={(e) => set('reference_no', e.target.value)} />
        </div>

        <Textarea label={t('warranties.fieldNotes')} rows={2} value={form.notes} onChange={(e) => set('notes', e.target.value)} />
      </div>
    </Modal>
  );
}
