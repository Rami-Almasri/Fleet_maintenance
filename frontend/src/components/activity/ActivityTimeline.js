import { useState } from 'react';
import { Link } from 'react-router-dom';
import Badge from '../ui/Badge';
import Icon from '../ui/Icon';
import { num, fmtDate, fmtClock, fmtAgo } from '../../lib/format';

// One vocabulary for the whole Activity Audit Trail — the six action categories the backend tags
// every event with. Each maps to a label (filter chip + badge), a tone (shared palette) and a glyph
// so a car's dirty→clean flip reads as instantly as a dispatch or a re-inspection.
export const CATEGORY_META = {
  inspection:  { label: 'Inspection',  tone: 'blue',    Icon: Icon.Shield },
  cleaning:    { label: 'Cleaning',    tone: 'cyan',    Icon: Icon.Spark },
  condition:   { label: 'Condition',   tone: 'amber',   Icon: Icon.Flag },
  readiness:   { label: 'Readiness',   tone: 'emerald', Icon: Icon.Check },
  maintenance: { label: 'Maintenance', tone: 'indigo',  Icon: Icon.Wrench },
  movement:    { label: 'Movement',    tone: 'violet',  Icon: Icon.Truck },
};

export const CATEGORY_KEYS = Object.keys(CATEGORY_META);

// The three super-tiers the Vehicle Life-Stream groups the six categories under. This is the coarse,
// scannable layer: hands-on-the-car TECHNICAL work, on-the-road OPERATIONAL moves, back-office
// ADMINISTRATIVE grading. Each has one dominant colour + glyph so a stream reads in 3 hues, not 6.
export const TIER_META = {
  technical:      { label: 'Technical',      tone: 'indigo', Icon: Icon.Wrench, hint: 'Inspections & maintenance' },
  operational:    { label: 'Operational',    tone: 'violet', Icon: Icon.Truck,  hint: 'Pickups & drop-offs' },
  administrative: { label: 'Administrative', tone: 'emerald', Icon: Icon.Check,  hint: 'Readiness, condition & cleaning' },
};

export const TIER_KEYS = Object.keys(TIER_META);

// category → tier, mirroring ActivityFeedService::CATEGORY_TIER so the frontend can bucket events even
// on older payloads that predate the backend `tier` field.
export const TIER_OF = {
  inspection: 'technical', maintenance: 'technical',
  movement: 'operational',
  readiness: 'administrative', condition: 'administrative', cleaning: 'administrative',
};

export const tierOf = (e) => e?.tier || TIER_OF[e?.category] || 'technical';

// Soft marker fills per tone (matches the vehicle-profile timeline styling).
const TONE_STYLE = {
  blue:    { soft: 'bg-blue-100',    text: 'text-blue-600',    ring: 'ring-blue-200',    spine: 'bg-blue-400' },
  cyan:    { soft: 'bg-cyan-100',    text: 'text-cyan-600',    ring: 'ring-cyan-200',    spine: 'bg-cyan-400' },
  amber:   { soft: 'bg-amber-100',   text: 'text-amber-600',   ring: 'ring-amber-200',   spine: 'bg-amber-400' },
  emerald: { soft: 'bg-emerald-100', text: 'text-emerald-600', ring: 'ring-emerald-200', spine: 'bg-emerald-400' },
  indigo:  { soft: 'bg-indigo-100',  text: 'text-indigo-600',  ring: 'ring-indigo-200',  spine: 'bg-indigo-400' },
  violet:  { soft: 'bg-violet-100',  text: 'text-violet-600',  ring: 'ring-violet-200',  spine: 'bg-violet-400' },
  slate:   { soft: 'bg-slate-100',   text: 'text-slate-500',   ring: 'ring-slate-200',   spine: 'bg-slate-300' },
};

const metaFor = (category) => CATEGORY_META[category] || { label: category, tone: 'slate', Icon: Icon.Activity };

// The plain-language milestone label the backend tags a row with ('Check-out', 'Garage Arrival',
// 'Ready for Rent'…). Falls back to the precise action for un-staged rows. `primary` marks the
// base↔garage movements the Story feed anchors on.
const stageOf = (e) => e?.stage || e?.action || 'Activity';
const isPrimaryMove = (e) => Boolean(e?.primary) || e?.stage === 'Check-out' || e?.stage === 'Check-in';

