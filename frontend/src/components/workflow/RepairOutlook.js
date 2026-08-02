// "What the garage will do" — the inspector's findings answered with the work they imply.
//
// WHY IT LIVES ON THE TICKET AND NOT ON THE ASSIGN STEP. It was born inside the Dispatch Plan, where
// every other block is a comparison between garages. But this one never described a garage — it
// describes the JOB, and the people who need it (the coordinator chasing the shop, the driver taking
// the car in, the supervisor answering "what are they even doing to it?") open the TICKET, not the
// assign dialog, which one person sees once. So the expected work moved here and the fault's repair
// history moved the other way, onto the screen where a garage is actually being chosen.
//
// Deliberately the plainest thing on the ticket: no percentages, no scores, no tiers, no engine
// vocabulary ([[operational-language-over-engine-vocabulary]]) — just two lists per fault.
//
// IT MUST NOT READ AS A DIAGNOSIS. Nobody has opened this car. The header says so once, in a sentence,
// rather than decorating every line with a hedge — a supervisor who reads "Replace spark plugs" as a
// decision already made is the failure this block would otherwise introduce.
//
// A hand-written finding with no concept behind it says so and stops. Guessing the repair for it would
// put invented work in front of the person authorising it. See [[RepairOutlook]] (the PHP service).

import { useEffect, useState } from 'react';
import api from '../../api/client';
import { useI18n } from '../../i18n/I18nContext';
import Icon from '../ui/Icon';

/**
 * @param {number|string} ticketId  self-fetches the ticket's expected work
 * @param {Array}         rows      pre-loaded rows instead (the fault-first finder already has them)
 */
export default function RepairOutlook({ ticketId, rows: preloaded, defaultOpen = true, className = '' }) {
  const { t } = useI18n();
  // Labels stay nested under the dispatch plan: they are this feature's wording, and re-keying them
  // would churn both language tables for no reader-visible gain.
  const dp = (k, v) => t(`workflow.garageRec.dispatchPlan.${k}`, v);

  const [rows, setRows] = useState(preloaded || []);
  const [open, setOpen] = useState(defaultOpen);

  useEffect(() => {
    if (preloaded) { setRows(preloaded); return undefined; }
    if (!ticketId) return undefined;
    let alive = true;
    api
      .get(`/maintenance-tickets/${ticketId}/repair-outlook`)
      // Read-only knowledge: a failure renders nothing rather than an error the reader cannot act on.
      .then((res) => alive && setRows(res.data?.data || []))
      .catch(() => alive && setRows([]));
    return () => { alive = false; };
  }, [ticketId, preloaded]);

  if (!rows.length) return null;

  // The faults we can actually describe. A ticket of nothing but hand-written findings still renders
  // the row for each one — "we have no standard repair for this" is the useful answer there.
  const known = rows.filter((r) => r.known && (r.causes?.length || r.fixes?.length));

  return (
    <div className={`overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-slate-300 ${className}`}>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        className={`flex w-full items-center justify-between gap-3 px-3 py-2 text-start transition ${
          open ? 'bg-white' : 'hover:bg-slate-50'}`}
      >
        <span className="min-w-0">
          <span className="block text-[12px] font-semibold text-slate-700">{dp('outlook.title')}</span>
          <span className="block text-[11px] leading-snug text-slate-400">
            {dp('outlook.subtitle', { n: known.length })}
          </span>
        </span>
        <Icon.ChevronDown className={`h-4 w-4 shrink-0 text-slate-400 transition ${open ? 'rotate-180' : ''}`} />
      </button>

      {open && (
        <div className="space-y-2.5 border-t border-slate-100 bg-slate-50/60 p-3">
          {/* Said ONCE, at the top, in the words a supervisor would use. */}
          <p className="text-[11px] leading-snug text-slate-500">{dp('outlook.caveat')}</p>

          {rows.map((row, i) => (
            <div key={`${row.symptom}-${i}`} className="rounded-lg bg-white p-2.5 ring-1 ring-inset ring-slate-200">
              <p className="text-[12px] font-semibold text-slate-800">{row.symptom}</p>

              {row.known ? (
                <div className="mt-1.5 flex flex-col gap-1.5 sm:flex-row">
                  {row.causes?.length > 0 && (
                    <div className="flex-1">
                      <p className="text-[10px] font-bold uppercase tracking-wide text-slate-400">{dp('outlook.causedBy')}</p>
                      <ul className="mt-0.5 space-y-0.5">
                        {row.causes.map((c, n) => (
                          <li key={n} className="flex items-start gap-1 text-[12px] leading-snug text-slate-700">
                            <span aria-hidden className="shrink-0 text-slate-300">•</span>{c}
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}
                  {row.fixes?.length > 0 && (
                    <div className="flex-1">
                      <p className="text-[10px] font-bold uppercase tracking-wide text-slate-400">{dp('outlook.workDone')}</p>
                      <ul className="mt-0.5 space-y-0.5">
                        {row.fixes.map((f, n) => (
                          <li key={n} className="flex items-start gap-1 text-[12px] leading-snug text-slate-700">
                            <span aria-hidden className="shrink-0 text-slate-300">•</span>
                            <span>
                              {f.label}
                              {/* "Typical" is the couple of repairs that usually fix it; the rest are
                                  possibilities. Marking the difference stops the tail of the list
                                  being read as work that is equally likely to be needed. */}
                              {!f.typical && <span className="ms-1 text-[10px] text-slate-400">{dp('outlook.sometimes')}</span>}
                            </span>
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}
                </div>
              ) : (
                // Honest gap. The supervisor asks the garage rather than reading a guess.
                <p className="mt-1 text-[11px] leading-snug text-slate-400">{dp('outlook.unknown')}</p>
              )}

              {/* THE MEASURED LINE. Everything above is curated knowledge about the fault; this is the
                  only part counted from this fleet's own ~8k historical repairs, and it is the part that
                  changes what the ticket-holder expects — "a third of our alignment jobs also involved
                  engine noise" says the job may grow before anyone promises the car back.
                  Marked green, like every measured figure elsewhere, so it never reads as curated. */}
              {row.history?.length > 0 && (
                <ul className="mt-2 space-y-0.5 border-t border-slate-100 pt-1.5">
                  {row.history.map((h, n) => (
                    <li key={n} className="flex items-start gap-1.5 text-[11px] leading-snug text-emerald-800">
                      <span aria-hidden className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-500" />
                      {dp(`outlook.history.${h.relation}`, { rate: h.rate, count: h.count, label: h.label })}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
