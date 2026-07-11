import { useCallback, useMemo } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import DataTable, { SectionCard } from '../ui/Table';
import Badge from '../ui/Badge';
import { InfoTip } from '../ui/Tooltip';
import Icon from '../ui/Icon';

// "Cars in Maintenance" — a self-contained card listing every car currently in the shop, overdue ones
// first. Derived from the live /Fleet/life-status feed so it stays in lock-step with the fleet's real
// state. Drop it on any page; it fetches its own data and polls every 60s.

// The life-status chips that mean "this car is in the shop" (see VehicleLifeStreamService::chipFor).
const MAINTENANCE_CHIPS = {
  in_garage:     { label: 'In workshop',    tone: 'amber' },
  grounded:      { label: 'Grounded',       tone: 'red' },
  waiting_parts: { label: 'Waiting parts',  tone: 'orange' },
};

// A car sitting in one maintenance stage longer than this many days is flagged Overdue.
const MAINTENANCE_OVERDUE_DAYS = 3;

// Explains the current (interim) overdue rule — surfaced as an (i) tooltip + a quiet sub-note so
// management sees the threshold is a deliberate first step toward data-driven SLAs, not a fixed limit.
const OVERDUE_NOTE =
  `Note: Overdue status is currently set to a ${MAINTENANCE_OVERDUE_DAYS}-day threshold. This will transition ` +
  `to dynamic, data-driven SLAs as we accumulate historical data on repair durations for each fault type.`;

// Is this in-shop car overdue? Overdue = grounded, stalled waiting for parts, or simply sat in its
// current maintenance stage past the threshold. days_in_status is server-computed (clock-skew-proof).
function maintenanceOverdue(v) {
  if (v.status_key === 'grounded' || v.status_key === 'waiting_parts') return true;
  return v.days_in_status != null && v.days_in_status >= MAINTENANCE_OVERDUE_DAYS;
}

// Next-step pill colours — mirror the backend next_step.tone vocabulary.
const STEP_TONE = {
  amber:  'bg-amber-50 text-amber-700 ring-amber-600/20 hover:bg-amber-100',
  violet: 'bg-violet-50 text-violet-700 ring-violet-600/20 hover:bg-violet-100',
  red:    'bg-red-50 text-red-700 ring-red-600/20 hover:bg-red-100',
  blue:   'bg-blue-50 text-blue-700 ring-blue-600/20',
  green:  'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  gray:   'bg-slate-100 text-slate-500 ring-slate-300/50',
};

