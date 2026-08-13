import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { useToast } from '../components/ui/Toast';
import { usePermissions } from '../hooks/usePermissions';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import { Input, Select } from '../components/ui/Field';
import { CommandPanel, StatGaugeTile } from '../components/ops';
import Icon from '../components/ui/Icon';
import CreateMoveModal from './logistics/CreateMoveModal';
import { useI18n } from '../i18n/I18nContext';
import { evaluateContinuity, needsNote, STAGE } from '../lib/odometerContinuity';
import OdometerContinuityHint, { odoGateBlocked } from '../components/workflow/OdometerContinuityHint';
import LogisticsAnalytics from '../components/analytics/LogisticsAnalytics';

// Relative "x ago" for a timestamp (kept tiny — no date lib). `t` is threaded in from the calling
// component, because a plain module-level helper cannot call the hook itself.
function ago(iso, t) {
  if (!iso) return '';
  const secs = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
  if (secs < 90) return t('just now');
  const mins = Math.round(secs / 60);
  if (mins < 60) return t('{n}m ago', { n: mins });
  const hrs = Math.round(mins / 60);
  if (hrs < 24) return t('{n}h ago', { n: hrs });
  return t('{n}d ago', { n: Math.round(hrs / 24) });
}

// Phase → badge tone, so the lifecycle reads at a glance everywhere it's shown.
const PHASE_TONE = {
  dispatched: 'slate',
  en_route: 'blue',
  picked_up: 'indigo',
  delivered: 'amber',
  returned: 'emerald',
  completed: 'emerald',
  cancelled: 'gray',
  // legacy
  in_transit: 'indigo',
  to_destination: 'indigo',
  at_destination: 'amber',
  to_base: 'amber',
};

/**
 * Ask the browser for a one-shot GPS fix. Best-effort: resolves to null if the user denies it or it
 * times out, so marking a car "Returned" never gets blocked — the move still closes, just without the
 * location stamp. (Requires HTTPS or localhost; falls back gracefully otherwise.)
 */
function getGeo() {
  return new Promise((resolve) => {
    if (!('geolocation' in navigator)) return resolve(null);
    navigator.geolocation.getCurrentPosition(
      (pos) => resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy }),
      () => resolve(null),
      { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 },
    );
  });
}

// A garage trip (round trip, or a move tied to a maintenance ticket) must carry the mandatory before/
// after odometer pair; a plain one-way move does not. Mirrors the server's requiresOdometer().
const isGarageTrip = (task) => !!(task.round_trip || task.maintenance_id);
function needsOdometer(task, action) {
  if (!isGarageTrip(task)) return false;
  if (action === 'pickup') return true;              // the "before" shot
  if (action === 'return') return true;              // the "after" shot (round trips)
  if (action === 'deliver') return !task.round_trip; // a one-way close doubles as the "after" shot
  return false;
}

// Compact header shared by every card — plate (link) + car label.
function CarLine({ task }) {
  return (
    <div className="flex items-center gap-2">
      <Link to={`/vehicles/${task.vehicle_id}`} className="font-semibold text-indigo-600 hover:text-indigo-700">
        {task.plate || '—'}
      </Link>
      {task.car && <span className="truncate text-sm text-slate-500">{task.car}</span>}
    </div>
  );
}

