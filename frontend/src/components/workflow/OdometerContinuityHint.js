// Odometer Continuity Hint — the ONE shared UI for every odometer-capture screen in the maintenance
// workflow (the action modal's test/pickup/receive/ready steps, the garage Transfer gate, and the
// Logistics Pre/Post-trip capture). It shows the "Previous Odometer" the system expects, a live verdict
// the moment a reading is typed (Verified / big-jump / garage test-drive / backward "Discrepancy"), and —
// whenever the reading is more than NOTE_THRESHOLD_KM off the previous one (either direction) — forces
// BOTH an acknowledgment checkbox AND a mandatory written note. Keeping this in one place is what makes
// the ">10 km ⇒ confirm + note" rule identical on every stage.
//
// Pair it with the gate helper below (odoGateBlocked) so callers don't each re-derive the submit guard.

import { needsConfirm, needsNote, isHardBlocked, needsApproval, stageReviewsInsteadOfBlocking, NOTE_THRESHOLD_KM, CONTINUITY_TONE, STATUS, STAGE } from '../../lib/odometerContinuity';

// Static Tailwind classes per continuity tone (dynamic `bg-${tone}` classes wouldn't survive purging).
const CONTINUITY_TONE_CLS = {
  emerald: 'border-emerald-200 bg-emerald-50 text-emerald-700',
  amber: 'border-amber-200 bg-amber-50 text-amber-800',
  red: 'border-red-200 bg-red-50 text-red-700',
};

// Does this step's odometer entry still need acknowledgment/note before it can submit? `continuity` is
// evaluateContinuity()'s verdict, `confirmed`/`note` the operator's current input. Steps that capture no
// odometer pass continuity === null → both requirements are false → this is a no-op (returns false).
// `ignoreTolerance` waives the forward-jump nag on a site↔garage move (a Discrepancy still asks).
export function odoGateBlocked(continuity, confirmed, note, ignoreTolerance = false) {
  // A hard block (garage intake ≤ pickup) can't be acknowledged away — it blocks submit outright.
  if (isHardBlocked(continuity)) return true;
  const noteRequired = needsNote(continuity, ignoreTolerance);
  const ackRequired = needsConfirm(continuity?.status, ignoreTolerance, continuity?.stage) || noteRequired;
  return (ackRequired && !confirmed) || (noteRequired && !String(note ?? '').trim());
}

