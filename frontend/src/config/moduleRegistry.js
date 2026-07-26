// ────────────────────────────────────────────────────────────────────────────
// Module Registry — the SINGLE source of truth for the Odoo-style module-first
// architecture. Everything (the App Launcher, each module's tab bar, the
// route→module resolver, permission gating) is generated from this file.
//
// Adding a new module later = add one entry here. Nothing else needs to change.
//
// A module:
//   id           stable slug (also its overview route: /apps/<id>)
//   name         display name
//   icon         Icon.* component
//   tone         accent tone (MetricCard/Badge key)
//   tagline      one-line description (launcher card + overview subtitle)
//   sections     ordered tabs (Overview is implicit, always first):
//     { name, route, permission, icon, flag?, status? }
//       route      an EXISTING app route (reused as-is), or null for a
//                  `status: 'soon'` placeholder tab. A `/apps/<id>` route jumps
//                  to another module (a cross-app shortcut).
//       permission perm string required to see the tab (null = any authed user)
//       flag       optional feature gate: 'financial' | 'intel'
//       status     'soon' → disabled "Coming Soon" tab (never a dead link)
// ────────────────────────────────────────────────────────────────────────────

import Icon from '../components/ui/Icon';
import { isFeatureEnabled } from './features';
import { pathBlockedForRoles } from './access';

export const MODULES = [
  {
    id: 'fleet-operations',
    name: 'Fleet Operations',
    icon: Icon.Car,
    tone: 'indigo',
    tagline: 'Vehicles, rentals and the daily movement of the fleet',
    sections: [
      { name: 'Vehicles', route: '/vehicles', permission: 'vehicles.view', icon: Icon.Car },
      { name: 'Contracts', route: '/contracts', permission: 'contracts.view', icon: Icon.Invoice },
      { name: 'Customers', route: '/customers', permission: 'customers.view', icon: Icon.Users },
      { name: 'Driver Dispatch', route: '/driver-dispatch', permission: 'logistics.view', icon: Icon.Truck },
      { name: 'Fleet Health', route: '/inspections/schedules', permission: 'inspections.view', icon: Icon.Shield },
      { name: 'Drivers', route: '/drivers', permission: 'drivers.view', icon: Icon.Users },
      { name: 'Reports', route: '/apps/reports', permission: 'insights.view', icon: Icon.Chart },
    ],
  },
  {
    id: 'maintenance',
    name: 'Maintenance',
    icon: Icon.Wrench,
    tone: 'amber',
    tagline: 'The repair pipeline, service schedule, parts and history',
    sections: [
      { name: 'Workshop', route: '/car-status', permission: 'maintenance.view', icon: Icon.Wrench },
      { name: 'Work Orders', route: '/maintenance-workflow', permission: 'maintenance.view', icon: Icon.Activity },
      { name: 'Service Due', route: '/service-due', permission: 'insights.view', flag: 'intel', icon: Icon.Clock },
      { name: 'Inspections', route: '/inspection-review', permission: 'maintenance.manage', icon: Icon.Check },
      { name: 'Parts', route: '/parts', permission: 'parts.view', icon: Icon.Coins },
      { name: 'Garages', route: '/garages', permission: 'maintenance.view', icon: Icon.Wrench },
      { name: 'History', route: '/maintenance-history', permission: 'maintenance.view', icon: Icon.Clock },
    ],
  },
  {
    id: 'fleet-intelligence',
    name: 'Fleet Intelligence',
    icon: Icon.Spark,
    tone: 'violet',
    tagline: 'Operational analytics — cost, prediction and utilization',
    sections: [
      { name: 'Cost Intelligence', route: '/cost-intelligence', permission: 'insights.view', flag: 'intel', icon: Icon.Chart },
      { name: 'Predictive Maintenance', route: '/maintenance-foresight', permission: 'maintenance.view', icon: Icon.Spark },
      { name: 'Fleet Analytics', route: '/fleet-utilization', permission: 'insights.view', icon: Icon.Gauge },
      { name: 'Health Scores', route: null, permission: 'insights.view', icon: Icon.Scale, status: 'soon' },
    ],
  },
  {
    id: 'finance',
    name: 'Finance',
    icon: Icon.Cash,
    tone: 'emerald',
    tagline: 'Profitability, reconciliation and financial integrity',
    sections: [
      { name: 'Profitability', route: '/profitability', permission: 'insights.view', flag: 'financial', icon: Icon.TrendUp },
      { name: 'Financial Conflicts', route: '/financial-conflicts', permission: 'insights.view', flag: 'financial', icon: Icon.Alert },
      { name: 'Reconciliation', route: '/financial-reconciliation', permission: 'insights.view', flag: 'financial', icon: Icon.Scale },
      { name: 'Accounting Data', route: null, permission: 'insights.view', icon: Icon.Invoice, status: 'soon' },
      { name: 'Reports', route: '/apps/reports', permission: 'insights.view', icon: Icon.Chart },
    ],
  },
  {
    id: 'reports',
    name: 'Reports',
    icon: Icon.Chart,
    tone: 'blue',
    tagline: 'Operational reports, audit trails and data quality',
    sections: [
      { name: 'Maintenance History', route: '/maintenance-history', permission: 'maintenance.view', icon: Icon.Clock },
      { name: 'Oversight', route: '/oversight', permission: 'insights.view', icon: Icon.Shield },
      { name: 'Data Health', route: '/data-health', permission: 'insights.view', icon: Icon.Activity },
      { name: 'Mileage & Fuel', route: '/mileage', permission: 'insights.view', icon: Icon.Gauge },
      { name: 'Sync Audit', route: '/sync-audit', permission: 'sync.run', icon: Icon.Refresh },
    ],
  },
  {
    id: 'administration',
    name: 'Administration',
    icon: Icon.Shield,
    tone: 'slate',
    tagline: 'People, access and system configuration',
    sections: [
      { name: 'Users', route: '/users', permission: 'users.manage', icon: Icon.Users },
      { name: 'Settings', route: '/settings', permission: null, icon: Icon.Filter },
      { name: 'Notifications', route: '/notifications', permission: null, icon: Icon.Alert },
      { name: 'Roles & Permissions', route: null, permission: 'users.manage', icon: Icon.Shield, status: 'soon' },
      { name: 'Audit Logs', route: null, permission: 'users.manage', icon: Icon.Route, status: 'soon' },
    ],
  },
];

