// Checkpoint Compliance (/oversight/checkpoint-compliance) — did the daily chase actually get answered?
//
// Every day a car is near or past the date it was promised back, the system reminds the responsible
// supervisor to confirm the date or give a new one with a reason. This board lists the cars where that
// reminder went out and NOTHING came back: who was notified, on which days, how long the silence has run,
// and — for context — every reason that car's date has already moved for. A row past the tolerated silence
// (one day by default) is flagged red.
//
// Read-only. The fix is filing the answer, so every row deep-links to the car's checkpoint form.
// Backed by GET /Oversight/checkpoint-compliance.
//
// Presentation notes, because this page is deliberately louder than its siblings:
//   • The header is a fixed dark band (navy/steel — the one palette that never inverts) so the page reads
//     as a control board, and so the one number that matters fleet-wide — the share of reminders that ever
//     got an answer — is the largest object on screen.
//   • Silence is the finding, so it is quantised into four buckets (today / 1 day / 2–3 / 4+) that colour
//     the row rail, drive the header histogram, and double as the filter. One vocabulary, three surfaces.
//   • Every row carries a chase strip: one mark per day the supervisor was pushed. Six unanswered marks
//     reads as neglect in a way "days_reminded: 6" never does.

import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import Icon from '../../components/ui/Icon';
import { Skeleton } from '../../components/ui/Skeleton';
import { Card, SearchInput, EmptyState, ErrorState } from '../../components/ui/Misc';
import CountUp from '../../components/ui/CountUp';
import { getCheckpointCompliance, useCheckpointVocab } from '../../lib/maintenanceCheckpoints';
import { fmtDate } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

// Counts stay Latin-digit under Arabic so the tabular-nums columns keep lining up.
const numLocale = (lang) => (lang === 'ar' ? 'ar-AE-u-nu-latn' : undefined);

// How loud the last ask was — the same ladder the scan fires on. The chip travels with the key; the
// words are resolved at render, so the resolver is threaded in.
const LEVEL_CHIP = {
  request:   'bg-sky-50 text-sky-700 ring-sky-200',
  reminder:  'bg-amber-50 text-amber-700 ring-amber-200',
  due_today: 'bg-orange-50 text-orange-700 ring-orange-200',
  overdue:   'bg-red-50 text-red-700 ring-red-200',
};
const levelLabel = (t, v) => ({
  request: t('First ask'),
  reminder: t('Reminder'),
  due_today: t('Due today'),
  overdue: t('Overdue'),
}[v]);

// The single silence vocabulary: bucket → colour, used by the histogram, the filter and the row rail.
// `test` runs on days_unanswered, which is 0 on the day the first unanswered reminder went out.
const BUCKETS = [
  { key: 'today', test: (d) => d === 0,
    rail: 'bg-sky-400',    dot: 'bg-sky-400',    text: 'text-sky-700',    soft: 'bg-sky-50 ring-sky-200' },
  { key: 'd1',    test: (d) => d === 1,
    rail: 'bg-amber-400',  dot: 'bg-amber-400',  text: 'text-amber-700',  soft: 'bg-amber-50 ring-amber-200' },
  { key: 'd23',   test: (d) => d >= 2 && d <= 3,
    rail: 'bg-orange-500', dot: 'bg-orange-500', text: 'text-orange-700', soft: 'bg-orange-50 ring-orange-200' },
  { key: 'd4',    test: (d) => d >= 4,
    rail: 'bg-rose-500',   dot: 'bg-rose-500',   text: 'text-rose-700',   soft: 'bg-rose-50 ring-rose-200' },
];
const bucketOf = (days) => BUCKETS.find((b) => b.test(days || 0)) || BUCKETS[0];

const bucketLabel = (t, key) => ({
  today: t('Today'), d1: t('1 day'), d23: t('2–3 days'), d4: t('4+ days'),
}[key]);
const bucketHint = (t, key) => ({
  today: t('Asked today, no answer yet'),
  d1: t('Silent since yesterday'),
  d23: t('Silent two to three days'),
  d4: t('Silent four days or more'),
}[key]);

// `other` carries the supervisor's own words, which stay as typed; only the blank-fallback translates.
const reasonText = (r, delayReasonLabel, otherWord) => (r.delay_reason === 'other'
  ? (r.reason_other || otherWord)
  : (delayReasonLabel(r.delay_reason) || '—'));

