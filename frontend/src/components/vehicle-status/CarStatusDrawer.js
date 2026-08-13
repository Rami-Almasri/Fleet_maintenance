import { useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import Drawer from '../ui/Drawer';
import Button from '../ui/Button';
import Badge from '../ui/Badge';
import Icon from '../ui/Icon';
import { Skeleton } from '../ui/Skeleton';
import { num, fmtDate } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

// The operational deep-dive behind a Car Status card. Opening a car here answers the follow-up questions a
// card can't fit: the full root cause, every fault's lifecycle, parts in-flight, the stage-by-stage
// maintenance timeline, and the captured photos/notes — without leaving the board. Reads the same
// /car-status/{id} intelligence the full profile page uses. Pure read; "Open full profile" deep-links out.

const humanize = (s) => (s || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

// Where the ticket came from. Translated at call time — pass the translator in.
const sourceLabel = (t, key) => ({
  inspection: t('From Inspection'),
  diagnostic: t('From Diagnostic'),
  scheduled: t('From Scheduled Maintenance'),
  breakdown: t('From Breakdown Report'),
  customer: t('From Customer Report'),
}[key]);

// Delay status → a spoken label (falls back to the humanised backend value).
const delayLabel = (t, s) => ({
  on_track: t('On track'),
  'on track': t('On track'),
  at_risk: t('At risk'),
  overdue: t('Overdue'),
}[s] || humanize(s));

// A pre-computed second count → a compact localisable duration ("2d 3h" / "45m").
const durationLabel = (t, seconds) => {
  if (seconds == null) return '—';
  const total = Math.max(0, Math.round(seconds));
  const d = Math.floor(total / 86400);
  const h = Math.floor((total % 86400) / 3600);
  const m = Math.floor((total % 3600) / 60);
  if (d) return t('{d}d {h}h', { d, h });
  if (h) return t('{h}h {m}m', { h, m });
  if (m) return t('{m}m', { m });
  return t('{s}s', { s: total });
};

// "just now" / "5m ago" / "2d ago", localisable.
const agoLabel = (t, value) => {
  if (!value) return null;
  const d = new Date(value);
  if (isNaN(d)) return null;
  const secs = Math.round((Date.now() - d.getTime()) / 1000);
  if (secs < 45) return t('just now');
  const mins = Math.floor(secs / 60);
  if (mins < 60) return t('{n}m ago', { n: mins });
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return t('{n}h ago', { n: hrs });
  const days = Math.floor(hrs / 24);
  if (days < 30) return t('{n}d ago', { n: days });
  const months = Math.floor(days / 30);
  if (months < 12) return t('{n}mo ago', { n: months });
  return t('{n}y ago', { n: Math.floor(months / 12) });
};

// reason tone → text/tint used by the root-cause banner.
const TONE = {
  red:    { text: 'text-red-700',     chip: 'bg-red-100 text-red-700',       band: 'from-rose-500 to-red-600' },
  orange: { text: 'text-orange-700',  chip: 'bg-orange-100 text-orange-700', band: 'from-amber-400 to-orange-500' },
  amber:  { text: 'text-amber-700',   chip: 'bg-amber-100 text-amber-700',   band: 'from-amber-400 to-yellow-500' },
  green:  { text: 'text-emerald-700', chip: 'bg-emerald-100 text-emerald-700', band: 'from-emerald-400 to-teal-500' },
  slate:  { text: 'text-slate-700',   chip: 'bg-slate-100 text-slate-600',   band: 'from-slate-400 to-slate-500' },
};

function Section({ title, icon: I, count, children, action }) {
  return (
    <section>
      <div className="mb-2 flex items-center justify-between">
        <h3 className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-slate-500">
          {I && <I className="h-4 w-4 text-slate-400" />}
          {title}
          {count != null && <span className="rounded-full bg-slate-100 px-1.5 text-[11px] font-bold text-slate-500">{count}</span>}
        </h3>
        {action}
      </div>
      {children}
    </section>
  );
}

// ── Live workflow rail (horizontal) ──────────────────────────────────────────────────────────────
function WorkflowRail({ steps = [], blocked }) {
  return (
    <div className="flex items-start gap-0">
      {steps.map((s, i) => {
        const done = s.state === 'completed';
        const cur = s.state === 'current';
        const blk = s.state === 'blocked';
        const dot = done ? 'bg-emerald-500 text-white' : cur ? 'bg-blue-600 text-white ring-4 ring-blue-100'
          : blk ? 'bg-red-500 text-white ring-4 ring-red-100 animate-pulse' : 'bg-slate-200 text-slate-400';
        return (
          <div key={i} className="flex min-w-0 flex-1 flex-col items-center text-center">
            <div className="flex w-full items-center">
              <span className={`h-0.5 flex-1 ${i === 0 ? 'opacity-0' : done || cur || blk ? 'bg-emerald-400' : 'bg-slate-200'}`} />
              <span className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[10px] font-bold ${dot}`}>
                {done ? '✓' : i + 1}
              </span>
              <span className={`h-0.5 flex-1 ${i === steps.length - 1 ? 'opacity-0' : done ? 'bg-emerald-400' : 'bg-slate-200'}`} />
            </div>
            <span className={`mt-1 truncate text-[10px] leading-tight ${blk ? 'font-bold text-red-600' : cur ? 'font-bold text-blue-700' : done ? 'text-slate-500' : 'text-slate-400'}`}>
              {s.label}
            </span>
          </div>
        );
      })}
    </div>
  );
}

// ── Fault card ───────────────────────────────────────────────────────────────────────────────────
function FaultCard({ f }) {
  const { t } = useI18n();
  return (
    <div className="rounded-xl bg-white p-3 ring-1 ring-slate-200">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="truncate text-sm font-semibold text-slate-800">{f.title}</p>
          <p className="text-[11px] text-slate-400">{f.category || '—'}{f.garage ? ` · ${f.garage}` : ''}</p>
        </div>
        <div className="flex shrink-0 items-center gap-1">
          {f.recurrence && <Badge tone="red">{t('Recurring')}</Badge>}
          <Badge tone={f.severity_tone}>{f.severity_label}</Badge>
          <Badge tone={f.status_tone}>{f.status_label}</Badge>
        </div>
      </div>
      {f.root_cause && (
        <p className="mt-2 rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800 ring-1 ring-inset ring-amber-500/20">
          <span className="font-semibold">{t('Root cause:')}</span> {f.root_cause}
        </p>
      )}
      {f.resolution_note && (
        <p className="mt-1.5 rounded-lg bg-emerald-50 px-2.5 py-1.5 text-xs text-emerald-800 ring-1 ring-inset ring-emerald-500/20">
          <span className="font-semibold">{t('Resolution:')}</span> {f.resolution_note}
        </p>
      )}
      {f.notes && <p className="mt-1.5 text-xs italic text-slate-500">“{f.notes}”</p>}
      <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-400">
        {f.mechanic && <span className="inline-flex items-center gap-1"><Icon.Users className="h-3 w-3" /> {f.mechanic}</span>}
        {f.opened_at && <span>{t('Opened {date}', { date: fmtDate(f.opened_at) })}</span>}
        {f.duration_hours != null && <span>{t('{h}h to fix', { h: f.duration_hours })}</span>}
      </div>
    </div>
  );
}

export default function CarStatusDrawer({ open, seed, onClose }) {
  const navigate = useNavigate();
  const { t } = useI18n();
  const vehicleId = seed?.vehicle_id;

  const fetcher = useCallback(async () => {
    if (!vehicleId) return null;
    return (await api.get(`/car-status/vehicle/${vehicleId}`)).data.data;
  }, [vehicleId]);
  const { data, loading } = useFetch(fetcher, [vehicleId]);

  const reason = seed?.reason || {};
  const tone = TONE[reason.tone] || TONE.slate;
  const lw = data?.live_workflow;
  const faults = (data?.faults || []).filter((f) => f.is_open);
  const closedFaults = (data?.faults || []).filter((f) => !f.is_open).slice(0, 6);
  const parts = data?.parts || {};
  const journey = (data?.workflow_journey || [])[0]; // most recent ticket's stage timeline
  const docs = data?.documents || {};
  const media = (docs.media || []).slice(0, 8);

  const overdue = seed?.delay_status === 'overdue';

  return (
    <Drawer
      open={open}
      onClose={onClose}
      eyebrow={t('Maintenance operations')}
      title={seed ? `${seed.car || t('Vehicle')} · ${seed.plate_no || ''}` : t('Loading…')}
      subtitle={seed ? t('Ticket {no} · {stage}', { no: seed.ticket_no, stage: seed.stage }) : ''}
      width="lg"
      footer={
        seed && (
          <div className="flex flex-wrap items-center gap-2">
            <Button variant="primary" size="sm" onClick={() => { navigate(`/car-status/${vehicleId}`); onClose?.(); }}>
              {t('Open full profile')}
            </Button>
            <Button variant="secondary" size="sm" onClick={() => { navigate('/maintenance-workflow'); onClose?.(); }}>
              {t('Open Maintenance')}
            </Button>
          </div>
        )
      }
    >
      {!seed ? null : (
        <div className="space-y-6">
          {/* Root cause banner — the WHY, front and centre */}
          <section className="overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200">
            <div className={`h-1.5 w-full bg-gradient-to-r ${tone.band}`} />
            <div className="p-4">
              <div className="flex items-center gap-2">
                <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${tone.chip}`}>
                  {sourceLabel(t, reason.source_key) || reason.source || t('Maintenance')}
                </span>
                {reason.category && <span className="text-[11px] text-slate-400">{reason.category}</span>}
              </div>
              <p className={`mt-1.5 font-display text-xl font-bold leading-tight ${tone.text}`}>
                {reason.emoji ? `${reason.emoji} ` : ''}{reason.label || t('In maintenance')}
              </p>
              {reason.other_faults > 0 && (
                <p className="mt-1 text-xs text-slate-400">
                  {reason.other_faults === 1
                    ? t('+1 more open issue on this ticket')
                    : t('+{n} more open issues on this ticket', { n: reason.other_faults })}
                </p>
              )}
            </div>
          </section>

          {/* ETA & delay */}
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <Stat label={t('Started')} value={seed.started_at ? fmtDate(seed.started_at) : '—'} sub={agoLabel(t, seed.started_at)} />
            <Stat label={t('Expected')} value={seed.expected_completion ? fmtDate(seed.expected_completion) : t('Not set')} />
            <Stat label={t('Days in shop')} value={seed.days_in_maintenance != null ? t('{n}d', { n: num(seed.days_in_maintenance) }) : '—'} />
            <Stat
              label={overdue ? t('Overdue') : t('Status')}
              value={overdue ? t('{n}d', { n: num(seed.days_overdue) }) : delayLabel(t, seed.delay_status || 'on track')}
              tone={overdue ? 'text-red-600' : seed.delay_status === 'at_risk' ? 'text-amber-600' : 'text-emerald-600'}
            />
          </div>

          {(seed.waiting_reason || (seed.missing_parts || []).length > 0) && (
            <div className="rounded-xl bg-slate-50 px-3 py-2 text-sm text-slate-600 ring-1 ring-inset ring-slate-200">
              <span className="font-medium text-slate-500">{t('Waiting on:')}</span> {seed.waiting_reason || t('Parts')}
              {(seed.missing_parts || []).length > 0 && (
                <span className="text-slate-400"> — {seed.missing_parts.join(', ')}</span>
              )}
            </div>
          )}

          {loading && !data ? (
            <div className="space-y-3">
              <Skeleton className="h-16 w-full" />
              <Skeleton className="h-28 w-full" />
            </div>
          ) : (
            <>
              {/* Live workflow */}
              {lw && (
                <Section title={t('Workflow stage')} icon={Icon.Route}>
                  <div className="rounded-xl bg-white p-4 ring-1 ring-slate-200">
                    <WorkflowRail steps={lw.steps} blocked={lw.blocked} />
                    {lw.stage_detail && <p className="mt-3 text-center text-xs text-slate-500">{lw.stage_detail}</p>}
                    <div className="mt-3 flex items-center justify-center gap-4 border-t border-slate-100 pt-2.5 text-[11px] text-slate-500">
                      {seed.responsible && <span><span className="text-slate-400">{t('Owner')}</span> <span className="font-semibold text-slate-700">{seed.responsible}</span></span>}
                      {seed.garage && <span><span className="text-slate-400">{t('Workshop')}</span> <span className="font-semibold text-slate-700">{seed.garage}</span></span>}
                    </div>
                  </div>
                </Section>
              )}

              {/* Open faults */}
              <Section title={t('Open faults')} icon={Icon.Wrench} count={faults.length}>
                {faults.length === 0 ? (
                  <p className="text-sm text-slate-400">{t('No open faults recorded on this ticket.')}</p>
                ) : (
                  <div className="space-y-2">{faults.map((f) => <FaultCard key={f.id} f={f} />)}</div>
                )}
              </Section>

              {/* Parts */}
              <Section title={t('Parts')} icon={Icon.Coins}>
                <div className="grid grid-cols-3 gap-2">
                  <Stat label={t('Installed')} value={num(parts.installed || 0)} tone="text-emerald-600" />
                  <Stat label={t('Pending')} value={num(parts.pending || 0)} tone="text-amber-600" />
                  <Stat label={t('Rejected')} value={num(parts.rejected || 0)} tone="text-slate-500" />
                </div>
                {(parts.suppliers || []).length > 0 && (
                  <div className="mt-2 space-y-1">
                    {parts.suppliers.slice(0, 4).map((s, i) => (
                      <div key={i} className="flex items-center justify-between rounded-lg bg-white px-3 py-1.5 text-xs ring-1 ring-slate-200">
                        <span className="truncate font-medium text-slate-600">{s.supplier}</span>
                        <span className="text-slate-400">
                          {s.purchases === 1 ? t('1 purchase') : t('{n} purchases', { n: s.purchases })}
                          {s.avg_delivery_days != null ? ` · ${t('{n}d avg', { n: s.avg_delivery_days })}` : ''}
                        </span>
                      </div>
                    ))}
                  </div>
                )}
              </Section>

              {/* Maintenance timeline */}
              {journey && (
                <Section title={t('Maintenance timeline')} icon={Icon.Clock} count={journey.stage_count}>
                  <ol className="relative space-y-3 border-s border-slate-200 ps-4">
                    {journey.stages.map((st, i) => (
                      <li key={i} className="relative">
                        <span className="absolute -start-[21px] top-1 h-2.5 w-2.5 rounded-full bg-blue-400 ring-2 ring-white" />
                        <div className="flex items-baseline justify-between gap-2">
                          <p className="text-sm font-medium text-slate-700">{humanize(st.workflow_status)}</p>
                          <span className="shrink-0 text-[11px] text-slate-400">{st.seconds != null ? durationLabel(t, st.seconds) : t('now')}</span>
                        </div>
                        <p className="text-[11px] text-slate-400">
                          {fmtDate(st.entered_at)}{st.actor ? ` · ${st.actor}` : ''}{st.garage ? ` · ${st.garage}` : ''}
                        </p>
                        {st.description && <p className="mt-0.5 text-xs text-slate-500">{st.description}</p>}
                      </li>
                    ))}
                  </ol>
                </Section>
              )}

              {/* Photos & attachments */}
              {media.length > 0 && (
                <Section title={t('Photos & attachments')} icon={Icon.Camera} count={media.length}>
                  <div className="grid grid-cols-4 gap-2">
                    {media.map((m, i) => (
                      <a key={i} href={m.url} target="_blank" rel="noreferrer"
                        className="group relative flex aspect-square items-center justify-center overflow-hidden rounded-lg bg-slate-100 ring-1 ring-slate-200 hover:ring-indigo-300">
                        {m.kind === 'video' ? <Icon.Video className="h-6 w-6 text-slate-400" /> : m.url
                          ? <img src={m.url} alt="" className="h-full w-full object-cover" />
                          : <Icon.Camera className="h-6 w-6 text-slate-400" />}
                      </a>
                    ))}
                  </div>
                </Section>
              )}

              {/* Resolved faults (compact) */}
              {closedFaults.length > 0 && (
                <Section title={t('Resolved history')} icon={Icon.Check} count={closedFaults.length}>
                  <ul className="divide-y divide-slate-100 rounded-xl bg-white ring-1 ring-slate-200">
                    {closedFaults.map((f) => (
                      <li key={f.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                        <span className="min-w-0 truncate text-slate-600">{f.title}</span>
                        <span className="shrink-0 text-[11px] text-slate-400">{f.resolved_at ? fmtDate(f.resolved_at) : '—'}</span>
                      </li>
                    ))}
                  </ul>
                </Section>
              )}
            </>
          )}
        </div>
      )}
    </Drawer>
  );
}

function Stat({ label, value, sub, tone = 'text-slate-900' }) {
  return (
    <div className="rounded-xl bg-white p-2.5 ring-1 ring-slate-200">
      <div className="text-[10px] font-medium uppercase tracking-wide text-slate-400">{label}</div>
      <div className={`mt-0.5 text-sm font-bold tabular-nums ${tone}`}>{value}</div>
      {sub && <div className="text-[10px] text-slate-400">{sub}</div>}
    </div>
  );
}
