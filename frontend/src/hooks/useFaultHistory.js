// "HAS THIS CAR HAD THIS BEFORE?" — one fetch, shared by every surface that asks.
//
// The question is asked twice on the test-drive screen and the answers must be identical: once at the
// moment of PICKING (the findings tray, step 2 — "you tapped Engine noise, this car has had four engine
// faults") and again at DIAGNOSIS (the watchdog panel, step 3). Two components each rolling their own
// fetch is how the two ended up able to disagree, so the request, the debounce and the shape live here.
//
// Backed by GET /maintenance-tickets/vehicle/{id}/fault-insights, which reads BOTH ledgers — the
// imported N-Maintenance workshop log and this system's own fault records — and returns per tag:
//
//   occurrences / sheet_count / system_count / source_code   this exact fault, and where it came from
//   last, last_seen, days_since_last                         when it last happened
//   related_count, related[]                                 same system, DIFFERENT fault — named, dated
//
// `occurrences === 0` with `related_count > 0` is a real and common answer: this exact fault is not on
// record, but the system it belongs to is. The two are never summed — see VehicleFaultHistoryService.

import { useEffect, useState } from 'react';
import api from '../api/client';

/**
 * @param {number|null} vehicleId
 * @param {string[]} tags          the labels currently selected
 * @param {number|null} excludeTicketId  the ticket being worked on — its own faults are not history
 * @param {boolean} includeClean   keep faults with NO history, so a caller can say "first time"
 * @returns {{ insights: Array, byTag: Object, loading: boolean }}
 */
export default function useFaultHistory(vehicleId, tags = [], excludeTicketId = null, includeClean = false) {
  const [insights, setInsights] = useState([]);
  const [loading, setLoading] = useState(false);
  // Stable dependency for the tag SET — a new array identity on every render would refetch forever.
  const tagKey = tags.join('|');

  useEffect(() => {
    if (!vehicleId || !tags.length) {
      setInsights([]);
      return undefined;
    }
    let alive = true;
    setLoading(true);
    // Debounced so tapping several chips in quick succession fires one request, not one per tap.
    const handle = setTimeout(() => {
      const params = new URLSearchParams();
      tags.forEach((tg) => params.append('tags[]', tg));
      if (excludeTicketId) params.append('exclude_ticket_id', String(excludeTicketId));
      if (includeClean) params.append('include_clean', '1');
      api
        .get(`/maintenance-tickets/vehicle/${vehicleId}/fault-insights?${params.toString()}`)
        .then((r) => {
          if (!alive) return;
          setInsights(Array.isArray(r.data?.data?.insights) ? r.data.data.insights : []);
          setLoading(false);
        })
        .catch(() => { if (alive) { setInsights([]); setLoading(false); } });
    }, 350);
    return () => { alive = false; clearTimeout(handle); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [vehicleId, tagKey, excludeTicketId, includeClean]);

  const byTag = {};
  insights.forEach((it) => { byTag[it.tag] = it; });

  return { insights, byTag, loading };
}
