import { useMemo } from 'react';
import SidebarHub from '../components/ui/SidebarHub';
import Icon from '../components/ui/Icon';
import { useI18n } from '../i18n/I18nContext';
import InspectionReviewQueue from './InspectionReviewQueue';
import OilProjection from './reminders/OilProjection';
import InvoiceMatching from './InvoiceMatching';
import VehicleLocations from './VehicleLocations';
import FaultVocabulary from './FaultVocabulary';

/**
 * Control Desk — the Controllers' (Lin & Marwa) day, in one place. Five jobs that were five separate
 * routes, each reached from a different corner of the sidebar, even though the same two people do all
 * of them and move between them constantly:
 *
 *   • Inspection Review  → a request to send a car in has been raised. Approve it on to Abu Maroof,
 *                          or reject it with a reason. The gate before the workshop.
 *   • Oil Follow-up      → cars already OUT on rental whose projected mileage is past the oil limit.
 *                          Call the customer, key the odometer they report, the projection re-anchors.
 *   • Invoice Matching   → the car is back. Key each garage's bill beside the work it covers and see
 *                          whether the paper and the repair agree.
 *   • Fault Vocabulary   → WHAT can be reported at all, HOW SERIOUS it is, and whether the matcher can
 *                          read it in a written note. Adding one here puts it in the picker with no
 *                          deploy, which is what it used to take.
 *   • Vehicle Locations  → the other half of that same vocabulary: WHERE on the car a fault can be,
 *                          and which fault types must name a place before the report can be filed.
 *
 * Fault Vocabulary WAS two sections — "Keyword Risk" and "Fault Types" — because a fault type is two
 * database rows. That is an implementation fact and it was on screen as two lists with two totals that
 * differ by design, which read as "one of these is broken". One word, one row, one page now; both old
 * `?tab=` keys still resolve here.
 *
 * A SIDEBAR rather than a tab strip: these are separate jobs, not five readings of one board, and the
 * rail stays in view while you work inside one of them.
 *
 * Permissions differ per section (review is maintenance.manage, the oil chase is reminders.view, the
 * rest are maintenance.view), so the ROUTE is left ungated and SidebarHub filters section by
 * section — each declaring the route it replaced so the role deny list still applies. A person who
 * only holds reminders.view opens the desk and sees exactly the oil chase.
 *
 * Every old route still resolves: /inspection-review, /oil-projection, /invoice-matching,
 * /finding-keywords and /vehicle-locations redirect in here on their own section, keeping their query
 * string — which the notification deep-links (?ticket=<id>) depend on.
 */
export default function ControlDesk() {
  const { t } = useI18n();

  const sections = useMemo(
    () => [
      {
        key: 'review',
        label: t('Inspection Review'),
        hint: t('Approve or reject requests to send a car in'),
        icon: <Icon.Check className="h-4 w-4" />,
        permission: 'maintenance.manage',
        was: '/inspection-review',
        Component: InspectionReviewQueue,
      },
      {
        key: 'oil',
        label: t('Oil Mileage Follow-up'),
        hint: t('Cars out on rental nearing their oil limit'),
        icon: <Icon.Clock className="h-4 w-4" />,
        permission: 'reminders.view',
        was: '/oil-projection',
        Component: OilProjection,
      },
      {
        key: 'invoices',
        label: t('Invoice Matching'),
        hint: t('Key each garage’s bill beside the work it covers'),
        icon: <Icon.Invoice className="h-4 w-4" />,
        permission: 'maintenance.view',
        was: '/invoice-matching',
        Component: InvoiceMatching,
      },
      {
        key: 'vocabulary',
        label: t('Fault Vocabulary'),
        hint: t('What can be reported, how serious it is, and what the matcher knows'),
        icon: <Icon.Flag className="h-4 w-4" />,
        permission: 'maintenance.view',
        // Both retired keys still land here, so every bookmark and deep link into either half of the
        // old pair opens the merged page rather than falling through to the first section.
        aliases: ['keywords', 'fault-types'],
        // '/finding-keywords' is the path the role deny lists in config/access.js name, and the merged
        // section has to keep being hidden from exactly the roles that could not see it before.
        was: '/finding-keywords',
        Component: FaultVocabulary,
      },
      {
        key: 'locations',
        label: t('Vehicle Locations'),
        hint: t('Where on the car a fault can be, and who must say'),
        icon: <Icon.Car className="h-4 w-4" />,
        permission: 'maintenance.view',
        was: '/vehicle-locations',
        Component: VehicleLocations,
      },
    ],
    [t],
  );

  // The subtitle is kept short deliberately — it sits inside a 19.5rem rail, and each section states
  // its own purpose on its own row. The full account of the four jobs lives in the page's info circle
  // (the nav `desc` in AppLayout), not here.
  return (
    <SidebarHub
      icon={Icon.Shield}
      title={t('Control Desk')}
      subtitle={t('Requests in, mileage chased, bills matched, vocabulary kept honest.')}
      ariaLabel={t('Control Desk sections')}
      sections={sections}
    />
  );
}
