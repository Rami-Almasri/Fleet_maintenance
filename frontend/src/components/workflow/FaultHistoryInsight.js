// Chronic Fault Watchdog — the "Historical Insight" panel. Given the fault tags currently selected
// on a vehicle, it asks the backend whether any of them were seen BEFORE and, if so, surfaces how many
// times, when last, and — the part that decides whether the technician believes it — WHERE THAT CAME
// FROM. This alerts the inspector to a recurring/chronic issue the instant they pick the tag, so a car
// that keeps coming back for the same fault is impossible to miss.
//
// This is READ-ONLY reference history at diagnosis. The authoritative recurring-fault detection still
// happens later, at the workshop-confirmation stage (RecurringFaultService) — this panel does not raise
// duplicate alerts or block anything.
//
// ── Both ledgers, and it says which ────────────────────────────────────────────────────────────────
// The endpoint reads the imported N-Maintenance workshop log AND this system's own fault records, and
// returns `sheet_count` / `system_count` / `source_code` per tag. It used to read closed tickets only
// and compare finding text with ===, which on a fleet of 21,928 sheet rows against 167 tasks meant a
// car with nine logged battery failures answered "no history". A count with no provenance is a claim a
// technician cannot check, so every row here shows its mix rather than a bare total.
//
// `related` is deliberately rendered apart and never added in: a different fault in the same system is
// worth knowing and is NOT the same problem coming back.
//
// Data source: GET /maintenance-tickets/vehicle/{id}/fault-insights?tags[]=…
// It renders nothing when there is no vehicle, no tags, or no prior history — so it only ever appears
// when there is a real recurrence to flag.

import useFaultHistory from '../../hooks/useFaultHistory';
import { useI18n } from '../../i18n/I18nContext';
import Icon from '../ui/Icon';
import { fmtDuration } from './meta';

// "12 Mar 2026" from an ISO string; empty when unparseable.
function fmtDate(iso) {
  if (!iso) return '';
  try {
    return new Date(iso).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' });
  } catch {
    return '';
  }
}

export default function FaultHistoryInsight({ vehicleId, tags = [], excludeTicketId }) {
  const { t } = useI18n();
  // Shared with the findings tray in step 2, so the two can never answer the same question differently.
  const { insights } = useFaultHistory(vehicleId, tags, excludeTicketId);

  if (!vehicleId || !tags.length || !insights.length) return null;

  // Where the history came from, in the workshop's own words. "Sheet history" is the imported
  // N-Maintenance log; "System records" is everything this application recorded itself.
  const sourceLine = (it) => {
    const parts = [];
    if (it.sheet_count > 0) parts.push(t('Sheet history: {n}', { n: it.sheet_count }));
    if (it.system_count > 0) parts.push(t('System records: {n}', { n: it.system_count }));
    return parts.join(' · ');
  };

  // An EXACT repeat is a warning; a same-system match is context. Anything that alarms on the second
  // reads as crying wolf the third time a technician sees it, so the panel only goes amber when this
  // exact fault has actually come back before.
  const anyExact = insights.some((it) => it.occurrences > 0);

  return (
    <div
      className={`space-y-2 rounded-xl border p-3 ${
        anyExact ? 'border-amber-200 bg-amber-50/70' : 'border-slate-200 bg-slate-50/70'
      }`}
    >
      <p
        className={`flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide ${
          anyExact ? 'text-amber-700' : 'text-slate-500'
        }`}
      >
        <Icon.Alert className="h-3.5 w-3.5" />
        {anyExact ? t('workflow.insight.title') : t('Related history on this car')}
      </p>
      <ul className="space-y-1.5">
        {insights.map((it) => (
          <li
            key={it.tag}
            className={`rounded-lg bg-white/80 px-2.5 py-2 text-xs ring-1 ${
              it.occurrences > 0 ? 'ring-amber-200/70' : 'ring-slate-200/70'
            }`}
          >
            <div className="flex items-center justify-between gap-2">
              <span className="min-w-0 truncate font-semibold text-slate-800">{it.tag}</span>
              {it.occurrences > 0 ? (
                <span className="shrink-0 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-700">
                  {t('workflow.insight.timesBefore', { n: it.occurrences })}
                </span>
              ) : (
                // Said out loud rather than left to inference: this exact fault is NOT on record, and
                // the reader must not mistake the related list below for a recurrence of it.
                <span className="shrink-0 rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500">
                  {t('Not recorded before')}
                </span>
              )}
            </div>
            {it.last && (
              <p className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-slate-500">
                <span>{t('workflow.insight.lastRepair', { date: fmtDate(it.last.date) || '—', garage: it.last.garage || t('common.unknown') })}</span>
                {it.last.downtime_seconds != null && (
                  <span className="inline-flex items-center gap-1 text-slate-400">
                    <Icon.Clock className="h-3 w-3" /> {t('workflow.insight.downtime', { dur: fmtDuration(it.last.downtime_seconds) })}
                  </span>
                )}
                {it.last.repair_hours != null && (
                  <span className="text-slate-400">{t('workflow.insight.faultTime', { h: it.last.repair_hours })}</span>
                )}
              </p>
            )}
            {/* PROVENANCE. Never folded into the count above it — a technician deciding whether to trust
                "seen 3 times" needs to know whether that is the old paper log, this system, or both. */}
            {(it.sheet_count > 0 || it.system_count > 0) && (
              <p className="mt-1 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-400">
                <span className="rounded bg-white px-1.5 py-px ring-1 ring-slate-200">
                  {it.source_code === 'BOTH'
                    ? t('Seen in both sources')
                    : it.source_code === 'SHEET'
                      ? t('Source: Sheet history')
                      : t('Source: System records')}
                </span>
                <span>{sourceLine(it)}</span>
              </p>
            )}
            {/* Same system, different fault — a weaker, separate claim, and labelled as one. Each row
                names the ACTUAL fault and when it last happened: "this car has had engine trouble" is
                not something a technician can act on, "Engine mechanical issue, 3×, last 23 Jul" is. */}
            {it.related_count > 0 && (
              <div className="mt-1.5 border-t border-slate-100 pt-1.5">
                <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                  {t('Same system, different fault')}
                </p>
                <ul className="mt-0.5 space-y-0.5">
                  {it.related.map((r) => (
                    <li key={r.label} className="flex items-baseline justify-between gap-2 text-[11px]">
                      <span className="min-w-0 truncate text-slate-600">{r.label}</span>
                      <span className="shrink-0 tabular-nums text-slate-400">
                        {r.count}× · {t('last {date}', { date: fmtDate(r.last) || r.last })}
                        {r.source && ` · ${r.source === 'sheet' ? t('Sheet') : t('System')}`}
                      </span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}