// Has this car's promised date actually shifted? Drives the struck-through "originally" line.
const moved = (r) => !!r.original_promised_on && r.original_promised_on !== r.current_promised_on;

// How far the promise slipped, in whole days — the number the "originally → now" track is really about.
const slipDays = (r) => {
  if (!moved(r)) return 0;
  const a = new Date(r.original_promised_on);
  const b = new Date(r.current_promised_on);
  if (isNaN(a) || isNaN(b)) return 0;
  return Math.round((b - a) / 86400000);
};

const initials = (name) => String(name || '?').trim().split(/\s+/).slice(0, 2)
  .map((w) => w[0]).join('').toUpperCase();

/* ------------------------------------------------------------------ header */

// The one fleet-wide number, drawn as an arc. Lives on the dark band, so its colours are literal
// rather than themed — the band never inverts.
function ResponseArc({ pct }) {
  const { t } = useI18n();
  const size = 148, stroke = 10, r = (size - stroke) / 2, c = 2 * Math.PI * r;
  const known = pct != null;
  const [shown, setShown] = useState(0);
  useEffect(() => {
    const id = requestAnimationFrame(() => setShown(known ? Math.max(0, Math.min(100, pct)) : 0));
    return () => cancelAnimationFrame(id);
  }, [pct, known]);
  const tone = !known ? '#7c8aa3' : pct >= 85 ? '#34d399' : pct >= 60 ? '#fbbf24' : '#fb7185';
  return (
    <div className="relative inline-flex shrink-0 items-center justify-center" style={{ width: size, height: size }}>
      <svg width={size} height={size} className="-rotate-90">
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="rgb(255 255 255 / 0.09)" strokeWidth={stroke} />
        <circle
          cx={size / 2} cy={size / 2} r={r} fill="none" stroke={tone} strokeWidth={stroke} strokeLinecap="round"
          strokeDasharray={c} strokeDashoffset={c * (1 - shown / 100)}
          style={{ transition: 'stroke-dashoffset 1.1s cubic-bezier(.21,1.02,.73,1)' }}
        />
      </svg>
      <div className="absolute inset-0 flex flex-col items-center justify-center">
        <span className="font-display text-3xl font-bold tabular-nums text-white">
          {known ? <CountUp value={pct} format={(n) => `${Math.round(n)}%`} /> : '—'}
        </span>
        <span className="mt-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-steel-400">{t('Answered')}</span>
      </div>
    </div>
  );
}

// A metric cell on the dark band. `tone` is only ever spent on the numbers that mean something is wrong.
function BandStat({ value, label, hint, tone = 'plain', pulse = false }) {
  const { lang } = useI18n();
  const colour = tone === 'bad' ? 'text-rose-400' : tone === 'warn' ? 'text-amber-300' : 'text-white';
  return (
    <div className="min-w-0 px-5 py-4">
      <div className="flex items-center gap-2">
        {pulse && <span className="relative flex h-2 w-2 shrink-0">
          <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-rose-400 opacity-70" />
          <span className="relative inline-flex h-2 w-2 rounded-full bg-rose-500" />
        </span>}
        <p className="truncate text-[10px] font-semibold uppercase tracking-[0.14em] text-steel-400">{label}</p>
      </div>
      <p className={`mt-1 font-display text-3xl font-bold tabular-nums ${colour}`}>
        <CountUp value={value} format={(n) => Math.round(n).toLocaleString(numLocale(lang))} />
      </p>
      {hint && <p className="mt-0.5 truncate text-[11px] text-steel-400">{hint}</p>}
    </div>
  );
}

