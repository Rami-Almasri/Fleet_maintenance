// Chronic Fault Watchdog — the "Historical Insight" panel. Given the fault tags currently selected
// on a vehicle, it asks the backend whether any of them were repaired BEFORE and, if so, surfaces the
// last repair's date, downtime and garage. This alerts the inspector to a recurring/chronic issue the
// instant they pick the tag, so a car that keeps coming back for the same fault is impossible to miss.
//
// Data source: GET /maintenance-tickets/vehicle/{id}/fault-insights?tags[]=…  (closed tickets only).
// It renders nothing when there is no vehicle, no tags, or no prior history — so it only ever appears
// when there is a real recurrence to flag.

import { useEffect, useState } from 'react';
import api from '../../api/client';
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
  const [insights, setInsights] = useState([]);
  const tagKey = tags.join(''); // stable dependency for the tag set

  useEffect(() => {
    if (!vehicleId || !tags.length) {
      setInsights([]);
      return undefined;
    }
    let alive = true;
    // Debounce so picking several tags in quick succession fires one request, not one per tap.
    const handle = setTimeout(() => {
      const params = new URLSearchParams();
      tags.forEach((tg) => params.append('tags[]', tg));
      if (excludeTicketId) params.append('exclude_ticket_id', String(excludeTicketId));
      api
        .get(`/maintenance-tickets/vehicle/${vehicleId}/fault-insights?${params.toString()}`)
        .then((r) => { if (alive) setInsights(Array.isArray(r.data?.data?.insights) ? r.data.data.insights : []); })
        .catch(() => { if (alive) setInsights([]); });
    }, 350);
    return () => { alive = false; clearTimeout(handle); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [vehicleId, tagKey, excludeTicketId]);

  if (!vehicleId || !tags.length || !insights.length) return null;

  return (
    <div className="space-y-2 rounded-xl border border-amber-200 bg-amber-50/70 p-3">
      <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-amber-700">
        <Icon.Alert className="h-3.5 w-3.5" /> {t('workflow.insight.title')}
      </p>
      <ul className="space-y-1.5">
        {insights.map((it) => (
          <li key={it.tag} className="rounded-lg bg-white/80 px-2.5 py-2 text-xs ring-1 ring-amber-200/70">
            <div className="flex items-center justify-between gap-2">
              <span className="min-w-0 truncate font-semibold text-slate-800">{it.tag}</span>
              <span className="shrink-0 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-700">
                {t('workflow.insight.timesBefore', { n: it.occurrences })}
              </span>
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
          </li>
        ))}
      </ul>
    </div>
  );
}
