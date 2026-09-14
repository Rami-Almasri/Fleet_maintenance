import { useMemo } from 'react';
import TabbedHub from '../components/ui/TabbedHub';
import Icon from '../components/ui/Icon';
import { useI18n } from '../i18n/I18nContext';
import Vehicles from './Vehicles';
import CostIntelligence from './CostIntelligence';
import FleetUtilization from './FleetUtilization';
import CompletedRepairs from './CompletedRepairs';
import MaintenanceHistory from './MaintenanceHistory';
import DamageAccidents from './DamageAccidents';
import { isFeatureEnabled } from '../config/features';

/**
 * Vehicles — the car, what it costs, what it earns, and everything already done to it, behind one
 * tab strip.
 *
 *   • Fleet Registry     → every car we own: live movement, condition, odometer, paperwork
 *   • Cost               → the true running cost per car: per kilometre, per day, per rental,
 *                          with the rental segments ranked by spend
 *   • Utilization        → each car's owned time split into rented, in-maintenance and idle days
 *   • Completed Repairs  → the signed-off ledger: who requested it, who drove it, where it was
 *                          fixed, what was found and what it cost
 *   • History            → how often each car saw the workshop and how long it stayed, trip by trip
 *   • Damage & Accidents → the imported damage log, as-is per car, coloured by who was liable
 *
 * Five of the six used to be separate destinations, scattered across four modules: Repair Records
 * (/repair-records) in Maintenance, Cost Intelligence (/cost-intelligence) in Finance, Fleet
 * Analytics (/fleet-utilization) in Fleet Intelligence, and the damage log (/damage-accidents) in
 * Damage Management. Every one of them is a per-car table keyed on the same fleet, and reading any
 * of them meant leaving the car list to go and find another page. All five old routes redirect in
 * here on their own tab.
 *
 * The damage log belongs here and NOT under Damage Management, because it is a READ of the
 * historical maintenance log — one more per-car ledger, like Completed Repairs. What stays under
 * Damage Management is /accidents: the live crash file with work waiting on somebody.
 *
 * The order is deliberate: what we own, then how each car performs, then what was done to it.
 *
 * ACCESS — the tabs carry three different permissions (vehicles.view for the registry,
 * maintenance.view for the two ledgers, insights.view for the two analytics boards) and, more
 * importantly, several different deny rules: the driver keeps the car list but is denied everything
 * else, and the inspector is denied the signed-off ledger and both analytics boards while keeping
 * the per-car history he reads before judging a car. So the ROUTE is left ungated — exactly as
 * /control-desk is — and TabbedHub filters tab by tab, each declaring the route it replaced so those
 * deny rules keep biting after the move. /vehicles/:id keeps its own vehicles.view gate: a car's
 * profile is not a tab of this page.
 *
 * Cost also ships behind SHOW_FLEET_INTELLIGENCE, as it did when it was its own route — folding a
 * page into a hub must not turn a dark feature on, so the flag is resolved here before TabbedHub
 * ever sees the tab.
 */
export default function VehiclesHub() {
  const { t } = useI18n();

  const tabs = useMemo(
    () => [
      { key: 'registry', label: t('vehicles.registry'), icon: <Icon.Car className="h-4 w-4" />, permission: 'vehicles.view', was: '/vehicles', Component: Vehicles },
      { key: 'cost', label: t('Cost Intelligence'), icon: <Icon.Chart className="h-4 w-4" />, permission: 'insights.view', flag: 'intel', was: '/cost-intelligence', Component: CostIntelligence },
      { key: 'utilization', label: t('Fleet Utilization'), icon: <Icon.Gauge className="h-4 w-4" />, permission: 'insights.view', was: '/fleet-utilization', Component: FleetUtilization },
      { key: 'signed-off', label: t('Completed Repairs'), icon: <Icon.Check className="h-4 w-4" />, permission: 'maintenance.view', was: '/completed-repairs', Component: CompletedRepairs },
      { key: 'per-car', label: t('History'), icon: <Icon.Clock className="h-4 w-4" />, permission: 'maintenance.view', was: '/maintenance-history', Component: MaintenanceHistory },
      { key: 'damage', label: t('Damage & Accidents'), icon: <Icon.Alert className="h-4 w-4" />, permission: 'maintenance.view', was: '/damage-accidents', Component: DamageAccidents },
    ].filter((tab) => isFeatureEnabled(tab.flag)),
    [t],
  );

  return (
    <TabbedHub
      title={t('Vehicles')}
      subtitle={t('Every car in the fleet — what it costs to run, how much of its owned time it earns, and the finished work behind it: the signed-off ledger of what was fixed, and each car’s workshop history, trip by trip.')}
      ariaLabel={t('Vehicle sections')}
      tabs={tabs}
    />
  );
}