// Capture the odometer reading + photo before a step that needs the Pre/Post proof (garage trips). Shares
// the fleet-wide Odometer Continuity rule: the reading is checked live against the car's last recorded
// mileage, and a >10 km gap (either way) demands both an acknowledgment checkbox and a written note.
function OdometerModal({ open, task, action, busy, onClose, onSubmit }) {
  const { t } = useI18n();
  const [reading, setReading] = useState('');
  const [photo, setPhoto] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const [note, setNote] = useState('');
  const [err, setErr] = useState({});

  useEffect(() => { if (open) { setReading(''); setPhoto(null); setConfirmed(false); setNote(''); setErr({}); } }, [open, task?.id, action]);

  if (!task) return null;
  const isAfter = action === 'return' || (action === 'deliver' && !task.round_trip);

  // Pre-trip → the reading vs the car's last recorded mileage; post-trip → the reading vs the same anchor
  // (i.e. the whole trip distance). Strict rule (no waiver): a >10 km gap forces the checkbox + note.
  const prevOdometer = task.previous_odometer ?? null;
  const stage = isAfter ? STAGE.RETURN : STAGE.PICKUP;
  const continuity = evaluateContinuity(reading, prevOdometer, stage);
  const noteRequired = needsNote(continuity);
  const gateBlocked = odoGateBlocked(continuity, confirmed, note);

  const submit = () => {
    const e = {};
    if (!reading || Number(reading) < 1) e.reading = t('Enter the odometer reading');
    if (!photo) e.photo = t('A photo of the odometer is required');
    setErr(e);
    if (Object.keys(e).length) return;
    if (gateBlocked) return; // acknowledge + explain a >10 km gap before it can submit
    onSubmit({ odometer: Number(reading), photo, odometer_note: note.trim() || null });
  };

  return (
    <Modal
      open={open}
      onClose={() => !busy && onClose()}
      title={isAfter ? t('Post-trip odometer') : t('Pre-trip odometer')}
      subtitle={`${task.plate || ''} — ${isAfter ? t('after the trip') : t('before setting off')}`}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={!!busy}>{t('Cancel')}</Button>
          <Button onClick={submit} loading={!!busy} disabled={gateBlocked}>{t('Confirm')}</Button>
        </>
      }
    >
      <div className="space-y-4">
        <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 ring-1 ring-inset ring-amber-600/20">
          {t('This is a garage trip — the odometer reading and a photo of it are required for the audit trail.')}
        </p>
        <Input
          label={t('Odometer (km)')} type="number" min="1" required
          value={reading} error={err.reading}
          onChange={(e) => { setReading(e.target.value); setConfirmed(false); setNote(''); }} placeholder={t('e.g. 45200')}
        />
        <OdometerContinuityHint
          previous={prevOdometer}
          continuity={continuity}
          confirmed={confirmed}
          onConfirm={setConfirmed}
          noteRequired={noteRequired}
          note={note}
          onNote={setNote}
          t={t}
        />
        <div>
          <span className="mb-1 block text-sm font-medium text-slate-700">{t('Odometer photo')}<span className="ms-0.5 text-red-500">*</span></span>
          <input
            type="file" accept="image/*" capture="environment"
            onChange={(e) => setPhoto(e.target.files?.[0] || null)}
            className="block w-full text-sm text-slate-600 file:me-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100"
          />
          {err.photo && <span className="mt-1 block text-xs text-red-600">{err.photo}</span>}
          {photo && <span className="mt-1 block text-xs text-slate-400">{photo.name}</span>}
        </div>
      </div>
    </Modal>
  );
}

