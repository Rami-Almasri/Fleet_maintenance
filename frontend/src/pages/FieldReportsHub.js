import { useMemo } from 'react';
import TabbedHub from '../components/ui/TabbedHub';
import Icon from '../components/ui/Icon';
import { useI18n } from '../i18n/I18nContext';
import ComplaintsCenter from './ComplaintsCenter';
import DriverObservations from './DriverObservations';

/**
 * What people report about a car — the customer said it, or the driver noticed it.
 *
 *   • Complaints          → a customer's complaint and its follow-up timeline. Ops logs it, Abu
 *                           Maroof triages it from the drawer.
 *   • Driver observations → an internal handover note, escalated to an inspection only if it
 *                           deserves one.
 *
 * They are deliberately different entities (a complaint is first-class and is answered to the
 * customer; an observation is a lightweight note), so this hub puts them side by side without
 * merging them. /complaints/:id stays a route of its own — the drawer is deep-linked from
 * notifications and from a car's timeline.
 */
export default function FieldReportsHub() {
  const { t } = useI18n();

  const tabs = useMemo(
    () => [
      { key: 'complaints', label: t('Complaints'), icon: <Icon.Flag className="h-4 w-4" />, permission: 'maintenance.view', was: '/complaints', Component: ComplaintsCenter },
      { key: 'observations', label: t('Driver Observations'), icon: <Icon.Search className="h-4 w-4" />, permission: 'maintenance.view', was: '/driver-observations', Component: DriverObservations },
    ],
    [t],
  );

  return (
    <TabbedHub
      title={t('What people report')}
      subtitle={t('Everything someone told us about a car — a customer complaint with its follow-up, and the notes drivers leave at handover.')}
      ariaLabel={t('Report sections')}
      tabs={tabs}
    />
  );
}
