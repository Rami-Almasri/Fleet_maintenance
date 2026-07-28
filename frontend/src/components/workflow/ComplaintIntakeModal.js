// Complaint Intake — the Operations controllers (Marwa & Leen) log a customer complaint against a
// car. This is the customer-facing START of the maintenance workflow. Unlike the inspector's flow it
// asks for no odometer / test drive: just the car, what the customer reported, and how bad it is.
//
// On submit the server (POST /complaints) creates a first-class Complaint entity (its own customer-support
// lifecycle, NOT a maintenance ticket) and notifies the Inspector (Abu Maroof) to triage it — call the
// customer, decide, and only send the car in if it needs real work. See ComplaintWorkflowService::open().

import { useEffect, useMemo, useState } from 'react';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Modal from '../ui/Modal';
import Button from '../ui/Button';
import { Textarea } from '../ui/Field';
import Icon from '../ui/Icon';
import VehicleStatusSelect from './VehicleStatusSelect';

export default function ComplaintIntakeModal({ vehicles = [], onClose, onDone }) {
  const { t } = useI18n();
  const [vehicleId, setVehicleId] = useState('');
  const [description, setDescription] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);
  // The open rental resolved for the picked car — WHO currently has it. A complaint is raised by
  // the renter, so we only offer rented cars and auto-pull the customer off the open contract.
  const [contract, setContract] = useState(null);
  const [contractLoading, setContractLoading] = useState(false);

  // Only cars that are out on rent right now can be complained about — the customer must have the
  // car in hand to report a fault. Narrow the picker to the Rented bucket.
  const rentedVehicles = useMemo(
    () => vehicles.filter((v) => v.rented || v.operational_status === 'rented'),
    [vehicles],
  );

  // When a car is picked, resolve its current open contract + the customer holding it.
  useEffect(() => {
    if (!vehicleId) { setContract(null); return undefined; }
    let alive = true;
    setContractLoading(true);
    setContract(null);
    api.get(`/Vehicle/${vehicleId}/operation`)
      .then((res) => { if (alive) setContract(res.data?.data || null); })
      .catch(() => { if (alive) setContract(null); })
      .finally(() => { if (alive) setContractLoading(false); });
    return () => { alive = false; };
  }, [vehicleId]);

  const customer = contract?.customer || null;
  const customerName = customer ? (customer.name_en || customer.name_ar || `#${customer.customer_no || customer.id}`) : null;

  const disabled = !vehicleId || !description.trim() || saving;

  const submit = async () => {
    if (disabled) return;
    setSaving(true);
    setError(null);
    try {
      await api.post('/complaints', {
        vehicle_id: Number(vehicleId),
        description: description.trim(),
        source: 'ops',
      });
      onDone?.(t('workflow.complaint.success'));
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
      title={t('workflow.complaint.title')}
      subtitle={t('workflow.complaint.subtitle')}
      footer={(
        <>
          <Button variant="ghost" onClick={onClose} disabled={saving}>{t('common.cancel')}</Button>
          <Button onClick={submit} disabled={disabled} loading={saving}>
            <Icon.Plus className="h-4 w-4" /> {t('workflow.complaint.submit')}
          </Button>
        </>
      )}
    >
      <div className="space-y-4">
        {/* Vehicle — restricted to cars out on rent (only a renter can raise a complaint). */}
        <div>
          <span className="mb-1 block text-sm font-medium text-slate-700">
            {t('workflow.complaint.vehicleLabel')}<span className="ms-0.5 text-red-500">*</span>
          </span>
          {rentedVehicles.length === 0 ? (
            <p className="flex items-start gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 ring-1 ring-inset ring-amber-100">
              <Icon.Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
              <span>{t('workflow.complaint.noRented')}</span>
            </p>
          ) : (
            <VehicleStatusSelect value={vehicleId} onChange={setVehicleId} vehicles={rentedVehicles} placeholder={t('workflow.ph.searchVehicle')} />
          )}
        </div>

        {/* Who currently has the car — auto-resolved from the open rental contract, read-only. */}
        {vehicleId && (
          <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5">
            <span className="mb-1 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
              <Icon.Users className="h-3.5 w-3.5" /> {t('workflow.complaint.customerLabel')}
            </span>
            {contractLoading ? (
              <span className="text-sm text-slate-400">{t('common.loading')}</span>
            ) : customer ? (
              <div className="flex flex-wrap items-center gap-x-3 gap-y-0.5">
                <span className="text-sm font-semibold text-slate-800">{customerName}</span>
                {(customer.mobile1 || customer.whatsapp) && (
                  <span className="font-mono text-xs text-slate-500" dir="ltr">{customer.mobile1 || customer.whatsapp}</span>
                )}
                {contract?.contract_no && (
                  <span className="rounded-full bg-white px-2 py-0.5 text-[11px] font-medium text-slate-500 ring-1 ring-inset ring-slate-200">
                    {t('workflow.complaint.contractTag', { no: contract.contract_no })}
                  </span>
                )}
              </div>
            ) : (
              <span className="text-sm text-amber-600">{t('workflow.complaint.noContract')}</span>
            )}
          </div>
        )}

        {/* What the customer reported — stored verbatim as the ticket's customer_complaint. */}
        <Textarea
          label={t('workflow.complaint.faultLabel')}
          required
          rows={4}
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          placeholder={t('workflow.complaint.faultPlaceholder')}
          maxLength={2000}
        />

        {/* What happens next — sets expectations that this routes straight to Management. */}
        <p className="flex items-start gap-1.5 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500 ring-1 ring-inset ring-slate-100">
          <Icon.Info className="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400" />
          <span>{t('workflow.complaint.hint')}</span>
        </p>

        {error && <p className="text-sm text-red-600">{error}</p>}
      </div>
    </Modal>
  );
}
