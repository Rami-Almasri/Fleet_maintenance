// Role-based page blocks — a deny layer on TOP of the permission gates.
//
// Some roles legitimately hold a broad `*.view` permission (e.g. the driver /
// `logistics` role has dashboard.view, vendors.view and maintenance.view so it
// can reach its own boards), but must NOT see certain management / oversight
// surfaces that happen to share those same permissions. Rather than shredding
// the permission model, we blocklist specific paths per role here.
//
// The rule of thumb: a role sees (a) the boards it works in every day, plus
// (b) the read-only pages it genuinely needs to do that work — and nothing
// else. Money, customers and fleet-wide dashboards are the two things most
// operational roles do NOT need.
//
// This is consumed in THREE places, which MUST stay in sync:
//   • AppLayout nav filter  — hides the sidebar/search entries.
//   • moduleRegistry        — hides the Workspace launcher tiles.
//   • RequirePermission     — blocks the route itself (direct-URL attempts).
//
// NOTE: this is a UI-side deny layer. The API still answers for any permission
// the role holds; tightening the backend grants is a separate, riskier step.

// Roles that bypass every path block (they can see everything).
const BYPASS_ROLES = ['admin', 'super-admin'];

// Paths nobody is ever blocked from — every signed-in user needs these.
const ALWAYS_ALLOWED = ['/notifications', '/settings'];

// role slug → paths that role must never see.
// Blocking a path also blocks everything under it (e.g. '/vehicles' also hides
// '/vehicles/123'), so parent paths are enough.
export const ROLE_BLOCKED_PATHS = {
  // ── The field driver. He drives the car and nothing else. Keeps Driver
  // Dispatch, My Queue, the ticket screen (to stamp Picked Up / Delivered /
  // Returned) and the vehicle list so he can look a plate up.
  logistics: [
    '/',                        // Workspace landing (Dashboard-gated)
    '/dashboard',               // Classic fleet Dashboard
    '/car-status',
    '/inspection-review',
    '/inspections/schedules',
    '/service-reminders',
    '/completed-repairs',
    '/maintenance-history',
    '/maintenance-swap',
    '/finding-keywords',
    '/recurring-fault-reviews',
    '/parts',
    '/garages',
    '/vendors',
    '/damage-accidents',
    '/customers',
    '/contracts',
    '/drivers',
    '/odometer-approvals',
    '/cost-intelligence',
    '/fleet-utilization',
    '/mileage',
    '/data-health',
    '/sync-audit',
    '/simulation',
    '/users',
  ],

  // ── Supervisor / Coordinator (Waleed, Abdullah): the DISPATCHER. Picks the
  // garage, assigns the driver, chases the car. Needs the workshop-side
  // reference pages (garages, parts, history) — not the commercial side.
  supervisor: [
    '/',                        // Workspace landing (Dashboard-gated)
    '/dashboard',               // Classic fleet Dashboard
    '/maintenance-swap',        // rental replacement — an Operations decision
    '/damage-accidents',
    '/customers',
    '/contracts',
    '/odometer-approvals',
    '/cost-intelligence',
    '/fleet-utilization',
    '/mileage',
    '/data-health',
    '/sync-audit',
    '/simulation',
    '/users',
  ],

  // ── Inspector (Abu Maroof): finds the fault, files the report, re-inspects
  // on return. Lives in My Queue. Keeps the diagnostic + history surfaces he
  // reads before judging a car; walled off from money, customers and dispatch.
  inspector: [
    '/',                        // Workspace landing (Dashboard-gated)
    '/dashboard',
    '/driver-dispatch',         // the supervisor assigns; the inspector doesn't
    '/maintenance-swap',
    '/damage-accidents',
    '/garages',
    '/vendors',
    '/customers',
    '/contracts',
    '/drivers',
    '/odometer-approvals',
    '/cost-intelligence',
    '/fleet-utilization',
    '/mileage',
    '/data-health',
    '/sync-audit',
    '/simulation',
    '/users',
  ],

  // ── Controller (Lin, Marwa) — the `maintenance` role in practice. The
  // control desk, NOT a workshop manager: they are the front door (raise an
  // inspection / test / breakdown request, log a customer complaint), they own
  // the approval gate before a request reaches Abu Maroof, and they pause or
  // resume a mid-repair car for a customer. Their notification lanes are just
  // Test Approvals + Test Interrupted.
  //
  // So they keep the workflow surfaces they act on and the history they read
  // to decide — and lose fleet analytics, catalogue admin and everything
  // commercial. If a real workshop manager is ever hired, give them their own
  // role rather than widening this one back out.
  maintenance: [
    '/dashboard',               // fleet-wide KPIs — a manager surface
    '/my-maintenance-queue',    // inspector / supervisor / driver queue, not theirs
    '/finding-keywords',        // fault-vocabulary admin — config, not control
    '/recurring-fault-reviews', // the management decision on a repeat fault
    '/damage-accidents',        // insurance / Operations
    '/vendors',                 // suppliers — they route to garages, not vendors
    '/drivers',                 // driver admin — the supervisor assigns
    '/maintenance-swap',        // rental replacement — an Operations decision
    '/customers',
    '/contracts',
    '/mileage',
    '/odometer-approvals',
    '/cost-intelligence',
    '/fleet-utilization',
    '/data-health',
    '/sync-audit',
    '/simulation',
    '/users',
  ],

  // ── Operations desk: rentals, customers, cars in and out. Sees the
  // maintenance boards read-only (it must know when a car comes back) but is
  // not part of the repair chain and does not touch fault vocabulary or money.
  operations: [
    '/my-maintenance-queue',    // not in the maintenance chain
    '/finding-keywords',
    '/recurring-fault-reviews',
    '/odometer-approvals',
    '/cost-intelligence',
    '/data-health',
    '/sync-audit',
    '/simulation',
    '/users',
  ],

  // ── Finance / accounts: audits the money. Reads what a repair was for, but
  // never moves an operational ticket.
  finance: [
    '/maintenance-workflow',
    '/my-maintenance-queue',
    '/car-status',
    '/driver-dispatch',
    '/inspection-review',
    '/inspections/schedules',
    '/service-reminders',
    '/maintenance-swap',
    '/finding-keywords',
    '/recurring-fault-reviews',
    '/parts',
    '/garages',
    '/drivers',
    '/odometer-approvals',
    '/sync-audit',
    '/simulation',
    '/users',
  ],

  // 'manager' and 'viewer' are intentionally absent:
  //   manager — full operational control, needs the fleet-wide picture.
  //   viewer  — read-only across the board (this is the role for the boss).
};

