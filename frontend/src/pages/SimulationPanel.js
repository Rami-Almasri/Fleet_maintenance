import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api/client';
import { useToast } from '../components/ui/Toast';
import { PageHeader } from '../components/ui/Misc';
import { SectionCard } from '../components/ui/Table';
import Button from '../components/ui/Button';

// The two demo scenarios. Each forces a REAL live condition and lets the system react end-to-end.
const SCENARIOS = [
  {
    key: 'oil-alert',
    title: 'Trigger Oil Alert',
    emoji: '🛢️',
    blurb: 'Forces a "Service Due" oil condition on the target car, then runs the real notification scanner. Watch the oil-change alert land in the bell — click it to jump straight into the Log Oil Change flow.',
    cta: 'Force Service Due',
  },
  {
    key: 'fault-discovery',
    title: 'Trigger Fault Discovery',
    emoji: '🔧',
    blurb: 'Files a real customer complaint against the target car. Watch the ticket appear in the Inspector\'s Pad and the Maintenance Workflow board, ready for a supervisor to dispatch.',
    cta: 'Inject Fault',
  },
];

export default function SimulationPanel() {
  const toast = useToast();
  const navigate = useNavigate();

  const [status, setStatus] = useState(null);   // { demo_mode, active, active_count, suggested_vehicle }
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(null);        // scenario key currently firing (or 'reset')
  const [vehicleRef, setVehicleRef] = useState('');
  const [severity, setSeverity] = useState('moderate');
  const [log, setLog] = useState([]);            // session activity, most-recent first

  const demoOn = !!status?.demo_mode;

  const loadStatus = useCallback(async () => {
    try {
      const { data } = await api.get('/simulation/status');
      setStatus(data.data || {});
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not load simulation status');
    } finally {
      setLoading(false);
    }
  }, [toast]);

  useEffect(() => { loadStatus(); }, [loadStatus]);

  const pushLog = (entry) => setLog((l) => [{ at: new Date().toLocaleTimeString(), ...entry }, ...l].slice(0, 25));

  const fire = async (scenario) => {
    setBusy(scenario.key);
    try {
      const payload = { vehicle: vehicleRef.trim() || undefined };
      if (scenario.key === 'fault-discovery') payload.severity = severity;
      const { data } = await api.post(`/simulation/${scenario.key}`, payload);
      const d = data.data || {};
      toast.success(data.message || `${scenario.title} done`);
      pushLog({
        label: scenario.title,
        emoji: scenario.emoji,
        vehicle: d.vehicle?.label,
        detail: scenario.key === 'oil-alert'
          ? `${(d.service_status?.overdue_km ?? 0).toLocaleString()} km overdue`
          : `ticket #${d.ticket_id} · ${d.workflow_status}`,
        link: scenario.key === 'oil-alert' ? d.deep_link : d.deep_links?.ticket,
        linkLabel: scenario.key === 'oil-alert' ? 'Open Log Oil Change' : 'Open ticket',
      });
      loadStatus();
    } catch (e) {
      toast.error(e.response?.data?.message || `Could not run ${scenario.title}`);
    } finally {
      setBusy(null);
    }
  };

  const reset = async (eventId) => {
    setBusy('reset');
    try {
      const { data } = await api.post('/simulation/reset', eventId ? { event: eventId } : {});
      toast.success(data.message || 'Reset complete');
      pushLog({ label: 'Reset', emoji: '♻️', detail: `${data.data?.reverted ?? 0} scenario(s) rolled back` });
      loadStatus();
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not reset');
    } finally {
      setBusy(null);
    }
  };

  const suggested = status?.suggested_vehicle;

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1100px] space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Simulation Panel"
          subtitle="Admin-only demo console. Force a real scenario on a real vehicle and watch the system handle it end-to-end — then roll everything back with one click. Nothing here is permanent."
        />

        {/* Demo-mode arm state */}
        {!loading && (
          demoOn ? (
            <div className="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800">
              <span className="inline-block h-2 w-2 animate-pulse rounded-full bg-emerald-500" />
              <span className="font-semibold">Demo Mode is ON</span>
              <span className="text-emerald-700">— the triggers below are armed.</span>
            </div>
          ) : (
            <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
              <p className="font-semibold">Demo Mode is OFF — triggers are disabled.</p>
              <p className="mt-0.5 text-amber-700">
                Set <code className="rounded bg-amber-100 px-1 py-0.5 font-mono text-xs">FEATURE_DEMO_MODE=true</code> in the backend <code className="font-mono text-xs">.env</code> (then <code className="font-mono text-xs">php artisan config:clear</code>) to arm the panel. This is a hard server-side guard — the buttons cannot touch data while it is off.
              </p>
            </div>
          )
        )}

        {/* Target selection */}
        <SectionCard title="Target vehicle" subtitle="Leave blank to auto-pick an active-fleet car." bodyClass="px-5 py-4">
          <div className="flex flex-wrap items-end gap-4">
            <label className="text-sm">
              <span className="mb-1 block font-medium text-slate-700">Vehicle (id, plate or code)</span>
              <input
                value={vehicleRef}
                onChange={(e) => setVehicleRef(e.target.value)}
                placeholder={suggested ? `auto: ${suggested.label}` : 'auto-pick'}
                className="w-72 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100"
              />
            </label>
            <label className="text-sm">
              <span className="mb-1 block font-medium text-slate-700">Fault severity</span>
              <select
                value={severity}
                onChange={(e) => setSeverity(e.target.value)}
                className="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100"
              >
                <option value="critical">🔴 Critical</option>
                <option value="moderate">🟡 Moderate</option>
                <option value="high">🟠 Normal</option>
                <option value="routine">🟢 Clear</option>
              </select>
            </label>
            {suggested && (
              <p className="text-xs text-slate-400">Suggested: <span className="font-medium text-slate-600">{suggested.label}</span></p>
            )}
          </div>
        </SectionCard>

        {/* Scenario triggers */}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          {SCENARIOS.map((s) => (
            <div key={s.key} className="flex flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
              <p className="text-lg font-semibold text-slate-900">{s.emoji} {s.title}</p>
              <p className="mt-1 flex-1 text-sm text-slate-500">{s.blurb}</p>
              <div className="mt-4">
                <Button
                  variant="primary"
                  loading={busy === s.key}
                  disabled={!demoOn || busy}
                  onClick={() => fire(s)}
                >
                  {s.cta}
                </Button>
              </div>
            </div>
          ))}
        </div>

        {/* Active simulations + reset */}
        <SectionCard
          title="Active simulations"
          subtitle="Everything the panel has forced and not yet rolled back."
          bodyClass="px-5 py-2"
          actions={
            (status?.active?.length > 0) && (
              <Button variant="danger" size="sm" loading={busy === 'reset'} disabled={!demoOn} onClick={() => reset()}>
                Reset all
              </Button>
            )
          }
        >
          {!status?.active?.length ? (
            <p className="py-2 text-sm text-slate-400">Nothing active — the fleet is clean.</p>
          ) : (
            <ul className="divide-y divide-slate-100">
              {status.active.map((e) => (
                <li key={e.id} className="flex items-center justify-between gap-3 py-2.5 text-sm">
                  <div className="min-w-0">
                    <span className="font-medium text-slate-800">
                      {e.scenario === 'oil_alert' ? '🛢️' : '🔧'} {e.label}
                    </span>
                    <span className="block text-xs text-slate-400">{e.created_at}</span>
                  </div>
                  <div className="flex shrink-0 items-center gap-2">
                    {e.scenario === 'fault_discovery' && e.maintenance_id && (
                      <Button variant="ghost" size="sm" onClick={() => navigate(`/maintenance-workflow/${e.maintenance_id}`)}>
                        View
                      </Button>
                    )}
                    {e.scenario === 'oil_alert' && e.vehicle && (
                      <Button variant="ghost" size="sm" onClick={() => navigate(`/vehicles/${e.vehicle.id}?logOil=1`)}>
                        View
                      </Button>
                    )}
                    <Button variant="secondary" size="sm" disabled={!demoOn || busy} onClick={() => reset(e.id)}>
                      Undo
                    </Button>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </SectionCard>

        {/* Session activity log with quick deep links */}
        <SectionCard title="Activity" subtitle="This session only — most recent first." bodyClass="px-5 py-2">
          {log.length === 0 ? (
            <p className="py-2 text-sm text-slate-400">Nothing fired yet. Trigger a scenario above.</p>
          ) : (
            <ul className="divide-y divide-slate-100">
              {log.map((r, i) => (
                <li key={i} className="flex items-center gap-3 py-2.5 text-sm">
                  <span className="w-16 shrink-0 tabular-nums text-xs text-slate-400">{r.at}</span>
                  <span className="min-w-0 flex-1">
                    <span className="font-medium text-slate-800">{r.emoji} {r.label}</span>
                    {r.vehicle && <span className="ml-2 text-slate-500">{r.vehicle}</span>}
                    {r.detail && <span className="ml-2 text-xs text-slate-400">· {r.detail}</span>}
                  </span>
                  {r.link && (
                    <Button variant="ghost" size="sm" onClick={() => navigate(r.link)}>{r.linkLabel}</Button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </SectionCard>
      </div>
    </div>
  );
}
