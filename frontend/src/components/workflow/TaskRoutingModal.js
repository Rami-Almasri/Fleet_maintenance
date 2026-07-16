// SINGLE-GARAGE routing — the panel that manages a ticket's faults while the car sits at ONE garage.
// The car is always in one place: every open fault belongs to the active stint of the ticket's current
// garage. From here a supervisor can:
//   • Mark a fault fixed / reopen it      → POST /maintenance-tasks/{id}/status        {status}
//   • Transfer the WHOLE car to another garage (sequential hand-off, all open faults move together)
//                → POST /maintenance-tickets/{id}/transfer-garage {vendor_id, reason, odometer, acknowledge_conflict?}
//
// State Intelligence guards the transfer so the page is the single source of truth — no silent moves:
//   • Conflict Check — 409 if the car is already under active repair at another garage (confirm to proceed).
//   • Mileage Gate   — the current odometer is MANDATORY; the move can't be confirmed without it.
//   • Live Status    — the banner states where the car is + its lifecycle stage right now.
//
// Faults are still tracked individually (you see all of them), but they are grouped under the single
// Current Garage and never routed to different garages at the same time. The first garage is chosen at
// dispatch (Assign garage); this panel handles everything after. Each action returns the parent ticket,
// so the list + current garage stay live and the board refreshes via onDone(). Copy is from the i18n
// catalog (workflow.task.*).

import { useMemo, useState } from 'react';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import { usePermissions } from '../../hooks/usePermissions';
import Modal from '../ui/Modal';
import Button from '../ui/Button';
import SearchSelect from '../ui/SearchSelect';
import { Input, Textarea } from '../ui/Field';
import Icon from '../ui/Icon';
import Tooltip from '../ui/Tooltip';
import { TASK_STATUS, SERVICE_CONFIRM, isAtGarage } from './meta';
import { evaluateContinuity, needsNote, stageIgnoresTolerance, STAGE } from '../../lib/odometerContinuity';
import OdometerContinuityHint, { odoGateBlocked } from './OdometerContinuityHint';
import { uploadRepairVideo } from '../../lib/maintenanceMedia';

const MAX_VIDEO_MB = 256; // matches the backend multipart cap (262144 KB)
const FIX_STAGE_LABEL = { presigning: 'Preparing upload…', uploading: 'Uploading…', saving: 'Saving…' };

const TERMINAL = ['completed', 'cancelled'];

// "12 Jul 2026" from an ISO string; empty when unparseable.
const fmtDay = (iso) => {
  if (!iso) return '';
  try {
    return new Date(iso).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' });
  } catch {
    return '';
  }
};

