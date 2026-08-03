// Inspection Request Review Gate — the Controllers' (Lin & Marwa) queue of Driver/system-generated
// inspection requests awaiting approval before they are sent to the Inspector (Abu Maroof).
// Approve sends the request on exactly as before; reject terminates it (requires a reason).

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import InspectionReviewAnalytics from '../components/analytics/InspectionReviewAnalytics';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { usePermissions } from '../hooks/usePermissions';
import { useToast } from '../components/ui/Toast';
import Button from '../components/ui/Button';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import Modal from '../components/ui/Modal';
import { Textarea } from '../components/ui/Field';
import { EmptyState } from '../components/ui/Misc';
import { Skeleton } from '../components/ui/Skeleton';
import TicketActionModal from '../components/workflow/TicketActionModal';
import ComplaintIntakeModal from '../components/workflow/ComplaintIntakeModal';
import SuggestedChecks from '../components/workflow/SuggestedChecks';

// Note: `customer_reported` here is a LEGACY driver-request reason — real customer complaints are their own
// entity now (Complaints Center), so it reads "Customer-reported", not "Customer complaint".
// The reason answers WHY the car needs attention. It used to double as the source ("Routine (system)"
// fused both), which is why an escalated Driver Observation read as scheduled service. WHERE the request
// came from is now its own field — request_origin — rendered separately as the Source line below.
const REASON_LABEL = {
  test_drive: 'Test drive',
  customer_reported: 'Customer-reported',
  periodic: 'Routine',
  driver_reported: 'Driver reported issue',
};
const REASON_TONE = { test_drive: 'violet', customer_reported: 'amber', periodic: 'blue', driver_reported: 'emerald' };

// request_origin → the human "Source:" label. Mirrors Maintenance::REQUEST_ORIGIN_LABELS (backend
// CONTRACT); an unknown value falls back to the raw key rather than being hidden.
const ORIGIN_LABEL = {
  driver_observation: 'Driver Observation',
  driver_request: 'Driver Request',
  controller: 'Controller',
  inspector: 'Inspector',
  system_schedule: 'System Schedule',
  workshop: 'Workshop',
  customer: 'Customer Report',
};

const ORIGIN_TONE = {
  driver_observation: 'emerald',
  driver_request: 'cyan',
  controller: 'blue',
  inspector: 'violet',
  system_schedule: 'indigo',
  workshop: 'red',
  customer: 'amber',
};

