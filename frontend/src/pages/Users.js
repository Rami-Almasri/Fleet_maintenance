// Workforce Operations Center — the enterprise re-imagining of the old Users list.
// Same admin-only CRUD (create / edit / suspend / delete via the users.manage-gated
// /auth endpoints), now wrapped in a live operations dashboard: who is online, what
// they are using, how long they have worked, their productivity and their audit trail.
//
// Every number here is REAL, derived by the backend (UserActivityService) from the
// activity log the app emits as people navigate — nothing is fabricated. Because
// tracking only starts accruing once deployed, surfaces degrade gracefully to "—"
// and an explanatory note until the team generates history.

import { useCallback, useMemo, useState, useEffect } from 'react';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { useAuth } from '../auth/AuthContext';
import { useToast } from '../components/ui/Toast';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import ConfirmDialog from '../components/ui/ConfirmDialog';
import Drawer from '../components/ui/Drawer';
import { Input, Select } from '../components/ui/Field';
import { PageHeader, SearchInput } from '../components/ui/Misc';
import { SectionCard } from '../components/ui/Table';
import { Skeleton } from '../components/ui/Skeleton';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import Tabs from '../components/ui/Tabs';
import FilterChips from '../components/ui/FilterChips';
import BarChart from '../components/ui/BarChart';
import LineChart from '../components/ui/LineChart';
import PieChart from '../components/ui/PieChart';
import { ProgressBar } from '../components/ui/Progress';
import CountUp from '../components/ui/CountUp';
import Icon from '../components/ui/Icon';

const ROLE_TONE = {
  'super-admin': 'red', admin: 'red', manager: 'indigo', operations: 'blue',
  maintenance: 'violet', supervisor: 'amber', inspector: 'cyan', logistics: 'blue',
  finance: 'emerald', viewer: 'slate',
};

const STATUS_META = {
  online: { tone: 'emerald', label: 'Online' },
  idle: { tone: 'amber', label: 'Idle' },
  offline: { tone: 'slate', label: 'Offline' },
};

const PIE_COLORS = ['blue', 'emerald', 'amber', 'purple', 'cyan', 'slate'];

