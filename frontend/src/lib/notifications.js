// Shared theming + helpers for the notification centre (bell dropdown + full page).
// Keeping the maps here means the badge, dropdown and page all render identically.

// Severity → visual theme. Mirrors App\Notifications\FleetAlert::SEVERITIES on the backend.
export const SEVERITY = {
  critical: {
    label: 'Critical',
    rank: 0,
    dot: 'bg-red-500',
    ring: 'ring-red-500/30',
    chipBg: 'bg-red-50',
    chipText: 'text-red-700',
    iconBg: 'bg-gradient-to-br from-red-500 to-rose-600',
    accent: 'bg-red-500',
    glow: 'shadow-[0_0_0_3px_rgba(239,68,68,0.10)]',
    // card-level theming
    border: 'border-l-red-500',
    cardTint: 'bg-red-50/40',
    headTint: 'bg-gradient-to-br from-red-50 to-white',
    btn: 'bg-red-600 text-white shadow-sm shadow-red-600/20 hover:bg-red-700 focus-visible:outline-red-600',
  },
  warning: {
    label: 'High',
    rank: 1,
    dot: 'bg-amber-500',
    ring: 'ring-amber-500/30',
    chipBg: 'bg-amber-50',
    chipText: 'text-amber-700',
    iconBg: 'bg-gradient-to-br from-amber-500 to-orange-500',
    accent: 'bg-amber-500',
    glow: 'shadow-[0_0_0_3px_rgba(245,158,11,0.10)]',
    border: 'border-l-amber-500',
    cardTint: 'bg-amber-50/40',
    headTint: 'bg-gradient-to-br from-amber-50 to-white',
    btn: 'bg-amber-500 text-white shadow-sm shadow-amber-500/20 hover:bg-amber-600 focus-visible:outline-amber-500',
  },
  info: {
    label: 'Info',
    rank: 2,
    dot: 'bg-blue-500',
    ring: 'ring-blue-500/30',
    chipBg: 'bg-blue-50',
    chipText: 'text-blue-700',
    iconBg: 'bg-gradient-to-br from-blue-500 to-indigo-500',
    accent: 'bg-blue-500',
    glow: 'shadow-[0_0_0_3px_rgba(59,130,246,0.10)]',
    border: 'border-l-blue-500',
    cardTint: 'bg-blue-50/40',
    headTint: 'bg-gradient-to-br from-blue-50 to-white',
    btn: 'bg-blue-600 text-white shadow-sm shadow-blue-600/20 hover:bg-blue-700 focus-visible:outline-blue-600',
  },
  success: {
    label: 'Resolved',
    rank: 3,
    dot: 'bg-emerald-500',
    ring: 'ring-emerald-500/30',
    chipBg: 'bg-emerald-50',
    chipText: 'text-emerald-700',
    iconBg: 'bg-gradient-to-br from-emerald-500 to-teal-500',
    accent: 'bg-emerald-500',
    glow: 'shadow-[0_0_0_3px_rgba(16,185,129,0.10)]',
    border: 'border-l-emerald-500',
    cardTint: 'bg-emerald-50/40',
    headTint: 'bg-gradient-to-br from-emerald-50 to-white',
    btn: 'bg-emerald-600 text-white shadow-sm shadow-emerald-600/20 hover:bg-emerald-700 focus-visible:outline-emerald-600',
  },
};

export const severityRank = (s) => (SEVERITY[s] || SEVERITY.info).rank;

export const severityTheme = (s) => SEVERITY[s] || SEVERITY.info;

// Named icon → SVG path (stroke icons, viewBox 0 0 24 24). `icon` comes from the payload.
export const ICONS = {
  bell: 'M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 0 0-4-5.7V5a2 2 0 1 0-4 0v.3A6 6 0 0 0 6 11v3.2a2 2 0 0 1-.6 1.4L4 17h5m6 0v1a3 3 0 1 1-6 0v-1m6 0H9',
  clock: 'M12 8v4l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  wrench: 'M14.7 6.3a4 4 0 0 1-5 5L4 17l3 3 5.7-5.7a4 4 0 0 1 5-5l-2.4-2.4 2.2-2.2-1.6-1.6-2.2 2.2z',
  shield: 'M12 3l8 3v5c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6l8-3z',
  oil: 'M5 21h14M7 21V10l3-3h7l-2 6h-2l1-6M4 14h6m-6 3h6',
  check: 'M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  alert: 'M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
  'trend-down': 'M3 7l6 6 4-4 8 8m0 0h-6m6 0v-6',
  truck: 'M3 6a1 1 0 0 1 1-1h9a1 1 0 0 1 1 1v9H3zM14 9h3.5L21 12.5V15h-7zM7 18.5A1.5 1.5 0 1 0 7 15.5a1.5 1.5 0 0 0 0 3zM17.5 18.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z',
  'map-pin': 'M12 21s-6-5.3-6-10a6 6 0 1 1 12 0c0 4.7-6 10-6 10zm0-7.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
  calendar: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z',
  // Entity + extra glyphs — several backend detectors emit these icon names
  // (phone, package, dollar, clipboard) which previously fell back to the bell.
  phone: 'M2 4.5A2 2 0 0 1 4 2.5h2.2a1 1 0 0 1 1 .8l1 4a1 1 0 0 1-.27 1L6.6 9.8a13 13 0 0 0 6 6l1.4-1.3a1 1 0 0 1 1-.27l4 1a1 1 0 0 1 .8 1V19a2 2 0 0 1-2 2A17 17 0 0 1 2 4.5z',
  package: 'M21 8l-9-5-9 5m18 0l-9 5m9-5v8l-9 5m0-8L3 8m9 5v8M3 8v8l9 5',
  dollar: 'M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6',
  clipboard: 'M9 4h6a1 1 0 0 1 1 1v1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h1V5a1 1 0 0 1 1-1z',
  car: 'M3 13l2-5a2 2 0 0 1 1.9-1.3h10.2A2 2 0 0 1 19 8l2 5m-18 0h18m-18 0v4h2m14-4v4h-2M7.5 17a1 1 0 1 0 0 2 1 1 0 0 0 0-2zm9 0a1 1 0 1 0 0 2 1 1 0 0 0 0-2z',
  user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 20a8 8 0 0 1 16 0',
  doc: 'M8 3h6l4 4v12a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zM14 3v4h4M9 13h6M9 17h4',
  building: 'M4 21V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v16M14 9h4a2 2 0 0 1 2 2v10M3 21h18M8 7h2M8 11h2M8 15h2',
};

