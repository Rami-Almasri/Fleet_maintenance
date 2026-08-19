import { useMemo } from 'react';
import TabbedHub from '../components/ui/TabbedHub';
import Icon from '../components/ui/Icon';
import { useI18n } from '../i18n/I18nContext';
import CompletedRepairs from './CompletedRepairs';
import MaintenanceHistory from './MaintenanceHistory';

/**
 * Repair records — the finished work, two ways of reading it.
 *
 *   • Signed off  → the completed-repair ledger: who requested it, who drove it, where it was
 *                   fixed, what was found and what it cost
 *   • Per car     → how often each car saw the workshop and how long it stayed, trip by trip
 *
 * The ledger is a control surface and is on some roles' deny list while the per-car history is not
 * — the inspector, for instance, is meant to read a car's visit list before judging it but not to
 * browse the signed-off ledger. Each tab declares the route it replaced, so that stays true.
 */
export default function RepairRecordsHub() {
  const { t } = useI18n();

  const tabs = useMemo(
    () => [
      { key: 'signed-off', label: t('Completed Repairs'), icon: <Icon.Check className="h-4 w-4" />, permission: 'maintenance.view', was: '/completed-repairs', Component: CompletedRepairs },
      { key: 'per-car', label: t('History'), icon: <Icon.Clock className="h-4 w-4" />, permission: 'maintenance.view', was: '/maintenance-history', Component: MaintenanceHistory },
    ],
    [t],
  );

  return (
    <TabbedHub
      title={t('Repair Records')}
      subtitle={t('Work that is finished — the signed-off ledger of what was fixed and what it cost, and the workshop history of each car, trip by trip.')}
      ariaLabel={t('Repair record sections')}
      tabs={tabs}
    />
  );
}
