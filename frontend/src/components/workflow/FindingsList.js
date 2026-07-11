// Findings grouped by SOURCE — "Inspector-Identified" (Abu Maroof's test-drive diagnosis) vs
// "Garage-Identified" (discovered during the repair). Shared by the ticket modal and the board card
// so the accountability trail looks identical everywhere. `compact` tightens it for the card.
//
// When `tasks` is passed, each finding also wears its live fix status (✓ Fixed / In progress / …),
// matched to its fault-task by symptom text — so opening a ticket shows at a glance which faults are
// already done, without going through the Manage-faults panel.

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

// Normalise a symptom/finding label so "Rough idle / misfire" matches across whitespace/case quirks.
const norm = (s) => String(s || '').trim().toLowerCase().replace(/\s+/g, ' ');

export default function FindingsList({ findings = [], tasks = [], compact = false }) {
  if (!findings.length) {
    return compact ? null : <p className="text-xs text-slate-400">No findings recorded yet.</p>;
  }

  // Symptom → its fault-task, so each finding can show its live fix status. `is_incorrect` faults are
  // treated as cancelled (a mis-diagnosis), matching the routing panel.
  const taskBySymptom = {};
  tasks.forEach((tk) => { if (tk?.symptom) taskBySymptom[norm(tk.symptom)] = tk; });
  const statusFor = (f) => {
    const tk = taskBySymptom[norm(f.text)];
    if (!tk) return null;
    const badge = tk.is_incorrect ? STATUS_BADGE.cancelled : STATUS_BADGE[tk.status];
    if (!badge) return null;
    // A completed fault keeps the garage that fixed it in current_vendor_id — surface its name so the
    // "✓ Fixed" badge reads "✓ Fixed · <Garage>" (where the repair actually happened).
    const garage = tk.status === 'completed' && !tk.is_incorrect ? tk.current_garage : null;
    return { ...badge, garage };
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
                return (
                  <span
                    key={i}
                    title={f.by ? `Added by ${f.by}` : undefined}
                    className={`inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs ring-1 ${meta.chip}`}
                  >
                    {f.text}
                    {/* Diagnosed root cause (Symptom → Root-Cause) — the structured "why" behind the symptom. */}
                    {f.root_cause && <span className="font-medium opacity-80">→ {f.root_cause}</span>}
                    {f.severity && <span className="opacity-60">· {f.severity}</span>}
                    {f.repair_hours != null && <span className="font-semibold opacity-80">· {f.repair_hours}h</span>}
                    {/* Live fix status — so a fixed fault reads as done the moment the ticket is opened. */}
                    {badge && (
                      <span className={`ml-0.5 inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-bold ring-1 ring-inset ${badge.cls}`}>
                        {badge.label}
                        {badge.garage && <span className="ml-1 font-semibold opacity-80">· {badge.garage}</span>}
                      </span>
                    )}
                  </span>
                );
              })}
            </div>
          </div>
        );
      })}
    </div>
  );
}
