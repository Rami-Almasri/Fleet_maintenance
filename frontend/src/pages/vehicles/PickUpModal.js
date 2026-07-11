import { useEffect, useState } from 'react';
import api from '../../api/client';
import { useToast } from '../../components/ui/Toast';
import Modal from '../../components/ui/Modal';
import Button from '../../components/ui/Button';
import { Input, Textarea } from '../../components/ui/Field';

// The fault-severity colour language, shared with the ticket board (routine / moderate / critical).
const SEV_TONE = {
  critical: 'bg-red-50 text-red-700 ring-red-600/20',
  high: 'bg-orange-50 text-orange-700 ring-orange-600/20',
  moderate: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  routine: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
};
const SEV_DOT = { critical: '🔴', high: '🟠', moderate: '🟡', routine: '🟢' };

/**
 * "Pick Up for Maintenance" — the odometer-gated intake. On open it previews the pending Inspector's-Pad
 * flags that will ride onto the new ticket. Submitting mints a local maintenance ticket carrying those
 * flags and moves the car to Maintenance. The odometer is MANDATORY — no reading, no ticket (enforced
 * again server-side). Mirrors DispatchModal's shape.
 */
export default function PickUpModal({ open, vehicle, onClose, onPickedUp }) {
  const toast = useToast();
  const [odometer, setOdometer] = useState('');
  const [note, setNote] = useState('');
  const [flags, setFlags] = useState([]);
  const [loadingFlags, setLoadingFlags] = useState(false);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  // Reset + preview the car's pending flags each time the modal opens.
  useEffect(() => {
    if (!open || !vehicle) return;
    setOdometer(''); setNote(''); setErrors({}); setFlags([]);
    let active = true;
    setLoadingFlags(true);
    api.get('/inspector-pad', { params: { vehicle_id: vehicle.id } })
      .then(({ data }) => { if (active) setFlags(data.data || []); })
      .catch(() => active && toast.error('Could not load the pending flags'))
      .finally(() => active && setLoadingFlags(false));
    return () => { active = false; };
  }, [open, vehicle, toast]);

  if (!vehicle) return null;

  const submit = async () => {
    const errs = {};
    const km = Number(odometer);
    if (!odometer.trim() || !Number.isFinite(km) || km < 1) {
      errs.odometer = 'Enter the current odometer reading to pick the car up';
    }
    setErrors(errs);
    if (Object.keys(errs).length) return;

    setSaving(true);
    try {
      await api.post(`/inspector-pad/pickup/${vehicle.id}`, {
        odometer: Math.round(km),
        note: note.trim() || null,
      });
      toast.success(`${carLabel} picked up for maintenance`);
      onPickedUp?.();
      onClose();
    } catch (err) {
      const res = err.response?.data;
      if (res?.errors) setErrors(res.errors);
      toast.error(res?.message || res?.msg || 'Could not pick up the vehicle');
    } finally {
      setSaving(false);
    }
  };

  const carLabel = [vehicle.make, vehicle.model].filter(Boolean).join(' ') || vehicle.vin;

  return (
    <Modal
      open={open}
      onClose={() => !saving && onClose()}
      title="Pick Up for Maintenance"
      subtitle={`${vehicle.plate_no || carLabel} — open a maintenance ticket`}
      size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button onClick={submit} loading={saving}>Pick Up</Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600 ring-1 ring-inset ring-slate-200">
          Sending <span className="font-medium text-slate-800">{carLabel}</span>
          {vehicle.plate_no && <span className="text-slate-400"> · {vehicle.plate_no}</span>} to maintenance.
        </div>

        <Input
          label="Current odometer (km)"
          required
          type="number"
          min="1"
          inputMode="numeric"
          autoFocus
          placeholder="e.g. 84500"
          value={odometer}
          error={errors.odometer}
          onChange={(e) => setOdometer(e.target.value)}
        />

        {/* Preview: the inspector flags that will be attached to the new ticket. */}
        <div className="rounded-lg border border-slate-200 bg-slate-50/60 px-3 py-2.5 text-sm">
          <span className="font-medium text-slate-800">Inspector flags to attach</span>
          {loadingFlags ? (
            <span className="block text-xs text-slate-500">Loading…</span>
          ) : flags.length === 0 ? (
            <span className="block text-xs text-slate-500">
              No pending flags for this car — a ticket will still open, ready for the garage’s diagnosis.
            </span>
          ) : (
            <ul className="mt-2 space-y-1.5">
              {flags.map((f) => (
                <li key={f.id} className="flex items-start gap-2">
                  <span
                    className={`mt-0.5 inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-xs ring-1 ring-inset ${
                      SEV_TONE[f.severity] || 'bg-slate-100 text-slate-600 ring-slate-300'
                    }`}
                  >
                    {SEV_DOT[f.severity] || '•'}
                  </span>
                  <span className="text-slate-700">{f.label}</span>
                </li>
              ))}
            </ul>
          )}
        </div>

        <Textarea
          label="Note (optional)"
          rows={2}
          placeholder="Anything the supervisor / garage should know…"
          value={note}
          onChange={(e) => setNote(e.target.value)}
        />
      </div>
    </Modal>
  );
}
