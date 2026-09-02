import { useMemo } from 'react';
import TabbedHub from '../components/ui/TabbedHub';
import Icon from '../components/ui/Icon';
import { useI18n } from '../i18n/I18nContext';
import Parts from './Parts';
import PartInvoices from './PartInvoices';
import PartsCatalog from './PartsCatalog';
import PartVariants from './PartVariants';
import SpareKeys from './SpareKeys';
import Storehouse from './Storehouse';
import Warranties from './Warranties';

/**
 * Parts — one page for everything about a part:
 *
 *   • Requests & Purchases → the board (request → approve → buy → install)
 *   • What to buy          → which KIND of each part actually lasts, per month of service
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
      // The shelf sits next to the board because the first question at request time is "do we
      // already have one?" — and the answer to it lives here.
      { key: 'store', label: t('Storehouse'), icon: <Icon.Box className="h-4 w-4" />, permission: 'parts.view', Component: Storehouse },
      // Spare keys sit here, not on a page of their own: a key IS a part, and the people working
      // this board are the people working the one beside it. What it adds is the stage BEFORE a
      // purchase request — "this car has to have a key" — which no other board can hold.
      { key: 'spare-keys', label: t('Spare Keys'), icon: <Icon.Shield className="h-4 w-4" />, permission: 'parts.view', Component: SpareKeys },
      // "Bills for parts", not "Supplier invoices": a part is billed down two roads — a supplier's
      // parts-only invoice, and a garage's invoice where the part sits beside the labour. The tab
      // now shows both, so its name had to stop promising only one of them.
      { key: 'invoices', label: t('Bills for parts'), icon: <Icon.Invoice className="h-4 w-4" />, permission: 'parts.view', was: '/part-invoices', Component: PartInvoices },
      // The buying question, which no other tab answers. The board says what a part COST; this says
      // what it cost PER MONTH IT LASTED, which is the only comparison that can tell a cheap part
      // from a false economy. It sits before Part Names because it is a decision, not reference data.
      { key: 'variants', label: t('What to buy'), icon: <Icon.Scale className="h-4 w-4" />, permission: 'parts.view', Component: PartVariants },
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
