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
//
// LOCALIZATION — `label` is the English, kept here beside the keywords that define the category;
// the Arabic lives in labels.js under `ar.faultCategories.<key>`. The segment builders below accept
// a `tf` (from useI18n) and resolve through it, so a chart legend renders in the active language.
// Individual FAULT TAGS are data, not UI text, and are never translated — only the buckets are.
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

// Fixed hex palette for the individual fault slices. NOT the shared chartUtils keys — after the
// brand repaint, violet/indigo resolve to yellows there, which put three near-identical
// yellow slices side by side on this donut. This order is CVD-validated (worst adjacent
// ΔE 24.2 on the light card surface): assign in order, never reshuffle; overflow past the
// palette folds into "Other" rather than inventing new hues.
const FAULT_PALETTE = ['#2a78d6', '#1baf7a', '#eda100', '#008300', '#4a3aa7', '#e34948', '#e87ba4', '#eb6834'];
// The non-categories: "Unspecified" (visits with no fault recorded) must read as missing data —
// muted, never the loudest hue even when it's the biggest slice. "Other" (folded long tail) is a
// darker gray so the two stay distinct where the ring wraps.
const UNSPECIFIED_COLOR = '#94a3b8';
const OTHER_COLOR = '#64748b';

const arr = (x) => (Array.isArray(x) ? x.filter(Boolean) : []);

// A visit's FAULTS — nothing else. The legacy sheet typed faults, planned services and bookkeeping
// words into one free-text column, so `tags` mixes "Rim Scratch" with "Oil & Fillter Change" and
// "Customer"; the backend now ships that list already split (fault_tags / service_tags /
// context_tags — see EventClassificationService::labelKind). `tags` is the fallback for payloads
// that predate the split, where the old undifferentiated behaviour is still the best available.
export function visitFaults(visit) {
  if (Array.isArray(visit?.fault_tags) || Array.isArray(visit?.service_tags) || Array.isArray(visit?.damage_tags)) {
    return arr(visit.fault_tags);
  }
  return arr(visit?.tags);
}

// A visit's DAMAGE — externally-caused physical damage (kerbed rim, door dent, cracked screen).
// Unplanned like a fault, but it describes what happened TO the car rather than what is wrong WITH it,
// so it belongs on the Damage surface and never on a fault chart.
export function visitDamage(visit) {
  return arr(visit?.damage_tags);
}

// True when a visit is PLANNED WORK ONLY — a service was recorded and no fault was. Such a visit has
// zero faults and must not be counted as one; it is also not missing data, so it must not inflate
// "Unspecified" either. It simply does not appear on a fault chart.
export function isServiceOnlyVisit(visit) {
  return !visitFaults(visit).length && arr(visit?.service_tags).length > 0;
}

// True when a visit produced NO fault — only planned work and/or externally-caused damage. Such a visit
// contributes nothing to a fault chart, and must not be counted as "Unspecified" either: we know exactly
// what it was about, and it was not a failure of the car.
export function isNonFaultVisit(visit) {
  return !visitFaults(visit).length
    && (arr(visit?.service_tags).length > 0 || visitDamage(visit).length > 0);
}

// The fault labels ONE visit contributes to a fault chart — the single rule both the donut and its
// drill-down read from, so a slice and the visits behind it can never disagree.
//   • A visit with no fault at all — only planned service and/or externally-caused damage —
//     contributes NOTHING. It has no fault, known or unknown, so it must neither be counted nor
//     inflate "Unspecified" (which means "we don't know what this visit was about", and here we do).
//   • A visit with no fault tag but a reason falls back to that reason.
//   • Anything else is "Unspecified" — a visit that happened with nothing recorded about it.
// Returns raw, untranslated labels: these ARE the segment keys.
export function faultLabelsOf(visit) {
  let faults = visitFaults(visit);
  if (!faults.length && isNonFaultVisit(visit)) return [];
  if (!faults.length && visit?.reason) faults = [visit.reason];
  if (!faults.length) faults = ['Unspecified'];
  return faults.map((f) => String(f).trim()).filter(Boolean);
}

// The visits behind one or more fault labels — the drill-down from a donut slice back to the
// records. `keys` are the RAW labels (a segment's `key`, or every child key of a folded "Other"
// slice). A visit carrying two of the wanted faults still appears once.
export function visitsForFault(visits = [], keys = []) {
  const want = new Set((Array.isArray(keys) ? keys : [keys]).map((k) => String(k)));
  if (!want.size) return [];
  return visits.filter((v) => faultLabelsOf(v).some((l) => want.has(l)));
}

// The flat per-label tally — one count per fault label per visit. The pre-`fault_systems` behaviour,
// kept as the fallback for older payloads.
function flatTotals(visits) {
  const totals = {};
  visits.forEach((v) => {
    faultLabelsOf(v).forEach((label) => {
      totals[label] = (totals[label] || 0) + 1;
    });
  });
  return totals;
}

// What a child row is called when the visit named the SYSTEM and nothing finer. A sentinel rather than
// the system's own name: repeating it under itself reads as a duplicate, when the honest statement is
// that the record does not say which fault it was.
const UNSPECIFIED_CHILD = 'Not specified';

