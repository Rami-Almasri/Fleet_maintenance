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
import { isFeatureEnabled, DEMO_MODE } from './features';
import { pathBlockedForRoles } from './access';

export const MODULES = [
  {
    // Direct-link tile: opens the classic fleet Dashboard straight away instead of
    // an Overview page. `link` + `permission` mark it as a single-page shortcut
    // (no sections, no mega-menu, no module tab bar) — see the helpers below and
    // ModuleCard.
    id: 'dashboard',
    name: 'Dashboard',
    icon: Icon.Gauge,
    tone: 'indigo',
    tagline: 'Fleet-wide overview — availability, composition and key totals',
    link: '/dashboard',
    permission: 'dashboard.view',
    sections: [],
  },
  {
    id: 'maintenance',
    name: 'Maintenance',
    icon: Icon.Wrench,
    tone: 'amber',
    tagline: 'The repair pipeline, queues, parts, suppliers and history',
    sections: [
      { name: 'Car Status', route: '/car-status', permission: 'maintenance.view', icon: Icon.Wrench, desc: 'Live stage board — every car by the exact workflow stage it sits in and who is responsible right now.' },
      {
        name: 'Maintenance Cycle',
        route: '/maintenance-workflow',
        permission: 'maintenance.view',
        icon: Icon.Activity,
        desc: 'The repair ticket pipeline — advance each ticket from test-drive request through to final QA.',
        // Odoo-style click dropdown — jump straight to a single stage of the repair pipeline.
        // `key` is the board column key (used to show live ticket counts, fetched from countsUrl);
        // keys + tones mirror PRIMARY_LANES in pages/MaintenanceWorkflow.js; the board reads ?stage=.
        countsUrl: '/maintenance-tickets/board',
        menu: [
          { name: 'All Stages',        key: '__all',            route: '/maintenance-workflow',                       tone: '#6366f1' },
          { name: 'Needs Test Drive',  key: 'requested',        route: '/maintenance-workflow?stage=requested',       tone: '#d946ef' },
          { name: 'Being Inspected',   key: 'diagnostic',       route: '/maintenance-workflow?stage=diagnostic',      tone: '#8b5cf6' },
          { name: 'Needs Dispatch',    key: 'pending',          route: '/maintenance-workflow?stage=pending',         tone: '#a855f7' },
          { name: 'Awaiting Pickup',   key: 'awaiting_pickup',  route: '/maintenance-workflow?stage=awaiting_pickup', tone: '#f59e0b' },
          { name: 'En Route to Garage',key: 'in_transit',       route: '/maintenance-workflow?stage=in_transit',      tone: '#f59e0b' },
          { name: 'In Workshop',       key: 'under_repair',     route: '/maintenance-workflow?stage=under_repair',    tone: '#f97316' },
          { name: 'Ready for Pickup',  key: 'ready_for_pickup', route: '/maintenance-workflow?stage=ready_for_pickup',tone: '#10b981' },
          { name: 'Final QA',          key: 'qa_reinspection',  route: '/maintenance-workflow?stage=qa_reinspection', tone: '#9333ea' },
          { heading: 'Exceptions' },
          { name: 'Sent Back — QA Failed', key: 'reinspection_failed',     route: '/maintenance-workflow?stage=reinspection_failed',     tone: '#dc2626' },
          { name: 'Paused',                key: 'paused',                  route: '/maintenance-workflow?stage=paused',                   tone: '#64748b' },
          { name: 'Returned — Resume Due', key: 'returned_waiting_resume', route: '/maintenance-workflow?stage=returned_waiting_resume', tone: '#f97316' },
          { name: 'On-Site Service',       key: 'on_site',                 route: '/maintenance-workflow?stage=on_site',                 tone: '#0d9488' },
        ],
      },
      { name: 'Maintenance Progress', route: '/maintenance-progress', permission: 'maintenance.view', icon: Icon.Activity, desc: 'Track in-shop progress against each car’s promised completion date, with escalating reminders.' },
      { name: 'My Queue', route: '/my-maintenance-queue', permission: 'maintenance.view', icon: Icon.Check, desc: 'Your role-scoped maintenance work in one place — what needs you, right now.' },
      { name: 'Inspection Review', route: '/inspection-review', permission: 'maintenance.manage', icon: Icon.Check, desc: 'Controllers vet inspection requests — approve to send on, or reject with a reason.' },
      { name: 'Complaints', route: '/complaints', permission: 'maintenance.view', icon: Icon.Flag, desc: 'Every customer complaint and its follow-up timeline, in one management view.' },
      { name: 'Driver Observations', route: '/driver-observations', permission: 'maintenance.view', icon: Icon.Search, desc: 'Internal handover notes from drivers — escalate to an inspection only when needed.' },
      { name: 'Completed Repairs', route: '/completed-repairs', permission: 'maintenance.view', icon: Icon.Check, desc: 'The signed-off ledger — who, where, what was found and fixed, and what it cost.' },
      { name: 'Oil Mileage Follow-up', route: '/oil-projection', permission: 'reminders.view', icon: Icon.Clock, desc: 'Cars out on rental heading for their oil limit — call the customer, enter the mileage they report, and the next check recalculates from it.' },
      { name: 'Parts', route: '/parts', permission: 'parts.view', icon: Icon.Coins, desc: 'Request, approve, buy and install parts — with duplicate-spend and recurrence detection.' },
      { name: 'Procurement', route: '/procurement', permission: 'maintenance.view', icon: Icon.Cash, desc: 'What we owe suppliers and garages aged by how long it has been outstanding, who we buy from and how well they perform, and every payment that has left the account.' },
      { name: 'Part Invoices', route: '/part-invoices', permission: 'parts.view', icon: Icon.Invoice, desc: "What a supplier charged for a part, with the invoice number, date and a photo of the paper. Garage-supplied parts stay on the garage's own invoice so nothing is counted twice." },
      { name: 'Parts Catalog', route: '/parts-catalog', permission: 'parts.view', icon: Icon.Coins, desc: 'The part names the app selects from, in English and Arabic, with warranty defaults.' },
      { name: 'Warranties', route: '/warranties', permission: 'parts.view', icon: Icon.Shield, desc: 'Supplier and garage promises, and whether each still holds — on months or kilometres, whichever ends first.' },
      { name: 'Garages', route: '/garages', permission: 'maintenance.view', icon: Icon.Wrench, desc: 'Which garage is good at which repair, where each one has a problem, and what is in every workshop now.' },
      { name: 'History', route: '/maintenance-history', permission: 'maintenance.view', icon: Icon.Clock, desc: 'Every workshop visit per car — how often and how long, trip by trip.' },
      // Coming Soon tabs sit last so the 12 live sections lead.
      { name: 'Component Intelligence', route: null, permission: 'components.view', icon: Icon.Shield, status: 'soon', desc: 'Every part installed across the fleet — warranty exposure, expected service life, replacement churn and total installed value. Built from the workflow; nothing is entered by hand — coming soon.' },
    ],
  },
  {
    id: 'fleet-operations',
    name: 'Fleet Operations',
    icon: Icon.Car,
    tone: 'indigo',
    tagline: 'Vehicles, rentals and the daily movement of the fleet',
    sections: [
      { name: 'Vehicles', route: '/vehicles', permission: 'vehicles.view', icon: Icon.Car, desc: 'Every car in the fleet — open a row for its full profile, history and documents.' },
      { name: 'Contracts', route: '/contracts', permission: 'contracts.view', icon: Icon.Invoice, desc: 'Rental contracts synced from OfficeManager — all open, plus recently closed.' },
      { name: 'Customers', route: '/customers', permission: 'customers.view', icon: Icon.Users, desc: 'Customer contacts, contracts and available wallet (carried-forward credit).' },
      { name: 'Drivers', route: '/drivers', permission: 'drivers.view', icon: Icon.Users, desc: 'Fleet drivers with licence number, expiry and status — expiring licences flagged.' },
      { name: 'Driver Dispatch', route: '/driver-dispatch', permission: 'logistics.view', icon: Icon.Truck, desc: 'Send a vehicle between locations and track which driver holds it and where.' },
      { name: 'Fleet Health', route: '/inspections/schedules', permission: 'inspections.view', icon: Icon.Shield, desc: 'Service-due and registration/insurance expiry surfaces, consolidated in one hub.' },
      { name: 'Damage & Accidents', route: '/damage-accidents', permission: 'maintenance.view', icon: Icon.Alert, desc: 'Damage and accident records per vehicle, coloured by liable party and insurance.' },
      { name: 'Maintenance Swap', route: '/maintenance-swap', permission: 'insights.view', icon: Icon.Refresh, desc: 'Keep a customer on the road — assign a replacement car while theirs is repaired.' },
      { name: 'Vendors', route: '/vendors', permission: 'vendors.view', icon: Icon.Truck, desc: 'Suppliers and service vendors referenced by maintenance and contracts.' },
      { name: 'Odometer Approvals', route: '/odometer-approvals', permission: 'vehicles.approve_odometer', icon: Icon.Gauge, desc: 'Review queue for significant manual odometer edits — approve or reject each.' },
      { name: 'Reports', route: '/apps/reports', permission: 'insights.view', icon: Icon.Chart, desc: 'Operational reports, audit trails and data-quality surfaces in one place.' },
    ],
  },
  {
    id: 'fleet-intelligence',
    name: 'Fleet Intelligence',
    icon: Icon.Spark,
    tone: 'violet',
    tagline: 'Operational analytics — cost, prediction, faults and utilization',
    sections: [
      { name: 'Fleet Analytics', route: '/fleet-utilization', permission: 'insights.view', icon: Icon.Gauge, desc: 'Per-car split of owned time into rented, in-maintenance and idle days.' },
      { name: 'Keyword Risk', route: '/finding-keywords', permission: 'maintenance.view', icon: Icon.Flag, desc: 'The fault-keyword library, each graded critical, moderate or routine.' },
      { name: 'Recurring Faults', route: '/recurring-fault-reviews', permission: 'maintenance.recurring.view', icon: Icon.Refresh, desc: 'Cars back with the same confirmed fault after a repair — for a management ruling.' },
      { name: 'Maintenance Analytics', route: null, permission: 'maintenance.view', icon: Icon.Chart, status: 'soon', desc: 'Deeper trends across repairs, cost and turnaround — coming soon.' },
      { name: 'Health Scores', route: null, permission: 'insights.view', icon: Icon.Scale, status: 'soon', desc: 'A single per-car condition score rolled up from every signal — coming soon.' },
    ],
  },
  {
    id: 'finance',
    name: 'Finance',
    icon: Icon.Cash,
    tone: 'emerald',
    tagline: 'Running cost, accounting data and reports',
    sections: [
      { name: 'Cost Intelligence', route: '/cost-intelligence', permission: 'insights.view', flag: 'intel', icon: Icon.Chart, desc: 'The true running cost of each asset — per kilometre, per day and per rental.' },
      { name: 'Reports', route: '/apps/reports', permission: 'insights.view', icon: Icon.Chart, desc: 'Operational reports, audit trails and data-quality surfaces in one place.' },
      { name: 'Accounting Data', route: null, permission: 'insights.view', icon: Icon.Invoice, status: 'soon', desc: 'Direct feed from the accounting system — coming soon.' },
    ],
  },
  {
    id: 'reports',
    name: 'Reports',
    icon: Icon.Chart,
    tone: 'blue',
    tagline: 'Operational reports, audit trails and data quality',
    sections: [
      { name: 'Mileage Discrepancies', route: '/oversight/mileage', permission: 'insights.view', icon: Icon.Gauge, desc: 'Odometer readings that don’t line up across contracts — flagged for review.' },
      { name: 'Left-the-Garage Invoices', route: '/oversight/left-garage', permission: 'insights.view', icon: Icon.Truck, desc: 'Cars that left the garage while their maintenance contract stayed open.' },
      { name: 'Diagnostic Review', route: '/oversight/severity', permission: 'insights.view', icon: Icon.Flag, desc: 'QC gate for fault severity — keep the call or upgrade it, with the reasoning shown.' },
      { name: 'Mis-Diagnosis', route: '/oversight/misdiagnoses', permission: 'insights.view', icon: Icon.XCircle, desc: 'Faults later marked incorrect — the audited mis-diagnosis trail.' },
      { name: 'Transferred — Faults Fixed', route: '/oversight/resolved-transfers', permission: 'insights.view', icon: Icon.ArrowRight, desc: 'Cars moved on with all faults fixed — each transfer noted and logged.' },
      { name: 'Checkpoint Compliance', route: '/oversight/checkpoint-compliance', permission: 'insights.view', icon: Icon.Clock, desc: 'Supervisors reminded a car is due back who never confirmed a date or gave a reason.' },
      { name: 'Data Health', route: '/data-health', permission: 'insights.view', icon: Icon.Activity, desc: 'Overall data quality — incomplete records and status mismatches.' },
      { name: 'Intelligence Center', route: '/intelligence-center', permission: 'insights.view', icon: Icon.Activity, desc: 'What the platform knows and how sure it is — evidence readiness, QC throughput, promotion decisions and what is blocking each capability.' },
      { name: 'Mileage & Fuel', route: '/mileage', permission: 'insights.view', icon: Icon.Gauge, desc: 'Every odometer and fuel tool — travel vs. contract km, leakage and chain audit.' },
    ],
  },
  {
    id: 'administration',
    name: 'Administration',
    icon: Icon.Shield,
    tone: 'slate',
    tagline: 'People, access and system configuration',
    sections: [
      { name: 'Users', route: '/users', permission: 'users.manage', icon: Icon.Users, desc: 'Every account, its status and role(s) — the live workforce dashboard.' },
      { name: 'Sync Audit', route: '/sync-audit', permission: 'sync.run', icon: Icon.Refresh, desc: 'History of each sync run — what it scanned, updated and auto-corrected.' },
      { name: 'Settings', route: '/settings', permission: null, icon: Icon.Filter, desc: 'Your account and preferences — appearance, language, roles and shortcuts.' },
      { name: 'Notifications', route: '/notifications', permission: null, icon: Icon.Alert, desc: 'Live fleet alerts, organised into role-aware action lanes.' },
      { name: 'Simulation', route: '/simulation', permission: 'users.manage', demoOnly: true, icon: Icon.Spark, desc: 'Admin demo console — trigger a real alert end-to-end, then roll it back.' },
      { name: 'Roles & Permissions', route: null, permission: 'users.manage', icon: Icon.Shield, status: 'soon', desc: 'Manage roles and per-permission grants from one screen — coming soon.' },
      { name: 'Audit Logs', route: null, permission: 'users.manage', icon: Icon.Route, status: 'soon', desc: 'A searchable trail of every system action — coming soon.' },
    ],
  },
];