// Odometer Continuity — shows the "Previous Odometer" the system expects, then a live verdict the moment
// a reading is typed. Abnormal cases (backward reading, big jump, garage test drive) surface a soft
// confirm checkbox (asks, never blocks). A >10 km gap ALSO forces a written note. `previous` is the last
// recorded km; `continuity` is evaluateContinuity()'s verdict; `noteRequired` = needsNote(continuity).
export default function OdometerContinuityHint({ previous, continuity, confirmed, onConfirm, noteRequired, note, onNote, ignoreTolerance = false, t }) {
  const status = continuity?.status;
  if (previous == null && !status) return null;
  // The must-increase block reads differently depending on WHY the car had to have moved: the garage→park
  // return leg spells out that the car travelled from the garage to our parking, rather than the generic
  // "an odometer can't run backwards" wording.
  const hintKey = status === STATUS.MUST_INCREASE && continuity?.stage === STAGE.PARK_ARRIVAL
    ? 'must_increase_return'
    // A backward reading at a review-not-block stage is no longer "re-check the dial, you can't submit
    // this" — it IS submittable. Both the badge and the sentence have to stop saying otherwise, or the
    // modal reads as a refusal while the button happily goes through.
    : status === STATUS.EXACT_MATCH && stageReviewsInsteadOfBlocking(continuity?.stage)
      ? 'exact_required_review'
      // A small forward drift at a park spot-check no longer demands a written note (see needsNote), so it
      // must stop announcing "Note required" — it reads as a refused form when the only thing asked for is
      // the confirmation tick.
      : status === STATUS.AUTHORIZED && !needsApproval(continuity)
        ? 'authorized_deviation_small'
        : status;
  const statusKey = hintKey === 'exact_required_review' || hintKey === 'authorized_deviation_small' ? hintKey : status;
  // Keyed off statusKey, not status: a backward reading that is being ACCEPTED and sent for review must
  // not wear the red "you cannot submit this" jacket.
  const tone = statusKey ? CONTINUITY_TONE[statusKey] : null;
  // The acknowledgment checkbox appears for the abnormal continuity cases AND whenever a >10 km gap
  // forces a note (so the writer consciously confirms the reading before explaining it). On a garage
  // transfer the forward-jump cases are waived (ignoreTolerance) — only a backward Discrepancy still asks.
  const showConfirm = needsConfirm(status, ignoreTolerance, continuity?.stage) || noteRequired;
  // At a review-not-block stage the note stops being mandatory (see needsNote) — but the box must not
  // vanish with the asterisk. A deviation that's on its way to a supervisor is exactly when the inspector
  // has something worth writing; we invite it instead of demanding it.
  // Same for a small strict-match drift: the box stays (the driver may well have something to say about
  // why the dial moved), it just loses the asterisk.
  const noteInvited = !noteRequired && (needsApproval(continuity) || status === STATUS.AUTHORIZED);
  return (
    <div className="mt-2 space-y-2">
      {previous != null && (
        <div className="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs">
          <span className="text-slate-500">{t('workflow.odo.previous')}</span>
          <span className="font-mono font-semibold text-slate-700">{Number(previous).toLocaleString()} {t('workflow.stage.kmShort')}</span>
        </div>
      )}
      {status && (
        <div className={`rounded-lg border px-3 py-2 text-xs ${CONTINUITY_TONE_CLS[tone]}`}>
          <div className="flex items-center justify-between gap-2">
            <span className="font-semibold">{t(`workflow.odo.status.${statusKey}`)}</span>
            {continuity.delta != null && continuity.delta !== 0 && (
              <span className="font-mono font-semibold tabular-nums">
                {continuity.delta > 0 ? '+' : ''}{Number(continuity.delta).toLocaleString()} {t('workflow.stage.kmShort')}
              </span>
            )}
          </div>
          <p className="mt-0.5 opacity-90">{t(`workflow.odo.hint.${hintKey}`)}</p>
          {/* The car moved further than the buffer at a point where it was supposed to be standing still.
              We take the reading — it may well be true — but say plainly that a supervisor will look at it,
              so nobody is surprised later and nobody is tempted to re-type the previous number instead. */}
          {needsApproval(continuity) && (
            <p className="mt-1 font-medium opacity-90">{t('workflow.odo.hint.sentForApproval')}</p>
          )}
          {showConfirm && (
            <label className="mt-2 flex cursor-pointer items-center gap-2 font-medium">
              <input type="checkbox" checked={confirmed} onChange={(e) => onConfirm(e.target.checked)} className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
              {t('workflow.odo.confirm')}
            </label>
          )}
          {(noteRequired || noteInvited) && (
            <div className="mt-2">
              <span className="mb-1 block font-medium">
                {noteInvited
                  ? t('workflow.odo.noteLabelOptional')
                  : status === STATUS.AUTHORIZED
                    ? t('workflow.odo.noteLabelDeviation', { km: Math.abs(continuity?.delta ?? 0) })
                    : t('workflow.odo.noteLabel', { km: NOTE_THRESHOLD_KM })}
                {noteRequired && <span className="text-red-500"> *</span>}
              </span>
              <textarea
                value={note}
                onChange={(e) => onNote(e.target.value)}
                rows={2}
                placeholder={t('workflow.odo.notePh')}
                className="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-sm text-slate-700 focus:border-indigo-400 focus:outline-none focus:ring-1 focus:ring-indigo-400"
              />
            </div>
          )}
        </div>
      )}
    </div>
  );
}
