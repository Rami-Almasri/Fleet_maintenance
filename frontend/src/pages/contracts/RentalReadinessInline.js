import { useCallback, useEffect, useState } from 'react';
import api from '../../api/client';
import { Spinner } from '../../components/ui/Misc';

// An upfront, read-only preview of the Rental Readiness Checklist for the car the operator just
// picked — so "does this car pass its condition checks?" is answered the moment the vehicle is
// selected, not only at the final confirm gate. It reads the SAME backend authority the blocking
// gate uses (VehicleReadinessService::rentalChecklist), so the two can never disagree. This panel
// only informs; RentalReadinessGate at submit is what actually blocks the save.

const STYLE = {
  pass: { dot: 'bg-emerald-500', chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200', glyph: '✓' },
  warn: { dot: 'bg-amber-500', chip: 'bg-amber-50 text-amber-700 ring-amber-200', glyph: '!' },
  fail: { dot: 'bg-red-500', chip: 'bg-red-50 text-red-700 ring-red-200', glyph: '✕' },
};

export default function RentalReadinessInline({ vehicleId }) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(false);
    try {
      const res = await api.get(`/readiness/vehicle/${vehicleId}/rental-checklist`);
      setData(res.data?.data || null);
    } catch {
      setError(true);
    } finally {
      setLoading(false);
    }
  }, [vehicleId]);

  useEffect(() => { load(); }, [load]);

  if (loading) {
    return (
      <div className="flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-5 py-4 text-sm text-slate-500 shadow-sm">
        <Spinner className="h-4 w-4" /> Checking rental readiness…
      </div>
    );
  }
  // Fail quietly — readiness is a preview here; the submit gate still enforces the real verdict.
  if (error || !data) return null;

  const points = data.points || [];
  const total = points.length;
  const blockers = data.blockers?.length || 0;
  const warnings = data.warnings?.length || 0;
  const passed = total - blockers - warnings;
  const ready = data.ready;

  // Overall tone: green when nothing blocks, red when a check fails.
  const head = ready
    ? { border: 'border-emerald-200', bg: 'bg-emerald-50', text: 'text-emerald-800', glyph: '✓',
        title: warnings ? `Ready to rent — ${passed}/${total} passed, ${warnings} advisory` : `Ready to rent — all ${total} checks passed` }
    : { border: 'border-red-200', bg: 'bg-red-50', text: 'text-red-800', glyph: '⛔',
        title: `Not ready — ${blockers} blocking issue${blockers === 1 ? '' : 's'} to resolve before renting` };

  return (
    <div className={`overflow-hidden rounded-2xl border ${head.border} bg-white shadow-sm`}>
      {/* Verdict header */}
      <div className={`flex items-center gap-3 ${head.bg} px-5 py-3`}>
        <span className={`text-lg ${head.text}`}>{head.glyph}</span>
        <div className="min-w-0 flex-1">
          <p className={`text-sm font-bold ${head.text}`}>{head.title}</p>
          <p className="text-[11px] text-slate-500">Live condition checks — resolvable blockers are enforced again when you confirm the rental.</p>
        </div>
        <span className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-bold tabular-nums ${ready ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'}`}>
          {passed}/{total}
        </span>
      </div>

      {/* Per-check chips */}
      <div className="flex flex-wrap gap-2 px-5 py-4">
        {points.map((p) => {
          const st = STYLE[p.status] || STYLE.warn;
          return (
            <span
              key={p.key}
              title={p.detail || ''}
              className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset ${st.chip}`}
            >
              <span className={`flex h-4 w-4 items-center justify-center rounded-full text-[9px] font-bold text-white ${st.dot}`}>{st.glyph}</span>
              {p.label}
            </span>
          );
        })}
      </div>

      {/* Blocking-check detail — only the failures, so the operator knows what to fix. */}
      {blockers > 0 && (
        <ul className="space-y-1 border-t border-red-100 bg-red-50/40 px-5 py-3">
          {data.blockers.map((p) => (
            <li key={p.key} className="flex items-start gap-2 text-xs text-red-700">
              <span className="mt-0.5 font-bold">✕</span>
              <span><span className="font-semibold">{p.label}:</span> {p.detail}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
