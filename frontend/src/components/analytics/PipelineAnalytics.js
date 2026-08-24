// The maintenance pipeline chart strip. Three questions a manager asks about a shop they are not
// going to scroll through car by car:
//
//   1. Pipeline by stage   → the funnel profile, in journey order (not ranked —
//                            the order IS the process, so it must not be re-sorted)
//   2. Longest in the workshop → the specific cars that have stalled at the garage
//   3. Who's holding it    → load per responsible role
//
// Everything derives from the lanes it is handed, so it can never disagree with the board those
// lanes came from. Rendered on the Dashboard by PipelinePanel.

import { useMemo } from 'react';
import { SectionCard } from '../ui/Table';
import RankedBar from '../ui/RankedBar';
import PieChart from '../ui/PieChart';
import { fmtDuration, stageSeconds } from '../workflow/meta';
import { num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

// Role → the chart palette key matching its chip colour on the cards below.
const ROLE_COLOR = {
  inspector: 'amber',
  supervisor: 'purple',
  driver: 'blue',
  garage: 'orange',
  none: 'slate',
};
const ROLE_LABEL = {
  inspector: 'Inspector',
  supervisor: 'Supervisor',
  driver: 'Driver',
  garage: 'Garage',
  none: 'On hold',
};

// The lane that IS the workshop — the only one where "how long has it sat here" measures real repair
// dwell time rather than a handover step. This is the board COLUMN key (see config/maintenanceLanes.js),
// which is stable; the lane's display name is not.
const WORKSHOP_LANE = 'under_repair';

export default function PipelineAnalytics({ lanes = [] }) {
  const { t } = useI18n();

  // The funnel profile — kept in the lanes' own order, because that order is the
  // repair journey. Sorting it by size would destroy the meaning.
  const byStage = useMemo(
    () =>
      lanes.map((l) => ({
        key: l.key,
        label: l.name,
        value: l.tickets.length,
        color: l.tone,
        role: ROLE_LABEL[l.role] || l.role,
      })),
    [lanes],
  );

  // The cars that have sat longest AT THE GARAGE. Deliberately scoped to the In-Workshop lane: the
  // other lanes are transitional (a car is "in transit" or "awaiting pickup" for hours, not weeks),
  // so ranking them together buried the repairs that are genuinely stuck behind short-lived handover
  // steps. Time in the workshop lane IS the repair's dwell time — the number worth chasing a garage over.
  const stalled = useMemo(() => {
    const workshop = lanes.find((l) => l.key === WORKSHOP_LANE);
    return (workshop?.tickets || [])
      .map((tk) => ({
        key: tk.id,
        label: tk.plate || `#${tk.id}`,
        sub: tk.ops?.responsibility?.garage || workshop.name,
        to: `/maintenance-workflow/${tk.id}`,
        value: stageSeconds(tk) ?? 0,
        color: workshop.tone,
        car: tk.car,
      }))
      .sort((a, b) => b.value - a.value)
      .slice(0, 8);
  }, [lanes]);

  // Load per responsible role — who the pipeline is currently waiting on.
  const byRole = useMemo(() => {
    const totals = {};
    lanes.forEach((l) => {
      if (!l.tickets.length) return;
      totals[l.role] = (totals[l.role] || 0) + l.tickets.length;
    });
    return Object.entries(totals)
      .map(([role, value]) => ({ label: t(ROLE_LABEL[role] || role), value, color: ROLE_COLOR[role] || 'slate' }))
      .sort((a, b) => b.value - a.value);
  }, [lanes, t]);

  const total = byStage.reduce((n, s) => n + s.value, 0);
  if (!total) return null;

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        title={t('Pipeline by stage')}
        subtitle={t('Cars at each step, in journey order')}
        bodyClass="p-5"
      >
        <RankedBar
          items={byStage}
          format={(n) => num(Math.round(n))}
          valueLabel={t('Cars')}
          labelWidth={128}
          valueWidth={44}
          tooltip={(r) => t('Owned by {role}', { role: t(r.role) })}
          empty={t('No cars in the pipeline.')}
        />
      </SectionCard>

      <SectionCard
        title={t('Longest in the workshop')}
        subtitle={t('Cars that have stalled at the garage')}
        bodyClass="p-5"
      >
        <RankedBar
          items={stalled}
          showRank
          format={fmtDuration}
          valueLabel={t('At garage')}
          labelWidth={116}
          valueWidth={68}
          tooltip={(r) => [r.car, r.sub].filter(Boolean).join(' · ')}
          empty={t('No cars are in the workshop right now.')}
        />
      </SectionCard>

      <SectionCard
        title={t("Who's holding the work")}
        subtitle={t('Open cars by responsible role')}
        bodyClass="flex items-center justify-center p-5"
      >
        {byRole.length ? (
          <PieChart segments={byRole} size={150} />
        ) : (
          <div className="flex h-[150px] items-center justify-center text-sm text-slate-400">
            {t('Nobody has work assigned.')}
          </div>
        )}
      </SectionCard>
    </div>
  );
}