// Inline supervisor control: reassign the move to a different driver at any point in the cycle.
function ReassignControl({ task, assignees, busy, onReassign }) {
  const { t } = useI18n();
  const [open, setOpen] = useState(false);
  const [pick, setPick] = useState('');

  if (!open) {
    return <Button variant="ghost" size="sm" onClick={() => setOpen(true)}>{t('Reassign')}</Button>;
  }
  return (
    <div className="flex items-center gap-2">
      <Select value={pick} onChange={(e) => setPick(e.target.value)} className="!py-1 text-sm">
        <option value="">{t('Pick a driver…')}</option>
        {assignees.filter((p) => p.id !== task.assigned_to_id).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
      </Select>
      <Button size="sm" loading={busy === 'reassign'} disabled={!pick} onClick={() => onReassign(task, pick).then(() => setOpen(false))}>{t('Apply')}</Button>
      <Button variant="ghost" size="sm" onClick={() => setOpen(false)}>{t('Cancel')}</Button>
    </div>
  );
}

// A pooled move up for grabs. Drivers (canClaim) get the Claim button; everyone else with
// logistics.view (e.g. supervisors) sees it read-only for operational awareness — visibility isn't
// gated, only the action is.
function PoolCard({ task, busy, canClaim, onClaim }) {
  const { t } = useI18n();
  return (
    <div className="rounded-xl border border-blue-200 bg-blue-50/40 p-4">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <CarLine task={task} />
          <p className="mt-1 text-sm text-slate-700">
            <span className="font-medium text-slate-900">{t('to {destination}', { destination: task.destination })}</span>
            {task.round_trip && <span className="text-slate-400"> · {t('round trip')}</span>}
            {task.maintenance_id && <span className="text-slate-400"> · 🔧 #{task.maintenance_id}</span>}
          </p>
          <p className="mt-1 text-xs text-slate-400">
            {t('Decided by {name}', { name: task.decided_by || t('a coordinator') })}
            {task.dispatched_at ? ` · ${ago(task.dispatched_at, t)}` : ''}
          </p>
          {task.notes && <p className="mt-2 rounded-lg bg-white/70 px-2 py-1 text-xs text-slate-500">{task.notes}</p>}
        </div>
        <Badge tone="blue">{t('Up for grabs')}</Badge>
      </div>
      <div className="mt-3 flex items-center justify-end border-t border-blue-100 pt-3">
        {canClaim ? (
          <Button size="sm" loading={busy === 'claim'} onClick={() => onClaim(task)}>{t('Claim Task')}</Button>
        ) : (
          <span className="text-xs text-slate-400">{t('Awaiting a driver to claim')}</span>
        )}
      </div>
    </div>
  );
}

// A move the signed-in driver owns — the execution buttons live here.
function MyTaskCard({ task, busy, onAction, onStatus }) {
  const { t } = useI18n();
  const actions = task.next_actions || [];
  const anyBusy = !!busy; // an action (pickup/deliver/return/status) is in flight on THIS task
  return (
    <div className="rounded-xl border border-violet-200 bg-violet-50/40 p-4">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <CarLine task={task} />
          <p className="mt-1 text-sm text-slate-700">
            <span className="font-medium text-slate-900">{t('to {destination}', { destination: task.destination })}</span>
            {task.round_trip && <span className="text-slate-400"> · {t('round trip')}</span>}
            {task.maintenance_id && <span className="text-slate-400"> · 🔧 #{task.maintenance_id}</span>}
          </p>
          <p className="mt-1 text-xs text-slate-400">
            {t('Decided by {name}', { name: task.decided_by || '—' })}
            {task.status_changed_at
              ? ` · ${t('{status} since {when}', {
                status: task.status_label || task.status || t('updated'),
                when: ago(task.status_changed_at, t),
              })}`
              : ''}
          </p>
          {task.notes && <p className="mt-2 rounded-lg bg-white/70 px-2 py-1 text-xs text-slate-500">{task.notes}</p>}
        </div>
        <Badge tone={PHASE_TONE[task.status] || 'slate'}>{task.status_label || task.status || '—'}</Badge>
      </div>

      {/* One-click "where is it?" reply presets, so a coordinator ping is answered in a tap. Disabled
          while ANY action on this task is in flight, so a double-tap can't fire two status posts. */}
      <div className="mt-3 flex flex-wrap gap-1.5">
        {(task.status_presets || []).map((s) => (
          <button
            key={s}
            onClick={() => onStatus(task, s)}
            disabled={anyBusy}
            className="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-600 hover:border-indigo-300 hover:text-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {s}
          </button>
        ))}
      </div>

      <div className="mt-3 flex flex-wrap justify-end gap-2 border-t border-violet-100 pt-3">
        {actions.length === 0 && <span className="self-center text-xs text-slate-400">{t('Waiting — nothing to do right now.')}</span>}
        {actions.map((a) => (
          <Button
            key={a.action}
            size="sm"
            variant={a.action === 'return' ? 'success' : 'primary'}
            loading={busy === a.action}
            disabled={anyBusy && busy !== a.action}
            onClick={() => onAction(task, a.action)}
          >
            {needsOdometer(task, a.action) ? `📷 ${a.label}` : a.label}
          </Button>
        ))}
      </div>
    </div>
  );
}

// Oversight row — the coordinator's "Decided by / Current Status / Assigned to" board, with the
// supervisor controls (reassign at any point, ping, cancel).
function BoardCard({ task, canDispatch, assignees, busy, onPing, onCancel, onReassign }) {
  const { t } = useI18n();
  const rl = task.returned_location;
  // Only treat the GPS stamp as renderable when both coordinates are present — a
  // partial/empty fix ({} or null lat) must never crash the board on .toFixed().
  const loc = rl && rl.lat != null && rl.lng != null ? rl : null;
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <CarLine task={task} />
          <p className="mt-1 text-sm text-slate-700">
            <span className="font-medium text-slate-900">{t('to {destination}', { destination: task.destination })}</span>
            {task.round_trip && <span className="text-slate-400"> · {t('round trip')}</span>}
          </p>
          <dl className="mt-2 space-y-0.5 text-xs text-slate-500">
            <div><span className="text-slate-400">{t('Decided by')}</span> <span className="font-medium text-slate-700">{task.decided_by || '—'}</span></div>
            <div><span className="text-slate-400">{t('Assigned to')}</span> <span className="font-medium text-slate-700">{task.assigned_to_name || t('Unclaimed')}</span></div>
            {task.last_status && (
              <div><span className="text-slate-400">{t('Last reply')}</span> <span className="text-slate-600">“{task.last_status}” · {ago(task.last_status_at, t)}</span></div>
            )}
            {loc && (
              <div>
                <span className="text-slate-400">{t('Returned at')}</span>{' '}
                <a className="text-indigo-600 hover:underline" target="_blank" rel="noreferrer"
                   href={`https://maps.google.com/?q=${loc.lat},${loc.lng}`}>
                  {loc.lat.toFixed(5)}, {loc.lng.toFixed(5)}
                </a>
                {loc.accuracy != null && <span className="text-slate-400"> · ±{Math.round(loc.accuracy)}m</span>}
              </div>
            )}
          </dl>
        </div>
        <div className="flex flex-col items-end gap-1">
          <Badge tone={PHASE_TONE[task.status] || 'slate'}>{task.status_label || task.status || '—'}</Badge>
          {task.awaiting_reply && <span className="text-[11px] text-amber-600">{t('awaiting reply…')}</span>}
        </div>
      </div>

      {canDispatch && task.is_open && (
        <div className="mt-3 flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-3">
          <ReassignControl task={task} assignees={assignees} busy={busy} onReassign={onReassign} />
          {task.assigned_to_id && (
            <Button variant="ghost" size="sm" loading={busy === 'ping'} onClick={() => onPing(task)}>{t('Ping location')}</Button>
          )}
          <Button variant="ghost" size="sm" className="text-red-600 hover:bg-red-50" loading={busy === 'cancel'} onClick={() => onCancel(task)}>
            {t('Cancel')}
          </Button>
        </div>
      )}
    </div>
  );
}

// Initials avatar for a driver (up to two letters).
const initialsOf = (name) =>
  (name || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?';

// One driver in the availability roster: avatar + name + Available/Busy chip, and (when busy) what
// they're on right now — the move/maintenance phase, the car, how long, and their last status reply.
function DriverChip({ d }) {
  const { t } = useI18n();
  const busy = d.status === 'busy';
  const a = d.activity;
  return (
    <div className={`flex items-start gap-3 rounded-xl border p-3 ${busy ? 'border-amber-200 bg-amber-50/40' : 'border-emerald-200 bg-emerald-50/40'}`}>
      <span className={`mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold ${busy ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'}`}>
        {initialsOf(d.name)}
      </span>
      <div className="min-w-0 flex-1">
        <div className="flex items-center justify-between gap-2">
          <span className="truncate text-sm font-semibold text-slate-800">{d.name}</span>
          <Badge tone={busy ? 'amber' : 'emerald'}>{busy ? t('Busy') : t('Available')}</Badge>
        </div>
        {busy && a ? (
          <p className="mt-1 flex flex-wrap items-center gap-x-1.5 text-xs text-slate-500">
            {a.kind === 'maintenance'
              ? <Icon.Wrench className="h-3 w-3 shrink-0 text-slate-400" />
              : <Icon.Truck className="h-3 w-3 shrink-0 text-slate-400" />}
            <span className="font-medium text-slate-600">{a.label}</span>
            {a.vehicle && <span className="font-mono text-slate-500">· {a.vehicle}</span>}
            {a.since && <span className="text-slate-400">· {ago(a.since, t)}</span>}
            {a.last_status && <span className="text-slate-400">· “{a.last_status}”</span>}
          </p>
        ) : (
          <p className="mt-1 text-xs font-medium text-emerald-600">{t('Ready for the next move')}</p>
        )}
      </div>
    </div>
  );
}

/**
 * Logistics Dispatch — the data-driven board that replaces WhatsApp coordination. A coordinator raises
 * a move (pooled or assigned); drivers claim from the pool and walk it through Picked Up → Delivered →
 * Returned/Arrived (GPS-stamped). Garage trips capture a mandatory Pre/Post odometer photo. "My Queue"
 * carries the driver's execution buttons; the board below is the team's live "Decided by / Current
 * Status / Assigned to" oversight, where a supervisor can reassign the driver, ping, or cancel.
 */
export default function LogisticsDispatch() {
  const toast = useToast();
  const { t } = useI18n();
  const { can } = usePermissions();
  const canDispatch = can('logistics.dispatch'); // coordinator: raise / cancel / ping / reassign
  const canClaim = can('logistics.claim');       // field driver: claim + execute

  const fetcher = useCallback(async () => {
    const empty = { drivers: [], summary: {} };
    // The pool is fetched for everyone with logistics.view (which gates this page) — supervisors see
    // how many moves are up for grabs for operational awareness, even though only drivers can Claim.
    const [mine, pool, all, roster] = await Promise.all([
      api.get('/logistics/my-queue').then((r) => r.data.data?.tasks || []).catch(() => []),
      api.get('/logistics/pool').then((r) => r.data.data?.tasks || []).catch(() => []),
      api.get('/logistics').then((r) => r.data.data?.tasks || []).catch(() => []),
      api.get('/logistics/drivers').then((r) => r.data.data || empty).catch(() => empty),
    ]);
    return { mine, pool, all, roster };
  }, []);
  const { data, loading, error, reload } = useFetch(fetcher);

  // Driver list for the reassign dropdown — only a coordinator needs (and may fetch) it.
  const [assignees, setAssignees] = useState([]);
  useEffect(() => {
    if (!canDispatch) return;
    let active = true;
    api.get('/logistics/assignees').then(({ data: d }) => active && setAssignees(d.data?.assignees || [])).catch(() => {});
    return () => { active = false; };
  }, [canDispatch]);

  const [createOpen, setCreateOpen] = useState(false);
  const [odoModal, setOdoModal] = useState(null); // { task, action } | null
  const [busyId, setBusyId] = useState(null); // `${id}:${action}`
  const busyFor = (id, action) => (busyId === `${id}:${action}` ? action : null);
  const fail = (err, fallback) => toast.error(err.response?.data?.message || err.response?.data?.msg || fallback);

  // Fire a lifecycle action. Garage-trip steps that need the odometer proof open the capture modal
  // first; `return` grabs a GPS fix (best-effort) to prove the car is home.
  const act = async (task, action) => {
    // Cancelling a live move is destructive and easy to mis-tap (it sits next to Ping/Reassign): it
    // voids the trip and strands the linked maintenance ticket. Confirm before firing.
    if (action === 'cancel' && !window.confirm(
      task.destination
        ? t('Cancel this move to {destination}? The car is released from this trip and its driver is left with no task.', { destination: task.destination })
        : t('Cancel this move? The car is released from this trip and its driver is left with no task.')
    )) return;
    if (['pickup', 'deliver', 'return'].includes(action) && needsOdometer(task, action)) {
      setOdoModal({ task, action });
      return;
    }
    setBusyId(`${task.id}:${action}`);
    try {
      let body;
      if (action === 'return') {
        toast.info?.(t('Getting your location…'));
        body = (await getGeo()) || {};
      }
      await api.post(`/logistics/${task.id}/${action}`, body);
      const done = {
        claim: t('Claimed — it\'s yours'),
        pickup: t('Marked picked up'),
        deliver: t('Marked delivered'),
        return: t('Marked returned / arrived'),
        cancel: t('Move cancelled'),
        ping: t('Location request sent'),
      };
      toast.success(done[action] || t('Updated'));
      reload();
    } catch (err) {
      fail(err, t('Action failed'));
    } finally {
      setBusyId(null);
    }
  };

  // Submit a garage-trip step with the captured odometer reading + photo (multipart). `return` also
  // attaches the GPS fix.
  const submitWithOdometer = async ({ odometer, photo, odometer_note }) => {
    const { task, action } = odoModal;
    setBusyId(`${task.id}:${action}`);
    try {
      const fd = new FormData();
      fd.append('odometer', odometer);
      fd.append('odometer_photo', photo);
      if (odometer_note) fd.append('odometer_note', odometer_note);
      if (action === 'return') {
        const geo = await getGeo();
        if (geo) { fd.append('lat', geo.lat); fd.append('lng', geo.lng); fd.append('accuracy', geo.accuracy); }
      }
      await api.post(`/logistics/${task.id}/${action}`, fd);
      toast.success(action === 'return' ? t('Marked returned / arrived') : action === 'deliver' ? t('Marked delivered') : t('Marked picked up'));
      setOdoModal(null);
      reload();
    } catch (err) {
      fail(err, t('Action failed'));
    } finally {
      setBusyId(null);
    }
  };

  const reassign = async (task, assignedToId) => {
    setBusyId(`${task.id}:reassign`);
    try {
      await api.post(`/logistics/${task.id}/reassign`, { assigned_to_id: Number(assignedToId) });
      toast.success(t('Driver reassigned'));
      reload();
    } catch (err) {
      fail(err, t('Could not reassign'));
    } finally {
      setBusyId(null);
    }
  };

  const postStatus = async (task, status) => {
    if (busyId && busyId.startsWith(`${task.id}:`)) return; // already acting on this task — ignore double-taps
    setBusyId(`${task.id}:status`);
    try {
      await api.post(`/logistics/${task.id}/status`, { status });
      toast.success(t('Status: {status}', { status }));
      reload();
    } catch (err) {
      fail(err, t('Could not post status'));
    } finally {
      setBusyId(null);
    }
  };

  const mine = data?.mine || [];
  const pool = data?.pool || [];
  const all = data?.all || [];
  const roster = data?.roster || { drivers: [], summary: {} };
  // The team board, minus the ones already shown in My Queue, so a card never appears twice.
  const board = all.filter((t) => !mine.some((m) => m.id === t.id));

  return (
    <div className="opx py-8">
      <div className="mx-auto max-w-6xl space-y-4 px-4 sm:px-6 lg:px-8">
        <div className="flex flex-wrap items-end justify-between gap-4" style={{ marginBottom: 4 }}>
          <div>
            <div className="opx-hint" style={{ letterSpacing: '.16em', textTransform: 'uppercase', marginBottom: 6 }}>{t('Movement Control · Live')}</div>
            <h1 className="font-display" style={{ fontSize: 24, fontWeight: 700, letterSpacing: '-.02em', color: 'var(--ink)', margin: 0 }}>{t('Driver Dispatch')}</h1>
            <p style={{ marginTop: 6, fontSize: 13.5, color: 'var(--ink-3)' }}>
              {loading
                ? t('Loading…')
                : t('{mine} in your queue · {pool} up for grabs · {all} in transit fleet-wide', {
                  mine: mine.length, pool: pool.length, all: all.length,
                })}
            </p>
          </div>
          {canDispatch && <button className="opx-btn primary" onClick={() => setCreateOpen(true)}>{t('+ New Move')}</button>}
        </div>

        <div className="opx-grid opx-c12" style={{ marginBottom: 4 }}>
          <div className="opx-span-4">
            <StatGaugeTile label={t('My Queue')} value={loading ? '—' : mine.length} hint={t("Moves you've claimed")} tone={mine.length ? 'reserved' : 'avail'} icon="check" percent={all.length ? (mine.length / all.length) * 100 : (mine.length ? 100 : 6)} />
          </div>
          <div className="opx-span-4">
            <StatGaugeTile label={t('Up for Grabs')} value={loading ? '—' : pool.length} hint={t('Pooled moves awaiting a driver')} tone={pool.length ? 'cyan' : 'avail'} icon="calendar" percent={all.length ? (pool.length / all.length) * 100 : (pool.length ? 100 : 6)} />
          </div>
          <div className="opx-span-4">
            <StatGaugeTile label={t('In Transit · Fleet')} value={loading ? '—' : all.length} hint={t('Every car currently on a move')} tone="rented" icon="car" percent={all.length ? 100 : 6} />
          </div>
        </div>

        {error && (
          <div style={{ borderRadius: 12, border: '1px solid rgba(251,113,133,.3)', background: 'rgba(251,113,133,.08)', color: '#fb7185', padding: '12px 16px', fontSize: 13 }}>{error}</div>
        )}

        {/* Analytics — the movement pipeline and driver load, before the roster. */}
        {!loading && <LogisticsAnalytics tasks={all} pool={pool} />}

        {/* Driver availability — who's free and what everyone else is doing right now. */}
        <CommandPanel
          title={t('Drivers')}
          dotColor="#34d399"
          label={t('roster')}
          meta={loading ? t('loading') : t('{available} available · {busy} busy · {total} total', {
            available: roster.summary?.available ?? 0,
            busy: roster.summary?.busy ?? 0,
            total: roster.summary?.total ?? 0,
          })}
        >
          {loading ? (
            <p className="opx-empty">{t('Loading drivers…')}</p>
          ) : roster.drivers.length === 0 ? (
            <p className="opx-empty">{t('No drivers found.')}</p>
          ) : (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {roster.drivers.map((d) => <DriverChip key={d.id} d={d} />)}
            </div>
          )}
        </CommandPanel>

        {/* Pooled moves up for grabs — drivers can claim; supervisors see it read-only for awareness. */}
        <CommandPanel
          title={canClaim ? t('Available to Claim') : t('Up for Grabs')}
          dotColor="#22d3ee"
          label={t('pool')}
          meta={canClaim ? t('first driver wins') : t('read-only — only drivers can claim')}
        >
          {!loading && pool.length === 0 ? (
            <p className="opx-empty">{t('Nothing waiting to be claimed.')}</p>
          ) : (
            <div className="grid gap-3 sm:grid-cols-2">
              {pool.map((t) => (
                <PoolCard key={t.id} task={t} busy={busyFor(t.id, 'claim')} canClaim={canClaim} onClaim={(x) => act(x, 'claim')} />
              ))}
            </div>
          )}
        </CommandPanel>

        {/* My active tasks — the driver's execution lane. */}
        <CommandPanel title={t('My Queue')} dotColor="#a78bfa" label={t('execution')} meta={t('step each car along as you go')}>
          {!loading && mine.length === 0 ? (
            <p className="opx-empty">{t('Nothing assigned to you right now.')}</p>
          ) : (
            <div className="grid gap-3 sm:grid-cols-2">
              {mine.map((t) => (
                <MyTaskCard
                  key={t.id}
                  task={t}
                  busy={busyId && busyId.startsWith(`${t.id}:`) ? busyId.split(':')[1] : null}
                  onAction={act}
                  onStatus={postStatus}
                />
              ))}
            </div>
          )}
        </CommandPanel>

        {/* Oversight board — Decided by / Current Status / Assigned to, plus supervisor controls. */}
        <CommandPanel title={t('Dispatch Board · Fleet')} dotColor="#60a5fa" label={t('where is it?')} meta={t('every car on the move')}>
          {!loading && board.length === 0 ? (
            <p className="opx-empty">{t('No other cars in transit.')}</p>
          ) : (
            <div className="grid gap-3 sm:grid-cols-2">
              {board.map((t) => (
                <BoardCard
                  key={t.id}
                  task={t}
                  canDispatch={canDispatch}
                  assignees={assignees}
                  busy={busyId && busyId.startsWith(`${t.id}:`) ? busyId.split(':')[1] : null}
                  onPing={(x) => act(x, 'ping')}
                  onCancel={(x) => act(x, 'cancel')}
                  onReassign={reassign}
                />
              ))}
            </div>
          )}
        </CommandPanel>
      </div>

      <CreateMoveModal open={createOpen} onClose={() => setCreateOpen(false)} onCreated={reload} />
      <OdometerModal
        open={!!odoModal}
        task={odoModal?.task}
        action={odoModal?.action}
        busy={odoModal && busyId === `${odoModal.task.id}:${odoModal.action}` ? odoModal.action : null}
        onClose={() => setOdoModal(null)}
        onSubmit={submitWithOdometer}
      />
    </div>
  );
}