export const iconPath = (key) => ICONS[key] || ICONS.bell;

// Human category label for grouping/filter chips.
export const CATEGORY_LABEL = {
  operations: 'Operations',
  maintenance: 'Maintenance',
  finance: 'Finance',
  fleet: 'Fleet',
  data: 'Data',
  system: 'System',
};

// ── Smart grouping ───────────────────────────────────────────────────────────
// Notifications are bucketed into actionable domains so the page reads like a
// to-do list instead of one long feed. Each group owns a headline accent colour
// and the alert `type`s that belong in it. `tone` drives the section header only;
// individual cards are still coloured by their own severity (urgency hierarchy).
export const GROUPS = [
  {
    key: 'rent',
    label: 'Rent Delays',
    blurb: 'Open rentals past their return date',
    icon: 'clock',
    tone: 'critical',
    types: ['overdue_rental'],
  },
  {
    key: 'insurance',
    label: 'Insurance & Documents',
    blurb: 'Registration & insurance approaching expiry',
    icon: 'shield',
    tone: 'warning',
    types: ['document_expiry'],
  },
  {
    key: 'maintenance',
    label: 'Maintenance',
    blurb: 'Service-due cars, garage overruns & approvals',
    icon: 'wrench',
    tone: 'info',
    types: ['overdue_maintenance', 'maint_checkpoint', 'maint_invoice_missing', 'maintenance_back_open', 'service_inspection', 'approval_pending', 'maint_recurring_fault_review', 'garage_visit_frequency', 'garage_downtime', 'maint_finding_approval', 'maint_finding_approval_decided'],
  },
  {
    key: 'finance',
    label: 'Financial Alerts',
    blurb: 'Negative-yield cars & costly repairs',
    icon: 'trend-down',
    tone: 'warning',
    types: ['negative_yield', 'high_maintenance_cost'],
  },
  {
    // Catch-all for event-driven workflow / ad-hoc alerts so nothing is ever dropped.
    key: 'other',
    label: 'Other Alerts',
    blurb: 'Workflow hand-offs & system messages',
    icon: 'bell',
    tone: 'info',
    types: [],
  },
];

const TYPE_TO_GROUP = GROUPS.reduce((acc, g) => {
  g.types.forEach((t) => { acc[t] = g.key; });
  return acc;
}, {});

// Which group does a notification belong to? Falls back to the catch-all.
export const groupOf = (n) => TYPE_TO_GROUP[n?.type] || 'other';

// ── Tab categories ───────────────────────────────────────────────────────────
// The full page is organised as tabs so the operator can focus on one domain at
// a time. Each tab claims a set of alert `type`s; `system` is the catch-all so
// every notification is always reachable from some tab (and the sum equals All).
export const TABS = [
  {
    key: 'all',
    label: 'All',
    icon: 'bell',
    blurb: 'Every active fleet alert',
    empty: 'No notifications found',
    // `all` matches everything — no `types` list.
  },
  {
    key: 'maintenance',
    label: 'Maintenance',
    icon: 'wrench',
    blurb: 'Service-due cars, repairs, diagnostics & approvals',
    empty: 'No maintenance notifications found',
    types: ['overdue_maintenance', 'maint_checkpoint', 'maint_invoice_missing', 'maintenance_back_open', 'service_inspection', 'approval_pending', 'high_maintenance_cost', 'maint_recurring_fault_review', 'garage_visit_frequency', 'garage_downtime', 'maint_finding_approval', 'maint_finding_approval_decided'],
  },
  {
    key: 'incidents',
    label: 'Incidents',
    icon: 'shield',
    blurb: 'Insurance claims, accidents & expiring documents',
    empty: 'No incident notifications found',
    types: ['document_expiry', 'accident', 'damage', 'insurance_claim'],
  },
  {
    key: 'system',
    label: 'System / Alerts',
    icon: 'alert',
    blurb: 'Warnings, status changes & administrative alerts',
    empty: 'No system alerts found',
    // Catch-all: overdue rentals, negative yield, workflow hand-offs, ad-hoc messages.
    types: [],
  },
];

const TYPE_TO_TAB = TABS.reduce((acc, t) => {
  (t.types || []).forEach((type) => { acc[type] = t.key; });
  return acc;
}, {});

// Which tab does a notification belong to? Falls back to the `system` catch-all
// so nothing is ever hidden from the user.
export const tabOf = (n) => TYPE_TO_TAB[n?.type] || 'system';

