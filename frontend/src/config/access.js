// Role-based page blocks — a deny layer on TOP of the permission gates.
//
// Some roles legitimately hold a broad `*.view` permission (e.g. the driver /
// `logistics` role has dashboard.view, vendors.view and maintenance.view so it
// can reach its own boards), but must NOT see certain management / oversight
// surfaces that happen to share those same permissions. Rather than shredding
// the permission model, we blocklist specific paths per role here.
//
// This is consumed in TWO places, which MUST stay in sync:
//   • AppLayout nav filter  — hides the sidebar/search entries.
//   • RequirePermission     — blocks the route itself (direct-URL attempts).

// Roles that bypass every path block (they can see everything).
const BYPASS_ROLES = ['admin', 'super-admin'];

// role slug → paths that role must never see.
export const ROLE_BLOCKED_PATHS = {
  // The field driver. Keeps its own boards (Driver Dispatch, My Queue, Workflow),
  // but is walled off from the fleet dashboards, analytics and maintenance admin.
  logistics: [
    '/',                             // Dashboard
    '/ops-dashboard',                // Delivery Command
    '/orders-board',                 // Orders Board
    '/overdue-rentals',              // Overdue Rentals
    '/analytics',                    // Analytics
    '/vendors',                      // Vendors
    '/garages',                      // Garages
    '/finding-keywords',             // Keyword Risk
    '/invoices/pending-submission',  // Pending Invoices
    '/maintenance-foresight',        // Foresight
    '/damage-accidents',             // Damage & Accidents
    '/maintenance-recommendations',  // Recommendations
  ],
  // The Supervisor / Coordinator (dispatcher). Works the maintenance + logistics
  // boards; walled off from the fleet-wide command dashboards.
  supervisor: [
    '/',                   // Dashboard
    '/analytics',          // Analytics
  ],
};

// True if `path` is blocked for a user holding `roles`. Admins bypass all blocks.
export function pathBlockedForRoles(path, roles = []) {
  if (!path) return false;
  if (roles.some((r) => BYPASS_ROLES.includes(r))) return false;
  return roles.some((r) => (ROLE_BLOCKED_PATHS[r] || []).includes(path));
}

// Where to send a user after login / when their default home is blocked. The
// driver can't see the Dashboard, so land them on their own dispatch board.
export function homePathForRoles(roles = []) {
  if (roles.some((r) => BYPASS_ROLES.includes(r))) return '/';
  if (pathBlockedForRoles('/', roles)) {
    if (roles.includes('logistics')) return '/driver-dispatch';
    if (roles.includes('supervisor')) return '/my-maintenance-queue';
  }
  return '/';
}
