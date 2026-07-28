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

const RESULT_TONE = { completed: 'emerald', partial: 'amber', failed: 'red' };

/** A labelled stat block used across the health + timeline sections. */
function Stat({ label, value, tone = 'text-slate-900', sub }) {
  return (
    <div className="rounded-lg bg-white p-3 ring-1 ring-slate-200">
      <div className="text-[11px] font-medium uppercase tracking-wide text-slate-400">{label}</div>
      <div className={`mt-0.5 text-lg font-semibold tabular-nums ${tone}`}>{value}</div>
      {sub && <div className="text-[11px] text-slate-400">{sub}</div>}
    </div>
  );
}

/**
 * Vehicle Maintenance Context — the intelligence drawer behind a row on the Maintenance Operations
 * Center. Opening a vehicle here answers "what's the health, what's already happening, and what should
 * happen next" without leaving the board. Reads /intelligence/vehicle/{id}/maintenance-detail.
 */
export default function MaintenanceContextDrawer({ vehicleId, onClose, onSnooze }) {
  const navigate = useNavigate();
  const fetcher = useCallback(async () => {
    if (!vehicleId) return null;
    const { data } = await api.get(`/intelligence/vehicle/${vehicleId}/maintenance-detail`);
    return data.data;
  }, [vehicleId]);
  const { data, loading } = useFetch(fetcher);

  const v = data?.vehicle;
  const h = data?.health;
  const active = data?.active_maintenance;
  const dq = data?.data_quality;
  const action = data?.recommended_action;

  const goAct = () => {
    if (!v) return;
    if (action?.key === 'verify_odometer') navigate('/mileage-chain-audit');
    else navigate(`/car-status/${v.id}`);
    onClose?.();
  };

  return (
    <Drawer
      open={!!vehicleId}
      onClose={onClose}
      eyebrow="Maintenance intelligence"
      title={v ? v.plate || `#${v.id}` : 'Loading…'}
      subtitle={v?.car || (loading ? '' : '—')}
      width="half"
      footer={
        data && (
          <div className="flex flex-wrap items-center gap-2">
            {action && !action.informational && (
              <Button variant="primary" size="sm" onClick={goAct}>
                {action.label}
              </Button>
            )}
            <Button variant="secondary" size="sm" onClick={() => { navigate(`/car-status/${v.id}`); onClose?.(); }}>
              Open vehicle
            </Button>
            <Button variant="ghost" size="sm" onClick={() => onSnooze?.(v)}>
              Snooze
            </Button>
          </div>
        )
      }
    >
      {loading || !data ? (
        <div className="space-y-3">
          <Skeleton className="h-24 w-full" />
          <Skeleton className="h-32 w-full" />
        </div>
      ) : (
        <div className="space-y-6">
          {dq?.suspect && (
            <div className="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 ring-1 ring-inset ring-amber-500/25">
              <Icon.Alert className="mt-0.5 h-4 w-4 shrink-0" />
              <div><span className="font-semibold">Data quality warning.</span> {dq.reason}</div>
            </div>
          )}

          {/* Vehicle Health */}
          <section>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Vehicle health</h3>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
              <Stat label="Current KM" value={h.current_km != null ? num(h.current_km) : '—'} />
              <Stat label="Interval" value={h.interval != null ? `${num(h.interval)}` : '—'} sub="km" />
              <Stat
                label={h.service_status === 'overdue' ? 'Overdue' : 'Remaining'}
                value={h.service_status === 'overdue' ? `${num(h.overdue_km)}` : (h.remaining_km != null ? `${num(h.remaining_km)}` : '—')}
                tone={h.service_status === 'overdue' ? 'text-red-600' : 'text-amber-600'}
                sub="km"
              />
              <Stat label="Usage" value={h.usage_rate != null ? `${num(h.usage_rate)}` : '—'} sub="km/day" />
            </div>
            <div className="mt-2 text-xs text-slate-500">
              Last service: <span className="font-medium text-slate-700">{h.last_service_at ? fmtDate(h.last_service_at) : 'Unknown'}</span>
            </div>
          </section>

          {/* Active Maintenance */}
          {active ? (
            <section className="rounded-lg bg-white p-4 ring-1 ring-slate-200">
              <h3 className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-slate-500">
                <Icon.Wrench className="h-4 w-4" /> Active maintenance
              </h3>
              <div className="flex items-center gap-2">
                <Badge tone="blue">{active.stage_label}</Badge>
                {active.expected_completion_date && (
                  <span className="text-xs text-slate-500">ETA {fmtDate(active.expected_completion_date)}</span>
                )}
              </div>
              {active.stage_detail && <p className="mt-1.5 text-sm text-slate-600">{active.stage_detail}</p>}
              <dl className="mt-3 grid grid-cols-2 gap-2 text-sm">
                {active.responsible && (
                  <div><dt className="text-xs text-slate-400">Responsible</dt><dd className="font-medium text-slate-700">{active.responsible}</dd></div>
                )}
                {active.latest_checkpoint && (
                  <div>
                    <dt className="text-xs text-slate-400">Last update</dt>
                    <dd className="flex items-center gap-1.5">
                      <Badge tone="slate">
                        {active.latest_checkpoint.next_expected_date
                          ? `ETA ${fmtDate(active.latest_checkpoint.next_expected_date)}`
                          : (active.latest_checkpoint.status || 'Update filed')}
                      </Badge>
                    </dd>
                  </div>
                )}
              </dl>
              {active.latest_checkpoint?.summary && (
                <p className="mt-2 rounded bg-slate-50 px-2.5 py-1.5 text-xs text-slate-600">“{active.latest_checkpoint.summary}”</p>
              )}
            </section>
          ) : (
            <section className="rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-500 ring-1 ring-inset ring-slate-200">
              No active maintenance ticket.
            </section>
          )}

          {/* Maintenance Timeline */}
          <section>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Maintenance timeline</h3>
            <div className="flex items-stretch gap-1 text-center text-[11px]">
              <TimelineNode label="Last service" value={data.timeline.last_service_at ? fmtDate(data.timeline.last_service_at) : 'Unknown'} sub={data.timeline.last_service_km != null ? `${num(data.timeline.last_service_km)} km` : null} tone="slate" />
              <TimelineArrow />
              <TimelineNode label="Now" value={data.timeline.current_km != null ? `${num(data.timeline.current_km)} km` : '—'} tone="indigo" />
              <TimelineArrow />
              <TimelineNode label="Projected due" value={data.timeline.projected_due ? fmtDate(data.timeline.projected_due) : 'Unknown'} tone={h.service_status === 'overdue' ? 'red' : 'amber'} />
            </div>
          </section>

          {/* Recent Maintenance */}
          <section>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Recent maintenance</h3>
            {data.recent_maintenance.length === 0 ? (
              <p className="text-sm text-slate-400">No service records on file.</p>
            ) : (
              <ul className="divide-y divide-slate-100 rounded-lg bg-white ring-1 ring-slate-200">
                {data.recent_maintenance.map((r, i) => (
                  <li key={i} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                    <div className="min-w-0">
                      <div className="truncate font-medium text-slate-700">{r.service_type || r.description || 'Service'}</div>
                      <div className="text-xs text-slate-400">{r.performed_at ? fmtDate(r.performed_at) : '—'}{r.odometer != null ? ` · ${num(r.odometer)} km` : ''}</div>
                    </div>
                    {r.result && <Badge tone={RESULT_TONE[r.result] || 'slate'}>{r.result}</Badge>}
                  </li>
                ))}
              </ul>
            )}
          </section>

          {/* Prediction / recommended action */}
          {action && (
            <section className="rounded-lg bg-indigo-50 px-4 py-3 ring-1 ring-inset ring-indigo-600/15">
              <h3 className="mb-1 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-indigo-500">
                <Icon.Spark className="h-4 w-4" /> Recommended next action
              </h3>
              <p className="text-sm font-semibold text-indigo-900">{action.label}</p>
            </section>
          )}
        </div>
      )}
    </Drawer>
  );
}

function TimelineNode({ label, value, sub, tone }) {
  const ring = { slate: 'ring-slate-200', indigo: 'ring-indigo-300', amber: 'ring-amber-300', red: 'ring-red-300' }[tone] || 'ring-slate-200';
  const dot = { slate: 'bg-slate-300', indigo: 'bg-indigo-500', amber: 'bg-amber-500', red: 'bg-red-500' }[tone] || 'bg-slate-300';
  return (
    <div className={`flex-1 rounded-lg bg-white p-2 ring-1 ${ring}`}>
      <div className={`mx-auto mb-1 h-1.5 w-1.5 rounded-full ${dot}`} />
      <div className="font-medium text-slate-700">{value}</div>
      {sub && <div className="text-slate-400">{sub}</div>}
      <div className="mt-0.5 text-[10px] uppercase tracking-wide text-slate-400">{label}</div>
    </div>
  );
}

function TimelineArrow() {
  return <div className="flex items-center px-0.5 text-slate-300">→</div>;
}
