import { useCallback, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Button from '../components/ui/Button';
import { PageHeader, SearchInput } from '../components/ui/Misc';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import DataTable, { SectionCard } from '../components/ui/Table';
import { MetricGridSkeleton } from '../components/ui/Skeleton';
import Icon from '../components/ui/Icon';
import { useToast } from '../components/ui/Toast';
import { Tooltip } from '../components/ui/Tooltip';
import MaintenanceContextDrawer from '../components/maintenance/MaintenanceContextDrawer';
import { num, fmtDate } from '../lib/format';

// 🔴🟠🟡🟢 — the actionable status that replaces the old "overdue / due soon" badge.
const RISK = {
  critical:  { label: 'Critical',  emoji: '🔴', dot: 'bg-red-500',     text: 'text-red-700',     ring: 'ring-red-600/20',    bg: 'bg-red-50' },
  attention: { label: 'Attention', emoji: '🟠', dot: 'bg-orange-500',  text: 'text-orange-700',  ring: 'ring-orange-600/20', bg: 'bg-orange-50' },
  upcoming:  { label: 'Upcoming',  emoji: '🟡', dot: 'bg-amber-400',   text: 'text-amber-700',   ring: 'ring-amber-600/20',  bg: 'bg-amber-50' },
  healthy:   { label: 'Healthy',   emoji: '🟢', dot: 'bg-emerald-500', text: 'text-emerald-700', ring: 'ring-emerald-600/20', bg: 'bg-emerald-50' },
};

// Recommended-action presentation. Informational actions render as a static chip (nothing to click —
// the work is already in motion); the rest render as a button that takes the manager to act.
const ACTION_CHIP = {
  blue:   'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-600/20',
  violet: 'bg-violet-50 text-violet-700 ring-1 ring-inset ring-violet-600/20',
  slate:  'bg-slate-100 text-slate-500 ring-1 ring-inset ring-slate-300/60',
};
const ACTION_BTN = {
  red:    'bg-red-600 text-white hover:bg-red-700',
  amber:  'bg-amber-500 text-white hover:bg-amber-600',
  yellow: 'bg-yellow-400 text-slate-900 hover:bg-yellow-500',
};

function RiskBadge({ level }) {
  const c = RISK[level] || RISK.healthy;
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset ${c.bg} ${c.text} ${c.ring}`}>
      <span className={`h-1.5 w-1.5 rounded-full ${c.dot}`} /> {c.label}
    </span>
  );
}

/** Priority score + the top reason, with the full reason list on hover. */
function PriorityCell({ row }) {
  const c = RISK[row.risk_level] || RISK.healthy;
  const reasons = row.priority_reasons || [];
  return (
    <Tooltip content={reasons.length ? reasons.join(' · ') : 'No contributing factors'}>
      <div className="flex items-center gap-2">
        <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-sm font-bold tabular-nums ring-1 ring-inset ${c.bg} ${c.text} ${c.ring}`}>
          {row.priority_score}
        </span>
        <span className="hidden max-w-[150px] truncate text-xs text-slate-400 lg:block">{reasons[0] || '—'}</span>
      </div>
    </Tooltip>
  );
}

/** Row-level quick actions: the recommended next action + a kebab (view / snooze). */
function ActionCell({ row, onAct, onView, onSnooze }) {
  const [open, setOpen] = useState(false);
  const a = row.recommended_action || {};
  const informational = a.informational;

  return (
    <div className="flex items-center justify-end gap-1.5" onClick={(e) => e.stopPropagation()}>
      {informational ? (
        <span className={`rounded-md px-2 py-1 text-xs font-medium ${ACTION_CHIP[a.tone] || ACTION_CHIP.slate}`}>{a.label}</span>
      ) : (
        <button
          type="button"
          onClick={() => onAct(row)}
          className={`rounded-md px-2.5 py-1 text-xs font-semibold shadow-sm transition-colors ${ACTION_BTN[a.tone] || ACTION_BTN.amber}`}
        >
          {a.label}
        </button>
      )}
      <div className="relative">
        <button type="button" onClick={() => setOpen((o) => !o)} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="More actions">
          <svg className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 6a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm0 5.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm0 5.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z" /></svg>
        </button>
        {open && (
          <>
            <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} />
            <div className="absolute right-0 z-20 mt-1 w-44 overflow-hidden rounded-lg bg-white py-1 text-sm shadow-lg ring-1 ring-slate-200">
              <MenuItem onClick={() => { setOpen(false); onView(row); }}>View vehicle</MenuItem>
              <MenuItem onClick={() => { setOpen(false); onAct(row); }}>{a.label}</MenuItem>
              <MenuItem onClick={() => { setOpen(false); onSnooze(row); }}>Snooze / ignore</MenuItem>
            </div>
          </>
        )}
      </div>
    </div>
  );
}

function MenuItem({ children, onClick }) {
  return (
    <button type="button" onClick={onClick} className="block w-full px-3 py-1.5 text-left text-slate-600 hover:bg-slate-50">
      {children}
    </button>
  );
}

