// DriverObservationModal — the lightweight capture for a Driver Handover Observation. When a driver takes
// a car back and notices something (or the customer casually mentions it), they log it here. This is NOT a
// customer complaint: no contact, no escalation, no customer-support workflow. Optionally the driver can
// raise an inspection request on the spot, which enters the normal inspection-review queue.
//
// POST /driver-observations (+ optional POST /driver-observations/{id}/request-inspection). See
// DriverObservationService.

import { useState } from 'react';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Modal from '../ui/Modal';
import Button from '../ui/Button';
import { Textarea } from '../ui/Field';
import Icon from '../ui/Icon';
import VehicleStatusSelect from './VehicleStatusSelect';

export default function DriverObservationModal({ vehicles = [], onClose, onDone }) {
  const { t } = useI18n();
  const [vehicleId, setVehicleId] = useState('');
  const [note, setNote] = useState('');
  const [requestInspection, setRequestInspection] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);

  const disabled = !vehicleId || !note.trim() || saving;

  const submit = async () => {
    if (disabled) return;
    setSaving(true);
    setError(null);
    try {
      const res = await api.post('/driver-observations', {
        vehicle_id: Number(vehicleId),
        note: note.trim(),
      });
      const created = res.data?.data;
      if (requestInspection && created?.id) {
        await api.post(`/driver-observations/${created.id}/request-inspection`);
      }
      onDone?.(requestInspection ? t('Observation logged — inspection requested') : t('Observation logged'));
    } catch (e) {
      setError(e?.response?.data?.message || t('Could not record the observation'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open
      onClose={onClose}
      size="md"
      title={t('Driver observation')}
      subtitle={t('Something you noticed when the car came back — an internal note, not a customer complaint.')}
      footer={(
        <>
          <Button variant="ghost" onClick={onClose} disabled={saving}>{t('Cancel')}</Button>
          <Button onClick={submit} disabled={disabled} loading={saving}>
            <Icon.Plus className="h-4 w-4" /> {t('Record')}
          </Button>
        </>
      )}
    >
      <div className="space-y-4">
        <div>
          <span className="mb-1 block text-sm font-medium text-slate-700">
            {t('Vehicle')}<span className="ms-0.5 text-red-500">*</span>
          </span>
          <VehicleStatusSelect value={vehicleId} onChange={setVehicleId} vehicles={vehicles} placeholder={t('Search vehicle…')} />
        </div>

        <Textarea
          label={t('What did you notice?')}
          required
          rows={4}
          value={note}
          onChange={(e) => setNote(e.target.value)}
          placeholder={t('e.g. Customer mentioned the steering vibrates · I heard an engine noise bringing it in')}
          maxLength={2000}
        />

        <label className="flex items-start gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-600 ring-1 ring-inset ring-slate-100">
          <input type="checkbox" checked={requestInspection} onChange={(e) => setRequestInspection(e.target.checked)} className="mt-0.5" />
          <span>{t('Raise an inspection request now — sends it to the inspection-review queue. Leave off to just record the note.')}</span>
        </label>

        {error && <p className="text-sm text-red-600">{error}</p>}
      </div>
    </Modal>
  );
}
