// Breakdown Intake — the EMERGENCY entry point. A technician (Abu Maroof) reports a car that is NOT
// driveable and needs immediate intervention. Unlike the routine diagnostic it asks for no odometer /
// test drive — you can't road-test a dead car — just the car and a brief description of the failure.
//
// On submit the server (POST /maintenance-tickets/breakdown) opens a ticket straight in the
// Supervisors' dispatch queue (inspection_pending, trigger_reason = breakdown), GROUNDS the car
// (condition_grade → red, so it's pulled from the rental pool), then alerts
// Management to dispatch a garage immediately. It is classified Breakdown + graded 🔴 critical
// automatically — nothing to pick. See openBreakdown().

import { useState } from 'react';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Modal from '../ui/Modal';
import Button from '../ui/Button';
import { Textarea } from '../ui/Field';
import Icon from '../ui/Icon';
import VehicleStatusSelect from './VehicleStatusSelect';

export default function BreakdownIntakeModal({ vehicles = [], onClose, onDone }) {
  const { t } = useI18n();
  const [vehicleId, setVehicleId] = useState('');
  const [description, setDescription] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);

  const disabled = !vehicleId || !description.trim() || saving;

  const submit = async () => {
    if (disabled) return;
    setSaving(true);
    setError(null);
    try {
      await api.post('/maintenance-tickets/breakdown', {
        vehicle_id: Number(vehicleId),
        fault_description: description.trim(),
      });
      onDone?.(t('workflow.breakdown.success'));
    } catch (e) {
      setError(e?.response?.data?.message || t('workflow.error.generic'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open
      onClose={onClose}
      size="md"
      title={t('workflow.breakdown.title')}
      subtitle={t('workflow.breakdown.subtitle')}
      footer={(
        <>
          <Button variant="ghost" onClick={onClose} disabled={saving}>{t('common.cancel')}</Button>
          <Button variant="danger" onClick={submit} disabled={disabled} loading={saving}>
            <span aria-hidden>⚠️</span> {t('workflow.breakdown.submit')}
          </Button>
        </>
      )}
    >
      <div className="space-y-4">
        {/* Vehicle — grouped by availability, same picker the rest of the workflow uses. */}
        <div>
          <span className="mb-1 block text-sm font-medium text-gray-700">
            {t('workflow.field.vehicle')}<span className="ms-0.5 text-red-500">*</span>
          </span>
          <VehicleStatusSelect value={vehicleId} onChange={setVehicleId} vehicles={vehicles} placeholder={t('workflow.ph.searchVehicle')} />
        </div>

        {/* Brief description of the failure — mandatory, stored as the ticket's fault description. */}
        <Textarea
          label={t('workflow.breakdown.faultLabel')}
          required
          rows={4}
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          placeholder={t('workflow.breakdown.faultPlaceholder')}
          maxLength={2000}
        />

        {/* Consequences banner — a breakdown is a hard, immediate action, so spell it out in red. */}
        <p className="flex items-start gap-1.5 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 ring-1 ring-inset ring-red-100">
          <Icon.Info className="mt-0.5 h-3.5 w-3.5 shrink-0 text-red-500" />
          <span>{t('workflow.breakdown.hint')}</span>
        </p>

        {error && <p className="text-sm text-red-600">{error}</p>}
      </div>
    </Modal>
  );
}