// ── Shared meta strip (odometer · garage · actor · photo · contract) ──────────────
function EventMeta({ e }) {
  const from = e.details?.from;
  const to = e.details?.to;
  const showTransition = (from || to) && !/→/.test(e.description || '');
  return (
    <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
      {showTransition && (
        <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 font-medium text-slate-600">
          {from || '—'} → {to || '—'}
        </span>
      )}
      {e.odometer != null && (
        <span className="inline-flex items-center gap-1.5 font-medium text-slate-600">
          <Icon.Gauge className="h-3.5 w-3.5 text-slate-400" /> {num(e.odometer)} km
        </span>
      )}
      {e.details?.garage && (
        <span className="inline-flex items-center gap-1.5 font-medium text-slate-600">
          <svg className="h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"><path d="M3 9l9-6 9 6v11a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z" /></svg>
          {e.details.garage}
        </span>
      )}
      <span className="inline-flex items-center gap-1.5">
        <Icon.Users className="h-3.5 w-3.5 text-slate-400" />
        <span className={e.actor_name === 'System' ? 'italic text-slate-400' : 'font-medium text-slate-600'}>{e.actor_name || 'System'}</span>
      </span>
      {e.photo_url && (
        <a href={e.photo_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 font-semibold text-indigo-600 hover:text-indigo-700">
          <Icon.Search className="h-3.5 w-3.5" /> Photo
        </a>
      )}
      {e.contract_id && (
        <Link to={`/contracts/${e.contract_id}`} className="font-medium text-indigo-600 hover:text-indigo-700">#{e.contract_no || e.contract_id}</Link>
      )}
    </div>
  );
}

// A single event card + its timeline marker. `showVehicle` adds the plate/model line (the manager
// feed spans the whole fleet; the per-car timeline already knows its car). `colorByTier` collapses the
// six category hues into the three super-tiers so a long life-stream reads in 3 colours, not 6 — the
// fine category still shows as a secondary chip. The headline is the plain-language STAGE; the precise
// action drops to a muted sub-label when the two differ.
export function ActivityItem({ e, showVehicle = false, colorByTier = false }) {
  const meta = metaFor(e.category);
  const tier = tierOf(e);
  const tm = TIER_META[tier];
  const tone = colorByTier
    ? tm.tone
    : (e.tone && TONE_STYLE[e.tone] ? e.tone : meta.tone);
  const st = TONE_STYLE[tone] || TONE_STYLE.slate;
  const Glyph = (colorByTier ? tm.Icon : meta.Icon) || Icon.Activity;
  const headline = stageOf(e);
  const subAction = e.stage && e.action && e.stage !== e.action ? e.action : null;

  return (
    <li className="relative flex gap-4">
      <span className={`relative z-10 mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full ring-4 ring-white ${st.soft} ${st.text}`}>
        <Glyph className="h-[18px] w-[18px]" />
      </span>

      <div className={`min-w-0 flex-1 rounded-2xl border bg-white p-4 shadow-soft ${e.flagged ? 'border-red-200 bg-red-50/30' : 'border-slate-200/60'}`}>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="flex flex-wrap items-center gap-2">
            {colorByTier ? (
              <>
                <Badge tone={tm.tone}>{tm.label}</Badge>
                <span className="text-[11px] font-medium uppercase tracking-wide text-slate-400">{meta.label}</span>
              </>
            ) : (
              <Badge tone={tone}>{meta.label}</Badge>
            )}
            <span className="text-sm font-semibold text-slate-800">{headline}</span>
            {isPrimaryMove(e) && <Badge tone="violet">Movement</Badge>}
            {e.flagged && <Badge tone="red">Damage</Badge>}
          </div>
          <span className="whitespace-nowrap text-xs font-medium text-slate-400" title={e.occurred_at ? `${fmtDate(e.occurred_at)} · ${fmtClock(e.occurred_at)}` : ''}>
            {e.occurred_at ? fmtAgo(e.occurred_at) : 'No date'}
          </span>
        </div>

        {subAction && <p className="mt-0.5 text-xs font-medium text-slate-400">{subAction}</p>}

        {showVehicle && e.vehicle_id && (
          <Link to={`/vehicles/${e.vehicle_id}`} className="mt-1 inline-flex items-center gap-1.5 text-xs font-medium text-indigo-600 hover:text-indigo-700">
            <Icon.Car className="h-3.5 w-3.5" />
            {e.plate || `#${e.vehicle_id}`}{e.model ? ` · ${e.model}` : ''}
          </Link>
        )}

        {e.description && <p className="mt-1.5 text-sm leading-relaxed text-slate-600">{e.description}</p>}

        <EventMeta e={e} />
      </div>
    </li>
  );
}

// ── Roll-up: cluster a car's consecutive events into one Maintenance Session ──────
// The feed interleaves the whole fleet by time; we key open sessions by vehicle so a car's burst
// (fault → dispatch → repair → ready) collapses into one card even when other cars' rows fall between
// them. A session extends while the next same-car event is within SESSION_GAP; a lone event stays a
// plain card. Input is newest-first; each session anchors at its newest event (preserving feed order).
const SESSION_GAP_MS = 8 * 60 * 60 * 1000; // 8h continuity window

export function groupSessions(events = [], gapMs = SESSION_GAP_MS) {
  const items = [];
  const openByVehicle = new Map();
  for (const e of events) {
    const vid = e.vehicle_id;
    const t = e.occurred_at ? Date.parse(e.occurred_at) : NaN;
    if (!vid || Number.isNaN(t)) {
      items.push({ type: 'event', key: e.id, e });
      continue;
    }
    const open = openByVehicle.get(vid);
    if (open && Number.isFinite(open.oldestT) && (open.oldestT - t) <= gapMs) {
      open.events.push(e);      // newest-first, so this is older than the last one
      open.oldestT = t;
    } else {
      const session = { type: 'session', key: `s-${e.id}`, vehicleId: vid, events: [e], newestT: t, oldestT: t };
      openByVehicle.set(vid, session);
      items.push(session);
    }
  }
  // A single-event "session" is just an event — render it without the roll-up chrome.
  return items.map((it) => (it.type === 'session' && it.events.length < 2)
    ? { type: 'event', key: it.events[0].id, e: it.events[0] }
    : it);
}

// Which tier dominates a session — decides the spine colour so a Check-out (operational/violet) reads
// differently from a Maintenance repair (technical/indigo) at a glance.
const TIER_PRIORITY = ['technical', 'operational', 'administrative'];
function dominantTier(evs) {
  const counts = {};
  for (const e of evs) { const t = tierOf(e); counts[t] = (counts[t] || 0) + 1; }
  return TIER_PRIORITY.reduce((best, t) => ((counts[t] || 0) > (counts[best] || 0) ? t : best), TIER_PRIORITY[0]);
}

// The one-line story: the milestone stages in order (oldest→newest), consecutive duplicates dropped,
// elided in the middle if long. "Check-out → Garage Arrival → Repair Complete → Check-in".
function stageJourney(chron) {
  const stages = [];
  for (const e of chron) {
    const s = stageOf(e);
    if (s && s !== stages[stages.length - 1]) stages.push(s);
  }
  if (stages.length <= 5) return stages.join('  →  ');
  return `${stages.slice(0, 2).join('  →  ')}  →  …  →  ${stages.slice(-2).join('  →  ')}`;
}

function sessionTitle(evs) {
  const hasTech = evs.some((e) => tierOf(e) === 'technical');
  const hasMove = evs.some((e) => e.category === 'movement');
  if (hasTech) return 'Maintenance Session';
  if (hasMove) return 'Vehicle Movement';
  return 'Activity Session';
}

// A compact sub-event row inside a session — movements stand out (bold + violet dot); technical and
// administrative logs sit as quieter secondary lines "inside" the movement they belong to.
function SubEvent({ e }) {
  const tier = tierOf(e);
  const st = TONE_STYLE[TIER_META[tier].tone] || TONE_STYLE.slate;
  const primary = isPrimaryMove(e);
  const headline = stageOf(e);
  const subAction = e.stage && e.action && e.stage !== e.action ? e.action : null;
  return (
    <li className="relative flex gap-3">
      <span className={`relative z-10 mt-1 flex h-2.5 w-2.5 shrink-0 items-center justify-center rounded-full ring-4 ring-white ${primary ? st.spine : st.soft}`} />
      <div className="min-w-0 flex-1 pb-0.5">
        <div className="flex flex-wrap items-baseline justify-between gap-x-2">
          <span className="flex items-center gap-1.5">
            {primary && <Icon.Truck className={`h-3.5 w-3.5 ${st.text}`} />}
            <span className={primary ? 'text-sm font-semibold text-slate-800' : 'text-sm font-medium text-slate-600'}>{headline}</span>
            {e.flagged && <Badge tone="red">Damage</Badge>}
          </span>
          <time className="whitespace-nowrap text-[11px] font-medium text-slate-400" title={e.occurred_at ? `${fmtDate(e.occurred_at)} · ${fmtClock(e.occurred_at)}` : ''}>
            {e.occurred_at ? fmtClock(e.occurred_at) : '—'}
          </time>
        </div>
        {subAction && <p className="text-[11px] font-medium text-slate-400">{subAction}</p>}
        {e.description && <p className="mt-0.5 text-xs leading-relaxed text-slate-500">{e.description}</p>}
        <EventMeta e={e} />
      </div>
    </li>
  );
}

// The roll-up card: one car's burst of activity as a single story — headline stage-journey, dominant
// tier spine, expandable list of the sub-events (movements first-class, technical logs nested inside).
function ActivitySession({ session, showVehicle = false }) {
  const [open, setOpen] = useState(false);
  const evs = session.events;                 // newest-first
  const chron = [...evs].slice().reverse();   // oldest-first for the narrative
  const anchor = evs[0];                        // newest — drives plate/time
  const tier = dominantTier(evs);
  const tm = TIER_META[tier];
  const st = TONE_STYLE[tm.tone] || TONE_STYLE.slate;
  const Glyph = tm.Icon || Icon.Activity;
  const title = sessionTitle(evs);
  const journey = stageJourney(chron);
  const flagged = evs.some((e) => e.flagged);
  const spanLabel = session.newestT && session.oldestT && session.newestT !== session.oldestT
    ? `${fmtClock(new Date(session.oldestT).toISOString())} – ${fmtClock(new Date(session.newestT).toISOString())}`
    : null;

  return (
    <li className="relative flex gap-4">
      <span className={`relative z-10 mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full ring-4 ring-white ${st.soft} ${st.text}`}>
        <Glyph className="h-[18px] w-[18px]" />
      </span>

      <div className={`min-w-0 flex-1 overflow-hidden rounded-2xl border bg-white shadow-soft ${flagged ? 'border-red-200' : 'border-slate-200/60'}`}>
        <button
          type="button"
          onClick={() => setOpen((o) => !o)}
          className="flex w-full items-start gap-3 p-4 text-left transition hover:bg-slate-50/70"
        >
          {/* tier spine */}
          <span aria-hidden className={`mt-0.5 h-full min-h-[2.5rem] w-1 shrink-0 self-stretch rounded-full ${st.spine}`} />
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="flex flex-wrap items-center gap-2">
                <Badge tone={tm.tone}>{tm.label}</Badge>
                <span className="text-sm font-semibold text-slate-800">{title}</span>
                <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500">{num(evs.length)} steps</span>
                {flagged && <Badge tone="red">Damage</Badge>}
              </div>
              <span className="whitespace-nowrap text-xs font-medium text-slate-400" title={spanLabel || (anchor.occurred_at ? `${fmtDate(anchor.occurred_at)} · ${fmtClock(anchor.occurred_at)}` : '')}>
                {anchor.occurred_at ? fmtAgo(anchor.occurred_at) : 'No date'}
              </span>
            </div>

            {showVehicle && anchor.vehicle_id && (
              <span className="mt-1 inline-flex items-center gap-1.5 text-xs font-medium text-indigo-600">
                <Icon.Car className="h-3.5 w-3.5" />
                {anchor.plate || `#${anchor.vehicle_id}`}{anchor.model ? ` · ${anchor.model}` : ''}
              </span>
            )}

            <p className="mt-1.5 text-sm leading-relaxed text-slate-600">{journey}</p>
            {spanLabel && <p className="mt-1 text-[11px] font-medium text-slate-400">{spanLabel}</p>}

            <span className="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-indigo-600">
              {open ? 'Hide steps' : 'Show steps'}
              <Icon.ChevronDown className={`h-3.5 w-3.5 transition-transform ${open ? 'rotate-180' : ''}`} />
            </span>
          </div>
        </button>

        {open && (
          <div className="border-t border-slate-100 bg-slate-50/50 px-4 py-3">
            <div className="relative">
              <span aria-hidden className="pointer-events-none absolute bottom-2 left-[0.3rem] top-2 w-px bg-slate-200" />
              <ol className="space-y-2.5">
                {chron.map((e) => <SubEvent key={e.id} e={e} />)}
              </ol>
            </div>
          </div>
        )}
      </div>
    </li>
  );
}

// The vertical feed: a connecting rail behind coloured markers, newest first. Renders its own empty
// state so callers just hand it `events`. With `group`, consecutive same-car bursts collapse into
// expandable Maintenance-Session cards (the Story view); without it, every row is a flat card.
export default function ActivityTimeline({
  events = [],
  showVehicle = false,
  colorByTier = false,
  group = false,
  gapHours = 8,
  emptyMessage = 'No activity recorded yet.',
}) {
  if (!events.length) {
    return (
      <p className="rounded-2xl border border-dashed border-slate-200 py-12 text-center text-sm text-slate-400">
        {emptyMessage}
      </p>
    );
  }
  const items = group
    ? groupSessions(events, gapHours * 60 * 60 * 1000)
    : events.map((e) => ({ type: 'event', key: e.id, e }));

  return (
    <div className="relative">
      <span aria-hidden className="pointer-events-none absolute bottom-4 left-[1.125rem] top-4 w-px bg-gradient-to-b from-slate-200 via-slate-200 to-transparent" />
      <ol className="stagger space-y-3">
        {items.map((it) => (it.type === 'session'
          ? <ActivitySession key={it.key} session={it} showVehicle={showVehicle} />
          : <ActivityItem key={it.key} e={it.e} showVehicle={showVehicle} colorByTier={colorByTier} />))}
      </ol>
    </div>
  );
}
