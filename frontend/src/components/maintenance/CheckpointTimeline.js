// Maintenance Checkpoint timeline — a chronological feed of progress updates (outcome, status, delay
// reason, summary, pushed-back date, and photos/videos). Shared by the CheckpointModal history and the
// Vehicle Profile "Maintenance Progress" tab. Presentation only; data comes from the checkpoints API.

import { outcomeLabel, statusLabel, delayReasonLabel } from '../../lib/maintenanceCheckpoints';
import { fmtDate } from '../../lib/format';

const OUTCOME_TONE = {
  on_track: { dot: 'bg-emerald-500', ring: 'ring-emerald-100', chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
  delayed:  { dot: 'bg-amber-500',   ring: 'ring-amber-100',   chip: 'bg-amber-50 text-amber-700 ring-amber-200' },
  critical: { dot: 'bg-red-500',     ring: 'ring-red-100',     chip: 'bg-red-50 text-red-700 ring-red-200' },
};

function when(iso) {
  if (!iso) return '';
  try {
    const d = new Date(iso);
    return `${fmtDate(iso)} · ${d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
  } catch {
    return fmtDate(iso);
  }
}

export default function CheckpointTimeline({ checkpoints = [], onDelete = null, canManage = false }) {
  if (!checkpoints.length) {
    return (
      <p className="py-8 text-center text-sm text-slate-400">
        No checkpoints yet — the first progress update will appear here.
      </p>
    );
  }

  return (
    <ol className="relative space-y-4">
      {/* Connecting rail behind the markers. */}
      <span aria-hidden="true" className="absolute left-[15px] top-2 bottom-2 w-px bg-slate-200" />
      {checkpoints.map((c) => {
        const tone = OUTCOME_TONE[c.outcome] || OUTCOME_TONE.on_track;
        const images = (c.media || []).filter((m) => m.kind === 'image');
        const videos = (c.media || []).filter((m) => m.kind === 'video');
        return (
          <li key={c.id} className="relative flex gap-3">
            <span className={`relative z-10 mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white ring-4 ${tone.ring}`}>
              <span className={`h-3 w-3 rounded-full ${tone.dot}`} />
            </span>
            <div className="min-w-0 flex-1 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                  <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ${tone.chip}`}>
                    {outcomeLabel(c.outcome)}
                  </span>
                  {c.status && (
                    <span className="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">
                      {statusLabel(c.status)}
                    </span>
                  )}
                </div>
                <span className="text-xs text-slate-400">{when(c.created_at)}</span>
              </div>

              {c.outcome === 'delayed' && (c.delay_reason || c.delay_reason_other) && (
                <p className="mt-2 text-xs font-medium text-amber-700">
                  Delay: {delayReasonLabel(c.delay_reason)}
                  {c.delay_reason === 'other' && c.delay_reason_other ? ` — ${c.delay_reason_other}` : ''}
                </p>
              )}

              {c.summary && <p className="mt-2 whitespace-pre-wrap text-sm text-slate-700">{c.summary}</p>}

              {c.next_expected_date && (
                <p className="mt-2 text-xs text-slate-500">
                  New expected completion: <span className="font-semibold text-slate-700">{fmtDate(c.next_expected_date)}</span>
                </p>
              )}

              {(images.length > 0 || videos.length > 0) && (
                <div className="mt-2 flex flex-wrap gap-2">
                  {images.map((m) => (
                    <a key={m.id} href={m.url} target="_blank" rel="noreferrer" className="block h-16 w-16 overflow-hidden rounded-lg ring-1 ring-slate-200">
                      <img src={m.url} alt={m.original_name || 'photo'} className="h-full w-full object-cover" loading="lazy" />
                    </a>
                  ))}
                  {videos.map((m) => (
                    <a key={m.id} href={m.url} target="_blank" rel="noreferrer"
                       className="flex h-16 w-16 flex-col items-center justify-center gap-1 rounded-lg bg-slate-900 text-[10px] font-medium text-white ring-1 ring-slate-200">
                      <span className="text-lg leading-none">🎬</span>
                      Video
                    </a>
                  ))}
                </div>
              )}

              <div className="mt-2 flex items-center justify-between gap-2">
                <span className="text-xs text-slate-400">By {c.submitted_by_name || 'Unknown'}</span>
                {canManage && onDelete && (
                  <button
                    type="button"
                    onClick={() => onDelete(c)}
                    className="text-xs font-medium text-slate-400 hover:text-red-600"
                  >
                    Delete
                  </button>
                )}
              </div>
            </div>
          </li>
        );
      })}
    </ol>
  );
}
