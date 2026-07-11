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

// A duration in whole SECONDS -> a compact "2h 10m" / "45m" / "1d 3h" / "30s". null/undefined -> "—".
// (Distinct from fmtDuration below, which takes two timestamps; this takes a pre-computed second count.)
export const fmtSeconds = (seconds) => {
  if (seconds == null) return '—';
  const s = Math.max(0, Math.round(seconds));
  const d = Math.floor(s / 86400);
  const h = Math.floor((s % 86400) / 3600);
  const m = Math.floor((s % 3600) / 60);
  if (d) return `${d}d ${h}h`;
  if (h) return `${h}h ${m}m`;
  if (m) return `${m}m`;
  return `${s}s`;
};

// A full date/datetime value -> the local time portion only, "10:48 PM" (12-hour, matches fmtTime).
// Unlike fmtTime (which parses a bare "HH:MM" string), this parses a real Date/ISO value and shows
// it in the viewer's local timezone — use it to hang a time off a timestamp beside fmtDate.
export const fmtClock = (value) => {
  if (!value) return '';
  const d = new Date(value);
  if (isNaN(d)) return '';
  let h = d.getHours();
  const min = String(d.getMinutes()).padStart(2, '0');
  const ap = h >= 12 ? 'PM' : 'AM';
  h %= 12;
  if (h === 0) h = 12;
  return `${h}:${min} ${ap}`;
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

// "2026-06-30T10:00:00Z" -> "just now" / "5m ago" / "3h ago" / "2d ago" / "3mo ago".
export const fmtAgo = (value) => {
  if (!value) return null;
  const d = new Date(value);
  if (isNaN(d)) return null;
  const secs = Math.round((Date.now() - d.getTime()) / 1000);
  if (secs < 45) return 'just now';
  const mins = Math.floor(secs / 60);
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  const days = Math.floor(hrs / 24);
  if (days < 30) return `${days}d ago`;
  const months = Math.floor(days / 30);
  if (months < 12) return `${months}mo ago`;
  return `${Math.floor(months / 12)}y ago`;
};

// A "days left" pill descriptor (red overdue / amber soon / green ok).
export const dayBadge = (d) => {
  if (d === null || d === undefined) return { text: '—', tone: 'gray' };
  if (d < 0) return { text: `${Math.abs(d)}d ago`, tone: 'red' };
  if (d <= 7) return { text: `${d}d`, tone: 'amber' };
  if (d <= 30) return { text: `${d}d`, tone: 'blue' };
  return { text: `${d}d`, tone: 'green' };
};