// Where the silence sits. Purely derived from the rows on screen, so it can never disagree with them.
function SilenceHistogram({ counts, total, active, onPick }) {
  const { t } = useI18n();
  if (!total) return null;
  return (
    <div className="px-5 pb-5">
      <div className="mb-2 flex items-baseline justify-between">
        <p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-steel-400">{t('How long they have been silent')}</p>
        <p className="text-[11px] text-steel-400">
          {total === 1 ? t('1 car waiting on an answer') : t('{n} cars waiting on an answer', { n: total })}
        </p>
      </div>
      <div className="flex h-2 w-full overflow-hidden rounded-full bg-white/5">
        {BUCKETS.map((b) => {
          const n = counts[b.key] || 0;
          if (!n) return null;
          return (
            <button
              key={b.key} type="button" onClick={() => onPick(active === b.key ? null : b.key)}
              title={`${n} · ${bucketHint(t, b.key)}`}
              aria-label={t('{n} cars — {hint}', { n, hint: bucketHint(t, b.key) })}
              style={{ width: `${(n / total) * 100}%` }}
              className={`h-full transition-opacity ${b.rail} ${active && active !== b.key ? 'opacity-30' : 'opacity-100'} hover:opacity-80`}
            />
          );
        })}
      </div>
      <div className="mt-2.5 flex flex-wrap gap-x-4 gap-y-1.5">
        {BUCKETS.map((b) => (
          <button
            key={b.key} type="button" onClick={() => onPick(active === b.key ? null : b.key)}
            aria-pressed={active === b.key}
            className={`inline-flex items-center gap-1.5 text-[11px] transition ${
              active === b.key ? 'text-white' : 'text-steel-400 hover:text-steel-200'}`}
          >
            <span className={`h-1.5 w-1.5 rounded-full ${b.dot} ${counts[b.key] ? '' : 'opacity-30'}`} />
            {bucketLabel(t, b.key)}
            <span className="font-semibold tabular-nums text-white/80">{counts[b.key] || 0}</span>
          </button>
        ))}
      </div>
    </div>
  );
}

/* --------------------------------------------------------------------- row */

// One mark per day the supervisor was pushed and said nothing. Reads as neglect at a glance in a way
// a count never does; capped so a badly-stuck car doesn't stretch the row.
function ChaseStrip({ days, tone }) {
  const { t } = useI18n();
  const shown = Math.min(days || 0, 12);
  return (
    <div
      className="flex items-center gap-[3px]"
      title={days === 1 ? t('Reminded on 1 day') : t('Reminded on {n} days', { n: days })}
    >
      {Array.from({ length: shown }).map((_, i) => (
        <span key={i} className={`h-3.5 w-1.5 rounded-[2px] ${tone}`} style={{ opacity: 0.45 + (0.55 * (i + 1)) / shown }} />
      ))}
      {days > 12 && <span className="ms-1 text-[10px] font-semibold text-slate-400">+{days - 12}</span>}
    </div>
  );
}

// The three dates, named. Collapsing them into one "promised back" reads as a contradiction the
// moment a car has been rescheduled.
function PromiseTrack({ r }) {
  const { t } = useI18n();
  const slip = slipDays(r);
  return (
    <div>
      <p className="mb-1.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">{t('Promised back')}</p>
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
        {moved(r) && (
          <>
            <span className="text-xs font-medium text-slate-400 line-through decoration-slate-300">
              {fmtDate(r.original_promised_on)}
            </span>
            <Icon.ArrowRight className="h-3 w-3 shrink-0 text-slate-300 rtl:rotate-180" />
          </>
        )}
        <span className="text-sm font-bold text-slate-900">
          {r.current_promised_on ? fmtDate(r.current_promised_on) : '—'}
        </span>
        {slip > 0 && (
          <span className="inline-flex items-center rounded-md bg-amber-50 px-1.5 py-0.5 text-[10px] font-bold text-amber-700 ring-1 ring-inset ring-amber-200">
            {t('+{n}d', { n: slip })}
          </span>
        )}
      </div>
      <div className="mt-1 space-y-0.5">
        {r.reminded_about_on && r.reminded_about_on !== r.current_promised_on && (
          <p className="text-[11px] text-slate-400">{t('Chased about {date}', { date: fmtDate(r.reminded_about_on) })}</p>
        )}
        {r.last_rescheduled_at && (
          <p className="text-[11px] text-amber-700">{t('Last moved {date}', { date: fmtDate(r.last_rescheduled_at) })}</p>
        )}
      </div>
    </div>
  );
}

