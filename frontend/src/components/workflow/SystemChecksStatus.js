import React from 'react';
import Icon from '../ui/Icon';
import Badge from '../ui/Badge';

/**
 * SYSTEM CHECKS — the READ view: what the platform asked of this car, and whether anyone answered.
 *
 * ── WHY THIS EXISTS SEPARATELY FROM [[SystemChecks]] ───────────────────────────────────────────
 * SystemChecks is the ANSWERING surface and lives inside the Decide step, where exactly one role can
 * reach it. That is correct — a check is answered in the same transaction that files the inspection
 * report, and offering a second write path would let a check be closed without the inspection that
 * closed it.
 *
 * But it left the obligations invisible to everyone else. A supervisor opening a ticket that had sat
 * ten days at Under Diagnosis with three unanswered checks saw no sign they existed: the agenda
 * sentence above mentions "Battery, Fluids and Brakes" as frozen prose, while the three addressable
 * obligations behind it were readable only by the inspector, only inside a modal. "Nobody has looked
 * at this yet" is the single fact this whole entity was built to surface, so the one screen where
 * people go to ask about a ticket is the last place it should be missing.
 *
 * This panel is therefore strictly READ-ONLY. It shows the question, who answered it, what they said
 * and what came of it. It offers no way to answer one — the Decide step remains the only door.
 */

/** Lifecycle → how the row reads at a glance. Unanswered states lead, because they are the finding. */
const STATUS_META = {
  pending:        { tone: 'amber',   label: 'Waiting for an inspection' },
  attached:       { tone: 'amber',   label: 'Not answered yet' },
  inspected:      { tone: 'blue',    label: 'Answered — awaiting a decision' },
  action_pending: { tone: 'indigo',  label: 'Work in progress' },
  resolved:       { tone: 'emerald', label: 'Resolved' },
  cancelled:      { tone: 'slate',   label: 'Withdrawn' },
  superseded:     { tone: 'slate',   label: 'Replaced by a newer check' },
  // Deliberately red. An expired check is not housekeeping — it is a question the platform asked and
  // nobody ever answered, which is precisely what this feature exists to stop being invisible.
  expired:        { tone: 'red',     label: 'Expired — never answered' },
};

/** What the inspector's answer led to, in plain words. Null when there is nothing to say yet. */
function outcomeLine(check) {
  if (check.resolution_code === 'confirmed_ok') return 'Checked — nothing wrong. No work raised.';
  if (check.resolution_code === 'monitoring')   return 'Checked — being monitored. No work raised.';
  if (check.resolution_code === 'deferred')     return 'Checked — work deferred.';
  if (check.resolution_code === 'declined')     return 'Checked — no work required.';
  if (check.resolution_code === 'repaired')     return 'Work completed.';
  if (check.resolution_code === 'expired')      return 'Nobody answered this before the evidence went stale.';
  if (check.action)                             return 'Maintenance action opened; the repair now owns the outcome.';
  return null;
}

export default function SystemChecksStatus({ checks, t }) {
  const tr = typeof t === 'function' ? t : (k) => k;
  const rows = Array.isArray(checks) ? checks : [];

  if (rows.length === 0) return null;

  const open = rows.filter((c) => c.is_open);

  return (
    <div className="mt-4 rounded-xl border border-slate-200 bg-white px-4 py-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-teal-700">
          <Icon.Shield className="h-3.5 w-3.5" /> {tr('System checks')}
        </p>
        {open.length > 0 ? (
          <Badge tone="amber" dot>
            {open.length === 1 ? tr('1 not answered yet') : `${open.length} ${tr('not answered yet')}`}
          </Badge>
        ) : (
          <Badge tone="emerald" dot>{tr('All answered')}</Badge>
        )}
      </div>

      <p className="mt-1 text-xs text-slate-500">
        {tr('Questions the system asked about this car. Only the inspector can answer them, on the Decide step.')}
      </p>

      <ul className="mt-2.5 space-y-2">
        {rows.map((c) => {
          const meta = STATUS_META[c.status] || { tone: 'slate', label: c.status };
          const outcome = outcomeLine(c);
          // The inspector's own words for what they found, straight from the catalog option they
          // picked — never re-derived here, so it still reads correctly against an older catalog.
          const answer = c.result_options?.find((o) => o.code === c.result_code)?.label;

          return (
            <li key={c.id} className="rounded-lg border border-slate-100 bg-slate-50/70 px-3 py-2">
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm font-semibold text-slate-800">{c.label}</span>
                <Badge tone={meta.tone} dot>{tr(meta.label)}</Badge>
              </div>

              {answer && (
                <p className="mt-1 text-xs text-slate-700">
                  <span className="font-medium">{tr('Result')}:</span> {answer}
                  {c.inspected_by ? ` — ${c.inspected_by}` : ''}
                </p>
              )}

              {outcome && <p className="mt-0.5 text-xs text-slate-500">{outcome}</p>}

              {/* Why it was asked. Kept on the unanswered rows above all, because that is where
                  somebody is deciding whether the question is worth an inspector's time. */}
              {c.detail_en && c.is_open && (
                <p className="mt-0.5 text-[11px] leading-relaxed text-slate-500">{c.detail_en}</p>
              )}
            </li>
          );
        })}
      </ul>
    </div>
  );
}
