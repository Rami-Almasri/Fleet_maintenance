// Complaints Center — the management & follow-up surface for the first-class Customer Complaint entity. A
// filterable table + KPI roll-up over every complaint, each row opening the same timeline drawer the
// vehicle's Complaint History tab uses. Intake (Ops) happens here via the "Log complaint" modal; triage
// (contact / decide / send-in / resolve / close) happens in the detail drawer.
//
// The list + KPIs come from GET /complaints (computed server-side once); status / search / assignee / date
// filtering is applied client-side for instant response, with the status-tab counts read from the KPI
// by_status map so they always reflect the full set.

import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useParams, useNavigate } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import DataTable, { SectionCard } from '../components/ui/Table';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import ComplaintDetailDrawer from '../components/complaints/ComplaintDetailDrawer';
import ComplaintIntakeModal from '../components/workflow/ComplaintIntakeModal';
import { usePermissions } from '../hooks/usePermissions';
import { useToast } from '../components/ui/Toast';
import Button from '../components/ui/Button';
import { STAGE_ORDER, STAGE_META } from '../components/complaints/stages';
import ComplaintsAnalytics from '../components/analytics/ComplaintsAnalytics';
import { useI18n } from '../i18n/I18nContext';

// Module-level word helpers take the resolver as an argument — the hook is only callable in a component.
function ago(iso, t) {
  if (!iso) return '—';
  const secs = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
  if (secs < 90) return t('just now');
  const mins = Math.round(secs / 60);
  if (mins < 60) return t('{n}m ago', { n: mins });
  const hrs = Math.round(mins / 60);
  if (hrs < 24) return t('{n}h ago', { n: hrs });
  const days = Math.round(hrs / 24);
  return t('{n}d ago', { n: days });
}

// Gregorian calendar + Latin digits under Arabic — a Hijri/Arabic-Indic date would not line up with the
// rest of the table and is not what this fleet reads.
function fmtDate(iso, lang) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleDateString(lang === 'ar' ? 'ar-AE-u-ca-gregory-nu-latn' : undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
}

const SEV_DOT = { critical: 'bg-rose-500', high: 'bg-orange-500', moderate: 'bg-amber-500', routine: 'bg-emerald-500' };

