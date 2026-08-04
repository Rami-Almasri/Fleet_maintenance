// Maintenance Checkpoint modal — where a responsible user answers the daily question the reminder asks:
// "is this car still coming back on the date we promised?". Yes files a dated confirmation; No demands a
// new date AND the reason it moved (and moves the whole reminder window with it — the next chase runs one
// day before the new date). Every answer is its own row, so a car chased for days keeps every reason it
// ever gave. Also reviews the timeline and lets a manager set the promised completion + the responsible
// follow-up owners. Opened from the Maintenance Progress queue, the dashboard widget and the Vehicle
// Profile tab. Backend: MaintenanceCheckpointController.

import { useEffect, useMemo, useRef, useState } from 'react';
import Modal from '../ui/Modal';
import Button from '../ui/Button';
import { Textarea, Select, Input } from '../ui/Field';
import CheckpointTimeline from './CheckpointTimeline';
import RepairIntelligencePanel from '../knowledge/RepairIntelligencePanel';
import { fmtDate } from '../../lib/format';
import {
  STATUS_OPTIONS, DELAY_REASONS, RESPONSE_CONFIRMED, RESPONSE_RESCHEDULED,
  getTicketCheckpoints, submitCheckpoint, deleteCheckpoint,
  setExpectedCompletion, setResponsibles, getCheckpointCandidates,
} from '../../lib/maintenanceCheckpoints';

function MonitorBar({ monitor }) {
  if (!monitor) return null;
  const { expected_on, is_estimated, eta_status, days_left, days_over, overdue, needs_update } = monitor;
  const tone = overdue ? 'text-red-700 bg-red-50 ring-red-200'
    : needs_update ? 'text-amber-700 bg-amber-50 ring-amber-200'
    : 'text-slate-600 bg-slate-50 ring-slate-200';
  const etaText = !expected_on ? 'No ETA'
    : overdue ? `${days_over} day(s) overdue`
    : eta_status === 'due_today' ? 'Due today'
    : `${days_left} day(s) left`;
  return (
    <div className={`flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg px-3 py-2 text-sm ring-1 ${tone}`}>
      <span className="font-semibold">{etaText}</span>
      {expected_on && (
        <span className="text-xs opacity-80">
          Expected {fmtDate(expected_on)}{is_estimated ? ' (estimated)' : ''}
        </span>
      )}
      {needs_update && <span className="text-xs font-medium">· Answer needed today</span>}
    </div>
  );
}

/**
 * The chase record for this car — the supervisor answering sees exactly what an admin sees: how long a
 * reminder has been sitting unanswered, and how many times this car's date has already moved. Silent when
 * there is nothing to report, so a car running to plan carries no noise.
 */
function ChaseBar({ chase }) {
  if (!chase) return null;
  const unanswered = chase.reminders_open > 0;
  const slipped = chase.reschedule_count > 0;
  if (!unanswered && !slipped) return null;

  return (
    <div className="flex flex-wrap items-center gap-2 text-xs">
      {unanswered && (
        <span className="inline-flex items-center rounded-full bg-red-50 px-2.5 py-1 font-medium text-red-700 ring-1 ring-red-200">
          {chase.days_unanswered > 0
            ? `Reminder unanswered for ${chase.days_unanswered} day(s) — since ${fmtDate(chase.first_reminder_on)}`
            : 'Reminded today — not yet answered'}
        </span>
      )}
      {slipped && (
        <span className="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-1 font-medium text-amber-700 ring-1 ring-amber-200">
          Date already moved {chase.reschedule_count}×
        </span>
      )}
    </div>
  );
}