// ── Inbox categories (fixed tabs) ────────────────────────────────────────────
// The focused, human-facing tab set the Action Center renders: Routine / Complaints
// / Test Drive. Each claims a set of granular alert `type`s. This MUST mirror the
// backend map App\Support\NotificationCategories::MAP — keep the two in lock-step
// when adding a type. Anything unmapped falls to the `other` catch-all so no
// notification is ever hidden.
export const INBOX_CATEGORIES = [
  {
    key: 'routine',
    label: 'Routine',
    icon: 'oil',
    blurb: 'Oil, battery, service & inspection reminders',
    empty: 'No routine notifications',
    types: ['service_inspection', 'service_reminder_due', 'contact_reminder_due', 'inspection_due', 'battery_overdue', 'service_due', 'service_due_soon', 'service_overdue'],
  },
  {
    key: 'complaints',
    label: 'Complaints',
    icon: 'alert',
    blurb: 'Customer complaints logged against a car',
    empty: 'No complaint notifications',
    types: ['maint_complaint_new', 'maint_complaint_intake', 'maint_complaint_headsup', 'maint_complaint_resolved', 'maint_complaint_triage', 'maint_complaint_call', 'maint_complaint_onsite_resolved', 'maint_complaint_diagnostic'],
  },
  {
    key: 'progress',
    label: 'Progress',
    icon: 'wrench',
    blurb: 'Workshop progress checkpoints owed before a car goes overdue, invoices still missing after a car left, cars going into the garage too often or staying too long, and findings held until somebody approves them',
    empty: 'No progress notifications',
    // Mirrors App\Support\NotificationCategories::MAP['progress'] — keep the two in lock-step.
    types: ['maint_checkpoint', 'maint_invoice_missing', 'garage_visit_frequency', 'garage_downtime', 'maint_finding_approval', 'maint_finding_approval_decided'],
  },
  {
    key: 'test_drive',
    label: 'Test Drive',
    icon: 'wrench',
    blurb: 'Cars awaiting a test drive or re-inspection',
    empty: 'No test-drive notifications',
    types: ['maint_review_pending', 'maint_review_reminder', 'maint_review_approved', 'maint_review_rejected', 'maint_review_withdrawn', 'maint_inspection_requested', 'maint_ready_reinspect', 'maint_reinspection_failed'],
  },
  {
    // MUST mirror NotificationCategories::MAP['warranty'] on the backend — the server stamps the
    // group, this list is the fallback, and a type missing from both lands in `other`.
    key: 'warranty',
    label: 'Warranty',
    icon: 'shield',
    blurb: 'Cover about to run out, coverage decisions somebody owes, and dealers who have gone quiet',
    empty: 'No warranty notifications',
    types: [
      'warranty_coverage_review', 'warranty_case_opened', 'warranty_not_covered',
      'warranty_expiring', 'warranty_review_overdue', 'warranty_provider_overdue',
      'warranty_case_stale', 'warranty_recovery_recorded',
      'warranty_case_authorization_requested', 'warranty_case_authorized',
      'warranty_case_sent_to_provider', 'warranty_case_repair_in_progress',
      'warranty_case_repair_completed', 'warranty_case_claim_submitted',
    ],
  },
];

const TYPE_TO_INBOX = INBOX_CATEGORIES.reduce((acc, c) => {
  c.types.forEach((t) => { acc[t] = c.key; });
  return acc;
}, {});

// The inbox category a notification lives in. Prefers the backend-computed `group`
// (single source of truth) and falls back to the local type map, then `other`.
export const inboxCategoryOf = (n) => n?.group || TYPE_TO_INBOX[n?.type] || 'other';

// ── Role-aware operations lanes ──────────────────────────────────────────────
// The Action Center is organised into LANES tailored to each operational role,
// each gated by the permission that role uniquely holds. A user only ever sees the
// lanes their permissions unlock, so the page reads as *their* to-do list:
//   • Inspector (Abu Maroof · maintenance.initiate):
//       Complaints · Awaiting Test · Re-inspect · Car Received
//   • Supervisor / Drivers (Waleed & Abdullah · delegate / logistics / checkpoint):
//       Checkpoint · No Invoice · Assign Garage · Pickup / Dropoff
//   • Controller (Lin · maintenance.manage):
//       Test Approvals · Test Interrupted
// Managers / super-admins hold every permission and therefore see every lane.
// The notifications themselves are already permission-gated at write time (the
// backend NotificationScanner never writes an alert to a role that can't act on
// it), so each lane naturally fills only for the person who owns it. Any received
// type not claimed by a *visible* lane falls to the `other` catch-all, so nothing
// is ever hidden. One type (maint_vehicle_received) is wired ahead of its backend
// hook — the lane simply stays empty until it fires.
//
// Each lane also carries a `group` — the stage of the job it belongs to — so the
// Action Center's lane picker can cluster them under headings instead of showing
// one flat run of a dozen chips. A group renders only when it has visible lanes.
export const LANE_GROUPS = [
  { key: 'inspection', label: 'Inspection', hint: "Abu Maroof's lane — diagnose the car" },
  { key: 'workshop', label: 'Workshop & Moves', hint: 'Dispatch, repair progress & vehicle moves' },
  { key: 'control', label: 'Control', hint: 'Approvals and interrupted work' },
];

