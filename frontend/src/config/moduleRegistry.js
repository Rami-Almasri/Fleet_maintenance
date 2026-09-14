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
      // The Daily Report moved to the Reports module — it is a report, it already lives under
      // /reports/, and every other one was there. It keeps maintenance.view, so the same people
      // reach it; only the tile moved.
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
      { name: 'My Queue', route: '/my-maintenance-queue', permission: 'maintenance.view', icon: Icon.Check, desc: 'Your role-scoped maintenance work in one place — what needs you, right now.' },
      // The Controllers' five jobs behind one sidebar: vet requests in, chase mileage on cars still
      // out, match the bills on cars that came back, and curate the fault vocabulary — both what a
      // fault is called and where on the car it can be. The five routes it absorbed redirect into
      // their section.
      { name: 'Control Desk', route: '/control-desk', permissionAny: ['maintenance.manage', 'reminders.view', 'maintenance.view'], icon: Icon.Check, desc: 'The Controllers’ day: vet requests to send a car in, chase the oil mileage on cars still out on rental, match each garage’s bill to the work it covers, and keep the fault vocabulary graded — what a fault is called, and where on the car it can be.' },
      { name: 'What people report', route: '/field-reports', permission: 'maintenance.view', icon: Icon.Flag, desc: 'Customer complaints with their follow-up timeline, and the handover notes drivers leave — the two ways a problem reaches us from outside the workshop.' },
      // Repair Records retired — the signed-off ledger and each car's workshop history are tabs of
      // Vehicles (Fleet Operations) now, because the finished work is a fact about the CARS. Not
      // re-listed here as a shortcut: a module section is deny-checked on its own route, and
      // '/vehicles' carries none of the rules that hid those two ledgers from the driver and the
      // inspector — a tile pointing at it would show them a link they must not have.
      // Each of these is one page of tabs now; the routes they absorbed redirect into their tab.
      { name: 'Parts', route: '/parts', permission: 'parts.view', icon: Icon.Coins, desc: 'Request, approve, buy and install parts — plus the supplier invoices behind what each part cost, the catalog of part names the app selects from, and the warranty that came with each part.' },
      { name: 'Suppliers', route: '/suppliers', permission: 'maintenance.view', icon: Icon.Cash, desc: 'What we owe suppliers and garages aged by how long it has been outstanding, every payment that has left the account, and the supplier register itself.' },
      { name: 'Garages', route: '/garages', permission: 'maintenance.view', icon: Icon.Wrench, desc: 'Which garage is good at which repair, which one to send this car to, and which cars are standing in a workshop right now.' },
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
      // One destination for the cars: the fleet register, the two analytics boards, the two repair
      // ledgers the retired Repair Records page held, and the imported damage log. Registry is
      // vehicles.view, the ledgers maintenance.view, the boards insights.view — so permissionAny,
      // and the hub filters tab by tab against the routes they replaced.
      { name: 'Vehicles', route: '/vehicles', permissionAny: ['vehicles.view', 'maintenance.view', 'insights.view'], icon: Icon.Car, desc: 'Every car in the fleet — open a row for its full profile, history and documents — plus what each car costs to run per kilometre, day and rental, how its owned time splits into rented, in-maintenance and idle days, the signed-off repair ledger, each car’s workshop history trip by trip, and the imported damage and accident log coloured by liable party and insurance.' },
      { name: 'Contracts', route: '/contracts', permission: 'contracts.view', icon: Icon.Invoice, desc: 'Rental contracts synced from OfficeManager — all open, plus recently closed.' },
      { name: 'Customers', route: '/customers', permission: 'customers.view', icon: Icon.Users, desc: 'Customer contacts, contracts and available wallet (carried-forward credit).' },
      { name: 'Drivers', route: '/drivers', permission: 'drivers.view', icon: Icon.Users, desc: 'Fleet drivers with licence number, expiry and status — expiring licences flagged.' },
      { name: 'Driver Dispatch', route: '/driver-dispatch', permission: 'logistics.view', icon: Icon.Truck, desc: 'Send a vehicle between locations and track which driver holds it and where.' },
      { name: 'Fleet Health', route: '/inspections/schedules', permission: 'inspections.view', icon: Icon.Shield, desc: 'Service-due and registration/insurance expiry surfaces, consolidated in one hub.' },
      { name: 'Maintenance Swap', route: '/maintenance-swap', permission: 'insights.view', icon: Icon.Refresh, desc: 'Keep a customer on the road — assign a replacement car while theirs is repaired.' },
      { name: 'Odometer Approvals', route: '/odometer-approvals', permission: 'vehicles.approve_odometer', icon: Icon.Gauge, desc: 'Review queue for significant manual odometer edits — approve or reject each.' },
      { name: 'Reports', route: '/apps/reports', permission: 'insights.view', icon: Icon.Chart, desc: 'Operational reports, audit trails and data-quality surfaces in one place.' },
    ],
  },
  // Fleet Intelligence is gone from the launcher too. Two of its three sections were
  // unbuilt "soon" tiles, so the module was really a wrapper around Recurring Faults —
  // and that is a management-ruling review, which is what Reports is for. It moved
  // there. Vehicle Locations had already left for the Control Desk (Maintenance).
  // Finance is gone from the launcher: its one live section was a second door onto
  // /apps/reports, which the Reports module below already owns, and the only other
  // entry was an unbuilt "soon" tile. A card advertising one borrowed section is
  // noise. Bring it back when accounting data actually lands.
  {
    id: 'reports',
    name: 'Reports',
    icon: Icon.Chart,
    tone: 'blue',
    tagline: 'Operational reports, audit trails and data quality',
    sections: [
      // Leads the module: it is the one report somebody opens every morning, and the only one here
      // that is operational rather than an audit surface. It carries maintenance.view rather than
      // the insights.view the rest share, so a dispatcher who holds neither the oversight nor the
      // data-quality boards now sees a Reports module containing exactly this — which is right, it
      // was always their report; it just used to be filed under Maintenance.
      { name: 'Daily Report', route: '/reports/daily-maintenance', permission: 'maintenance.view', icon: Icon.Activity, desc: "The morning report — every car the day touched plus every car still out, with severity, garage, days and the work recorded. Prints straight to PDF." },
      // The five accountability reports are tabs on one page now; each old URL redirects into its tab.
      { name: 'Workflow Oversight', route: '/oversight', permission: 'insights.view', icon: Icon.Flag, desc: 'Did the process hold? Five checks in one page: cars that left the garage with the contract still open, the severity QC gate, faults later marked incorrect, transfers with every fault fixed, and supervisors who never answered the "due back" reminder.' },
      // Arrived from the retired Fleet Intelligence module. It sits beside Workflow Oversight
      // because it asks the same kind of question — did the repair actually hold? — and ends
      // the same way, in a ruling rather than a job.
      { name: 'Recurring Faults', route: '/recurring-fault-reviews', permission: 'maintenance.recurring.view', icon: Icon.Refresh, desc: 'Cars back with the same confirmed fault after a repair — for a management ruling.' },
      { name: 'Data Health', route: '/data-health', permission: 'insights.view', icon: Icon.Activity, desc: 'Overall data quality — incomplete records, status mismatches, and the history of every sync run.' },
      { name: 'Mileage & Fuel', route: '/mileage', permission: 'insights.view', icon: Icon.Gauge, desc: 'Every odometer and fuel tool — travel vs. contract km, leakage, chain audit and the readings that do not line up.' },
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
  // A hub whose own sections carry different permissions (the Control Desk: maintenance.manage for
  // the review gate, reminders.view for the oil chase, maintenance.view for the rest) declares
  // `permissionAny` instead of one `permission` — holding ANY of them opens the page, and the hub
  // then filters its own sidebar. Gating such a tile on a single permission would hide it from
  // someone who can legitimately work in one of its sections.
  const allowed = section.permissionAny
    ? section.permissionAny.some((p) => can(p))
    : can(section.permission);
  if (!allowed) return false;
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