// True if `path` is blocked for a user holding `roles`. Admins bypass all
// blocks. A blocked parent path also blocks its children ('/vehicles' hides
// '/vehicles/123'); '/' is matched exactly so it never swallows the whole app.
//
// Multi-role users get the UNION of what their roles can see: the path is only
// blocked when EVERY role the user holds blocks it. Adding a second role must
// widen access, never narrow it — a role with no entry here blocks nothing, so
// e.g. `manager` + `finance` still sees everything.
export function pathBlockedForRoles(path, roles = []) {
  if (!path) return false;
  if (ALWAYS_ALLOWED.includes(path)) return false;
  if (!roles.length) return false;
  if (roles.some((r) => BYPASS_ROLES.includes(r))) return false;
  return roles.every((r) =>
    (ROLE_BLOCKED_PATHS[r] || []).some(
      (blocked) => path === blocked || path.startsWith(`${blocked}/`),
    ),
  );
}

// Where to send a user after login / when their default home is blocked. The
// driver lands on his dispatch board; the inspector and supervisor on the
// role-scoped queue they work out of all day.
export function homePathForRoles(roles = []) {
  if (roles.some((r) => BYPASS_ROLES.includes(r))) return '/';
  if (pathBlockedForRoles('/', roles)) {
    if (roles.includes('logistics')) return '/driver-dispatch';
    if (roles.includes('supervisor')) return '/my-maintenance-queue';
    if (roles.includes('inspector')) return '/my-maintenance-queue';
  }
  return '/';
}