function Row({ r, open, onToggle }) {
  const { t } = useI18n();
  const { delayReasonLabel } = useCheckpointVocab();
  const level = {
    label: levelLabel(t, r.last_level) || r.last_level || '—',
    chip: LEVEL_CHIP[r.last_level] || 'bg-slate-50 text-slate-600 ring-slate-200',
  };
  const b = bucketOf(r.days_unanswered);
  const notified = r.notified || [];

  return (
    <div className={`group relative overflow-hidden rounded-2xl border bg-white shadow-soft transition
                     hover:shadow-card ${r.breached ? 'border-rose-200' : 'border-slate-200/60'}`}>
      {/* Silence rail — the row's severity, in the page's one colour vocabulary. */}
      <span className={`absolute inset-y-0 start-0 w-1 ${b.rail}`} aria-hidden />

      <div className="ps-5 pe-4 py-4">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-start">
          {/* Vehicle */}
          <div className="min-w-0 lg:w-52 lg:shrink-0">
            <div className="flex items-center gap-2">
              <Link
                to={`/maintenance-workflow/${r.ticket_id}`}
                className="font-mono text-base font-bold tracking-tight text-slate-900 transition-colors hover:text-indigo-600"
              >
                {r.plate_no || `#${r.ticket_id}`}
              </Link>
              <Icon.ArrowUpRight className="h-3.5 w-3.5 shrink-0 text-slate-300 opacity-0 transition-opacity group-hover:opacity-100" />
            </div>
            {r.car && <p className="truncate text-xs text-slate-500">{r.car}</p>}
            {r.garage && (
              <p className="mt-1.5 inline-flex max-w-full items-center gap-1 rounded-md bg-slate-50 px-1.5 py-0.5 text-[11px] font-medium text-slate-600 ring-1 ring-inset ring-slate-200/70">
                <Icon.Wrench className="h-3 w-3 shrink-0 text-slate-400" />
                <span className="truncate">{r.garage}</span>
              </p>
            )}
          </div>

          <div className="lg:w-52 lg:shrink-0"><PromiseTrack r={r} /></div>

          {/* The silence — the finding itself */}
          <div className="lg:w-60 lg:shrink-0">
            <p className="mb-1.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">{t('Unanswered')}</p>
            <div className="flex items-baseline gap-1.5">
              <span className={`font-display text-2xl font-bold tabular-nums ${b.text}`}>
                {r.days_unanswered === 0 ? t('Today') : r.days_unanswered}
              </span>
              {r.days_unanswered > 0 && (
                <span className={`text-xs font-semibold ${b.text}`}>{r.days_unanswered === 1 ? t('day') : t('days')}</span>
              )}
              <span className={`ms-1 inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 ring-inset ${level.chip}`}>
                {level.label}
              </span>
            </div>
            <div className="mt-1.5 flex items-center gap-2">
              <ChaseStrip days={r.days_reminded} tone={b.rail} />
              <span className="text-[11px] text-slate-400">
                {r.days_reminded === 1
                  ? t('1 push · {from} → {to}', { from: fmtDate(r.first_reminder_on), to: fmtDate(r.last_reminder_on) })
                  : t('{n} pushes · {from} → {to}', {
                    n: r.days_reminded, from: fmtDate(r.first_reminder_on), to: fmtDate(r.last_reminder_on),
                  })}
              </span>
            </div>
          </div>

          {/* Who was told, and what this car has answered before */}
          <div className="min-w-0 flex-1">
            <p className="mb-1.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">{t('Notified · never replied')}</p>
            {notified.length === 0 ? (
              <span className="text-xs text-slate-400">—</span>
            ) : (
              <div className="flex flex-wrap items-center gap-1.5">
                {notified.map((n) => (
                  <span
                    key={n} title={n}
                    className="inline-flex items-center gap-1.5 rounded-full bg-indigo-50 py-0.5 pe-2.5 ps-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-100"
                  >
                    <span className="flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-[9px] font-bold text-white">
                      {initials(n)}
                    </span>
                    {n}
                  </span>
                ))}
              </div>
            )}
            <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-500">
              <span>
                {r.checkpoint_count === 1
                  ? t('1 answer on record')
                  : t('{n} answers on record', { n: r.checkpoint_count })}
              </span>
              <span className="text-slate-300">·</span>
              <span className={r.reschedule_count > 0 ? 'font-semibold text-amber-700' : ''}>
                {t('Date moved {n}×', { n: r.reschedule_count })}
              </span>
              {r.reasons?.length > 0 && (
                <button
                  type="button" onClick={onToggle} aria-expanded={open}
                  className="inline-flex items-center gap-1 font-semibold text-indigo-600 transition-colors hover:text-indigo-700"
                >
                  {open ? t('Hide reasons') : t('Why it moved')}
                  <Icon.ChevronDown className={`h-3 w-3 transition-transform ${open ? 'rotate-180' : ''}`} />
                </button>
              )}
            </div>
          </div>

          {/* The fix is filing the answer, so the row ends in the door to the form. */}
          <div className="lg:shrink-0 lg:self-center">
            <Link
              to={`/maintenance-workflow/${r.ticket_id}`}
              className="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700"
            >
              {t('Chase it')}
              <Icon.ArrowRight className="h-3.5 w-3.5 rtl:rotate-180" />
            </Link>
          </div>
        </div>

        {/* Every reason this car's date has moved for — newest first. */}
        {open && r.reasons?.length > 0 && (
          <ol className="mt-4 space-y-2 border-t border-slate-100 pt-4 animate-fade">
            {r.reasons.map((x, i) => (
              <li key={i} className="relative flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg bg-amber-50/60 py-2 pe-3 ps-3 text-xs ring-1 ring-inset ring-amber-100">
                <span className="font-semibold text-amber-800">
                  {reasonText(x, delayReasonLabel, delayReasonLabel('other'))}
                </span>
                <span className="text-slate-500">
                  {x.previous_date
                    ? t('{from} → {to}', { from: fmtDate(x.previous_date), to: fmtDate(x.next_date) })
                    : fmtDate(x.next_date)}
                </span>
                <span className="ms-auto text-slate-400">{x.by || t('Unknown')} · {fmtDate(x.at)}</span>
              </li>
            ))}
          </ol>
        )}
      </div>
    </div>
  );
}

