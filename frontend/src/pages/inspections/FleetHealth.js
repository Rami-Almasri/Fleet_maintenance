import { useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import { PageHeader } from '../../components/ui/Misc';
import Tabs from '../../components/ui/Tabs';
import Icon from '../../components/ui/Icon';
import { usePermissions } from '../../hooks/usePermissions';
import ReadinessDashboard from '../ReadinessDashboard';
import ServiceReminders from '../reminders/ServiceReminders';
import MaintenanceBookings from '../MaintenanceBookings';
import Registrations from '../Registrations';

/**
 * Fleet Health — the unified "neural center" hub. It gathers the live vehicle-health
 * surfaces into one tabbed page:
 *
 *   • Vehicle Readiness       → /readiness      (fleet status, handover queues, cars in maintenance)
 *   • Service Due             → /reminders/service (odometer-based service reminders)
 *   • Booked in Shop          → /maintenance-bookings (cars in the workshop with an upcoming booking)
 *   • Registration & Insurance→ /registrations  (official-document expiry)
 *
 * Each tab simply MOUNTS the existing self-contained page — no logic is duplicated, so this hub
 * can never disagree with the standalone routes (which still work). Only the ACTIVE tab is mounted,
 * so just one data-fetch / poll loop runs at a time. Tabs are permission-gated to match the API the
 * underlying page calls, so a user only sees the sections they can actually load.
 */
export default function FleetHealth() {
  const { can, canAny } = usePermissions();

  const tabs = useMemo(
    () =>
      [
        // Vehicle Readiness — the same permission set the /readiness endpoint accepts, so the tab only
        // shows for a user who can actually load it (and ?tab=readiness deep-links straight here).
        { key: 'readiness', label: 'Vehicle Readiness', icon: <Icon.Shield className="h-4 w-4" />, show: canAny(['insights.view', 'logistics.view', 'maintenance.view', 'maintenance.initiate', 'maintenance.delegate', 'inspections.view']), Component: ReadinessDashboard },
        { key: 'service', label: 'Service Due', icon: <Icon.Wrench className="h-4 w-4" />, show: can('reminders.view'), Component: ServiceReminders },
        { key: 'bookings', label: 'Booked in Shop', icon: <Icon.Calendar className="h-4 w-4" />, show: can('maintenance.view'), Component: MaintenanceBookings },
        { key: 'registrations', label: 'Registration & Insurance', icon: <Icon.Invoice className="h-4 w-4" />, show: can('registration.view'), Component: Registrations },
      ].filter((t) => t.show),
    [can, canAny],
  );

  // The active tab lives in the URL (?tab=…) so sections are deep-linkable — e.g. the retired
  // /registrations route redirects to ?tab=registrations, and readiness/rental "fix" links can
  // target a section directly. Falls back to the first available tab for this user.
  const [searchParams, setSearchParams] = useSearchParams();
  const current = tabs.find((t) => t.key === searchParams.get('tab')) || tabs[0];
  const Active = current?.Component;
  const setActive = (key) => setSearchParams({ tab: key }, { replace: true });

  return (
    <div>
      <div className="mx-auto max-w-7xl px-4 pt-8 sm:px-6 lg:px-8">
        <PageHeader
          title="Fleet Health"
          subtitle="Your fleet's neural center — vehicle readiness, upcoming service, and official documents in one place."
        />
        <Tabs tabs={tabs} active={current?.key} onChange={setActive} ariaLabel="Fleet health sections" />
      </div>

      <div role="tabpanel" id={`panel-${current?.key}`} aria-labelledby={`tab-${current?.key}`}>
        {Active ? <Active /> : null}
      </div>
    </div>
  );
}