export const OVERVIEW_ROUTE = (id) => `/apps/${id}`;

export const getModule = (id) => MODULES.find((m) => m.id === id) || null;

// Can THIS user see a given section? Mirrors the sidebar/route guards exactly:
// permission + feature-flag + role path-block. Soon tabs still honour permission
// (so the roadmap is audience-appropriate) but have no route to block.
export function sectionReachable(section, can, roles = []) {
  if (!isFeatureEnabled(section.flag)) return false;
  if (!can(section.permission)) return false;
  if (section.route && !section.route.startsWith('/apps/') && pathBlockedForRoles(section.route, roles)) return false;
  return true;
}

// The tabs a user actually sees for a module (Overview handled separately).
export const visibleSections = (module, can, roles) =>
  module.sections.filter((s) => sectionReachable(s, can, roles));

// A module appears on the launcher only if the user can reach ≥1 real (non-soon) section.
export const isModuleVisible = (module, can, roles) =>
  module.sections.some((s) => s.status !== 'soon' && s.route && sectionReachable(s, can, roles));

export const visibleModules = (can, roles) =>
  MODULES.filter((m) => isModuleVisible(m, can, roles));

// The module that owns a path — its overview route, or the section route it
// matches (exact or as a prefix, so /vehicles/123 and /contracts/new resolve).
// Longest section-route match wins. Powers the persistent module tab bar.
export function moduleForPath(pathname) {
  if (!pathname) return null;
  const overview = MODULES.find((m) => pathname === OVERVIEW_ROUTE(m.id));
  if (overview) return overview;
  let best = null;
  let bestLen = -1;
  for (const m of MODULES) {
    for (const s of m.sections) {
      if (!s.route || s.route.startsWith('/apps/')) continue;
      const hit = pathname === s.route || pathname.startsWith(s.route + '/');
      if (hit && s.route.length > bestLen) {
        best = m;
        bestLen = s.route.length;
      }
    }
  }
  return best;
}