export const LANES = [
  // ── Inspector (Abu Maroof) — maintenance.initiate ──────────────────────────
  {
    key: 'complaints',
    group: 'inspection',
    label: 'Complaints',
    icon: 'phone',
    permission: 'maintenance.initiate',
    blurb: 'Customer complaints to triage — with the contact details to reach them',
    empty: 'No customer complaints',
    types: ['maint_complaint_new', 'maint_complaint_triage', 'maint_complaint_headsup', 'maint_complaint_diagnostic',
            'maint_complaint_call', 'maint_complaint_onsite_resolved', 'maint_complaint_resolved'],
  },
  {
    key: 'awaiting_test',
    group: 'inspection',
    label: 'Awaiting Test',
    icon: 'wrench',
    permission: 'maintenance.initiate',
    blurb: 'Approved requests ready for you to take out for a test drive',
    empty: 'Nothing awaiting a test drive',
    types: ['maint_inspection_requested', 'maint_review_approved', 'maint_recommendation_dismissed'],
  },
  {
    key: 'reinspect',
    group: 'inspection',
    label: 'Re-inspect',
    icon: 'shield',
    permission: 'maintenance.initiate',
    blurb: 'Cars back from the garage that need a re-inspection',
    empty: 'Nothing to re-inspect',
    types: ['maint_ready_reinspect', 'maint_reinspection_failed'],
  },
  {
    key: 'car_received',
    group: 'inspection',
    label: 'Car Received',
    icon: 'truck',
    permission: 'maintenance.initiate',
    blurb: 'A driver has just collected the car from the customer',
    empty: 'No cars received from customers yet',
    types: ['maint_vehicle_received'],
  },

  // ── Supervisor / Drivers (Waleed & Abdullah) — delegate / logistics ────────
  {
    key: 'assignments',
    group: 'workshop',
    label: 'Checkpoint',
    icon: 'wrench',
    permission: 'maintenance.checkpoint.manage',
    blurb: 'Cars in the workshop that need you — progress updates owed',
    empty: 'No checkpoints owed',
    types: ['maint_checkpoint'],
  },
  {
    // The money tail of a workshop job: the car is already back, the bill never came. Same owners as
    // Checkpoint (maintenance.checkpoint.manage) but a lane of its own, because the action is different —
    // you chase a garage for paperwork, you don't file a progress update.
    key: 'invoice_missing',
    group: 'workshop',
    label: 'No Invoice',
    icon: 'dollar',
    permission: 'maintenance.checkpoint.manage',
    blurb: 'Cars that left the garage with no invoice entered — chase the bill',
    empty: 'No invoices outstanding',
    types: ['maint_invoice_missing'],
  },
  {
    key: 'assign_garage',
    group: 'workshop',
    label: 'Assign Garage',
    icon: 'map-pin',
    permission: 'maintenance.delegate',
    blurb: 'Tickets waiting for you to pick a garage and dispatch a driver',
    empty: 'No garages to assign right now',
    types: ['maint_dispatch_ready', 'maint_complaint_intake', 'maint_pickup_intake', 'maint_service_intake',
            'maint_breakdown_intake', 'maint_recommendation_pending', 'maint_triage_route_pending',
            'maint_parts_ready', 'maint_arrived_at_garage', 'maint_repair_review'],
  },
  {
    key: 'pickup_dropoff',
    group: 'workshop',
    label: 'Pickup / Dropoff',
    icon: 'truck',
    permission: 'maintenance.logistics',
    blurb: 'Moves assigned to you — go collect or deliver a car',
    empty: 'No pickups or dropoffs',
    types: ['maint_pickup_ready', 'maint_pickup_assigned', 'maint_delegated', 'maint_ready_for_pickup',
            'logistics_dispatch', 'logistics_update', 'logistics_status', 'logistics_ping',
            'logistics_reassigned', 'logistics_unassigned'],
  },
  {
    // Garage Intelligence. Every other lane here is about ONE job — this one is about one CAR, read
    // over a month: it keeps going back in, or it went in and never came out. A lane of its own
    // rather than a corner of Checkpoint, because the response is different in kind: a checkpoint
    // asks "what is happening to this car today", this asks "should this car still be in the fleet,
    // and is this garage the right one". Buried among daily progress chases, a month-long pattern
    // reads as just another overdue update and gets cleared with them.
    // @see backend App\Services\Garage\GarageIntelligenceService
    key: 'garage_pattern',
    group: 'workshop',
    label: 'Too Often / Too Long',
    icon: 'alert',
    // Matches the read gate on these types in NotificationScanner::ALERT_PERMISSIONS, so the lane is
    // visible to exactly the people allowed to see its contents.
    permission: 'maintenance.manage',
    blurb: 'Cars going into the garage too often, or staying in too long',
    empty: 'No cars are going in too often or staying too long',
    types: ['garage_visit_frequency', 'garage_downtime'],
  },

  // ── Controller (Lin) — maintenance.manage ──────────────────────────────────
  {
    key: 'test_approvals',
    group: 'control',
    label: 'Test Approvals',
    icon: 'check',
    permission: 'maintenance.manage',
    blurb: 'The system suggests a car needs a test — approve or reject the request',
    empty: 'No test requests to approve',
    // `maint_review_reminder` belongs here and not in a lane of its own: it IS a request awaiting this
    // Controller's decision — the only difference is that they asked to be told about it later rather
    // than being told about it when it arrived. A reminder that lands in a lane the reviewer doesn't
    // watch is a reminder that did not happen.
    types: ['maint_review_pending', 'maint_review_reminder'],
  },
  {
    key: 'test_interrupted',
    group: 'control',
    label: 'Test Interrupted',
    icon: 'alert',
    permission: 'maintenance.manage',
    blurb: 'A car due for a test went out on rent and has now returned — action it before it goes out again',
    empty: 'No interrupted tests',
    types: ['maint_test_interrupted'],
  },
];

// The lanes this user can see, in order — permission-gated. `can` is usePermissions().can.
export const visibleLanes = (can) => LANES.filter((l) => can(l.permission));

// The lane a notification belongs to, restricted to the lanes the user can actually
// see (`visibleKeys` = a Set of keys from visibleLanes). A type shared by two lanes
// resolves to the first VISIBLE one, so a supervisor's copy of a shared alert never
// lands in an inspector-only lane. Falls back to `other` so nothing is dropped.
export const laneOf = (n, visibleKeys) => {
  const t = n?.type;
  for (const lane of LANES) {
    if (visibleKeys.has(lane.key) && lane.types.includes(t)) return lane.key;
  }
  return 'other';
};

// ── Per-type tabs ────────────────────────────────────────────────────────────
// The page can also break notifications out into ONE tab per alert `type` (not
// just the broad domains above). There are ~60 possible types on the backend, so
// rather than hard-code a tab list we derive the tabs from the types actually
// present in the loaded feed — you only ever see tabs that have something in them.

