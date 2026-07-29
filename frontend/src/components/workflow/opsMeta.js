// Shared vocabulary for the Operations view of a maintenance ticket — the `ops` block the backend
// attaches to every board ticket (see MaintenanceOpsCardService). One place for the tones, the urgency
// ranking and the attention filters, so the KPI tiles, the filter chips, the card and the drawer can
// never disagree about which cars need a manager's eye.
//
// Nothing here invents data: every helper reads a field the server already computed.

// A backend tone key → the Tailwind classes for a soft chip and for a solid accent.
export const TONE = {
  red:     { chip: 'bg-red-50 text-red-700 ring-red-200',             dot: 'bg-red-500',     bar: '#ef4444' },
  orange:  { chip: 'bg-orange-50 text-orange-700 ring-orange-200',    dot: 'bg-orange-500',  bar: '#f97316' },
  amber:   { chip: 'bg-amber-50 text-amber-700 ring-amber-200',       dot: 'bg-amber-500',   bar: '#f59e0b' },
  violet:  { chip: 'bg-violet-50 text-violet-700 ring-violet-200',    dot: 'bg-violet-500',  bar: '#8b5cf6' },
  blue:    { chip: 'bg-blue-50 text-blue-700 ring-blue-200',          dot: 'bg-blue-500',    bar: '#3b82f6' },
  cyan:    { chip: 'bg-cyan-50 text-cyan-700 ring-cyan-200',          dot: 'bg-cyan-500',    bar: '#06b6d4' },
  emerald: { chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200', dot: 'bg-emerald-500', bar: '#10b981' },
  green:   { chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200', dot: 'bg-emerald-500', bar: '#10b981' },
  slate:   { chip: 'bg-slate-100 text-slate-600 ring-slate-200',      dot: 'bg-slate-400',   bar: '#64748b' },
};

export const tone = (key) => TONE[key] || TONE.slate;

// Responsibility role → how the accountability line is labelled + drawn.
export const ROLE = {
  inspector:  { label: 'Inspector',  waiting: 'Waiting for inspector' },
  supervisor: { label: 'Supervisor', waiting: 'Waiting for supervisor' },
  driver:     { label: 'Driver',     waiting: 'Waiting for driver' },
  garage:     { label: 'Garage',     waiting: 'Not dispatched' },
  none:       { label: 'On hold',    waiting: 'Nobody assigned' },
};

// The attention filters across the top of the board. `test` reads ONLY the server-computed ops block, so
// a chip's count and the cards it reveals are always the same set.
export const ATTENTION_FILTERS = [
  { key: 'all',        label: 'All cars',        tone: 'slate',   test: () => true },
  { key: 'overdue',    label: 'ETA overdue',     tone: 'red',     test: (o) => (o?.timing?.days_over ?? 0) > 0 },
  { key: 'no_update',  label: 'No updates',      tone: 'amber',   test: (o) => hasAlert(o, 'no_checkpoint') },
  { key: 'parts',      label: 'Waiting on parts', tone: 'violet', test: (o) => (o?.parts?.length ?? 0) > 0 },
  { key: 'approval',   label: 'Awaiting approval', tone: 'violet',
    test: (o) => ['repair_approval', 'routing_approval', 'customer_decision', 'insurance', 'waiting_review']
      .includes(o?.blocker?.key) },
];

/** Does this ops block carry an alert of the given key? */
export function hasAlert(ops, key) {
  return (ops?.alerts || []).some((a) => a.key === key);
}

/**
 * How loudly this car is asking for attention. Higher = shows first. Built from the facts, in the order a
 * manager triages them: days past the promised date, then days of silence, then a hard blocker, then a
 * critical fault. Cars with nothing wrong fall to the bottom on plain time-in-shop.
 */
export function urgency(tk) {
  const o = tk?.ops;
  if (!o) return 0;
  const over = o.timing?.days_over ?? 0;
  const silent = o.timing?.days_since_checkpoint ?? 0;
  const blocked = o.blocker ? 1 : 0;
  const red = (o.alerts || []).filter((a) => a.tone === 'red').length;
  return over * 100 + red * 60 + blocked * 25 + silent * 5 + (o.timing?.days_in_maintenance ?? 0);
}

/** "3d" / "12d" / "today" from a whole-day count. null → null (the caller renders "—"). */
export function days(n) {
  if (n === null || n === undefined) return null;
  return n === 0 ? 'today' : `${n}d`;
}

/** A short, locale-formatted day ("12 Aug") from an ISO date/datetime. */
export function shortDate(iso) {
  if (!iso) return null;
  try {
    return new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
  } catch {
    return iso;
  }
}
