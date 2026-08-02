// ComplaintTimeline — the "record of truth" for one complaint: the chronological story of what actually
// happened (logged → contacted → sent in / resolved), rendered from the vehicle_log_events the workflow
// already writes. Shared by the Complaints Center drawer AND the vehicle's Complaint History tab, so the
// two surfaces can never tell a different story. Pure presentational: give it a `timeline` array
// ([{ emoji, title, description, actor, source, at }], oldest → newest) and it draws the rail.

const SOURCE_DOT = {
  inspector: 'bg-indigo-500',
  garage: 'bg-orange-500',
};

function when(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export default function ComplaintTimeline({ timeline = [], emptyLabel = 'No activity logged yet.' }) {
  if (!timeline.length) {
    return <p className="rounded-lg bg-slate-50 px-3 py-4 text-center text-sm text-slate-400 ring-1 ring-inset ring-slate-100">{emptyLabel}</p>;
  }

  return (
    <ol className="relative space-y-0">
      {timeline.map((e, i) => {
        const last = i === timeline.length - 1;
        return (
          <li key={e.id ?? i} className="relative flex gap-3 pb-5 last:pb-0">
            {/* The rail — a vertical connector behind every node except the last. */}
            {!last && <span aria-hidden className="absolute start-[15px] top-8 bottom-0 w-px bg-slate-200" />}

            {/* Node glyph */}
            <span className="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white text-base ring-1 ring-slate-200">
              {e.emoji || '•'}
              <span className={`absolute -bottom-0.5 -end-0.5 h-2.5 w-2.5 rounded-full ring-2 ring-white ${SOURCE_DOT[e.source] || 'bg-slate-300'}`} />
            </span>

            {/* Body */}
            <div className="min-w-0 flex-1 pt-0.5">
              <div className="flex flex-wrap items-baseline justify-between gap-x-2">
                <p className="text-sm font-semibold text-slate-800">{e.title}</p>
                <time className="shrink-0 text-[11px] tabular-nums text-slate-400">{when(e.at)}</time>
              </div>
              {e.description && e.description !== e.title && (
                <p className="mt-0.5 whitespace-pre-wrap break-words text-xs leading-relaxed text-slate-600">{e.description}</p>
              )}
              {e.actor && <p className="mt-0.5 text-[11px] text-slate-400">by {e.actor}</p>}
            </div>
          </li>
        );
      })}
    </ol>
  );
}
