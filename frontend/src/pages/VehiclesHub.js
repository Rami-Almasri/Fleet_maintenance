import { useMemo } from 'react';
import TabbedHub from '../components/ui/TabbedHub';
import Icon from '../components/ui/Icon';
import { useI18n } from '../i18n/I18nContext';
import Vehicles from './Vehicles';
import CompletedRepairs from './CompletedRepairs';
import MaintenanceHistory from './MaintenanceHistory';

/**
 * Vehicles — the car, and everything already done to it, behind one tab strip.
 *
 *   • Fleet Registry     → every car we own: live movement, condition, odometer, paperwork
 *   • Completed Repairs  → the signed-off ledger: who requested it, who drove it, where it was
 *                          fixed, what was found and what it cost
 *   • History            → how often each car saw the workshop and how long it stayed, trip by trip
 *
 * The last two used to be the Repair Records hub (/repair-records), a separate destination in the
 * Maintenance module. It is retired: the question those two tabs answer — "what has this fleet
 * actually had done to it?" — is a question about the CARS, and asking it meant leaving the car list
 * to go and find another page. Both old routes redirect in here on their own tab.
 *
 * ACCESS — the three tabs carry two different permissions (vehicles.view for the registry,
 * maintenance.view for the two ledgers) and, more importantly, three different deny rules: the
 * driver keeps the car list but is denied both ledgers, and the inspector is denied the signed-off
 * ledger while keeping the per-car history he reads before judging a car. So the ROUTE is left
 * ungated — exactly as /control-desk is — and TabbedHub filters tab by tab, each declaring the route
 * it replaced so those deny rules keep biting after the move. /vehicles/:id keeps its own
 * vehicles.view gate: a car's profile is not a tab of this page.
 */
export default function VehiclesHub() {
  const { t } = useI18n();

  const tabs = useMemo(
    () => [
      { key: 'registry', label: t('vehicles.registry'), icon: <Icon.Car className="h-4 w-4" />, permission: 'vehicles.view', was: '/vehicles', Component: Vehicles },
      { key: 'signed-off', label: t('Completed Repairs'), icon: <Icon.Check className="h-4 w-4" />, permission: 'maintenance.view', was: '/completed-repairs', Component: CompletedRepairs },
      { key: 'per-car', label: t('History'), icon: <Icon.Clock className="h-4 w-4" />, permission: 'maintenance.view', was: '/maintenance-history', Component: MaintenanceHistory },
    ],
    [t],
  );

  return (
    <TabbedHub
      title={t('Vehicles')}
      subtitle={t('Every car in the fleet, and the finished work behind it — the signed-off ledger of what was fixed and what it cost, and each car’s workshop history, trip by trip.')}
      ariaLabel={t('Vehicle sections')}
      tabs={tabs}
    />
  );
}