/** Lightweight snooze dialog — sends POST /service-due/snooze. */
function SnoozeDialog({ vehicle, onClose, onDone }) {
  const toast = useToast();
  const [until, setUntil] = useState('');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    setBusy(true);
    try {
      await api.post('/service-due/snooze', {
        vehicle_id: vehicle.vehicle_id || vehicle.id,
        snoozed_until: until || null,
        reason: reason || null,
      });
      toast.success('Vehicle snoozed');
      onDone?.();
    } catch (e) {
      toast.error(e?.response?.data?.message || 'Could not snooze');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="fixed inset-0 z-[70] flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-slate-900/40" onClick={onClose} />
      <div className="relative w-full max-w-sm rounded-xl bg-white p-5 shadow-2xl ring-1 ring-slate-200">
        <h3 className="text-base font-bold text-slate-900">Snooze {vehicle.plate || `#${vehicle.vehicle_id || vehicle.id}`}</h3>
        <p className="mt-1 text-xs text-slate-500">Hides this car from the board until the date below. It does not change any service data.</p>
        <label className="mt-4 block text-xs font-medium text-slate-500">Snooze until</label>
        <input type="date" value={until} onChange={(e) => setUntil(e.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        <label className="mt-3 block text-xs font-medium text-slate-500">Reason (optional)</label>
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} rows={2} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="e.g. serviced off-record, awaiting owner decision…" />
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="secondary" size="sm" onClick={onClose}>Cancel</Button>
          <Button variant="primary" size="sm" loading={busy} onClick={submit}>Snooze</Button>
        </div>
      </div>
    </div>
  );
}

/**
 * Maintenance Operations Center — the evolved /service-due. The first screen a maintenance manager
 * opens: which vehicles need attention (risk dashboard + priority-ranked list), WHY they're prioritized
 * (Maintenance Priority Score + reasons), and WHAT to do next (a Recommended Next Action per vehicle).
 * All computed server-side by MaintenanceOpsCenterService over the existing km / foresight / workflow
 * engines — this page only presents and acts.
 */
export default function ServiceDueBoard() {
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/intelligence/maintenance-ops');
    return data.data;
  }, []);
  const { data, loading, error, reload } = useFetch(fetcher);
  const navigate = useNavigate();

  const [q, setQ] = useState('');
  const [level, setLevel] = useState('all'); // all | critical | attention | upcoming
  const [dqOnly, setDqOnly] = useState(false);
  const [drawerId, setDrawerId] = useState(null);
  const [snoozeVehicle, setSnoozeVehicle] = useState(null);

  const rows = useMemo(() => {
    let list = data?.vehicles || [];
    if (level !== 'all') list = list.filter((r) => r.risk_level === level);
    if (dqOnly) list = list.filter((r) => r.data_quality?.suspect);
    const needle = q.trim().toLowerCase();
    if (needle) {
      list = list.filter(
        (r) => (r.plate || '').toLowerCase().includes(needle) || (r.car || '').toLowerCase().includes(needle),
      );
    }
    return list;
  }, [data, q, level, dqOnly]);

  const s = data?.summary || {};

  const onAct = (r) => {
    const key = r.recommended_action?.key;
    if (key === 'verify_odometer') navigate('/mileage-chain-audit');
    else navigate(`/car-status/${r.vehicle_id}`);
  };
  const onView = (r) => navigate(`/car-status/${r.vehicle_id}`);

  const columns = [
    {
      key: 'priority_score', header: 'Priority', align: 'left',
      tooltip: 'Maintenance Priority Score (0–100): km overdue + usage + failure risk + history + importance. Hover for the reasons.',
      render: (r) => <PriorityCell row={r} />,
    },
    {
      key: 'plate', align: 'left', header: 'Vehicle', cellClass: 'font-medium',
      render: (r) => (
        <>
          <Link to={`/car-status/${r.vehicle_id}`} onClick={(e) => e.stopPropagation()} className="text-indigo-600 hover:text-indigo-700">{r.plate || `#${r.vehicle_id}`}</Link>
          <div className="text-xs text-slate-400">{r.car || '—'}</div>
        </>
      ),
    },
    {
      key: 'current', align: 'right', header: 'Current KM', cellClass: 'tabular-nums text-slate-600',
      render: (r) => (
        <div className="flex items-center justify-end gap-1">
          {r.data_quality?.suspect && (
            <Tooltip content={r.data_quality.reason}><Icon.Alert className="h-3.5 w-3.5 text-amber-500" /></Tooltip>
          )}
          <span className={r.data_quality?.suspect ? 'text-amber-600 line-through decoration-amber-400/60' : ''}>
            {r.current != null ? `${num(r.current)} km` : '—'}
          </span>
        </div>
      ),
    },
    {
      key: 'interval', align: 'right', header: 'Interval', cellClass: 'tabular-nums text-slate-500',
      render: (r) => (r.interval != null ? `${num(r.interval)} km` : '—'),
    },
    {
      key: 'remaining_km', align: 'right', header: 'Overdue / Remaining', cellClass: 'tabular-nums font-semibold',
      render: (r) =>
        r.data_quality?.suspect ? (
          <span className="text-amber-500">check data</span>
        ) : r.service_status === 'overdue' ? (
          <span className="text-red-600">{num(r.overdue_km)} km over</span>
        ) : r.remaining_km != null ? (
          <span className="text-amber-600">{num(r.remaining_km)} km left</span>
        ) : '—',
    },
    {
      key: 'usage_rate', align: 'right', header: 'Usage', cellClass: 'tabular-nums text-slate-500',
      render: (r) => (r.usage_rate != null ? `${num(r.usage_rate)} km/d` : '—'),
    },
    {
      key: 'risk_level', header: 'Risk', align: 'left',
      render: (r) => <RiskBadge level={r.risk_level} />,
    },
    {
      key: 'last_service_at', align: 'left', header: 'Last maintenance', cellClass: 'text-slate-500',
      render: (r) => (r.last_service_at ? fmtDate(r.last_service_at) : <span className="text-slate-300">Unknown</span>),
    },
    {
      key: 'projected_date', align: 'right', header: 'Projected due', cellClass: 'tabular-nums text-slate-600',
      render: (r) => (r.projected_date ? fmtDate(r.projected_date) : <span className="text-slate-300">—</span>),
    },
    {
      key: 'action', header: 'Action', align: 'right',
      render: (r) => <ActionCell row={r} onAct={onAct} onView={onView} onSnooze={setSnoozeVehicle} />,
    },
  ];

  const filterBtn = (key, label) => (
    <button
      type="button"
      onClick={() => setLevel(key)}
      className={`rounded-full px-3 py-1 text-xs font-medium ${level === key ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}
    >
      {label}
    </button>
  );

  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1600px] space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Maintenance Operations Center"
          subtitle="Which vehicles need attention, why they're prioritized, and what to do next. Priority blends km overdue, usage, failure risk, service history and operational importance — never guessed."
        />

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {/* Maintenance Risk Dashboard */}
        {loading ? (
          <MetricGridSkeleton count={4} />
        ) : (
          <MetricGrid cols={4}>
            <MetricCard label="Critical Overdue" value={num(s.critical_count || 0)} tone="red" icon={<Icon.Alert className="h-5 w-5" />} hint={`${num(s.critical_overdue_km_total || 0)} km overdue total`} />
            <MetricCard label="Due Soon" value={num(s.due_soon_count || 0)} tone="amber" icon={<Icon.Clock className="h-5 w-5" />} hint="Approaching the service interval" />
            <MetricCard label="High-Usage Risk" value={num(s.high_usage_risk_count || 0)} tone="violet" icon={<Icon.Gauge className="h-5 w-5" />} hint="Overdue + hard-driven, not yet in the shop" />
            <MetricCard label="Average Delay" value={`${num(s.avg_overdue_km || 0)} km`} tone="indigo" icon={<Icon.TrendUp className="h-5 w-5" />} hint="Average distance past the interval" />
          </MetricGrid>
        )}

        {/* Controls */}
        <div className="flex flex-wrap items-center gap-3">
          <SearchInput value={q} onChange={setQ} placeholder="Search plate or make / model…" className="w-full max-w-xs" />
          <div className="flex items-center gap-2">
            {filterBtn('all', 'All')}
            {filterBtn('critical', '🔴 Critical')}
            {filterBtn('attention', '🟠 Attention')}
            {filterBtn('upcoming', '🟡 Upcoming')}
          </div>
          {(s.data_quality_flags || 0) > 0 && (
            <button
              type="button"
              onClick={() => setDqOnly((v) => !v)}
              className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium ${dqOnly ? 'bg-amber-500 text-white' : 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-500/30'}`}
            >
              <Icon.Alert className="h-3.5 w-3.5" /> Data issues {num(s.data_quality_flags)}
            </button>
          )}
          <span className="ml-auto flex items-center gap-3 text-xs text-slate-400">
            {(s.snoozed_count || 0) > 0 && <span>{num(s.snoozed_count)} snoozed</span>}
            <span>{num(rows.length)} of {num(s.listed || 0)} cars</span>
            <Button variant="ghost" size="sm" onClick={reload}><Icon.Refresh className="h-4 w-4" /></Button>
          </span>
        </div>

        <SectionCard
          title="Vehicles needing attention"
          subtitle="Ranked by Maintenance Priority Score — the manager's morning triage order."
        >
          <DataTable
            columns={columns}
            rows={rows}
            rowKey={(r) => r.vehicle_id}
            loading={loading}
            onRowClick={(r) => setDrawerId(r.vehicle_id)}
            highlightRow={(r) => r.risk_level === 'critical'}
            empty={q || dqOnly || level !== 'all' ? 'No cars match your filters.' : 'No cars need attention right now. 🎉'}
          />
        </SectionCard>
      </div>

      <MaintenanceContextDrawer
        vehicleId={drawerId}
        onClose={() => setDrawerId(null)}
        onSnooze={(v) => { setSnoozeVehicle(v); setDrawerId(null); }}
      />

      {snoozeVehicle && (
        <SnoozeDialog
          vehicle={snoozeVehicle}
          onClose={() => setSnoozeVehicle(null)}
          onDone={() => { setSnoozeVehicle(null); reload(); }}
        />
      )}
    </div>
  );
}