// Tally per SYSTEM, carrying the individual faults recorded under it.
//
// A system's `value` counts the VISITS that recorded a fault in it, not the faults — a visit that
// logged two engine faults is one engine visit, and counting it twice would make the shares sum past
// the visit count. The children carry their own per-visit counts so the expanded row still shows which
// specific fault dominates.
//
// A visit with no fault at all still contributes "Unspecified" exactly as before, so a car whose log is
// mostly blank still reads as mostly blank rather than quietly shrinking.
function systemTotals(visits, loc) {
  const bySystem = {};

  visits.forEach((v) => {
    const systems = Array.isArray(v?.fault_systems) ? v.fault_systems : [];

    if (!systems.length) {
      // Same rule as faultLabelsOf: planned-work / damage-only visits contribute nothing, everything
      // else with no fault recorded is Unspecified.
      const fallback = faultLabelsOf(v);
      fallback.forEach((label) => {
        bySystem[label] ??= { key: label, label, value: 0, kids: {} };
        bySystem[label].value += 1;
      });
      return;
    }

    systems.forEach((s) => {
      bySystem[s.key] ??= { key: s.key, label: s.label, value: 0, kids: {} };
      bySystem[s.key].value += 1;
      // No child faults means the visit recorded only the system's own name. That is worth seeing —
      // it says the record is thin — but it must NOT be shown by repeating the system name, which
      // renders as "Engine › Engine" and reads as a duplicate rather than as missing detail.
      // The child's KEY stays the raw recorded label — it is what `fault_tags` holds, so it is what the
      // drill-down matches on. Only the displayed text changes, so an unspecified row still opens onto
      // the visits behind it instead of becoming a dead end.
      const faults = Array.isArray(s.faults) && s.faults.length
        ? s.faults.map((f) => ({ key: f, label: f }))
        : [{ key: s.label, label: UNSPECIFIED_CHILD }];
      faults.forEach((f) => {
        bySystem[s.key].kids[f.key] ??= { label: f.label, value: 0 };
        bySystem[s.key].kids[f.key].value += 1;
      });
    });
  });

  return Object.values(bySystem)
    .map((s) => ({
      key: s.key,
      label: s.label,
      value: s.value,
      children: Object.entries(s.kids)
        .map(([key, kid]) => ({
          key,
          label: kid.label === UNSPECIFIED_CHILD ? loc('faultCategories.notSpecified', UNSPECIFIED_CHILD) : kid.label,
          value: kid.value,
        }))
        .sort((a, b) => b.value - a.value),
    }))
    .sort((a, b) => b.value - a.value);
}

// Per-FAULT distribution — instead of bucketing each visit into one broad mechanical category,
// this tallies the INDIVIDUAL fault tags recorded across all visits, so a car's donut shows each
// distinct fault and its share of every fault logged (the slices sum to 100%). Services are excluded
// entirely (see isServiceOnlyVisit); a visit with nothing recorded falls back to its reason, else
// "Unspecified". The long tail past `top` folds into one "Other (N types)" slice so the donut stays
// legible. Returns [{ key, label, color, value }] sorted big → small.
export function faultTagSegments(visits = [], { top = 10, tf = null, tp = null } = {}) {
  const loc = (key, en, vars) => (tf ? tf(key, en, vars) : en);

  // BY SYSTEM when the payload says which system each fault belongs to (`fault_systems`), because a
  // flat tally puts a category beside its own children: "Engine" ranked as a peer of "Engine Oil leak",
  // and one visit was counted at both grains. Grouping answers the question the reader actually has —
  // "what is wrong with the engine on this car" — and the donut's existing expand-on-click turns each
  // system row into the list of faults underneath it, with no chart changes at all.
  //
  // Falls back to the flat tally for payloads that predate the field, where the old undifferentiated
  // behaviour is still the best available.
  const grouped = visits.some((v) => Array.isArray(v?.fault_systems));
  const sorted = grouped
    ? systemTotals(visits, loc)
    : Object.entries(flatTotals(visits))
      .map(([label, value]) => ({ key: label, label, value }))
      .sort((a, b) => b.value - a.value);

  // Never show more distinct slices than the palette has hues — the excess folds into "Other".
  const limit = Math.min(top, FAULT_PALETTE.length);

  // Hues go to REAL faults in fixed palette order; "Unspecified" stays gray and consumes no slot.
  let slot = 0;
  // `key` stays the raw English sentinel (it identifies the slice); only the displayed label is
  // localized, so colour assignment and any caller keyed on 'Unspecified' keep working.
  const withColor = (s) => ({
    ...s,
    label: s.label === 'Unspecified' ? loc('faultCategories.unspecified', 'Unspecified') : s.label,
    color: s.label === 'Unspecified' ? UNSPECIFIED_COLOR : FAULT_PALETTE[slot++],
  });
  if (sorted.length <= limit) return sorted.map(withColor);

  const head = sorted.slice(0, limit - 1).map(withColor);
  const tail = sorted.slice(limit - 1);
  head.push({
    key: '__other',
    label: tp ? tp('faultCategories.otherTypes', tail.length) : `Other (${tail.length} types)`,
    value: tail.reduce((a, s) => a + s.value, 0),
    color: OTHER_COLOR,
    // The folded long-tail, so the legend's "Other" row can expand to reveal each type's share.
    children: tail.map((s) => ({ key: s.key, label: s.label, value: s.value })),
  });
  return head;
}

// Aggregate a list of visits into donut segments.
//   metric: 'count'  → one per visit (fault distribution)
//           'cost'   → visit.total summed  (expense architecture)
// Returns [{ key, label, color, value }] sorted big → small, zero buckets dropped.
export function categorySegments(visits = [], metric = 'count', { tf = null } = {}) {
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
    .map((k) => ({
      key: k,
      label: tf ? tf(`faultCategories.${k}`, meta[k].label) : meta[k].label,
      color: meta[k].color,
      value: Math.round(totals[k] * 100) / 100,
    }))
    .filter((s) => s.value > 0)
    .sort((a, b) => b.value - a.value);
}
