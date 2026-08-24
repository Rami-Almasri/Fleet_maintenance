// HAS THIS FAULT HAPPENED BEFORE — AND DID ANYONE SIGN OFF ON REPAIRING IT AGAIN?
//
// Two separate facts, deliberately shown together because they are read together:
//
//   · the recurrence itself — this exact fault was FIXED on this car before, by that garage, on that
//     date, and it is back. Detected at report time (RecurringFaultService); informational.
//   · the repair gate — when a CONFIRMED fault recurs, the repair is frozen until a manager approves
//     it. So the row says whether someone approved it, who, and when — or that it is still waiting.
//
// Both used to live only inside the fault-routing modal. Someone reading the ticket to understand why
// a car is back in the shop should not have to open a modal to learn it has been here before.
//
// DRIVEN BY TASKS, NOT FINDINGS. This first hung off the findings list, matching a finding's text to
// its fault-task — which silently showed nothing on every ticket whose faults exist as tasks with a
// null `findings` JSON (a large share of them). The task IS the fault record; the findings blob is a
// parallel, sometimes-absent copy. Reading the authoritative one is the whole fix.
//
// Renders nothing when no fault on the ticket has either history or a gate.

import Icon from '../ui/Icon';
import { useI18n } from '../../i18n/I18nContext';

// "12 Mar 2026" — the date a previous repair was signed off.
const fmtDay = (iso) => {
  if (!iso) return null;
  try {
    return new Date(iso).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' });
  } catch {
    return null;
  }
};

// Does this fault have anything to say about its own history?
export const hasFaultHistory = (task) => !!(task && (task.recurrence_flagged || task.repair_gate));

function FaultRecurrenceRow({ task }) {
  const { t } = useI18n();
  const gate = task.repair_gate;               // null | pending | approved | rejected
  const rec = task.recurrence_flagged ? task.recurrence : null;

  const when = fmtDay(rec?.repaired_on);
  const tone = gate === 'pending' ? 'border-red-200 bg-red-50/70'
    : gate === 'rejected' ? 'border-slate-200 bg-slate-50'
      : 'border-amber-200 bg-amber-50/70';

  return (
    <div className={`rounded-lg border px-3 py-2 text-xs leading-snug ${tone}`}>
      <p className="font-semibold text-slate-900">{task.display || task.symptom}</p>

      {task.recurrence_flagged && (
        <p className="mt-0.5 font-semibold text-amber-800">
          🔁 {t('workflow.task.recurrence.happenedBefore')}
          {rec?.garage ? ` · ${t('workflow.task.recurrence.lastFixedBy', { garage: rec.garage })}` : ''}
          {when ? ` · ${when}` : ''}
          {rec?.repair_days != null ? ` · ${t('workflow.task.recurrence.tookDays', { n: rec.repair_days })}` : ''}
        </p>
      )}

      {/* The approval on THIS repeat repair. "Waiting" is the loud one — work is frozen until someone acts. */}
      {gate === 'pending' && (
        <p className="mt-0.5 font-semibold text-red-700">{t('workflow.task.recurrence.awaitingApproval')}</p>
      )}
      {gate === 'approved' && (
        <p className="mt-0.5 text-emerald-700">
          <span className="font-semibold">{t('workflow.task.recurrence.approvedBy', { name: task.repair_gate_by || '—' })}</span>
          {task.repair_gate_at ? ` · ${fmtDay(task.repair_gate_at)}` : ''}
          {task.repair_gate_note ? ` · ${task.repair_gate_note}` : ''}
        </p>
      )}
      {gate === 'rejected' && (
        <p className="mt-0.5 text-slate-600">
          <span className="font-semibold">{t('workflow.task.recurrence.rejectedBy', { name: task.repair_gate_by || '—' })}</span>
          {task.repair_gate_at ? ` · ${fmtDay(task.repair_gate_at)}` : ''}
          {task.repair_gate_note ? ` · ${task.repair_gate_note}` : ''}
        </p>
      )}
    </div>
  );
}

export default function FaultRecurrence({ tasks = [] }) {
  const { t } = useI18n();
  const rows = tasks.filter(hasFaultHistory);
  if (!rows.length) return null;

  return (
    <div className="space-y-2">
      <p className="flex items-start gap-1.5 text-[11px] leading-relaxed text-slate-500">
        <Icon.Alert className="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-500" />
        {t('workflow.task.recurrence.intro')}
      </p>
      {rows.map((task) => <FaultRecurrenceRow key={task.id} task={task} />)}
    </div>
  );
}
