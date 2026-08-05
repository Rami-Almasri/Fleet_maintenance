// The compact operational KPI strip under the launcher — "how is the fleet doing
// right now?". Config-driven: each KPI is a declarative row, gated by the same
// permission + feature-flag rules as everything else, and rendered with the
// shared MetricCard (which brings its own loading skeleton, so the row never
// reflows when data lands).
//
// The set is curated for operational signal over quantity — every tile is either
// a live state you act on (available / on-rent / in-shop) or an exception you
// chase (overdue rentals, overdue maintenance, service due). We deliberately
// omit a raw "total fleet" tile: it is just the sum of the state tiles beside it.

import { memo } from 'react';
import MetricCard, { MetricGrid } from '../ui/MetricCard';
import Icon from '../ui/Icon';
import { usePermissions } from '../../hooks/usePermissions';
import { isFeatureEnabled } from '../../config/features';
import { aed } from '../../lib/format';

// `get(m)` reads the normalized metrics object built by the overview page.
// `modules` scopes a KPI to the module Overview(s) it belongs to.
const KPIS = [
  { key: 'rented',      label: 'Active Rentals',      tone: 'blue',    modules: ['fleet-operations'],                    icon: <Icon.Route className="h-5 w-5" />,  to: '/contracts',  permission: 'contracts.view',   hint: 'Cars out on paid rental',   get: (m) => m.rented },
  { key: 'available',   label: 'Available',           tone: 'emerald', modules: ['fleet-operations'],                    icon: <Icon.Check className="h-5 w-5" />,  to: '/vehicles',                                   hint: 'Ready to rent now',         get: (m) => m.available },
  { key: 'workshop',    label: 'In Workshop',         tone: 'amber',   modules: ['maintenance'],                         icon: <Icon.Wrench className="h-5 w-5" />, to: '/car-status', permission: 'maintenance.view', hint: 'Down for maintenance',      get: (m) => m.maint },
  { key: 'occupancy',   label: 'Fleet Occupancy',     tone: 'violet',  modules: ['fleet-operations', 'fleet-intelligence'], icon: <Icon.Gauge className="h-5 w-5" />,                                              hint: 'On-rent share of active fleet', get: (m) => (m.activeFleet ? `${Math.round((m.rented / m.activeFleet) * 100)}%` : '—') },
  { key: 'overdue_r',   label: 'Overdue Rentals',     tone: 'red',     modules: ['fleet-operations'],                    icon: <Icon.Alert className="h-5 w-5" />,  to: '/contracts',  permission: 'contracts.view',   hint: 'Out past their due date',   get: (m) => m.overdueRentals },
  { key: 'overdue_m',   label: 'Overdue Maintenance', tone: 'red',     modules: ['maintenance'],                         icon: <Icon.Clock className="h-5 w-5" />,  to: '/car-status', permission: 'maintenance.view', hint: 'Past their ready-by target', get: (m) => m.overdueMaintenance },
  { key: 'service_due', label: 'Due for Service',     tone: 'amber',   modules: ['maintenance', 'fleet-intelligence'],   icon: <Icon.Spark className="h-5 w-5" />,  to: '/inspections/schedules?tab=service', permission: 'reminders.view', hint: 'Overdue or approaching', get: (m) => m.serviceDue },
  { key: 'outstanding', label: 'Outstanding Balance', tone: 'slate',   modules: ['finance'],                             icon: <Icon.Cash className="h-5 w-5" />,   flag: 'financial',                                 hint: 'Unpaid contract balances',  get: (m) => aed(m.outstanding) },
];

function OperationalDashboard({ metrics = {}, loading = false, moduleId = null }) {
  const { can } = usePermissions();
  const visible = KPIS.filter(
    (k) => (!moduleId || (k.modules || []).includes(moduleId)) && isFeatureEnabled(k.flag) && can(k.permission),
  );

  if (!visible.length) return null;

  return (
    <MetricGrid cols={4}>
      {visible.map((k) => (
        <MetricCard
          key={k.key}
          label={k.label}
          value={loading ? '—' : k.get(metrics) ?? '—'}
          icon={k.icon}
          tone={k.tone}
          hint={k.hint}
          to={k.to}
          loading={loading}
        />
      ))}
    </MetricGrid>
  );
}

export default memo(OperationalDashboard);