// Workshop confirmation verdicts — the technician's per-fault review at the In Workshop stage. Only
// `confirmed` triggers recurring-fault intelligence server-side; the rest simply record the finding.
const CONFIRM_VERDICTS = ['confirmed', 'not_found', 'different_cause'];
const CONFIRM_TONE = {
  confirmed:       { icon: '✓', active: 'bg-emerald-600 text-white ring-emerald-600', idle: 'bg-white text-emerald-700 ring-emerald-300 hover:bg-emerald-50' },
  not_found:       { icon: '∅', active: 'bg-slate-600 text-white ring-slate-600', idle: 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-100' },
  different_cause: { icon: '≠', active: 'bg-amber-500 text-white ring-amber-500', idle: 'bg-white text-amber-700 ring-amber-300 hover:bg-amber-50' },
};

export default function TaskRoutingModal({ ticket, garages = [], onClose, onDone }) {
  const { t } = useI18n();
  const { can } = usePermissions();
  const canApprove = can('maintenance.recurring.manage'); // clear a blocked recurring-fault repair
  const [tasks, setTasks] = useState(ticket?.tasks || []);
  const [garage, setGarage] = useState(ticket?.garage || null);
  const [wfStatus, setWfStatus] = useState(ticket?.workflow_status); // tracks live stage so a transfer flips it at once
  const [transferOpen, setTransferOpen] = useState(false);
  // "How will the vehicle be transferred?" — asked BEFORE the transfer form itself; null = not chosen
  // yet (shows the choice, not the form). The rest of the transfer form/API call is unchanged either way.
  const [transportMethod, setTransportMethod] = useState(null);
  const [vendorId, setVendorId] = useState('');
  const [reason, setReason] = useState('');
  const [odometer, setOdometer] = useState('');       // Mileage Gate — mandatory current reading on transfer
  const [odoConfirmed, setOdoConfirmed] = useState(false); // soft-confirm for an odd continuity verdict
  const [odoNote, setOdoNote] = useState('');         // mandatory explanation when the reading is >10 km off the previous
  const [conflict, setConflict] = useState(null);     // Conflict Check warning awaiting the operator's call
  const [busyId, setBusyId] = useState(null); // a fault row mid status-change
  const [transferring, setTransferring] = useState(false);
  const [error, setError] = useState(null);

  // "Mark fixed" fix-evidence panel — the fault being resolved + its note/video.
  const [fixTask, setFixTask] = useState(null);
  const [fixNote, setFixNote] = useState('');
  const [fixOdometer, setFixOdometer] = useState(''); // routine service: odometer at the moment of change
  const [fixVideo, setFixVideo] = useState(null);   // a File
  const [fixStage, setFixStage] = useState(null);   // upload progress label key
  const [fixBusy, setFixBusy] = useState(false);
  const [fixError, setFixError] = useState(null);

  // "Mark incorrect" — a delegate overrules the inspector, ruling an inspector-flagged fault a
  // mis-diagnosis. Offered only In Workshop (under_repair) on inspector-source faults. Reason is required.
  const [disputeTask, setDisputeTask] = useState(null);
  const [disputeReason, setDisputeReason] = useState('');
  const [disputeBusy, setDisputeBusy] = useState(false);
  const [disputeError, setDisputeError] = useState(null);

  // The car's last recorded mileage (newest link in the chain) — the anchor the transfer reading is
  // checked against for a live "does this make sense?" verdict before submit.
  const prevOdometer = ticket?.receive_odometer ?? ticket?.return_odometer
    ?? ticket?.dispatch_odometer ?? ticket?.test_odometer ?? null;
  const continuity = evaluateContinuity(odometer, prevOdometer, STAGE.TRANSFER);
  // A transfer IS a road trip — the car is driven from one garage to the next, so a forward mileage
  // increase is expected and we waive the ±10 km note/confirm nag for it (mirrors the garage in/out
  // legs). A BACKWARD reading is still impossible, so that Discrepancy guard stays in force below.
  const ignoreOdoTolerance = stageIgnoresTolerance(STAGE.TRANSFER);
  const odoNoteRequired = needsNote(continuity, ignoreOdoTolerance);
  // Block the transfer until a garage is chosen, a positive odometer is entered, and the shared odometer
  // gate is satisfied (a backward Discrepancy still holds it until acknowledged; forward travel is free).
  const odoValid = Number(odometer) > 0 && !odoGateBlocked(continuity, odoConfirmed, odoNote, ignoreOdoTolerance);

  // You can only transfer to a DIFFERENT garage, so the current one is dropped from the picker.
  const garageOptions = useMemo(
    () => garages.filter((g) => g.name !== garage).map((g) => ({ id: g.id, label: g.name, sub: g.phone || undefined })),
    [garages, garage],
  );
  const openFaults = tasks.filter((task) => !TERMINAL.includes(task.status)).length;
  // Resolved-Transfer Oversight — moving the car to another garage while EVERY fault is already fixed is
  // unusual (nothing left to repair). When that's the case the justification note becomes MANDATORY and
  // the move is logged for review on /oversight/resolved-transfers (enforced server-side too).
  const allFixed = tasks.length > 0 && openFaults === 0;
  const reasonMissing = allFixed && reason.trim() === '';
  const busy = busyId !== null || transferring || fixBusy || disputeBusy;
  // Can a fault be marked fixed right now? Only once the car is at the garage stage. Derived from the
  // LIVE stage so a transfer (which rolls the ticket back to in_transit) hides fault management at once.
  const carAtGarage = isAtGarage({ workflow_status: wfStatus });
  // Only offer the "how will it be transferred?" choice when the car's CURRENT leg is a recovery tow —
  // it may or may not still need a tow onward, so it's a real decision. A car that arrived by a normal
  // driver is presumably still drivable, so a further transfer goes straight through the driver flow
  // with no dialog. ticket.is_recovery always reflects the current leg (dispatch() clears it on pickup).
  const offerTransportChoice = carAtGarage && !!ticket?.is_recovery;

  // Pull the refreshed ticket (faults + current garage + stage) out of any action response.
  const apply = (res) => {
    const tk = res?.data?.data;
    if (tk?.tasks) setTasks(tk.tasks);
    if (tk) setGarage(tk.garage ?? null);
    if (tk?.workflow_status) setWfStatus(tk.workflow_status);
    onDone?.(); // reloads the board behind the modal
  };

  const setStatus = async (task, status) => {
    setBusyId(task.id);
    setError(null);
    try {
      apply(await api.post(`/maintenance-tasks/${task.id}/status`, { status }));
    } catch (e) {
      setError(e?.response?.data?.message || t('workflow.error.generic'));
    } finally {
      setBusyId(null);
    }
  };

  // Workshop confirmation — record the technician's verdict on whether the reported fault is real. A
  // `confirmed` verdict opens a recurring-fault review; if the fault recurred it also BLOCKS the repair
  // (repair_gate=pending) until a manager approves it below.
  const confirmTask = async (task, confirmation_status) => {
    setBusyId(task.id);
    setError(null);
    try {
      apply(await api.post(`/maintenance-tasks/${task.id}/confirm`, { confirmation_status }));
    } catch (e) {
      setError(e?.response?.data?.message || t('workflow.error.generic'));
    } finally {
      setBusyId(null);
    }
  };

  // Approve / reject a blocked recurring-fault repair (managers only). Approve unblocks the repair; reject
  // cancels the fault so we don't pay to repair the same thing twice.
  const repairApproval = async (task, decision) => {
    setBusyId(task.id);
    setError(null);
    try {
      apply(await api.post(`/maintenance-tasks/${task.id}/repair-approval`, { decision }));
    } catch (e) {
      setError(e?.response?.data?.message || t('workflow.error.generic'));
    } finally {
      setBusyId(null);
    }
  };

  // acknowledge = the operator saw the Conflict Check warning and chose to proceed anyway.
  // ── "Mark fixed" with video + note ──────────────────────────────────────────────────────────────
  const openFix = (task) => {
    setFixTask(task);
    setFixNote('');
    // Pre-fill the routine-service odometer with the car's last known reading so a routine change is a
    // one-tap confirm; the user can correct it to the reading at the actual moment of the service.
    setFixOdometer(task?.routine_service_type && prevOdometer != null ? String(prevOdometer) : '');
    setFixVideo(null);
    setFixError(null);
    setFixStage(null);
  };
  const closeFix = () => { setFixTask(null); setFixVideo(null); setFixNote(''); setFixOdometer(''); setFixError(null); setFixStage(null); };

  const pickVideo = (e) => {
    const file = e.target.files?.[0];
    if (!file) { setFixVideo(null); return; }
    if (file.size > MAX_VIDEO_MB * 1024 * 1024) {
      setFixError(`File is too large (max ${MAX_VIDEO_MB} MB).`);
      setFixVideo(null);
      return;
    }
    setFixError(null);
    setFixVideo(file);
  };

  // Fix Evidence is mandatory — a fault can't be marked fixed without both a note AND a video.
  const fixValid = fixNote.trim() !== '' && fixVideo != null;

  const submitFix = async () => {
    if (!fixTask || !fixValid) return;
    setFixBusy(true);
    setFixError(null);
    try {
      // 1) Upload the repair video first (if provided) so the completed fault carries its evidence.
      if (fixVideo) {
        await uploadRepairVideo(ticket.id, fixVideo, {
          note: fixNote,
          taskId: fixTask.id,
          onStage: setFixStage,
        });
      }
      // 2) Mark the fault fixed + store the resolution note. For a routine service, also send the odometer
      //    at the change so its Service Reminder rolls forward from that reading.
      setFixStage('saving');
      const payload = { status: 'completed', note: fixNote || null };
      if (fixTask.routine_service_type && Number(fixOdometer) > 0) payload.odometer = Number(fixOdometer);
      apply(await api.post(`/maintenance-tasks/${fixTask.id}/status`, payload));
      closeFix();
    } catch (e) {
      setFixError(e?.response?.data?.message || t('workflow.error.generic'));
    } finally {
      setFixBusy(false);
      setFixStage(null);
    }
  };

  // ── "Mark incorrect" — delegate disputes an inspector fault (In Workshop only) ───────────────────
  const openDispute = (task) => { setDisputeTask(task); setDisputeReason(''); setDisputeError(null); };
  const closeDispute = () => { setDisputeTask(null); setDisputeReason(''); setDisputeError(null); };
  const submitDispute = async () => {
    if (!disputeTask || disputeReason.trim() === '') return;
    setDisputeBusy(true);
    setDisputeError(null);
    try {
      apply(await api.post(`/maintenance-tasks/${disputeTask.id}/incorrect`, { reason: disputeReason.trim() }));
      closeDispute();
    } catch (e) {
      setDisputeError(e?.response?.data?.message || t('workflow.error.generic'));
    } finally {
      setDisputeBusy(false);
    }
  };

  const transferCar = async (acknowledge = false) => {
    if (!vendorId || !odoValid || reasonMissing) return;
    setTransferring(true);
    setError(null);
    try {
      const res = await api.post(`/maintenance-tickets/${ticket.id}/transfer-garage`, {
        vendor_id: vendorId,
        reason: reason || null,
        odometer: Number(odometer),
        odometer_note: odoNote.trim() || null,
        acknowledge_conflict: acknowledge,
        transport_method: transportMethod || 'driver',
      });
      apply(res);
      setTransferOpen(false);
      setTransportMethod(null);
      setConflict(null);
      setVendorId('');
      setReason('');
      setOdometer('');
      setOdoConfirmed(false);
      setOdoNote('');
    } catch (e) {
      // 409 = Conflict Check tripped: the car has active repairs at another garage. Surface the
      // warning inline and let the operator confirm — the retry carries acknowledge_conflict.
      if (e?.response?.status === 409 && e?.response?.data?.data?.conflict) {
        setConflict({ message: e.response.data.message });
      } else {
        setError(e?.response?.data?.message || t('workflow.error.generic'));
      }
    } finally {
      setTransferring(false);
    }
  };

  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      title={t('workflow.task.panelTitle')}
      subtitle={`${ticket?.plate || `#${ticket?.id}`}${ticket?.car ? ' · ' + ticket.car : ''} — ${t('workflow.task.panelSubtitle')}`}
    >
      {error && (
        <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
      )}

      {/* Current garage banner — the single place the car is right now; every fault below sits here. */}
      {garage ? (
        <div className="mb-3 flex items-center justify-between gap-3 rounded-xl border border-indigo-100 bg-indigo-50/60 px-3.5 py-2.5">
          <span className="inline-flex min-w-0 items-center gap-2">
            <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600">
              <Icon.Wrench className="h-4 w-4" />
            </span>
            <span className="min-w-0">
              <span className="block text-[10px] font-semibold uppercase tracking-wide text-indigo-400">{t('workflow.task.currentGarage')}</span>
              {/* Live Status — the car's whereabouts + lifecycle stage, stated plainly so the page is
                  the single source of truth: "Currently at [Garage] · Status: [In Workshop]". */}
              <span className="block truncate text-sm font-bold text-slate-800">
                {carAtGarage ? 'Currently at' : 'En route to'} {garage}
              </span>
              {!carAtGarage && wfStatus === 'in_transit' && (
                <span className="mt-0.5 block truncate text-[11px] font-semibold text-blue-500">
                  Status: In Transit — awaiting arrival check-in
                </span>
              )}
            </span>
          </span>
          <Button
            size="sm"
            variant="secondary"
            disabled={busy}
            onClick={() => {
              setTransferOpen((v) => !v);
              // Ask "how" ONLY when the car's current leg is a recovery tow (offerTransportChoice) — a
              // genuine open decision. Otherwise (pre-arrival re-route, OR the car arrived by a normal
              // driver and is presumably still drivable) skip straight to the driver transfer flow with
              // no dialog — transport_method 'driver' is sent but ignored server-side on the pre-arrival
              // branch, same as before.
              setTransportMethod(offerTransportChoice ? null : 'driver');
            }}
          >
            <Icon.ArrowRight className="h-3.5 w-3.5" /> {t('workflow.task.transferCar')}
          </Button>
        </div>
      ) : (
        <div className="mb-3 rounded-xl border border-amber-100 bg-amber-50/70 px-3.5 py-2.5 text-sm text-amber-800">
          {t('workflow.task.noGarage')}
        </div>
      )}

      {/* Transit gap — after a transfer the car is on the road, not in the shop. Faults are held
          "In Transit" and can't be worked until the "Now at Garage" check-in is run at the new garage. */}
      {!carAtGarage && wfStatus === 'in_transit' && garage && (
        <div className="mb-3 flex items-start gap-2 rounded-xl border border-blue-100 bg-blue-50/70 px-3.5 py-2.5 text-[13px] text-blue-800">
          <Icon.Truck className="mt-0.5 h-4 w-4 shrink-0 text-blue-500" />
          <span>
            The car is in transit to <span className="font-semibold">{garage}</span>. Faults are held
            {' '}<span className="font-semibold">In Transit</span> — run the <span className="font-semibold">“Now at Garage”</span>
            {' '}check-in (arrival odometer) to resume work.
          </span>
        </div>
      )}

      {/* Step 1 of Transfer — "How will the vehicle be transferred?" Only asked when the car's CURRENT
          leg is a recovery tow (offerTransportChoice) — it may or may not still need towing onward, so
          it's a genuine open decision. A car that arrived by a normal driver skips this screen entirely
          (see the button's onClick above) and goes straight to the driver transfer form — it's presumably
          still drivable, so there's nothing to ask. Same for a pre-arrival re-route (not carAtGarage). */}
      {transferOpen && garage && offerTransportChoice && !transportMethod && (
        <div className="mb-3 space-y-2.5 rounded-xl bg-slate-50 p-3 ring-1 ring-inset ring-slate-200">
          <p className="text-sm font-semibold text-slate-700">{t('workflow.task.transportMethodQuestion')}</p>
          <div className="grid gap-2 sm:grid-cols-2">
            <button
              type="button"
              onClick={() => setTransportMethod('recovery')}
              className="flex flex-col items-start gap-1 rounded-xl border border-slate-200 bg-white p-3 text-left transition hover:border-red-300 hover:bg-red-50/50"
            >
              <span className="flex items-center gap-1.5 text-sm font-semibold text-slate-800">🛻 {t('workflow.task.transportRecovery')}</span>
              <span className="text-xs text-slate-500">{t('workflow.task.transportRecoveryHint')}</span>
            </button>
            <button
              type="button"
              onClick={() => setTransportMethod('driver')}
              className="flex flex-col items-start gap-1 rounded-xl border border-slate-200 bg-white p-3 text-left transition hover:border-indigo-300 hover:bg-indigo-50/50"
            >
              <span className="flex items-center gap-1.5 text-sm font-semibold text-slate-800">🚗 {t('workflow.task.transportDriver')}</span>
              <span className="text-xs text-slate-500">{t('workflow.task.transportDriverHint')}</span>
            </button>
          </div>
          <div className="flex justify-end">
            <Button size="sm" variant="ghost" onClick={() => setTransferOpen(false)}>{t('common.cancel')}</Button>
          </div>
        </div>
      )}

      {/* Whole-car transfer form — all open faults move together to the next garage (sequential).
          Unchanged from before except it now carries the chosen transport_method along on submit. */}
      {transferOpen && garage && transportMethod && (
        <div className="mb-3 space-y-2 rounded-xl bg-slate-50 p-3 ring-1 ring-inset ring-slate-200">
          {/* The chosen-method chip (+ the ability to change it) only makes sense once a real choice was
              offered — a driver-arrived car or a pre-arrival re-route skipped the dialog entirely, so
              there's nothing to show or change. */}
          {offerTransportChoice && (
            <div className="flex items-center justify-between">
              <span className="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-inset ring-slate-200">
                {transportMethod === 'recovery' ? '🛻' : '🚗'} {transportMethod === 'recovery' ? t('workflow.task.transportRecovery') : t('workflow.task.transportDriver')}
              </span>
              <button type="button" className="text-xs font-medium text-indigo-600 hover:text-indigo-700" onClick={() => setTransportMethod(null)}>
                {t('workflow.task.transportChange')}
              </button>
            </div>
          )}
          <SearchSelect
            value={vendorId}
            onChange={setVendorId}
            options={garageOptions}
            placeholder={t('workflow.task.transferTo')}
          />

          {/* Mileage Gate — mandatory current odometer. The move can't be confirmed without it. */}
          <Input
            type="number"
            min="1"
            inputMode="numeric"
            value={odometer}
            onChange={(e) => { setOdometer(e.target.value); setOdoConfirmed(false); setOdoNote(''); }}
            placeholder="Current odometer (km) — required"
          />
          {/* Shared continuity hint — a transfer is a road trip, so forward travel is expected and waived
              (ignoreTolerance): only a backward "Discrepancy" still surfaces the confirm/note. */}
          <OdometerContinuityHint
            previous={prevOdometer}
            continuity={continuity}
            confirmed={odoConfirmed}
            onConfirm={setOdoConfirmed}
            noteRequired={odoNoteRequired}
            note={odoNote}
            onNote={setOdoNote}
            ignoreTolerance={ignoreOdoTolerance}
            t={t}
          />

          {/* Resolved-Transfer Oversight — all faults already fixed: the note is mandatory and this move
              is recorded for review. Warn plainly so the operator knows why the note is being demanded. */}
          {allFixed && (
            <div className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-[13px] text-amber-900">
              <Icon.Info className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" />
              <span>{t('workflow.task.allFixedTransferWarning')}</span>
            </div>
          )}

          <Textarea
            rows={2}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder={allFixed ? t('workflow.task.transferReasonRequired') : t('workflow.task.transferReason')}
          />

          {/* Conflict Check — the car is already under active repair at another garage. */}
          {conflict && (
            <div className="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2.5 text-sm text-amber-900">
              <p className="flex items-start gap-1.5 font-semibold">
                <span aria-hidden="true">⚠️</span>
                <span>{conflict.message}</span>
              </p>
              <div className="mt-2 flex justify-end gap-2">
                <Button size="sm" variant="ghost" disabled={transferring} onClick={() => setConflict(null)}>
                  {t('common.cancel')}
                </Button>
                <Button size="sm" variant="danger" disabled={transferring} onClick={() => transferCar(true)}>
                  Proceed anyway
                </Button>
              </div>
            </div>
          )}

          <div className="flex justify-end gap-2">
            <Button size="sm" variant="ghost" disabled={transferring} onClick={() => { setTransferOpen(false); setTransportMethod(null); }}>
              {t('common.cancel')}
            </Button>
            <Button size="sm" variant="primary" disabled={transferring || !vendorId || !odoValid || reasonMissing} onClick={() => transferCar(false)}>
              {t('workflow.task.confirmTransfer')}
            </Button>
          </div>
        </div>
      )}

      {tasks.length === 0 ? (
        <p className="py-8 text-center text-sm text-slate-400">{t('workflow.task.noTasks')}</p>
      ) : (
        <ul className="space-y-2.5">
          {tasks.map((task) => {
            const st = TASK_STATUS[task.status] || TASK_STATUS.pending;
            const terminal = TERMINAL.includes(task.status);
            const rowBusy = busyId === task.id;

            const isFixing = fixTask?.id === task.id;
            const isDisputing = disputeTask?.id === task.id;
            // "Mark incorrect" is a delegate's override of the inspector — offered ONLY while the car is
            // actually In Workshop, and only on faults the inspector raised (a garage-found fault isn't
            // his call to overrule). The whole panel is already delegate-gated (opened via canRoute).
            const canDispute = wfStatus === 'under_repair' && task.source === 'inspector' && !terminal;

            return (
              <li key={task.id} className="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    {/* A rejected fault is struck through + greyed so the still-active faults below it
                        stay the clear, actionable ones. */}
                    <p className={`flex items-center gap-1.5 truncate text-sm font-semibold ${task.is_incorrect ? 'text-slate-400 line-through' : 'text-slate-800'}`}>
                      {task.severity_emoji && <span className={task.is_incorrect ? 'no-underline' : undefined}>{task.severity_emoji}</span>}
                      {task.symptom}
                    </p>
                    <div className="mt-1 flex flex-wrap items-center gap-1.5 text-[11px]">
                      <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-semibold ring-1 ring-inset ${st.chip}`}>
                        <span className={`h-1.5 w-1.5 rounded-full ${st.dot}`} />
                        {t(`workflow.task.status.${task.status}`)}
                      </span>
                      {/* Persistent workshop-confirmation verdict badge — stays visible after the fault is fixed. */}
                      {task.confirmation_status && (
                        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-semibold ring-1 ring-inset ${CONFIRM_TONE[task.confirmation_status]?.idle || 'bg-slate-50 text-slate-600 ring-slate-200'}`}>
                          {CONFIRM_TONE[task.confirmation_status]?.icon} {t(`workflow.task.review.${task.confirmation_status}`)}
                        </span>
                      )}
                      {/* Where this fault was fixed — so after a transfer, faults already resolved at the
                          PREVIOUS garage stay attributed to it instead of looking like they're at the new one. */}
                      {task.status === 'completed' && task.current_garage && (
                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 font-medium text-emerald-700 ring-1 ring-inset ring-emerald-200">
                          ✓ {t('workflow.task.fixedAt', { garage: task.current_garage })}
                        </span>
                      )}
                      {/* Routine service performed → its vehicle-sync state: Pending Confirmation until the
                          ticket is closed, then Confirmed. The vehicle record never updates before closure. */}
                      {task.service_confirmation && SERVICE_CONFIRM[task.service_confirmation] && (
                        <Tooltip content={t(`workflow.task.confirm.${task.service_confirmation}Hint`)}>
                          <span className={`inline-flex cursor-help items-center gap-1 rounded-full px-2 py-0.5 font-semibold ring-1 ring-inset ${SERVICE_CONFIRM[task.service_confirmation].chip}`}>
                            {SERVICE_CONFIRM[task.service_confirmation].icon} {t(`workflow.task.confirm.${task.service_confirmation}`)}
                          </span>
                        </Tooltip>
                      )}
                      {/* Delegate overruled the inspector — a mis-diagnosis, distinct from a plain cancel.
                          Hover/focus the badge to read WHY it was rejected (the delegate's reason). */}
                      {task.is_incorrect && (
                        <Tooltip content={task.incorrect_reason}>
                          <span className="inline-flex cursor-help items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 font-medium text-red-700 ring-1 ring-inset ring-red-200">
                            ✕ {t('workflow.task.incorrectBadge')}
                          </span>
                        </Tooltip>
                      )}
                      {/* An open fault physically at a different garage than the car's current one (e.g. still
                          in transit after a transfer) — flag where it actually is. */}
                      {!terminal && task.current_garage && task.current_garage !== garage && (
                        <span className="text-slate-400">· {t('workflow.task.at', { garage: task.current_garage })}</span>
                      )}
                      {task.total_cost > 0 && (
                        <span className="text-slate-400">· {t('workflow.task.cost')} {Number(task.total_cost).toLocaleString()}</span>
                      )}
                    </div>
                  </div>

                  {/* Per-fault status — the only per-fault control; garage routing is whole-car only. */}
                  <div className="flex shrink-0 items-center gap-1.5">
                    {terminal ? (
                      <Button size="sm" variant="ghost" disabled={busy} onClick={() => setStatus(task, 'pending')}>
                        {t('workflow.task.reopen')}
                      </Button>
                    ) : !isFixing && !isDisputing ? (
                      <>
                        {/* "Mark fixed" only appears once the car is actually at the garage — in earlier
                            stages (awaiting dispatch / in transit) there's nothing to mark fixed yet.
                            Opens the fix-evidence panel (video + note) rather than completing outright. */}
                        {carAtGarage && task.repair_gate !== 'pending' && (
                          <Button size="sm" variant="success" disabled={busy || rowBusy || fixTask != null || disputeTask != null} onClick={() => openFix(task)}>
                            {t('workflow.task.complete')}
                          </Button>
                        )}
                        {/* Delegate overrules the inspector — In Workshop only, on his own faults. */}
                        {canDispute && (
                          <Button size="sm" variant="ghost" disabled={busy || rowBusy || fixTask != null || disputeTask != null} onClick={() => openDispute(task)}>
                            {t('workflow.task.markIncorrect')}
                          </Button>
                        )}
                      </>
                    ) : null}
                  </div>
                </div>

                {/* Workshop confirmation gate — In Workshop, the technician reviews EVERY reported fault
                    ("does it exist?") before repair. Only "Confirmed" lets the server open a recurring-fault
                    review. Informational + non-blocking; independent of the repair status below. */}
                {wfStatus === 'under_repair' && !terminal && (
                  <div className="mt-2.5 rounded-lg bg-slate-50 p-2.5 ring-1 ring-inset ring-slate-200">
                    {/* Recurring-fault repair gate. PENDING → a blocking red panel: the repair cannot start
                        until a manager approves it. Otherwise the soft amber "Previous repair found" note.
                        Both surface the previous-repair details from the report-time flag. */}
                    {task.repair_gate === 'pending' ? (
                      <div className="mb-2 rounded-lg border border-red-300 bg-red-50 p-2.5">
                        <p className="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-red-800">
                          <Icon.Alert className="h-3.5 w-3.5" /> {t('workflow.task.review.gateRequired')}
                        </p>
                        <dl className="mt-1.5 space-y-0.5 text-[11px] text-red-900">
                          {task.recurrence?.garage && (
                            <div className="flex flex-wrap gap-x-1.5"><dt className="text-red-700">{t('workflow.task.review.lastRepairedBy')}:</dt><dd className="font-semibold">{task.recurrence.garage}</dd></div>
                          )}
                          {task.recurrence?.repair_days != null && (
                            <div className="flex flex-wrap gap-x-1.5"><dt className="text-red-700">{t('workflow.task.review.repairDuration')}:</dt><dd className="font-semibold">{t('workflow.task.review.durationDays', { n: task.recurrence.repair_days })}</dd></div>
                          )}
                          {task.recurrence?.repaired_on && (
                            <div className="flex flex-wrap gap-x-1.5"><dt className="text-red-700">{t('workflow.task.review.fixedOn')}:</dt><dd className="font-semibold">{fmtDay(task.recurrence.repaired_on)}</dd></div>
                          )}
                        </dl>
                        <p className="mt-1.5 text-[11px] font-semibold text-red-800">
                          {t('workflow.task.review.status')}: {t('workflow.task.review.waitingApproval')}
                        </p>
                        {canApprove ? (
                          <div className="mt-2 flex flex-wrap gap-1.5">
                            <Button size="sm" variant="success" disabled={busy || rowBusy} onClick={() => repairApproval(task, 'approve')}>
                              {t('workflow.task.review.approveRepair')}
                            </Button>
                            <Button size="sm" variant="ghost" className="text-red-600 hover:bg-red-100" disabled={busy || rowBusy} onClick={() => repairApproval(task, 'reject')}>
                              {t('workflow.task.review.reject')}
                            </Button>
                          </div>
                        ) : (
                          <p className="mt-1.5 text-[11px] italic text-red-600">{t('workflow.task.review.waitingApprovalHint')}</p>
                        )}
                      </div>
                    ) : task.recurrence_flagged ? (
                      <div className="mb-2 rounded-lg border border-amber-300 bg-amber-50 p-2.5">
                        <p className="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-amber-800">
                          <Icon.Alert className="h-3.5 w-3.5" /> {t('workflow.task.review.prevRepairFound')}
                        </p>
                        <dl className="mt-1.5 space-y-0.5 text-[11px] text-amber-900">
                          {task.recurrence?.garage && (
                            <div className="flex flex-wrap gap-x-1.5"><dt className="text-amber-700">{t('workflow.task.review.lastRepairedBy')}:</dt><dd className="font-semibold">{task.recurrence.garage}</dd></div>
                          )}
                          {task.recurrence?.repair_days != null && (
                            <div className="flex flex-wrap gap-x-1.5"><dt className="text-amber-700">{t('workflow.task.review.repairDuration')}:</dt><dd className="font-semibold">{t('workflow.task.review.durationDays', { n: task.recurrence.repair_days })}</dd></div>
                          )}
                          {task.recurrence?.repaired_on && (
                            <div className="flex flex-wrap gap-x-1.5"><dt className="text-amber-700">{t('workflow.task.review.fixedOn')}:</dt><dd className="font-semibold">{fmtDay(task.recurrence.repaired_on)}</dd></div>
                          )}
                        </dl>
                        {task.repair_gate === 'approved' ? (
                          <p className="mt-1.5 flex items-center gap-1 text-[11px] font-semibold text-emerald-700">
                            ✓ {t('workflow.task.review.repairApprovedBy', { name: task.repair_gate_by || '—' })}
                          </p>
                        ) : (
                          <p className="mt-1.5 text-[11px] font-medium text-amber-800">{t('workflow.task.review.mayBeRecurring')}</p>
                        )}
                      </div>
                    ) : null}
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{t('workflow.task.review.title')}</p>
                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                      {CONFIRM_VERDICTS.map((v) => {
                        const activeVerdict = task.confirmation_status === v;
                        return (
                          <button
                            key={v}
                            type="button"
                            disabled={busy || rowBusy}
                            onClick={() => confirmTask(task, v)}
                            className={`rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset transition-colors disabled:opacity-50 ${activeVerdict ? CONFIRM_TONE[v].active : CONFIRM_TONE[v].idle}`}
                          >
                            {CONFIRM_TONE[v].icon} {t(`workflow.task.review.${v}`)}
                          </button>
                        );
                      })}
                    </div>
                    {task.confirmation_status && task.confirmed_by && (
                      <p className="mt-1.5 text-[11px] text-slate-400">{t('workflow.task.review.reviewedBy', { name: task.confirmed_by })}</p>
                    )}
                  </div>
                )}

                {/* Fix Evidence — capture a repair video + resolution note before completing the fault. */}
                {isFixing && (
                  <div className="mt-3 space-y-2 rounded-lg bg-emerald-50/60 p-3 ring-1 ring-inset ring-emerald-200">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-emerald-700">Mark fixed — add evidence</p>

                    <p className="text-[11px] text-emerald-700">A resolution note and a repair photo or video are both required to mark this fault fixed.</p>

                    <Textarea
                      rows={2}
                      value={fixNote}
                      onChange={(e) => setFixNote(e.target.value)}
                      placeholder="Resolution note — what was done to fix it (required)"
                    />

                    {/* Routine service (oil / battery): capture the odometer AT the change so the next
                        service reminder is scheduled from the right reading. Pre-filled with the last
                        known mileage; optional (falls back to the ticket reading server-side). */}
                    {fixTask?.routine_service_type && (
                      <div className="rounded-lg bg-white px-3 py-2 ring-1 ring-inset ring-emerald-200">
                        <label className="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-emerald-700">
                          Odometer at service (km)
                        </label>
                        <input
                          type="number"
                          min="0"
                          inputMode="numeric"
                          value={fixOdometer}
                          onChange={(e) => setFixOdometer(e.target.value)}
                          placeholder="Reading when the service was done"
                          className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 placeholder:text-slate-400 focus:border-emerald-400 focus:outline-none focus:ring-1 focus:ring-emerald-400"
                        />
                        <p className="mt-1 text-[11px] text-slate-400">Schedules the next {fixTask.routine_service_type.replace('_', ' ')} reminder from this reading.</p>
                      </div>
                    )}

                    <label className={`flex cursor-pointer items-center gap-2 rounded-lg border border-dashed px-3 py-2 text-sm hover:bg-emerald-50 ${fixVideo ? 'border-emerald-300 bg-white text-slate-600' : 'border-emerald-400 bg-white text-emerald-700'}`}>
                      <Icon.Video className="h-4 w-4 text-emerald-600" />
                      <span className="truncate">{fixVideo ? fixVideo.name : 'Attach repair photo or video (required)'}</span>
                      <input type="file" accept="video/*,image/*" capture="environment" className="hidden" disabled={fixBusy} onChange={pickVideo} />
                    </label>
                    {fixVideo && (
                      <p className="px-0.5 text-[11px] text-slate-400">
                        {(fixVideo.size / (1024 * 1024)).toFixed(1)} MB
                        <button type="button" className="ml-2 text-slate-400 underline hover:text-slate-600" disabled={fixBusy} onClick={() => setFixVideo(null)}>remove</button>
                      </p>
                    )}

                    {fixError && <p className="text-xs font-medium text-red-600">{fixError}</p>}
                    {fixBusy && fixStage && <p className="text-xs font-medium text-emerald-700">{FIX_STAGE_LABEL[fixStage] || 'Working…'}</p>}

                    <div className="flex justify-end gap-2">
                      <Button size="sm" variant="ghost" disabled={fixBusy} onClick={closeFix}>{t('common.cancel')}</Button>
                      <Button size="sm" variant="success" disabled={fixBusy || !fixValid} onClick={submitFix}>{t('workflow.task.complete')}</Button>
                    </div>
                  </div>
                )}

                {/* Mark incorrect — the delegate's reason for overruling the inspector (mandatory). */}
                {isDisputing && (
                  <div className="mt-3 space-y-2 rounded-lg bg-red-50/60 p-3 ring-1 ring-inset ring-red-200">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-red-700">{t('workflow.task.markIncorrect')}</p>
                    <p className="text-[11px] text-red-700">{t('workflow.task.incorrectHint')}</p>
                    <Textarea
                      rows={2}
                      value={disputeReason}
                      onChange={(e) => setDisputeReason(e.target.value)}
                      placeholder={t('workflow.task.incorrectReason')}
                    />
                    {disputeError && <p className="text-xs font-medium text-red-600">{disputeError}</p>}
                    <div className="flex justify-end gap-2">
                      <Button size="sm" variant="ghost" disabled={disputeBusy} onClick={closeDispute}>{t('common.cancel')}</Button>
                      <Button size="sm" variant="danger" disabled={disputeBusy || disputeReason.trim() === ''} onClick={submitDispute}>{t('workflow.task.markIncorrect')}</Button>
                    </div>
                  </div>
                )}

                {/* Who overruled the inspector (the WHY sits in the badge tooltip). */}
                {!isDisputing && task.is_incorrect && (
                  <div className="mt-2 border-t border-red-100 pt-2 text-[12px] text-red-500">
                    {t('workflow.task.incorrectBy', { name: task.marked_incorrect_by || '—' })}
                  </div>
                )}

                {/* Resolution note + video evidence on a fixed fault. */}
                {!isFixing && terminal && !task.is_incorrect && (task.resolution_note || (task.media && task.media.length > 0)) && (
                  <div className="mt-2 space-y-1.5 border-t border-slate-100 pt-2">
                    {task.resolution_note && (
                      <p className="text-[12px] text-slate-500"><span className="font-semibold text-slate-600">Fix note:</span> {task.resolution_note}</p>
                    )}
                    {(task.media || []).map((m) => (
                      <a key={m.id} href={m.url || undefined} target="_blank" rel="noreferrer"
                         className={`inline-flex items-center gap-1.5 text-[12px] font-medium ${m.url ? 'text-emerald-700 hover:underline' : 'cursor-not-allowed text-slate-400'}`}>
                        <Icon.Video className="h-3.5 w-3.5" /> {m.original_name || 'Repair video'}
                      </a>
                    ))}
                  </div>
                )}
              </li>
            );
          })}
        </ul>
      )}
    </Modal>
  );
}
