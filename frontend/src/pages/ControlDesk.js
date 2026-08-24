import { useMemo } from 'react';
import SidebarHub from '../components/ui/SidebarHub';
import Icon from '../components/ui/Icon';
import { useI18n } from '../i18n/I18nContext';
import InspectionReviewQueue from './InspectionReviewQueue';
import OilProjection from './reminders/OilProjection';
import InvoiceMatching from './InvoiceMatching';
import FindingKeywords from './FindingKeywords';

/**
 * Control Desk — the Controllers' (Lin & Marwa) day, in one place. Four jobs that were four separate
 * routes, each reached from a different corner of the sidebar, even though the same two people do all
 * of them and move between them constantly:
 *
 *   • Inspection Review  → a request to send a car in has been raised. Approve it on to Abu Maroof,
 *                          or reject it with a reason. The gate before the workshop.
 *   • Oil Follow-up      → cars already OUT on rental whose projected mileage is past the oil limit.
 *                          Call the customer, key the odometer they report, the projection re-anchors.
 *   • Invoice Matching   → the car is back. Key each garage's bill beside the work it covers and see
 *                          whether the paper and the repair agree.
 *   • Keyword Risk       → the fault vocabulary the inspection picker offers, and how serious each
 *                          fault type is. The desk's own settings, not a daily queue.
 *
 * A SIDEBAR rather than a tab strip: these are separate jobs, not four readings of one board, and the
 * rail stays in view while you work inside one of them.
 *
 * Permissions differ per section (review is maintenance.manage, the oil chase is reminders.view, the
 * other two are maintenance.view), so the ROUTE is left ungated and SidebarHub filters section by
 * section — each declaring the route it replaced so the role deny list still applies. A person who
 * only holds reminders.view opens the desk and sees exactly the oil chase.
 *
 * Every old route still resolves: /inspection-review, /oil-projection, /invoice-matching and
 * /finding-keywords redirect in here on their own section, keeping their query string — which the
 * notification deep-links (?ticket=<id>) depend on.
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
        key: 'keywords',
        label: t('Keyword Risk'),
        hint: t('The fault vocabulary, graded by how serious it is'),
        icon: <Icon.Flag className="h-4 w-4" />,
        permission: 'maintenance.view',
        was: '/finding-keywords',
        Component: FindingKeywords,
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