// One actionable pill → links straight to the exact ticket/page where the step is performed.
function StepPill({ label, tone, href }) {
  const cls = STEP_TONE[tone] || STEP_TONE.gray;
  return (
    <Link
      to={href}
      onClick={(e) => e.stopPropagation()}
      className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset transition ${cls}`}
      title="Go to where this step is performed"
    >
      {label} <Icon.ArrowRight className="h-3 w-3" />
    </Link>
  );
}

// The "do this next" cell. A decision gate branches two ways, so we render BOTH outcome pills; a
// single-action stage renders one; a non-actionable stage is a quiet pill.
function NextStepButton({ step }) {
  if (!step) return <span className="text-slate-300">—</span>;
  if (Array.isArray(step.options) && step.options.length) {
    return (
      <div className="flex flex-wrap items-center gap-1.5">
        {step.options.map((o) => <StepPill key={o.label} label={o.label} tone={o.tone} href={o.href} />)}
      </div>
    );
  }
  if (!step.actionable || !step.href) {
    return (
      <span className={`inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${STEP_TONE[step.tone] || STEP_TONE.gray}`}>
        {step.label}
      </span>
    );
  }
  return <StepPill label={step.label} tone={step.tone} href={step.href} />;
}

export default function MaintenanceCarsCard({ onRowClick }) {
  const navigate = useNavigate();

  const fetcher = useCallback(async () => (await api.get('/Fleet/life-status')).data.data, []);
  const { data: fleet, error } = useFetch(fetcher, [], { refreshInterval: 60000 });

  // Cars currently in the shop — everything on a maintenance chip — overdue ones first, then longest-sitting.
  const maintenanceCars = useMemo(() => {
    return (fleet?.vehicles || [])
      .filter((v) => MAINTENANCE_CHIPS[v.status_key])
      .sort(
        (a, b) =>
          (maintenanceOverdue(b) ? 1 : 0) - (maintenanceOverdue(a) ? 1 : 0) ||
          (b.days_in_status || 0) - (a.days_in_status || 0),
      );
  }, [fleet]);
  const overdueCount = useMemo(() => maintenanceCars.filter(maintenanceOverdue).length, [maintenanceCars]);

  // Default row action: open the car's profile. Callers can override (e.g. to follow the car elsewhere).
  const handleRowClick = onRowClick || ((v) => v.vehicle_id && navigate(`/vehicles/${v.vehicle_id}`));

  const columns = [
    {
      key: 'vehicle',
      header: 'Vehicle',
      cellClass: 'align-top',
      render: (v) => (
        <button
          type="button"
          onClick={(ev) => { ev.stopPropagation(); handleRowClick(v); }}
          className="text-left"
          title="Open this car"
        >
          <span className="font-semibold text-slate-900 hover:text-indigo-600">{v.plate || `#${v.vehicle_id}`}</span>
          {v.model && <span className="block text-xs text-slate-400">{v.model}</span>}
        </button>
      ),
    },
    {
      key: 'stage',
      header: 'In shop for',
      cellClass: 'align-top',
      render: (v) => {
        const chip = MAINTENANCE_CHIPS[v.status_key] || { label: v.status_label, tone: v.status_tone };
        return (
          <div className="flex flex-col gap-1">
            <Badge tone={chip.tone}>{chip.label}</Badge>
            {v.stage_label && v.stage_label !== chip.label && (
              <span className="text-xs text-slate-500">{v.stage_label}</span>
            )}
          </div>
        );
      },
    },
    {
      key: 'age',
      header: 'Days in shop',
      cellClass: 'align-top whitespace-nowrap',
      render: (v) => {
        const overdue = maintenanceOverdue(v);
        return (
          <div className="flex flex-col gap-1">
            <span className={`text-sm font-semibold ${overdue ? 'text-red-600' : 'text-slate-700'}`}>
              {v.days_in_status != null ? `${v.days_in_status}d` : '—'}
            </span>
            {overdue
              ? <Badge tone="red">⚠ Overdue</Badge>
              : <Badge tone="green">On track</Badge>}
          </div>
        );
      },
    },
    {
      key: 'owner',
      header: 'Owner',
      cellClass: 'align-top whitespace-nowrap text-sm text-slate-600',
      render: (v) => v.owner || '—',
    },
    {
      key: 'next_step',
      header: 'Next Step',
      cellClass: 'align-top',
      render: (v) => (
        <div className="flex flex-col gap-1">
          <NextStepButton step={v.next_step} />
          {v.ticket_id && (
            <Link
              to={`/maintenance-workflow/${v.ticket_id}`}
              onClick={(e) => e.stopPropagation()}
              className="text-xs font-medium text-indigo-500 hover:text-indigo-600"
            >
              Ticket #{v.ticket_id}
            </Link>
          )}
        </div>
      ),
    },
  ];

  // Hide the card entirely on error so it never shows a broken shell inside a host page.
  if (error) return null;

  return (
    <SectionCard
      title="Cars in Maintenance"
      subtitle={
        maintenanceCars.length
          ? `${maintenanceCars.length} ${maintenanceCars.length === 1 ? 'car is' : 'cars are'} in the shop${overdueCount ? ` — ${overdueCount} overdue (${MAINTENANCE_OVERDUE_DAYS}+ days, grounded or waiting on parts)` : ''}.`
          : 'Cars currently in the workshop, and whether any are overdue.'
      }
      actions={
        maintenanceCars.length ? (
          <span className="inline-flex items-center gap-1.5">
            {overdueCount
              ? <Badge tone="red">{overdueCount} overdue</Badge>
              : <Badge tone="green">All on track</Badge>}
            <InfoTip content={OVERDUE_NOTE} side="bottom" />
          </span>
        ) : null
      }
    >
      <DataTable
        columns={columns}
        rows={maintenanceCars}
        rowKey={(v) => v.vehicle_id}
        onRowClick={handleRowClick}
        empty="No cars are in the workshop right now."
      />
      {maintenanceCars.length > 0 && (
        <p className="border-t border-slate-100 px-5 py-3 text-xs italic leading-relaxed text-slate-400">
          {OVERDUE_NOTE}
        </p>
      )}
    </SectionCard>
  );
}