// Nicer display names for the common types. Anything not listed here is
// title-cased from its raw type (see `humanizeType`), so new backend types still
// render a sensible label without a code change.
export const TYPE_LABEL = {
  overdue_rental: 'Overdue Rental',
  rental_expiring: 'Rental Expiring',
  overdue_maintenance: 'Overdue Maintenance',
  maint_checkpoint: 'Maintenance Progress',
  maint_review_reminder: 'Reminder You Set',
  maint_invoice_missing: 'Invoice Not Entered',
  garage_visit_frequency: 'Going In Too Often',
  garage_downtime: 'Too Long In The Garage',
  maintenance_back_open: 'Return Reconciliation',
  booking_in_maintenance: 'Booking In Maintenance',
  booking_readiness: 'Booking Readiness',
  document_expiry: 'Document Expiry',
  inspection_due: 'Inspection Due',
  service_inspection: 'Oil Change',
  service_due_soon: 'Service Due Soon',
  service_overdue: 'Service Overdue',
  service_reminder_due: 'Service Reminder',
  contact_reminder_due: 'Contact Reminder',
  battery_overdue: 'Battery Overdue',
  approval_pending: 'Approval Pending',
  negative_yield: 'Negative Yield',
  high_maintenance_cost: 'High Repair Cost',
  invoice_overdue: 'Invoice Overdue',
  maint_invoice_overdue: 'Invoice Overdue',
  garage_invoice_submitted: 'Garage Invoice',
  chronic_fault: 'Chronic Fault',
  frequent_breakdowns: 'Frequent Breakdowns',
  workshop_stalling: 'Workshop Stalling',
  condition_grade: 'Condition Grade',
  penalty: 'Penalty',
  rating: 'Rating',
  info: 'System Message',
  logistics_dispatch: 'Dispatch',
  logistics_update: 'Move Update',
  logistics_status: 'Move Status',
  logistics_ping: 'Location Ping',
  logistics_reassigned: 'Move Reassigned',
  logistics_unassigned: 'Move Unassigned',
};

// Fallback label: strip the noisy `maint_` prefix and Title-Case the rest.
// e.g. `maint_pickup_ready` → "Pickup Ready", `condition_graded` → "Condition Graded".
const humanizeType = (t) => (t || 'other')
  .replace(/^maint_/, '')
  .replace(/_/g, ' ')
  .replace(/\b\w/g, (c) => c.toUpperCase())
  .trim();

export const typeLabel = (t) => TYPE_LABEL[t] || humanizeType(t);

// Some distinct backend types are really the same thing to an operator, so they
// collapse into one tab. Oil-change fires as `service_inspection` today but has a
// few legacy/related service keys — fold them all under the single Oil Change tab.
export const TAB_ALIAS = {
  service_due: 'service_inspection',
  service_due_soon: 'service_inspection',
  service_overdue: 'service_inspection',
  service_inspection_soon: 'service_inspection',
};

// The tab a notification lives under: its alias if any, else its own type.
export const tabKeyOf = (n) => {
  const t = n?.type || 'other';
  return TAB_ALIAS[t] || t;
};

// Pick a glyph for a type by keyword, reusing the existing ICONS set.
export const typeIcon = (t) => {
  if (!t) return 'bell';
  if (t.startsWith('logistics_') || t.includes('transit') || t.includes('pickup') || t.includes('dispatch')) return 'truck';
  if (t.includes('service') || t.includes('oil') || t.includes('battery')) return 'oil';
  if (t.includes('rental') || t === 'penalty') return 'clock';
  if (t.includes('document') || t.includes('insurance') || t.includes('inspection') || t.includes('condition')) return 'shield';
  if (t.includes('yield') || t.includes('cost') || t.includes('invoice')) return 'trend-down';
  if (t.startsWith('maint') || t.includes('maintenance') || t.includes('garage') || t.includes('workshop') || t.includes('repair') || t.includes('breakdown') || t.includes('fault')) return 'wrench';
  if (t === 'info') return 'bell';
  return 'alert';
};

// Build the tab list from the loaded items: an "All" tab followed by one tab per
// distinct `type` present, alphabetised by label. Nothing empty is ever shown.
export const buildTypeTabs = (items) => {
  const types = [...new Set((items || []).map((n) => tabKeyOf(n)))];
  types.sort((a, b) => typeLabel(a).localeCompare(typeLabel(b)));
  return [
    { key: 'all', label: 'All', icon: 'bell', empty: 'No notifications found' },
    ...types.map((t) => ({
      key: t,
      label: typeLabel(t),
      icon: typeIcon(t),
      empty: `No ${typeLabel(t).toLowerCase()} notifications`,
    })),
  ];
};

// ── Per-type call-to-action ──────────────────────────────────────────────────
// The verb on each card's action button. Keeps the alert outcome-oriented:
// "what do I do about this?" rather than just "open a page".
export const ACTION_LABEL = {
  overdue_rental: 'View Contract',
  overdue_maintenance: 'View Maintenance',
  maint_checkpoint: 'Submit Checkpoint',
  maint_invoice_missing: 'Chase Invoice',
  // Both land on the car's own profile, where the Garage Behaviour panel shows the same reading the
  // alert was raised from — the visits, the days, and the sentences under "Why".
  garage_visit_frequency: 'View Car',
  garage_downtime: 'View Car',
  maintenance_back_open: 'Close Contract',
  document_expiry: 'Renew Document',
  service_inspection: 'Service & Inspection',
  approval_pending: 'Review & Approve',
  negative_yield: 'View Analysis',
  high_maintenance_cost: 'View Maintenance',
  logistics_dispatch: 'Open Driver Dispatch',
  logistics_update: 'View Move',
  logistics_status: 'View Move',
  logistics_ping: 'Reply',
  booking_readiness: 'Prep Car',

  // ── Operations-lane verbs — each button reads as the action that lane owns ──
  // Inspector (Abu Maroof)
  maint_complaint_new: 'Triage Complaint',
  maint_complaint_triage: 'Triage Complaint',
  maint_complaint_headsup: 'View Complaint',
  maint_complaint_diagnostic: 'View Complaint',
  maint_complaint_call: 'View Complaint',
  maint_complaint_onsite_resolved: 'View Complaint',
  maint_complaint_resolved: 'View Complaint',
  maint_inspection_requested: 'Start Test',
  maint_review_approved: 'Start Test',
  maint_recommendation_dismissed: 'View Ticket',
  maint_ready_reinspect: 'Re-inspect',
  maint_reinspection_failed: 'Re-inspect',
  maint_vehicle_received: 'Inspect Car',
  // Supervisor / Drivers (Waleed & Abdullah)
  maint_dispatch_ready: 'Assign Garage',
  maint_pickup_intake: 'Assign Garage',
  maint_service_intake: 'Assign Garage',
  maint_breakdown_intake: 'Assign Garage',
  maint_complaint_intake: 'Assign Garage',
  maint_parts_ready: 'Assign Garage',
  maint_recommendation_pending: 'Review & Assign',
  maint_triage_route_pending: 'Route Ticket',
  maint_arrived_at_garage: 'View Ticket',
  maint_repair_review: 'Review Repair',
  maint_pickup_ready: 'Go to Pickup',
  maint_pickup_assigned: 'Go to Pickup',
  maint_delegated: 'View Task',
  maint_ready_for_pickup: 'Go to Pickup',
  // Controller (Lin)
  maint_review_pending: 'Review Request',
  // The reminder they set on themselves — the verb is the same, because the job is the same.
  maint_review_reminder: 'Review Request',
  maint_review_withdrawn: 'See why',
  maint_test_interrupted: 'Review Ticket',
};

