// Maintenance Checkpoint timeline — every answer a car has given to the daily "is it still coming back on
// that date?" question, newest first. Each entry is either a CONFIRMATION of the promised date or a MOVE
// (previous → new date + the reason it moved), plus a workshop status, a note, photos/videos, and who
// filed it when. Nothing is ever overwritten, so a car chased for a week shows all seven answers and all
// seven reasons — that record is the point. Shared by the CheckpointModal history and the Vehicle Profile
// "Maintenance Progress" tab. Presentation only; data comes from the checkpoints API.
//
// There is NO manual "outcome" — a job's On Schedule / Overdue status is derived from the ETA elsewhere.

import { statusLabel, delayReasonLabel, RESPONSE_RESCHEDULED } from '../../lib/maintenanceCheckpoints';
import { fmtDate } from '../../lib/format';

function when(iso) {
  if (!iso) return '';
  try {
    const d = new Date(iso);
    return `${fmtDate(iso)} · ${d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
  } catch {
    return fmtDate(iso);
  }
}

const reasonText = (c) => (c.delay_reason === 'other'
  ? (c.delay_reason_other || 'Other')
  : delayReasonLabel(c.delay_reason));

export default function CheckpointTimeline({ checkpoints = [], onDelete = null, canManage = false }) {
  if (!checkpoints.length) {
    return (
      <p className="py-8 text-center text-sm text-slate-400">
        No updates yet — the first progress update will appear here.
      </p>
    );
  }

  return (
    <ol className="relative space-y-4">
      {/* Connecting rail behind the markers. */}
      <span aria-hidden="true" className="absolute start-[15px] top-2 bottom-2 w-px bg-slate-200" />
      {checkpoints.map((c) => {
        const images = (c.media || []).filter((m) => m.kind === 'image');
        const videos = (c.media || []).filter((m) => m.kind === 'video');
        // The recorded ANSWER: did the supervisor stand by the promised date, or move it? Read from the
        // stored response, falling back to the dates for rows filed before the answer was captured.
        const etaChanged = c.response
          ? c.response === RESPONSE_RESCHEDULED
          : (!!c.next_expected_date
            && (!c.previous_expected_date || c.previous_expected_date !== c.next_expected_date));
        const tone = etaChanged
          ? { dot: 'bg-amber-500', ring: 'ring-amber-100' }
          : { dot: 'bg-slate-300', ring: 'ring-slate-100' };
        return (
          <li key={c.id} className="relative flex gap-3">
            <span className={`relative z-10 mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white ring-4 ${tone.ring}`}>
              <span className={`h-3 w-3 rounded-full ${tone.dot}`} />
            </span>
            <div className="min-w-0 flex-1 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                  <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ${etaChanged ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-slate-50 text-slate-600 ring-slate-200'}`}>
                    {etaChanged ? 'Date moved' : 'Date confirmed'}
                  </span>
                  {c.status && (
                    <span className="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">
                      {statusLabel(c.status)}
                    </span>
                  )}
                </div>
                <span className="text-xs text-slate-400">{when(c.created_at)}</span>
              </div>

              {/* The ETA change — previous → new + why it moved. */}
              {etaChanged ? (
                <div className="mt-2 rounded-lg bg-amber-50/70 px-2.5 py-2 text-xs ring-1 ring-amber-100">
                  <p className="flex flex-wrap items-center gap-1.5 text-slate-600">
                    {c.previous_expected_date && (
                      <>
                        <span className="text-slate-400 line-through">{fmtDate(c.previous_expected_date)}</span>
                        <span aria-hidden className="text-amber-500">→</span>
                      </>
                    )}
                    <span className="font-semibold text-amber-800">{fmtDate(c.next_expected_date)}</span>
                  </p>
                  {reasonText(c) && <p className="mt-1 font-medium text-amber-700">Reason: {reasonText(c)}</p>}
                </div>
              ) : (
                c.next_expected_date && (
                  <p className="mt-2 text-xs text-slate-500">
                    Confirmed still coming back on <span className="font-semibold text-slate-700">{fmtDate(c.next_expected_date)}</span>
                  </p>
                )
              )}

              {c.summary && <p className="mt-2 whitespace-pre-wrap text-sm text-slate-700">{c.summary}</p>}

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
