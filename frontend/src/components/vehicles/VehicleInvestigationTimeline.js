import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import Badge from '../ui/Badge';
import Icon from '../ui/Icon';
import { num, fmtDate, fmtClock, fmtAgo } from '../../lib/format';
import {
  TYPE_META, TYPE_KEYS, typeMeta, eventKind, QUICK_JUMPS,
  severityMeta, stageLabel, buildFacets, countByKind,
  applyFilters, sortEvents, groupEvents, computeKpis, SORTS, GROUPS,
  normalizeLegacyTimeline,
} from '../../lib/vehicleTimeline';

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

const FLAG_OPTIONS = [
  { key: 'photos',          label: 'Has photos',          Icon: Icon.Camera },
  { key: 'attachments',     label: 'Has attachments',     Icon: Icon.Download },
  { key: 'notes',           label: 'Has notes',           Icon: Icon.Info },
  { key: 'recommendations', label: 'Has recommendations', Icon: Icon.Flag },
];

// ── URL param helpers (source of truth for shareable state) ─────────────────────────────
const P = 'tl.'; // namespace so we never collide with the profile's ?tab= / ?event= params.

// ── Small presentational atoms ──────────────────────────────────────────────────────────
function Chip({ on, tone = 'slate', onClick, children, count }) {
  const st = styleFor(tone);
  return (
    <button
      type="button"
      onClick={onClick}
      className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition ${
        on ? `${st.soft} ${st.text} ring-1 ring-inset ring-black/5` : 'bg-slate-100 text-slate-500 hover:text-slate-700'
      }`}
    >
      {children}
      {count != null && <span className={`rounded-full px-1.5 text-[10px] ${on ? 'bg-white/60' : 'bg-white'}`}>{num(count)}</span>}
    </button>
  );
}

function Field({ label, children }) {
  return (
    <label className="flex min-w-[9rem] flex-1 flex-col gap-1">
      <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{label}</span>
      {children}
    </label>
  );
}

const selectCls = 'w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm text-slate-700 shadow-soft focus:border-indigo-300 focus:outline-none focus:ring-2 focus:ring-indigo-100';

function Select({ value, onChange, children }) {
  return <select value={value} onChange={(e) => onChange(e.target.value)} className={selectCls}>{children}</select>;
}

// ── KPI strip ──────────────────────────────────────────────────────────────────────────
function KpiStrip({ kpis }) {
  return (
    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
      {kpis.map((k) => (
        <div key={k.key} className="rounded-xl border border-slate-200/70 bg-white px-3 py-2.5 shadow-soft">
          <div className="text-[11px] font-medium uppercase tracking-wide text-slate-400">{k.label}</div>
          <div className="mt-0.5 text-lg font-semibold tabular-nums text-slate-900">{k.text ? k.value : num(k.value)}</div>
        </div>
      ))}
    </div>
  );
}

// ── One event row ────────────────────────────────────────────────────────────────────────
function EventRow({ e }) {
  const kind = eventKind(e);
  const tm = typeMeta(kind);
  const st = styleFor(tm.tone);
  const Glyph = tm.Icon || Icon.Activity;
  const headline = stageLabel(e) || e.action || 'Activity';
  const subAction = e.action && e.action !== headline ? e.action : null;
  const sev = e.severity ? severityMeta(e.severity) : null;
  const roleLabel = e.actor_role && e.actor_role !== 'system'
    ? e.actor_role.charAt(0).toUpperCase() + e.actor_role.slice(1) : null;

  return (
    <li className="relative flex gap-4">
      <span className={`relative z-10 mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full ring-4 ring-white ${st.soft} ${st.text}`}>
        <Glyph className="h-[18px] w-[18px]" />
      </span>
      <div className={`min-w-0 flex-1 rounded-2xl border bg-white p-4 shadow-soft ${e.flagged ? 'border-red-200 bg-red-50/30' : 'border-slate-200/60'}`}>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={tm.tone}>{tm.label}</Badge>
            <span className="text-sm font-semibold text-slate-800">{headline}</span>
            {sev && <Badge tone={sev.tone} dot>{sev.label}</Badge>}
            {e.flagged && <Badge tone="red">Damage</Badge>}
          </div>
          <span className="whitespace-nowrap text-xs font-medium text-slate-400" title={e.occurred_at ? `${fmtDate(e.occurred_at)} · ${fmtClock(e.occurred_at)}` : ''}>
            {e.occurred_at ? fmtAgo(e.occurred_at) : 'No date'}
          </span>
        </div>

        {subAction && <p className="mt-0.5 text-xs font-medium text-slate-400">{subAction}</p>}
        {e.transition && <p className="mt-1 inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">{e.transition}</p>}
        {e.description && <p className="mt-1.5 text-sm leading-relaxed text-slate-600">{e.description}</p>}

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
            <span className={e.actor_name === 'System' || !e.actor_name ? 'italic text-slate-400' : 'font-medium text-slate-600'}>{e.actor_name || 'System'}</span>
            {roleLabel && <span className="text-[11px] text-slate-400">· {roleLabel}</span>}
          </span>
          {e.photo_url && (
            <a href={e.photo_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 font-semibold text-indigo-600 hover:text-indigo-700">
              <Icon.Camera className="h-3.5 w-3.5" /> Photo
            </a>
          )}
          {e.contract_id && (
            <Link to={`/contracts/${e.contract_id}`} className="font-medium text-indigo-600 hover:text-indigo-700">#{e.contract_no || e.contract_id}</Link>
          )}
        </div>
      </div>
    </li>
  );
}

function EventList({ events }) {
  return (
    <div className="relative">
      <span aria-hidden className="pointer-events-none absolute bottom-4 left-[1.125rem] top-4 w-px bg-gradient-to-b from-slate-200 via-slate-200 to-transparent" />
      <ol className="space-y-3">
        {events.map((e) => <EventRow key={e.id} e={e} />)}
      </ol>
    </div>
  );
}

// ── Main component ────────────────────────────────────────────────────────────────────────
export default function VehicleInvestigationTimeline({ vehicleId, legacyTimeline }) {
  const [searchParams, setSearchParams] = useSearchParams();

  const fetcher = useCallback(async () => {
    const { data } = await api.get(`/Vehicle/${vehicleId}/activity`, { params: { limit: 500 } });
    return data.data;
  }, [vehicleId]);
  const { data, loading, error } = useFetch(fetcher, [vehicleId], { refreshInterval: 60000 });

  // The investigation dataset unifies TWO sources so no car looks empty: the workflow-era activity trail
  // (vehicle_log_events + logistics + inspections, when present) plus the sheet workshop visits &
  // follow-ups the feed can't supply, lifted from the profile's own `data.timeline`. normalizeLegacy
  // drops the workflow rows the activity feed already owns, so nothing double-counts.
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

  const activeQuick = QUICK_JUMPS.find((qj) => qj.types.length === types.size && qj.types.every((t) => types.has(t)));
  const anyFilter = Boolean(
    search || types.size || severities.size || flags.size || garage || inspector || driver || stage
    || status !== 'all' || from || to,
  );
  const resetAll = () => { setSearch(''); patch({ q: '', type: '', sev: '', flags: '', garage: '', insp: '', driver: '', stage: '', status: '', from: '', to: '' }); };

  const availTypes = TYPE_KEYS.filter((k) => kindCounts[k]);

  return (
    <div className="space-y-5">
      {error && <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>}

      {/* ── Search + quick jumps ── */}
      <div className="rounded-2xl border border-slate-200/70 bg-white p-4 shadow-soft">
        <div className="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50/60 px-3 py-2 focus-within:border-indigo-300 focus-within:bg-white focus-within:ring-2 focus-within:ring-indigo-100">
          <Icon.Search className="h-4 w-4 shrink-0 text-slate-400" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search faults, garage, vendor, inspector, driver, stage, parts, mileage, notes…"
            className="w-full bg-transparent text-sm text-slate-700 placeholder:text-slate-400 focus:outline-none"
          />
          {search && (
            <button type="button" onClick={() => setSearch('')} className="text-slate-400 hover:text-slate-600"><Icon.XCircle className="h-4 w-4" /></button>
          )}
        </div>

        <div className="mt-3 flex flex-wrap gap-2">
          {QUICK_JUMPS.filter((qj) => qj.types.some((t) => kindCounts[t])).map((qj) => {
            const on = activeQuick?.key === qj.key;
            const QIcon = qj.Icon;
            const cnt = qj.types.reduce((n, t) => n + (kindCounts[t] || 0), 0);
            return (
              <Chip key={qj.key} on={on} tone={on ? typeMeta(qj.types[0]).tone : 'slate'} count={cnt}
                onClick={() => setTypes(on ? new Set() : new Set(qj.types))}>
                <QIcon className="h-3.5 w-3.5" />{qj.label}
              </Chip>
            );
          })}
        </div>
      </div>

      {/* ── KPI summary (recomputes as filters change) ── */}
      <KpiStrip kpis={kpis} />

      {/* ── Filters ── */}
      <div className="space-y-4 rounded-2xl border border-slate-200/70 bg-white p-4 shadow-soft">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
            <Icon.Filter className="h-4 w-4" /> Filters
          </div>
          {anyFilter && (
            <button type="button" onClick={resetAll} className="inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-700">
              <Icon.XCircle className="h-3.5 w-3.5" /> Clear all
            </button>
          )}
        </div>

        {/* Event type chips */}
        {availTypes.length > 0 && (
          <div>
            <div className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Event type</div>
            <div className="flex flex-wrap gap-2">
              {availTypes.map((k) => (
                <Chip key={k} on={types.has(k)} tone={TYPE_META[k].tone} count={kindCounts[k]} onClick={() => toggle('type', k)}>
                  {TYPE_META[k].label}
                </Chip>
              ))}
            </div>
          </div>
        )}

        {/* Severity chips */}
        {facets.severities.length > 0 && (
          <div>
            <div className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Severity</div>
            <div className="flex flex-wrap gap-2">
              {facets.severities.map((s) => (
                <Chip key={s.value} on={severities.has(s.value)} tone={severityMeta(s.value).tone} count={s.count} onClick={() => toggle('sev', s.value)}>
                  {s.label}
                </Chip>
              ))}
            </div>
          </div>
        )}

        {/* Dropdown facets + date range */}
        <div className="flex flex-wrap gap-3">
          {facets.garages.length > 0 && (
            <Field label="Garage">
              <Select value={garage} onChange={(v) => patch({ garage: v })}>
                <option value="">All garages</option>
                {facets.garages.map((g) => <option key={g.value} value={g.value}>{g.label} ({g.count})</option>)}
              </Select>
            </Field>
          )}
          {facets.inspectors.length > 0 && (
            <Field label="Inspector">
              <Select value={inspector} onChange={(v) => patch({ insp: v })}>
                <option value="">All inspectors</option>
                {facets.inspectors.map((g) => <option key={g.value} value={g.value}>{g.label} ({g.count})</option>)}
              </Select>
            </Field>
          )}
          {facets.drivers.length > 0 && (
            <Field label="Driver">
              <Select value={driver} onChange={(v) => patch({ driver: v })}>
                <option value="">All drivers</option>
                {facets.drivers.map((g) => <option key={g.value} value={g.value}>{g.label} ({g.count})</option>)}
              </Select>
            </Field>
          )}
          {facets.stages.length > 0 && (
            <Field label="Workflow stage">
              <Select value={stage} onChange={(v) => patch({ stage: v })}>
                <option value="">All stages</option>
                {facets.stages.map((g) => <option key={g.value} value={g.value}>{g.label} ({g.count})</option>)}
              </Select>
            </Field>
          )}
          <Field label="Status">
            <Select value={status} onChange={(v) => patch({ status: v })}>
              <option value="all">Open &amp; closed</option>
              <option value="open">Open only</option>
              <option value="closed">Closed only</option>
            </Select>
          </Field>
          <Field label="From date">
            <input type="date" value={from} onChange={(e) => patch({ from: e.target.value })} className={selectCls} />
          </Field>
          <Field label="To date">
            <input type="date" value={to} onChange={(e) => patch({ to: e.target.value })} className={selectCls} />
          </Field>
        </div>

        {/* Boolean flags */}
        <div className="flex flex-wrap gap-2">
          {FLAG_OPTIONS.map((f) => {
            const FIcon = f.Icon;
            return (
              <Chip key={f.key} on={flags.has(f.key)} tone="indigo" onClick={() => toggle('flags', f.key)}>
                <FIcon className="h-3.5 w-3.5" />{f.label}
              </Chip>
            );
          })}
        </div>

        {/* Sort + group */}
        <div className="flex flex-wrap gap-3 border-t border-slate-100 pt-3">
          <Field label="Sort by">
            <Select value={sort} onChange={(v) => patch({ sort: v })}>
              {Object.entries(SORTS).map(([k, m]) => <option key={k} value={k}>{m.label}</option>)}
            </Select>
          </Field>
          <Field label="Group by">
            <Select value={group} onChange={(v) => patch({ group: v })}>
              {Object.entries(GROUPS).map(([k, m]) => <option key={k} value={k}>{m.label}</option>)}
            </Select>
          </Field>
        </div>
      </div>

      {/* ── Results ── */}
      <div className="flex items-center justify-between px-1">
        <span className="text-sm text-slate-500">
          Showing <span className="font-semibold text-slate-800">{num(filtered.length)}</span> of {num(allEvents.length)} events
        </span>
      </div>

      {loading && !allEvents.length ? (
        <div className="flex justify-center py-16"><Icon.Refresh className="h-6 w-6 animate-spin text-slate-300" /></div>
      ) : filtered.length === 0 ? (
        <p className="rounded-2xl border border-dashed border-slate-200 py-12 text-center text-sm text-slate-400">
          {allEvents.length ? 'No events match these filters.' : 'No activity recorded for this car yet.'}
        </p>
      ) : grouped ? (
        <div className="space-y-6">
          {grouped.map((g) => (
            <section key={g.key}>
              <div className="mb-3 flex items-center gap-3">
                <h4 className="text-sm font-semibold text-slate-800">{g.label}</h4>
                <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500">{num(g.count)} {g.count === 1 ? 'event' : 'events'}</span>
                {g.sublabel && <span className="text-[11px] font-medium text-slate-400">{g.sublabel}</span>}
              </div>
              <EventList events={g.events} />
            </section>
          ))}
        </div>
      ) : (
        <EventList events={sorted} />
      )}
    </div>
  );
}