// Destination override: a few alerts must route to the page where their action is actually PERFORMED,
// not to the raw payload url. The review-gate approve/reject lives on the Inspection Review Queue
// (POST /maintenance-tickets/{id}/review/approve) — the ticket command view can't action it — so
// `maint_review_pending` is sent there. Everything else keeps its payload url. Applied on the frontend
// so it also corrects notifications already stored with the old url.
export const ACTION_TARGET = {
  maint_review_pending: '/inspection-review',
  // "Your request was withdrawn" — the queue is where the reason lives (which contract took the car, when
  // it opened, for whom). The ticket view can only show that it ended, not why.
  maint_review_withdrawn: '/inspection-review',
};

// The place a notification's action button should navigate to: the per-type override if any, else the
// payload url. Returns null when there's nowhere to go (button is then hidden).
//
// For a review-gate alert we also deep-link to the exact card: `/inspection-review?ticket=<id>` so the
// queue can scroll to and highlight the request the operator clicked, instead of dropping them at the
// top of a long list to hunt for it.
export const actionTarget = (n) => {
  const base = ACTION_TARGET[n?.type] || n?.url || null;
  if (base && (n?.type === 'maint_review_pending' || n?.type === 'maint_review_withdrawn')) {
    const ticket = n?.meta?.ticket_id;
    if (ticket) return `/inspection-review?ticket=${ticket}`;
  }
  return base;
};

// Resolve the button label, refining document_expiry into Insurance vs Registration.
// The detector titles each alert "Insurance …" / "Registration …", so we read the
// document kind straight off the title (the dedup `key` isn't sent to the client).
export const actionLabel = (n) => {
  if (n?.type === 'document_expiry') {
    const t = (n.title || '').toLowerCase();
    if (t.startsWith('insurance')) return 'Renew Insurance';
    if (t.startsWith('registration')) return 'Renew Registration';
  }
  return ACTION_LABEL[n?.type] || (actionTarget(n) ? 'View Details' : null);
};

// ── Meta chips ───────────────────────────────────────────────────────────────
// Compact, glanceable facts pulled from the alert payload's `meta` map. `tone`
// 'strong' chips inherit the card's severity colour (the headline number); the
// rest are neutral. Returns [] when there's nothing structured to show.
export const metaChips = (n) => {
  const m = n?.meta || {};
  const chips = [];
  const num = (v) => Number(v).toLocaleString();

  switch (n?.type) {
    case 'overdue_rental':
      if (m.days_overdue != null) chips.push({ text: `${m.days_overdue}d late`, tone: 'strong' });
      if (m.contract_no) chips.push({ text: `#${m.contract_no}` });
      break;
    case 'overdue_maintenance':
      if (m.days_overdue != null) chips.push({ text: `${m.days_overdue}d late`, tone: 'strong' });
      if (m.garage) chips.push({ text: m.garage });
      break;
    case 'maintenance_back_open':
      if (m.days_since_return != null) chips.push({ text: `${m.days_since_return}d back`, tone: 'strong' });
      if (m.contract_no) chips.push({ text: `#${m.contract_no}` });
      break;
    case 'document_expiry':
      if (m.days != null) {
        chips.push(m.days < 0
          ? { text: `${Math.abs(m.days)}d expired`, tone: 'strong' }
          : { text: `${m.days}d left`, tone: 'strong' });
      }
      break;
    case 'service_inspection':
      if (m.overdue_km != null) chips.push({ text: `${num(m.overdue_km)} km over`, tone: 'strong' });
      else if (m.remaining_km != null) chips.push({ text: `~${num(m.remaining_km)} km left`, tone: 'strong' });
      if (m.days_to_due != null) chips.push({ text: `~${m.days_to_due}d` });
      break;
    case 'negative_yield':
      if (m.net_yield != null) chips.push({ text: `−AED ${num(Math.abs(m.net_yield))}`, tone: 'strong' });
      break;
    case 'high_maintenance_cost':
      if (m.cost != null) chips.push({ text: `AED ${num(m.cost)}`, tone: 'strong' });
      break;
    case 'maint_priority_watch':
      if (m.priority_label) chips.push({ text: `${m.priority_emoji || ''} ${m.priority_label}`.trim(), tone: 'strong' });
      break;
    case 'maint_delegated':
      if (m.task) chips.push({ text: m.task === 'pickup' ? 'Pickup' : 'Dropoff', tone: 'strong' });
      break;
    // Customer complaints carry who reported it + how to reach them, so the
    // Inspector can call straight from the card (Abu Maroof's "enough to contact").
    case 'maint_complaint_new':
    case 'maint_complaint_triage':
    case 'maint_complaint_headsup':
    case 'maint_complaint_diagnostic':
      if (m.customer) chips.push({ text: m.customer, tone: 'strong' });
      if (m.customer_phone) chips.push({ text: `📞 ${m.customer_phone}` });
      if (m.contract_no) chips.push({ text: `#${m.contract_no}` });
      break;
    case 'booking_readiness':
      if (m.days_left != null) chips.push({ text: m.days_left <= 0 ? 'Pickup today' : `${m.days_left}d to pickup`, tone: 'strong' });
      if (m.contract_no) chips.push({ text: `#${m.contract_no}` });
      break;
    default:
      break;
  }
  if (m.plate) chips.push({ text: m.plate, plate: true });
  return chips;
};

