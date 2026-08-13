import { useCallback, useMemo } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import Badge from '../../components/ui/Badge';
import Icon from '../../components/ui/Icon';
import { EmptyState } from '../../components/ui/Misc';
import { MetricGridSkeleton } from '../../components/ui/Skeleton';
import { fmtDate } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';
import usePanelFilter from './usePanelFilter';

// Inspection status → tone + shared urgency bucket.
const STATUS = {
  overdue:  { tone: 'red',    bucket: 'urgent' },
  due_soon: { tone: 'amber',  bucket: 'attention' },
  ok:       { tone: 'green',  bucket: 'ok' },
  no_data:  { tone: 'slate',  bucket: 'ok' },
};

// "Next due in" — days_remaining can be negative (overdue) or null (no baseline yet).
// `t` is threaded in because this helper returns words and lives outside a component body.
function nextDue(days, t) {
  if (days == null) return { text: t('No schedule'), tone: 'slate' };
  if (days < 0) return { text: t('{n}d overdue', { n: Math.abs(days) }), tone: 'red' };
  if (days === 0) return { text: t('Due today'), tone: 'red' };
  if (days <= 7) return { text: t('In {n}d', { n: days }), tone: 'amber' };
  return { text: t('In {n}d', { n: days }), tone: 'green' };
}

// Card optimised for compliance: [Vehicle] | [Last Inspected] | [Next Due In] | [Start Inspection].
function ReadinessInspectionCard({ c }) {
  const { t } = useI18n();
  const due = nextDue(c.daysRemaining, t);
  return (
    <div className="flex flex-col rounded-2xl border border-slate-200/60 bg-white p-4 shadow-soft">
      <div className="flex items-start justify-between gap-2">
        <p className="truncate text-sm font-semibold text-slate-800" title={c.vehicle}>{c.vehicle}</p>
        <Badge tone={c.statusTone}>{c.statusLabel}</Badge>
      </div>
      <p className="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500">
        <Icon.Shield className="h-3.5 w-3.5 shrink-0 text-slate-400" />
        <span className="truncate">{c.name}</span>
      </p>

      <dl className="mt-3 grid grid-cols-2 gap-3 text-xs">
        <div>
          <dt className="text-slate-400">{t('Last inspected')}</dt>
          <dd className="mt-0.5 font-medium text-slate-700">{c.lastInspected ? fmtDate(c.lastInspected) : '—'}</dd>
        </div>
        <div>
          <dt className="text-slate-400">{t('Next due')}</dt>
          <dd className="mt-0.5"><Badge tone={due.tone}>{due.text}</Badge></dd>
        </div>
      </dl>

      <Link
        to={`/vehicles/${c.vehicleId}`}
        className="mt-4 inline-flex items-center justify-center gap-1.5 rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100"
      >
        <Icon.Search className="h-3.5 w-3.5" /> {t('Start Inspection')}
      </Link>
    </div>
  );
}

/**
 * Tab 2 — Readiness & Inspection. Compliance intervals from GET /InspectionSchedules: when each car
 * was last inspected, when it's next due, and a jump-off to start the inspection on the car's profile.
 */
export default function ReadinessInspectionPanel({ search, filter, onStats }) {
  const { t } = useI18n();
  const fetcher = useCallback(async () => (await api.get('/InspectionSchedules')).data.data || [], []);
  const { data, loading, error } = useFetch(fetcher, [], { refreshInterval: 60000 });

  const items = useMemo(() => (data || []).map((s) => {
    const meta = STATUS[s.status] || STATUS.no_data;
    const vehicle = s.vehicle?.label || [s.vehicle?.make, s.vehicle?.model].filter(Boolean).join(' ') || t('Vehicle #{id}', { id: s.vehicle_id });
    return {
      id: s.id,
      vehicleId: s.vehicle_id,
      vehicle,
      name: s.name || s.pillar || t('Inspection'),
      lastInspected: s.last_inspected_at,
      daysRemaining: s.days_remaining,
      statusLabel: s.status_label || s.status,
      statusTone: meta.tone,
      bucket: meta.bucket,
      haystack: [vehicle, s.name, s.pillar, s.status_label].filter(Boolean).join(' ').toLowerCase(),
    };
  }), [data, t]);

  const shown = usePanelFilter(items, { search, filter, onStats });

  if (loading && !items.length) return <MetricGridSkeleton count={6} />;
  if (error) {
    return <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>;
  }
  if (!shown.length) {
    return (
      <EmptyState
        icon={<Icon.Shield className="h-7 w-7" />}
        title={items.length ? t('No inspections match') : t('No inspection schedules')}
        message={items.length ? t('Try clearing the search or filter.') : t('No cars are on an inspection interval yet.')}
      />
    );
  }

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
      {shown.map((c) => <ReadinessInspectionCard key={c.id} c={c} />)}
    </div>
  );
}
