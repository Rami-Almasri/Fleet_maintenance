// THE TICKET IS STOPPED, AND THIS IS WHY.
//
// Somebody logged a finding the car's own data disagrees with — an oil change on a car with 5,415 km of
// its interval left, or a fault this car already had six weeks ago. The picker warned them in amber; the
// warning used to be the end of it and the job went ahead, surfacing days later as a Data Health audit row
// after the work was done and the bill was written. Now the finding is HELD: it is not promoted into a
// workable fault, and the whole ticket sits where it stands until someone with the authority decides.
//
// This panel is the other end of that. It is deliberately the FIRST thing in the drawer and the command
// view — above the journey rail, above the faults — because while it is on screen nothing else about the
// ticket can happen, and a blocker found by scrolling is a blocker found late.
//
// APPROVE means "I am overruling the data": the person in front of the car can see something the odometer
// cannot, which is a legitimate call for a manager to make, and the trail records that they made it.
// REJECT means the job does not happen; the finding stays on the ticket marked refused, because "somebody
// tried to log an oil change this car did not need, and it was refused" is exactly the fact worth keeping.
// Either way the ticket unblocks — there is no way to strand it.
//
// Read-only for everyone without `maintenance.manage`: the people who LOG findings must not be the people
// who wave them through, or the second signature is the same signature. They still see the panel, so a
// technician understands why their ticket is not moving and who is holding it.

import { useState } from 'react';
import api from '../../api/client';
import Button from '../ui/Button';
import Icon from '../ui/Icon';
import { usePermissions } from '../../hooks/usePermissions';
import { useI18n } from '../../i18n/I18nContext';

// Reason CODE → the sentence, composed here from the engine's measured params so it reads natively in
// either language. The engine emits `not_needed` + {summary} and never an English clause
// ([[reason-code-contract]]).
function reasonText(t, reason, params = {}) {
  if (reason === 'not_needed') {
    return params.summary
      ? t('findingApproval.reason.notNeededWith', { summary: params.summary })
      : t('findingApproval.reason.notNeeded');
  }
  if (reason === 'repeat') {
    return t('findingApproval.reason.repeat', {
      days: params.days_ago ?? 0,
      ticket: params.prior_ticket_id ?? '—',
    });
  }
  return t('findingApproval.reason.generic');
}

// Reason CODE → the short chip on the finding. Kept as an explicit map rather than an interpolated key
// so an unknown code from a newer backend renders as "Check this" instead of a raw dot-path on screen.
const REASON_TAG = {
  not_needed: 'findingApproval.tag.notNeeded',
  repeat:     'findingApproval.tag.repeat',
};

export default function FindingApprovalPanel({ ticketId, pending = [], onDecided }) {
  const { t, tf, tp } = useI18n();
  const { can } = usePermissions();
  // Which finding's confirm pane is open, and the note being typed into it. One at a time: these are
  // decisions, not a checklist, and a bulk "approve all" is the exact reflex this gate exists to break.
  const [openFor, setOpenFor] = useState(null);
  const [action, setAction] = useState(null);
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  if (!pending.length) return null;

  const mayDecide = can('maintenance.manage');

  const start = (finding, act) => {
    setOpenFor(finding);
    setAction(act);
    setNote('');
    setError(null);
  };

  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      await api.post(`/maintenance-tickets/${ticketId}/finding-approvals`, {
        finding: openFor,
        action,
        note: note.trim() || undefined,
      });
      setOpenFor(null);
      setAction(null);
      setNote('');
      if (onDecided) await onDecided();
    } catch (e) {
      setError(e?.response?.data?.message || t('findingApproval.error'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="rounded-xl bg-amber-50 p-3 ring-1 ring-inset ring-amber-300">
      <div className="mb-2 flex items-start gap-2">
        <Icon.Alert className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
        <div className="min-w-0">
          <p className="text-sm font-semibold text-amber-900">
            {tp('findingApproval.title', pending.length)}
          </p>
          <p className="text-xs leading-snug text-amber-800">
            {mayDecide ? t('findingApproval.subtitle') : t('findingApproval.subtitleReadOnly')}
          </p>
        </div>
      </div>

      <ul className="space-y-2">
        {pending.map((p) => (
          <li key={p.finding} className="rounded-lg bg-white/80 p-2.5 ring-1 ring-inset ring-amber-200">
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
              <span className="text-sm font-semibold text-slate-800">{p.finding}</span>
              <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-800">
                {tf(REASON_TAG[p.reason] || 'findingApproval.tag.generic', 'Check this')}
              </span>
            </div>
            {/* WHO TRIED. Named on the card, not buried in the timeline — the first question anyone
                asks about a held finding is whose it is. */}
            <p className="mt-0.5 text-xs text-slate-600">
              {t('findingApproval.loggedBy', { name: p.requested_by || t('findingApproval.someone') })}
              {' · '}
              {reasonText(t, p.reason, p.params)}
            </p>

            {mayDecide && openFor !== p.finding && (
              <div className="mt-2 flex flex-wrap gap-2">
                <Button size="sm" onClick={() => start(p.finding, 'approve')}>
                  {t('findingApproval.approve')}
                </Button>
                <Button size="sm" variant="secondary" onClick={() => start(p.finding, 'reject')}>
                  {t('findingApproval.reject')}
                </Button>
              </div>
            )}

            {mayDecide && openFor === p.finding && (
              <div className="mt-2 space-y-2 border-t border-amber-200 pt-2">
                <p className="text-xs font-medium text-slate-700">
                  {action === 'approve'
                    ? t('findingApproval.confirmApprove', { finding: p.finding })
                    : t('findingApproval.confirmReject', { finding: p.finding })}
                </p>
                <textarea
                  value={note}
                  onChange={(e) => setNote(e.target.value)}
                  rows={2}
                  maxLength={500}
                  placeholder={t('findingApproval.notePlaceholder')}
                  className="w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm text-slate-700 placeholder:text-slate-400 focus:border-amber-400 focus:outline-none focus:ring-1 focus:ring-amber-400"
                />
                <div className="flex flex-wrap gap-2">
                  <Button size="sm" onClick={submit} disabled={busy}>
                    {busy ? t('findingApproval.saving') : t('findingApproval.confirm')}
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => setOpenFor(null)} disabled={busy}>
                    {t('findingApproval.cancel')}
                  </Button>
                </div>
              </div>
            )}
          </li>
        ))}
      </ul>

      {error && <p className="mt-2 text-xs font-medium text-red-600">{error}</p>}
    </div>
  );
}
