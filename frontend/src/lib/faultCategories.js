// Lightweight, keyword-based classifier that buckets a maintenance visit into a
// mechanical category from the issue tags / reason text already on the profile
// payload. It powers two Overview widgets:
//   • Fault distribution — COUNT of visits per category (money-free, always shown)
//   • Expense architecture — SUM of visit cost per category (money, gated)
//
// This is a management-analytics estimate, not booked accounting: cost is stored per
// visit (not per line), so a visit's whole cost is attributed to its dominant
// category. Good enough to see "where the money/faults go" at a glance; swap for a
// real per-line categorisation later without touching the charts.

// Ordered most-specific → most-generic; first category with a keyword hit wins.
// `color` is a chart-palette key (see chartUtils PALETTES).
export const FAULT_CATEGORIES = [
  { key: 'ac',           label: 'Cooling & A/C',        color: 'cyan',   keywords: ['cool', 'a/c', 'aircon', 'air con', 'climate', 'compressor', 'condenser', 'radiator', 'overheat'] },
  { key: 'electrical',   label: 'Electrical system',    color: 'violet', keywords: ['electr', 'battery', 'wiring', 'alternator', 'sensor', 'fuse', 'ecu', 'light', 'lamp', 'won\'t start', 'wont start', 'no start', 'starter'] },
  { key: 'tyres',        label: 'Tyres & wheels',       color: 'amber',  keywords: ['tyre', 'tire', 'wheel', 'rim', 'puncture', 'tpms', 'alignment', 'rotation', 'balancing'] },
  { key: 'brakes',       label: 'Brakes',               color: 'red',    keywords: ['brake', 'pad', 'disc', 'rotor', 'abs', 'handbrake', 'caliper'] },
  { key: 'suspension',   label: 'Suspension & steering',color: 'teal',   keywords: ['suspension', 'shock', 'strut', 'steering', 'bushing', 'control arm', 'ball joint', 'wishbone'] },
  { key: 'transmission', label: 'Transmission',         color: 'purple', keywords: ['transmission', 'gearbox', 'gear', 'clutch', 'drivetrain', 'differential', 'axle'] },
  { key: 'bodywork',     label: 'Spare parts & bodywork', color: 'orange', keywords: ['body', 'paint', 'dent', 'scratch', 'bumper', 'panel', 'door', 'mirror', 'windscreen', 'windshield', 'glass', 'spare part', 'bodywork'] },
  { key: 'recovery',     label: 'Recovery & transport', color: 'slate',  keywords: ['recovery', 'tow', 'towing', 'winch', 'transport', 'flatbed', 'breakdown'] },
  { key: 'engine',       label: 'Major repairs',        color: 'blue',   keywords: ['engine', 'oil leak', 'spark', 'timing', 'coolant', 'gasket', 'turbo', 'piston', 'cylinder', 'valve', 'overhaul'] },
  { key: 'routine',      label: 'Maintenance service',  color: 'emerald',keywords: ['service', 'oil change', 'oil', 'filter', 'inspection', 'wash', 'check', 'routine', 'maintenance', 'battery replacement'] },
];

const OTHER = { key: 'other', label: 'General & other', color: 'slate' };

// Classify one visit → a category descriptor. Looks at its issue tags, reason and notes.
export function classifyVisit(visit) {
  const hay = [
    ...(Array.isArray(visit?.tags) ? visit.tags : []),
    visit?.reason || '',
    visit?.notes || '',
  ].join(' ').toLowerCase();

  if (hay.trim()) {
    for (const cat of FAULT_CATEGORIES) {
      if (cat.keywords.some((k) => hay.includes(k))) return cat;
    }
  }
  return OTHER;
}

// Chart-palette keys cycled across individual fault slices (faultTagSegments).
const FAULT_PALETTE = ['blue', 'violet', 'amber', 'red', 'teal', 'purple', 'orange', 'cyan', 'emerald', 'slate'];

// Per-FAULT distribution — instead of bucketing each visit into one broad mechanical category,
// this tallies the INDIVIDUAL fault tags recorded across all visits, so a car's donut shows each
// distinct fault and its share of every fault logged (the slices sum to 100%). A visit's faults are
// its issue tags (MAIN+SUP); a visit with no tags falls back to its reason, else "Unspecified".
// The long tail past `top` is folded into one "Other (N types)" slice so the donut stays legible.
// Returns [{ key, label, color, value }] sorted big → small.
export function faultTagSegments(visits = [], { top = 10 } = {}) {
  const totals = {};
  visits.forEach((v) => {
    let faults = Array.isArray(v?.tags) ? v.tags.filter(Boolean) : [];
    if (!faults.length && v?.reason) faults = [v.reason];
    if (!faults.length) faults = ['Unspecified'];
    faults.forEach((f) => {
      const label = String(f).trim();
      if (label) totals[label] = (totals[label] || 0) + 1;
    });
  });

  const sorted = Object.keys(totals)
    .map((label) => ({ key: label, label, value: totals[label] }))
    .sort((a, b) => b.value - a.value);

  const withColor = (s, i) => ({ ...s, color: FAULT_PALETTE[i % FAULT_PALETTE.length] });
  if (sorted.length <= top) return sorted.map(withColor);

  const head = sorted.slice(0, top - 1).map(withColor);
  const tail = sorted.slice(top - 1);
  head.push({
    key: '__other',
    label: `Other (${tail.length} types)`,
    value: tail.reduce((a, s) => a + s.value, 0),
    color: 'slate',
    // The folded long-tail, so the legend's "Other" row can expand to reveal each type's share.
    children: tail.map((s) => ({ key: s.key, label: s.label, value: s.value })),
  });
  return head;
}

// Aggregate a list of visits into donut segments.
//   metric: 'count'  → one per visit (fault distribution)
//           'cost'   → visit.total summed  (expense architecture)
// Returns [{ key, label, color, value }] sorted big → small, zero buckets dropped.
export function categorySegments(visits = [], metric = 'count') {
  const totals = {};
  const meta = {};
  visits.forEach((v) => {
    const cat = classifyVisit(v);
    const amount = metric === 'cost' ? Number(v?.total || 0) : 1;
    if (amount <= 0) return;
    totals[cat.key] = (totals[cat.key] || 0) + amount;
    meta[cat.key] = cat;
  });
  return Object.keys(totals)
    .map((k) => ({ key: k, label: meta[k].label, color: meta[k].color, value: Math.round(totals[k] * 100) / 100 }))
    .filter((s) => s.value > 0)
    .sort((a, b) => b.value - a.value);
}
