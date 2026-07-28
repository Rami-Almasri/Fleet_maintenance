// CycleGuide — the "How the cycle works" flow map shown inline on the Maintenance Cycle board.
//
// It is DOCUMENTATION GENERATED FROM THE SOURCE OF TRUTH, not a hand-drawn diagram: the stages come
// from the board's own PRIMARY_LANES (passed in as `lanes`, so it shows live per-stage counts too), and
// the owner + the action that advances each stage are read from the same ACTION metadata (meta.js) the
// board itself uses to render every card's primary button. Change the workflow and this map follows —
// it can never drift out of sync with real behaviour.

import { useI18n } from '../../i18n/I18nContext';
import Icon from '../ui/Icon';
import { ACTION } from './meta';

// Board lane key → the representative workflow_status used to look ACTION up. The board's column keys
// intentionally differ from the raw statuses in a few places (see PRIMARY_LANES in MaintenanceWorkflow.js).
const LANE_STATUS = {
  requested: 'inspection_requested',
  diagnostic: 'inspection_diagnostic',
  pending: 'inspection_pending',
  awaiting_pickup: 'awaiting_dispatch',
  in_transit: 'in_transit',
  under_repair: 'under_repair',
  ready_for_pickup: 'ready_for_pickup',
  qa_reinspection: 'ready_for_reinspection',
};

// ready_for_pickup has no static ACTION entry (resolveAction derives it dynamically per return leg) —
// its first advancing action is the driver collecting the car from the garage.
const DYNAMIC_ACTION = {
  ready_for_pickup: { action: 'collectFromGarage', perm: 'maintenance.logistics' },
};

// Which permission → which operator role owns the advancing step.
const ACTOR_KEY = {
  'maintenance.initiate': 'inspector',
  'maintenance.delegate': 'supervisor',
  'maintenance.logistics': 'driver',
  'maintenance.manage': 'manager',
};

// The physical repair at "In Workshop" is the garage's work (a driver/logistics user only marks it
// ready) — surface the garage as the owner so the map reads truthfully.
const ACTOR_OVERRIDE = { under_repair: 'garage' };

export default function CycleGuide({ lanes = [], onClose }) {
  const { t } = useI18n();

  const actorNames = (status, perm) => {
    const override = ACTOR_OVERRIDE[status];
    const keys = override ? [override] : (Array.isArray(perm) ? perm : [perm]).map((p) => ACTOR_KEY[p]).filter(Boolean);
    return [...new Set(keys)].map((k) => t(`workflow.cycle.actors.${k}`)).join(' / ');
  };

  const steps = lanes.map((lane) => {
    const status = LANE_STATUS[lane.key];
    const act = DYNAMIC_ACTION[lane.key] || ACTION[status];
    return {
      key: lane.key,
      name: lane.name,
      tone: lane.tone,
      hint: lane.hint,
      count: (lane.tickets || []).length,
      actor: act ? actorNames(status, act.perm) : null,
      advance: act ? t(`workflow.cardAction.${act.action}`) : null,
    };
  });

  return (
    <div className="mwf-cycle">
      <div className="mwf-cycle-hd">
        <div>
          <h3 className="mwf-cycle-title">{t('workflow.cycle.title')}</h3>
          <p className="mwf-cycle-sub">{t('workflow.cycle.subtitle')}</p>
        </div>
        <button type="button" className="mwf-cycle-x" onClick={onClose} aria-label="Close">
          <Icon.X className="h-4 w-4" />
        </button>
      </div>

      <div className="mwf-cycle-flow">
        {steps.map((s, i) => (
          <div key={s.key} className="mwf-cycle-step-wrap">
            <div className="mwf-cycle-step" title={s.hint}>
              <span className="mwf-cycle-accent" style={{ background: s.tone }} />
              <div className="mwf-cycle-step-hd">
                <span className="mwf-cycle-num" style={{ color: s.tone, background: `${s.tone}22` }}>{i + 1}</span>
                <span className="mwf-cycle-nm">{s.name}</span>
                <span className="mwf-cycle-ct" title={`${s.count} in this stage`}>{s.count}</span>
              </div>
              {s.actor && (
                <p className="mwf-cycle-actor">
                  <Icon.Users className="h-3 w-3" /> {s.actor}
                </p>
              )}
              {s.advance && (
                <p className="mwf-cycle-adv">
                  <span className="lbl">{t('workflow.cycle.advanceLabel')}</span> {s.advance}
                </p>
              )}
            </div>
            {i < steps.length - 1 && <Icon.ArrowRight className="mwf-cycle-arrow h-4 w-4" />}
          </div>
        ))}
      </div>

      <div className="mwf-cycle-loops">
        <span className="mwf-cycle-loops-title">{t('workflow.cycle.loopbacks.title')}</span>
        <ul>
          <li>↩ {t('workflow.cycle.loopbacks.qaFailed')}</li>
          <li>⏸ {t('workflow.cycle.loopbacks.paused')}</li>
          <li>🔧 {t('workflow.cycle.loopbacks.onsite')}</li>
        </ul>
      </div>
    </div>
  );
}
