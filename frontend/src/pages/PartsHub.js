import { useMemo } from 'react';
import TabbedHub from '../components/ui/TabbedHub';
import Icon from '../components/ui/Icon';
import { useI18n } from '../i18n/I18nContext';
import Parts from './Parts';
import PartInvoices from './PartInvoices';
import PartsCatalog from './PartsCatalog';
import Warranties from './Warranties';

/**
 * Parts — one page for everything about a part:
 *
 *   • Requests & Purchases → the board (request → approve → buy → install)
 *   • Supplier invoices    → what the supplier charged, with the paper behind it
 *   • Part Names           → the catalog every part picker in the app selects from
 *   • Warranties           → the promise that came with the part, and whether it still holds
 *
 * Warranty sits here rather than with the suppliers because a warranty is a promise about a PART —
 * the people chasing one are the people who bought it, and they arrive from the purchase, not from
 * the vendor record.
 *
 * Every tab is parts.view, the same gate the route carries. Write actions inside each page keep
 * their own per-action permissions (approving a purchase, curating the catalog, filing a claim).
 * The old /part-invoices, /parts-catalog and /warranties URLs redirect into their tab.
 */
export default function PartsHub() {
  const { t } = useI18n();

  const tabs = useMemo(
    () => [
      { key: 'board', label: t('Requests & Purchases'), icon: <Icon.Coins className="h-4 w-4" />, permission: 'parts.view', was: '/parts', Component: Parts },
      { key: 'invoices', label: t('Supplier invoices'), icon: <Icon.Invoice className="h-4 w-4" />, permission: 'parts.view', was: '/part-invoices', Component: PartInvoices },
      { key: 'catalog', label: t('Part Names'), icon: <Icon.Search className="h-4 w-4" />, permission: 'parts.view', was: '/parts-catalog', Component: PartsCatalog },
      { key: 'warranties', label: t('Warranties'), icon: <Icon.Shield className="h-4 w-4" />, permission: 'parts.view', was: '/warranties', Component: Warranties },
    ],
    [t],
  );

  return (
    <TabbedHub
      title={t('Parts')}
      subtitle={t('Everything about a part in one place — what was asked for and bought, what the supplier charged, the names the whole app selects from, and the warranty that came with it.')}
      ariaLabel={t('Parts sections')}
      tabs={tabs}
    />
  );
}