// The car's live operational status → a small context pill on the card, so the reviewer knows at a
// glance whether the car is free to inspect before deciding.
const STATUS_META = {
  available:   { label: 'Available',      cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
  ready:       { label: 'Available',      cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
  rented:      { label: 'On rent',        cls: 'bg-amber-50 text-amber-700 ring-amber-200' },
  maintenance: { label: 'In Workshop',    cls: 'bg-rose-50 text-rose-700 ring-rose-200' },
  in_transit:  { label: 'In transit',     cls: 'bg-blue-50 text-blue-700 ring-blue-200' },
};

function StatusPill({ status }) {
  const meta = STATUS_META[status] || { label: String(status).replace(/_/g, ' '), cls: 'bg-slate-100 text-slate-600 ring-slate-200' };
  return (
    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 ring-inset ${meta.cls}`}>
      {meta.label}
    </span>
  );
}

// Rule severity → the colour of the dot next to each system-detected rule.
const SEV_DOT = { critical: 'bg-red-500', moderate: 'bg-amber-500', routine: 'bg-emerald-500' };

function ago(iso) {
  if (!iso) return '';
  const secs = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
  if (secs < 90) return 'just now';
  const mins = Math.round(secs / 60);
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.round(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  return `${Math.round(hrs / 24)}d ago`;
}

const km = (n) => (n === null || n === undefined ? null : `${Number(n).toLocaleString()} km`);

function dueDate(iso) {
  if (!iso) return null;
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return null;
  return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

// The "why the system flagged this" panel — only rendered for a system-generated request that carries a
// trigger_detail snapshot. Shows each rule that fired (with its human reason) and the
// mileage/threshold/overdue/due values behind it.
//
// WHAT IT NO LONGER SHOWS: a "Suggested checks for the inspector" chip row. That row used to fall back
// to `rules.flatMap(r => r.finding_keywords)` whenever the ticket's own suggested_findings was empty —
// which, for an idle car, is the fixed post-downtime checklist (Battery Replacement / Low fluid level /
// Brake noise). The backend deliberately excludes that checklist from suggested_findings
// (InspectionsGenerateTasks::suggestedFindings) and a command exists purely to strip it from tickets
// that already stored it (InspectionsCleanSuggestedFindings) — so this fallback was quietly undoing
// both, and printing the same three "findings" on every car. Per-car checks now come from
// <SuggestedChecks>, and the standing checklist is rendered there as the agenda it is.
function SystemDetail({ detail, suggested }) {
  const rules = Array.isArray(detail?.rules) ? detail.rules : [];
  const svc = detail?.service || null;

  const values = [
    ['Current mileage', km(svc?.current_km)],
    ['Service interval', km(svc?.interval_km)],
    ['Overdue by', km(svc?.overdue_km)],
    ['Next due', dueDate(svc?.next_due_at)],
  ].filter(([, v]) => v);

  // Only the ticket's OWN stored suggestions — never re-derived from the checklist rules.
  const chips = (suggested || []).filter((v, i, a) => v && a.indexOf(v) === i);

  return (
    <div className="mt-3 rounded-lg border border-indigo-100 bg-indigo-50/50 px-3 py-2.5">
      <div className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-indigo-700">
        <span aria-hidden>🤖</span> Why the system flagged this
      </div>

      {rules.length > 0 && (
        <ul className="mt-1.5 space-y-1.5">
          {rules.map((r, i) => (
            <li key={r.key || i} className="flex items-start gap-2 text-xs text-slate-700">
              <span className={`mt-1 h-1.5 w-1.5 shrink-0 rounded-full ${SEV_DOT[r.severity] || 'bg-slate-400'}`} />
              <span>
                <span className="font-medium text-slate-800">{r.label}</span>
                {r.why && <span className="text-slate-500"> — {r.why}</span>}
              </span>
            </li>
          ))}
        </ul>
      )}

      {values.length > 0 && (
        <dl className="mt-2 grid grid-cols-2 gap-x-3 gap-y-1">
          {values.map(([label, value]) => (
            <div key={label} className="flex items-center justify-between gap-2 text-[11px]">
              <dt className="text-slate-400">{label}</dt>
              <dd className="font-mono font-medium text-slate-700">{value}</dd>
            </div>
          ))}
        </dl>
      )}

      {chips.length > 0 && (
        <div className="mt-2">
          <p className="text-[11px] text-slate-400">What this request is due for</p>
          <div className="mt-1 flex flex-wrap gap-1">
            {chips.map((c) => (
              <span key={c} className="rounded-full bg-white px-2 py-0.5 text-[11px] font-medium text-indigo-700 ring-1 ring-inset ring-indigo-200">
                {c}
              </span>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

// ── "When & why the system asks for a test" ────────────────────────────────────────────────────
// The rulebook explainer under the page header. A Controller sees "🤖 System Schedule" requests land in
// this queue with no human behind them; this panel answers what raised it and on what threshold, so the
// gate is not a black box (see [[traceability-visibility-requirement]]).
//
// Every number here comes from GET /maintenance-tickets/review-gate-rules — i.e. from
// DiagnosticGateService::rulebook(), the same service that decides what's due. Nothing is retyped in the
// frontend, so changing DIAGNOSTIC_GATE_DOWNTIME_DAYS changes this panel too. Collapsed by default —
// it's reference material, not something to read on every visit.
// One visual identity per rule — the icon and accent colour are presentation, so they live here and are
// keyed off the rule's stable `key`. An unknown key still renders, in neutral slate: the backend owns the
// list of rules, and this map must never be the thing that decides whether one is shown.
const RULE_SKIN = {
  oil_change: { icon: '🛢️', ring: 'ring-amber-200',   tint: 'bg-amber-50',   ink: 'text-amber-700',   bar: 'bg-amber-400' },
  reminders:  { icon: '🔧', ring: 'ring-sky-200',     tint: 'bg-sky-50',     ink: 'text-sky-700',     bar: 'bg-sky-400' },
  battery:    { icon: '🔋', ring: 'ring-emerald-200', tint: 'bg-emerald-50', ink: 'text-emerald-700', bar: 'bg-emerald-400' },
  downtime:   { icon: '🗓️', ring: 'ring-violet-200',  tint: 'bg-violet-50',  ink: 'text-violet-700',  bar: 'bg-violet-400' },
  inactivity: { icon: '🅿️', ring: 'ring-slate-200',   tint: 'bg-slate-50',   ink: 'text-slate-700',   bar: 'bg-slate-400' },
};
const RULE_SKIN_FALLBACK = { icon: '•', ring: 'ring-slate-200', tint: 'bg-slate-50', ink: 'text-slate-700', bar: 'bg-slate-400' };

// The urgency the rule carries into the workshop. Shown as a word, not a colour alone — a colour-blind
// reader gets the same information.
const SEVERITY_WORD = {
  moderate: { label: 'Needs attention', cls: 'bg-amber-100 text-amber-800' },
  routine:  { label: 'Routine',         cls: 'bg-emerald-100 text-emerald-800' },
  critical: { label: 'Urgent',          cls: 'bg-rose-100 text-rose-800' },
};

function severityChip(severity) {
  const first = String(severity || '').split('/')[0].trim();
  return SEVERITY_WORD[first] || { label: severity || '—', cls: 'bg-slate-100 text-slate-700' };
}

// One rule = one card. Big threshold on the right so the limits ("15 days", "30 months") are scannable
// without reading a word; the plain sentence carries the meaning, the note carries the nuance.
function RuleCard({ rule }) {
  const skin = RULE_SKIN[rule.key] || RULE_SKIN_FALLBACK;
  const sev = severityChip(rule.severity);

  return (
    <div className={`relative overflow-hidden rounded-xl bg-white p-3.5 pl-4 shadow-sm ring-1 ring-inset ${skin.ring}`}>
      <span className={`absolute inset-y-0 left-0 w-1 ${skin.bar}`} aria-hidden />
      <div className="flex items-start gap-3">
        <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-lg ${skin.tint}`} aria-hidden>
          {skin.icon}
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h4 className="text-sm font-bold text-slate-800">{rule.label}</h4>
            <span className={`rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${sev.cls}`}>
              {sev.label}
            </span>
          </div>
          <p className="mt-1 text-[13px] leading-relaxed text-slate-700">{rule.plain || rule.when}</p>
          {rule.note && <p className="mt-1 text-xs leading-relaxed text-slate-500">{rule.note}</p>}
          {rule.agenda && (
            <p className="mt-2 flex items-center gap-1.5 border-t border-slate-100 pt-2 text-[11px] text-slate-500">
              <span aria-hidden>👉</span>
              <span>The inspector is asked to check: <span className="font-semibold text-slate-700">{rule.agenda}</span></span>
            </p>
          )}
        </div>
        {(rule.chip || rule.threshold) && (
          <span className={`shrink-0 rounded-lg px-2 py-1 text-center text-[11px] font-bold leading-tight ${skin.tint} ${skin.ink}`}>
            {rule.chip || rule.threshold}
          </span>
        )}
      </div>
    </div>
  );
}

// The four-step journey strip. Numbered, so it reads as a sequence rather than four unrelated boxes.
function FlowStrip({ steps }) {
  return (
    <ol className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
      {steps.map((s, i) => (
        <li key={s.title} className="relative rounded-xl bg-white p-3 shadow-sm ring-1 ring-inset ring-slate-200">
          <span className="flex h-6 w-6 items-center justify-center rounded-full bg-indigo-600 text-[11px] font-bold text-white">
            {i + 1}
          </span>
          <p className="mt-2 text-[13px] font-bold text-slate-800">{s.title}</p>
          <p className="mt-0.5 text-xs leading-relaxed text-slate-600">{s.text}</p>
        </li>
      ))}
    </ol>
  );
}

function SectionTitle({ children, hint }) {
  return (
    <div className="mb-2 flex items-baseline gap-2">
      <h3 className="text-[13px] font-bold uppercase tracking-wide text-slate-700">{children}</h3>
      {hint && <span className="text-xs text-slate-400">{hint}</span>}
    </div>
  );
}

function SystemRulesPanel() {
  const [open, setOpen] = useState(false);
  const [book, setBook] = useState(null);
  const [state, setState] = useState('idle'); // idle | loading | error
  // Fetch-once guard. It must be a ref, NOT the `state` value: setState('loading') re-renders, and an
  // effect that re-runs tears down its own previous cleanup — an `alive` flag there would cancel the very
  // request it just fired, leaving the panel on a blank skeleton forever.
  const fetched = useRef(false);
  // Set to true on EVERY mount, not just at declaration: React 18 StrictMode mounts, unmounts and
  // re-mounts in dev, so a ref initialised once to `true` is left `false` by that first simulated
  // unmount — and every later response gets thrown away as "component is gone".
  const mounted = useRef(true);
  useEffect(() => {
    mounted.current = true;
    return () => { mounted.current = false; };
  }, []);

  // Fetched lazily on first expand — a Controller working the queue shouldn't pay for reference copy.
  useEffect(() => {
    if (!open || fetched.current) return;
    fetched.current = true;
    setState('loading');
    api.get('/maintenance-tickets/review-gate-rules')
      .then((r) => {
        if (!mounted.current) return;
        setBook(r.data?.data || null);
        setState('idle');
      })
      .catch(() => {
        if (!mounted.current) return;
        fetched.current = false; // let a re-open retry
        setState('error');
      });
  }, [open]);

  return (
    <section className="overflow-hidden rounded-2xl bg-white shadow-soft ring-1 ring-inset ring-indigo-200">
      {/* ── Banner: readable as a single line when collapsed ─────────────────────────── */}
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        className="flex w-full items-center gap-3 bg-gradient-to-r from-indigo-600 to-indigo-500 px-4 py-3.5 text-left transition-opacity hover:opacity-95"
      >
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/15 text-lg" aria-hidden>🤖</span>
        <span className="min-w-0 flex-1">
          <span className="block text-sm font-bold text-white">Why does the system ask for a test?</span>
          <span className="block text-xs text-indigo-100">
            Some requests below were raised by the system, not by a person. This explains when, and why.
          </span>
        </span>
        <span className="shrink-0 rounded-full bg-white/15 px-2.5 py-1 text-xs font-semibold text-white">
          {open ? 'Hide' : 'Read this'}
        </span>
      </button>

      {open && (
        <div className="px-4 py-5 sm:px-5">
          {state === 'loading' && (
            <div className="space-y-2">
              <Skeleton className="h-5 w-2/3 rounded" />
              <Skeleton className="h-20 rounded-lg" />
            </div>
          )}
          {state === 'error' && (
            <p className="text-sm text-rose-600">Could not load this explanation — close and open it again to retry.</p>
          )}
          {state === 'idle' && !book && (
            <p className="text-sm text-slate-500">Nothing came back — the automatic check may not be switched on.</p>
          )}

          {book && (
            <div className="space-y-6">
              {/* 1 ── The one sentence that answers the question. */}
              <div className="flex flex-wrap items-start justify-between gap-3">
                <p className="max-w-2xl text-[15px] font-medium leading-relaxed text-slate-800">
                  {book.headline}
                </p>
                <span className="flex shrink-0 items-center gap-1.5 rounded-full bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white">
                  <Icon.Clock className="h-3.5 w-3.5" />
                  Checked every day at {book.schedule?.runs_at || '—'}
                </span>
              </div>

              {book.enabled === false && (
                <p className="rounded-lg bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 ring-1 ring-inset ring-rose-200">
                  ⚠ The automatic check is currently switched off — no new system requests are being raised.
                </p>
              )}

              {/* 2 ── The journey, so the request in the queue has a visible origin. */}
              {Array.isArray(book.steps) && book.steps.length > 0 && (
                <div>
                  <SectionTitle hint="from the daily check to your decision">How it works</SectionTitle>
                  <FlowStrip steps={book.steps} />
                </div>
              )}

              {/* 3 ── The rules themselves: the heart of the panel. */}
              {Array.isArray(book.rules) && book.rules.length > 0 && (
                <div>
                  <SectionTitle hint="any one of these is enough">What makes a car due for a test</SectionTitle>
                  <div className="grid grid-cols-1 gap-2.5 lg:grid-cols-2">
                    {book.rules.map((r) => <RuleCard key={r.key} rule={r} />)}
                  </div>
                </div>
              )}

              {/* 4 ── The two "and it does NOT…" halves, side by side. */}
              <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                {Array.isArray(book.preconditions) && book.preconditions.length > 0 && (
                  <div className="rounded-xl bg-emerald-50/60 p-3.5 ring-1 ring-inset ring-emerald-100">
                    <SectionTitle>Which cars it checks</SectionTitle>
                    <ul className="space-y-1.5">
                      {book.preconditions.map((p) => (
                        <li key={p} className="flex items-start gap-2 text-[13px] leading-relaxed text-slate-700">
                          <Icon.Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" />
                          <span>{p}</span>
                        </li>
                      ))}
                    </ul>
                  </div>
                )}

                {Array.isArray(book.suppressions) && book.suppressions.length > 0 && (
                  <div className="rounded-xl bg-amber-50/60 p-3.5 ring-1 ring-inset ring-amber-100">
                    <SectionTitle hint="so you can tell “not due” from “held back”">What it will not ask for</SectionTitle>
                    <ul className="space-y-2">
                      {book.suppressions.map((s) => (
                        <li key={s.label} className="text-[13px] leading-relaxed text-slate-700">
                          <span className="font-semibold text-slate-800">{s.label}</span>
                          <span className="block text-xs leading-relaxed text-slate-600">{s.why}</span>
                        </li>
                      ))}
                    </ul>
                  </div>
                )}
              </div>

              {/* 5 ── Where it lands and who decides — the operator's own part. */}
              {book.outcome && (
                <div className="flex items-start gap-3 rounded-xl bg-indigo-50 p-3.5 ring-1 ring-inset ring-indigo-100">
                  <span className="text-lg" aria-hidden>🙋</span>
                  <p className="text-[13px] leading-relaxed text-indigo-950">
                    <span className="font-bold">Your part: </span>{book.outcome}
                  </p>
                </div>
              )}

              {/* 6 ── Engine vocabulary lives here, out of the operator's way. */}
              <details className="rounded-lg bg-slate-50 px-3 py-2 ring-1 ring-inset ring-slate-200">
                <summary className="cursor-pointer text-xs font-semibold text-slate-600">Technical details</summary>
                <div className="mt-2 space-y-1.5 text-[11px] text-slate-500">
                  <p>
                    Job: <code className="rounded bg-slate-900/90 px-1.5 py-0.5 font-mono text-slate-100">{book.schedule?.command}</code>
                    {' '}— {book.schedule?.frequency} at {book.schedule?.runs_at}. {book.schedule?.description}
                  </p>
                  {book.limits && (
                    <p className="font-mono">
                      downtime_days={book.limits.downtime_days} · inactive_days={book.limits.inactive_days} ·
                      battery_life_months={book.limits.battery_life_months} · oil_ceiling_floor_km={book.limits.oil_ceiling_floor_km}
                    </p>
                  )}
                  <p>
                    Data Origin: every limit above is read live from <code className="font-mono">DiagnosticGateService</code>
                    {' '}(config <code className="font-mono">features.diagnostic_gate</code>) — the same code that decides what is
                    due, so this page cannot describe a rule that is not the one running.
                  </p>
                </div>
              </details>
            </div>
          )}
        </div>
      )}
    </section>
  );
}

// Exact, human timestamp — shown as the tooltip on relative times and as a secondary line.
function fmtDateTime(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

// Fault severity → priority chip. Only meaningful once the inspector has graded the fault; a fresh
// driver request has none yet (it's set at the Decide step).
const SEV_META = {
  critical: { label: 'Critical', emoji: '🔴', cls: 'bg-rose-50 text-rose-700 ring-rose-200' },
  high:     { label: 'High',     emoji: '🟠', cls: 'bg-orange-50 text-orange-700 ring-orange-200' },
  moderate: { label: 'Moderate', emoji: '🟡', cls: 'bg-amber-50 text-amber-700 ring-amber-200' },
  routine:  { label: 'Routine',  emoji: '🟢', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
};

// Vehicle avatar — the fleet has no photo column, so we render a branded make-initials glyph tile
// (a car silhouette watermark behind the make's first letters) as a consistent stand-in.
function VehicleAvatar({ make }) {
  const initials = (make || '?').trim().slice(0, 2).toUpperCase();
  return (
    <div className="relative flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-700 text-white ring-1 ring-inset ring-white/20">
      <Icon.Car className="absolute h-9 w-9 opacity-20" />
      <span className="relative text-sm font-bold tracking-wide">{initials}</span>
    </div>
  );
}

// One compact info tile: icon + label on top, value (+ optional sub) below. The grid of these is the
// card's "at a glance" data block.
function MetaTile({ icon, label, value, sub, muted }) {
  return (
    <div className="rounded-lg bg-slate-50 px-2.5 py-2 ring-1 ring-inset ring-slate-100">
      <p className="flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide text-slate-400">
        {icon}{label}
      </p>
      <p className={`mt-0.5 truncate text-xs font-semibold ${muted ? 'text-slate-400' : 'text-slate-700'}`} title={typeof value === 'string' ? value : undefined}>
        {value ?? '—'}
      </p>
      {sub && <p className="truncate text-[10px] text-slate-400">{sub}</p>}
    </div>
  );
}

function RequestCard({ tk, onApprove, onReject, onAcknowledge, ackBusy, highlight }) {
  const [expanded, setExpanded] = useState(false);
  const reasonTone = REASON_TONE[tk.trigger_reason] || 'slate';
  const reasonLabel = REASON_LABEL[tk.trigger_reason] || tk.trigger_reason;
  const requested = tk.handoffs?.requested;
  // WHERE the request came from. The stored origin is authoritative; the "no human requester" guess is
  // only the fallback for rows written before the column existed.
  const origin = tk.request_origin || null;
  const originLabel = tk.request_origin_label || ORIGIN_LABEL[origin] || origin;
  // System-generated = raised by the scheduler, not merely missing a requester (an escalated Driver
  // Observation has no requester either, and is emphatically NOT a system request).
  const isSystem = origin ? origin === 'system_schedule' : !requested?.user_id;
  const fromObservation = origin === 'driver_observation';
  const isLegacy = !!tk.is_legacy_unreviewed;
  // The car is out on hire — it can't be sent for inspection until it's physically back, so approval is
  // held (the downtime clock still counts against it; see the 15-day test-based rule).
  const awaitingReturn = tk.operational_status === 'rented';

  const sev = tk.fault_severity ? SEV_META[tk.fault_severity] : null;
  const complaint = (tk.customer_complaint || '').trim();
  const isLong = complaint.length > 140;
  const identityBits = [tk.vehicle_year, tk.vehicle_code && `#${tk.vehicle_code}`].filter(Boolean);

  // Km driven since the last oil service (server computes it from the vehicle's service anchor).
  const kmSince = tk.last_service?.km_since;

  // Last-ready anchor — the SAME record the Post-Downtime check counts from. When its source is
  // 'onboarding' the car has never actually been serviced, and the clock runs from its onboarding date
  // (that's the "N days since last maintenance" the system flag shows).
  const lm = tk.last_maintenance;
  const lmOnboard = !!lm && (lm.reason === 'onboarding' || lm.source === 'onboarding');

  return (
    <div
      id={`review-card-${tk.id}`}
      className={`scroll-mt-24 overflow-hidden rounded-2xl border bg-white shadow-soft transition-all duration-300 hover:shadow-md ${
        highlight
          ? 'border-indigo-400 ring-2 ring-indigo-400 ring-offset-2 shadow-lg'
          : isSystem ? 'border-indigo-200' : 'border-slate-200'
      }`}
    >
      {/* ── Header: vehicle identity (primary) + classification badges ───────────────── */}
      <div className="flex items-start gap-3 border-b border-slate-100 p-4">
        <VehicleAvatar make={tk.vehicle_make} />
        <div className="min-w-0 flex-1">
          <div className="flex items-start justify-between gap-2">
            <Link to={`/vehicles/${tk.vehicle_id}`} className="truncate font-mono text-xl font-extrabold leading-tight text-slate-900 hover:text-indigo-600">
              {tk.plate || `#${tk.id}`}
            </Link>
            <div className="flex shrink-0 flex-col items-end gap-1">
              {/* Reason (WHY) and Source (WHERE FROM) are two independent facts — both are shown, and
                  neither is inferred from the other. */}
              <Badge tone={reasonTone}>{reasonLabel}</Badge>
              {originLabel && (
                <Badge tone={ORIGIN_TONE[origin] || 'slate'}>
                  {isSystem && <span aria-hidden>🤖</span>} {originLabel}
                </Badge>
              )}
              {sev && (
                <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ring-1 ring-inset ${sev.cls}`}>
                  <span aria-hidden>{sev.emoji}</span> {sev.label}
                </span>
              )}
            </div>
          </div>
          <p className="truncate text-sm font-semibold text-slate-600">{tk.car || 'Vehicle'}</p>
          <div className="mt-1.5 flex flex-wrap items-center gap-2">
            {tk.operational_status && <StatusPill status={tk.operational_status} />}
            {tk.vehicle_odometer != null && (
              <span className="inline-flex items-center gap-1 font-mono text-[11px] text-slate-500">
                <Icon.Gauge className="h-3.5 w-3.5 text-slate-400" />{Number(tk.vehicle_odometer).toLocaleString()} km
              </span>
            )}
            {identityBits.length > 0 && (
              <span className="text-[11px] text-slate-400">{identityBits.join(' · ')}</span>
            )}
          </div>
        </div>
      </div>

      <div className="space-y-3 p-4">
        {/* ── Driver's report (expandable when long) ───────────────────────────────── */}
        {complaint && (
          <div className="rounded-lg bg-slate-50 px-3 py-2 ring-1 ring-inset ring-slate-100">
            <p className="mb-0.5 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
              <Icon.Flag className="h-3 w-3" />
              {isSystem ? 'Flagged reason' : fromObservation ? 'What the driver observed' : 'What the driver reported'}
            </p>
            <p className={`text-xs italic text-slate-600 ${isLong && !expanded ? 'line-clamp-2' : ''}`}>“{complaint}”</p>
            {isLong && (
              <button type="button" onClick={() => setExpanded((v) => !v)} className="mt-0.5 text-[11px] font-semibold text-indigo-600 hover:text-indigo-700">
                {expanded ? 'Show less' : 'Show more'}
              </button>
            )}
            {/* Data Origin — this text did not start life as an inspection request; say where it came
                from and link back to the record that owns it (see [[traceability-visibility-requirement]]). */}
            {fromObservation && (
              <p className="mt-1.5 border-t border-slate-200/70 pt-1.5 text-[11px] text-slate-400">
                Escalated from a{' '}
                <Link to="/driver-observations" className="font-semibold text-indigo-600 hover:text-indigo-700">
                  Driver Observation
                </Link>
                {tk.driver_observation_id ? ` #${tk.driver_observation_id}` : ''} — logged as a note first, then raised for inspection.
              </p>
            )}
          </div>
        )}

        {/* ── Attachments — the driver's photos/videos as previews ─────────────────── */}
        {Array.isArray(tk.media) && tk.media.length > 0 && (
          <div>
            <p className="mb-1.5 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
              <Icon.Camera className="h-3 w-3" /> Attachments
              <span className="rounded-full bg-slate-100 px-1.5 text-[10px] font-bold text-slate-500">{tk.media.length}</span>
            </p>
            <div className="flex flex-wrap gap-2">
              {tk.media.map((m) => (
                <a
                  key={m.id}
                  href={m.url}
                  target="_blank"
                  rel="noreferrer"
                  title={m.note || m.original_name || (m.kind === 'image' ? 'Photo' : 'Video')}
                  className="relative block h-16 w-16 shrink-0 overflow-hidden rounded-lg ring-1 ring-slate-200 transition hover:ring-2 hover:ring-indigo-400"
                >
                  {m.kind === 'image' && m.url ? (
                    <img src={m.url} alt={m.original_name || 'Driver photo'} className="h-full w-full object-cover" />
                  ) : (
                    <span className="flex h-full w-full items-center justify-center bg-slate-800 text-white">
                      <Icon.Video className="h-5 w-5" />
                    </span>
                  )}
                </a>
              ))}
            </div>
          </div>
        )}

        {isSystem && (tk.trigger_detail || (tk.suggested_findings || []).length > 0) && (
          <SystemDetail detail={tk.trigger_detail} suggested={tk.suggested_findings} />
        )}

        {/* Per-car suggested checks — this car's own repeat faults + service forecast. Rendered for
            EVERY request, not just system-raised ones: a driver reporting a noise on a car that has
            been back for brakes four times is exactly when the reviewer needs to know. Fetches itself
            when the card scrolls into view, so a 140-card queue stays fast. Read-only here (no
            onPick) — the reviewer approves or rejects; the inspector does the tapping at Decide. */}
        <SuggestedChecks vehicleId={tk.vehicle_id} />

        {/* ── At-a-glance data grid ────────────────────────────────────────────────── */}
        <div className="grid grid-cols-2 gap-2">
          <MetaTile
            icon={<Icon.Users className="h-3 w-3" />}
            label="Requested by"
            value={isSystem ? 'System' : (requested?.name || 'Driver')}
            sub={requested?.at ? ago(requested.at) : null}
          />
          <MetaTile
            icon={<Icon.Clock className="h-3 w-3" />}
            label="Requested"
            value={requested?.at ? fmtDateTime(requested.at) : '—'}
          />
          <MetaTile
            icon={<Icon.Wrench className="h-3 w-3" />}
            label="Last maintenance"
            value={!lm ? 'No record' : (lmOnboard ? 'None yet' : (dueDate(lm.at) || '—'))}
            sub={!lm
              ? null
              : (lmOnboard
                ? (lm.days_ago != null ? `${lm.days_ago}d since onboarding` : 'since onboarding')
                : `${lm.days_ago != null ? `${lm.days_ago}d ago` : ''}${lm.reason === 'test' ? ' · inspection' : ''}`.trim())}
            muted={!lm || lmOnboard}
          />
          <MetaTile
            icon={<Icon.Gauge className="h-3 w-3" />}
            label="Since oil service"
            value={tk.last_service
              ? (kmSince != null ? `${Number(kmSince).toLocaleString()} km` : `${Number(tk.last_service.odometer).toLocaleString()} km`)
              : 'No record'}
            sub={tk.last_service
              ? (kmSince != null ? `since ${Number(tk.last_service.odometer).toLocaleString()} km` : 'at last service')
              : null}
            muted={!tk.last_service}
          />
        </div>

        {isLegacy && (
          <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 ring-1 ring-inset ring-amber-200">
            Raised before this review queue existed — already in Abu Maroof's queue and actionable there.
            Acknowledge to close the sign-off gap; nothing else changes.
          </p>
        )}

        {!isLegacy && awaitingReturn && (
          <p className="flex items-center gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 ring-1 ring-inset ring-amber-200">
            <Icon.Clock className="h-3.5 w-3.5 shrink-0" />
            Waiting for return — the car is with a customer. Review it once it's back and available to inspect.
          </p>
        )}
      </div>

      {/* ── Actions ──────────────────────────────────────────────────────────────── */}
      <div className="flex items-center justify-end gap-2 border-t border-slate-100 bg-slate-50/50 px-4 py-3">
        {isLegacy ? (
          <Button variant="secondary" loading={ackBusy} onClick={() => onAcknowledge(tk)}>
            <Icon.Check className="h-4 w-4" /> Acknowledge
          </Button>
        ) : (
          <>
            <Button variant="danger" disabled={awaitingReturn} onClick={() => onReject(tk)}>
              <Icon.XCircle className="h-4 w-4" /> Reject
            </Button>
            <Button variant="success" disabled={awaitingReturn} onClick={() => onApprove(tk)}>
              <Icon.Check className="h-4 w-4" /> Approve &amp; send
            </Button>
          </>
        )}
      </div>
    </div>
  );
}

function ApproveModal({ ticket, onClose, onDone }) {
  const toast = useToast();
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    setBusy(true);
    try {
      // No reviewer note is collected: the card already shows what was reported and where it came
      // from, and that text travels with the ticket to the inspector. A second free-text box here
      // only invited a restatement of it. (The API still accepts `notes` for legacy/other callers.)
      await api.post(`/maintenance-tickets/${ticket.id}/review/approve`, {});
      onDone('Approved — sent to Abu Maroof');
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not approve this request');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open
      onClose={onClose}
      title="Approve inspection request"
      subtitle={`${ticket.plate || `#${ticket.id}`} · will be sent to Abu Maroof`}
      footer={(
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button variant="success" onClick={submit} loading={busy}>Approve &amp; send</Button>
        </>
      )}
    >
      {/* Confirm what actually travels to the inspector, rather than asking for it again. */}
      <p className="text-sm text-slate-600">
        Abu Maroof will receive this request with everything already on the card
        {ticket.customer_complaint ? ' — including the note below.' : '.'}
      </p>
      {ticket.customer_complaint && (
        <p className="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-sm italic text-slate-600 ring-1 ring-inset ring-slate-100">
          “{ticket.customer_complaint}”
        </p>
      )}
    </Modal>
  );
}

function RejectModal({ ticket, onClose, onDone }) {
  const toast = useToast();
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    if (!reason.trim()) {
      toast.error('Say why this request is being rejected');
      return;
    }
    setBusy(true);
    try {
      await api.post(`/maintenance-tickets/${ticket.id}/review/reject`, { rejection_reason: reason });
      onDone('Inspection request rejected');
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not reject this request');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open
      onClose={onClose}
      title="Reject inspection request"
      subtitle={`${ticket.plate || `#${ticket.id}`} · nothing will be sent externally`}
      footer={(
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button variant="danger" onClick={submit} loading={busy}>Reject</Button>
        </>
      )}
    >
      <Textarea
        label="Rejection reason"
        required
        rows={3}
        value={reason}
        onChange={(e) => setReason(e.target.value)}
        placeholder="Why is this request being rejected?"
      />
    </Modal>
  );
}

export default function InspectionReviewQueue() {
  const toast = useToast();
  const { can } = usePermissions();
  const canManage = can('maintenance.manage');
  const [modal, setModal] = useState(null); // { action: 'approve'|'reject'|'test'|'complaint', ticket }
  const [vehicles, setVehicles] = useState([]);

  const fetcher = useCallback(async () => (await api.get('/maintenance-tickets/pending-review')).data.data, []);
  const { data, loading, error, reload } = useFetch(fetcher, [], {
    refreshInterval: 8000,
    paused: () => !!modal,
  });

  const tickets = useMemo(() => data || [], [data]);

  // Deep-link focus — the Action Center links here as /inspection-review?ticket=<id> when a Controller
  // clicks "Review Request" on a maint_review_pending alert. Scroll that exact card into view and pulse
  // a highlight ring so they land on the right request, not the top of a long queue.
  const [searchParams, setSearchParams] = useSearchParams();
  const targetTicket = searchParams.get('ticket');
  const [highlightId, setHighlightId] = useState(null);
  const focusedRef = useRef(false);
  const highlightTimer = useRef(null);

  useEffect(() => {
    // Wait for the first load; act once. Set the guard up front so clearing the query param below
    // (which re-runs this effect) can't re-enter and cancel the highlight timer.
    if (!targetTicket || loading || focusedRef.current) return;
    focusedRef.current = true;

    const match = tickets.find((t) => String(t.id) === String(targetTicket));
    if (match) {
      setHighlightId(match.id);
      requestAnimationFrame(() => {
        document.getElementById(`review-card-${match.id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });
      highlightTimer.current = setTimeout(() => setHighlightId(null), 3500);
    } else {
      // No longer pending — already approved/rejected by someone else, or auto-resolved.
      toast.info('That request is no longer awaiting review — it may have already been actioned.');
    }
    // Drop the query param so a manual refresh doesn't re-highlight.
    setSearchParams({}, { replace: true });
  }, [targetTicket, loading, tickets, toast, setSearchParams]);

  useEffect(() => () => clearTimeout(highlightTimer.current), []);

  // Pickers for the New Test / New Complaint intake modals — only Ready + Rented cars.
  useEffect(() => {
    let alive = true;
    api.get('/Vehicle')
      .then((v) => {
        if (!alive) return;
        const list = v.data?.data;
        const all = Array.isArray(list) ? list : list?.items || [];
        setVehicles(all.filter((veh) => ['ready', 'rented'].includes(veh.status)));
      })
      .catch(() => { /* pickers stay empty */ });
    return () => { alive = false; };
  }, []);

  const onDone = (message) => {
    setModal(null);
    if (message) toast.success(message);
    reload({ silent: true });
  };

  const [ackBusyId, setAckBusyId] = useState(null);
  const onAcknowledge = async (tk) => {
    setAckBusyId(tk.id);
    try {
      await api.post(`/maintenance-tickets/${tk.id}/review/acknowledge-legacy`);
      toast.success('Acknowledged');
      reload({ silent: true });
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not acknowledge this request');
    } finally {
      setAckBusyId(null);
    }
  };

  return (
    <div className="opx py-8">
      <div className="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
        <div className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <div className="opx-hint" style={{ letterSpacing: '.16em', textTransform: 'uppercase', marginBottom: 6, display: 'flex', alignItems: 'center', gap: 10 }}>
              Controller Approval Gate
              {!loading && <span style={{ color: 'var(--cyan)', fontWeight: 700 }}>· {tickets.length} awaiting</span>}
            </div>
            <h1 className="font-display" style={{ fontSize: 24, fontWeight: 700, letterSpacing: '-.02em', color: 'var(--ink)', margin: 0 }}>Inspection Review Queue</h1>
            <p style={{ marginTop: 6, fontSize: 13.5, color: 'var(--ink-3)' }}>Requests awaiting Controller approval before they reach Abu Maroof.</p>
          </div>
          <div style={{ display: 'flex', gap: 9, flexWrap: 'wrap' }}>
            {canManage && <button className="opx-btn primary" onClick={() => setModal({ action: 'request' })}>+ Request Inspection</button>}
            {canManage && <button className="opx-btn" onClick={() => setModal({ action: 'complaint' })}>📣 New complaint</button>}
          </div>
        </div>

        {error && <div style={{ borderRadius: 12, border: '1px solid rgba(251,113,133,.3)', background: 'rgba(251,113,133,.08)', color: '#fb7185', padding: '12px 16px', fontSize: 13 }}>{error}</div>}

        {/* The rulebook sits above the queue and stays visible when the queue is empty — "why did nothing
            come in?" is the same question as "why did this come in?". */}
        <SystemRulesPanel />

        {loading ? (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Skeleton className="h-32 rounded-xl" />
            <Skeleton className="h-32 rounded-xl" />
          </div>
        ) : tickets.length === 0 ? (
          <EmptyState
            icon={<Icon.Check className="h-7 w-7" />}
            title="All caught up"
            message="No inspection requests are waiting for review."
          />
        ) : (
          <>
          {/* Analytics — the shape of the queue, before the request cards. */}
          <InspectionReviewAnalytics tickets={tickets} />
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {tickets.map((tk) => (
              <RequestCard
                key={tk.id}
                tk={tk}
                onApprove={(t) => setModal({ action: 'approve', ticket: t })}
                onReject={(t) => setModal({ action: 'reject', ticket: t })}
                onAcknowledge={onAcknowledge}
                ackBusy={ackBusyId === tk.id}
                highlight={highlightId === tk.id}
              />
            ))}
          </div>
          </>
        )}
      </div>

      {modal?.action === 'approve' && (
        <ApproveModal ticket={modal.ticket} onClose={() => setModal(null)} onDone={onDone} />
      )}
      {modal?.action === 'reject' && (
        <RejectModal ticket={modal.ticket} onClose={() => setModal(null)} onDone={onDone} />
      )}
      {/* Request Inspection — the driver "flag a car" form: vehicle + What happened? (test drive /
          customer / routine) + notes + optional photo/video. Born in pending_review, so it lands right
          back in this queue for a Controller to approve before it reaches Abu Maroof. */}
      {modal?.action === 'request' && (
        <TicketActionModal action="request" vehicles={vehicles} onClose={() => setModal(null)} onDone={onDone} />
      )}
      {/* Complaint Intake — logs a customer complaint straight into Abu Maroof's triage lane. */}
      {modal?.action === 'complaint' && (
        <ComplaintIntakeModal vehicles={vehicles} onClose={() => setModal(null)} onDone={onDone} />
      )}
    </div>
  );
}
