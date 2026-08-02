// "Verify repair" — the inspector's independent confirmation.
//
// Deliberately a SEPARATE screen from repair capture, behind a separate permission
// (inspections.manage), because the person who performed a repair must never be the only person
// confirming it. The workshop says *we repaired it*; the inspector says *we verified it*; only then
// can the system ask *was it actually successful?* and mean anything by the answer.
//
// The server enforces the rule that matters: whoever recorded the repair cannot verify it, even if
// they hold both permissions. This screen surfaces that refusal in plain language rather than
// letting someone fill in a form and be rejected at the end.
//
// Backend: MaintenanceWorkflowController@verificationOptions / @verifyRepair.

import { useEffect, useState } from 'react';
import Modal from '../ui/Modal';
import Button from '../ui/Button';
import { Textarea } from '../ui/Field';
import { useToast } from '../ui/Toast';
import {
  VERIFICATION_RESULTS, VERIFICATION_METHODS,
  getVerificationOptions, submitVerification, outcomeMeta,
} from '../../lib/repairCapture';

const TONES = {
  emerald: 'ring-emerald-300 bg-emerald-50 text-emerald-800',
  amber:   'ring-amber-300 bg-amber-50 text-amber-800',
  red:     'ring-red-300 bg-red-50 text-red-800',
  slate:   'ring-slate-300 bg-slate-50 text-slate-700',
};

export default function RepairVerifyModal({ open, taskId, onClose, onSaved }) {
  const toast = useToast();

  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [data, setData] = useState(null);
  const [errors, setErrors] = useState({});

  const [result, setResult] = useState(null);
  const [method, setMethod] = useState(null);
  const [note, setNote] = useState('');

  useEffect(() => {
    if (!open || !taskId) return;

    let cancelled = false;
    setLoading(true);
    setErrors({});
    setResult(null);
    setMethod(null);
    setNote('');

    getVerificationOptions(taskId)
      .then((d) => {
        if (cancelled) return;
        setData(d);
        // Re-opening an already-verified fault shows the existing verdict rather than a blank form.
        if (d?.verified) {
          setResult(d.verified.result);
          setMethod(d.verified.method);
          setNote(d.verified.note || '');
        }
      })
      .catch(() => !cancelled && toast.error('Could not load the verification form.'))
      .finally(() => !cancelled && setLoading(false));

    return () => { cancelled = true; };
  }, [open, taskId, toast]);

  const blocked = data && data.can_verify === false;
  const claim = data?.workshop_claim;
  const claimMeta = claim?.claimed_outcome ? outcomeMeta(claim.claimed_outcome) : null;

  async function handleSubmit() {
    setSaving(true);
    setErrors({});
    try {
      await submitVerification(taskId, { result, method, note: note.trim() || null });
      toast.success('Verification recorded');
      onSaved?.();
      onClose?.();
    } catch (e) {
      const bag = e?.response?.data?.errors || {};
      setErrors(bag);
      toast.error(
        Object.values(bag).flat()[0] || e?.response?.data?.message || 'Could not save the verification.',
      );
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Verify repair"
      subtitle={data?.fault?.symptom}
      size="lg"
      footer={
        <div className="flex gap-2">
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button
            onClick={handleSubmit}
            disabled={saving || blocked || !result || !method}
            loading={saving}
          >
            Record verification
          </Button>
        </div>
      }
    >
      {loading && <p className="py-8 text-center text-sm text-slate-500">Loading…</p>}

      {!loading && data && (
        <div className="space-y-5">
          {/* The refusal, in the inspector's language. Shown instead of the form rather than as a
              validation error after they have filled it in. */}
          {blocked && (
            <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 ring-1 ring-amber-200">
              {data.reason}
            </p>
          )}

          {/* What the workshop claimed. Shown first so the inspector checks a stated claim rather
              than forming an impression from scratch — and so a disagreement is a deliberate act. */}
          <section className="space-y-2">
            <h3 className="text-sm font-semibold text-slate-800">What the workshop reported</h3>
            <div className="rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200">
              {claim?.no_fault_found ? (
                <p className="text-sm text-slate-700">No fault found — nothing was repaired.</p>
              ) : (
                <>
                  <div className="flex items-center gap-2">
                    <span className="text-xs text-slate-500">Their verdict</span>
                    {claimMeta ? (
                      <span className={`rounded-full px-2 py-0.5 text-xs font-medium ring-1 ${TONES[claimMeta.tone] || TONES.slate}`}>
                        {claimMeta.label}
                      </span>
                    ) : (
                      <span className="text-xs italic text-slate-400">not recorded</span>
                    )}
                  </div>
                  {claim?.actions?.length > 0 && (
                    <ol className="mt-2 space-y-1">
                      {claim.actions.map((a) => (
                        <li key={a.sequence} className="flex gap-2 text-sm text-slate-700">
                          <span className="text-slate-400">{a.sequence}.</span>
                          <span>
                            {a.label}
                            {a.note && <span className="text-slate-500"> — {a.note}</span>}
                          </span>
                        </li>
                      ))}
                    </ol>
                  )}
                </>
              )}
            </div>
          </section>

          {!blocked && (
            <>
              <section className="space-y-2">
                <h3 className="text-sm font-semibold text-slate-800">What did you find?</h3>
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                  {VERIFICATION_RESULTS.map((r) => (
                    <button
                      key={r.value}
                      type="button"
                      onClick={() => setResult(r.value)}
                      className={`rounded-lg px-3 py-2 text-start ring-1 transition ${
                        result === r.value
                          ? `${TONES[r.tone]} ring-2`
                          : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50'
                      }`}
                    >
                      <span className="block text-sm font-medium">{r.label}</span>
                      <span className="block text-xs opacity-80">{r.hint}</span>
                    </button>
                  ))}
                </div>
                {errors.result && <p className="text-xs text-red-600">{errors.result[0]}</p>}
              </section>

              {/* Disagreeing with the workshop is a normal, expected outcome — said plainly so an
                  inspector does not soften a verdict to avoid contradicting a colleague. That
                  disagreement is the most valuable record this workflow produces. */}
              {result && result !== 'verified' && claim?.claimed_outcome === 'complete' && (
                <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 ring-1 ring-slate-200">
                  This differs from the workshop’s report. That’s fine — record what you actually found.
                </p>
              )}

              <section className="space-y-2">
                <h3 className="text-sm font-semibold text-slate-800">How did you check?</h3>
                <div className="flex flex-wrap gap-1.5">
                  {VERIFICATION_METHODS.map((m) => (
                    <button
                      key={m.value}
                      type="button"
                      onClick={() => setMethod(m.value)}
                      className={`rounded-full px-3 py-1.5 text-xs font-medium ring-1 transition ${
                        method === m.value
                          ? 'bg-indigo-600 text-white ring-indigo-600'
                          : 'bg-white text-slate-700 ring-slate-300 hover:bg-slate-50'
                      }`}
                    >
                      {m.label}
                    </button>
                  ))}
                </div>
                {errors.method && <p className="text-xs text-red-600">{errors.method[0]}</p>}
              </section>

              <Textarea
                label="Notes (optional)"
                rows={2}
                value={note}
                onChange={(e) => setNote(e.target.value)}
                placeholder="What you observed"
              />
            </>
          )}
        </div>
      )}
    </Modal>
  );
}
