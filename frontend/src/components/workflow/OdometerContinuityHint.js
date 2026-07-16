// Odometer Continuity Hint — the ONE shared UI for every odometer-capture screen in the maintenance
// workflow (the action modal's test/pickup/receive/ready steps, the garage Transfer gate, and the
// Logistics Pre/Post-trip capture). It shows the "Previous Odometer" the system expects, a live verdict
// the moment a reading is typed (Verified / big-jump / garage test-drive / backward "Discrepancy"), and —
// whenever the reading is more than NOTE_THRESHOLD_KM off the previous one (either direction) — forces
// BOTH an acknowledgment checkbox AND a mandatory written note. Keeping this in one place is what makes
// the ">10 km ⇒ confirm + note" rule identical on every stage.
//
// Pair it with the gate helper below (odoGateBlocked) so callers don't each re-derive the submit guard.

import { needsConfirm, needsNote, isHardBlocked, NOTE_THRESHOLD_KM, CONTINUITY_TONE, STATUS, STAGE } from '../../lib/odometerContinuity';

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
  const ackRequired = needsConfirm(continuity?.status, ignoreTolerance) || noteRequired;
  return (ackRequired && !confirmed) || (noteRequired && !String(note ?? '').trim());
}

// Odometer Continuity — shows the "Previous Odometer" the system expects, then a live verdict the moment
// a reading is typed. Abnormal cases (backward reading, big jump, garage test drive) surface a soft
// confirm checkbox (asks, never blocks). A >10 km gap ALSO forces a written note. `previous` is the last
// recorded km; `continuity` is evaluateContinuity()'s verdict; `noteRequired` = needsNote(continuity).
export default function OdometerContinuityHint({ previous, continuity, confirmed, onConfirm, noteRequired, note, onNote, ignoreTolerance = false, t }) {
  const status = continuity?.status;
  if (previous == null && !status) return null;
  const tone = status ? CONTINUITY_TONE[status] : null;
  // The must-increase block reads differently depending on WHY the car had to have moved: the garage→park
  // return leg spells out that the car travelled from the garage to our parking, rather than the generic
  // "an odometer can't run backwards" wording.
  const hintKey = status === STATUS.MUST_INCREASE && continuity?.stage === STAGE.PARK_ARRIVAL
    ? 'must_increase_return'
    : status;
  // The acknowledgment checkbox appears for the abnormal continuity cases AND whenever a >10 km gap
  // forces a note (so the writer consciously confirms the reading before explaining it). On a garage
  // transfer the forward-jump cases are waived (ignoreTolerance) — only a backward Discrepancy still asks.
  const showConfirm = needsConfirm(status, ignoreTolerance) || noteRequired;
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
            <span className="font-semibold">{t(`workflow.odo.status.${status}`)}</span>
            {continuity.delta != null && continuity.delta !== 0 && (
              <span className="font-mono font-semibold tabular-nums">
                {continuity.delta > 0 ? '+' : ''}{Number(continuity.delta).toLocaleString()} {t('workflow.stage.kmShort')}
              </span>
            )}
          </div>
          <p className="mt-0.5 opacity-90">{t(`workflow.odo.hint.${hintKey}`)}</p>
          {showConfirm && (
            <label className="mt-2 flex cursor-pointer items-center gap-2 font-medium">
              <input type="checkbox" checked={confirmed} onChange={(e) => onConfirm(e.target.checked)} className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
              {t('workflow.odo.confirm')}
            </label>
          )}
          {noteRequired && (
            <div className="mt-2">
              <span className="mb-1 block font-medium">
                {status === STATUS.AUTHORIZED
                  ? t('workflow.odo.noteLabelDeviation', { km: Math.abs(continuity?.delta ?? 0) })
                  : t('workflow.odo.noteLabel', { km: NOTE_THRESHOLD_KM })}
                <span className="text-red-500"> *</span>
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
