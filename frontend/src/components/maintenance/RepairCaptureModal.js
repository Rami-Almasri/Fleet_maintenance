// Repair capture — "Complete repair", in four steps.
//
// The technician is completing a job, not filling in a data model. Nothing here mentions events,
// facts, judgements or confidence, and no step asks a question a person standing next to a car
// couldn't answer in a few seconds. The structure the platform needs is a consequence of the flow,
// never a demand made of the user.
//
// THERE IS NO VERIFICATION STEP HERE, AND THAT IS THE POINT. This form asks only what the workshop
// can honestly answer: what was wrong, what was done, and whether they think it worked. Confirming
// the repair is a separate act by an inspector (RepairVerifyModal) behind a different permission,
// because the person who performed a repair must never be the only person confirming it. An earlier
// version collected both here, which made every verification self-reported by construction.
//
// PROGRESSIVE, NOT PAGINATED. Each step appears when the previous one is answered, so the screen
// only ever shows what is relevant now. A technician who knows exactly what they did can go
// start-to-finish without reading anything twice.
//
// Backend: MaintenanceWorkflowController@captureOptions / @captureRepair.

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useI18n } from '../../i18n/I18nContext';
import Modal from '../ui/Modal';
import Button from '../ui/Button';
import { Textarea } from '../ui/Field';
import { useToast } from '../ui/Toast';
import {
  useRepairCaptureVocab,
  getCaptureOptions, submitCapture, createFrictionTracker,
  startCapture, abandonCapture,
} from '../../lib/repairCapture';

const TONES = {
  emerald: 'ring-emerald-300 bg-emerald-50 text-emerald-800',
  amber:   'ring-amber-300 bg-amber-50 text-amber-800',
  orange:  'ring-orange-300 bg-orange-50 text-orange-800',
  red:     'ring-red-300 bg-red-50 text-red-800',
  slate:   'ring-slate-300 bg-slate-50 text-slate-700',
};

