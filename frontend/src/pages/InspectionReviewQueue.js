// Inspection Request Review Gate — the Controllers' (Lin & Marwa) queue of Driver/system-generated
// inspection requests awaiting approval before they are sent to the Inspector (Abu Maroof).
//
// Three outcomes, not two:
//   Approve   — sends the request on to the Inspector, exactly as before.
//   Reject    — terminates it. The reviewer picks a REASON from a fixed list (a code, so rejections can
//               be counted) and may add their own words beside it; and may book a reminder to revisit it,
//               because "not now" and "never" are different decisions.
//   Remind me — decides nothing. The request stays in the queue for whoever gets to it first; the
//               reviewer just asks to be pinged about it later. Personal: your reminder is yours, and it
//               cancels itself the moment anyone approves or rejects the request.

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
import Tabs from '../components/ui/Tabs';
import DataTable from '../components/ui/Table';
import { Input, Textarea } from '../components/ui/Field';
import { useI18n } from '../i18n/I18nContext';
import { EmptyState } from '../components/ui/Misc';
import { Skeleton } from '../components/ui/Skeleton';
import SendCarInModal from '../components/workflow/SendCarInModal';
import ComplaintIntakeModal from '../components/workflow/ComplaintIntakeModal';
import SuggestedChecks from '../components/workflow/SuggestedChecks';
import { num, fmtDate, fmtClock } from '../lib/format';
// The oil change is recorded identically wherever it is recorded from — one dialog, one write path.
import { OilChangeDialog } from './reminders/OilProjection';

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
  const { t } = useI18n();
  const meta = STATUS_META[status];
  const label = meta ? t(meta.label) : String(status).replace(/_/g, ' ');
  const cls = meta ? meta.cls : 'bg-slate-100 text-slate-600 ring-slate-200';
  return (
    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 ring-inset ${cls}`}>
      {label}
    </span>
  );
}

// Rule severity → the colour of the dot next to each system-detected rule.
const SEV_DOT = { critical: 'bg-red-500', moderate: 'bg-amber-500', routine: 'bg-emerald-500' };

// Returns WORDS, so the translator comes in as a parameter — this is a module-level helper, not a hook.
function ago(iso, t) {
  if (!iso) return '';
  const secs = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
  if (secs < 90) return t('just now');
  const mins = Math.round(secs / 60);
  if (mins < 60) return t('{n}m ago', { n: mins });
  const hrs = Math.round(mins / 60);
  if (hrs < 24) return t('{n}h ago', { n: hrs });
  return t('{n}d ago', { n: Math.round(hrs / 24) });
}

const km = (n) => (n === null || n === undefined ? null : `${num(n)} km`);

function dueDate(iso) {
  if (!iso) return null;
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return null;
  return fmtDate(iso);
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
  const { t, tf } = useI18n();
  const rules = Array.isArray(detail?.rules) ? detail.rules : [];
  const svc = detail?.service || null;

  const values = [
    [tf('reviewQueue.detail.currentKm', 'Current mileage'), km(svc?.current_km)],
    [tf('reviewQueue.detail.intervalKm', 'Service interval'), km(svc?.interval_km)],
    [tf('reviewQueue.detail.overdueBy', 'Overdue by'), km(svc?.overdue_km)],
    [tf('reviewQueue.detail.nextDue', 'Next due'), dueDate(svc?.next_due_at)],
  ].filter(([, v]) => v);

  // Only the ticket's OWN stored suggestions — never re-derived from the checklist rules.
  const chips = (suggested || []).filter((v, i, a) => v && a.indexOf(v) === i);

  return (
    <div className="mt-3 rounded-lg border border-indigo-100 bg-indigo-50/50 px-3 py-2.5">
      <div className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-indigo-700">
        <span aria-hidden>🤖</span> {t('Why the system flagged this')}
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
          <p className="text-[11px] text-slate-400">{tf('reviewQueue.dueFor', 'What this request is due for')}</p>
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

function severityChip(severity, t) {
  const first = String(severity || '').split('/')[0].trim();
  const known = SEVERITY_WORD[first];
  if (known) return { label: t(known.label), cls: known.cls };
  return { label: severity || '—', cls: 'bg-slate-100 text-slate-700' };
}

// One rule = one card. Big threshold on the right so the limits ("15 days", "30 months") are scannable
// without reading a word; the plain sentence carries the meaning, the note carries the nuance.
function RuleCard({ rule }) {
  const { t } = useI18n();
  const skin = RULE_SKIN[rule.key] || RULE_SKIN_FALLBACK;
  const sev = severityChip(rule.severity, t);

  return (
    <div className={`relative overflow-hidden rounded-xl bg-white p-3.5 ps-4 shadow-sm ring-1 ring-inset ${skin.ring}`}>
      <span className={`absolute inset-y-0 start-0 w-1 ${skin.bar}`} aria-hidden />
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
              <span>{t('The inspector is asked to check: {items}', { items: rule.agenda })}</span>
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
  const { t, tf } = useI18n();
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
        className="flex w-full items-center gap-3 bg-gradient-to-r from-indigo-600 to-indigo-500 px-4 py-3.5 text-start transition-opacity hover:opacity-95"
      >
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/15 text-lg" aria-hidden>🤖</span>
        <span className="min-w-0 flex-1">
          <span className="block text-sm font-bold text-white">{t('Why does the system ask for a test?')}</span>
          <span className="block text-xs text-indigo-100">
            {t('Some requests below were raised by the system, not by a person. This explains when, and why.')}
          </span>
        </span>
        <span className="shrink-0 rounded-full bg-white/15 px-2.5 py-1 text-xs font-semibold text-white">
          {open ? t('Hide') : t('Read this')}
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
            <p className="text-sm text-rose-600">{tf('reviewQueue.rulebook.loadError', 'Could not load this explanation — close and open it again to retry.')}</p>
          )}
          {state === 'idle' && !book && (
            <p className="text-sm text-slate-500">{tf('reviewQueue.rulebook.empty', 'Nothing came back — the automatic check may not be switched on.')}</p>
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
                  {t('Checked every day at {time}', { time: book.schedule?.runs_at || '—' })}
                </span>
              </div>

              {book.enabled === false && (
                <p className="rounded-lg bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 ring-1 ring-inset ring-rose-200">
                  ⚠ {t('The automatic check is currently switched off — no new system requests are being raised.')}
                </p>
              )}

              {/* 2 ── The journey, so the request in the queue has a visible origin. */}
              {Array.isArray(book.steps) && book.steps.length > 0 && (
                <div>
                  <SectionTitle hint={tf('reviewQueue.rulebook.howItWorksHint', 'from the daily check to your decision')}>
                    {tf('reviewQueue.rulebook.howItWorks', 'How it works')}
                  </SectionTitle>
                  <FlowStrip steps={book.steps} />
                </div>
              )}

              {/* 3 ── The rules themselves: the heart of the panel. */}
              {Array.isArray(book.rules) && book.rules.length > 0 && (
                <div>
                  <SectionTitle hint={tf('reviewQueue.rulebook.whatMakesDueHint', 'any one of these is enough')}>
                    {tf('reviewQueue.rulebook.whatMakesDue', 'What makes a car due for a test')}
                  </SectionTitle>
                  <div className="grid grid-cols-1 gap-2.5 lg:grid-cols-2">
                    {book.rules.map((r) => <RuleCard key={r.key} rule={r} />)}
                  </div>
                </div>
              )}

              {/* 4 ── The two "and it does NOT…" halves, side by side. */}
              <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                {Array.isArray(book.preconditions) && book.preconditions.length > 0 && (
                  <div className="rounded-xl bg-emerald-50/60 p-3.5 ring-1 ring-inset ring-emerald-100">
                    <SectionTitle>{tf('reviewQueue.rulebook.whichCars', 'Which cars it checks')}</SectionTitle>
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
                    <SectionTitle hint={tf('reviewQueue.rulebook.willNotAskHint', 'so you can tell “not due” from “held back”')}>
                      {tf('reviewQueue.rulebook.willNotAsk', 'What it will not ask for')}
                    </SectionTitle>
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
                    <span className="font-bold">{t('Your part:')} </span>{book.outcome}
                  </p>
                </div>
              )}

              {/* 6 ── Engine vocabulary lives here, out of the operator's way. */}
              <details className="rounded-lg bg-slate-50 px-3 py-2 ring-1 ring-inset ring-slate-200">
                <summary className="cursor-pointer text-xs font-semibold text-slate-600">{tf('reviewQueue.rulebook.technical', 'Technical details')}</summary>
                <div className="mt-2 space-y-1.5 text-[11px] text-slate-500">
                  <p>
                    {t('Job:')} <code className="rounded bg-slate-900/90 px-1.5 py-0.5 font-mono text-slate-100">{book.schedule?.command}</code>
                    {' '}— {t('{frequency} at {time}', { frequency: book.schedule?.frequency, time: book.schedule?.runs_at })}. {book.schedule?.description}
                  </p>
                  {book.limits && (
                    <p className="font-mono">
                      downtime_days={book.limits.downtime_days} · inactive_days={book.limits.inactive_days} ·
                      battery_life_months={book.limits.battery_life_months} · oil_ceiling_floor_km={book.limits.oil_ceiling_floor_km}
                    </p>
                  )}
                  <p>
                    {/* The class and config keys are IDENTIFIERS, not prose — they are the same in every
                        language, so they stay as literals rather than becoming translatable strings. */}
                    {tf('reviewQueue.rulebook.dataOriginLead', 'Data Origin: every limit above is read live from')}
                    {' '}<code className="font-mono">{'DiagnosticGateService'}</code>
                    {' '}({tf('reviewQueue.rulebook.dataOriginConfig', 'config')} <code className="font-mono">{'features.diagnostic_gate'}</code>)
                    {' '}{tf('reviewQueue.rulebook.dataOriginTail', '— the same code that decides what is due, so this page cannot describe a rule that is not the one running.')}
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
  return `${fmtDate(iso)} ${fmtClock(iso)}`;
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

// ── The system withdrew this request ──────────────────────────────────────────────────
// A car that was flagged for a test drive can be in the workshop before anyone gets to the card:
// OfficeManager opens a maintenance contract (type U) on it, and from that moment the request is asking
// a Controller to decide about a car that has already gone. The system withdraws it — and this note is
// the receipt. It names the contract, when it opened and for whom, so the decision that was taken out of
// the Controller's hands is one they can check rather than one they have to trust
// (see [[traceability-visibility-requirement]]).
//
// Three facts can do it, and the note reads differently for each: the car is away on an OM contract, the
// car is away per the garage log, or — the rule the other two are only snapshots of — the car went and
// CAME BACK, which restarted the count the request was quoting.
function WithdrawnNote({ ctx, at }) {
  const { t, tf } = useI18n();
  const opened = dueDate(ctx?.opened_at);
  // Two facts can withdraw a request: an OfficeManager maintenance contract, or — while workshop trips
  // are still recorded on the sheet rather than as OM contracts — a garage-log event (sheet-imported or
  // hand-entered) showing the car went out and hasn't come back. Same note, different evidence.
  const fromLog = ctx?.source === 'workshop_log';
  // …and the third: the car has been to a workshop and COME BACK since the request was raised. The two
  // above describe a car that is away right now; this one is the count itself — it restarted on the day
  // the car returned, so what the system asked for is no longer due.
  const fromClock = ctx?.source === 'clock_restarted';
  // (A fourth code, `superseded_by_test`, never reaches this component: a person already opened a ticket
  // for that car, so the queue drops the request entirely rather than carding it. See pendingReview().)
  const returned = dueDate(ctx?.anchor_at);
  const label = ctx?.contract_no ? `#${ctx.contract_no}` : ctx?.contract_id ? `#${ctx.contract_id}` : null;
  // Where the "it came back" fact came from — a closed ticket, the garage log, or an OM contract.
  const anchorLabel = {
    workflow: tf('review.withdrawn.clock.srcTicket', 'Ticket'),
    legacy: tf('review.withdrawn.clock.srcLog', 'Garage log'),
    om_contract: tf('review.withdrawn.clock.srcContract', 'Contract'),
  }[ctx?.anchor_source] || null;
  const anchorLink = ctx?.anchor_source === 'workflow' && ctx?.anchor_source_id
    ? `/maintenance-workflow/${ctx.anchor_source_id}`
    : ctx?.anchor_source === 'om_contract' && ctx?.anchor_source_id
      ? `/contracts/${ctx.anchor_source_id}`
      : null;

  return (
    <div className="overflow-hidden rounded-xl bg-gradient-to-br from-violet-50 via-indigo-50 to-white ring-1 ring-inset ring-violet-200">
      <div className="flex items-start gap-3 px-3.5 py-3">
        <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-violet-100 text-violet-600 ring-1 ring-inset ring-violet-200">
          <Icon.Invoice className="h-4 w-4" />
        </span>
        <div className="min-w-0 flex-1">
          <p className="text-[11px] font-bold uppercase tracking-wide text-violet-500">
            {t('Withdrawn by the system')}
          </p>
          <p className="mt-0.5 text-sm font-semibold leading-snug text-slate-800">
            {fromClock
              ? tf('review.withdrawn.clock.title', 'This car went to the workshop and came back after the request was raised.')
              : fromLog
                ? t('This car is already in the workshop — the garage log shows it went out and has not come back.')
                : t('This car is already in maintenance — OfficeManager opened a maintenance contract for it.')}
          </p>
          <p className="mt-1 text-xs leading-relaxed text-slate-600">
            {fromClock
              ? tf(
                  'review.withdrawn.clock.body',
                  'The count started again the day it came back{when}, so the check the system asked for is no longer due.',
                  { when: returned ? ` (${returned})` : '' },
                )
              : fromLog
                ? t('The trip was recorded on the maintenance log after this request was raised, so nobody needs to decide it any more.')
                : t('The request was raised before that contract existed, so nobody needs to decide it any more.')}
            {' '}{t('Nothing was sent to Abu Maroof.')}
          </p>

          {/* The evidence, not the claim. */}
          <div className="mt-2.5 flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t border-violet-200/70 pt-2.5 text-[11px] text-slate-600">
            {fromLog && ctx?.garage && (
              <span className="inline-flex min-w-0 items-center gap-1.5">
                <Icon.Wrench className="h-3.5 w-3.5 text-violet-400" />
                <span className="text-slate-400">{tf('reviewQueue.garage', 'Garage')}</span>
                <span className="truncate font-semibold text-slate-700">{ctx.garage}</span>
              </span>
            )}
            {fromLog && ctx?.service_main && (
              <span className="inline-flex min-w-0 items-center gap-1.5">
                <Icon.Flag className="h-3.5 w-3.5 text-violet-400" />
                <span className="text-slate-400">{tf('reviewQueue.work', 'Work')}</span>
                <span className="truncate font-semibold text-slate-700">{ctx.service_main}</span>
              </span>
            )}
            {fromClock && returned && (
              <span className="inline-flex items-center gap-1.5">
                <Icon.Calendar className="h-3.5 w-3.5 text-violet-400" />
                <span className="text-slate-400">{tf('review.withdrawn.clock.cameBack', 'Came back')}</span>
                <span className="font-semibold text-slate-700">{returned}</span>
              </span>
            )}
            {fromClock && anchorLabel && (
              <span className="inline-flex min-w-0 items-center gap-1.5">
                <Icon.Wrench className="h-3.5 w-3.5 text-violet-400" />
                <span className="text-slate-400">{tf('review.withdrawn.clock.per', 'Per')}</span>
                {anchorLink ? (
                  <Link to={anchorLink} className="font-semibold text-violet-700 underline-offset-2 hover:underline">
                    {anchorLabel} #{ctx.anchor_source_id}
                  </Link>
                ) : (
                  <span className="truncate font-semibold text-slate-700">
                    {anchorLabel}{ctx?.anchor_source_id ? ` #${ctx.anchor_source_id}` : ''}
                  </span>
                )}
              </span>
            )}
            {fromClock && ctx?.request_created_at && (
              <span className="inline-flex items-center gap-1.5">
                <Icon.Flag className="h-3.5 w-3.5 text-violet-400" />
                <span className="text-slate-400">{tf('review.withdrawn.clock.asked', 'Asked')}</span>
                <span className="font-semibold text-slate-700">{dueDate(ctx.request_created_at)}</span>
              </span>
            )}
            {label && (
              <span className="inline-flex items-center gap-1.5">
                <Icon.Invoice className="h-3.5 w-3.5 text-violet-400" />
                <span className="text-slate-400">{tf('reviewQueue.contract', 'Contract')}</span>
                {ctx?.contract_id ? (
                  <Link
                    to={`/contracts/${ctx.contract_id}`}
                    className="font-mono font-bold text-violet-700 underline-offset-2 hover:underline"
                  >
                    {label}
                  </Link>
                ) : (
                  <span className="font-mono font-bold text-violet-700">{label}</span>
                )}
              </span>
            )}
            {opened && (
              <span className="inline-flex items-center gap-1.5">
                <Icon.Calendar className="h-3.5 w-3.5 text-violet-400" />
                <span className="text-slate-400">{fromLog ? tf('reviewQueue.outSince', 'Out since') : tf('reviewQueue.opened', 'Opened')}</span>
                <span className="font-semibold text-slate-700">{opened}</span>
              </span>
            )}
            {ctx?.customer && (
              <span className="inline-flex min-w-0 items-center gap-1.5">
                <Icon.Users className="h-3.5 w-3.5 text-violet-400" />
                <span className="text-slate-400">{tf('reviewQueue.customer', 'Customer')}</span>
                <span className="truncate font-semibold text-slate-700">{ctx.customer}</span>
              </span>
            )}
            {at && (
              <span className="inline-flex items-center gap-1.5">
                <Icon.Clock className="h-3.5 w-3.5 text-violet-400" />
                <span className="text-slate-400">{tf('reviewQueue.withdrawn', 'Withdrawn')}</span>
                <span className="font-semibold text-slate-700">{ago(at, t)}</span>
              </span>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

/**
 * Oil Service Follow-up — a request raised by an oil recall/defer decision on /oil-projection.
 * The figures are LIVE: the backend recomputes them from the decision's contract on every read, so
 * a fresh odometer reading changes this block everywhere at once. `at_decision` (shown only when it
 * differs) is the frozen audit answer to "what did the Controller see when they decided".
 */
const OIL_ACTION_META = {
  oil_change_required:   { label: 'OIL CHANGE REQUIRED',   cls: 'bg-rose-100 text-rose-700 ring-rose-200' },
  oil_service_completed: { label: 'OIL SERVICE COMPLETED', cls: 'bg-emerald-100 text-emerald-700 ring-emerald-200' },
  // The recall relay, stated as what is actually happening. "Collection in progress" used to be
  // shown from the moment a recall was decided, which claimed a driver was moving before anyone had
  // even spoken to the customer.
  awaiting_sales:        { label: 'WAITING FOR SALES OK',  cls: 'bg-amber-100 text-amber-700 ring-amber-200' },
  awaiting_driver:       { label: 'AWAITING A DRIVER',     cls: 'bg-amber-100 text-amber-700 ring-amber-200' },
  collection_in_progress:{ label: 'COLLECTION IN PROGRESS', cls: 'bg-amber-100 text-amber-700 ring-amber-200' },
  vehicle_collected:     { label: 'VEHICLE COLLECTED',     cls: 'bg-indigo-100 text-indigo-700 ring-indigo-200' },
  service_on_return:     { label: 'SERVICE ON RETURN',     cls: 'bg-amber-100 text-amber-700 ring-amber-200' },
  inspection_only:       { label: 'INSPECTION ONLY',       cls: 'bg-slate-100 text-slate-600 ring-slate-200' },
};

/** The recall relay's stages, as the review side names them. */
const OIL_STAGE_LABEL = {
  waiting_sales:     'Waiting for Sales',
  ready_for_driver:  'Ready for driver',
  driver_assigned:   'Driver assigned',
  vehicle_collected: 'Vehicle collected',
  at_workshop:       'At the workshop',
  inspection:        'Inspection / test',
  oil_service:       'Oil change',
  completed:         'Completed',
  cancelled:         'Stood down',
};

function OilFollowUpNote({ ctx, onRecordOilChange }) {
  const { t, tf } = useI18n();
  if (!ctx) return null;
  const live = ctx.live;
  const snap = ctx.at_decision;
  const over = live?.over_allowance_km;
  const decisionLabel = ctx.decision === 'recall' ? t('Recall now') : t('Do it on return');
  const cleared = over != null && over <= 0;
  const action = OIL_ACTION_META[ctx.current_action];
  const recall = ctx.recall;
  return (
    <div className="rounded-lg bg-amber-50/70 px-3 py-2 ring-1 ring-inset ring-amber-200/70">
      <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
        <p className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-amber-700">
          <Icon.Flag className="h-3 w-3" />
          {/* An ADOPTED card is the system's own test request with the oil change added to it — it
              must not claim to have come from the oil board, or the reason above it reads as a lie. */}
          {ctx.recall?.request_adopted
            ? tf('review.oil.addedTo', 'Oil change added to this test request · Source: Oil Projection')
            : t('Oil Service Follow-up · Source: Oil Projection')}
        </p>
        {/* The CURRENT action, stated — nobody infers it from raw numbers. */}
        {action && (
          <span className={`rounded-full px-2 py-0.5 text-[10px] font-bold tracking-wide ring-1 ring-inset ${action.cls}`}>
            {t(action.label)}
          </span>
        )}
      </div>
      <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-700">
        <span>
          {ctx.decided_by
            ? t('Decision: {decision} by {who}', { decision: decisionLabel, who: ctx.decided_by })
            : t('Decision: {decision}', { decision: decisionLabel })}
        </span>
        {ctx.contract_no && <span>{t('Contract: {no}', { no: ctx.contract_no })}</span>}
        {ctx.settled && <span className="font-semibold text-emerald-700">{tf('reviewQueue.settledReturned', 'Settled — car returned')}</span>}
      </div>
      {live && (
        <div className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-700">
          <span>
            {t('Latest reading: {km} km ({source})', {
              km: num(live.anchor_odometer),
              source: live.anchor_source === 'reading' ? t('customer') : t('handover'),
            })}
          </span>
          <span>{t('Est. return: {km} km', { km: num(live.expected_return) })}</span>
          <span>{t('Allowed max: {km} km', { km: num(live.allowed_max) })}</span>
          {over != null && (cleared
            ? <span className="font-semibold text-emerald-700">{t('Now inside the allowance ({km} km spare)', { km: num(Math.abs(over)) })}</span>
            : <span className="font-semibold text-rose-700">{t('{km} km over the allowance', { km: num(over) })}</span>)}
        </div>
      )}
      {snap && live && snap.over_allowance != null && over != null && snap.over_allowance !== Math.max(0, over) && (
        <p className="mt-1.5 border-t border-amber-200/70 pt-1.5 text-[11px] text-slate-500">
          {t('At decision time the projection said {km} km over — a newer reading has updated the figures above.', {
            km: num(snap.over_allowance),
          })}
        </p>
      )}

      {/* ── The recall relay: how the car is actually getting here, and what it owes on arrival ── */}
      {recall && (
        <div className="mt-2 border-t border-amber-200/70 pt-2">
          <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-700">
            <span>{t('Stage: {stage}', { stage: OIL_STAGE_LABEL[recall.stage] ? t(OIL_STAGE_LABEL[recall.stage]) : recall.stage })}</span>
            {/* Where it is going, and therefore who owns the change when it lands. */}
            <span>
              {tf('review.oil.where', 'Oil change at')}:{' '}
              <strong>
                {recall.service_location === 'parking'
                  ? tf('review.oil.whereParking', 'our parking · Abu Maroof')
                  : tf('review.oil.whereGarage', 'a garage · Waleed / Abdullah')}
              </strong>
            </span>
            <span>
              {t('Sales:')}{' '}
              {recall.sales?.confirmed
                ? <strong className="text-emerald-700">{tf('review.oil.sales.confirmed', 'Confirmed')}{recall.sales.confirmed_by ? ` · ${recall.sales.confirmed_by}` : ''}</strong>
                : <strong className="text-amber-700">{tf('review.oil.sales.waiting', 'Waiting')}</strong>}
            </span>
            <span>
              {t('Driver:')}{' '}
              <strong>
                {!recall.collection ? t('Not arranged')
                  : recall.collection.driver ? `${recall.collection.driver} · ${recall.collection.phase}`
                  : recall.collection.phase}
              </strong>
            </span>
          </div>

          {/* The required work. The oil change is rendered as a locked requirement — this card is
              where a reviewer decides what to send in, and "just test it" must not be reachable. */}
          {recall.required_actions && (
            <div className="mt-1.5 flex flex-wrap items-center gap-3 text-xs">
              <span className={recall.required_actions.test?.required ? 'font-semibold text-slate-800' : 'text-slate-400'}>
                {recall.required_actions.test?.required ? '☑' : '☐'} {t('Inspection / Test')}
              </span>
              {/* The requirement never disappears — it is why the customer was interrupted. What
                  changes is whether it has been MET, and the reading that proves it. */}
              {ctx.oil_changed ? (
                <span className="rounded-md bg-emerald-100 px-2 py-0.5 font-bold text-emerald-700 ring-1 ring-inset ring-emerald-200">
                  ✅ {tf('review.oil.done.pill', 'Oil changed at {km} km', { km: num(ctx.oil_changed.odometer) })}
                </span>
              ) : (
                <span className="rounded-md bg-rose-100 px-2 py-0.5 font-bold text-rose-700 ring-1 ring-inset ring-rose-200">
                  🔒 {t('Oil Change — REQUIRED')}
                </span>
              )}
              {/* The car is ours and the oil is still owed: this is the moment, and this is where
                  the person holding it already is. One number ends the whole follow-up. */}
              {!ctx.oil_changed && ctx.in_our_custody && onRecordOilChange && (
                <button
                  type="button"
                  onClick={onRecordOilChange}
                  className="rounded-md bg-indigo-600 px-2 py-0.5 font-bold text-white hover:bg-indigo-500"
                >
                  {tf('review.oil.done.record', 'Oil changed — record it')}
                </button>
              )}
            </div>
          )}
          {/* What it did to the car's own schedule — the reason the number was worth typing. */}
          {ctx.oil_changed && (
            <p className="mt-1.5 text-[11px] text-emerald-700">
              {tf('review.oil.done.line', 'Oil changed at {km} km by {who} — next change due at {next} km.', {
                km: num(ctx.oil_changed.odometer),
                who: ctx.oil_changed.by || '—',
                next: num(ctx.oil_changed.next_due),
              })}
            </p>
          )}
        </div>
      )}
    </div>
  );
}

function RequestCard({ tk, onApprove, onReject, onAcknowledge, onRemind, onCancelReminder, onRecordOilChange, ackBusy, remindBusy, highlight }) {
  const { t, tf } = useI18n();
  const [expanded, setExpanded] = useState(false);
  const reasonTone = REASON_TONE[tk.trigger_reason] || 'slate';
  const reasonLabel = REASON_LABEL[tk.trigger_reason] ? t(REASON_LABEL[tk.trigger_reason]) : tk.trigger_reason;
  const requested = tk.handoffs?.requested;
  // WHERE the request came from. The stored origin is authoritative; the "no human requester" guess is
  // only the fallback for rows written before the column existed.
  const origin = tk.request_origin || null;
  const originLabel = tk.request_origin_label
    || (ORIGIN_LABEL[origin] ? t(ORIGIN_LABEL[origin]) : origin);
  // System-generated = raised by the scheduler, not merely missing a requester (an escalated Driver
  // Observation has no requester either, and is emphatically NOT a system request).
  const isSystem = origin ? origin === 'system_schedule' : !requested?.user_id;
  const fromObservation = origin === 'driver_observation';
  const isLegacy = !!tk.is_legacy_unreviewed;
  // The system already answered this one: the car went into the workshop on an OM maintenance contract
  // while the request was still waiting. It is on the card as NEWS, not work — no approve, no reject.
  const isWithdrawn = !!tk.review?.is_system_withdrawal;
  const withdrawnCtx = tk.review?.auto_context || null;
  // The car is out on hire — it can't be sent for inspection until it's physically back, so approval is
  // held (the downtime clock still counts against it; see the 15-day test-based rule).
  //
  // …unless we have physically collected it. On an oil recall a driver takes the keys hours before
  // OfficeManager closes the rental, so `operational_status` still says "rented" while the car is
  // standing in our workshop. Custody is the honest test, and the backend derives it from the
  // collection itself (`oil_context.in_our_custody`), never from the contract.
  //
  // …and a RECALLED car is not "waiting" either, even before a driver has moved. A recall means the
  // return is being actively organised — Sales are agreeing it, a collection follows — so the
  // request is not hostage to whether a customer happens to bring the car back. Leen approves it
  // now, the ordinary inspection workflow runs exactly as it always does, and the oil change rides
  // along as a locked requirement. Holding it shut until the keys are physically in our hand only
  // delays the queue Abu Maroof works from, for a car everyone already knows is coming.
  const recallInFlight = !!tk.oil_context?.recall && !tk.oil_context?.settled;
  const awaitingReturn = tk.operational_status === 'rented'
    && !tk.oil_context?.in_our_custody
    && !recallInFlight;

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
          : isWithdrawn ? 'border-violet-200 bg-slate-50/60'
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
              {/* The outcome leads when there is one: a Controller scanning the queue must see at a glance
                  that this card is settled before they read anything else on it. */}
              {isWithdrawn && (
                <span className="inline-flex items-center gap-1 rounded-full bg-violet-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-violet-700 ring-1 ring-inset ring-violet-200">
                  <Icon.Check className="h-3 w-3" /> {t('Withdrawn')}
                </span>
              )}
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
                  <span aria-hidden>{sev.emoji}</span> {t(sev.label)}
                </span>
              )}
            </div>
          </div>
          <p className="truncate text-sm font-semibold text-slate-600">{tk.car || t('Vehicle')}</p>
          <div className="mt-1.5 flex flex-wrap items-center gap-2">
            {tk.operational_status && <StatusPill status={tk.operational_status} />}
            {tk.vehicle_odometer != null && (
              <span className="inline-flex items-center gap-1 font-mono text-[11px] text-slate-500">
                <Icon.Gauge className="h-3.5 w-3.5 text-slate-400" />{num(tk.vehicle_odometer)} km
              </span>
            )}
            {identityBits.length > 0 && (
              <span className="text-[11px] text-slate-400">{identityBits.join(' · ')}</span>
            )}
          </div>
        </div>
      </div>

      <div className="space-y-3 p-4">
        {/* ── The system's own answer, first: this request is settled ──────────────── */}
        {isWithdrawn && <WithdrawnNote ctx={withdrawnCtx} at={tk.review?.reviewed_at} />}

        {/* ── Driver's report (expandable when long) ───────────────────────────────── */}
        {complaint && (
          <div className="rounded-lg bg-slate-50 px-3 py-2 ring-1 ring-inset ring-slate-100">
            <p className="mb-0.5 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
              <Icon.Flag className="h-3 w-3" />
              {isSystem
                ? t('Flagged reason')
                : fromObservation ? t('What the driver observed') : t('What the driver reported')}
            </p>
            <p className={`text-xs italic text-slate-600 ${isLong && !expanded ? 'line-clamp-2' : ''}`}>“{complaint}”</p>
            {isLong && (
              <button type="button" onClick={() => setExpanded((v) => !v)} className="mt-0.5 text-[11px] font-semibold text-indigo-600 hover:text-indigo-700">
                {expanded ? t('Show less') : t('Show more')}
              </button>
            )}
            {/* Data Origin — this text did not start life as an inspection request; say where it came
                from and link back to the record that owns it (see [[traceability-visibility-requirement]]). */}
            {fromObservation && (
              <p className="mt-1.5 border-t border-slate-200/70 pt-1.5 text-[11px] text-slate-400">
                {t('Escalated from')}{' '}
                <Link to="/driver-observations" className="font-semibold text-indigo-600 hover:text-indigo-700">
                  {t('a Driver Observation')}
                </Link>
                {tk.driver_observation_id ? ` #${tk.driver_observation_id}` : ''}
                {' '}{t('— logged as a note first, then raised for inspection.')}
              </p>
            )}
          </div>
        )}

        {/* ── Oil-decision origin: live figures + frozen at-decision snapshot ──────── */}
        {tk.oil_context && (
          <OilFollowUpNote
            ctx={tk.oil_context}
            onRecordOilChange={onRecordOilChange ? () => onRecordOilChange(tk) : undefined}
          />
        )}

        {/* ── Attachments — the driver's photos/videos as previews ─────────────────── */}
        {Array.isArray(tk.media) && tk.media.length > 0 && (
          <div>
            <p className="mb-1.5 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
              <Icon.Camera className="h-3 w-3" /> {t('Attachments')}
              <span className="rounded-full bg-slate-100 px-1.5 text-[10px] font-bold text-slate-500">{tk.media.length}</span>
            </p>
            <div className="flex flex-wrap gap-2">
              {tk.media.map((m) => (
                <a
                  key={m.id}
                  href={m.url}
                  target="_blank"
                  rel="noreferrer"
                  title={m.note || m.original_name || (m.kind === 'image' ? t('Photo') : t('Video'))}
                  className="relative block h-16 w-16 shrink-0 overflow-hidden rounded-lg ring-1 ring-slate-200 transition hover:ring-2 hover:ring-indigo-400"
                >
                  {m.kind === 'image' && m.url ? (
                    <img src={m.url} alt={m.original_name || t('Driver photo')} className="h-full w-full object-cover" />
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
            label={tf('reviewQueue.requestedBy', 'Requested by')}
            value={isSystem ? t('System') : (requested?.name || t('Driver'))}
            sub={requested?.at ? ago(requested.at, t) : null}
          />
          <MetaTile
            icon={<Icon.Clock className="h-3 w-3" />}
            label={tf('reviewQueue.requested', 'Requested')}
            value={requested?.at ? fmtDateTime(requested.at) : '—'}
          />
          <MetaTile
            icon={<Icon.Wrench className="h-3 w-3" />}
            label={tf('reviewQueue.lastMaintenance', 'Last maintenance')}
            value={!lm ? t('No record') : (lmOnboard ? t('None yet') : (dueDate(lm.at) || '—'))}
            sub={!lm
              ? null
              : (lmOnboard
                ? (lm.days_ago != null ? t('{n}d since onboarding', { n: lm.days_ago }) : t('since onboarding'))
                : `${lm.days_ago != null ? t('{n}d ago', { n: lm.days_ago }) : ''}${lm.reason === 'test' ? ` · ${t('inspection')}` : ''}`.trim())}
            muted={!lm || lmOnboard}
          />
          <MetaTile
            icon={<Icon.Gauge className="h-3 w-3" />}
            label={tf('reviewQueue.sinceOil', 'Since oil service')}
            value={tk.last_service
              ? (kmSince != null ? `${num(kmSince)} km` : `${num(tk.last_service.odometer)} km`)
              : t('No record')}
            sub={tk.last_service
              ? (kmSince != null ? t('since {km} km', { km: num(tk.last_service.odometer) }) : t('at last service'))
              : null}
            muted={!tk.last_service}
          />
        </div>

        {isLegacy && (
          <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 ring-1 ring-inset ring-amber-200">
            {t('Raised before this review queue existed — already in Abu Maroof’s queue and actionable there. Acknowledge to close the sign-off gap; nothing else changes.')}
          </p>
        )}

        {!isLegacy && !isWithdrawn && awaitingReturn && (
          <p className="flex items-start gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 ring-1 ring-inset ring-amber-200">
            <Icon.Clock className="mt-0.5 h-3.5 w-3.5 shrink-0" />
            {tf('review.rented.mayApprove',
              'The car is with a customer, so normally this waits until it comes back. You can still approve it now — it sits in Abu Maroof’s queue until the car is available, and the ordinary workflow runs from there. Or use Remind me and come back to it.')}
          </p>
        )}

        {/* A car whose RETURN IS BEING ORGANISED is not waiting on chance, so the request is not
            held shut. Say which link of the chain it is on, and say plainly that approving now is
            the intended move — otherwise an enabled Approve button beside a "rented" pill reads as
            a bug rather than as the design. */}
        {!isLegacy && !isWithdrawn && recallInFlight && !tk.oil_context?.in_our_custody && (
          <p className="flex items-center gap-1.5 rounded-lg bg-indigo-50 px-3 py-2 text-xs text-indigo-800 ring-1 ring-inset ring-indigo-200">
            <Icon.Check className="h-3.5 w-3.5 shrink-0" />
            {tf('review.oil.approveNow',
              'Being recalled — {stage}. You can approve it now: it waits in Abu Maroof’s queue until the car arrives, and the oil change is required either way.',
              { stage: OIL_STAGE_LABEL[tk.oil_context.recall.stage]
                ? t(OIL_STAGE_LABEL[tk.oil_context.recall.stage])
                : t('in progress') })}
          </p>
        )}

        {/* Collected but the rental is still open on OfficeManager's books — say so, or the enabled
            Approve button looks like a bug next to a "rented" pill. */}
        {!isLegacy && !isWithdrawn && tk.operational_status === 'rented' && tk.oil_context?.in_our_custody && (
          <p className="flex items-center gap-1.5 rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-800 ring-1 ring-inset ring-emerald-200">
            <Icon.Check className="h-3.5 w-3.5 shrink-0" />
            {t('The car has been collected from the customer and is with us — you can send it in now. The rental only closes on OfficeManager’s side, so the car still reads as rented.')}
          </p>
        )}

        {/* YOUR reminder on this request — nobody else's, and nobody else can see it. */}
        {tk.my_reminder && (
          <div className="flex items-start gap-2 rounded-lg bg-indigo-50 px-3 py-2 text-xs text-indigo-800 ring-1 ring-inset ring-indigo-200">
            <Icon.Clock className="mt-0.5 h-3.5 w-3.5 shrink-0" />
            <div className="min-w-0 flex-1">
              <p className="font-semibold">
                {tf('review.remind.pill', 'You’ll be reminded at {when}', { when: whenLabel(tk.my_reminder.remind_at) })}
              </p>
              {tk.my_reminder.note && <p className="truncate text-[11px] text-indigo-600">{tk.my_reminder.note}</p>}
            </div>
            <button
              type="button"
              disabled={remindBusy}
              onClick={() => onCancelReminder(tk)}
              className="shrink-0 text-[11px] font-semibold text-indigo-600 underline-offset-2 hover:underline disabled:opacity-50"
            >
              {tf('review.remind.cancel', 'Cancel')}
            </button>
          </div>
        )}
      </div>

      {/* ── Actions ──────────────────────────────────────────────────────────────── */}
      <div className="flex items-center justify-end gap-2 border-t border-slate-100 bg-slate-50/50 px-4 py-3">
        {isWithdrawn ? (
          // Nothing to decide. The only useful move left is to go and look at the car that is in the shop,
          // so that is the only button — an Approve/Reject pair here would be offering a choice that no
          // longer exists.
          <>
            <span className="me-auto text-[11px] text-slate-400">{tf('reviewQueue.noAction', 'No action needed')}</span>
            <Link
              to={`/vehicles/${tk.vehicle_id}`}
              className="inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold text-slate-600 transition-colors hover:bg-slate-100"
            >
              <Icon.Car className="h-4 w-4" /> {t('Open the car')}
            </Link>
          </>
        ) : isLegacy ? (
          <Button variant="secondary" loading={ackBusy} onClick={() => onAcknowledge(tk)}>
            <Icon.Check className="h-4 w-4" /> {t('Acknowledge')}
          </Button>
        ) : (
          <>
            {/* "The customer still has it, ask me again after lunch" is precisely the case this button
                exists for — and it is now the alternative to approving early rather than the only thing
                a reviewer can do with a rented car. */}
            <Button variant="ghost" loading={remindBusy} onClick={() => onRemind(tk)}>
              <Icon.Clock className="h-4 w-4" />
              {tk.my_reminder
                ? tf('review.remind.change', 'Change reminder')
                : tf('review.remind.button', 'Remind me')}
            </Button>
            {/* NOT disabled while the car is out on hire. Being with a customer is a fact worth STATING
                — the panel above says it — but it is not the reviewer's answer, and locking the buttons
                made it one: the request sat here undecidable until somebody happened to notice the car
                had come back. Approving early is already the established behaviour for a recalled car
                (it waits in Abu Maroof's queue until the car arrives); a rented car is the same
                situation with a less certain date, and the person reading the card is the one entitled
                to weigh that. */}
            <Button variant="danger" onClick={() => onReject(tk)}>
              <Icon.XCircle className="h-4 w-4" /> {t('Reject')}
            </Button>
            <Button variant="success" onClick={() => onApprove(tk)}>
              <Icon.Check className="h-4 w-4" /> {t('Approve & send')}
            </Button>
          </>
        )}
      </div>
    </div>
  );
}

function ApproveModal({ ticket, onClose, onDone }) {
  const { t, tf } = useI18n();
  const toast = useToast();
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    setBusy(true);
    try {
      // No reviewer note is collected: the card already shows what was reported and where it came
      // from, and that text travels with the ticket to the inspector. A second free-text box here
      // only invited a restatement of it. (The API still accepts `notes` for legacy/other callers.)
      await api.post(`/maintenance-tickets/${ticket.id}/review/approve`, {});
      onDone(t('Approved — sent to Abu Maroof'));
    } catch (e) {
      toast.error(e.response?.data?.message || t('Could not approve this request'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open
      onClose={onClose}
      title={tf('reviewQueue.approve', 'Approve inspection request')}
      subtitle={`${ticket.plate || `#${ticket.id}`} · ${t('will be sent to Abu Maroof')}`}
      footer={(
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>{tf('reviewQueue.cancel', 'Cancel')}</Button>
          <Button variant="success" onClick={submit} loading={busy}>{t('Approve & send')}</Button>
        </>
      )}
    >
      {/* Confirm what actually travels to the inspector, rather than asking for it again. */}
      <p className="text-sm text-slate-600">
        {ticket.customer_complaint
          ? t('Abu Maroof will receive this request with everything already on the card — including the note below.')
          : t('Abu Maroof will receive this request with everything already on the card.')}
      </p>
      {ticket.customer_complaint && (
        <p className="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-sm italic text-slate-600 ring-1 ring-inset ring-slate-100">
          “{ticket.customer_complaint}”
        </p>
      )}
    </Modal>
  );
}

// ── "Remind me later" plumbing ─────────────────────────────────────────────────────────────────
// The presets the server accepts (ReviewReminderService::OFFSET_PRESETS + CLOCK_PRESETS). The KEY is the
// contract — the label beside it is presentation and is translated at render time. "Tomorrow morning"
// resolves server-side to 08:00 tomorrow, not to "24 hours from now": a reminder set at 19:00 that fires
// at 19:00 the next day has missed the day it was meant to protect.
const REMINDER_PRESETS = [
  { key: '30m', labelKey: 'review.remind.in30m', labelEn: 'In 30 minutes' },
  { key: '1h', labelKey: 'review.remind.in1h', labelEn: 'In 1 hour' },
  { key: '2h', labelKey: 'review.remind.in2h', labelEn: 'In 2 hours' },
  { key: '4h', labelKey: 'review.remind.in4h', labelEn: 'In 4 hours' },
  { key: 'tomorrow_morning', labelKey: 'review.remind.tomorrow', labelEn: 'Tomorrow morning' },
  { key: 'next_week', labelKey: 'review.remind.nextWeek', labelEn: 'Next week' },
];

// <input type="datetime-local"> speaks LOCAL wall-clock with no zone, so it can neither read nor emit an
// ISO string. These two convert at the boundary; everything sent to the server is a real ISO instant.
function toLocalInputValue(date) {
  const d = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
  return d.toISOString().slice(0, 16);
}

function localInputToIso(value) {
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? null : d.toISOString();
}

// A sensible floor for the custom picker: now. Stops the obvious mistake (a time already past, which the
// server rejects anyway) before it costs a round-trip.
function nowLocalInput() {
  return toLocalInputValue(new Date());
}

// "in 2 hours" / "tomorrow at 08:00" — the reminder read back to the person who set it.
function whenLabel(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const sameDay = d.toDateString() === new Date().toDateString();
  const time = fmtClock(iso);
  return sameDay ? time : `${fmtDate(iso)} ${time}`;
}

// The time half of both modals — preset chips plus a custom picker. Owns nothing: the parent holds the
// chosen preset / custom value, because on the reject path that choice is optional and travels with the
// rejection rather than being submitted on its own.
function WhenPicker({ preset, custom, onPreset, onCustom, disabled }) {
  const { tf } = useI18n();

  return (
    <div>
      <div className="flex flex-wrap gap-1.5">
        {REMINDER_PRESETS.map((p) => (
          <button
            key={p.key}
            type="button"
            disabled={disabled}
            onClick={() => onPreset(p.key)}
            className={`rounded-full px-3 py-1.5 text-xs font-semibold ring-1 ring-inset transition disabled:opacity-50 ${
              preset === p.key
                ? 'bg-indigo-600 text-white ring-indigo-600'
                : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50'
            }`}
          >
            {tf(p.labelKey, p.labelEn)}
          </button>
        ))}
        <button
          type="button"
          disabled={disabled}
          onClick={() => onPreset('custom')}
          className={`rounded-full px-3 py-1.5 text-xs font-semibold ring-1 ring-inset transition disabled:opacity-50 ${
            preset === 'custom'
              ? 'bg-indigo-600 text-white ring-indigo-600'
              : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50'
          }`}
        >
          {tf('review.remind.custom', 'Pick a time…')}
        </button>
      </div>

      {preset === 'custom' && (
        <div className="mt-2">
          <Input
            type="datetime-local"
            label={tf('review.remind.customLabel', 'Remind me at')}
            min={nowLocalInput()}
            value={custom}
            disabled={disabled}
            onChange={(e) => onCustom(e.target.value)}
          />
        </div>
      )}
    </div>
  );
}

// The fixed rejection reasons, as a last resort. The server owns this list
// (Maintenance::REVIEW_REJECTION_REASONS) and is fetched on open; this copy exists so a reviewer whose
// network hiccups can still reject a request rather than being stuck staring at an empty picker. Codes
// must match the server's exactly — the code is what gets stored and counted.
const FALLBACK_REASONS = [
  { code: 'not_needed', label: "The car doesn't need it" },
  { code: 'duplicate', label: 'Already covered by another request' },
  { code: 'recently_done', label: 'Inspected or serviced recently' },
  { code: 'car_unavailable', label: "The car isn't available" },
  { code: 'wrong_vehicle', label: 'Raised on the wrong car' },
  { code: 'no_detail', label: 'Not enough detail to act on' },
  { code: 'handled_elsewhere', label: 'Already handled another way' },
  { code: 'other', label: 'Other reason', requires_note: true },
];

function RejectModal({ ticket, onClose, onDone }) {
  const toast = useToast();
  const { t, tf } = useI18n();
  const [reasons, setReasons] = useState(FALLBACK_REASONS);
  const [code, setCode] = useState('');
  const [note, setNote] = useState('');
  const [askAgain, setAskAgain] = useState(false);
  const [preset, setPreset] = useState('tomorrow_morning');
  const [custom, setCustom] = useState(nowLocalInput());
  const [busy, setBusy] = useState(false);

  // Pull the live list; keep the fallback if it doesn't arrive. Never blocks the form.
  useEffect(() => {
    let alive = true;
    api.get('/maintenance-tickets/review/rejection-reasons')
      .then((r) => {
        const list = r.data?.data;
        if (alive && Array.isArray(list) && list.length) setReasons(list);
      })
      .catch(() => { /* the fallback list stands */ });
    return () => { alive = false; };
  }, []);

  const chosen = reasons.find((r) => r.code === code) || null;
  const noteRequired = !!chosen?.requires_note;

  const submit = async () => {
    if (!code) {
      toast.error(tf('review.reject.needReason', 'Choose why this request is being rejected'));
      return;
    }
    if (noteRequired && !note.trim()) {
      toast.error(tf('review.reject.needNote', 'Add a short note saying what the reason was'));
      return;
    }

    // The reminder half is optional, so an unusable custom time is caught here rather than silently
    // dropping the "ask me again" the reviewer just asked for.
    let remindAt = null;
    if (askAgain) {
      if (preset === 'custom') {
        remindAt = localInputToIso(custom);
        if (!remindAt) {
          toast.error(tf('review.remind.badTime', 'Pick a valid time to be reminded'));
          return;
        }
      } else {
        remindAt = presetToIso(preset);
      }
    }

    setBusy(true);
    try {
      await api.post(`/maintenance-tickets/${ticket.id}/review/reject`, {
        rejection_code: code,
        rejection_reason: note.trim() || null,
        remind_at: remindAt,
      });
      onDone(remindAt
        ? tf('review.reject.doneWithReminder', 'Rejected — you’ll be reminded to revisit it')
        : tf('review.reject.done', 'Inspection request rejected'));
    } catch (e) {
      toast.error(e.response?.data?.message || tf('review.reject.failed', 'Could not reject this request'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open
      onClose={onClose}
      title={tf('review.reject.title', 'Reject inspection request')}
      subtitle={`${ticket.plate || `#${ticket.id}`} · ${tf('review.reject.subtitle', 'nothing will be sent externally')}`}
      footer={(
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>{tf('common.cancel', 'Cancel')}</Button>
          <Button variant="danger" onClick={submit} loading={busy}>{tf('review.reject.action', 'Reject')}</Button>
        </>
      )}
    >
      <fieldset>
        <legend className="mb-1.5 text-sm font-semibold text-slate-700">
          {tf('review.reject.why', 'Why is this being rejected?')}
          <span className="text-rose-500"> *</span>
        </legend>
        <div className="space-y-1">
          {reasons.map((r) => (
            <label
              key={r.code}
              className={`flex cursor-pointer items-start gap-2.5 rounded-lg px-3 py-2 text-sm ring-1 ring-inset transition ${
                code === r.code
                  ? 'bg-rose-50 text-rose-900 ring-rose-300'
                  : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50'
              }`}
            >
              <input
                type="radio"
                name="rejection_code"
                className="mt-0.5 h-4 w-4 shrink-0 accent-rose-600"
                checked={code === r.code}
                onChange={() => setCode(r.code)}
              />
              <span>{t(r.label)}</span>
            </label>
          ))}
        </div>
      </fieldset>

      <div className="mt-3">
        <Textarea
          label={noteRequired
            ? tf('review.reject.noteRequired', 'What was the reason?')
            : tf('review.reject.noteOptional', 'Anything to add (optional)')}
          required={noteRequired}
          rows={2}
          value={note}
          onChange={(e) => setNote(e.target.value)}
        />
        <p className="mt-1 text-[11px] text-slate-400">
          {tf('review.reject.noteHint', 'The reason and this note both go back to whoever raised the request.')}
        </p>
      </div>

      {/* "Not now" is not "never" — the second half of that thought, captured instead of lost. */}
      <div className="mt-3 rounded-lg bg-slate-50 px-3 py-2.5 ring-1 ring-inset ring-slate-200">
        <label className="flex cursor-pointer items-start gap-2.5 text-sm font-medium text-slate-700">
          <input
            type="checkbox"
            className="mt-0.5 h-4 w-4 shrink-0 accent-indigo-600"
            checked={askAgain}
            onChange={(e) => setAskAgain(e.target.checked)}
          />
          <span>
            {tf('review.reject.askAgain', 'Remind me to look at this car again')}
            <span className="block text-[11px] font-normal text-slate-500">
              {tf('review.reject.askAgainHint', 'For a car that is fine today but should be checked later.')}
            </span>
          </span>
        </label>
        {askAgain && (
          <div className="mt-2.5">
            <WhenPicker preset={preset} custom={custom} onPreset={setPreset} onCustom={setCustom} disabled={busy} />
          </div>
        )}
      </div>
    </Modal>
  );
}

// Resolve a preset to an ISO instant CLIENT-side, for the reject path only — that endpoint takes a
// timestamp, not a preset key, because the reminder rides along with the rejection. The "Remind me"
// button sends the preset key itself and lets the server resolve it. Kept in step with
// ReviewReminderService::presetToMoment.
function presetToIso(preset) {
  const d = new Date();
  const mins = { '30m': 30, '1h': 60, '2h': 120, '4h': 240 }[preset];
  if (mins) {
    d.setMinutes(d.getMinutes() + mins);
    return d.toISOString();
  }
  if (preset === 'tomorrow_morning') d.setDate(d.getDate() + 1);
  else if (preset === 'next_week') d.setDate(d.getDate() + 7);
  else return null;
  d.setHours(8, 0, 0, 0);
  return d.toISOString();
}

// "Remind me about this request later." Decides nothing — the request stays in the queue for whoever
// gets to it first, and this reminder is the caller's alone.
function RemindModal({ ticket, onClose, onDone }) {
  const toast = useToast();
  const { tf } = useI18n();
  const [preset, setPreset] = useState('2h');
  const [custom, setCustom] = useState(nowLocalInput());
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    const body = { note: note.trim() || null };
    if (preset === 'custom') {
      const iso = localInputToIso(custom);
      if (!iso) {
        toast.error(tf('review.remind.badTime', 'Pick a valid time to be reminded'));
        return;
      }
      body.remind_at = iso;
    } else {
      // Send the KEY, not a computed time: the server owns what "tomorrow morning" means, and its clock
      // is the one the dispatcher runs on.
      body.preset = preset;
    }

    setBusy(true);
    try {
      const res = await api.post(`/maintenance-tickets/${ticket.id}/review/remind`, body);
      const at = res.data?.data?.remind_at;
      onDone(tf('review.remind.done', 'Reminder set for {when}', { when: whenLabel(at) }));
    } catch (e) {
      toast.error(e.response?.data?.message || tf('review.remind.failed', 'Could not set that reminder'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open
      onClose={onClose}
      title={tf('review.remind.title', 'Remind me about this request')}
      subtitle={`${ticket.plate || `#${ticket.id}`} · ${tf('review.remind.subtitle', 'stays in the queue — nothing is decided')}`}
      footer={(
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>{tf('common.cancel', 'Cancel')}</Button>
          <Button variant="primary" onClick={submit} loading={busy}>{tf('review.remind.action', 'Remind me')}</Button>
        </>
      )}
    >
      <p className="text-sm text-slate-600">
        {tf('review.remind.explain', 'The request stays where it is and anyone can still act on it. Only you get this reminder, and it cancels itself if the request is approved or rejected before then.')}
      </p>

      <div className="mt-3">
        <WhenPicker preset={preset} custom={custom} onPreset={setPreset} onCustom={setCustom} disabled={busy} />
      </div>

      <div className="mt-3">
        <Textarea
          label={tf('review.remind.note', 'What should the reminder say? (optional)')}
          rows={2}
          value={note}
          onChange={(e) => setNote(e.target.value)}
        />
      </div>
    </Modal>
  );
}

// ── Tab 2 — Parked: the cars physically in a shop right now ───────────────────────────────────────
// Driven by the SHOP STAY, not by which requests happened to be parked: a car on a lift belongs here
// whether or not anyone had asked for a test on it. Same fact the countdown pauses the clock on, from
// the same service, so the two tabs can never disagree about who is in the workshop.
//
// The card answers the four things a Controller actually needs: which visit parked it, how long it has
// been in, what the car's last check was, and what happens to its test schedule when it comes out.

const PARK_SOURCE = {
  om_contract:  { chip: 'In OM maintenance', tone: 'bg-violet-50 text-violet-700 ring-violet-200', icon: 'Invoice' },
  workshop_log: { chip: 'At a garage',       tone: 'bg-amber-50 text-amber-700 ring-amber-200',   icon: 'Wrench' },
};

function Fact({ label, children, strong = false }) {
  return (
    <div className="min-w-0">
      <div className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{label}</div>
      <div className={`mt-0.5 truncate text-[13px] ${strong ? 'font-bold text-slate-900' : 'font-medium text-slate-700'}`}>
        {children}
      </div>
    </div>
  );
}

function ParkedCard({ row }) {
  const { tf } = useI18n();
  const p = row.parked;
  const src = PARK_SOURCE[p.source] || PARK_SOURCE.workshop_log;
  const req = row.request;
  const test = row.last_test;

  const visitLink = p.source === 'om_contract' && p.ref_id ? `/contracts/${p.ref_id}` : null;

  return (
    <div className="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-slate-200">
      {/* Who, and what parked it */}
      <div className="flex flex-wrap items-start justify-between gap-2 border-b border-slate-100 px-4 py-3">
        <div className="min-w-0">
          <Link to={`/vehicles/${row.vehicle_id}`} className="text-base font-bold text-slate-900 hover:text-indigo-600">
            {row.plate_no || `#${row.vehicle_id}`}
          </Link>
          <span className="ms-2 text-sm text-slate-400">{[row.make, row.model].filter(Boolean).join(' ')}</span>
        </div>
        <span className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold ring-1 ring-inset ${src.tone}`}>
          <Icon.Wrench className="h-3.5 w-3.5" />
          {p.source === 'om_contract'
            ? tf('review.parked.omChip', 'In OM maintenance')
            : tf('review.parked.garageChip', 'At a garage')}
        </span>
      </div>

      <div className="grid grid-cols-2 gap-x-4 gap-y-3 px-4 py-3 sm:grid-cols-4">
        <Fact label={p.source === 'om_contract' ? tf('review.parked.contract', 'Contract') : tf('review.parked.garage', 'Garage')}>
          {visitLink
            ? <Link to={visitLink} className="text-violet-700 underline-offset-2 hover:underline">{p.label || `#${p.ref_id}`}</Link>
            : (p.label || '—')}
        </Fact>
        <Fact label={tf('review.parked.started', 'Went in')}>{p.started_at ? dueDate(p.started_at) : '—'}</Fact>
        <Fact label={tf('review.parked.inShop', 'In the shop')} strong>
          {p.days_in_shop === null || p.days_in_shop === undefined
            ? '—'
            : `${p.days_in_shop} ${p.days_in_shop === 1 ? tf('review.parked.day', 'day') : tf('review.parked.days', 'days')}`}
        </Fact>
        <Fact label={tf('review.parked.work', 'Work')}>{p.work || '—'}</Fact>
      </div>

      {/* What the car's history says — the real test if there is one, otherwise the record the count
          actually runs from. Never one dressed up as the other. */}
      <div className="grid grid-cols-2 gap-x-4 gap-y-3 border-t border-slate-100 bg-slate-50/60 px-4 py-3 sm:grid-cols-2">
        <Fact label={tf('review.parked.lastTest', 'Last test drive')}>
          {test
            ? <>{dueDate(test.at)} <span className="text-slate-400">· {test.days_ago}d</span></>
            : <span className="text-slate-400">{tf('review.parked.noTest', 'None on record')}</span>}
        </Fact>
        <Fact label={tf('review.parked.lastReady', 'Last came back ready')}>
          {row.anchor?.at
            ? <>{dueDate(row.anchor.at)} <span className="text-slate-400">· {row.anchor.days_ago}d</span></>
            : '—'}
        </Fact>
      </div>

      {/* The recommendation that was already pending when it went in — preserved, not lost. */}
      <div className="border-t border-slate-100 px-4 py-3">
        <div className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">
          {tf('review.parked.recommendation', 'Test recommendation')}
        </div>
        {req ? (
          <div className="mt-1 flex flex-wrap items-center gap-2 text-[13px]">
            <span className="font-semibold text-slate-800">
              {req.was_parked_by_this_visit
                ? tf('review.parked.reqParked', 'Already pending before this visit — parked by it')
                : tf('review.parked.reqOpen', 'Open request on this car')}
            </span>
            <span className={`rounded-full px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset ${
              req.source_lane === 'oil_projection'
                ? 'bg-amber-50 text-amber-700 ring-amber-200'
                : 'bg-indigo-50 text-indigo-700 ring-indigo-200'
            }`}>
              {req.source_lane === 'oil_projection'
                ? tf('review.parked.laneOil', 'Source: Oil Projection')
                : tf('review.parked.laneTest', 'Source: Test schedule')}
            </span>
            <Link to={`/maintenance-workflow/${req.ticket_id}`} className="text-xs text-violet-700 underline-offset-2 hover:underline">
              #{req.ticket_id}
            </Link>
            {req.raised_at && <span className="text-xs text-slate-400">{tf('review.parked.raised', 'raised')} {dueDate(req.raised_at)}</span>}
          </div>
        ) : (
          <div className="mt-1 text-[13px] text-slate-500">{tf('review.parked.noReq', 'None — nothing was pending when it went in.')}</div>
        )}
      </div>

      {/* State + what happens next. */}
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t border-slate-100 bg-violet-50/50 px-4 py-2.5 text-xs">
        <span className="inline-flex items-center gap-1.5 font-bold text-violet-700">
          <Icon.Clock className="h-3.5 w-3.5" />
          {tf('review.parked.paused', 'Countdown paused while in the shop')}
        </span>
        <span className="text-slate-500">{row.on_release}</span>
      </div>
    </div>
  );
}

function ParkedInShopPanel() {
  const { tf } = useI18n();
  const fetcher = useCallback(async () => (await api.get('/maintenance-tickets/parked-in-shop')).data.data, []);
  const { data, loading, error } = useFetch(fetcher, []);
  const [source, setSource] = useState('all');

  const rows = useMemo(() => data?.rows || [], [data]);
  const summary = data?.summary || {};
  const outside = data?.outside_fleet || [];

  const shown = useMemo(
    () => (source === 'all' ? rows : rows.filter((r) => r.parked.source === source)),
    [rows, source],
  );

  // Only the OM maintenance contract parks a car (features.diagnostic_gate.workshop_log_parks is
  // off), so there is normally ONE source and a filter row would just repeat the same number twice.
  // The chips appear only if a second source is ever switched back on and actually has cars in it.
  const sources = [
    { key: 'om_contract', label: tf('review.parked.omChip', 'In OM maintenance'), n: summary.om_contract || 0 },
    { key: 'workshop_log', label: tf('review.parked.garageChip', 'At a garage'), n: summary.workshop_log || 0 },
  ].filter((c) => c.n > 0);
  const chips = sources.length > 1
    ? [{ key: 'all', label: tf('review.parked.allChip', 'All in the shop'), n: summary.total || 0 }, ...sources]
    : [];

  return (
    <div role="tabpanel" id="panel-withdrawn" aria-labelledby="tab-withdrawn" className="space-y-4">
      <p className="text-xs text-slate-500">
        {tf(
          'review.parked.blurb',
          'These cars are in the workshop right now on an OfficeManager maintenance contract — that contract is the only thing that puts a car here. While a car is in here it is not treated as a rental car and its test countdown is paused, so parked days never make it look overdue. When OM closes the contract the car leaves this tab, the count starts again from the day it came back, and the next morning scan re-checks it.',
        )}
      </p>

      {error && <div className="rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700">{String(error)}</div>}

      <div className="flex flex-wrap items-center gap-2">
        {chips.length > 0 ? chips.map((c) => (
          <button
            key={c.key}
            type="button"
            onClick={() => setSource(c.key)}
            className={`rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset transition ${
              source === c.key ? 'bg-indigo-50 text-indigo-700 ring-indigo-200' : 'bg-white text-slate-500 ring-slate-200 hover:text-slate-800'
            }`}
          >
            {c.label} <span className="tabular-nums opacity-60">{c.n}</span>
          </button>
        )) : (
          <span className="inline-flex items-center gap-1.5 rounded-full bg-violet-50 px-3 py-1 text-xs font-semibold text-violet-700 ring-1 ring-inset ring-violet-200">
            <Icon.Wrench className="h-3.5 w-3.5" />
            {tf('review.parked.omChip', 'In OM maintenance')} <span className="tabular-nums opacity-70">{summary.total || 0}</span>
          </span>
        )}
        {summary.with_request > 0 && (
          <span className="ms-auto self-center text-xs text-slate-400">
            {summary.with_request} {tf('review.parked.hadRequest', 'had a test request pending when they went in')}
          </span>
        )}
      </div>

      {loading ? (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <Skeleton className="h-56 rounded-xl" />
          <Skeleton className="h-56 rounded-xl" />
        </div>
      ) : shown.length === 0 ? (
        <EmptyState
          icon={<Icon.Check className="h-7 w-7" />}
          title={tf('review.parked.emptyTitle', 'Nothing in the shop')}
          message={tf('review.parked.emptyBody', 'No car is on a maintenance contract or an open garage trip right now.')}
        />
      ) : (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          {shown.map((r) => <ParkedCard key={r.vehicle_id} row={r} />)}
        </div>
      )}

      {/* Cars in a shop that the planning board does not cover either — named, not silently dropped. */}
      {outside.length > 0 && (
        <p className="text-[11px] text-slate-400">
          {tf('review.parked.outside', 'Also in a shop but outside the operational fleet (not scheduled for tests):')}{' '}
          {outside.map((v) => `${v.plate_no} (${v.for_sale ? tf('review.parked.forSale', 'for sale') : v.status})`).join(', ')}
        </p>
      )}
    </div>
  );
}

// ── Tab 3 — the planning board: when is each car due ──────────────────────────────────────────────
// The queue answers "what has the system asked for". This answers the question before it. Same
// rulebook read forwards — a car in the Overdue or Due-today lane is exactly a car the 07:30 scan
// raises, because both come from DiagnosticGateService. No second definition of "due" anywhere.
//
// Three of the lanes deliberately carry NO countdown, because a number there would be a lie: the car
// is already spoken for, being worked on, or sitting in a shop with the clock paused and no way to
// know when it will be released.

const LANES = [
  { key: 'overdue',     label: 'Overdue',           tone: 'text-rose-700',   dot: 'bg-rose-500' },
  { key: 'today',       label: 'Due today',         tone: 'text-rose-600',   dot: 'bg-rose-400' },
  { key: 'tomorrow',    label: 'Due tomorrow',      tone: 'text-amber-700',  dot: 'bg-amber-500' },
  { key: 'soon',        label: 'Due in 2–3 days',   tone: 'text-amber-600',  dot: 'bg-amber-300' },
  { key: 'later',       label: 'Due later',         tone: 'text-slate-600',  dot: 'bg-slate-300' },
  { key: 'requested',   label: 'Already requested', tone: 'text-indigo-600', dot: 'bg-indigo-400' },
  { key: 'in_workflow', label: 'Being worked on',   tone: 'text-indigo-600', dot: 'bg-indigo-300' },
  { key: 'parked',      label: 'Parked — paused',   tone: 'text-violet-700', dot: 'bg-violet-400' },
];

const ANCHOR_SOURCE_LABEL = {
  workflow: 'Ticket',
  legacy: 'Garage log',
  om_contract: 'Maintenance contract',
  onboarding: 'Since we got it',
};

const OPERATIONAL_LABEL = {
  rented: 'Rented',
  available: 'Ready',
  maintenance: 'In workshop',
  in_transit: 'Moving',
  test: 'On test',
  transfer: 'Transfer',
  sale_prep: 'Sale prep',
};

/** The one sentence the whole board exists for: when is this car due? */
function Countdown({ r }) {
  const { tf } = useI18n();

  if (r.bucket === 'parked') {
    return <span className="font-semibold text-violet-700">{tf('review.board.paused', 'Paused — in the shop')}</span>;
  }
  if (r.bucket === 'in_workflow') {
    return <span className="font-semibold text-indigo-600">{tf('review.board.pausedWork', 'Paused — being worked on')}</span>;
  }
  if (r.bucket === 'requested') {
    return <span className="font-semibold text-indigo-600">{tf('review.board.requested', 'Already requested')}</span>;
  }
  if (r.days_over) {
    return (
      <span className="font-bold text-rose-600">
        {tf('review.board.overdue', 'Overdue by {n} days', { n: r.days_over })}
      </span>
    );
  }
  if (r.days_left === 0) return <span className="font-bold text-rose-600">{tf('review.board.today', 'Test today')}</span>;
  if (r.days_left === 1) return <span className="font-bold text-amber-600">{tf('review.board.tomorrow', 'Test in 1 day')}</span>;
  if (r.days_left > 1) {
    return (
      <span className={r.days_left <= 3 ? 'font-semibold text-amber-600' : 'font-medium text-slate-700'}>
        {tf('review.board.inDays', 'Test in {n} days', { n: r.days_left })}
      </span>
    );
  }
  return <span className="text-slate-300">—</span>;
}

function FleetCountdownPanel() {
  const { t, tf } = useI18n();
  // Whole-fleet walk (~150 cars, ~4s) — fetched once when the tab is opened, not polled. Nothing here
  // changes minute to minute: the clock ticks in days and the scan runs once a morning.
  const fetcher = useCallback(async () => (await api.get('/maintenance-tickets/test-countdown')).data.data, []);
  const { data, loading, error } = useFetch(fetcher, []);
  const [lane, setLane] = useState('all');

  const rows = useMemo(() => data?.rows || [], [data]);
  const buckets = data?.buckets || {};
  const limits = data?.limits || {};

  const shown = useMemo(
    () => (lane === 'all' ? rows : rows.filter((r) => r.bucket === lane)),
    [rows, lane],
  );

  const columns = [
    {
      key: 'plate_no',
      header: tf('review.board.vehicle', 'Vehicle'),
      render: (r) => (
        <Link to={`/vehicles/${r.vehicle_id}`} className="font-semibold text-slate-800 hover:text-indigo-600">
          {r.plate_no || `#${r.vehicle_id}`}
          <span className="ms-2 block text-[11px] font-normal text-slate-400">{[r.make, r.model].filter(Boolean).join(' ')}</span>
        </Link>
      ),
    },
    {
      key: 'operational_status',
      header: tf('review.board.status', 'Status'),
      render: (r) => {
        const l = OPERATIONAL_LABEL[r.operational_status]
          ? t(OPERATIONAL_LABEL[r.operational_status])
          : (r.operational_status || '—');
        const parked = r.bucket === 'parked';
        return (
          <span className={`inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${
            parked ? 'bg-violet-50 text-violet-700 ring-violet-200'
              : r.operational_status === 'rented' ? 'bg-sky-50 text-sky-700 ring-sky-200'
                : 'bg-slate-50 text-slate-600 ring-slate-200'
          }`}>{l}</span>
        );
      },
    },
    {
      key: 'last_test',
      header: tf('review.board.lastTest', 'Last test'),
      // The real test drive if the car has one; otherwise say plainly what the count DOES run from
      // rather than passing a workshop return off as a test.
      render: (r) => (r.last_test
        ? <span className="text-slate-700">{dueDate(r.last_test.at)}<span className="ms-1 text-slate-400">· {r.last_test.days_ago}d</span></span>
        : (
          <span className="text-[11px] text-slate-400">
            {tf('review.board.noTest', 'no test on record')}
            {r.anchor?.at && <><br />{tf('review.board.readySince', 'ready since')} {dueDate(r.anchor.at)}</>}
          </span>
        )),
    },
    {
      key: 'due_on',
      header: tf('review.board.nextTest', 'Next test'),
      render: (r) => (r.due_on
        ? <span className="text-slate-700">{dueDate(r.due_on)}</span>
        : <span className="text-slate-300">—</span>),
    },
    {
      key: 'countdown',
      header: tf('review.board.countdown', 'Countdown'),
      render: (r) => <Countdown r={r} />,
    },
    {
      key: 'why',
      header: tf('review.board.why', 'Why'),
      render: (r) => (
        <span className="text-[11px] leading-snug text-slate-600">
          {r.why || '—'}
          {r.anchor?.source && r.bucket !== 'parked' && (
            <span className="mt-0.5 block text-slate-400">
              {tf('review.board.countingFrom', 'Counting from')}{' '}
              {r.anchor.source === 'workflow' && r.anchor.source_id
                ? <Link to={`/maintenance-workflow/${r.anchor.source_id}`} className="text-violet-600 underline-offset-2 hover:underline">{t(ANCHOR_SOURCE_LABEL.workflow)} #{r.anchor.source_id}</Link>
                : r.anchor.source === 'om_contract' && r.anchor.source_id
                  ? <Link to={`/contracts/${r.anchor.source_id}`} className="text-violet-600 underline-offset-2 hover:underline">{t(ANCHOR_SOURCE_LABEL.om_contract)} #{r.anchor.source_id}</Link>
                  : (ANCHOR_SOURCE_LABEL[r.anchor.source] ? t(ANCHOR_SOURCE_LABEL[r.anchor.source]) : r.anchor.source)}
            </span>
          )}
          {r.bucket === 'parked' && r.on_release && (
            <span className="mt-0.5 block text-violet-500">{r.on_release}</span>
          )}
        </span>
      ),
    },
  ];

  return (
    <div role="tabpanel" id="panel-countdown" aria-labelledby="tab-countdown" className="space-y-4">
      <p className="text-xs text-slate-500">
        {tf(
          'review.board.blurb',
          'Every car we are running and when it is next due for a test. The count runs from the day the car was last ready — a closed ticket, a garage-log return, or a maintenance contract that closed — whichever is latest, and it pauses entirely while the car is in a shop, so parked days never make a car look overdue.',
        )}
        {limits.downtime_days
          ? ` (${tf('review.board.limit', 'Limit')}: ${limits.downtime_days}d ${tf('review.board.afterRental', 'once it has been rented since its last check')}, ${limits.inactive_days}d ${tf('review.board.ifUnrented', 'if it has not')}.)`
          : ''}
      </p>

      {error && <div className="rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700">{String(error)}</div>}

      <div className="flex flex-wrap gap-2">
        <button
          type="button"
          onClick={() => setLane('all')}
          className={`rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset transition ${
            lane === 'all' ? 'bg-indigo-50 text-indigo-700 ring-indigo-200' : 'bg-white text-slate-500 ring-slate-200 hover:text-slate-800'
          }`}
        >
          {tf('review.board.allCars', 'All cars')} <span className="tabular-nums opacity-60">{rows.length}</span>
        </button>
        {LANES.filter((l) => (buckets[l.key] || 0) > 0).map((l) => (
          <button
            key={l.key}
            type="button"
            onClick={() => setLane(l.key)}
            className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset transition ${
              lane === l.key ? 'bg-indigo-50 text-indigo-700 ring-indigo-200' : 'bg-white text-slate-500 ring-slate-200 hover:text-slate-800'
            }`}
          >
            <span className={`h-1.5 w-1.5 rounded-full ${l.dot}`} />
            {t(l.label)} <span className="tabular-nums opacity-60">{buckets[l.key]}</span>
          </button>
        ))}
      </div>

      <DataTable
        columns={columns}
        rows={shown}
        rowKey={(r) => r.vehicle_id}
        loading={loading}
        dense
        stickyHeader
        highlightRow={(r) => r.bucket === 'overdue' || r.bucket === 'today'}
        empty={tf('review.board.empty', 'No cars in this lane.')}
      />
    </div>
  );
}


/* ── where the car IS, as a filter on the queue ────────────────────────────────────────────────────
 *
 * A Controller works this queue differently depending on where the car is standing. A car sitting on
 * the yard can be sent in today; a car out on rent cannot be touched until the customer brings it back.
 * Same request, different decision — so the queue can be narrowed to one or the other.
 *
 * The value is the car's own `operational_status` (vehicles.operational_status, carried on each ticket by
 * MaintenanceWorkflowResource) — a stored fact about the car, not a judgement made here. Filtering never
 * changes the badge on the tab: that stays the whole backlog, so narrowing the list can't hide the count.
 */
const CAR_STATE_LABEL = {
  available:   ['review.carState.available', 'On the yard — available'],
  rented:      ['review.carState.rented', 'Out on rent'],
  maintenance: ['review.carState.maintenance', 'In the shop'],
  test:        ['review.carState.test', 'On a test drive'],
  in_transit:  ['review.carState.inTransit', 'In transit'],
  transfer:    ['review.carState.transfer', 'Being transferred'],
  sale_prep:   ['review.carState.salePrep', 'Sale prep'],
  unknown:     ['review.carState.unknown', 'Car state not recorded'],
};
const CAR_STATE_ORDER = ['available', 'rented', 'maintenance', 'test', 'in_transit', 'transfer', 'sale_prep', 'unknown'];
const carStateOf = (tk) => (CAR_STATE_LABEL[tk.operational_status] ? tk.operational_status : 'unknown');

export default function InspectionReviewQueue() {
  const toast = useToast();
  const { t, tf } = useI18n();
  const { can } = usePermissions();
  const canManage = can('maintenance.manage');
  const [modal, setModal] = useState(null); // { action: 'approve'|'reject'|'remind'|'request'|'complaint', ticket }
  const [vehicles, setVehicles] = useState([]);

  const fetcher = useCallback(async () => (await api.get('/maintenance-tickets/pending-review')).data.data, []);
  const { data, loading, error, reload } = useFetch(fetcher, [], {
    refreshInterval: 8000,
    paused: () => !!modal,
  });

  const tickets = useMemo(() => data || [], [data]);

  // Two different things arrive on this endpoint and they must never be counted as one. `awaiting` is
  // work: requests a Controller still has to decide. `withdrawn` is the parked pile — requests the
  // system answered with "that car is IN THE SHOP RIGHT NOW", and the backend keeps one only while that
  // is still true (open OM type-U contract / open garage-log trip). A car that came back is recounted,
  // not carded, so nothing here is stale by construction.
  const awaiting = useMemo(() => tickets.filter((t) => !t.review?.is_system_withdrawal), [tickets]);

  // Narrow the queue to where the car is standing — "show me only the cars I can send in today", or only
  // the ones stuck with a customer. `all` is the default, so nobody has to opt back into the full list.
  const [carState, setCarState] = useState('all');
  const carStateChips = useMemo(() => {
    const n = {};
    awaiting.forEach((tk) => { const k = carStateOf(tk); n[k] = (n[k] || 0) + 1; });
    return CAR_STATE_ORDER.filter((k) => n[k] > 0).map((k) => ({ key: k, label: CAR_STATE_LABEL[k], n: n[k] }));
  }, [awaiting]);
  const awaitingShown = useMemo(
    () => (carState === 'all' ? awaiting : awaiting.filter((tk) => carStateOf(tk) === carState)),
    [awaiting, carState],
  );
  // A chip that stops existing (its last request was actioned) must not leave the queue looking empty.
  useEffect(() => {
    if (carState !== 'all' && !carStateChips.some((c) => c.key === carState)) setCarState('all');
  }, [carStateChips, carState]);

  // Three tabs, three questions. This one: what needs a decision. Tab 2: which cars are in a shop
  // right now (its own endpoint, keyed off the shop stay — not off this payload, because a car on a
  // lift belongs there whether or not a request happened to be parked on it). Tab 3: what the system
  // is about to ask for. The parked tab carries no badge here on purpose: the only honest count comes
  // from its own query, and a stale number on the tab would contradict the list inside it.
  const [tab, setTab] = useState('awaiting');
  const activeTab = tab;

  // Deep-link focus — /inspection-review?ticket=<id>. The Action Center links here when a Controller
  // clicks "Review Request" on a maint_review_pending alert, and Send-a-car-in links here with the exact
  // record it just wrote or added to. Scroll that card into view and pulse a highlight ring so they land
  // on the right request, not the top of a long queue.
  const [searchParams, setSearchParams] = useSearchParams();
  const targetTicket = searchParams.get('ticket');
  const [highlightId, setHighlightId] = useState(null);
  const highlightTimer = useRef(null);
  const missingTimer = useRef(null);

  useEffect(() => {
    // The query param IS the guard — it is dropped the moment this resolves, either way, so a manual
    // refresh doesn't re-highlight and a second deep-link to the same card still works.
    if (!targetTicket || loading) return undefined;

    const match = tickets.find((t) => String(t.id) === String(targetTicket));
    if (match) {
      clearTimeout(missingTimer.current);
      clearTimeout(highlightTimer.current);
      // The card may live on a tab that isn't showing — a "See why" bell for a withdrawn request lands
      // here, and Send-a-car-in can fire this while the countdown tab is open. Switch to the card's own
      // tab first, or the deep-link would scroll to something that isn't rendered.
      setTab(match.review?.is_system_withdrawal ? 'withdrawn' : 'awaiting');
      // A deep-link points at ONE card — clear any car-state narrowing, or it would scroll to a card the
      // filter is hiding.
      setCarState('all');
      setHighlightId(match.id);
      requestAnimationFrame(() => {
        document.getElementById(`review-card-${match.id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });
      highlightTimer.current = setTimeout(() => setHighlightId(null), 3500);
      setSearchParams({}, { replace: true });
      return undefined;
    }

    // NOT THERE YET is not the same as NOT THERE. A request written seconds ago in the Send-a-car-in
    // modal lands here before the list carrying it has come back, and answering that with "already
    // actioned" would be a lie about the card the person is looking at. Wait one refresh cycle — this
    // effect re-runs on every new payload, so an arrival cancels the verdict — and only then say so.
    missingTimer.current = setTimeout(() => {
      // No longer pending — already approved/rejected by someone else, or auto-resolved.
      toast.info(t('That request is no longer awaiting review — it may have already been actioned.'));
      setSearchParams({}, { replace: true });
    }, 10000);
    return () => clearTimeout(missingTimer.current);
  }, [targetTicket, loading, tickets, toast, setSearchParams, t]);

  useEffect(() => () => {
    clearTimeout(highlightTimer.current);
    clearTimeout(missingTimer.current);
  }, []);

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

  // Cancelling your own reminder is a one-click action on the card, so it lives here rather than behind
  // a modal. Setting one opens RemindModal (you have to say WHEN).
  const [remindBusyId, setRemindBusyId] = useState(null);
  const onCancelReminder = async (tk) => {
    setRemindBusyId(tk.id);
    try {
      await api.delete(`/maintenance-tickets/${tk.id}/review/remind`);
      toast.success(tf('review.remind.cancelled', 'Reminder cancelled'));
      reload({ silent: true });
    } catch (e) {
      toast.error(e.response?.data?.message || tf('review.remind.cancelFailed', 'Could not cancel that reminder'));
    } finally {
      setRemindBusyId(null);
    }
  };

  const [ackBusyId, setAckBusyId] = useState(null);
  const onAcknowledge = async (tk) => {
    setAckBusyId(tk.id);
    try {
      await api.post(`/maintenance-tickets/${tk.id}/review/acknowledge-legacy`);
      toast.success(t('Acknowledged'));
      reload({ silent: true });
    } catch (e) {
      toast.error(e.response?.data?.message || t('Could not acknowledge this request'));
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
              {t('Controller Approval Gate')}
              {!loading && (
                <span style={{ color: 'var(--cyan)', fontWeight: 700 }}>
                  · {t('{n} awaiting', { n: awaiting.length })}
                </span>
              )}
            </div>
            <h1 className="font-display" style={{ fontSize: 24, fontWeight: 700, letterSpacing: '-.02em', color: 'var(--ink)', margin: 0 }}>{tf('reviewQueue.title', 'Inspection Review Queue')}</h1>
            <p style={{ marginTop: 6, fontSize: 13.5, color: 'var(--ink-3)' }}>{tf('reviewQueue.subtitle', 'Requests awaiting Controller approval before they reach Abu Maroof.')}</p>
          </div>
          <div style={{ display: 'flex', gap: 9, flexWrap: 'wrap' }}>
            {canManage && <button className="opx-btn primary" onClick={() => setModal({ action: 'request' })}>+ {t('Request Inspection')}</button>}
            {canManage && <button className="opx-btn" onClick={() => setModal({ action: 'complaint' })}>{tf('reviewQueue.newComplaint', '📣 New complaint')}</button>}
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
        ) : (
          <>
          {/* Three tabs, three questions: what needs a decision; which cars are in a shop right now;
              and — the other direction entirely — what the system is about to ask for. All three
              always render, so the workshop and planning views are reachable on a quiet day with an
              empty queue; each of the last two loads its own data when opened. */}
          <Tabs
            ariaLabel={t('Inspection review sections')}
            active={activeTab}
            onChange={setTab}
            tabs={[
              { key: 'awaiting', label: tf('review.tabs.awaiting', 'Awaiting review'), badge: awaiting.length, icon: <Icon.Clock className="h-4 w-4" /> },
              { key: 'withdrawn', label: tf('review.tabs.inShop', 'Needs a test — done by OM'), icon: <Icon.Wrench className="h-4 w-4" /> },
              { key: 'countdown', label: tf('review.tabs.countdown', 'When each car is due'), icon: <Icon.Calendar className="h-4 w-4" /> },
            ]}
          />

          {activeTab === 'countdown' ? (
            <FleetCountdownPanel />
          ) : activeTab === 'awaiting' ? (
            <div role="tabpanel" id="panel-awaiting" aria-labelledby="tab-awaiting" className="space-y-6">
              {awaiting.length === 0 ? (
                <EmptyState
                  icon={<Icon.Check className="h-7 w-7" />}
                  title={tf('reviewQueue.allCaughtUp', 'All caught up')}
                  message={tf('reviewQueue.allCaughtUpBody', 'No inspection requests are waiting for review.')}
                />
              ) : (
                <>
                  {/* Where the car is standing. One chip per state actually present, each carrying its
                      own count, so the filter row doubles as a read of the queue. */}
                  {carStateChips.length > 1 && (
                    <div className="flex flex-wrap items-center gap-2">
                      {[{ key: 'all', label: ['review.carState.all', 'All cars'], n: awaiting.length }, ...carStateChips].map((c) => (
                        <button
                          key={c.key}
                          type="button"
                          onClick={() => setCarState(c.key)}
                          aria-pressed={carState === c.key}
                          className={`rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset transition ${
                            carState === c.key
                              ? 'bg-indigo-50 text-indigo-700 ring-indigo-200'
                              : 'bg-white text-slate-500 ring-slate-200 hover:text-slate-800'
                          }`}
                        >
                          {tf(c.label[0], c.label[1])} <span className="tabular-nums opacity-60">{c.n}</span>
                        </button>
                      ))}
                    </div>
                  )}

                  {/* Analytics — the shape of the queue, before the request cards. Withdrawn requests are
                      excluded: they are not a backlog and would distort every count on it. It reads the
                      filtered list, so the numbers always describe the cards underneath them. */}
                  <InspectionReviewAnalytics tickets={awaitingShown} />
                  <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    {awaitingShown.map((tk) => (
                      <RequestCard
                        key={tk.id}
                        tk={tk}
                        onApprove={(t) => setModal({ action: 'approve', ticket: t })}
                        onReject={(t) => setModal({ action: 'reject', ticket: t })}
                        onAcknowledge={onAcknowledge}
                        onRemind={(t) => setModal({ action: 'remind', ticket: t })}
                        onCancelReminder={onCancelReminder}
                        onRecordOilChange={(t) => setModal({ action: 'oil_change', ticket: t })}
                        ackBusy={ackBusyId === tk.id}
                        remindBusy={remindBusyId === tk.id}
                        highlight={highlightId === tk.id}
                      />
                    ))}
                  </div>
                </>
              )}
            </div>
          ) : (
            /* Cars physically in a shop — its own endpoint, driven by the shop stay rather than by
               which requests happened to be parked, so a car on a lift shows up here whether or not
               anyone had asked for a test on it. */
            <ParkedInShopPanel />
          )}
          </>
        )}
      </div>

      {modal?.action === 'approve' && (
        <ApproveModal ticket={modal.ticket} onClose={() => setModal(null)} onDone={onDone} />
      )}
      {modal?.action === 'reject' && (
        <RejectModal ticket={modal.ticket} onClose={() => setModal(null)} onDone={onDone} />
      )}
      {/* THE OIL WAS CHANGED. The same one-number dialog the Oil Follow-up board uses — reused, not
          re-implemented, so both surfaces write the car's service anchor exactly the same way. */}
      {modal?.action === 'oil_change' && (
        <OilChangeDialog
          row={{
            contract_id:         modal.ticket.oil_context?.contract_id,
            contract_no:         modal.ticket.oil_context?.contract_no,
            plate:               modal.ticket.plate,
            car:                 modal.ticket.car,
            service_interval_km: modal.ticket.oil_context?.live?.service_interval_km,
            projection:          { expected: modal.ticket.oil_context?.live?.expected },
          }}
          onClose={() => setModal(null)}
          onSaved={() => reload({ silent: true })}
        />
      )}
      {/* "Remind me later" — decides nothing; the request stays in the queue and only the caller is
          pinged. See RemindModal. */}
      {modal?.action === 'remind' && (
        <RemindModal ticket={modal.ticket} onClose={() => setModal(null)} onDone={onDone} />
      )}
      {/* Send a car in — the two-door front form. "Ask for a test" is born in pending_review, so it
          lands right back in this queue for a Controller to approve before it reaches Abu Maroof;
          "Straight to the garage" skips both and opens at Needs Dispatch. */}
      {modal?.action === 'request' && (
        <SendCarInModal vehicles={vehicles} onClose={() => setModal(null)} onDone={onDone} />
      )}
      {/* Complaint Intake — logs a customer complaint straight into Abu Maroof's triage lane. */}
      {modal?.action === 'complaint' && (
        <ComplaintIntakeModal vehicles={vehicles} onClose={() => setModal(null)} onDone={onDone} />
      )}
    </div>
  );
}