export const OVERVIEW_ROUTE = (id) => `/apps/${id}`;

// ── Localization ────────────────────────────────────────────────────────────
// The `name`/`tagline`/`desc` above are the ENGLISH source (see the EXCEPTION
// note in i18n/labels.js). Arabic lives under `ar.modules.*` and is resolved by
// the consuming components with tf(key, englishFromHere), so English never
// depends on the catalog and Arabic layers on top.
//
//   modules.<moduleId>.name | .tagline
//   modules.<moduleId>.sections.<sectionSlug>.name | .desc
//   modules.<moduleId>.menu.<menuSlug>
//
// Section/menu slugs come from the English name, so renaming one means renaming
// its key in labels.js too — scripts/check-i18n reports any mismatch.
export const slugifyLabel = (name) =>
  (name || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

export const moduleNameKey = (m) => `modules.${m.id}.name`;
export const moduleTaglineKey = (m) => `modules.${m.id}.tagline`;
export const sectionNameKey = (m, s) => `modules.${m.id}.sections.${slugifyLabel(s.name)}.name`;
export const sectionDescKey = (m, s) => `modules.${m.id}.sections.${slugifyLabel(s.name)}.desc`;
export const menuLabelKey = (m, entry) =>
  `modules.${m.id}.menu.${slugifyLabel(entry.name || entry.heading)}`;

export const getModule = (id) => MODULES.find((m) => m.id === id) || null;

// Can THIS user see a given section? Mirrors the sidebar/route guards exactly:
// permission + feature-flag + role path-block. Soon tabs still honour permission
// (so the roadmap is audience-appropriate) but have no route to block.
export function sectionReachable(section, can, roles = []) {
  if (section.demoOnly && !DEMO_MODE) return false;
  if (!isFeatureEnabled(section.flag)) return false;
  if (!can(section.permission)) return false;
  if (section.route && !section.route.startsWith('/apps/') && pathBlockedForRoles(section.route, roles)) return false;
  return true;
}

// The tabs a user actually sees for a module (Overview handled separately).
export const visibleSections = (module, can, roles) =>
  module.sections.filter((s) => sectionReachable(s, can, roles));

// A module appears on the launcher only if the user can reach ≥1 real (non-soon)
// section. A direct-link tile (e.g. Dashboard) is instead gated by its own
// `permission` field, since it has no sections.
export const isModuleVisible = (module, can, roles) =>
  module.link
    ? can(module.permission)
    : module.sections.some((s) => s.status !== 'soon' && s.route && sectionReachable(s, can, roles));

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
