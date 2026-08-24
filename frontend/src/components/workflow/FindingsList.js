// Findings grouped by SOURCE — "Inspector-Identified" (Abu Maroof's test-drive diagnosis) vs
// "Garage-Identified" (discovered during the repair). Shared by the ticket modal and the board card
// so the accountability trail looks identical everywhere. `compact` tightens it for the card.
//
// When `tasks` is passed, each finding also wears its live fix status (✓ Fixed / In progress / …),
// matched to its fault-task by symptom text — so opening a ticket shows at a glance which faults are
// already done, without going through the Manage-faults panel.

import { useI18n } from '../../i18n/I18nContext';

const SOURCE = {
  inspector: { label: 'Inspector', chip: 'bg-violet-50 text-violet-700 ring-violet-200', dot: 'bg-violet-500' },
  garage:    { label: 'Garage',    chip: 'bg-amber-50 text-amber-700 ring-amber-200',   dot: 'bg-amber-500' },
};
const ORDER = ['inspector', 'garage'];

// Per-fault status → the little badge shown after a finding. Only "settled" states get a badge; a plain
// still-open (pending) fault stays unadorned so the fixed ones stand out.
const STATUS_BADGE = {
  completed:   { label: '✓ Fixed',       cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
  in_progress: { label: 'In progress',   cls: 'bg-blue-50 text-blue-700 ring-blue-200' },
  cancelled:   { label: 'Cancelled',     cls: 'bg-slate-100 text-slate-500 ring-slate-200' },
};

// Shown on an open (no other badge) finding while the TICKET itself is paused — released back into
// service with the repair on hold. Same class shape as STATUS_BADGE above.
const PAUSED_BADGE = { label: 'Paused', cls: 'bg-slate-100 text-slate-500 ring-slate-200' };

// Opt-in (showPending): make a still-open fault say so EXPLICITLY instead of staying unadorned — used when
// the car is about to leave mid-repair (Temporary Release) so "this fixed / this not" is unmistakable.
const PENDING_BADGE = { label: 'Not fixed', cls: 'bg-amber-50 text-amber-700 ring-amber-200' };

// Vehicle-sync confirmation for a PERFORMED routine service: the vehicle record is updated only when the
// ticket closes, so a performed-but-open service reads "Pending Confirmation", a closed one "Confirmed".
const CONFIRM_BADGE = {
  pending_confirmation: { label: '⏳ Pending Confirmation', cls: 'bg-amber-50 text-amber-800 ring-amber-300' },
  confirmed:            { label: '✓ Confirmed',            cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
};

// Normalise a symptom/finding label so "Rough idle / misfire" matches across whitespace/case quirks.
const norm = (s) => String(s || '').trim().toLowerCase().replace(/\s+/g, ' ');

// "2d 4h" / "6h 12m" from seconds — the AUTO-derived time the fault occupied a garage (dispatch →
// release, summed across every attempt of THIS occurrence). Distinct from the manual labor hours: a
// recurrence after a passed re-inspection is a new fault and starts its own clock.
const fmtElapsed = (secs) => {
  if (secs == null || secs <= 0) return null;
  const d = Math.floor(secs / 86400);
  const h = Math.floor((secs % 86400) / 3600);
  const m = Math.floor((secs % 3600) / 60);
  if (d > 0) return `${d}d ${h}h`;
  if (h > 0) return `${h}h ${m}m`;
  return `${m}m`;
};

// Part-request lifecycle → the little marker shown next to a part listed under its fault. Installed reads
// as a green ✓ (fitted); the earlier stages get a coloured dot; the off-ramps read muted + struck-through.
const PART_STATUS = {
  requested:    { dot: 'bg-slate-300',  label: 'Requested' },
  under_review: { dot: 'bg-blue-400',   label: 'Under review' },
  approved:     { dot: 'bg-cyan-400',   label: 'Approved' },
  purchased:    { dot: 'bg-violet-400', label: 'Purchased' },
  installed:    { check: true,          label: 'Installed' },
  completed:    { check: true,          label: 'Installed' },
  rejected:     { dot: 'bg-slate-300',  label: 'Rejected',  muted: true },
  cancelled:    { dot: 'bg-slate-300',  label: 'Cancelled', muted: true },
};

// The parts ordered/fitted for one fault — so opening the fault shows what it needed at a glance.
function FaultParts({ parts }) {
  const { t } = useI18n();
  return (
    <div className="mt-1 ms-3 border-s border-slate-200 ps-2.5">
      <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{t('Parts')}</p>
      <ul className="mt-0.5 space-y-0.5">
        {parts.map((p) => {
          const st = PART_STATUS[p.status] || { dot: 'bg-slate-300', label: p.status };
          return (
            <li key={p.id} className="flex items-center gap-1.5 text-[11px]">
              {st.check
                ? <span className="font-bold text-emerald-600">✓</span>
                : <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${st.dot}`} />}
              <span className={st.muted ? 'text-slate-400 line-through' : 'font-medium text-slate-700'}>{p.part_name}</span>
              {p.quantity > 1 && <span className="text-slate-400">×{Math.round(p.quantity)}</span>}
              <span className="text-slate-400">· {PART_STATUS[p.status] ? t(st.label) : st.label}</span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

export default function FindingsList({ findings = [], tasks = [], compact = false, paused = false, showPending = false }) {
  const { t } = useI18n();
  if (!findings.length) {
    return compact ? null : <p className="text-xs text-slate-400">{t('No findings recorded yet.')}</p>;
  }

  // Symptom → its fault-task, so each finding can show its live fix status. `is_incorrect` faults are
  // treated as cancelled (a mis-diagnosis), matching the routing panel.
  const taskBySymptom = {};
  tasks.forEach((tk) => { if (tk?.symptom) taskBySymptom[norm(tk.symptom)] = tk; });
  // An open fault's fallback badge: "Paused" if the ticket is paused, an explicit "Not fixed" when the
  // caller asked to spell it out (showPending), otherwise nothing.
  const openBadge = () => (paused ? PAUSED_BADGE : (showPending ? PENDING_BADGE : null));
  const statusFor = (f) => {
    const tk = taskBySymptom[norm(f.text)];
    if (!tk) return openBadge(); // no fault-task match (or no tasks at all) → still-open
    const badge = tk.is_incorrect ? STATUS_BADGE.cancelled : STATUS_BADGE[tk.status];
    // A settled fault (fixed/in-progress/cancelled) keeps its own badge even while the ticket is paused;
    // only a genuinely open fault falls back to the Paused / Not-fixed badge.
    if (!badge) return openBadge();
    // A completed fault keeps the garage that fixed it in current_vendor_id — surface its name so the
    // "✓ Fixed" badge reads "✓ Fixed · <Garage>" (where the repair actually happened).
    const garage = tk.status === 'completed' && !tk.is_incorrect ? tk.current_garage : null;
    return { ...badge, garage, confirm: tk.service_confirmation || null };
  };

  const groups = {};
  findings.forEach((f) => {
    const s = f.source || 'inspector';
    (groups[s] = groups[s] || []).push(f);
  });

  const sources = [...ORDER.filter((s) => groups[s]?.length), ...Object.keys(groups).filter((s) => !ORDER.includes(s))];

  return (
    <div className={compact ? 'space-y-1.5' : 'space-y-2.5'}>
      {sources.map((s) => {
        const meta = SOURCE[s] || { label: s, chip: 'bg-slate-50 text-slate-700 ring-slate-200', dot: 'bg-slate-400' };
        return (
          <div key={s}>
            <div className="mb-1 flex items-center gap-1.5">
              <span className={`h-1.5 w-1.5 rounded-full ${meta.dot}`} />
              <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{meta.label}-Identified</span>
              <span className="text-[10px] font-medium text-slate-300">· {groups[s].length}</span>
            </div>
            <div className="flex flex-wrap gap-1.5">
              {groups[s].map((f, i) => {
                const badge = statusFor(f);
                // Parts ordered/fitted for THIS fault (from the matched fault-task). Shown only in the full
                // (non-compact) view; a fault with parts breaks onto its own line so the list reads under it.
                const task = taskBySymptom[norm(f.text)];
                const parts = (!compact && task?.parts?.length) ? task.parts : null;
                // Has this fault been here before, and did anyone approve repairing it again? Full view
                // only — the board card has no room, and it is a reading fact, not a glance fact.
                // PER-FAULT TIME. Only the WORK clock belongs to this fault alone — it runs from the
                // moment the workshop confirmed/started THIS fault. Custody time (from dispatch) is the
                // car's workshop time and is identical for every fault on the ticket, so it is NEVER
                // shown as the fault's own duration; it only appears in the tooltip, named for what it
                // is. 🔧 labor is the mechanic hours a human typed, summed per attempt (falling back to
                // the legacy findings-JSON stamp on tickets closed before the per-attempt ledger).
                const rt = task?.repair_time;
                const work = fmtElapsed(rt?.cumulative_work_seconds);
                const custody = fmtElapsed(rt?.cumulative_custody_seconds);
                const attempts = rt?.attempt_count || 0;
                const laborHours = task?.repair_hours ?? f.repair_hours ?? null;
                const chip = (
                  <span
                    title={f.by ? `Added by ${f.by}` : undefined}
                    className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs ring-1 ${meta.chip}`}
                  >
                    {/* WHAT IS WRONG, HOW MANY, AND WHERE — one sentence, built server-side by the
                        single fault formatter (MaintenanceTaskResource.display), so this chip, the
                        board card, the audit trail and the API all word the same fault identically.
                        A fault with no count and no location renders exactly its own text, which is
                        byte-for-byte what this chip showed before any of this existed. The fallback
                        covers a finding not yet promoted to a fault task (no `display` to read). */}
                    {task?.display || f.text}
                    {!task?.display && Number(f.quantity) > 1 && <span className="font-semibold opacity-80">· ×{f.quantity}</span>}
                    {/* Diagnosed root cause (Symptom → Root-Cause) — the structured "why" behind the symptom. */}
                    {f.root_cause && <span className="font-medium opacity-80">→ {f.root_cause}</span>}
                    {/* Which garage DISCOVERED it — stamped when a garage-identified finding is added, so the
                        accountability trail names where the fault was found (e.g. "🔧 Al Habtoor"). */}
                    {f.garage && <span className="font-medium opacity-70">· 🔧 {f.garage}</span>}
                    {f.severity && <span className="opacity-60">· {f.severity}</span>}
                    {/* This fault's own repair time — measured from the moment the workshop confirmed
                        it, so two faults on the same car read differently. "×2" = two repair attempts
                        (it came back from a failed re-inspection and the clock kept running). */}
                    {work && (
                      <span
                        className="font-semibold opacity-80"
                        title={t('workflow.task.workTip', { n: attempts, custody: custody || '—' })}
                      >
                        · ⏱ {work}{attempts > 1 ? ` ×${attempts}` : ''}
                      </span>
                    )}
                    {/* No confirmation was ever recorded for this fault, so its own clock never started.
                        We show the car's workshop time instead — explicitly labelled as the car's, never
                        dressed up as this fault's duration. */}
                    {!work && custody && (
                      <span className="opacity-60" title={t('workflow.task.custodyOnlyTip')}>
                        · 🚗 {custody}
                      </span>
                    )}
                    {/* Actual mechanic hours entered on the Mark ready screen (summed across attempts). */}
                    {laborHours != null && Number(laborHours) > 0 && (
                      <span className="font-semibold opacity-80" title={t('workflow.task.laborChipTip')}>
                        · 🔧 {Number(laborHours)}h
                      </span>
                    )}
                    {/* Live fix status — so a fixed fault reads as done the moment the ticket is opened. */}
                    {badge && (
                      <span className={`ms-0.5 inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-bold ring-1 ring-inset ${badge.cls}`}>
                        {badge.label}
                        {badge.garage && <span className="ms-1 font-semibold opacity-80">· {badge.garage}</span>}
                      </span>
                    )}
                    {/* Vehicle-sync state for a performed routine service — Pending Confirmation until close. */}
                    {badge?.confirm && CONFIRM_BADGE[badge.confirm] && (
                      <span className={`ms-0.5 inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-bold ring-1 ring-inset ${CONFIRM_BADGE[badge.confirm].cls}`}>
                        {CONFIRM_BADGE[badge.confirm].label}
                      </span>
                    )}
                  </span>
                );
                // A fault WITH parts breaks onto its own line (w-full) with the parts listed beneath it;
                // otherwise the finding stays an inline chip that wraps with the others (display:contents).
                return (
                  <div key={i} className={parts ? 'w-full' : 'contents'}>
                    {chip}
                    {parts && <FaultParts parts={parts} />}
                  </div>
                );
              })}
            </div>
          </div>
        );
      })}
    </div>
  );
}