// ── Structured metadata: highlights + entities ───────────────────────────────
// The rich Action-Center card splits an alert's `meta` into two reads:
//   • highlights — the URGENCY numbers ("6d late", "AED 1,450", "1,200 km over").
//     Colour-toned so the reason-it-matters pops. tone: danger | warn | strong | neutral.
//   • entities   — the WHO / WHAT (vehicle, customer, contract, ticket, garage, driver …),
//     rendered as an icon-labelled info grid so operators never dig through prose.
// Both are derived from the payload the backend already sends — no extra lookups.

const numFmt = (v) => Number(v).toLocaleString();

// fault_severity (workflow tickets) → a chip tone, so "critical" reads red at a glance.
export const FAULT_SEVERITY_TONE = {
  critical: 'danger',
  major: 'warn',
  moderate: 'warn',
  minor: 'neutral',
  cosmetic: 'neutral',
};

// The urgency metrics for a card — the "why it matters" line. Type-specific first,
// then a generic sweep so a brand-new backend type still surfaces something sensible.
export const metaHighlights = (n) => {
  const m = n?.meta || {};
  const out = [];
  const seen = new Set();
  const add = (text, tone = 'strong') => {
    if (text == null || text === '' || seen.has(text)) return;
    seen.add(text);
    out.push({ text, tone });
  };

  switch (n?.type) {
    case 'overdue_rental':
    case 'overdue_maintenance':
    case 'part_delivery_overdue':
      if (m.days_overdue != null) add(`${m.days_overdue}d late`, 'danger');
      break;
    case 'maintenance_back_open':
      if (m.days_since_return != null) add(`${m.days_since_return}d back`, 'warn');
      break;
    case 'document_expiry':
      if (m.days != null) add(m.days < 0 ? `${Math.abs(m.days)}d expired` : `${m.days}d left`, m.days < 0 ? 'danger' : 'warn');
      break;
    case 'service_inspection':
      if (m.overdue_km != null) add(`${numFmt(m.overdue_km)} km over`, 'warn');
      else if (m.remaining_km != null) add(`~${numFmt(m.remaining_km)} km left`, 'strong');
      if (m.days_to_due != null) add(`~${m.days_to_due}d to due`, 'neutral');
      break;
    case 'service_reminder_due':
      if (m.km_remaining != null && m.km_remaining < 0) add(`${numFmt(Math.abs(m.km_remaining))} km over`, 'warn');
      else if (m.days_remaining != null && m.days_remaining < 0) add(`${Math.abs(m.days_remaining)}d over`, 'warn');
      break;
    case 'negative_yield':
      if (m.net_yield != null) add(`−AED ${numFmt(Math.abs(m.net_yield))}`, 'danger');
      break;
    case 'high_maintenance_cost':
      if (m.cost != null) add(`AED ${numFmt(m.cost)}`, 'warn');
      break;
    case 'invoice_overdue':
      if (m.balance != null) add(`AED ${numFmt(m.balance)} due`, 'danger');
      break;
    case 'rental_expiring':
    case 'booking_readiness':
    case 'booking_in_maintenance':
      if (m.days_left != null) add(m.days_left <= 0 ? 'Due today' : `${m.days_left}d left`, m.days_left <= 1 ? 'danger' : 'warn');
      break;
    case 'inspection_due':
      if (m.status === 'overdue') add('Overdue', 'warn');
      else if (m.days_remaining != null) add(`${m.days_remaining}d left`, 'neutral');
      break;
    default:
      // Generic fallbacks for the many event-driven workflow types.
      if (m.days_overdue != null) add(`${m.days_overdue}d late`, 'danger');
      if (m.days_left != null) add(m.days_left <= 0 ? 'Due today' : `${m.days_left}d left`, 'warn');
      break;
  }

  // Fault severity is meaningful across almost every workshop alert — always show it.
  if (m.fault_severity) add(m.fault_severity, FAULT_SEVERITY_TONE[m.fault_severity] || 'neutral');
  if (m.priority_label) add(`${m.priority_emoji || ''} ${m.priority_label}`.trim(), 'warn');

  return out;
};

