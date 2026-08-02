import { useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import Drawer from '../ui/Drawer';
import Button from '../ui/Button';
import Badge from '../ui/Badge';
import Icon from '../ui/Icon';
import { Skeleton } from '../ui/Skeleton';
import { num, fmtDate, fmtAgo } from '../../lib/format';
import { usePermissions } from '../../hooks/usePermissions';

/** A labelled stat block, mirroring the Maintenance Context drawer. */
function Stat({ label, value, tone = 'text-slate-900', sub }) {
  return (
    <div className="rounded-lg bg-white p-3 ring-1 ring-slate-200">
      <div className="text-[11px] font-medium uppercase tracking-wide text-slate-400">{label}</div>
      <div className={`mt-0.5 text-base font-semibold tabular-nums ${tone}`}>{value ?? '—'}</div>
      {sub && <div className="text-[11px] text-slate-400">{sub}</div>}
    </div>
  );
}

/**
 * Maintenance Operations detail — the timeline drawer behind an operations card. The card already shows
 * the live snapshot; this fills in the HISTORY: every progress checkpoint (with each ETA change + reason)
 * and the stage-by-stage workflow audit trail. Reads /maintenance-operations/vehicle/{id}.
 */
export default function MaintenanceOperationsDrawer({ vehicleId, onClose }) {
  const navigate = useNavigate();
  const { can } = usePermissions();
  const fetcher = useCallback(async () => {
    if (!vehicleId) return null;
    const { data } = await api.get(`/maintenance-operations/vehicle/${vehicleId}`);
    return data.data;
  }, [vehicleId]);
  const { data, loading } = useFetch(fetcher);

  const card = data?.card;
  const v = card?.vehicle;
  const m = card?.maintenance;
  const cps = data?.checkpoints || [];
  const events = data?.timeline || [];

  return (
    <Drawer
      open={!!vehicleId}
      onClose={onClose}
      eyebrow="Maintenance operations"
      title={v ? v.plate_no || `#${data?.vehicle_id}` : 'Loading…'}
      subtitle={v ? [v.car, v.year].filter(Boolean).join(' · ') : (loading ? '' : '—')}
      width="half"
      footer={
        data && card && (
          <div className="flex flex-wrap items-center gap-2">
            <Button variant="primary" size="sm" onClick={() => { navigate(`/car-status/${data.vehicle_id}`); onClose?.(); }}>
              Open vehicle profile
            </Button>
            {card.ticket_id && (
              <Button variant="secondary" size="sm" onClick={() => { navigate(`/maintenance-workflow/${card.ticket_id}`); onClose?.(); }}>
                Maintenance details
              </Button>
            )}
            {can('maintenance.checkpoint.create') && (
              <Button variant="ghost" size="sm" onClick={() => { navigate('/maintenance-progress'); onClose?.(); }}>
                Add update
              </Button>
            )}
          </div>
        )
      }
    >
      {loading || !data ? (
        <div className="space-y-3">
          <Skeleton className="h-24 w-full" />
          <Skeleton className="h-32 w-full" />
        </div>
      ) : !card ? (
        <div className="rounded-lg bg-slate-50 px-4 py-6 text-center text-sm text-slate-500 ring-1 ring-inset ring-slate-200">
          This vehicle has no active maintenance ticket.
        </div>
      ) : (
        <div className="space-y-6">
          {/* Snapshot — where is it, what's wrong, when will it be ready */}
          <section>
            {card.location && <DrawerLocation loc={card.location} />}
            <div className="mt-3 flex flex-wrap items-center gap-2">
              <Badge tone={m.stage_tone || 'slate'} dot>{m.stage_label}</Badge>
              <Badge tone="slate">{m.type}</Badge>
              {m.severity_label && <Badge tone={m.severity_tone || 'slate'}>{m.severity_emoji} {m.severity_label}</Badge>}
              {m.is_overdue && <Badge tone="red">Overdue {m.days_overdue}d</Badge>}
            </div>
            {m.reason && (
              <p className="mt-2 text-sm"><span className="text-slate-400">Current issue:</span> <span className="font-medium text-slate-700">{m.reason}</span></p>
            )}
            <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
              <Stat label="Days in shop" value={m.days_in_workshop != null ? num(m.days_in_workshop) : '—'} sub="days" />
              <Stat label="ETA" value={m.eta ? fmtDate(m.eta) : '—'} tone={m.is_overdue ? 'text-red-600' : 'text-slate-900'} sub={m.eta_estimated ? 'estimated' : null} />
              <Stat label="Open faults" value={`${num(m.active_faults)} / ${num(m.fault_total)}`} />
              <Stat label="Workshop" value={m.workshop || '—'} sub={m.workshop_kind === 'on_site' ? 'on-site' : 'in-shop'} />
            </div>

            {/* ETA history — original vs current promise, and how many times it slipped */}
            <div className="mt-3 grid grid-cols-3 gap-2 rounded-lg bg-white p-3 text-center ring-1 ring-slate-200">
              <div>
                <div className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Original ETA</div>
                <div className="text-sm font-semibold text-slate-800">{m.eta_original ? fmtDate(m.eta_original) : '—'}</div>
              </div>
              <div>
                <div className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Current ETA</div>
                <div className={`text-sm font-semibold ${m.is_overdue ? 'text-red-600' : 'text-slate-800'}`}>{m.eta ? fmtDate(m.eta) : '—'}</div>
              </div>
              <div>
                <div className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">ETA changed</div>
                <div className={`text-sm font-semibold ${m.eta_changes > 0 ? 'text-amber-600' : 'text-slate-800'}`}>{m.eta_changes}×</div>
              </div>
            </div>
          </section>

          {/* Operational status & responsibility */}
          {card.indicators?.length > 0 || card.operational ? (
            <section>
              <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Status &amp; responsibility</h3>
              {card.indicators?.length > 0 && (
                <div className="mb-2 flex flex-wrap gap-1.5">
                  {card.indicators.map((k) => (
                    <span key={k} className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${INDICATOR_CLS[k] || 'bg-slate-100 text-slate-600'}`}>
                      {INDICATOR_LABEL[k] || k}
                    </span>
                  ))}
                </div>
              )}
              <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <Field label="Responsible" value={card.operational?.fleet_manager} />
                <Field label="Inspector" value={card.operational?.inspector} />
                <Field label="Driver" value={card.operational?.driver} />
                <Field label="Workshop contact" value={card.operational?.workshop_contact} />
              </dl>
              {card.operational?.missing_parts?.length > 0 && (
                <div className="mt-2 text-sm">
                  <span className="text-[11px] uppercase tracking-wide text-slate-400">Waiting on parts</span>
                  <div className="mt-0.5 flex flex-wrap gap-1.5">
                    {card.operational.missing_parts.map((p, i) => (
                      <span key={i} className="rounded-md bg-violet-50 px-1.5 py-0.5 text-xs font-medium text-violet-700 ring-1 ring-inset ring-violet-100">{p}</span>
                    ))}
                  </div>
                </div>
              )}
            </section>
          ) : null}

          {/* Affected rental */}
          <section>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Affected rental &amp; assignment</h3>
            <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
              <Field label="Branch" value={card.affected.branch} />
              <Field label="Current customer" value={card.affected.customer || (card.affected.on_rent ? '—' : 'Not on rent')} />
              <Field label="Contract" value={card.affected.contract_no} />
              <Field label="Contract start" value={card.affected.contract_start ? fmtDate(card.affected.contract_start) : '—'} />
              <Field label="Contract end" value={card.affected.contract_end ? fmtDate(card.affected.contract_end) : '—'} />
            </dl>
          </section>

          {/* Faults */}
          <section>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Faults ({card.faults.length})</h3>
            {card.faults.length === 0 ? (
              <p className="text-sm text-slate-400">No faults recorded.</p>
            ) : (
              <ul className="space-y-2">
                {card.faults.map((f) => (
                  <li key={f.id} className={`rounded-lg bg-white p-3 ring-1 ${f.open ? 'ring-slate-200' : 'ring-slate-100 opacity-70'}`}>
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0">
                        <div className="flex items-center gap-1.5 font-medium text-slate-800">
                          {f.severity_emoji} <span className="truncate">{f.title}</span>
                        </div>
                        <div className="mt-0.5 text-xs text-slate-400">
                          {f.category || 'Uncategorised'} · {f.reported_by}{f.reported_at ? ` · ${fmtDate(f.reported_at)}` : ''}
                        </div>
                      </div>
                      <div className="flex shrink-0 flex-col items-end gap-1">
                        <Badge tone={f.open ? (f.severity_tone || 'slate') : 'gray'}>{f.open ? f.status : 'closed'}</Badge>
                        {f.blocking && <span className="text-[10px] font-semibold uppercase text-red-600">Blocks release</span>}
                      </div>
                    </div>
                    {(f.diagnostic || f.resolution) && (
                      <p className="mt-1.5 rounded bg-slate-50 px-2 py-1 text-xs text-slate-600">
                        {f.resolution || f.diagnostic}
                      </p>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </section>

          {/* Progress checkpoints */}
          <section>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Progress updates ({cps.length})</h3>
            {cps.length === 0 ? (
              <p className="text-sm text-slate-400">No progress updates filed yet.</p>
            ) : (
              <ol className="relative space-y-3 border-s border-slate-200 ps-4">
                {cps.map((c) => (
                  <li key={c.id} className="relative">
                    <span className="absolute -start-[21px] top-1 h-2.5 w-2.5 rounded-full bg-indigo-400 ring-2 ring-white" />
                    <div className="flex items-center justify-between gap-2">
                      <span className="text-sm font-medium text-slate-700">{c.status ? c.status.replace(/_/g, ' ') : 'Update'}</span>
                      <span className="text-[11px] text-slate-400">{c.created_at ? fmtAgo(c.created_at) : ''}</span>
                    </div>
                    {c.summary && <p className="mt-0.5 text-sm text-slate-600">{c.summary}</p>}
                    {c.eta_changed && (
                      <p className="mt-1 text-xs text-amber-700">
                        ETA moved {c.previous_eta ? fmtDate(c.previous_eta) : '—'} → <span className="font-semibold">{c.next_eta ? fmtDate(c.next_eta) : '—'}</span>
                        {c.delay_reason ? ` · ${c.delay_reason}` : ''}
                      </p>
                    )}
                    <div className="mt-0.5 text-[11px] text-slate-400">{c.submitted_by || 'Unknown'}</div>
                    {c.media?.length > 0 && (
                      <div className="mt-1.5 flex flex-wrap gap-1.5">
                        {c.media.map((md) => md.kind === 'image' ? (
                          <img key={md.id} src={md.url} alt="" className="h-14 w-14 rounded object-cover ring-1 ring-slate-200" />
                        ) : (
                          <a key={md.id} href={md.url} target="_blank" rel="noreferrer" className="inline-flex h-14 w-14 items-center justify-center rounded bg-slate-100 ring-1 ring-slate-200">
                            <Icon.Video className="h-5 w-5 text-slate-500" />
                          </a>
                        ))}
                      </div>
                    )}
                  </li>
                ))}
              </ol>
            )}
          </section>

          {/* Workflow trail */}
          <section>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Workflow trail</h3>
            {events.length === 0 ? (
              <p className="text-sm text-slate-400">No workflow events recorded.</p>
            ) : (
              <ul className="divide-y divide-slate-100 rounded-lg bg-white ring-1 ring-slate-200">
                {events.map((e) => (
                  <li key={e.id} className="flex items-start justify-between gap-3 px-3 py-2 text-sm">
                    <div className="min-w-0">
                      <div className="truncate text-slate-700">{e.description || (e.event_type || '').replace(/_/g, ' ')}</div>
                      {e.actor && <div className="text-[11px] text-slate-400">{e.actor}</div>}
                    </div>
                    <span className="shrink-0 text-[11px] text-slate-400">{e.occurred_at ? fmtDate(e.occurred_at) : ''}</span>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>
      )}
    </Drawer>
  );
}

const INDICATOR_LABEL = {
  overdue: 'Overdue', critical_faults: 'Critical fault', long_running: 'Long-running',
  waiting_parts: 'Waiting parts', no_recent_update: 'No recent update',
};
const INDICATOR_CLS = {
  overdue: 'bg-red-100 text-red-700', critical_faults: 'bg-red-100 text-red-700',
  long_running: 'bg-orange-100 text-orange-700', waiting_parts: 'bg-violet-100 text-violet-700',
  no_recent_update: 'bg-amber-100 text-amber-700',
};

// Prominent "where is it / who has it" block — matches the holder treatment on the board cards.
const HOLDER = {
  workshop:  { Icon: Icon.Wrench, badge: 'In Workshop',      strip: 'bg-red-50 ring-red-100',        fg: 'text-red-700',     chip: 'bg-red-600 text-white' },
  driver:    { Icon: Icon.Truck,  badge: 'With Driver',      strip: 'bg-blue-50 ring-blue-100',      fg: 'text-blue-700',    chip: 'bg-blue-600 text-white' },
  ready:     { Icon: Icon.Check,  badge: 'Ready',            strip: 'bg-emerald-50 ring-emerald-100', fg: 'text-emerald-700', chip: 'bg-emerald-600 text-white' },
  customer:  { Icon: Icon.Users,  badge: 'With Customer',    strip: 'bg-violet-50 ring-violet-100',  fg: 'text-violet-700',  chip: 'bg-violet-600 text-white' },
  inspector: { Icon: Icon.Shield, badge: 'Under Inspection', strip: 'bg-amber-50 ring-amber-100',    fg: 'text-amber-700',   chip: 'bg-amber-500 text-white' },
  branch:    { Icon: Icon.Car,    badge: 'At Branch',        strip: 'bg-slate-100 ring-slate-200',   fg: 'text-slate-700',   chip: 'bg-slate-600 text-white' },
  system:    { Icon: Icon.Info,   badge: 'In Workflow',      strip: 'bg-slate-100 ring-slate-200',   fg: 'text-slate-600',   chip: 'bg-slate-500 text-white' },
};

function DrawerLocation({ loc }) {
  const h = HOLDER[loc.holder_type] || HOLDER.system;
  const HIcon = h.Icon;
  return (
    <div className={`flex items-center gap-3 rounded-xl px-3 py-3 ring-1 ring-inset ${h.strip}`}>
      <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white/80 ring-1 ring-inset ring-black/5 ${h.fg}`}>
        <HIcon className="h-5 w-5" />
      </span>
      <div className="min-w-0 flex-1">
        <div className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Current holder · location</div>
        <div className={`truncate text-base font-bold leading-tight ${h.fg}`}>{loc.label}</div>
        {loc.detail && <div className="truncate text-xs text-slate-500">{loc.detail}</div>}
      </div>
      <span className={`shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ${h.chip}`}>{h.badge}</span>
    </div>
  );
}

function Field({ label, value }) {
  return (
    <div>
      <dt className="text-[11px] uppercase tracking-wide text-slate-400">{label}</dt>
      <dd className="font-medium text-slate-700">{value || '—'}</dd>
    </div>
  );
}
