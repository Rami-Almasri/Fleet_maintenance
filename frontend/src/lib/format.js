// Shared formatting helpers used across pages.

export const aed = (n) =>
  'AED ' + Number(n || 0).toLocaleString('en-AE', { maximumFractionDigits: 0 });

export const aed2 = (n) =>
  'AED ' + Number(n || 0).toLocaleString('en-AE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export const num = (n) => Number(n || 0).toLocaleString();

// "2025-05-07T00:00:00.000000Z" | "2025-05-07" -> "07 May 2025"
export const fmtDate = (value) => {
  if (!value) return '—';
  const d = new Date(value);
  if (isNaN(d)) return String(value);
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

// "22:48:00" | "12:24" -> "10:48 PM" (12-hour clock; leaves unparseable values as-is)
export const fmtTime = (t) => {
  if (!t) return '—';
  const m = String(t).match(/^(\d{1,2}):(\d{2})/);
  if (!m) return String(t);
  let h = Number(m[1]);
  const ap = h >= 12 ? 'PM' : 'AM';
  h %= 12;
  if (h === 0) h = 12;
  return `${h}:${m[2]} ${ap}`;
};

// Fold a date ("2026-06-18T..." | "2026-06-18") and a "HH:MM[:SS]" time into one local Date.
export const combineDateTime = (date, time) => {
  if (!date) return null;
  const ymd = String(date).slice(0, 10);
  const t = time && /^\d{1,2}:\d{2}/.test(time) ? (String(time).length === 5 ? `${time}:00` : time) : '00:00:00';
  const d = new Date(`${ymd}T${t}`);
  return isNaN(d) ? null : d;
};

// Elapsed time between two Dates -> "1d 13h 36m" (drops leading zero units).
export const fmtDuration = (start, end) => {
  if (!start || !end) return null;
  let mins = Math.round((end - start) / 60000);
  if (mins < 0) return null;
  const d = Math.floor(mins / 1440); mins -= d * 1440;
  const h = Math.floor(mins / 60); const m = mins - h * 60;
  const parts = [];
  if (d) parts.push(`${d}d`);
  if (h) parts.push(`${h}h`);
  if (m || parts.length === 0) parts.push(`${m}m`);
  return parts.join(' ');
};

// A "days left" pill descriptor (red overdue / amber soon / green ok).
export const dayBadge = (d) => {
  if (d === null || d === undefined) return { text: '—', tone: 'gray' };
  if (d < 0) return { text: `${Math.abs(d)}d ago`, tone: 'red' };
  if (d <= 7) return { text: `${d}d`, tone: 'amber' };
  if (d <= 30) return { text: `${d}d`, tone: 'blue' };
  return { text: `${d}d`, tone: 'green' };
};
