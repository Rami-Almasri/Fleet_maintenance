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
    label: 'Warning',
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
    label: 'Success',
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
    types: ['overdue_maintenance', 'maintenance_back_open', 'service_inspection', 'approval_pending'],
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
    types: ['overdue_maintenance', 'maintenance_back_open', 'service_inspection', 'approval_pending', 'high_maintenance_cost'],
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
    types: ['maint_complaint_intake', 'maint_complaint_headsup', 'maint_complaint_resolved', 'maint_complaint_triage', 'maint_complaint_call', 'maint_complaint_onsite_resolved', 'maint_complaint_diagnostic'],
  },
  {
    key: 'test_drive',
    label: 'Test Drive',
    icon: 'wrench',
    blurb: 'Cars awaiting a test drive or re-inspection',
    empty: 'No test-drive notifications',
    types: ['maint_review_pending', 'maint_review_approved', 'maint_review_rejected', 'maint_inspection_requested', 'maint_ready_reinspect', 'maint_reinspection_failed'],
  },
];

const TYPE_TO_INBOX = INBOX_CATEGORIES.reduce((acc, c) => {
  c.types.forEach((t) => { acc[t] = c.key; });
  return acc;
}, {});

// The inbox category a notification lives in. Prefers the backend-computed `group`
// (single source of truth) and falls back to the local type map, then `other`.
export const inboxCategoryOf = (n) => n?.group || TYPE_TO_INBOX[n?.type] || 'other';

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
  return ACTION_LABEL[n?.type] || (n?.url ? 'View Details' : null);
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
