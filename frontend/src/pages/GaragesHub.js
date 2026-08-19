import { useMemo } from 'react';
import TabbedHub from '../components/ui/TabbedHub';
import Icon from '../components/ui/Icon';
import { useI18n } from '../i18n/I18nContext';
import Garages from './Garages';
import GarageFinder from './GarageFinder';
import InGarage from './InGarage';

/**
 * Garages — the three questions people actually ask about a workshop:
 *
 *   • Scorecard   → which garage is good at which repair, and where each one has a problem
 *   • Find a garage → this car has these faults; who should it go to? (read-only — it answers,
 *                     it does not dispatch)
 *   • In the garage now → every car standing at a workshop right now, and which one
 *
 * All three are maintenance.view. The scorecard is on several roles' deny list while the finder is
 * not, so each tab declares the route it replaced and TabbedHub honours the deny layer per tab.
 */
export default function GaragesHub() {
  const { t } = useI18n();

  const tabs = useMemo(
    () => [
      { key: 'scorecard', label: t('Scorecard'), icon: <Icon.Chart className="h-4 w-4" />, permission: 'maintenance.view', was: '/garages', Component: Garages },
      { key: 'finder', label: t('Find a garage'), icon: <Icon.Search className="h-4 w-4" />, permission: 'maintenance.view', was: '/garage-finder', Component: GarageFinder },
      { key: 'now', label: t('In the Garage'), icon: <Icon.Wrench className="h-4 w-4" />, permission: 'maintenance.view', was: '/in-garage', Component: InGarage },
    ],
    [t],
  );

  return (
    <TabbedHub
      title={t('Garages')}
      subtitle={t('Which garage is good at what, which one to send this car to, and which cars are standing at a workshop right now.')}
      ariaLabel={t('Garage sections')}
      tabs={tabs}
    />
  );
}
