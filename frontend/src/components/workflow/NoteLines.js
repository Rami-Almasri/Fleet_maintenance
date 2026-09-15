import React from 'react';

/**
 * A machine-composed ticket note is several INDEPENDENT facts glued into one paragraph:
 *
 *   "Routine check due — go check: Battery Status. Routine check overdue — 62 days since last
 *    maintenance completion — please check: Battery, Fluids, and Brakes. Oil follow-up — recall
 *    now. Moved more than expected: ~22,552 km over the 51,948 km max (…). Check oil on arrival."
 *
 * Read as prose that is a wall of text; read as a list it is the checklist it always was. This
 * splits the note back into the facts it was built from and renders one dashed line each.
 *
 * The split is PRESENTATIONAL — the stored note is never rewritten. That matters twice over: the
 * note stays the frozen audit record of why the request was raised, and notes written before the
 * producers started emitting one fact per line are rescued at render time just the same.
 */

// A fact boundary is a sentence end followed by the start of a new statement. The leading [^0-9]
// guard keeps a decimal ("1.5 L oil") from reading as a sentence end; the lookahead keeps a
// mid-sentence abbreviation from splitting. Written with a lookahead only — no lookbehind — so the
// regex literal parses on every browser the fleet is opened on.
const FACT_BOUNDARY = /([^0-9][.!?])\s+(?=[A-Z“"(~])/g;

// The other boundary is explicit: when a second person adds to an open request the backend appends
// their sentence to the note joined by " · " (MaintenanceWorkflowService::addToOpenRequest). That
// middot is a seam between two people's statements, so it separates facts even without a full stop —
// and it must, because the appended sentence may be Arabic and never starts with [A-Z].
const THREAD_SEPARATOR = /\s+·\s+/;

/** Split a note into its separate facts, in order. Returns [] for an empty note. */
export function noteFacts(value) {
  const out = [];

  for (const line of String(value || '').split(/\r?\n+/).flatMap((l) => l.split(THREAD_SEPARATOR))) {
    let start = 0;
    let match;
    FACT_BOUNDARY.lastIndex = 0;

    // Cut AFTER the punctuation (m.index + the matched "<char><.!?>") and resume after the
    // whitespace the match consumed, so the separator itself is never handed to a caller.
    while ((match = FACT_BOUNDARY.exec(line)) !== null) {
      out.push(line.slice(start, match.index + match[1].length));
      start = FACT_BOUNDARY.lastIndex;
    }
    out.push(line.slice(start));
  }

  return out.map((fact) => fact.trim().replace(/^[-–•]\s*/, '')).filter(Boolean);
}

/**
 * Render a note as a dashed fact list. A note that holds only ONE fact is left as a plain
 * paragraph — a single bullet is noise, not structure.
 *
 * @param value  the raw note (ignored when `facts` is passed)
 * @param facts  a pre-split fact list, for callers that clamp or paginate the lines themselves
 * @param quote  wrap the single-fact form in quotes, matching the surrounding "reported" styling
 */
export default function NoteLines({ value, facts, className = '', quote = false }) {
  const lines = facts || noteFacts(value);

  if (lines.length === 0) return null;
  if (lines.length === 1) {
    return <p className={className}>{quote ? `“${lines[0]}”` : lines[0]}</p>;
  }

  return (
    <ul className={`space-y-1 ${className}`}>
      {lines.map((fact, i) => (
        <li key={i} className="flex gap-1.5">
          <span aria-hidden className="select-none opacity-50">–</span>
          {/* dir="auto" per line: a thread can hold an English fact and an Arabic one, and each must
              be laid out by its OWN script rather than by the paragraph's. */}
          <span dir="auto" className="min-w-0 flex-1">{fact}</span>
        </li>
      ))}
    </ul>
  );
}
