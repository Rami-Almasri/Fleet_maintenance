import { useCallback, useEffect, useState } from 'react';
import api from '../../api/client';
import Button from '../../components/ui/Button';
import { Spinner } from '../../components/ui/Misc';
import { useToast } from '../../components/ui/Toast';

// The 8-point Rental Readiness Checklist — the interactive gate shown right before a rental/booking
// is confirmed. It reads the live per-point verdict from the backend (VehicleReadinessService::
// rentalChecklist) and BLOCKS the confirm while any point is `fail`. One point is manual (Cleaning):
// the agent sets it inline here; every other fail deep-links to the page that fixes it.

const STATUS_STYLE = {
  pass: { ring: 'ring-emerald-200', bg: 'bg-emerald-50', dot: 'bg-emerald-500', text: 'text-emerald-700', glyph: '✓', label: 'Pass' },
  warn: { ring: 'ring-amber-200', bg: 'bg-amber-50', dot: 'bg-amber-500', text: 'text-amber-700', glyph: '!', label: 'Advisory' },
  fail: { ring: 'ring-red-200', bg: 'bg-red-50', dot: 'bg-red-500', text: 'text-red-700', glyph: '✕', label: 'Blocked' },
};

// The inline set-buttons offered for each manual point.
const MANUAL_ACTIONS = {
  cleaning_status: [
    { value: 'clean', label: 'Mark clean', variant: 'primary' },
    { value: 'dirty', label: 'Mark dirty', variant: 'secondary' },
  ],
};

export default function RentalReadinessGate({ vehicleId, vehicleLabel, onBack, onProceed, saving }) {
  const toast = useToast();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [busyField, setBusyField] = useState(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get(`/readiness/vehicle/${vehicleId}/rental-checklist`);
      setData(res.data?.data || null);
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not load the readiness checklist');
    } finally {
      setLoading(false);
    }
  }, [vehicleId, toast]);

  useEffect(() => { load(); }, [load]);

  // Persist a manual point (Cleaning / Tools-Docs) and swap in the freshly re-evaluated checklist.
  const setField = async (field, value) => {
    setBusyField(field);
    try {
      const res = await api.post(`/readiness/vehicle/${vehicleId}/checklist-field`, { field, value });
      setData(res.data?.data || null);
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not update the checklist');
    } finally {
      setBusyField(null);
    }
  };

  const blockers = data?.blockers?.length || 0;
  const points = data?.points || [];

  return (
    <div className="fixed inset-0 z-40 overflow-y-auto bg-slate-900/40 backdrop-blur-sm">
      <div className="mx-auto my-8 max-w-3xl px-4">
        <div className="rounded-2xl bg-white shadow-xl ring-1 ring-slate-200">
          {/* Header */}
          <div className="flex items-start justify-between gap-4 border-b border-slate-100 px-6 py-5">
            <div>
              <h2 className="text-lg font-bold tracking-tight text-slate-900">Rental Readiness Checklist</h2>
              <p className="mt-0.5 text-sm text-slate-500">
                {points.length ? `${points.length} mandatory checks` : 'Mandatory checks'} for {vehicleLabel || `vehicle #${vehicleId}`} before handover.
              </p>
            </div>
            <span
              className={`shrink-0 rounded-full px-3 py-1 text-xs font-semibold ring-1 ${
                blockers ? 'bg-red-50 text-red-700 ring-red-200' : 'bg-emerald-50 text-emerald-700 ring-emerald-200'
              }`}
            >
              {loading ? 'Checking…' : data?.summary || ''}
            </span>
          </div>

          {/* Checklist */}
          <div className="px-6 py-4">
            {loading ? (
              <div className="flex justify-center py-16"><Spinner className="h-7 w-7" /></div>
            ) : (
              <ol className="space-y-2">
                {points.map((p, i) => {
                  const st = STATUS_STYLE[p.status] || STATUS_STYLE.warn;
                  const actions = p.manual ? MANUAL_ACTIONS[p.field] : null;
                  return (
                    <li key={p.key} className={`flex flex-wrap items-center gap-3 rounded-xl px-4 py-3 ring-1 ${st.ring} ${st.bg}`}>
                      <span className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white ${st.dot}`}>
                        {st.glyph}
                      </span>
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                          <span className="text-xs font-semibold text-slate-400">{i + 1}.</span>
                          <span className="text-sm font-semibold text-slate-800">{p.label}</span>
                          <span className={`text-[11px] font-semibold uppercase tracking-wide ${st.text}`}>{st.label}</span>
                        </div>
                        <p className="mt-0.5 text-xs text-slate-500">{p.detail}</p>
                      </div>

                      {/* Fix affordances — only when this point isn't already passing. */}
                      {p.status !== 'pass' && (
                        <div className="flex shrink-0 flex-wrap items-center gap-2">
                          {actions
                            ? actions.map((a) => (
                                <Button
                                  key={a.value}
                                  variant={a.variant}
                                  onClick={() => setField(p.field, a.value)}
                                  loading={busyField === p.field}
                                  className="!px-2.5 !py-1 text-xs"
                                >
                                  {a.label}
                                </Button>
                              ))
                            : p.fix_url && (
                                <a
                                  href={p.fix_url}
                                  target="_blank"
                                  rel="noreferrer"
                                  className="inline-flex items-center gap-1 rounded-lg border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-600 transition hover:border-slate-400 hover:text-slate-800"
                                >
                                  Open fix ↗
                                </a>
                              )}
                        </div>
                      )}
                    </li>
                  );
                })}
              </ol>
            )}
          </div>

          {/* Footer */}
          <div className="flex items-center justify-between gap-3 border-t border-slate-100 px-6 py-4">
            <button
              type="button"
              onClick={onBack}
              disabled={saving}
              className="text-sm font-medium text-slate-500 transition hover:text-slate-700"
            >
              ← Back to contract
            </button>
            <div className="flex items-center gap-3">
              {!loading && (
                <Button variant="secondary" onClick={load} disabled={saving || busyField}>Re-check</Button>
              )}
              <Button
                onClick={onProceed}
                loading={saving}
                disabled={loading || blockers > 0 || Boolean(busyField)}
                title={blockers > 0 ? 'Resolve the blocking checks first' : undefined}
              >
                {blockers > 0 ? `${blockers} to resolve` : 'Confirm rental'}
              </Button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