// The who/what entities on a card — a labelled, icon-led info grid. Order = importance.
// `mono` renders identifiers (plate, contract, ticket) in a tabular mono chip; `href`
// makes a value directly actionable (tel: for a customer phone).
export const metaEntities = (n) => {
  const m = n?.meta || {};
  const out = [];
  const push = (key, label, value, icon, opts = {}) => {
    if (value == null || value === '') return;
    out.push({ key, label, value: String(value), icon, ...opts });
  };

  push('plate', 'Vehicle', m.plate, 'car', { mono: true });
  push('customer', 'Customer', m.customer, 'user');
  if (m.customer_phone) push('phone', 'Phone', m.customer_phone, 'phone', { mono: true, href: `tel:${String(m.customer_phone).replace(/\s+/g, '')}` });
  if (m.contract_no) push('contract', 'Contract', `#${m.contract_no}`, 'doc', { mono: true });
  const ticket = m.ticket_id || m.maintenance_id;
  if (ticket) push('ticket', 'Ticket', `#${ticket}`, 'wrench', { mono: true });
  const garage = m.garage || m.to_garage;
  if (garage) push('garage', 'Garage', garage, 'map-pin');
  if (m.from_garage && m.from_garage !== garage) push('from_garage', 'From', m.from_garage, 'map-pin');
  push('driver', 'Driver', m.driver, 'truck');
  push('vendor', 'Vendor', m.vendor, 'building');
  push('supplier', 'Supplier', m.supplier, 'building');
  if (m.service || m.service_type) push('service', 'Service', typeLabel(m.service || m.service_type), 'oil');
  const eta = m.projected_date || m.out_date || m.scheduled_for;
  if (eta) push('eta', 'Date', String(eta).slice(0, 10), 'calendar');
  const actor = m.requested_by || m.reviewed_by || m.assigned_by || m.by;
  if (actor && actor !== 'system') push('actor', 'By', actor, 'user');

  return out;
};

// ── Priority axis + entity clustering ────────────────────────────────────────
// Severity IS our priority axis on the Action Center: critical (immediate) →
// warning (high, today) → info (standard) → success (resolved).
export const PRIORITY_ORDER = ['critical', 'warning', 'info', 'success'];

// Human section copy per priority tier — headline + a one-line "how urgent".
export const PRIORITY_SECTION = {
  critical: { label: 'Critical', sub: 'Needs immediate attention' },
  warning: { label: 'High priority', sub: 'Handle these today' },
  info: { label: 'Standard', sub: 'Work through when you can' },
  success: { label: 'Resolved', sub: 'Completed — for your records' },
};

// Does this alert carry a next-step action (a CTA)? Drives the "Action required" count.
export const hasAction = (n) => !!actionLabel(n);

// The most-urgent (lowest-rank) severity across a set of alerts — a cluster's headline.
export const worstSeverity = (items) =>
  (items || []).reduce((worst, n) => (severityRank(n.severity) < severityRank(worst) ? n.severity : worst), 'success');

// The operational entity an alert is about — its maintenance ticket first, else its
// vehicle. Used to visually cluster the several alerts that pile up on one car / one
// repair so the board reads as "work items", not a flat stream of rows.
export const entityKeyOf = (n) => {
  const m = n?.meta || {};
  const ticket = m.ticket_id || m.maintenance_id;
  if (ticket) return `ticket:${ticket}`;
  if (m.plate) return `plate:${m.plate}`;
  return null;
};

// Cluster a (pre-sorted) list into render nodes: a { type:'group' } when 2+ alerts share
// an entity, else { type:'single' }. First-occurrence order is preserved; later members
// of a group are pulled up under its first appearance so a car's alerts read as one block.
export const clusterByEntity = (items) => {
  const groups = new Map();
  const order = [];
  for (const n of items || []) {
    const k = entityKeyOf(n);
    if (!k) { order.push({ type: 'single', item: n }); continue; }
    let g = groups.get(k);
    if (!g) { g = { type: 'group', key: k, items: [] }; groups.set(k, g); order.push(g); }
    g.items.push(n);
  }
  // A "group" of one is just a single card.
  return order.map((node) =>
    node.type === 'group' && node.items.length === 1 ? { type: 'single', item: node.items[0] } : node);
};

// A human label for a cluster header: prefer the plate (what an operator recognises),
// fall back to the ticket number.
export const clusterLabel = (node) => {
  const withPlate = node.items.find((n) => n?.meta?.plate);
  if (withPlate) return withPlate.meta.plate;
  const t = node.items.find((n) => n?.meta?.ticket_id || n?.meta?.maintenance_id);
  const id = t?.meta?.ticket_id || t?.meta?.maintenance_id;
  return id ? `Ticket #${id}` : 'Related';
};

// Verbose relative time for the page: "just now" · "5 min ago" · "2 hours ago" ·
// "Yesterday" · "3 days ago" · then an absolute date. (timeAgo() stays terse for the bell.)
export const relativeTime = (iso) => {
  if (!iso) return '';
  const then = new Date(iso).getTime();
  if (isNaN(then)) return '';
  const s = Math.floor((Date.now() - then) / 1000);
  if (s < 45) return 'just now';
  const m = Math.floor(s / 60);
  if (m < 60) return `${m} min ago`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h} hour${h === 1 ? '' : 's'} ago`;
  const d = Math.floor(h / 24);
  if (d === 1) return 'Yesterday';
  if (d < 7) return `${d} days ago`;
  return new Date(iso).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

// The exact timestamp for the title tooltip / secondary line: "28 Jul 2026, 14:32".
export const exactTime = (iso) => {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '';
  return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};

// Compact "time ago": just now · 5m · 2h · 3d · then a date.
export const timeAgo = (iso) => {
  if (!iso) return '';
  const then = new Date(iso).getTime();
  if (isNaN(then)) return '';
  const s = Math.floor((Date.now() - then) / 1000);
  if (s < 45) return 'just now';
  if (s < 90) return '1m';
  const m = Math.floor(s / 60);
  if (m < 60) return `${m}m`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h}h`;
  const d = Math.floor(h / 24);
  if (d < 7) return `${d}d`;
  return new Date(iso).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
};

// Calendar bucket for the full-page grouped list.
export const dateBucket = (iso) => {
  if (!iso) return 'Earlier';
  const d = new Date(iso);
  const now = new Date();
  const startOf = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
  const diffDays = Math.round((startOf(now) - startOf(d)) / 86400000);
  if (diffDays <= 0) return 'Today';
  if (diffDays === 1) return 'Yesterday';
  if (diffDays <= 7) return 'This week';
  if (diffDays <= 30) return 'This month';
  return 'Earlier';
};
