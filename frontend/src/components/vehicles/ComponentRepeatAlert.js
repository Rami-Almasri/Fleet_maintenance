import { useCallback, useState } from 'react';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import Icon from '../../components/ui/Icon';
import { aed2, fmtDate, num } from '../../lib/format';

/**
 * "This car keeps eating the same part."
 *
 * A banner that fires when one part TYPE has been replaced on this vehicle more than once. It is a
 * COUNT, not a diagnosis: it states how many times, how long each fitting lasted, and what the
 * catalog expects — the reason lives in the tickets, which every row links to.
 *
 * The numbers come from the same `/Vehicle/{id}/components` payload the Installed Components tab
 * reads (`repeat_replacements`), so the banner on the overview tab and the table on the components
 * tab can never disagree. Pass `data` when the caller already has that payload; pass only
 * `vehicleId` and it fetches for itself.
 *
 * Renders NOTHING when there is no repeat, while loading, or on error (a user without
 * `components.view` gets a 403 here and should simply not see the strip).
 */
export default function ComponentRepeatAlert({ vehicleId, data, onOpenComponents }) {
  const selfFetch = data === undefined;

  // `paused` only stops BACKGROUND polling, so the guard has to live inside the fetcher itself —
  // otherwise a caller that already handed us the payload would still cost one request per mount.
  const fetcher = useCallback(
    async () => (selfFetch && vehicleId
      ? (await api.get(`/Vehicle/${vehicleId}/components`)).data.data?.repeat_replacements ?? null
      : null),
    [vehicleId, selfFetch]
  );
  const { data: fetched } = useFetch(fetcher, [vehicleId, selfFetch]);

  const repeat = selfFetch ? fetched : data;
  const rows = repeat?.rows || [];

  const [expanded, setExpanded] = useState(false);

  if (!rows.length) return null;

  const worst = rows.some((r) => r.severity === 'high') ? 'high' : 'watch';
  const shown = expanded ? rows : rows.slice(0, 3);

  const tone = worst === 'high'
    ? { ring: 'ring-rose-200', bg: 'bg-rose-50/70', icon: 'text-rose-600', title: 'text-rose-900', body: 'text-rose-800' }
    : { ring: 'ring-amber-200', bg: 'bg-amber-50/70', icon: 'text-amber-600', title: 'text-amber-900', body: 'text-amber-800' };

  return (
    <div className={`rounded-2xl ${tone.bg} p-4 shadow-soft ring-1 ring-inset ${tone.ring}`}>
      <div className="flex items-start gap-3">
        <Icon.Alert className={`mt-0.5 h-5 w-5 shrink-0 ${tone.icon}`} />
        <div className="min-w-0 flex-1">
          <h3 className={`text-sm font-semibold ${tone.title}`}>
            {rows.length === 1
              ? 'This vehicle has replaced the same part more than once'
              : `This vehicle has replaced ${num(rows.length)} different parts more than once`}
          </h3>
          <p className={`mt-0.5 text-xs ${tone.body}`}>
            Counted per fitting position, so changing four tyres at once is one round, not four. Transfers
            and the sale of the car are excluded.
          </p>

          <ul className="mt-3 space-y-2">
            {shown.map((r) => (
              <li
                key={r.component_catalog_id || r.type}
                className="rounded-xl bg-white/80 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-white/60"
              >
                <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                  <span className="font-semibold text-slate-900">{r.type || 'Unknown part'}</span>
                  <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${r.severity === 'high' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700'}`}>
                    replaced {num(r.replacements)}×
                  </span>
                  {/* A multi-slot part (tyres, brakes) is counted per corner, so the round count and
                      the unit count differ — say so on the row rather than leave it to be queried. */}
                  {r.slots > 1 && (
                    <span className="text-[11px] text-slate-400">
                      per position · {num(r.total_removals)} units across {num(r.slots)} positions
                    </span>
                  )}
                  {r.failing_early && (
                    <span className="rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-semibold text-rose-700">
                      failing early
                    </span>
                  )}
                </div>
                <div className="mt-1 text-xs text-slate-500">
                  {lifeLine(r)}
                  {r.last_removed_at && <> · last replaced {fmtDate(r.last_removed_at)}</>}
                  {/* Cost coverage is counted in UNITS (total_removals), never in rounds —
                      "6 of 3 fittings" is what happens when the two are mixed. */}
                  {r.costed_count > 0 && <> · {aed2(r.known_spend)} spent across {num(r.costed_count)} of {num(r.total_removals)} fittings</>}
                </div>
                {r.currently_fitted && (
                  <div className="mt-0.5 text-xs text-slate-400">
                    A replacement is fitted now — installed {fmtDate(r.currently_fitted.installed_at)}
                    {r.currently_fitted.age_days !== null && <> ({num(r.currently_fitted.age_days)} days ago)</>}.
                  </div>
                )}
              </li>
            ))}
          </ul>

          <div className="mt-3 flex flex-wrap items-center gap-4">
            {rows.length > 3 && (
              <button
                type="button"
                onClick={() => setExpanded((s) => !s)}
                className={`text-xs font-semibold ${tone.title} hover:underline`}
              >
                {expanded ? 'Show less' : `Show all ${num(rows.length)} repeat parts`}
              </button>
            )}
            {onOpenComponents && (
              <button
                type="button"
                onClick={onOpenComponents}
                className={`text-xs font-semibold ${tone.title} hover:underline`}
              >
                Open Installed Components →
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

/** "each lasted ~8,200 km on average (catalog expects 25,000 km)" — or the honest silence when no life is known. */
function lifeLine(r) {
  const parts = [];
  if (r.avg_life_km !== null && r.avg_life_km !== undefined) parts.push(`${num(r.avg_life_km)} km`);
  if (r.avg_life_days !== null && r.avg_life_days !== undefined) parts.push(`${num(r.avg_life_days)} days`);

  if (!parts.length) return 'No install/removal odometer or dates recorded, so the life achieved is unknown';

  const expected = r.expected_life_km
    ? ` (catalog expects ${num(r.expected_life_km)} km)`
    : r.expected_life_months
      ? ` (catalog expects ${num(r.expected_life_months)} months)`
      : ' (catalog sets no expected life)';

  return `Each fitting lasted ${parts.join(' / ')} on average${expected}`;
}