export default function ComplaintsCenter() {
  const { can } = usePermissions();
  const { t, lang } = useI18n();
  const toast = useToast();
  const navigate = useNavigate();
  const { id: routeId } = useParams();
  const [openId, setOpenId] = useState(routeId ? Number(routeId) : null);

  // Deep link (/complaints/:id from a notification) → open that complaint's drawer.
  useEffect(() => {
    if (routeId) setOpenId(Number(routeId));
  }, [routeId]);
  const [stage, setStage] = useState('__all');
  const [q, setQ] = useState('');
  const [assignee, setAssignee] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [intakeOpen, setIntakeOpen] = useState(false);
  const [vehicles, setVehicles] = useState([]);

  const fetcher = useCallback(async () => (await api.get('/complaints')).data.data, []);
  const { data, loading, error, reload } = useFetch(fetcher, [], {
    refreshInterval: 15000,
    paused: () => !!openId || intakeOpen,
  });

  // Vehicles for the intake picker — the modal narrows to rented cars itself.
  useEffect(() => {
    if (!can('maintenance.manage')) return undefined;
    let alive = true;
    api.get('/Vehicle')
      .then((v) => {
        if (!alive) return;
        const list = v.data?.data;
        const all = Array.isArray(list) ? list : list?.items || [];
        setVehicles(all.filter((veh) => ['ready', 'rented'].includes(veh.status)));
      })
      .catch(() => { /* picker stays empty */ });
    return () => { alive = false; };
  }, [can]);

  const rows = useMemo(() => data?.rows || [], [data]);
  const kpis = useMemo(() => data?.kpis || {}, [data]);

  // Assignee options — everyone who has handled a complaint (from the loaded set).
  const assignees = useMemo(() => {
    const map = new Map();
    rows.forEach((r) => { if (r.assigned_id && r.assigned) map.set(r.assigned_id, r.assigned); });
    return Array.from(map, ([id, name]) => ({ id, name }));
  }, [rows]);

  const filtered = useMemo(() => {
    const needle = q.trim().toLowerCase();
    return rows.filter((r) => {
      if (stage !== '__all' && r.status !== stage) return false;
      if (assignee && String(r.assigned_id) !== String(assignee)) return false;
      if (from && (!r.created_at || r.created_at.slice(0, 10) < from)) return false;
      if (to && (!r.created_at || r.created_at.slice(0, 10) > to)) return false;
      if (needle) {
        const hay = [r.plate, r.car, r.customer, r.complaint].filter(Boolean).join(' ').toLowerCase();
        if (!hay.includes(needle)) return false;
      }
      return true;
    });
  }, [rows, stage, assignee, from, to, q]);

  const stageTabs = useMemo(() => {
    const byStage = kpis.by_status || {};
    return [
      { key: '__all', label: t('All'), emoji: '📋', count: kpis.total || 0 },
      ...STAGE_ORDER.map((s) => ({ key: s, label: STAGE_META[s].label, emoji: STAGE_META[s].emoji, count: byStage[s] || 0 })),
    ];
  }, [kpis, t]);

  const columns = [
    {
      key: 'id',
      header: t('Complaint'),
      render: (r) => (
        <div className="flex items-center gap-2">
          <span className={`h-2 w-2 shrink-0 rounded-full ${SEV_DOT[r.severity] || 'bg-slate-300'}`} title={r.severity || t('ungraded')} />
          <span className="font-mono text-sm font-semibold text-slate-700">#{r.id}</span>
        </div>
      ),
    },
    {
      key: 'vehicle',
      header: t('Vehicle'),
      render: (r) => (
        <div className="min-w-0">
          <Link to={`/vehicles/${r.vehicle_id}`} onClick={(e) => e.stopPropagation()} className="font-mono text-sm font-bold text-slate-800 hover:text-indigo-600">
            {r.plate || `#${r.vehicle_id}`}
          </Link>
          {r.car && <p className="truncate text-xs text-slate-400">{r.car}</p>}
        </div>
      ),
    },
    {
      key: 'customer',
      header: t('Customer'),
      render: (r) => (
        <div className="min-w-0">
          <p className="truncate text-sm text-slate-700">{r.customer || <span className="text-slate-300">—</span>}</p>
          {r.complaint && <p className="truncate text-xs italic text-slate-400" title={r.complaint}>“{r.complaint}”</p>}
        </div>
      ),
    },
    {
      key: 'created_at',
      header: t('Reported'),
      render: (r) => (
        <span className="whitespace-nowrap text-xs text-slate-500" title={fmtDate(r.created_at, lang)}>{ago(r.created_at, t)}</span>
      ),
    },
    {
      key: 'status',
      header: t('Status'),
      render: (r) => {
        const m = STAGE_META[r.status] || { label: r.status, emoji: '•', tone: 'slate' };
        return <Badge tone={m.tone}>{m.emoji} {m.label}</Badge>;
      },
    },
    {
      key: 'assigned',
      header: t('Assigned'),
      render: (r) => (r.assigned ? <span className="text-sm text-slate-600">{r.assigned}</span> : <span className="text-xs text-slate-300">{t('Unassigned')}</span>),
    },
  ];

  return (
    <div className="opx py-8">
      <div className="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
        {/* Header */}
        <div className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <div className="opx-hint" style={{ letterSpacing: '.16em', textTransform: 'uppercase', marginBottom: 6 }}>{t('Customer Care')}</div>
            <h1 className="font-display" style={{ fontSize: 24, fontWeight: 700, letterSpacing: '-.02em', color: 'var(--ink)', margin: 0 }}>{t('Complaints Center')}</h1>
            <p style={{ marginTop: 6, fontSize: 13.5, color: 'var(--ink-3)' }}>{t('Every customer complaint, its lifecycle, and what happened after we reached out.')}</p>
          </div>
          {can('maintenance.manage') && (
            <Button onClick={() => setIntakeOpen(true)}>
              <Icon.Plus className="h-4 w-4" /> {t('Log complaint')}
            </Button>
          )}
        </div>

        {/* KPIs */}
        <MetricGrid cols={4}>
          <MetricCard label={t('Total complaints')} value={kpis.total ?? '—'} icon={<span aria-hidden>📣</span>} tone="indigo" loading={loading} />
          <MetricCard label={t('Open')} value={kpis.open ?? '—'} icon={<Icon.Clock className="h-4 w-4" />} tone="amber" hint={t('Not yet resolved')} loading={loading} />
          <MetricCard label={t('Became maintenance')} value={kpis.converted ?? '—'} icon={<Icon.Wrench className="h-4 w-4" />} tone="violet" hint={kpis.conversion_rate != null ? t('{pct}% of complaints', { pct: kpis.conversion_rate }) : undefined} loading={loading} />
          <MetricCard label={t('Median time to close')} value={kpis.median_hours != null ? t('{n}h', { n: kpis.median_hours }) : '—'} icon={<Icon.Activity className="h-4 w-4" />} tone="emerald" hint={t('Logged → resolved')} loading={loading} />
        </MetricGrid>

        {error && <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{error}</div>}

        {/* Stage tabs */}
        <div className="flex flex-wrap gap-1.5">
          {stageTabs.map((tb) => {
            const active = stage === tb.key;
            return (
              <button
                key={tb.key}
                type="button"
                onClick={() => setStage(tb.key)}
                className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition ring-1 ring-inset ${active ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50'}`}
              >
                <span aria-hidden>{tb.emoji}</span> {tb.label}
                <span className={`rounded-full px-1.5 text-[10px] font-bold ${active ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-500'}`}>{tb.count}</span>
              </button>
            );
          })}
        </div>

        {/* Analytics — the filtered set, matching the table below. */}
        {!loading && filtered.length > 0 && <ComplaintsAnalytics rows={filtered} />}

        {/* Table + secondary filters */}
        <SectionCard
          title={t('Complaints')}
          subtitle={t('{n} shown', { n: filtered.length })}
          actions={(
            <div className="flex flex-wrap items-center gap-2">
              <div className="relative">
                <Icon.Search className="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
                <input
                  value={q}
                  onChange={(e) => setQ(e.target.value)}
                  placeholder={t('Search car / customer…')}
                  className="w-44 rounded-lg border border-slate-200 py-1.5 ps-8 pe-2 text-sm outline-none focus:border-indigo-400"
                />
              </div>
              <select value={assignee} onChange={(e) => setAssignee(e.target.value)} className="rounded-lg border border-slate-200 py-1.5 px-2 text-sm text-slate-600 outline-none focus:border-indigo-400">
                <option value="">{t('All assignees')}</option>
                {assignees.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
              </select>
              <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} title={t('From')} className="rounded-lg border border-slate-200 py-1.5 px-2 text-sm text-slate-600 outline-none focus:border-indigo-400" />
              <input type="date" value={to} onChange={(e) => setTo(e.target.value)} title={t('To')} className="rounded-lg border border-slate-200 py-1.5 px-2 text-sm text-slate-600 outline-none focus:border-indigo-400" />
              {(q || assignee || from || to || stage !== '__all') && (
                <button type="button" onClick={() => { setQ(''); setAssignee(''); setFrom(''); setTo(''); setStage('__all'); }} className="rounded-lg px-2 py-1.5 text-xs font-semibold text-slate-500 hover:bg-slate-100">
                  {t('Clear')}
                </button>
              )}
            </div>
          )}
        >
          <DataTable
            columns={columns}
            rows={filtered}
            rowKey={(r) => r.id}
            loading={loading}
            onRowClick={(r) => setOpenId(r.id)}
            empty={t('No complaints match these filters.')}
          />
        </SectionCard>
      </div>

      <ComplaintDetailDrawer
        id={openId}
        open={!!openId}
        onChanged={() => reload({ silent: true })}
        onClose={() => { setOpenId(null); if (routeId) navigate('/complaints', { replace: true }); reload({ silent: true }); }}
      />

      {intakeOpen && (
        <ComplaintIntakeModal
          vehicles={vehicles}
          onClose={() => setIntakeOpen(false)}
          onDone={(msg) => { setIntakeOpen(false); toast.success(msg || t('Complaint logged')); reload({ silent: true }); }}
        />
      )}
    </div>
  );
}
