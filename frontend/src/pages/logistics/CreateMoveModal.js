import { useEffect, useMemo, useState } from 'react';
import api from '../../api/client';
import { useToast } from '../../components/ui/Toast';
import Modal from '../../components/ui/Modal';
import Button from '../../components/ui/Button';
import SearchSelect from '../../components/ui/SearchSelect';
import { Input, Select, Textarea } from '../../components/ui/Field';

const CUSTOM = '__custom__';

/**
 * "New Logistics Task" — the coordinator's dispatch form. Pick a free car (or, when raised from a
 * maintenance ticket, the car is locked in and the move is linked to that ticket) and say where it's
 * going. Every move is POOLED — there is no direct driver assignment: all available drivers are pinged
 * and the first to claim it owns it (first-come-first-served). Submitting creates the task, flips the
 * car to "In Transit" and fires the notifications.
 *
 * Props:
 *   lockedVehicle  { id, plate, label } — pre-selected, un-editable car (the ticket's vehicle). When set
 *                  the vehicle picker is replaced by a read-only chip and the car list isn't fetched.
 *   maintenanceId  links the move to a maintenance ticket (sent as maintenance_id).
 */
export default function CreateMoveModal({ open, onClose, onCreated, lockedVehicle = null, maintenanceId = null }) {
  const toast = useToast();
  const lockedId = lockedVehicle?.id ?? null;

  const [vehicles, setVehicles] = useState([]);
  const [presets, setPresets] = useState([]);
  const [loading, setLoading] = useState(false);

  const [vehicleId, setVehicleId] = useState('');
  const [destChoice, setDestChoice] = useState('');
  const [customDest, setCustomDest] = useState('');
  const [roundTrip, setRoundTrip] = useState(true);
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open) return undefined;
    setVehicleId(lockedId ? String(lockedId) : '');
    setDestChoice(''); setCustomDest(''); setRoundTrip(true); setNotes(''); setErrors({});
    let active = true;
    setLoading(true);
    Promise.all([
      // A ticket move already knows its car — skip the (potentially big) fleet fetch.
      lockedId ? Promise.resolve([]) : api.get('/Vehicle').then((r) => r.data.data || r.data || []).catch(() => []),
      api.get('/logistics/assignees').then((r) => r.data.data || {}).catch(() => ({})),
    ])
      .then(([v, people]) => {
        if (!active) return;
        // Only cars free to move (not rented / in the garage / already on a dispatch).
        setVehicles((Array.isArray(v) ? v : []).filter((x) => x.available));
        setPresets(people.destinations || []);
      })
      .finally(() => active && setLoading(false));
    return () => { active = false; };
  }, [open, lockedId]);

  const vehicleOptions = useMemo(() => vehicles.map((v) => ({
    id: v.id,
    label: `${v.plate_no || '—'} · ${[v.make, v.model].filter(Boolean).join(' ') || v.vin || ''}`.trim(),
    sub: v.vin || undefined,
  })), [vehicles]);

  const destination = destChoice === CUSTOM ? customDest.trim() : destChoice;

  const submit = async () => {
    const errs = {};
    if (!vehicleId) errs.vehicle = 'Pick the car to move';
    if (!destination) errs.destination = 'Pick or type where it\'s going';
    setErrors(errs);
    if (Object.keys(errs).length) return;

    setSaving(true);
    try {
      await api.post('/logistics', {
        vehicle_id: vehicleId,
        destination,
        round_trip: roundTrip,
        assigned_to_id: null, // Always pooled — no direct assignment.
        maintenance_id: maintenanceId || null,
        notes: notes.trim() || null,
      });
      toast.success(`Posted to the driver pool · ${destination}`);
      onCreated?.();
      onClose();
    } catch (err) {
      toast.error(err.response?.data?.message || err.response?.data?.msg || 'Could not create the move');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={() => !saving && onClose()}
      title={lockedVehicle ? 'Dispatch this car' : 'New Driver Task'}
      subtitle={lockedVehicle
        ? 'Move this repair car — linked to its maintenance ticket'
        : 'Send a car somewhere — pool it to all drivers, or assign one directly'}
      size="md"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button onClick={submit} loading={saving}>Post to Pool</Button>
        </>
      }
    >
      <div className="space-y-4">
        {lockedVehicle ? (
          <div className="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-sm ring-1 ring-inset ring-slate-200">
            <span className="rounded bg-white px-2 py-0.5 font-mono text-xs font-bold tracking-wider text-slate-700 ring-1 ring-inset ring-slate-200">
              {lockedVehicle.plate || `#${lockedVehicle.id}`}
            </span>
            {lockedVehicle.label && <span className="truncate text-slate-500">{lockedVehicle.label}</span>}
            {maintenanceId && <span className="ms-auto shrink-0 text-xs text-slate-400">🔧 Ticket #{maintenanceId}</span>}
          </div>
        ) : (
          <div>
            <span className="mb-1 block text-sm font-medium text-slate-700">Vehicle<span className="ms-0.5 text-red-500">*</span></span>
            <SearchSelect
              value={vehicleId}
              onChange={setVehicleId}
              options={vehicleOptions}
              loading={loading}
              placeholder={loading ? 'Loading cars…' : 'Search an available car…'}
            />
            {errors.vehicle && <span className="mt-1 block text-xs text-red-600">{errors.vehicle}</span>}
            {!loading && vehicleOptions.length === 0 && (
              <span className="mt-1 block text-xs text-amber-600">No free cars — every car is rented, in the garage or already on a move.</span>
            )}
          </div>
        )}

        <Select label="Destination" required value={destChoice} error={errors.destination} onChange={(e) => setDestChoice(e.target.value)}>
          <option value="">Select a destination…</option>
          {presets.map((d) => <option key={d} value={d}>{d}</option>)}
          <option value={CUSTOM}>Other (type it)…</option>
        </Select>

        {destChoice === CUSTOM && (
          <Input label="Custom destination" required autoFocus placeholder="e.g. Sharjah branch" value={customDest} onChange={(e) => setCustomDest(e.target.value)} />
        )}

        <div className="rounded-lg border border-slate-200 bg-slate-50/60 px-3 py-2.5 text-sm">
          <span className="font-medium text-slate-800">Unassigned · Available Pool</span>
          <span className="block text-xs text-slate-500">
            Every available driver is notified and the first to tap Claim takes it.
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
            <span className="font-medium text-slate-800">Round trip</span>
            <span className="block text-xs text-slate-500">
              The car is brought back to base after (e.g. to the garage and home). The driver finishes with “Returned / Arrived”. Turn off for a one-way drop.
            </span>
          </span>
        </label>

        <Textarea label="Notes (optional)" rows={2} placeholder="Anything the driver should know…" value={notes} onChange={(e) => setNotes(e.target.value)} />
      </div>
    </Modal>
  );
}
