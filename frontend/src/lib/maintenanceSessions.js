// "Smart Maintenance Timer" — a maintenance ticket is NOT one continuous block of shop time. When the
// car is pulled out for a rental the clock PAUSES; it RESUMES only when the car returns to the garage.
// So a ticket is modelled as an array of in-shop SESSIONS — [{ start, end }, { start, end }, …] — and
// the rental gaps between them are simply absent. The real downtime is the sum of the session lengths,
// so rental days are never counted as workshop time.
//
//   const sessions = buildShopSessions('2025-10-01', '2025-10-12', [{ start: '2025-10-03', end: '2025-10-08' }]);
//   // → [{ start:'2025-10-01', end:'2025-10-03', days:2, label:'initial' },
//   //    { start:'2025-10-08', end:'2025-10-12', days:4, label:'after rental' }]
//   calculateTotalShopDays(sessions);   // → 6   (the 5 rental days are excluded)

const DAY_MS = 86400000;

// A calendar date (YYYY-MM-DD string or Date) → an integer day index at UTC midnight. null on bad input.
function dayIndex(d) {
  if (!d) return null;
  const iso = d instanceof Date ? d.toISOString().slice(0, 10) : String(d).slice(0, 10);
  const t = Date.parse(`${iso}T00:00:00Z`);
  return Number.isNaN(t) ? null : Math.round(t / DAY_MS);
}

function indexToISO(i) {
  return new Date(i * DAY_MS).toISOString().slice(0, 10);
}

// Today as a day index (the local calendar day, so an open session ends "today" correctly).
function todayIndex() {
  const n = new Date();
  return Math.round(Date.UTC(n.getFullYear(), n.getMonth(), n.getDate()) / DAY_MS);
}

export function isoToday() {
  return indexToISO(todayIndex());
}

// Whole days a single session lasted. An open session (no end) runs to today. Never negative.
export function sessionDays(session) {
  const a = dayIndex(session?.start);
  if (a == null) return 0;
  const b = session?.end ? dayIndex(session.end) : todayIndex();
  return Math.max(0, b - a);
}

// ── THE CORE FUNCTION (PURE) ──────────────────────────────────────────────────
// Real shop days = days the car was STRICTLY in the workshop and NOT on a rental. Rental is King: a
// day where the car is also on a rental counts as rental time and is NEVER counted as shop time.
// Purity is enforced HERE (the rentals are punched out internally), not left to the caller.
//   calculateTotalShopDays({ start, end }, [{ start, end }, …])  → pure shop-day count
export function calculateTotalShopDays(maintenance, rentals = []) {
  const sessions = buildShopSessions(maintenance?.start, maintenance?.end, rentals);
  return sessions.reduce((total, s) => total + s.days, 0);
}

// ── AUTOMATIC PAUSE / RESUME ─────────────────────────────────────────────────
// Derive the in-shop sessions for a maintenance window by PUNCHING OUT every rental that falls inside
// it. rentals = [{ start, end }] (an open rental's end → today). Returns ordered
// [{ start, end, days, label }] sessions, where `label` is 'initial' (opens the ticket) or
// 'after rental' (resumes once the car came back).
export function buildShopSessions(maintStart, maintEnd, rentals = []) {
  const start = dayIndex(maintStart);
  if (start == null) return [];
  const end = maintEnd ? dayIndex(maintEnd) : todayIndex();
  if (end <= start) return [];

  // Rental intervals clipped to the maintenance window, empties dropped, sorted by start.
  const pauses = rentals
    .map((r) => [
      Math.max(start, dayIndex(r.start) ?? start),
      Math.min(end, r.end ? dayIndex(r.end) : todayIndex()),
    ])
    .filter(([a, b]) => b > a)
    .sort((x, y) => x[0] - y[0]);

  // Walk the timeline, emitting a shop session for each gap between the rental pauses.
  const raw = [];
  let cursor = start;
  let pausedBefore = false;
  for (const [a, b] of pauses) {
    if (a > cursor) raw.push({ a: cursor, b: a, pausedBefore });
    cursor = Math.max(cursor, b);
    pausedBefore = true;            // a rental has now interrupted the ticket
  }
  if (end > cursor) raw.push({ a: cursor, b: end, pausedBefore });

  // Label: the session that opens the ticket = 'initial'; each one resuming after a rental = 'after
  // rental' (numbered when there is more than one resume).
  const resumes = raw.filter((s) => s.pausedBefore).length;
  let n = 0;
  return raw.map((s) => {
    let label;
    if (!s.pausedBefore) {
      label = 'initial';
    } else {
      n += 1;
      label = resumes > 1 ? `after rental ${n}` : 'after rental';
    }
    return { start: indexToISO(s.a), end: indexToISO(s.b), days: s.b - s.a, label };
  });
}

// "2 days initial + 5 days after rental"
export function describeShopSessions(sessions = []) {
  if (!sessions.length) return 'no shop time yet';
  return sessions.map((s) => `${s.days} day${s.days === 1 ? '' : 's'} ${s.label}`).join(' + ');
}

// ── LIVE STATE MACHINE (when the events happen in real time) ──────────────────
// Car arrives at the shop → open a session (no-op if one is already open).
export function startSession(sessions = [], today = isoToday()) {
  if (sessions.some((s) => !s.end)) return sessions;     // already in the shop
  return [...sessions, { start: today, end: null }];
}
// Car is pulled out for a rental → close the open session (PAUSE the clock).
export function pauseForRental(sessions = [], today = isoToday()) {
  return sessions.map((s) => (s.end ? s : { ...s, end: today }));
}
// Car returns to the shop → open a fresh session (RESUME the clock).
export function resumeFromRental(sessions = [], today = isoToday()) {
  return startSession(sessions, today);
}