export default function CheckpointModal({ open, ticketId, title, subtitle, onClose, onDone }) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');

  // Submit form — the answer to the daily question, then the date it implies (no manual "outcome").
  // answer: '' (not chosen yet) | RESPONSE_CONFIRMED | RESPONSE_RESCHEDULED.
  const [answer, setAnswer] = useState('');
  const [status, setStatus] = useState('');
  const [delayReason, setDelayReason] = useState('');
  const [delayReasonOther, setDelayReasonOther] = useState('');
  const [summary, setSummary] = useState('');
  const [nextDate, setNextDate] = useState('');
  const [files, setFiles] = useState([]);
  const fileRef = useRef(null);

  // Manager editors
  const [candidates, setCandidates] = useState([]);
  const [assigned, setAssigned] = useState([]);
  const [durationDays, setDurationDays] = useState('');
  const [expDate, setExpDate] = useState('');
  const [showManage, setShowManage] = useState(false);

  const load = useMemo(() => async () => {
    setLoading(true);
    setErr('');
    try {
      const d = await getTicketCheckpoints(ticketId);
      setData(d);
      setAssigned(d.assigned || []);
      setExpDate(d.monitor?.expected_on || '');
      // Prefill the date with the promise currently in force. The supervisor answers the question first
      // ("still coming back that day?"); confirming keeps this date, rescheduling replaces it.
      setNextDate(d.monitor?.expected_on || '');
      // A car with no promised date yet has nothing to confirm — the only possible answer is to set one.
      setAnswer(d.monitor?.expected_on ? '' : RESPONSE_RESCHEDULED);
    } catch (e) {
      setErr(e?.response?.data?.message || 'Failed to load checkpoints.');
    } finally {
      setLoading(false);
    }
  }, [ticketId]);

  useEffect(() => {
    if (!open || !ticketId) return;
    load();
  }, [open, ticketId, load]);

  // Lazy-load the responsible-user candidate list when a manager opens the editors.
  useEffect(() => {
    if (showManage && data?.can_manage && candidates.length === 0) {
      getCheckpointCandidates().then(setCandidates).catch(() => setCandidates([]));
    }
  }, [showManage, data, candidates.length]);

  const canSubmit = data?.can_submit;
  const canManage = data?.can_manage;

  // The promise currently in force. Confirming files it back unchanged; rescheduling replaces it (and a
  // reason becomes mandatory) — so the answer, not the date field, is what the supervisor chooses first.
  const currentEta = data?.monitor?.expected_on || '';
  const rescheduling = answer === RESPONSE_RESCHEDULED;
  const submittedDate = rescheduling ? nextDate : currentEta;

  const resetForm = () => {
    setAnswer(currentEta ? '' : RESPONSE_RESCHEDULED);
    setStatus(''); setDelayReason(''); setDelayReasonOther('');
    setSummary(''); setNextDate(currentEta); setFiles([]);
    if (fileRef.current) fileRef.current.value = '';
  };

  // Choosing an answer resets the half of the form the other answer owns, so a reason can never ride
  // along on a confirmation (or a stale date on a reschedule).
  const chooseAnswer = (value) => {
    setAnswer(value);
    setErr('');
    if (value === RESPONSE_CONFIRMED) {
      setNextDate(currentEta);
      setDelayReason(''); setDelayReasonOther('');
    }
  };

  const submit = async (e) => {
    e.preventDefault();
    if (busy) return;
    setErr('');
    if (!answer) { setErr('Answer the question: is the car still coming back on the promised date?'); return; }
    if (!submittedDate) { setErr('Set the date the car is expected back.'); return; }
    if (rescheduling && currentEta && nextDate === currentEta) {
      setErr('Pick the NEW date the car is expected back — or answer "Yes" to confirm the current one.');
      return;
    }
    if (rescheduling && !delayReason) { setErr('Select a reason for the changed completion date.'); return; }
    if (rescheduling && delayReason === 'other' && !delayReasonOther.trim()) { setErr('Explain the reason for the change.'); return; }
    setBusy(true);
    try {
      await submitCheckpoint(ticketId, {
        status,
        delayReason: rescheduling ? delayReason : '',
        delayReasonOther: rescheduling && delayReason === 'other' ? delayReasonOther.trim() : '',
        summary: summary.trim(), nextExpectedDate: submittedDate, files,
      });
      resetForm();
      await load();
      onDone?.('Progress update saved');
    } catch (e2) {
      setErr(e2?.response?.data?.message || 'Failed to save the update.');
    } finally {
      setBusy(false);
    }
  };

  const removeCheckpoint = async (c) => {
    if (!window.confirm('Delete this checkpoint and its evidence?')) return;
    try {
      await deleteCheckpoint(ticketId, c.id);
      await load();
      onDone?.('Checkpoint removed');
    } catch (e2) {
      setErr(e2?.response?.data?.message || 'Failed to delete.');
    }
  };

  const saveExpected = async () => {
    setBusy(true); setErr('');
    try {
      await setExpectedCompletion(ticketId, { durationDays, completionDate: expDate });
      setDurationDays('');
      await load();
      onDone?.('Expected completion updated');
    } catch (e2) {
      setErr(e2?.response?.data?.message || 'Failed to update expected completion.');
    } finally { setBusy(false); }
  };

  const toggleAssigned = (id) => {
    setAssigned((prev) => prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]);
  };

  const saveResponsibles = async () => {
    setBusy(true); setErr('');
    try {
      await setResponsibles(ticketId, assigned);
      await load();
      onDone?.('Responsible users updated');
    } catch (e2) {
      setErr(e2?.response?.data?.message || 'Failed to update responsible users.');
    } finally { setBusy(false); }
  };

  return (
    <Modal open={open} onClose={onClose} title={title || 'Maintenance Checkpoint'} subtitle={subtitle} size="xl">
      {loading ? (
        <p className="py-10 text-center text-sm text-slate-400">Loading checkpoints…</p>
      ) : (
        <div className="space-y-5">
          <MonitorBar monitor={data?.monitor} />
          <ChaseBar chase={data?.chase} />

          {err &&<div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">{err}</div>}

          {/* Responsible follow-up owners */}
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-xs font-semibold uppercase tracking-wide text-slate-400">Responsible</span>
            {(data?.responsibles || []).length === 0
              ? <span className="text-xs text-slate-400">Default supervisors</span>
              : data.responsibles.map((u) => (
                  <span key={u.id} className="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-indigo-100">{u.name}</span>
                ))}
            {canManage && (
              <button type="button" onClick={() => setShowManage((s) => !s)} className="ms-1 text-xs font-medium text-indigo-600 hover:text-indigo-700">
                {showManage ? 'Hide settings' : 'Edit settings'}
              </button>
            )}
          </div>

          {/* Manager settings: expected completion + responsible users */}
          {showManage && canManage && (
            <div className="space-y-4 rounded-xl border border-slate-200 bg-slate-50/60 p-4">
              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Expected completion</p>
                <div className="flex flex-wrap items-end gap-3">
                  <Input label="Duration (days)" type="number" min="1" max="365" value={durationDays}
                         onChange={(e) => setDurationDays(e.target.value)} className="w-32" placeholder="e.g. 4" />
                  <span className="pb-2 text-xs text-slate-400">or</span>
                  <Input label="Completion date" type="date" value={expDate || ''} onChange={(e) => setExpDate(e.target.value)} className="w-44" />
                  <Button type="button" variant="secondary" onClick={saveExpected} disabled={busy}>Save date</Button>
                </div>
                <p className="mt-1 text-[11px] text-slate-400">Enter a duration to derive the date, or set the date directly (the date wins).</p>
              </div>
              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Responsible users</p>
                <div className="flex flex-wrap gap-2">
                  {candidates.length === 0 && <span className="text-xs text-slate-400">Loading…</span>}
                  {candidates.map((u) => (
                    <label key={u.id} className={`cursor-pointer rounded-full px-3 py-1 text-xs font-medium ring-1 transition ${assigned.includes(u.id) ? 'bg-indigo-600 text-white ring-indigo-600' : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'}`}>
                      <input type="checkbox" className="sr-only" checked={assigned.includes(u.id)} onChange={() => toggleAssigned(u.id)} />
                      {u.name}
                    </label>
                  ))}
                </div>
                <div className="mt-3">
                  <Button type="button" variant="secondary" onClick={saveResponsibles} disabled={busy}>Save responsible users</Button>
                </div>
              </div>
            </div>
          )}

          {/* Submit form — a progress update, not a classification. The status is derived from the ETA. */}
          {canSubmit ? (
            <form onSubmit={submit} className="space-y-4 rounded-xl border border-slate-200 p-4">
              {/* The daily question, asked outright. Everything below follows from the answer. */}
              {currentEta && (
                <div>
                  <p className="text-sm font-semibold text-slate-700">
                    Is the car still coming back on {fmtDate(currentEta)}?
                  </p>
                  <div className="mt-2 flex flex-wrap gap-2">
                    <button
                      type="button" onClick={() => chooseAnswer(RESPONSE_CONFIRMED)}
                      className={`rounded-lg px-3 py-2 text-sm font-medium ring-1 transition ${
                        answer === RESPONSE_CONFIRMED
                          ? 'bg-emerald-600 text-white ring-emerald-600'
                          : 'bg-white text-slate-700 ring-slate-300 hover:bg-emerald-50'}`}
                    >
                      Yes — back on {fmtDate(currentEta)}
                    </button>
                    <button
                      type="button" onClick={() => chooseAnswer(RESPONSE_RESCHEDULED)}
                      className={`rounded-lg px-3 py-2 text-sm font-medium ring-1 transition ${
                        answer === RESPONSE_RESCHEDULED
                          ? 'bg-amber-600 text-white ring-amber-600'
                          : 'bg-white text-slate-700 ring-slate-300 hover:bg-amber-50'}`}
                    >
                      No — it moved to a new date
                    </button>
                  </div>
                </div>
              )}

              {/* The new date + WHY — asked only when the answer is "no". A reschedule without a reason is
                  what the whole chase exists to prevent, so both are mandatory together. */}
              {rescheduling && (
                <div className="grid grid-cols-1 gap-3 rounded-lg bg-amber-50/60 p-3 ring-1 ring-amber-100 sm:grid-cols-2">
                  <Input
                    label={currentEta ? 'New date the car is expected back' : 'Date the car is expected back'}
                    required type="date" value={nextDate} onChange={(e) => setNextDate(e.target.value)}
                  />
                  <Select label="Reason it moved" required value={delayReason} onChange={(e) => setDelayReason(e.target.value)}>
                    <option value="">— Select —</option>
                    {DELAY_REASONS.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
                  </Select>
                  {delayReason === 'other' && (
                    <Input label="Explain" required value={delayReasonOther} onChange={(e) => setDelayReasonOther(e.target.value)} placeholder="Why did the date move?" />
                  )}
                  {currentEta && (
                    <p className="text-[11px] text-amber-700 sm:col-span-2">
                      Moving the completion date from {fmtDate(currentEta)} to {nextDate && nextDate !== currentEta ? fmtDate(nextDate) : '—'}.
                      The next reminder will run one day before the new date.
                    </p>
                  )}
                </div>
              )}

              {answer === RESPONSE_CONFIRMED && (
                <p className="rounded-lg bg-emerald-50/70 px-3 py-2 text-[11px] text-emerald-700 ring-1 ring-emerald-100">
                  Recorded as confirmed for {fmtDate(currentEta)}. You will be asked again tomorrow while the car is still in the shop.
                </p>
              )}

              <Select label="Workshop status" value={status} onChange={(e) => setStatus(e.target.value)}>
                <option value="">— Select —</option>
                {STATUS_OPTIONS.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
              </Select>

              <Textarea label="Progress note" rows={3} value={summary} onChange={(e) => setSummary(e.target.value)} placeholder="What was done since the last update? What's next?" />

              <div>
                <span className="mb-1.5 block text-sm font-medium text-slate-700">Attachments (photos / videos)</span>
                <input ref={fileRef} type="file" accept="image/*,video/*" multiple onChange={(e) => setFiles(Array.from(e.target.files || []))}
                       className="block w-full text-sm text-slate-500 file:me-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200" />
                {files.length > 0 && <p className="mt-1 text-xs text-slate-500">{files.length} file(s) selected</p>}
              </div>

              <div className="flex justify-end">
                <Button type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save answer'}</Button>
              </div>
            </form>
          ) : (
            <p className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-500 ring-1 ring-slate-200">
              You are not a responsible user for this ticket, so you can view the timeline but not submit updates.
            </p>
          )}

          {/* History */}
          <div>
            <h4 className="mb-3 text-sm font-semibold text-slate-700">Timeline</h4>
            <CheckpointTimeline checkpoints={data?.checkpoints || []} canManage={canManage} onDelete={removeCheckpoint} />
          </div>

          {/* Repair intelligence per fault — the SAME reusable panel used in the drawer (read-only). */}
          {data?.faults?.length > 0 && (
            <div className="space-y-2">
              {data.faults.map((f) => (
                <RepairIntelligencePanel key={f.id} taskId={f.id} />
              ))}
            </div>
          )}
        </div>
      )}
    </Modal>
  );
}