/* -------------------------------------------------------------------- page */

export default function CheckpointCompliance() {
  const { t, lang } = useI18n();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [q, setQ] = useState('');
  const [breachedOnly, setBreachedOnly] = useState(false);
  const [bucket, setBucket] = useState(null);
  const [expanded, setExpanded] = useState(null);

  useEffect(() => {
    let alive = true;
    getCheckpointCompliance()
      .then((d) => { if (alive) setData(d); })
      .catch(() => { if (alive) setError(true); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, []);

  const all = useMemo(() => data?.rows || [], [data]);

  const counts = useMemo(() => {
    const c = {};
    all.forEach((r) => { const k = bucketOf(r.days_unanswered).key; c[k] = (c[k] || 0) + 1; });
    return c;
  }, [all]);

  const rows = useMemo(() => {
    let r = all;
    if (breachedOnly) r = r.filter((x) => x.breached);
    if (bucket) r = r.filter((x) => bucketOf(x.days_unanswered).key === bucket);
    const term = q.trim().toLowerCase();
    if (term) {
      r = r.filter((x) => `${x.plate_no || ''} ${x.car || ''} ${x.garage || ''} ${(x.notified || []).join(' ')}`
        .toLowerCase().includes(term));
    }
    return r;
  }, [all, breachedOnly, bucket, q]);

  const summary = data?.summary || {};
  const alertDays = data?.alert_days ?? 1;
  const unassigned = data?.unassigned || [];
  const filtered = rows.length !== all.length;

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1240px] space-y-5 px-4 sm:px-6 lg:px-8">
        <Link to="/apps/reports" className="inline-flex items-center gap-1 text-xs font-medium text-slate-400 transition-colors hover:text-slate-600">
          <Icon.ArrowRight className="h-3 w-3 rotate-180 rtl:rotate-0" /> {t('Reports')}
        </Link>

        {/* ---- The command band. Fixed navy/steel chrome: it must read the same in either theme. ---- */}
        <section className="relative overflow-hidden rounded-3xl bg-navy-900 shadow-card ring-1 ring-white/10">
          {/* Depth: one warm bloom behind the arc, one cool bloom behind the title, a faint grid over both. */}
          <div className="pointer-events-none absolute -end-24 -top-28 h-72 w-72 rounded-full bg-rose-500/10 blur-3xl" aria-hidden />
          <div className="pointer-events-none absolute -start-32 bottom-0 h-64 w-96 rounded-full bg-indigo-500/10 blur-3xl" aria-hidden />
          <div
            className="pointer-events-none absolute inset-0 opacity-[0.35]"
            style={{
              backgroundImage: 'linear-gradient(rgb(255 255 255 / .04) 1px, transparent 1px), linear-gradient(90deg, rgb(255 255 255 / .04) 1px, transparent 1px)',
              backgroundSize: '46px 46px',
            }}
            aria-hidden
          />

          <div className="relative flex flex-col gap-6 p-6 lg:flex-row lg:items-start lg:gap-10">
            <div className="min-w-0 flex-1">
              <p className="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.2em] text-steel-400">
                <span className="h-1.5 w-1.5 rounded-full bg-emerald-400" />
                {t('Oversight · the daily chase')}
              </p>
              <h1 className="mt-2 font-display text-[26px] font-bold leading-tight tracking-tight text-white sm:text-3xl">
                {t('Reminders nobody answered')}
              </h1>
              <p className="mt-2 max-w-2xl text-sm leading-relaxed text-steel-300">
                {alertDays === 1
                  ? t('Every car below had its supervisor reminded that it was due back — and nothing came back. No confirmation, no new date, no reason. Silence past 1 day is treated as a breach.')
                  : t('Every car below had its supervisor reminded that it was due back — and nothing came back. No confirmation, no new date, no reason. Silence past {n} days is treated as a breach.', { n: alertDays })}
              </p>
              <p className="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-white/5 px-2.5 py-1 text-[11px] text-steel-300 ring-1 ring-inset ring-white/10">
                <Icon.Info className="h-3.5 w-3.5 shrink-0 text-steel-400" />
                {t('Source: reminder delivery receipts, live. Answering closes the row.')}
              </p>
            </div>

            <div className="flex shrink-0 items-center gap-5">
              <ResponseArc pct={summary.response_rate ?? null} />
              <div className="min-w-0">
                <p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-steel-400">{t('All time')}</p>
                <p className="mt-1 font-display text-lg font-bold tabular-nums text-white">
                  {(summary.answered_total ?? 0).toLocaleString(numLocale(lang))}
                  <span className="text-steel-400"> / {(summary.sent_total ?? 0).toLocaleString(numLocale(lang))}</span>
                </p>
                <p className="text-[11px] text-steel-400">{t('reminders answered')}</p>
              </div>
            </div>
          </div>

          {/* The three live counts, in a hairline-divided strip. */}
          <div className="relative grid grid-cols-1 gap-px border-t border-white/10 bg-white/5 sm:grid-cols-3">
            <div className="bg-navy-900">
              <BandStat value={summary.open ?? 0} label={t('Awaiting an answer')} hint={t('cars with an open reminder')} />
            </div>
            <div className="bg-navy-900">
              <BandStat
                value={summary.breached ?? 0}
                label={alertDays === 1 ? t('Silent 1+ day') : t('Silent {n}+ days', { n: alertDays })}
                hint={t('past the tolerated silence')} tone={summary.breached ? 'bad' : 'plain'} pulse={!!summary.breached}
              />
            </div>
            <div className="bg-navy-900">
              <BandStat
                value={summary.unassigned ?? 0} label={t('Nobody responsible')} hint={t('no reminder could be sent')}
                tone={summary.unassigned ? 'warn' : 'plain'}
              />
            </div>
          </div>

          {!loading && !error && all.length > 0 && (
            <div className="relative border-t border-white/10">
              <SilenceHistogram counts={counts} total={all.length} active={bucket} onPick={setBucket} />
            </div>
          )}
        </section>

        {/* ---- Cars needing a chase that NOBODY owns. The reminder is deliberately withheld rather than
             broadcast to every permission holder, so this list is the only place the gap shows up —
             it has to be loud, and it sits above the normal rows. ---- */}
        {!loading && !error && unassigned.length > 0 && (
          <div className="overflow-hidden rounded-2xl border border-rose-200 bg-rose-50/60">
            <div className="flex items-start gap-3 p-5">
              <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-rose-100 text-rose-600 ring-1 ring-inset ring-rose-200">
                <Icon.Alert className="h-4 w-4" />
              </span>
              <div className="min-w-0">
                <h2 className="text-sm font-bold text-rose-900">
                  {unassigned.length === 1
                    ? t('1 car needs a checkpoint but has no responsible owner')
                    : t('{n} cars need a checkpoint but have no responsible owner', { n: unassigned.length })}
                </h2>
                <p className="mt-0.5 text-xs text-rose-700">
                  {t('No reminder was sent for these — nobody is assigned to chase them. Open each car and set a responsible user, or configure the supervisor fallback.')}
                </p>
                <div className="mt-3 flex flex-wrap gap-2">
                  {unassigned.map((u) => (
                    <Link
                      key={u.ticket_id} to={`/maintenance-workflow/${u.ticket_id}`}
                      className="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-sm ring-1 ring-inset ring-rose-200 transition hover:bg-rose-50"
                    >
                      <span className="font-mono font-bold">{u.plate_no || `#${u.ticket_id}`}</span>
                      <span className="text-slate-400">
                        {u.expected_on ? fmtDate(u.expected_on) : t('no ETA')}
                        {u.days_over > 0 ? ` · ${t('{n}d over', { n: u.days_over })}` : ''}
                      </span>
                    </Link>
                  ))}
                </div>
              </div>
            </div>
          </div>
        )}

        {/* ---- Toolbar. Sticky, because the list is the page and the filters have to stay reachable. ---- */}
        <div className="sticky top-2 z-10 flex flex-wrap items-center gap-3 rounded-2xl border border-slate-200/60 bg-white/90 px-4 py-2.5 shadow-soft backdrop-blur">
          <button
            type="button" onClick={() => setBreachedOnly(!breachedOnly)} aria-pressed={breachedOnly}
            className={`inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition ring-1 ring-inset ${
              breachedOnly
                ? 'bg-rose-50 text-rose-700 ring-rose-200'
                : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50'}`}
          >
            <Icon.Alert className="h-3.5 w-3.5" />
            {t('Breaches only')}
          </button>

          <span className="h-5 w-px bg-slate-200" aria-hidden />

          <div className="flex flex-wrap items-center gap-1.5">
            {BUCKETS.map((b) => {
              const on = bucket === b.key;
              const n = counts[b.key] || 0;
              return (
                <button
                  key={b.key} type="button" disabled={!n} title={bucketHint(t, b.key)} aria-pressed={on}
                  onClick={() => setBucket(on ? null : b.key)}
                  className={`inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition ring-1 ring-inset ${
                    on ? `${b.soft} ${b.text}`
                       : n ? 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50'
                           : 'cursor-default bg-white text-slate-300 ring-slate-100'}`}
                >
                  <span className={`h-1.5 w-1.5 rounded-full ${b.dot} ${n ? '' : 'opacity-30'}`} />
                  {bucketLabel(t, b.key)}
                  <span className="tabular-nums">{n}</span>
                </button>
              );
            })}
          </div>

          {filtered && (
            <button
              type="button" onClick={() => { setBucket(null); setBreachedOnly(false); setQ(''); }}
              className="text-xs font-medium text-slate-400 transition-colors hover:text-slate-600"
            >
              {t('Clear')}
            </button>
          )}

          <SearchInput value={q} onChange={setQ} placeholder={t('Plate, garage or supervisor…')} className="ms-auto w-full sm:w-72" />
        </div>

        {loading ? (
          <div className="space-y-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-32 rounded-2xl" />)}</div>
        ) : error ? (
          <Card><ErrorState /></Card>
        ) : rows.length === 0 ? (
          <Card>
            {all.length === 0 ? (
              <EmptyState
                icon={<Icon.Check className="h-6 w-6 text-emerald-500" />}
                title={t('Every reminder has been answered')}
                message={t('No supervisor is currently sitting on an unanswered checkpoint reminder.')}
              />
            ) : (
              <EmptyState
                icon={<Icon.Search className="h-6 w-6 text-slate-400" />}
                title={t('No cars match these filters')}
                message={all.length === 1
                  ? t('1 unanswered reminder is hidden by the current filter.')
                  : t('{n} unanswered reminders are hidden by the current filter.', { n: all.length })}
                action={(
                  <button
                    type="button" onClick={() => { setBucket(null); setBreachedOnly(false); setQ(''); }}
                    className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
                  >
                    {t('Clear filters')}
                  </button>
                )}
              />
            )}
          </Card>
        ) : (
          <div className="stagger space-y-2.5">
            {rows.map((r) => (
              <Row
                key={r.ticket_id} r={r}
                open={expanded === r.ticket_id}
                onToggle={() => setExpanded(expanded === r.ticket_id ? null : r.ticket_id)}
              />
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
