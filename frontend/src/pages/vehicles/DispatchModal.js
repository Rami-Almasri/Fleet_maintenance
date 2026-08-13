import { useEffect, useState } from 'react';
import api from '../../api/client';
import { useToast } from '../../components/ui/Toast';
import Modal from '../../components/ui/Modal';
import Button from '../../components/ui/Button';
import { Input, Select, Textarea } from '../../components/ui/Field';
import { useI18n } from '../../i18n/I18nContext';

const CUSTOM = '__custom__';

/**
 * "Dispatch Vehicle" — pick a destination for the car. Submitting creates a Logistics Task that is
 * always POOLED: the car flips to "In Transit to {destination}" on the grid and every available driver
 * gets an Action Required alert; the first to Claim it owns the move. Destination presets are fetched
 * on open. There is no direct driver assignment — dispatch is first-come-first-served by design.
 */
export default function DispatchModal({ open, vehicle, onClose, onDispatched }) {
  const toast = useToast();
  const { t } = useI18n();
  const [presets, setPresets] = useState([]);

  const [destChoice, setDestChoice] = useState('');
  const [customDest, setCustomDest] = useState('');
  const [roundTrip, setRoundTrip] = useState(false);
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  // Reset the form + load the destination presets each time the modal opens for a car.
  useEffect(() => {
    if (!open) return;
    setDestChoice(''); setCustomDest(''); setRoundTrip(false); setNotes(''); setErrors({});
    let active = true;
    api.get('/logistics/assignees')
      .then(({ data }) => {
        if (!active) return;
        setPresets(data.data?.destinations || []);
      })
      .catch(() => active && toast.error(t('Could not load the destination list')));
    return () => { active = false; };
  }, [open, toast, t]);

  if (!vehicle) return null;

  const destination = destChoice === CUSTOM ? customDest.trim() : destChoice;

  const submit = async () => {
    const errs = {};
    if (!destination) errs.destination = t('Pick or type where the car is going');
    setErrors(errs);
    if (Object.keys(errs).length) return;

    setSaving(true);
    try {
      await api.post('/logistics', {
        vehicle_id: vehicle.id,
        destination,
        round_trip: roundTrip,
        assigned_to_id: null, // Always pooled — no direct assignment.
        notes: notes.trim() || null,
      });
      toast.success(t('Dispatched to {destination}', { destination }));
      onDispatched?.();
      onClose();
    } catch (err) {
      toast.error(err.response?.data?.message || err.response?.data?.msg || t('Could not dispatch the vehicle'));
    } finally {
      setSaving(false);
    }
  };

  const carLabel = [vehicle.make, vehicle.model].filter(Boolean).join(' ') || vehicle.vin;

  return (
    <Modal
      open={open}
      onClose={() => !saving && onClose()}
      title={t('Dispatch Vehicle')}
      subtitle={t('{car} — assign a move', { car: vehicle.plate_no || carLabel })}
      size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>{t('Cancel')}</Button>
          <Button onClick={submit} loading={saving}>{t('Dispatch')}</Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600 ring-1 ring-inset ring-slate-200">
          <span className="font-medium text-slate-800">{t('Moving {car}', { car: carLabel })}</span>
          {vehicle.plate_no && <span className="text-slate-400"> · {vehicle.plate_no}</span>}
        </div>

        <Select
          label={t('Destination')}
          required
          value={destChoice}
          error={errors.destination}
          onChange={(e) => setDestChoice(e.target.value)}
        >
          <option value="">{t('Select a destination…')}</option>
          {presets.map((d) => <option key={d} value={d}>{d}</option>)}
          <option value={CUSTOM}>{t('Other (type it)…')}</option>
        </Select>

        {destChoice === CUSTOM && (
          <Input
            label={t('Custom destination')}
            required
            autoFocus
            placeholder={t('e.g. Sharjah branch')}
            value={customDest}
            onChange={(e) => setCustomDest(e.target.value)}
          />
        )}

        <div className="rounded-lg border border-slate-200 bg-slate-50/60 px-3 py-2.5 text-sm">
          <span className="font-medium text-slate-800">{t('Unassigned · Available Pool')}</span>
          <span className="block text-xs text-slate-500">
            {t('Every available driver is notified and the first to tap Claim takes it.')}
          </span>
        </div>

        <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 bg-slate-50/60 px-3 py-2.5">
          <input
            type="checkbox"
            className="mt-0.5 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
            checked={roundTrip}
            onChange={(e) => setRoundTrip(e.target.checked)}
          />
          <span className="text-sm">
            <span className="font-medium text-slate-800">{t('Round trip')}</span>
            <span className="block text-xs text-slate-500">
              {t('The car is brought back to base after (e.g. to the garage and home). Leave off for a one-way move.')}
            </span>
          </span>
        </label>

        <Textarea
          label={t('Notes (optional)')}
          rows={2}
          placeholder={t('Anything the driver should know…')}
          value={notes}
          onChange={(e) => setNotes(e.target.value)}
        />
      </div>
    </Modal>
  );
}
