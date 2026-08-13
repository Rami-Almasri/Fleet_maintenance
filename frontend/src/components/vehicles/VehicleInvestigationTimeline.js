import { Fragment, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import Badge from '../ui/Badge';
import Icon from '../ui/Icon';
import WhyThisGarage from '../workflow/WhyThisGarage';
import { num, fmtDate, fmtClock, fmtAgo } from '../../lib/format';
import {
  TYPE_META, TYPE_KEYS, typeMeta, eventKind,
  severityMeta, stageLabel, buildFacets, countByKind,
  applyFilters, sortEvents, groupEvents, computeKpis, SORTS, GROUPS,
  normalizeLegacyTimeline,
} from '../../lib/vehicleTimeline';
import { useI18n } from '../../i18n/I18nContext';

// The Vehicle Timeline — an INVESTIGATION tool, not a scrolling feed. It reads the car's unified Activity
// Audit Trail (GET /Vehicle/{id}/activity) and layers search, combinable filters, a live KPI summary,
// grouping, sorting, event-type badges and one-click quick jumps on top of it. Every control is mirrored
// into the URL (tl.* params) so the exact investigation view is shareable. No backend data-model change —
// all derivation happens over fields that already ship on each event (see lib/vehicleTimeline).

// Soft marker fills per badge tone (mirrors the shared timeline palette).
const TONE_STYLE = {
  slate:   { soft: 'bg-slate-100',   text: 'text-slate-500' },
  blue:    { soft: 'bg-blue-100',    text: 'text-blue-600' },
  cyan:    { soft: 'bg-cyan-100',    text: 'text-cyan-600' },
  red:     { soft: 'bg-red-100',     text: 'text-red-600' },
  emerald: { soft: 'bg-emerald-100', text: 'text-emerald-600' },
  green:   { soft: 'bg-emerald-100', text: 'text-emerald-600' },
  violet:  { soft: 'bg-violet-100',  text: 'text-violet-600' },
  indigo:  { soft: 'bg-indigo-100',  text: 'text-indigo-600' },
  amber:   { soft: 'bg-amber-100',   text: 'text-amber-600' },
  orange:  { soft: 'bg-orange-100',  text: 'text-orange-600' },
  yellow:  { soft: 'bg-yellow-100',  text: 'text-yellow-700' },
  gray:    { soft: 'bg-slate-100',   text: 'text-slate-500' },
};
const styleFor = (tone) => TONE_STYLE[tone] || TONE_STYLE.slate;

// `short` is the bare noun shown on the chip and on the active-filter pill — both sit under a "has"
// heading already. It is stated outright rather than derived by stripping a "Has " prefix with a
// regex, which would not survive translation.
const FLAG_OPTIONS = [
  { key: 'photos',          short: 'Photos',          Icon: Icon.Camera },
  { key: 'attachments',     short: 'Attachments',     Icon: Icon.Download },
  { key: 'notes',           short: 'Notes',           Icon: Icon.Info },
  { key: 'recommendations', short: 'Recommendations', Icon: Icon.Flag },
];

// Matches the server's own hard cap on GET /Vehicle/{id}/activity — hitting it means the trail is
// truncated to the newest FEED_LIMIT rows, which the UI has to say out loud.
const FEED_LIMIT = 500;

// ── URL param helpers (source of truth for shareable state) ─────────────────────────────
const P = 'tl.'; // namespace so we never collide with the profile's ?tab= / ?event= params.

// ── Small presentational atoms ──────────────────────────────────────────────────────────
function Chip({ on, tone = 'slate', onClick, children, count }) {
  const st = styleFor(tone);
  return (
    <button
      type="button"
      onClick={onClick}
      className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold transition ${
        on
          ? `${st.soft} ${st.text} border-transparent ring-1 ring-inset ring-black/5`
          : 'border-slate-200 bg-white text-slate-500 hover:border-slate-300 hover:text-slate-700'
      }`}
    >
      {children}
      {count != null && (
        <span className={`rounded-full px-1.5 text-[10px] tabular-nums ${on ? 'bg-white/70' : 'bg-slate-100 text-slate-500'}`}>{num(count)}</span>
      )}
    </button>
  );
}

// A removable summary of one active filter — shown in the toolbar so a collapsed panel never hides state.
function ActivePill({ label, value, onClear }) {
  const { t } = useI18n();
  return (
    <span className="inline-flex items-center gap-1.5 rounded-full bg-indigo-50 py-1 ps-2.5 pe-1.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-100">
      <span className="text-indigo-400">{label}</span>
      <span className="font-semibold">{value}</span>
      <button type="button" onClick={onClear} aria-label={t('Clear {label} filter', { label })} className="text-indigo-300 transition hover:text-indigo-600">
        <Icon.XCircle className="h-3.5 w-3.5" />
      </button>
    </span>
  );
}

function Field({ label, children }) {
  return (
    <label className="flex min-w-[10rem] flex-1 flex-col gap-1">
      <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{label}</span>
      {children}
    </label>
  );
}

const selectCls = 'w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm text-slate-700 shadow-soft focus:border-indigo-300 focus:outline-none focus:ring-2 focus:ring-indigo-100';

function Select({ value, onChange, children }) {
  return <select value={value} onChange={(e) => onChange(e.target.value)} className={selectCls}>{children}</select>;
}

// Toolbar-sized select: the label sits inline so sort/group cost one line, not a whole form row.
function InlineSelect({ label, value, onChange, children }) {
  return (
    <label className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white ps-2.5 text-xs shadow-soft focus-within:border-indigo-300 focus-within:ring-2 focus-within:ring-indigo-100">
      <span className="font-semibold uppercase tracking-wide text-slate-400">{label}</span>
      <select
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="cursor-pointer rounded-e-lg border-0 bg-transparent py-1.5 ps-0 pe-2 text-xs font-semibold text-slate-700 focus:outline-none"
      >
        {children}
      </select>
    </label>
  );
}

// ── Stat rail ──────────────────────────────────────────────────────────────────────────
// One quiet row instead of a wall of tiles: empty measures are dropped, so the rail only ever states
// what this car actually has. Recomputes against the filtered set.
function StatRail({ kpis }) {
  const { t } = useI18n();
  if (!kpis.length) return null;
  return (
    <div className="flex flex-wrap items-center gap-x-7 gap-y-3 rounded-2xl border border-slate-200/70 bg-white px-4 py-3 shadow-soft">
      {kpis.map((k) => (
        <div key={k.key} className="flex items-baseline gap-2">
          <span className="text-lg font-semibold tabular-nums text-slate-900">{k.text ? k.value : num(k.value)}</span>
          <span className="text-[11px] font-medium uppercase tracking-wide text-slate-400">{t(k.label)}</span>
        </div>
      ))}
    </div>
  );
}

// ── One event row ────────────────────────────────────────────────────────────────────────
// A sheet workshop row carries its untouched source record (`raw`); those rows are clickable and open
// the full workshop-event drawer. Everything else renders as a static card.
function EventRow({ e, onOpen, highlighted, showDate }) {
  const { t } = useI18n();
  const kind = eventKind(e);
  const tm = typeMeta(kind);
  const st = styleFor(tm.tone);
  const Glyph = tm.Icon || Icon.Activity;
  const headline = stageLabel(e) || e.action || t('Activity');
  const subAction = e.action && e.action !== headline ? e.action : null;
  const sev = e.severity ? severityMeta(e.severity) : null;
  const roleLabel = e.actor_role && e.actor_role !== 'system'
    ? e.actor_role.charAt(0).toUpperCase() + e.actor_role.slice(1) : null;

  const openable = Boolean(e.raw && onOpen);
  const Card = openable ? 'button' : 'div';
  const cardProps = openable
    ? { type: 'button', onClick: () => onOpen(e.raw), className: 'group text-start transition-all duration-200 hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-card focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400' }
    : {};

  return (
    // The `log-event-<id>` anchor keeps the ?event=<id> deep-link (Foresight / Fleet Utilization) working.
    <li id={e.raw?.id != null ? `log-event-${e.raw.id}` : undefined} className="relative flex scroll-mt-28 gap-3.5">
      <span className={`relative z-10 mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full ring-4 ring-slate-50 ${st.soft} ${st.text}`}>
        <Glyph className="h-4 w-4" />
      </span>
      <Card
        {...cardProps}
        className={`min-w-0 flex-1 rounded-xl border bg-white p-3.5 shadow-soft ${cardProps.className || ''} ${
          highlighted ? 'border-red-300 bg-red-50/40 ring-2 ring-red-400' : e.flagged ? 'border-red-200 bg-red-50/30' : 'border-slate-200/60'
        }`}
      >
        <div className="flex items-start justify-between gap-3">
          <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
            <span className="text-sm font-semibold text-slate-800">{headline}</span>
            <Badge tone={tm.tone}>{t(tm.label)}</Badge>
            {sev && <Badge tone={sev.tone} dot>{t(sev.label)}</Badge>}
            {/* `flagged` means damage on an inspection row, but "still unresolved" on a complaint or a
                driver note — same signal, different word, so it must not be labelled "Damage" there. */}
            {e.flagged && (
              kind === 'complaint' || kind === 'observation'
                ? <Badge tone="amber" dot>{t('Open')}</Badge>
                : <Badge tone="red">{t('Damage')}</Badge>
            )}
            {highlighted && <Badge tone="red" dot>{t('Worst-case downtime — investigate')}</Badge>}
          </div>
          {/* The day divider carries the date in a chronological view, so the row only needs the clock;
              under severity/mileage sorts there is no divider, so it states the full date instead. */}
          <span
            className="shrink-0 whitespace-nowrap text-end text-[11px] font-medium tabular-nums text-slate-400"
            title={e.occurred_at ? `${fmtDate(e.occurred_at)} · ${fmtClock(e.occurred_at)} · ${fmtAgo(e.occurred_at)}` : ''}
          >
            {!e.occurred_at ? t('No date') : showDate ? fmtDate(e.occurred_at) : fmtClock(e.occurred_at) || fmtAgo(e.occurred_at)}
          </span>
        </div>

        {subAction && <p className="mt-0.5 text-xs font-medium text-slate-400">{subAction}</p>}
        {e.transition && <p className="mt-1 inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">{e.transition}</p>}
        {e.description && <p className="mt-1.5 line-clamp-3 text-sm leading-relaxed text-slate-600">{e.description}</p>}

        {/* "Why this garage?" — the data-driven decision behind a garage assignment (from meta.recommendation) */}
        {e.event_type === 'garage_assigned' && e.details?.recommendation && (
          <WhyThisGarage rec={e.details.recommendation} className="mt-2.5" />
        )}

        <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
          {e.odometer != null && (
            <span className="inline-flex items-center gap-1.5 font-medium text-slate-600">
              <Icon.Gauge className="h-3.5 w-3.5 text-slate-400" /> {num(e.odometer)} km
            </span>
          )}
          {e.garage && (
            <span className="inline-flex items-center gap-1.5 font-medium text-slate-600">
              <Icon.Wrench className="h-3.5 w-3.5 text-slate-400" /> {e.garage}
            </span>
          )}
          <span className="inline-flex items-center gap-1.5">
            <Icon.Users className="h-3.5 w-3.5 text-slate-400" />
            <span className={e.actor_name === 'System' || !e.actor_name ? 'italic text-slate-400' : 'font-medium text-slate-600'}>{e.actor_name || t('System')}</span>
            {roleLabel && <span className="text-[11px] text-slate-400">· {roleLabel}</span>}
          </span>
          {/* Links only on static cards — an <a>/<Link> inside the clickable <button> variant is invalid
              HTML and would swallow the row's own click. Openable rows expose these in the drawer. */}
          {!openable && e.photo_url && (
            <a href={e.photo_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 font-semibold text-indigo-600 hover:text-indigo-700">
              <Icon.Camera className="h-3.5 w-3.5" /> {t('Photo')}
            </a>
          )}
          {!openable && e.contract_id && (
            <Link to={`/contracts/${e.contract_id}`} className="font-medium text-indigo-600 hover:text-indigo-700">#{e.contract_no || e.contract_id}</Link>
          )}
          {/* Jump to the ticket behind a workflow event — carried over from the retired Maintenance Log. */}
          {!openable && e.maintenance_id && (
            <Link to={`/maintenance-workflow/${e.maintenance_id}`} className="inline-flex items-center gap-1 font-semibold text-amber-700 hover:text-amber-800">
              <Icon.Wrench className="h-3.5 w-3.5" /> {t('Ticket')}
            </Link>
          )}
          {openable && (
            <span className="font-medium text-indigo-500 opacity-0 transition group-hover:opacity-100">
              {t('Open full record')} <span aria-hidden className="inline-block rtl:-scale-x-100">→</span>
            </span>
          )}
        </div>
      </Card>
    </li>
  );
}

// A day heading on the rail. Only rendered under a chronological sort, where "the next row is the next
// day" is true — under severity/mileage sorts the rows would jump between dates and the divider would lie.
function DayDivider({ label }) {
  return (
    <li className="relative flex items-center gap-3.5 pt-1">
      <span aria-hidden className="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center">
        <span className="h-2 w-2 rounded-full bg-slate-300 ring-4 ring-slate-50" />
      </span>
      <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{label}</span>
      <span aria-hidden className="h-px flex-1 bg-slate-200/70" />
    </li>
  );
}

function EventList({ events, onOpen, highlightEventId, chronological }) {
  const { t } = useI18n();
  let lastDay = null;
  return (
    <div className="relative">
      <span aria-hidden className="pointer-events-none absolute bottom-4 start-4 top-4 w-px bg-gradient-to-b from-slate-200 via-slate-200 to-transparent" />
      <ol className="space-y-2.5">
        {events.map((e) => {
          const day = e.occurred_at ? String(e.occurred_at).slice(0, 10) : null;
          const newDay = chronological && day !== lastDay;
          if (chronological) lastDay = day;
          return (
            <Fragment key={e.id}>
              {newDay && <DayDivider label={day ? fmtDate(e.occurred_at) : t('Undated')} />}
              <EventRow
                e={e}
                onOpen={onOpen}
                showDate={!chronological}
                highlighted={highlightEventId != null && e.raw?.id != null && String(e.raw.id) === String(highlightEventId)}
              />
            </Fragment>
          );
        })}
      </ol>
    </div>
  );
}

// First paint on a cold load — the shape of the rail, so the page doesn't jump when the data lands.
function ListSkeleton() {
  return (
    <div className="relative rounded-2xl border border-slate-200/70 bg-slate-50/60 p-3">
      <span aria-hidden className="pointer-events-none absolute bottom-7 start-7 top-7 w-px bg-slate-200" />
      <ul className="space-y-2.5">
        {[0, 1, 2, 3, 4].map((i) => (
          <li key={i} className="flex animate-pulse gap-3.5">
            <span className="mt-1 h-8 w-8 shrink-0 rounded-full bg-slate-200 ring-4 ring-slate-50" />
            <div className="flex-1 rounded-xl border border-slate-200/60 bg-white p-3.5">
              <div className="h-3.5 w-1/3 rounded bg-slate-200" />
              <div className="mt-2.5 h-3 w-2/3 rounded bg-slate-100" />
              <div className="mt-2 h-3 w-1/4 rounded bg-slate-100" />
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}

// ── Main component ────────────────────────────────────────────────────────────────────────
export default function VehicleInvestigationTimeline({ vehicleId, legacyTimeline, onOpenEvent, highlightEventId }) {
  const [searchParams, setSearchParams] = useSearchParams();
  const { t } = useI18n();

  // The API caps a car's activity trail (server max = FEED_LIMIT, newest first). A car that hits the cap
  // has OLDER events the feed silently dropped — we surface that rather than let the list imply "all".
  const fetcher = useCallback(async () => {
    const { data } = await api.get(`/Vehicle/${vehicleId}/activity`, { params: { limit: FEED_LIMIT } });
    return data.data;
  }, [vehicleId]);
  const { data, loading, error } = useFetch(fetcher, [vehicleId], { refreshInterval: 60000 });

  // The investigation dataset unifies TWO sources so no car looks empty: the workflow-era activity trail
  // (vehicle_log_events + logistics + inspections, when present) plus the sheet workshop visits &
  // follow-ups the feed can't supply, lifted from the profile's own `data.timeline`. normalizeLegacy
  // drops the workflow rows the activity feed already owns, so nothing double-counts.
  const feedCapped = (data?.events?.length || 0) >= FEED_LIMIT;
  const legacy = useMemo(() => normalizeLegacyTimeline(legacyTimeline), [legacyTimeline]);
  const allEvents = useMemo(() => [...(data?.events || []), ...legacy], [data, legacy]);

  // ── Read filter state from the URL (the shareable source of truth) ──────────────────────
  const get = (k) => searchParams.get(P + k) || '';
  const getSet = (k) => new Set((get(k)).split(',').filter(Boolean));
  const types = getSet('type');
  const severities = getSet('sev');
  const flags = getSet('flags');
  const garage = get('garage');
  const inspector = get('insp');
  const driver = get('driver');
  const stage = get('stage');
  const status = get('status') || 'all';
  const from = get('from');
  const to = get('to');
  const sort = get('sort') || 'newest';
  const group = get('group') || 'none';

  // Write helper — set/clear namespaced params, preserving everything else in the URL.
  const patch = useCallback((obj) => {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      for (const [k, v] of Object.entries(obj)) {
        const key = P + k;
        if (v == null || v === '' || v === 'all' || v === 'none' || v === 'newest' || (v instanceof Set && v.size === 0)) next.delete(key);
        else next.set(key, v instanceof Set ? [...v].join(',') : v);
      }
      return next;
    }, { replace: true });
  }, [setSearchParams]);

  const toggle = (k, value) => { const s = getSet(k); s.has(value) ? s.delete(value) : s.add(value); patch({ [k]: s }); };
  const setTypes = (s) => patch({ type: s });

  // Search: local state drives filtering instantly; a debounced effect mirrors it into the URL so a
  // shared link reproduces the query, without a parent re-render on every keystroke.
  const [search, setSearch] = useState(get('q'));
  // The advanced panel starts collapsed — unless a shared link already carries one of its refinements,
  // in which case it opens so the recipient sees why the view is narrowed.
  const [showFilters, setShowFilters] = useState(
    () => Boolean(get('sev') || get('flags') || get('garage') || get('insp') || get('driver') || get('stage') || get('status') || get('from') || get('to')),
  );
  const firstSync = useRef(true);
  useEffect(() => {
    if (firstSync.current) { firstSync.current = false; return; }
    const id = setTimeout(() => patch({ q: search.trim() }), 250);
    return () => clearTimeout(id);
  }, [search, patch]);

  const filters = useMemo(() => ({
    q: search, types, severities, flags, garage, inspector, driver, stage, status, from, to,
  }), [search, types, severities, flags, garage, inspector, driver, stage, status, from, to]);

  // ── Derive the view ────────────────────────────────────────────────────────────────────
  const facets = useMemo(() => buildFacets(allEvents), [allEvents]);
  const kindCounts = useMemo(() => countByKind(allEvents), [allEvents]);
  const filtered = useMemo(() => applyFilters(allEvents, filters), [allEvents, filters]);
  const sorted = useMemo(() => sortEvents(filtered, sort), [filtered, sort]);
  const grouped = useMemo(() => groupEvents(sorted, group), [sorted, group]);
  const kpis = useMemo(() => computeKpis(filtered), [filtered]);

  const resetAll = () => { setSearch(''); patch({ q: '', type: '', sev: '', flags: '', garage: '', insp: '', driver: '', stage: '', status: '', from: '', to: '' }); };

  // Event types are the primary lens, so they live in the toolbar — busiest kinds first.
  const availTypes = useMemo(
    () => TYPE_KEYS.filter((k) => kindCounts[k]).sort((a, b) => kindCounts[b] - kindCounts[a]),
    [kindCounts],
  );

  // Everything the advanced panel owns, summarised as removable pills so a collapsed panel hides no state.
  const refinements = [
    garage && { key: 'garage', label: t('Garage'), value: garage, clear: () => patch({ garage: '' }) },
    inspector && { key: 'insp', label: t('Inspector'), value: inspector, clear: () => patch({ insp: '' }) },
    driver && { key: 'driver', label: t('Driver'), value: driver, clear: () => patch({ driver: '' }) },
    stage && { key: 'stage', label: t('Stage'), value: stage, clear: () => patch({ stage: '' }) },
    status !== 'all' && { key: 'status', label: t('Status'), value: status === 'open' ? t('Open only') : t('Closed only'), clear: () => patch({ status: '' }) },
    from && { key: 'from', label: t('From'), value: from, clear: () => patch({ from: '' }) },
    to && { key: 'to', label: t('To'), value: to, clear: () => patch({ to: '' }) },
    ...[...severities].map((s) => ({ key: `sev-${s}`, label: t('Severity'), value: t(severityMeta(s).label), clear: () => toggle('sev', s) })),
    ...[...flags].map((f) => ({ key: `flag-${f}`, label: t('Has'), value: t(FLAG_OPTIONS.find((o) => o.key === f)?.short || f), clear: () => toggle('flags', f) })),
  ].filter(Boolean);

  const anyFilter = Boolean(search || types.size || refinements.length);
  const refineCount = refinements.length;

  // Day dividers only make sense while the list actually runs in date order.
  const chronological = sort === 'newest' || sort === 'oldest';

  // Drop the measures this car has nothing to say about — a wall of zeros reads as noise, not data.
  const shownKpis = kpis.filter((k) => k.key === 'total' || (k.text ? k.value && k.value !== '—' : k.value > 0));

  return (
    // `maintenance-log` is the legacy anchor the ?focus=maintenance deep-link scrolls to.
    <div id="maintenance-log" className="scroll-mt-28 space-y-5">
      {error && <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>}

      {/* Traceability: never let a truncated trail read as a complete one. */}
      {feedCapped && (
        <div className="flex items-start gap-2 rounded-lg bg-amber-50 px-4 py-2.5 text-xs text-amber-800 ring-1 ring-inset ring-amber-600/20">
          <Icon.Alert className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" />
          <span>
            {t('Trail truncated. This car has more workflow activity than the feed returns — only the newest {n} events are loaded. Older workshop history from the maintenance sheet is still shown in full below.', { n: num(FEED_LIMIT) })}
          </span>
        </div>
      )}

      {/* ── Toolbar: search · type lens · sort/group · advanced-filter toggle ──
          Sticky, because on a 143-event car the controls are otherwise scrolled far out of reach. It parks
          just below the profile's own sticky chrome (app header 4rem + tab strip 2.5rem) and stays UNDER
          them in z-order (header z-20, tab strip z-10). */}
      <div className="sticky top-[6.5rem] z-[9] space-y-3 rounded-2xl border border-slate-200/70 bg-white/95 p-3 shadow-soft backdrop-blur supports-[backdrop-filter]:bg-white/85">
        <div className="flex flex-wrap items-center gap-2">
          <div className="flex min-w-[16rem] flex-1 items-center gap-2 rounded-lg border border-slate-200 bg-slate-50/60 px-3 py-1.5 focus-within:border-indigo-300 focus-within:bg-white focus-within:ring-2 focus-within:ring-indigo-100">
            <Icon.Search className="h-4 w-4 shrink-0 text-slate-400" />
            <input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={t('Search faults, garages, people, stages, notes…')}
              className="w-full bg-transparent text-sm text-slate-700 placeholder:text-slate-400 focus:outline-none"
            />
            {search && (
              <button type="button" onClick={() => setSearch('')} aria-label={t('Clear search')} className="text-slate-400 hover:text-slate-600"><Icon.XCircle className="h-4 w-4" /></button>
            )}
          </div>

          <InlineSelect label={t('Sort')} value={sort} onChange={(v) => patch({ sort: v })}>
            {Object.entries(SORTS).map(([k, m]) => <option key={k} value={k}>{t(m.label)}</option>)}
          </InlineSelect>
          <InlineSelect label={t('Group')} value={group} onChange={(v) => patch({ group: v })}>
            {Object.entries(GROUPS).map(([k, m]) => <option key={k} value={k}>{t(m.label)}</option>)}
          </InlineSelect>

          <button
            type="button"
            onClick={() => setShowFilters((v) => !v)}
            aria-expanded={showFilters}
            className={`inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-semibold shadow-soft transition ${
              showFilters || refineCount
                ? 'border-indigo-200 bg-indigo-50 text-indigo-700'
                : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'
            }`}
          >
            <Icon.Filter className="h-3.5 w-3.5" /> {t('Filters')}
            {refineCount > 0 && <span className="rounded-full bg-indigo-600 px-1.5 text-[10px] font-bold tabular-nums text-white">{refineCount}</span>}
            <Icon.ChevronDown className={`h-3.5 w-3.5 transition-transform ${showFilters ? 'rotate-180' : ''}`} />
          </button>
        </div>

        {/* Event type — the primary lens, always visible. "All" clears it. */}
        {availTypes.length > 0 && (
          <div className="flex flex-wrap items-center gap-1.5">
            <Chip on={types.size === 0} tone="indigo" count={allEvents.length} onClick={() => setTypes(new Set())}>{t('All')}</Chip>
            <span aria-hidden className="mx-0.5 h-4 w-px bg-slate-200" />
            {availTypes.map((k) => {
              const TIcon = TYPE_META[k].Icon;
              return (
                <Chip key={k} on={types.has(k)} tone={TYPE_META[k].tone} count={kindCounts[k]} onClick={() => toggle('type', k)}>
                  {TIcon && <TIcon className="h-3.5 w-3.5" />}{t(TYPE_META[k].label)}
                </Chip>
              );
            })}
          </div>
        )}

        {/* Active refinements — visible even while the panel is collapsed. */}
        {(refineCount > 0 || search) && (
          <div className="flex flex-wrap items-center gap-1.5 border-t border-slate-100 pt-2.5">
            {search && (
              <ActivePill label={t('Search')} value={`“${search}”`} onClear={() => setSearch('')} />
            )}
            {refinements.map((r) => <ActivePill key={r.key} label={r.label} value={r.value} onClear={r.clear} />)}
            {anyFilter && (
              <button type="button" onClick={resetAll} className="ms-1 text-xs font-semibold text-slate-400 underline-offset-2 hover:text-slate-600 hover:underline">
                {t('Clear all')}
              </button>
            )}
          </div>
        )}

        {/* ── Advanced filters (collapsed by default — the panel, not the page, holds the complexity) ── */}
        {showFilters && (
        <div className="max-h-[55vh] space-y-4 overflow-y-auto rounded-xl border border-slate-200 bg-slate-50/50 p-3">
        {/* Severity chips */}
        {facets.severities.length > 0 && (
          <div>
            <div className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{t('Severity')}</div>
            <div className="flex flex-wrap gap-2">
              {facets.severities.map((s) => (
                <Chip key={s.value} on={severities.has(s.value)} tone={severityMeta(s.value).tone} count={s.count} onClick={() => toggle('sev', s.value)}>
                  {t(s.label)}
                </Chip>
              ))}
            </div>
          </div>
        )}

        {/* Dropdown facets + date range */}
        <div className="flex flex-wrap gap-3">
          {facets.garages.length > 0 && (
            <Field label={t('Garage')}>
              <Select value={garage} onChange={(v) => patch({ garage: v })}>
                <option value="">{t('All garages')}</option>
                {facets.garages.map((g) => <option key={g.value} value={g.value}>{g.label} ({g.count})</option>)}
              </Select>
            </Field>
          )}
          {facets.inspectors.length > 0 && (
            <Field label={t('Inspector')}>
              <Select value={inspector} onChange={(v) => patch({ insp: v })}>
                <option value="">{t('All inspectors')}</option>
                {facets.inspectors.map((g) => <option key={g.value} value={g.value}>{g.label} ({g.count})</option>)}
              </Select>
            </Field>
          )}
          {facets.drivers.length > 0 && (
            <Field label={t('Driver')}>
              <Select value={driver} onChange={(v) => patch({ driver: v })}>
                <option value="">{t('All drivers')}</option>
                {facets.drivers.map((g) => <option key={g.value} value={g.value}>{g.label} ({g.count})</option>)}
              </Select>
            </Field>
          )}
          {facets.stages.length > 0 && (
            <Field label={t('Workflow stage')}>
              <Select value={stage} onChange={(v) => patch({ stage: v })}>
                <option value="">{t('All stages')}</option>
                {facets.stages.map((g) => <option key={g.value} value={g.value}>{g.label} ({g.count})</option>)}
              </Select>
            </Field>
          )}
          <Field label={t('Status')}>
            <Select value={status} onChange={(v) => patch({ status: v })}>
              <option value="all">{t('Open & closed')}</option>
              <option value="open">{t('Open only')}</option>
              <option value="closed">{t('Closed only')}</option>
            </Select>
          </Field>
          <Field label={t('From date')}>
            <input type="date" value={from} onChange={(e) => patch({ from: e.target.value })} className={selectCls} />
          </Field>
          <Field label={t('To date')}>
            <input type="date" value={to} onChange={(e) => patch({ to: e.target.value })} className={selectCls} />
          </Field>
        </div>

        {/* Boolean flags */}
        <div>
          <div className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{t('Only events that have')}</div>
          <div className="flex flex-wrap gap-2">
            {FLAG_OPTIONS.map((f) => {
              const FIcon = f.Icon;
              return (
                <Chip key={f.key} on={flags.has(f.key)} tone="indigo" onClick={() => toggle('flags', f.key)}>
                  <FIcon className="h-3.5 w-3.5" />{t(f.short)}
                </Chip>
              );
            })}
          </div>
        </div>
        </div>
        )}
      </div>

      {/* ── Stat rail (recomputes as filters change) ── */}
      <StatRail kpis={shownKpis} />

      {/* ── Results ── */}
      <div className="flex items-center justify-between gap-3 px-1">
        <span className="text-xs font-medium text-slate-500">
          {anyFilter
            ? t('Showing {shown} of {total} events', { shown: num(filtered.length), total: num(allEvents.length) })
            : t('{total} events', { total: num(allEvents.length) })}
          {grouped && (
            <span className="text-slate-400">
              {' '}
              {t('· {n} groups by {kind}', { n: grouped.length, kind: t(GROUPS[group].label) })}
            </span>
          )}
        </span>
        {loading && allEvents.length > 0 && (
          <Icon.Refresh className="h-3.5 w-3.5 animate-spin text-slate-300" aria-label={t('Refreshing')} />
        )}
      </div>

      {loading && !allEvents.length ? (
        <ListSkeleton />
      ) : filtered.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50/40 px-6 py-14 text-center">
          <Icon.Search className="mx-auto h-6 w-6 text-slate-300" />
          <p className="mt-3 text-sm font-semibold text-slate-600">
            {allEvents.length ? t('No events match these filters') : t('No activity recorded for this car yet')}
          </p>
          <p className="mt-1 text-xs text-slate-400">
            {allEvents.length
              ? t('{n} events are hidden by the current filters.', { n: num(allEvents.length) })
              : t('Events appear here as the car moves through inspections, garages and tickets.')}
          </p>
          {allEvents.length > 0 && anyFilter && (
            <button type="button" onClick={resetAll} className="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-soft transition hover:bg-indigo-700">
              <Icon.XCircle className="h-3.5 w-3.5" /> {t('Clear all filters')}
            </button>
          )}
        </div>
      ) : grouped ? (
        <div className="space-y-5">
          {grouped.map((g) => (
            <section key={g.key} className="overflow-hidden rounded-2xl border border-slate-200/70 bg-slate-50/60">
              <div className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-slate-200/70 bg-white px-4 py-2.5">
                <h4 className="text-sm font-semibold text-slate-800">{g.label}</h4>
                <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold tabular-nums text-slate-500">{num(g.count)}</span>
                {g.sublabel && <span className="text-[11px] font-medium text-slate-400">{g.sublabel}</span>}
              </div>
              <div className="p-3">
                <EventList events={g.events} onOpen={onOpenEvent} highlightEventId={highlightEventId} chronological={chronological} />
              </div>
            </section>
          ))}
        </div>
      ) : (
        <div className="rounded-2xl border border-slate-200/70 bg-slate-50/60 p-3">
          <EventList events={sorted} onOpen={onOpenEvent} highlightEventId={highlightEventId} chronological={chronological} />
        </div>
      )}
    </div>
  );
}
