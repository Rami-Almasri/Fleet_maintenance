// Shared theming + helpers for the notification centre (bell dropdown + full page).
// Keeping the maps here means the badge, dropdown and page all render identically.

// Severity → visual theme. Mirrors App\Notifications\FleetAlert::SEVERITIES on the backend.
export const SEVERITY = {
  critical: {
    label: 'Critical',
    dot: 'bg-red-500',
    ring: 'ring-red-500/30',
    chipBg: 'bg-red-50',
    chipText: 'text-red-700',
    iconBg: 'bg-gradient-to-br from-red-500 to-rose-600',
    accent: 'bg-red-500',
    glow: 'shadow-[0_0_0_3px_rgba(239,68,68,0.10)]',
  },
  warning: {
    label: 'Warning',
    dot: 'bg-amber-500',
    ring: 'ring-amber-500/30',
    chipBg: 'bg-amber-50',
    chipText: 'text-amber-700',
    iconBg: 'bg-gradient-to-br from-amber-500 to-orange-500',
    accent: 'bg-amber-500',
    glow: 'shadow-[0_0_0_3px_rgba(245,158,11,0.10)]',
  },
  info: {
    label: 'Info',
    dot: 'bg-blue-500',
    ring: 'ring-blue-500/30',
    chipBg: 'bg-blue-50',
    chipText: 'text-blue-700',
    iconBg: 'bg-gradient-to-br from-blue-500 to-indigo-500',
    accent: 'bg-blue-500',
    glow: 'shadow-[0_0_0_3px_rgba(59,130,246,0.10)]',
  },
  success: {
    label: 'Success',
    dot: 'bg-emerald-500',
    ring: 'ring-emerald-500/30',
    chipBg: 'bg-emerald-50',
    chipText: 'text-emerald-700',
    iconBg: 'bg-gradient-to-br from-emerald-500 to-teal-500',
    accent: 'bg-emerald-500',
    glow: 'shadow-[0_0_0_3px_rgba(16,185,129,0.10)]',
  },
};

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
};

export const iconPath = (key) => ICONS[key] || ICONS.bell;

// Human category label for grouping/filter chips.
export const CATEGORY_LABEL = {
  operations: 'Operations',
  maintenance: 'Maintenance',
  fleet: 'Fleet',
  data: 'Data',
  system: 'System',
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
