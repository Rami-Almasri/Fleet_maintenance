// Complaint Triage — Abu Maroof's decision hub for a logged customer complaint. A complaint no longer
// jumps straight to the garage-dispatch queue; it lands here (workflow_status = complaint_triage) and he
// picks how to handle it, so unnecessary garage trips are filtered out of the pipeline:
//
//   1. 📞 Talk to the customer  → POST /maintenance-tickets/{id}/triage/call    (logged touchpoint; stays open)
//   2. ✅ Resolved on site      → POST /maintenance-tickets/{id}/triage/resolve  (closes the complaint)
//   3. 🚗 Send the car in       → POST /maintenance-tickets/{id}/triage/route    (garage OR diagnostic,
//                                                                                 + optional replacement swap)
//
// The replacement, when chosen, is recorded on the shared Maintenance Swap board so "who is driving what"
// stays in one place.

import { useEffect, useMemo, useState } from 'react';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Modal from '../ui/Modal';
import Button from '../ui/Button';
import Icon from '../ui/Icon';
import { Textarea } from '../ui/Field';
import SearchSelect from '../ui/SearchSelect';

export default function ComplaintTriageModal({ ticket, vehicles = [], onClose, onDone }) {
  const { t } = useI18n();
  const [callNote, setCallNote] = useState('');
  const [resolveNote, setResolveNote] = useState('');
  const [sendNote, setSendNote] = useState('');
  const [replacementId, setReplacementId] = useState('');
  const [pool, setPool] = useState(null);       // available cars for a replacement (from the swap board)
  const [busy, setBusy] = useState('');          // which action is in flight ('call'|'resolve'|'garage'|'diagnostic')
  const [error, setError] = useState(null);

  const id = ticket?.id;

  // Pull the swap board's available POOL (the smart list that excludes cars on an open contract). Falls
  // back to the ready cars already loaded on the board if the inspector can't read the swap board.
  useEffect(() => {
    let alive = true;
    api.get('/maintenance-swaps/board')
      .then((r) => { if (alive) setPool(Array.isArray(r.data?.data?.pool) ? r.data.data.pool : []); })
      .catch(() => { if (alive) setPool(null); });
    return () => { alive = false; };
  }, []);

  // Replacement options: prefer the swap pool; else the board's ready cars (minus the complained car).
  const replacementOptions = useMemo(() => {
    const src = pool != null
      ? pool.map((p) => ({ id: p.vehicle_id, plate: p.plate, car: p.car, year: p.year }))
      : vehicles
          .filter((v) => v.status === 'ready' && v.id !== ticket?.vehicle_id)
          .map((v) => ({ id: v.id, plate: v.plate_no, car: [v.make, v.model].filter(Boolean).join(' '), year: v.year }));
    return src.map((o) => ({
      id: o.id,
      label: o.plate || `#${o.id}`,
      sub: [o.car, o.year].filter(Boolean).join(' · ') || undefined,
    }));
  }, [pool, vehicles, ticket]);

  const post = async (path, body, action, successKey) => {
    if (busy) return;
    setBusy(action);
    setError(null);
    try {
      await api.post(`/maintenance-tickets/${id}/triage/${path}`, body);
      onDone?.(t(successKey));
    } catch (e) {
      setError(e?.response?.data?.message || t('workflow.error.generic'));
      setBusy('');
    }
  };

  const logCall   = () => post('call', { note: callNote.trim() || undefined }, 'call', 'workflow.triage.callSuccess');
  const resolve   = () => post('resolve', { note: resolveNote.trim() || undefined }, 'resolve', 'workflow.triage.resolveSuccess');
  const sendIn = (destination) => post(
    'route',
    { destination, replacement_vehicle_id: replacementId ? Number(replacementId) : undefined, note: sendNote.trim() || undefined },
    destination,
    destination === 'garage' ? 'workflow.triage.garageSuccess' : 'workflow.triage.diagnosticSuccess',
  );

  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      title={t('workflow.triage.title')}
      subtitle={t('workflow.triage.subtitle')}
      footer={<Button variant="ghost" onClick={onClose} disabled={!!busy}>{t('common.close')}</Button>}
    >
      <div className="space-y-4">
        {/* The complaint in question — car + what the customer reported. */}
        <div className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5">
          <span className="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-rose-700">
            📣 {t('workflow.complaint.badge')}
          </span>
          <p className="mt-1 flex flex-wrap items-center gap-x-2 text-sm">
            <span className="font-mono font-bold tracking-wider text-slate-800" dir="ltr">{ticket?.plate || `#${id}`}</span>
            {ticket?.car && <span className="text-slate-500">{ticket.car}</span>}
          </p>
          {ticket?.customer_complaint && (
            <p className="mt-1 text-sm text-slate-700">“{ticket.customer_complaint}”</p>
          )}
        </div>

        {/* Option 1 — Talk to the customer (a logged touchpoint; the complaint stays in triage). */}
        <section className="rounded-xl border border-slate-200 p-3.5">
          <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-800">
            <span aria-hidden>📞</span> {t('workflow.triage.callTitle')}
          </h3>
          <p className="mt-0.5 text-xs text-slate-500">{t('workflow.triage.callHint')}</p>
          <Textarea
            className="mt-2"
            rows={2}
            value={callNote}
            onChange={(e) => setCallNote(e.target.value)}
            placeholder={t('workflow.triage.notePlaceholder')}
            maxLength={2000}
          />
          <div className="mt-2 flex justify-end">
            <Button size="sm" variant="secondary" onClick={logCall} loading={busy === 'call'} disabled={!!busy && busy !== 'call'}>
              {t('workflow.triage.callBtn')}
            </Button>
          </div>
        </section>

        {/* Option 2 — Resolved on site (closes the complaint, no garage trip). */}
        <section className="rounded-xl border border-slate-200 p-3.5">
          <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-800">
            <span aria-hidden>✅</span> {t('workflow.triage.resolveTitle')}
          </h3>
          <p className="mt-0.5 text-xs text-slate-500">{t('workflow.triage.resolveHint')}</p>
          <Textarea
            className="mt-2"
            rows={2}
            value={resolveNote}
            onChange={(e) => setResolveNote(e.target.value)}
            placeholder={t('workflow.triage.resolvePlaceholder')}
            maxLength={2000}
          />
          <div className="mt-2 flex justify-end">
            <Button size="sm" variant="success" onClick={resolve} loading={busy === 'resolve'} disabled={!!busy && busy !== 'resolve'}>
              {t('workflow.triage.resolveBtn')}
            </Button>
          </div>
        </section>

        {/* Option 3 — Send the car in: optional replacement + choose the destination. */}
        <section className="rounded-xl border border-slate-200 p-3.5">
          <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-800">
            <span aria-hidden>🚗</span> {t('workflow.triage.sendTitle')}
          </h3>
          <p className="mt-0.5 text-xs text-slate-500">{t('workflow.triage.sendHint')}</p>

          {/* Optional replacement — wired to the Maintenance Swap board. */}
          <div className="mt-2.5">
            <span className="mb-1 block text-xs font-medium text-slate-600">{t('workflow.triage.replacementLabel')}</span>
            <SearchSelect
              value={replacementId}
              onChange={setReplacementId}
              options={replacementOptions}
              placeholder={t('workflow.triage.replacementPlaceholder')}
              loading={pool === null && replacementOptions.length === 0}
            />
            {replacementId && (
              <button type="button" onClick={() => setReplacementId('')} className="mt-1 text-[11px] font-medium text-slate-400 hover:text-slate-600">
                {t('workflow.triage.clearReplacement')}
              </button>
            )}
          </div>

          <Textarea
            className="mt-2.5"
            rows={2}
            value={sendNote}
            onChange={(e) => setSendNote(e.target.value)}
            placeholder={t('workflow.triage.notePlaceholder')}
            maxLength={2000}
          />

          {/* The two destination buttons — Abu Maroof's call on where the car goes. */}
          <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
            <Button onClick={() => sendIn('garage')} loading={busy === 'garage'} disabled={!!busy && busy !== 'garage'} className="justify-center">
              <Icon.Wrench className="h-4 w-4" /> {t('workflow.triage.toGarage')}
            </Button>
            <Button variant="secondary" onClick={() => sendIn('diagnostic')} loading={busy === 'diagnostic'} disabled={!!busy && busy !== 'diagnostic'} className="justify-center">
              <Icon.Search className="h-4 w-4" /> {t('workflow.triage.toDiagnostic')}
            </Button>
          </div>
          <p className="mt-2 text-[11px] text-slate-400">{t('workflow.triage.sendFootnote')}</p>
        </section>

        {error && <p className="text-sm text-red-600">{error}</p>}
      </div>
    </Modal>
  );
}
