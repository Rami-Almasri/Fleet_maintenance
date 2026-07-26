// ────────────────────────────────────────────────────────────────────────────
// Module Overview — the home tab of every module's mini-app (route /apps/:id).
// The module's tab bar is rendered by AppLayout (persistent across sections);
// this page renders the Overview *content*: the module-scoped KPIs, Action
// Center and Recent Activity, plus quick-links into the module's sections.
//
// Which KPIs / action categories appear is driven by the `modules` tags on the
// shared OperationalDashboard / ActionCenter configs — so this page is generic
// and every module gets a consistent Overview with zero bespoke code.
// ────────────────────────────────────────────────────────────────────────────

import { useCallback, useMemo } from 'react';
import { useParams, Navigate, Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { usePermissions } from '../hooks/usePermissions';
import { useNotifications } from '../hooks/useNotifications';
import { getModule, isModuleVisible, visibleSections } from '../config/moduleRegistry';
import { ErrorState } from '../components/ui/Misc';
import OperationalDashboard from '../components/workspace/OperationalDashboard';
import ActionCenter from '../components/workspace/ActionCenter';
import RecentActivity from '../components/workspace/RecentActivity';
import AppCard from '../components/workspace/AppCard';

const EMPTY_FLAGS = {
  contract_expiry: { count: 0, items: [] },
  in_maintenance: { count: 0, items: [] },
  invoice_overdue: { count: 0, items: [] },
  inspection_due: { count: 0, items: [] },
};

const REFRESH_INTERVAL_MS = 60_000;

// Modules whose Overview shows the operational KPI strip / Action Center. Kept in
// sync with the `modules` tags in OperationalDashboard / ActionCenter configs.
const KPI_MODULES = new Set(['fleet-operations', 'maintenance', 'finance', 'fleet-intelligence']);
const ACTION_MODULES = new Set(['fleet-operations', 'maintenance', 'finance']);

function Band({ title, action, children }) {
  return (
    <section aria-label={title}>
      <div className="mb-3 flex items-baseline justify-between gap-3">
        <h2 className="font-display text-sm font-bold uppercase tracking-wide text-slate-500">{title}</h2>
        {action}
      </div>
      {children}
    </section>
  );
}

const BandLink = ({ to, children }) => (
  <Link to={to} className="text-xs font-semibold text-indigo-600 outline-none hover:underline focus-visible:underline">
    {children}
  </Link>
);

export default function ModuleOverview() {
  const { moduleId } = useParams();
  const module = getModule(moduleId);
  const { can, roles } = usePermissions();
  const { latest, ready } = useNotifications();

  const canInsights = can('insights.view');
  const needsData = !!module && KPI_MODULES.has(module.id);

  const fetcher = useCallback(async () => {
    if (!needsData) return {};
    const safe = (fallback) => () => ({ data: { data: fallback } });
    const calls = [
      api.get('/Dashboard', { params: { expiring_days: 7 } }).catch(safe({})),
      api.get('/Dashboard/proactive-flags', { params: { days: 7 } }).catch(safe(EMPTY_FLAGS)),
    ];
    if (canInsights) {
      calls.push(api.get('/intelligence/service-due').catch(safe({ summary: { actionable: null } })));
    }
    const [sumRes, flagRes, svcRes] = await Promise.all(calls);
    return {
      summary: sumRes.data.data || {},
      proactive: flagRes.data.data || EMPTY_FLAGS,
      serviceDue: canInsights ? svcRes?.data?.data?.summary?.actionable ?? null : null,
    };
  }, [needsData, canInsights]);

  const { data, loading, error, reload } = useFetch(fetcher, [fetcher], {
    refreshInterval: needsData ? REFRESH_INTERVAL_MS : 0,
  });

  const metrics = useMemo(() => {
    const summary = data?.summary || {};
    const fleet = summary.fleet_status || {};
    const available = fleet.available || 0;
    const rented = fleet.rented || 0;
    const maint = fleet.maintenance || 0;
    return {
      available,
      rented,
      maint,
      activeFleet: available + rented + maint,
      serviceDue: data?.serviceDue ?? null,
      overdueRentals: summary.overdue_rentals ?? 0,
      overdueMaintenance: summary.overdue_maintenance ?? 0,
      outstanding: summary.total_outstanding_balance ?? 0,
    };
  }, [data]);

  // Guards (after hooks, so hook order stays stable).
  if (!module) return <Navigate to="/" replace />;
  if (!isModuleVisible(module, can, roles)) return <Navigate to="/" replace />;

  const sections = visibleSections(module, can, roles);
  const sectionApps = sections.map((s) => ({
    key: s.name,
    name: s.name,
    to: s.route,
    icon: s.icon,
    tone: module.tone,
    status: s.status,
  }));

  const showKpis = KPI_MODULES.has(module.id);
  const showActions = ACTION_MODULES.has(module.id);

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-8 px-4 sm:px-6 lg:px-8">
        <p className="text-sm text-slate-500">{module.tagline}</p>

        {showKpis && (
          <Band title="Operational Overview" action={!error && <BandLink to="/dashboard">Full dashboard</BandLink>}>
            {error ? (
              <ErrorState
                title="Couldn’t load fleet metrics"
                message="The operational data feed didn’t respond. The module sections below are unaffected."
                onRetry={reload}
              />
            ) : (
              <OperationalDashboard metrics={metrics} loading={loading} moduleId={module.id} />
            )}
          </Band>
        )}

        {showActions && (
          <Band title="Action Center">
            <ActionCenter data={data?.proactive || EMPTY_FLAGS} loading={loading && !error} moduleId={module.id} />
          </Band>
        )}

        <Band title={`Explore ${module.name}`}>
          <div className="stagger grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            {sectionApps.map((app) => (
              <AppCard key={app.key} app={app} />
            ))}
          </div>
        </Band>

        <Band title="Recent Activity" action={<BandLink to="/notifications">View all</BandLink>}>
          <RecentActivity items={latest} loading={!ready} />
        </Band>
      </div>
    </div>
  );
}