const initialsOf = (name) =>
  (name || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?';

const messageOf = (err, fallback) => {
  const msg = err?.response?.data?.msg;
  if (typeof msg === 'string') return msg;
  if (msg && typeof msg === 'object') {
    const first = Object.values(msg)[0];
    return Array.isArray(first) ? first[0] : String(first);
  }
  return fallback;
};

// ── formatting ────────────────────────────────────────────────────────────
const todayStr = () => new Date().toISOString().slice(0, 10);

const fmtDuration = (sec) => {
  if (sec == null) return '—';
  const s = Math.max(0, Math.round(sec));
  if (s < 60) return `${s}s`;
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  if (h > 0) return `${h}h ${m}m`;
  return `${m}m`;
};

const clock = (iso) => {
  if (!iso) return '—';
  const d = new Date(iso);
  return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
};

const relTime = (iso) => {
  if (!iso) return 'Never';
  const diff = (Date.now() - new Date(iso).getTime()) / 1000;
  if (diff < 60) return 'just now';
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
  const d = new Date(iso);
  return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
};

const dateLabel = (iso) => (iso ? new Date(iso).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' }) : '—');

const EMPTY_FORM = { name: '', email: '', password: '', role: 'viewer', status: 'active' };

// ── small building blocks ───────────────────────────────────────────────────
function StatusDot({ status }) {
  const m = STATUS_META[status] || STATUS_META.offline;
  return <Badge tone={m.tone} dot>{m.label}</Badge>;
}

function IconButton({ title, onClick, danger, disabled, children }) {
  return (
    <button
      type="button"
      title={title}
      aria-label={title}
      disabled={disabled}
      onClick={(e) => { e.stopPropagation(); onClick?.(); }}
      className={`rounded-lg p-1.5 transition disabled:opacity-30 disabled:cursor-not-allowed ${
        danger ? 'text-slate-400 hover:bg-red-50 hover:text-red-600' : 'text-slate-400 hover:bg-slate-100 hover:text-slate-700'
      }`}
    >
      {children}
    </button>
  );
}

const SVG = {
  eye: <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2"><path strokeLinecap="round" strokeLinejoin="round" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z" /><circle cx="12" cy="12" r="3" /></svg>,
  pencil: <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2"><path strokeLinecap="round" strokeLinejoin="round" d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" /></svg>,
  power: <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2"><path strokeLinecap="round" strokeLinejoin="round" d="M18.36 6.64a9 9 0 1 1-12.73 0M12 2v10" /></svg>,
  trash: <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2"><path strokeLinecap="round" strokeLinejoin="round" d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" /></svg>,
};

// A single account row — rich, information-dense, click to open the activity drawer.
function UserRow({ row, isSelf, onOpen, onEdit, onToggle, onDelete }) {
  return (
    <div
      role="button"
      tabIndex={0}
      onClick={() => onOpen(row)}
      onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && onOpen(row)}
      className="group grid cursor-pointer grid-cols-12 items-center gap-3 px-4 py-3 transition hover:bg-slate-50/80"
    >
      {/* Identity */}
      <div className="col-span-12 flex min-w-0 items-center gap-3 sm:col-span-4">
        <span className="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-slate-100 to-slate-200 text-xs font-bold text-slate-600 ring-1 ring-slate-200">
          {initialsOf(row.name)}
          <span
            className={`absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-white ${
              row.status === 'online' ? 'bg-emerald-500' : row.status === 'idle' ? 'bg-amber-400' : 'bg-slate-300'
            }`}
          />
        </span>
        <div className="min-w-0">
          <div className="flex items-center gap-1.5">
            <span className="truncate text-sm font-semibold text-slate-800">{row.name}</span>
            {isSelf && <span className="text-[11px] text-slate-400">(you)</span>}
            {row.account_status !== 'active' && <Badge tone="red">suspended</Badge>}
          </div>
          <p className="truncate text-xs text-slate-400">{row.email}</p>
        </div>
      </div>

      {/* Role + department */}
      <div className="col-span-6 hidden flex-wrap items-center gap-1 sm:col-span-2 sm:flex">
        {(row.roles || []).length === 0
          ? <span className="text-xs italic text-slate-300">no role</span>
          : row.roles.map((r) => <Badge key={r} tone={ROLE_TONE[r] || 'gray'}>{r}</Badge>)}
      </div>

      {/* Status + last seen */}
      <div className="col-span-6 sm:col-span-2">
        <StatusDot status={row.status} />
        <p className="mt-1 text-[11px] text-slate-400">
          {row.status === 'online'
            ? (row.last_page ? `on ${row.last_page}` : 'active now')
            : row.status === 'idle'
              ? `idle ${fmtDuration(row.idle_seconds)}`
              : `seen ${relTime(row.last_seen_at)}`}
        </p>
      </div>

      {/* Session / today */}
      <div className="col-span-6 hidden text-right sm:col-span-1 sm:block">
        <p className="text-sm font-semibold tabular-nums text-slate-700">{row.online ? fmtDuration(row.current_session_seconds) : '—'}</p>
        <p className="text-[11px] text-slate-400">session</p>
      </div>
      <div className="col-span-6 hidden text-right sm:col-span-1 sm:block">
        <p className="text-sm font-semibold tabular-nums text-slate-700">{fmtDuration(row.today_seconds)}</p>
        <p className="text-[11px] text-slate-400">today</p>
      </div>

      {/* Pending + actions */}
      <div className="col-span-12 flex items-center justify-end gap-2 sm:col-span-2">
        {row.pending_tasks > 0 && (
          <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">
            {row.pending_tasks} pending
          </span>
        )}
        <div className="flex items-center opacity-100 sm:opacity-0 sm:transition sm:group-hover:opacity-100">
          <IconButton title="View activity" onClick={() => onOpen(row)}>{SVG.eye}</IconButton>
          <IconButton title="Edit / reset password / role" onClick={() => onEdit(row)}>{SVG.pencil}</IconButton>
          <IconButton title={row.account_status === 'active' ? 'Disable account' : 'Enable account'} disabled={isSelf} onClick={() => onToggle(row)}>{SVG.power}</IconButton>
          <IconButton title="Delete" danger disabled={isSelf} onClick={() => onDelete(row)}>{SVG.trash}</IconButton>
        </div>
      </div>
    </div>
  );
}

// A compact 24-cell peak-usage strip — instantly shows the busy hours.
function UsageHeatmap({ byHour }) {
  const max = Math.max(1, ...byHour.map((h) => h.count));
  return (
    <div className="space-y-2">
      <div className="flex gap-1">
        {byHour.map((h) => {
          const intensity = h.count / max;
          return (
            <div key={h.hour} className="group/cell relative flex-1" title={`${h.hour} — ${h.count} events`}>
              <div
                className="h-10 rounded"
                style={{ backgroundColor: intensity === 0 ? '#f1f5f9' : `rgba(37, 99, 235, ${0.15 + intensity * 0.85})` }}
              />
            </div>
          );
        })}
      </div>
      <div className="flex justify-between text-[10px] font-medium text-slate-400">
        {['00', '04', '08', '12', '16', '20', '23'].map((h) => <span key={h}>{h}:00</span>)}
      </div>
    </div>
  );
}

// ── the activity drawer ─────────────────────────────────────────────────────
// Format a productivity metric by its declared unit — durations as "2h 15m",
// percentages with a "%", counts as-is; a null value renders as "—".
const fmtProdValue = (p) => {
  if (p.value == null) return '—';
  if (p.unit === 'duration') return fmtDuration(p.value);
  if (p.unit === 'percent') return `${p.value}%`;
  return p.value;
};

const PROD_TONE = { amber: 'text-amber-700', red: 'text-red-700' };

const RATING_META = {
  excellent: { tone: 'emerald', label: 'Excellent' },
  good: { tone: 'blue', label: 'Good' },
  needs_attention: { tone: 'amber', label: 'Needs attention' },
};

// A +/- change pill; null shows a muted "no baseline". `higherIsBetter` colours it.
function DeltaPill({ pct, suffix }) {
  if (pct == null) return <span className="text-[11px] text-slate-400">no baseline</span>;
  const up = pct >= 0;
  return (
    <span className={`inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${
      up ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-red-50 text-red-600 ring-red-600/20'
    }`}>
      {up ? '▲' : '▼'} {up ? '+' : ''}{pct}%{suffix ? ` ${suffix}` : ''}
    </span>
  );
}

// Comparative performance intelligence — rank, team benchmark, deltas, trend, rating.
function PerformancePanel({ c }) {
  if (!c) return null;
  const rating = RATING_META[c.rating] || { tone: 'slate', label: 'Not enough data' };
  const cmpMax = Math.max(1, c.output_30d, c.team_avg_30d);
  return (
    <div className="rounded-xl border border-slate-200/70 bg-white p-4">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <span className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">Performance · 30 days</span>
          <Badge tone={rating.tone}>{rating.label}</Badge>
        </div>
        <div className="text-right">
          <p className="text-sm font-bold text-slate-800">Rank #{c.rank} <span className="font-normal text-slate-400">of {c.total_users}</span></p>
          {c.percentile != null && <p className="text-[11px] text-slate-400">ahead of {Math.round(c.percentile * 100)}% of the team</p>}
        </div>
      </div>

      <div className="mb-3 grid grid-cols-3 gap-3">
        <div className="rounded-lg bg-slate-50 p-2.5">
          <p className="text-[11px] font-medium text-slate-400">30-day output</p>
          <p className="text-xl font-bold tabular-nums text-slate-800">{c.output_30d}</p>
          <p className="text-[11px] text-slate-400">vs {c.team_avg_30d} team avg</p>
        </div>
        <div className="rounded-lg bg-slate-50 p-2.5">
          <p className="text-[11px] font-medium text-slate-400">This week</p>
          <p className="text-xl font-bold tabular-nums text-slate-800">{c.this_week}</p>
          <DeltaPill pct={c.week_delta_pct} suffix="vs last wk" />
        </div>
        <div className="rounded-lg bg-slate-50 p-2.5">
          <p className="text-[11px] font-medium text-slate-400">Today so far</p>
          <p className="text-xl font-bold tabular-nums text-slate-800">{c.today}</p>
          <DeltaPill pct={c.today_vs_avg_pct} suffix="vs avg" />
        </div>
      </div>

      {/* Employee vs team benchmark */}
      <div className="mb-3 space-y-1.5">
        <div className="flex items-center justify-between text-[11px]"><span className="font-medium text-slate-600">This employee</span><span className="tabular-nums text-slate-500">{c.output_30d}</span></div>
        <ProgressBar value={c.output_30d} max={cmpMax} tone="blue" />
        <div className="flex items-center justify-between text-[11px]"><span className="font-medium text-slate-600">Team average</span><span className="tabular-nums text-slate-500">{c.team_avg_30d}</span></div>
        <ProgressBar value={c.team_avg_30d} max={cmpMax} tone="slate" />
      </div>

      {/* 30-day output trend */}
      <div>
        <p className="mb-1 text-[11px] font-medium text-slate-400">Output trend · last 30 days</p>
        <LineChart data={(c.trend || []).map((d) => ({ label: d.label, value: d.count }))} color="indigo" height={130} valueLabel="Actions" />
      </div>
    </div>
  );
}

function ProductivityGroup({ title, metrics }) {
  if (!metrics || metrics.length === 0) return null;
  return (
    <div>
      <p className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">{title}</p>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
        {metrics.map((p) => (
          <div key={p.label} className="rounded-xl border border-slate-200/70 bg-white p-3">
            <p className={`text-2xl font-bold tabular-nums ${(p.value ?? 0) > 0 ? (PROD_TONE[p.tone] || 'text-slate-800') : 'text-slate-300'}`}>
              {fmtProdValue(p)}
            </p>
            <p className="mt-0.5 text-[11px] font-medium text-slate-500">{p.label}</p>
            {p.hint && <p className="mt-0.5 text-[10px] text-slate-400">{p.hint}</p>}
          </div>
        ))}
      </div>
    </div>
  );
}

function AuditRow({ label, value }) {
  return (
    <div className="flex items-center justify-between border-b border-slate-100 py-2 last:border-0">
      <span className="text-xs font-medium text-slate-500">{label}</span>
      <span className="text-sm font-semibold text-slate-800">{value ?? '—'}</span>
    </div>
  );
}

function ActivityDrawer({ open, row, onClose, currentUserId, onEdit, onToggle, onDelete }) {
  const [date, setDate] = useState(todayStr());
  const [detail, setDetail] = useState(null);
  const [loading, setLoading] = useState(false);
  const [tab, setTab] = useState('overview');

  useEffect(() => { if (open) { setDate(todayStr()); setTab('overview'); } }, [open, row?.id]);

  useEffect(() => {
    if (!open || !row) return;
    let alive = true;
    setLoading(true);
    api.get(`/auth/users/${row.id}/activity`, { params: { date } })
      .then((res) => { if (alive) setDetail(res.data.data); })
      .catch(() => { if (alive) setDetail(null); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, [open, row, date]);

  if (!row) return null;
  const daily = detail?.daily;
  const isSelf = row.id === currentUserId;

  const tabs = [
    { key: 'overview', label: 'Overview' },
    { key: 'timeline', label: 'Timeline' },
    { key: 'modules', label: 'Modules' },
    { key: 'productivity', label: 'Productivity' },
    { key: 'audit', label: 'Audit' },
  ];

  return (
    <Drawer
      open={open}
      onClose={onClose}
      width="lg"
      eyebrow={(row.roles || []).join(', ') || 'no role'}
      title={row.name}
      subtitle={row.email}
      footer={
        <div className="flex items-center justify-between gap-2">
          <StatusDot status={row.status} />
          <div className="flex items-center gap-2">
            <Button variant="secondary" size="sm" onClick={() => onEdit(row)}>Edit / reset</Button>
            <Button variant="secondary" size="sm" disabled={isSelf} onClick={() => onToggle(row)}>
              {row.account_status === 'active' ? 'Disable' : 'Enable'}
            </Button>
            <Button variant="ghost" size="sm" className="text-red-600 hover:bg-red-50" disabled={isSelf} onClick={() => onDelete(row)}>Delete</Button>
          </div>
        </div>
      }
    >
      {/* Live snapshot */}
      <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        {[
          { label: 'Status', value: (STATUS_META[row.status] || {}).label },
          { label: 'Current session', value: row.online ? fmtDuration(row.current_session_seconds) : '—' },
          { label: "Today's activity", value: fmtDuration(row.today_seconds) },
          { label: 'This week', value: fmtDuration(row.week_seconds) },
        ].map((s) => (
          <div key={s.label} className="rounded-xl border border-slate-200/70 bg-white p-3">
            <p className="text-[11px] font-medium text-slate-400">{s.label}</p>
            <p className="mt-1 text-lg font-bold tabular-nums text-slate-800">{s.value}</p>
          </div>
        ))}
      </div>

      {/* Date + tabs */}
      <div className="mb-3 flex items-center justify-between gap-2">
        <Tabs tabs={tabs} active={tab} onChange={setTab} ariaLabel="Activity sections" />
      </div>
      {tab !== 'audit' && (
        <div className="mb-4 flex items-center gap-2">
          <span className="text-xs font-medium text-slate-500">Day</span>
          <input
            type="date"
            value={date}
            max={todayStr()}
            onChange={(e) => setDate(e.target.value)}
            className="rounded-lg border border-slate-200 px-2 py-1 text-sm text-slate-700"
          />
        </div>
      )}

      {loading ? (
        <div className="space-y-2"><Skeleton className="h-24 rounded-xl" /><Skeleton className="h-40 rounded-xl" /></div>
      ) : (
        <>
          {tab === 'overview' && (
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {[
                  { label: 'First login', value: daily?.login_time || '—' },
                  { label: 'Last logout', value: daily?.logout_time || (row.online ? 'still active' : '—') },
                  { label: 'Sessions', value: daily?.session_count ?? '—' },
                  { label: 'Working hours', value: fmtDuration(daily?.total_working_seconds) },
                  { label: 'Active (est.)', value: fmtDuration(daily?.active_seconds) },
                  { label: 'Idle (est.)', value: fmtDuration(daily?.idle_seconds) },
                ].map((s) => (
                  <div key={s.label} className="rounded-xl border border-slate-200/70 bg-white p-3">
                    <p className="text-[11px] font-medium text-slate-400">{s.label}</p>
                    <p className="mt-1 text-base font-bold tabular-nums text-slate-800">{s.value}</p>
                  </div>
                ))}
              </div>
              {daily && daily.total_working_seconds > 0 && (
                <div className="rounded-xl border border-slate-200/70 bg-white p-4">
                  <p className="mb-2 text-xs font-semibold text-slate-600">Active vs idle</p>
                  <ProgressBar value={daily.active_seconds} max={Math.max(1, daily.total_working_seconds)} tone="emerald" showPct />
                </div>
              )}
            </div>
          )}

          {tab === 'timeline' && (
            <div className="rounded-xl border border-slate-200/70 bg-white p-4">
              {(detail?.timeline || []).length === 0 ? (
                <p className="py-8 text-center text-sm text-slate-400">No recorded activity on this day.</p>
              ) : (
                <ol className="relative ml-2 border-l-2 border-slate-100">
                  {detail.timeline.map((e, i) => (
                    <li key={i} className="mb-4 ml-4 last:mb-0">
                      <span className={`absolute -left-[7px] mt-1 h-3 w-3 rounded-full ring-2 ring-white ${
                        e.type === 'login' ? 'bg-emerald-500' : e.type === 'logout' ? 'bg-slate-400' : 'bg-blue-500'
                      }`} />
                      <div className="flex items-center gap-2">
                        <span className="font-mono text-xs font-semibold text-slate-500">{e.time}</span>
                        <span className="text-sm font-medium text-slate-800">{e.label}</span>
                      </div>
                    </li>
                  ))}
                </ol>
              )}
            </div>
          )}

          {tab === 'modules' && (
            <div className="rounded-xl border border-slate-200/70 bg-white p-4">
              {(detail?.module_usage || []).length === 0 ? (
                <p className="py-8 text-center text-sm text-slate-400">No module usage recorded on this day.</p>
              ) : (
                <div className="space-y-3">
                  {detail.module_usage.map((m) => (
                    <div key={m.module}>
                      <div className="mb-1 flex items-center justify-between text-xs">
                        <span className="font-medium text-slate-700">{m.module}</span>
                        <span className="tabular-nums text-slate-400">{m.pct}% · {m.count}</span>
                      </div>
                      <ProgressBar value={m.pct} max={100} tone="blue" />
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {tab === 'productivity' && (
            <div className="space-y-4">
              <PerformancePanel c={detail?.comparison} />
              <ProductivityGroup title="Throughput" metrics={(detail?.productivity || []).filter((p) => p.group === 'throughput')} />
              <ProductivityGroup title="Quality & speed" metrics={(detail?.productivity || []).filter((p) => p.group === 'quality')} />
              <p className="text-[11px] text-slate-400">Real output attributed to this account across the maintenance, parts & logistics workflows (lifetime). “—” means there’s no basis to compute it yet.</p>
            </div>
          )}

          {tab === 'audit' && detail?.audit && (
            <div className="rounded-xl border border-slate-200/70 bg-white p-4">
              <AuditRow label="Account created" value={dateLabel(detail.audit.account_created)} />
              <AuditRow label="Created by" value={detail.audit.created_by} />
              <AuditRow label="Last password change" value={dateLabel(detail.audit.last_password_change)} />
              <AuditRow label="Last role change" value={dateLabel(detail.audit.last_role_change)} />
              <AuditRow label="Failed login attempts" value={detail.audit.failed_login_count} />
              <AuditRow label="Last failed login" value={detail.audit.last_failed_login ? `${dateLabel(detail.audit.last_failed_login)} ${clock(detail.audit.last_failed_login)}` : '—'} />
              <AuditRow label="Last device" value={detail.audit.device} />
              <AuditRow label="Browser" value={detail.audit.browser} />
              <AuditRow label="Last IP address" value={detail.audit.last_ip} />
            </div>
          )}
        </>
      )}
    </Drawer>
  );
}

// ── the page ────────────────────────────────────────────────────────────────
export default function Users() {
  const { user: currentUser } = useAuth();
  const toast = useToast();

  const [date, setDate] = useState(todayStr());
  const [formOpen, setFormOpen] = useState(false);
  const [deleting, setDeleting] = useState(null);

  const fetcher = useCallback(async () => (await api.get('/auth/workforce', { params: { date } })).data.data, [date]);
  const { data, loading, error, reload } = useFetch(fetcher, [date], {
    refreshInterval: 30000,
    paused: () => formOpen || !!deleting,
  });

  const rolesFetcher = useCallback(async () => (await api.get('/auth/roles')).data.data, []);
  const { data: rolesData } = useFetch(rolesFetcher);
  const roles = useMemo(() => (Array.isArray(rolesData) ? rolesData : []), [rolesData]);

  const kpis = data?.kpis || {};
  const users = useMemo(() => (Array.isArray(data?.users) ? data.users : []), [data]);
  const charts = useMemo(() => data?.charts || {}, [data]);

  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [roleFilter, setRoleFilter] = useState('all');
  const [view, setView] = useState('directory');
  const [selected, setSelected] = useState(null);

  // CRUD modal state.
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(EMPTY_FORM);
  const [fieldErrors, setFieldErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [deleteBusy, setDeleteBusy] = useState(false);

  const roleOptions = useMemo(() => {
    const set = new Set();
    users.forEach((u) => (u.roles || []).forEach((r) => set.add(r)));
    return Array.from(set).sort();
  }, [users]);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return users.filter((u) => {
      if (statusFilter === 'online' && !u.online) return false;
      if (statusFilter === 'idle' && u.status !== 'idle') return false;
      if (statusFilter === 'offline' && u.online) return false;
      if (statusFilter === 'never' && !u.never_logged_in) return false;
      if (statusFilter === 'suspended' && u.account_status === 'active') return false;
      if (roleFilter !== 'all' && !(u.roles || []).includes(roleFilter)) return false;
      if (q && ![u.name, u.email, u.department, ...(u.roles || [])].some((f) => (f || '').toLowerCase().includes(q))) return false;
      return true;
    });
  }, [users, search, statusFilter, roleFilter]);

  const statusChips = useMemo(() => [
    { key: 'all', label: 'All', count: users.length },
    { key: 'online', label: 'Online', count: users.filter((u) => u.online).length, tone: 'green' },
    { key: 'idle', label: 'Idle', count: users.filter((u) => u.status === 'idle').length, tone: 'amber' },
    { key: 'offline', label: 'Offline', count: users.filter((u) => !u.online).length },
    { key: 'never', label: 'Never logged in', count: users.filter((u) => u.never_logged_in).length },
    { key: 'suspended', label: 'Disabled', count: users.filter((u) => u.account_status !== 'active').length, tone: 'red' },
  ], [users]);

  const hasActivity = useMemo(() => {
    const a = (charts.activity_by_hour || []).reduce((s, h) => s + h.count, 0);
    const l = (charts.login_trend || []).reduce((s, d) => s + d.count, 0);
    return a + l > 0;
  }, [charts]);

  // ── CRUD handlers ─────────────────────────────────────────────────────────
  const openCreate = () => {
    setEditing(null);
    setForm({ ...EMPTY_FORM, role: roles.includes('viewer') ? 'viewer' : roles[0] || '' });
    setFieldErrors({});
    setFormOpen(true);
  };

  const openEdit = (u) => {
    setEditing(u);
    setForm({ name: u.name || '', email: u.email || '', password: '', role: (u.roles || [])[0] || '', status: u.account_status || 'active' });
    setFieldErrors({});
    setFormOpen(true);
  };

  const setField = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const submit = async (e) => {
    e.preventDefault();
    setSaving(true);
    setFieldErrors({});
    try {
      if (editing) {
        const payload = { name: form.name, email: form.email, role: form.role, status: form.status };
        if (form.password) payload.password = form.password;
        await api.put(`/auth/users/${editing.id}`, payload);
        toast.success('User updated');
      } else {
        await api.post('/auth/signup', { name: form.name, email: form.email, password: form.password, role: form.role, status: form.status });
        toast.success('User created');
      }
      setFormOpen(false);
      reload({ silent: true });
    } catch (err) {
      const msg = err?.response?.data?.msg;
      if (msg && typeof msg === 'object') {
        setFieldErrors(Object.fromEntries(Object.entries(msg).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v])));
      }
      toast.error(messageOf(err, editing ? 'Could not update the user.' : 'Could not create the user.'));
    } finally {
      setSaving(false);
    }
  };

  const toggleStatus = async (u) => {
    try {
      await api.put(`/auth/users/${u.id}`, {
        name: u.name, email: u.email, role: (u.roles || [])[0] || 'viewer',
        status: u.account_status === 'active' ? 'suspended' : 'active',
      });
      toast.success(u.account_status === 'active' ? 'Account disabled' : 'Account enabled');
      reload({ silent: true });
    } catch (err) {
      toast.error(messageOf(err, 'Could not change the account status.'));
    }
  };

  const confirmDelete = async () => {
    if (!deleting) return;
    setDeleteBusy(true);
    try {
      await api.delete(`/auth/users/${deleting.id}`);
      toast.success('User deleted');
      setDeleting(null);
      if (selected?.id === deleting.id) setSelected(null);
      reload({ silent: true });
    } catch (err) {
      toast.error(messageOf(err, 'Could not delete the user.'));
    } finally {
      setDeleteBusy(false);
    }
  };

  const mostActive = kpis.most_active_user;

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Workforce Operations Center"
          subtitle={loading ? 'Loading…' : `${users.length} account${users.length === 1 ? '' : 's'} · ${kpis.online_now || 0} online now`}
        >
          <div className="flex items-center gap-2">
            <input
              type="date"
              value={date}
              max={todayStr()}
              onChange={(e) => setDate(e.target.value)}
              className="rounded-lg border border-slate-200 bg-white px-2.5 py-2 text-sm text-slate-700"
              title="Analytics date"
            />
            <Button onClick={openCreate}><span className="mr-1"><Icon.Plus /></span>Add user</Button>
          </div>
        </PageHeader>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {/* ── KPI cards ─────────────────────────────────────────────────── */}
        <MetricGrid cols={4}>
          <MetricCard loading={loading} label="Total users" value={<CountUp value={kpis.total_users} />} icon={<Icon.Users />} tone="slate" />
          <MetricCard loading={loading} label="Online now" value={<CountUp value={kpis.online_now} />} icon={<Icon.Activity />} tone="emerald" hint={`${kpis.idle_now || 0} idle`} />
          <MetricCard loading={loading} label="Active today" value={<CountUp value={kpis.active_today} />} icon={<Icon.Spark />} tone="blue" />
          <MetricCard loading={loading} label="Logged in today" value={<CountUp value={kpis.logged_in_today} />} icon={<Icon.Check />} tone="cyan" />
          <MetricCard loading={loading} label="Avg session today" value={fmtDuration(kpis.avg_session_seconds)} icon={<Icon.Clock />} tone="indigo" tooltip="Mean length of today's sessions across the team" />
          <MetricCard loading={loading} label="Most active user" value={mostActive ? mostActive.name.split(' ')[0] : '—'} icon={<Icon.Gauge />} tone="violet" hint={mostActive ? fmtDuration(mostActive.seconds) : 'No activity yet'} />
          <MetricCard loading={loading} label="Pending tasks" value={<CountUp value={kpis.pending_tasks} />} icon={<Icon.Alert />} tone="amber" hint="Open logistics moves" />
          <MetricCard loading={loading} label="Disabled accounts" value={<CountUp value={kpis.disabled} />} icon={<Icon.Shield />} tone="red" hint={`${kpis.never_logged_in || 0} never logged in`} />
        </MetricGrid>

        {!hasActivity && !loading && (
          <div className="rounded-xl border border-blue-200/70 bg-blue-50/60 px-4 py-3 text-sm text-blue-800">
            <span className="font-semibold">Activity tracking is live.</span> Presence, sessions, timelines and usage charts fill in as the team works in FleetView — historical data can't be backfilled, so surfaces show “—” until then.
          </div>
        )}

        <Tabs
          tabs={[{ key: 'directory', label: 'Directory' }, { key: 'analytics', label: 'Analytics' }]}
          active={view}
          onChange={setView}
          ariaLabel="Workforce views"
        />

        {view === 'directory' ? (
          <SectionCard
            title="Team directory"
            actions={<span className="text-xs text-slate-400">{filtered.length} shown</span>}
          >
            {/* Toolbar */}
            <div className="space-y-3 border-b border-slate-100 p-4">
              <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                <div className="flex-1"><SearchInput value={search} onChange={setSearch} placeholder="Search name, email, role or department…" /></div>
                <select
                  value={roleFilter}
                  onChange={(e) => setRoleFilter(e.target.value)}
                  className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700"
                >
                  <option value="all">All roles</option>
                  {roleOptions.map((r) => <option key={r} value={r}>{r}</option>)}
                </select>
              </div>
              <FilterChips options={statusChips} value={statusFilter} onChange={setStatusFilter} />
            </div>

            {loading ? (
              <div className="space-y-2 p-4">
                <Skeleton className="h-16 rounded-xl" /><Skeleton className="h-16 rounded-xl" /><Skeleton className="h-16 rounded-xl" />
              </div>
            ) : filtered.length === 0 ? (
              <div className="px-6 py-12 text-center text-sm text-slate-500">No users match these filters.</div>
            ) : (
              <div className="divide-y divide-slate-100">
                {filtered.map((u) => (
                  <UserRow
                    key={u.id}
                    row={u}
                    isSelf={currentUser?.id === u.id}
                    onOpen={setSelected}
                    onEdit={openEdit}
                    onToggle={toggleStatus}
                    onDelete={setDeleting}
                  />
                ))}
              </div>
            )}
          </SectionCard>
        ) : (
          <div className="space-y-6">
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
              <SectionCard title="Activity by hour" subtitle={`Module opens on ${dateLabel(date)}`}>
                <div className="p-4">
                  <BarChart data={(charts.activity_by_hour || []).map((h) => ({ label: h.hour, value: h.count }))} color="blue" height={220} valueLabel="Events" />
                </div>
              </SectionCard>
              <SectionCard title="Peak usage heat map" subtitle="Busiest hours of the day">
                <div className="p-4"><UsageHeatmap byHour={charts.activity_by_hour || []} /></div>
              </SectionCard>
              <SectionCard title="Login trend" subtitle="Logins per day (last 7 days)">
                <div className="p-4">
                  <LineChart data={(charts.login_trend || []).map((d) => ({ label: d.label, value: d.count }))} color="emerald" height={220} valueLabel="Logins" />
                </div>
              </SectionCard>
              <SectionCard title="Concurrent users" subtitle={`Distinct active users per hour on ${dateLabel(date)}`}>
                <div className="p-4">
                  <LineChart data={(charts.online_through_day || []).map((h) => ({ label: h.hour, value: h.count }))} color="cyan" height={220} valueLabel="Users" />
                </div>
              </SectionCard>
              <SectionCard title="Platform usage by module" subtitle="Most-used modules (last 7 days)">
                <div className="p-4">
                  {(charts.module_usage || []).length === 0 ? (
                    <p className="py-10 text-center text-sm text-slate-400">No module usage recorded yet.</p>
                  ) : (
                    <PieChart segments={(charts.module_usage || []).map((m, i) => ({ label: m.module, value: m.count, color: PIE_COLORS[i % PIE_COLORS.length] }))} />
                  )}
                </div>
              </SectionCard>
              <SectionCard title="Most active departments" subtitle={`Activity by department on ${dateLabel(date)}`}>
                <div className="p-4">
                  {(charts.department_activity || []).length === 0 ? (
                    <p className="py-10 text-center text-sm text-slate-400">No department activity recorded yet.</p>
                  ) : (
                    <BarChart data={(charts.department_activity || []).map((d) => ({ label: d.department, value: d.count }))} color="violet" height={220} valueLabel="Events" />
                  )}
                </div>
              </SectionCard>
            </div>
          </div>
        )}
      </div>

      {/* Activity drawer */}
      <ActivityDrawer
        open={!!selected}
        row={selected}
        onClose={() => setSelected(null)}
        currentUserId={currentUser?.id}
        onEdit={(u) => { setSelected(null); openEdit(u); }}
        onToggle={toggleStatus}
        onDelete={(u) => setDeleting(u)}
      />

      {/* Create / edit modal */}
      <Modal
        open={formOpen}
        onClose={() => !saving && setFormOpen(false)}
        title={editing ? 'Edit user' : 'Add user'}
        subtitle={editing ? editing.email : 'Create a new account and assign a role.'}
        footer={
          <>
            <Button variant="secondary" onClick={() => setFormOpen(false)} disabled={saving}>Cancel</Button>
            <Button type="submit" form="user-form" loading={saving}>{editing ? 'Save changes' : 'Create user'}</Button>
          </>
        }
      >
        <form id="user-form" onSubmit={submit} className="space-y-4">
          <Input label="Full name" required value={form.name} onChange={setField('name')} error={fieldErrors.name} placeholder="Jane Doe" />
          <Input label="Email" type="email" required value={form.email} onChange={setField('email')} error={fieldErrors.email} placeholder="jane@example.com" />
          <Input
            label={editing ? 'New password' : 'Password'}
            type="password"
            required={!editing}
            value={form.password}
            onChange={setField('password')}
            error={fieldErrors.password}
            placeholder={editing ? 'Leave blank to keep current' : 'At least 6 characters'}
            autoComplete="new-password"
          />
          <div className="grid grid-cols-2 gap-4">
            <Select label="Role" value={form.role} onChange={setField('role')} error={fieldErrors.role}>
              {roles.length === 0 && <option value="">viewer</option>}
              {roles.map((r) => <option key={r} value={r}>{r}</option>)}
            </Select>
            <Select label="Status" value={form.status} onChange={setField('status')} error={fieldErrors.status}>
              <option value="active">active</option>
              <option value="suspended">suspended</option>
            </Select>
          </div>
        </form>
      </Modal>

      <ConfirmDialog
        open={!!deleting}
        onClose={() => setDeleting(null)}
        onConfirm={confirmDelete}
        title="Delete user"
        confirmText="Delete"
        loading={deleteBusy}
        message={deleting ? `Permanently delete ${deleting.name} (${deleting.email})? This cannot be undone.` : ''}
      />
    </div>
  );
}
