import { useCallback } from 'react';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { SectionCard } from '../../components/ui/Table';
import Badge from '../../components/ui/Badge';
import { Skeleton } from '../../components/ui/Skeleton';
import Icon from '../../components/ui/Icon';

// Rental Readiness — a thin renderer over the backend's single readiness authority
// (VehicleReadinessService::evaluate, GET /readiness/vehicle/{id}). We deliberately do NOT
// recompute any verdict here: the same endpoint feeds the Readiness Dashboard and the "Set to
// Ready" gate, so this checklist can never disagree with the actual rental guard. Whatever checks
// the service returns (pass / warn / fail, each tagged with its pillar) are rendered as-is.

// Per-status glyph + colour. pass = green tick, warn = amber triangle, fail = red x.
const STATUS_UI = {
  pass: { Glyph: Icon.Check, color: 'text-emerald-500', badge: 'emerald' },
  warn: { Glyph: Icon.Alert, color: 'text-amber-500', badge: 'amber' },
  fail: { Glyph: Icon.XCircle, color: 'text-red-500', badge: 'red' },
};

// A failing/near-expiry item is actionable: deep-link the manager straight to the tab where the
// underlying data lives so they can fix it. Only checks whose fix genuinely has a home get a link;
// condition / cleaning / GPS are informational here (no editable surface on this page yet).
const CHECK_TARGET = {
  registration: { tab: 'specs', label: 'Specs' },
  insurance: { tab: 'specs', label: 'Specs' },
  service: { tab: 'specs', label: 'Specs' },
  active_maintenance: { tab: 'maintenance', label: 'Maintenance' },
  open_damage: { tab: 'maintenance', label: 'Maintenance' },
  check_in: { tab: 'financials', label: 'Financials' },
};

export default function ReadinessChecklist({ vehicleId, onNavigate }) {
  const fetcher = useCallback(async () => {
    const { data } = await api.get(`/readiness/vehicle/${vehicleId}`);
    return data.data;
  }, [vehicleId]);
  const { data, loading, error } = useFetch(fetcher, [vehicleId]);

  if (loading) {
    return <Skeleton className="h-64 w-full rounded-2xl" />;
  }
  // Secondary panel — never break the profile if readiness is unavailable; fail quietly.
  if (error || !data) {
    return (
      <SectionCard title="Rental Readiness">
        <div className="px-5 py-4 text-sm text-slate-400">Readiness is unavailable right now.</div>
      </SectionCard>
    );
  }

  const checks = data.checks || [];
  const fails = checks.filter((c) => c.status === 'fail').length;
  const warns = checks.filter((c) => c.status === 'warn').length;
  const passes = checks.length - fails - warns;

  // Headline verdict, straight from the service's own summary + a matching tone.
  const headline = fails > 0
    ? { tone: 'red', text: 'Not ready' }
    : warns > 0
      ? { tone: 'amber', text: `Ready · ${warns} advisory` }
      : { tone: 'emerald', text: 'Ready for delivery' };

  return (
    <SectionCard
      title="Rental Readiness"
      subtitle={`${passes}/${checks.length} checks passing${data.summary ? ` · ${data.summary}` : ''}`}
      actions={<Badge tone={headline.tone}>{headline.text}</Badge>}
    >
      <ul className="divide-y divide-slate-100">
        {checks.map((c) => {
          const ui = STATUS_UI[c.status] || STATUS_UI.warn;
          const Glyph = ui.Glyph;
          const target = c.status !== 'pass' ? CHECK_TARGET[c.key] : undefined;
          return (
            <li key={c.key} className="flex items-start gap-3 px-5 py-3">
              <Glyph className={`mt-0.5 h-5 w-5 shrink-0 ${ui.color}`} />
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                  <span className="text-sm font-medium text-slate-800">{c.label}</span>
                  {c.pillar && <span className="text-[10px] font-medium uppercase tracking-wide text-slate-400">{c.pillar}</span>}
                </div>
                <p className="mt-0.5 text-xs text-slate-500">{c.detail}</p>
              </div>
              {target && onNavigate && (
                <button
                  type="button"
                  onClick={() => onNavigate(target.tab)}
                  className="mt-0.5 inline-flex shrink-0 items-center gap-1 whitespace-nowrap text-xs font-semibold text-indigo-600 transition hover:text-indigo-800"
                >
                  Fix in {target.label}
                  <Icon.ArrowRight className="h-3.5 w-3.5" />
                </button>
              )}
            </li>
          );
        })}
      </ul>
    </SectionCard>
  );
}