function StepHeader({ n, title, done }) {
  return (
    <div className="flex items-center gap-2.5">
      <span
        className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${
          done ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-600'
        }`}
      >
        {done ? '✓' : n}
      </span>
      <h3 className="text-sm font-semibold text-slate-800">{title}</h3>
    </div>
  );
}

/**
 * Step 2's picker.
 *
 * Suggested actions come from the server already filtered to the fault's system, so it opens on a
 * handful of relevant entries rather than the full catalogue — a picker that needs scrolling is one
 * technicians route around, and a free-text box is what they route around it TO.
 */
function ActionPicker({ suggestions, chosen, onAdd, onRemove, onMove, onNote }) {
  const { t } = useI18n();
  const [query, setQuery] = useState('');

  const results = useMemo(() => {
    const q = query.trim().toLowerCase();
    const chosenIds = new Set(chosen.map((c) => c.action_catalog_id));
    return suggestions
      .filter((a) => !chosenIds.has(a.id))
      .filter((a) => !q || a.label.toLowerCase().includes(q) || a.target?.toLowerCase().includes(q));
  }, [suggestions, chosen, query]);

  return (
    <div className="space-y-3">
      {chosen.length > 0 && (
        <ol className="space-y-2">
          {chosen.map((c, i) => (
            <li key={c.action_catalog_id} className="rounded-lg bg-white p-2.5 ring-1 ring-slate-200">
              <div className="flex items-center gap-2">
                <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-slate-100 text-[11px] font-semibold text-slate-600">
                  {i + 1}
                </span>
                <span className="flex-1 text-sm font-medium text-slate-800">{c.label}</span>

                {/* Marked, not blocked. The parts link may legitimately be recorded later, and a
                    picker that refuses to move on is a picker people abandon. */}
                {c.requires_part && (
                  <span className="rounded bg-blue-50 px-1.5 py-0.5 text-[10px] font-medium text-blue-700 ring-1 ring-blue-200">
                    {t('needs part')}
                  </span>
                )}

                <div className="flex items-center gap-0.5">
                  <button
                    type="button"
                    onClick={() => onMove(i, -1)}
                    disabled={i === 0}
                    className="rounded px-1.5 py-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30"
                    aria-label={t('Move earlier')}
                  >
                    ↑
                  </button>
                  <button
                    type="button"
                    onClick={() => onMove(i, 1)}
                    disabled={i === chosen.length - 1}
                    className="rounded px-1.5 py-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30"
                    aria-label={t('Move later')}
                  >
                    ↓
                  </button>
                  <button
                    type="button"
                    onClick={() => onRemove(i)}
                    className="rounded px-1.5 py-0.5 text-slate-400 hover:bg-red-50 hover:text-red-600"
                    aria-label={t('Remove')}
                  >
                    ✕
                  </button>
                </div>
              </div>
              <input
                value={c.note || ''}
                onChange={(e) => onNote(i, e.target.value)}
                placeholder={t('Note (optional)')}
                className="mt-1.5 w-full rounded border-0 bg-slate-50 px-2 py-1 text-xs text-slate-700 ring-1 ring-inset ring-slate-200 placeholder:text-slate-400 focus:ring-2 focus:ring-indigo-500"
              />
            </li>
          ))}
        </ol>
      )}

      <input
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        placeholder={chosen.length ? t('Add another action…') : t('Search actions…')}
        className="w-full rounded-lg border-0 px-3 py-2 text-sm text-slate-800 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-indigo-500"
      />

      <div className="flex flex-wrap gap-1.5">
        {results.slice(0, 14).map((a) => (
          <button
            key={a.id}
            type="button"
            onClick={() => { onAdd(a); setQuery(''); }}
            className={`rounded-full px-2.5 py-1 text-xs font-medium ring-1 transition ${
              a.is_verification
                ? 'bg-violet-50 text-violet-700 ring-violet-200 hover:bg-violet-100'
                : 'bg-white text-slate-700 ring-slate-300 hover:bg-slate-50 hover:ring-slate-400'
            }`}
          >
            + {a.label}
          </button>
        ))}
        {results.length === 0 && query && (
          <p className="text-xs text-slate-500">
            {t('No match. Try a different word — or record the closest action and add a note.')}
          </p>
        )}
      </div>
    </div>
  );
}

export default function RepairCaptureModal({ open, taskId, onClose, onSaved }) {
  const { t } = useI18n();
  const toast = useToast();
  const { outcomes } = useRepairCaptureVocab();

  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [options, setOptions] = useState(null);
  const [errors, setErrors] = useState({});

  const [actions, setActions] = useState([]);
  const [outcome, setOutcome] = useState(null);
  const [noFaultFound, setNoFaultFound] = useState(false);
  const [note, setNote] = useState('');

  // Four fields offered: actions, outcome, note, no-fault-found.
  const friction = useRef(null);
  const sessionId = useRef(null);
  const saved = useRef(false);

  useEffect(() => {
    if (!open || !taskId) return;

    let cancelled = false;
    setLoading(true);
    setErrors({});
    saved.current = false;

    getCaptureOptions(taskId)
      .then((data) => {
        if (cancelled) return;
        setOptions(data);

        // Re-opening a captured fault must show what is already there, or the technician will
        // reasonably assume nothing saved and enter it twice.
        const prior = data?.captured || {};
        setActions(
          (prior.actions || []).map((a) => ({
            action_catalog_id: a.action_catalog_id,
            label: a.label,
            note: a.note || '',
            requires_part: false,
          })),
        );
        setOutcome(prior.claimed_outcome || null);
        setNoFaultFound(Boolean(prior.no_fault_found));

        friction.current = createFrictionTracker(4);
      })
      .catch(() => !cancelled && toast.error(t('Could not load the capture form.')))
      .finally(() => !cancelled && setLoading(false));

    // Opened separately from loading the options so a slow options request never delays the start
    // stamp — the clock should run from when the technician saw the form, not from when we finished
    // preparing it.
    startCapture(taskId).then((id) => { if (!cancelled) sessionId.current = id; });

    return () => { cancelled = true; };
  }, [open, taskId, toast, t]);

  /**
   * Closing without saving is an abandonment, and is recorded as one.
   *
   * It is still allowed — a form that traps people gets worked around within a week, and a
   * technician held hostage produces worse data than one who leaves and comes back. The telemetry
   * exists to find out WHY they left, not to stop them.
   */
  const handleClose = useCallback(() => {
    if (!saved.current && sessionId.current) {
      const step = noFaultFound ? 1 : (outcome ? 3 : (actions.length ? 2 : 1));
      abandonCapture(taskId, sessionId.current, {
        duration_ms: friction.current?.payload()?.duration_ms,
        last_step: step,
      });
      sessionId.current = null;
    }
    onClose?.();
  }, [taskId, onClose, actions.length, outcome, noFaultFound]);

  const addAction = useCallback((a) => {
    setActions((prev) => [
      ...prev,
      { action_catalog_id: a.id, label: a.label, requires_part: a.requires_part, note: '' },
    ]);
  }, []);

  const moveAction = useCallback((i, delta) => {
    setActions((prev) => {
      const next = [...prev];
      const j = i + delta;
      if (j < 0 || j >= next.length) return prev;
      [next[i], next[j]] = [next[j], next[i]];
      return next;
    });
  }, []);

  const step1Done = Boolean(options);
  const step2Done = noFaultFound || actions.length > 0;
  const step3Done = noFaultFound || Boolean(outcome);

  const canSubmit = !saving && (noFaultFound || (step2Done && step3Done));

  async function handleSubmit() {
    setSaving(true);
    setErrors({});

    const tracker = friction.current?.payload() || {};

    // What was shown and deliberately left empty — the signal that says whether a field earns its
    // place. A field skipped by everybody should be removed, and this is the evidence for that call.
    const skipped = [];
    if (!note.trim()) skipped.push('note');

    try {
      await submitCapture(taskId, {
        actions: noFaultFound
          ? []
          : actions.map((a) => ({ action_catalog_id: a.action_catalog_id, note: a.note || null })),
        claimed_outcome: noFaultFound ? null : outcome,
        no_fault_found: noFaultFound,
        note: note.trim() || null,
        session_id: sessionId.current,
        duration_ms: tracker.duration_ms,
        last_step: noFaultFound ? 1 : 3,
        skipped_fields: skipped,
      });

      // Set before closing so handleClose does not also fire an abandon for a session that
      // completed — browsers deliver those out of order often enough for it to matter.
      saved.current = true;
      toast.success(t('Repair recorded'));
      onSaved?.();
      onClose?.();
    } catch (e) {
      const bag = e?.response?.data?.errors || {};
      setErrors(bag);
      toast.error(
        Object.values(bag).flat()[0] || e?.response?.data?.message || t('Could not save the repair.'),
      );
    } finally {
      setSaving(false);
    }
  }

  const fault = options?.fault;

  return (
    <Modal
      open={open}
      onClose={handleClose}
      title={t('Complete repair')}
      subtitle={fault ? fault.symptom : undefined}
      size="lg"
      footer={
        // w-full because Modal's footer wraps children in `justify-end`; without it the
        // no-fault-found control would be pinned against the buttons instead of opposite them.
        <div className="flex w-full items-center justify-between gap-3">
          <label className="flex cursor-pointer items-center gap-2 text-xs text-slate-600">
            <input
              type="checkbox"
              checked={noFaultFound}
              onChange={(e) => setNoFaultFound(e.target.checked)}
              className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
            />
            {/* The honest escape hatch. Without it the only way to close a fault that was never
                there is to invent a repair for it. */}
            {t('No fault found — nothing was wrong')}
          </label>
          <div className="flex gap-2">
            <Button variant="secondary" onClick={handleClose} disabled={saving}>{t('Cancel')}</Button>
            <Button onClick={handleSubmit} disabled={!canSubmit} loading={saving}>
              {t('Save repair')}
            </Button>
          </div>
        </div>
      }
    >
      {loading && <p className="py-8 text-center text-sm text-slate-500">{t('Loading…')}</p>}

      {!loading && options && (
        <div className="space-y-6">
          {/* ── Step 1 — confirm the problem ─────────────────────────────────────────── */}
          <section className="space-y-2">
            <StepHeader n={1} title={t('The problem')} done={step1Done} />
            <dl className="grid grid-cols-1 gap-x-6 gap-y-1.5 rounded-lg bg-slate-50 p-3 text-sm sm:grid-cols-2">
              <div className="flex gap-2">
                <dt className="text-slate-500">{t('Reported')}</dt>
                <dd className="font-medium text-slate-800">{fault?.symptom || '—'}</dd>
              </div>
              <div className="flex gap-2">
                <dt className="text-slate-500">{t('Diagnosis')}</dt>
                <dd className="font-medium text-slate-800">
                  {fault?.root_cause || <span className="italic text-slate-400">{t('not recorded')}</span>}
                </dd>
              </div>
            </dl>
          </section>

          {!noFaultFound && (
            <>
              {/* ── Step 2 — what was done ─────────────────────────────────────────── */}
              <section className="space-y-2">
                <StepHeader n={2} title={t('What did you do?')} done={step2Done} />
                <p className="text-xs text-slate-500">{t('In the order you did it.')}</p>
                <ActionPicker
                  suggestions={options.suggested_actions || []}
                  chosen={actions}
                  onAdd={addAction}
                  onRemove={(i) => setActions((p) => p.filter((_, j) => j !== i))}
                  onMove={moveAction}
                  onNote={(i, v) =>
                    setActions((p) => p.map((a, j) => (j === i ? { ...a, note: v } : a)))}
                />
                {errors.actions && <p className="text-xs text-red-600">{errors.actions[0]}</p>}
              </section>

              {/* ── Step 3 — did it work ───────────────────────────────────────────── */}
              {step2Done && (
                <section className="space-y-2">
                  <StepHeader n={3} title={t('Did it fix the problem?')} done={step3Done} />
                  <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    {outcomes.map((o) => (
                      <button
                        key={o.value}
                        type="button"
                        onClick={() => setOutcome(o.value)}
                        className={`rounded-lg px-3 py-2 text-start ring-1 transition ${
                          outcome === o.value
                            ? `${TONES[o.tone]} ring-2`
                            : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50'
                        }`}
                      >
                        <span className="block text-sm font-medium">{o.label}</span>
                        <span className="block text-xs opacity-80">{o.hint}</span>
                      </button>
                    ))}
                  </div>
                  {errors.claimed_outcome && (
                    <p className="text-xs text-red-600">{errors.claimed_outcome[0]}</p>
                  )}
                </section>
              )}

              {/* Verification is NOT asked for here — an inspector confirms the repair separately.
                  Said out loud so nobody thinks the step went missing, and so the technician knows
                  their claim is going to be checked by someone else. */}
              {step3Done && (
                <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 ring-1 ring-slate-200">
                  {t('An inspector will confirm this repair separately — you don’t need to verify your own work.')}
                </p>
              )}
            </>
          )}

          {noFaultFound && (
            <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 ring-1 ring-amber-200">
              {t('Recorded as “no fault found” — no repair actions or outcome needed.')}
            </p>
          )}

          <Textarea
            label={t('Anything else? (optional)')}
            rows={2}
            value={note}
            onChange={(e) => setNote(e.target.value)}
            placeholder={t('Notes for whoever sees this car next')}
          />
        </div>
      )}
    </Modal>
  );
}
