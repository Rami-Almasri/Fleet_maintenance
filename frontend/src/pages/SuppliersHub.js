import { useMemo } from 'react';
import TabbedHub from '../components/ui/TabbedHub';
import Icon from '../components/ui/Icon';
import { useI18n } from '../i18n/I18nContext';
import Procurement from './Procurement';
import Vendors from './Vendors';

/**
 * Suppliers — who we buy from, and what we owe them.
 *
 *   • What we owe → the procurement ledger: outstanding by age, supplier performance, payments out
 *   • Suppliers   → the vendor register the rest of the app references
 *
 * The two carry DIFFERENT permissions (procurement is maintenance.view, the register is
 * vendors.view) and different role deny rules, so both tabs declare the route they replaced and
 * TabbedHub hides the ones a given role never had. The route itself is gated on maintenance.view:
 * every seeded role holding vendors.view also holds maintenance.view, so nobody loses the register
 * by arriving through this door.
 *
 * Warranties is NOT here — a warranty is a promise about a part, so it lives on /parts with the
 * purchase that created it.
 */
export default function SuppliersHub() {
  const { t } = useI18n();

  const tabs = useMemo(
    () => [
      { key: 'owed', label: t('What we owe'), icon: <Icon.Cash className="h-4 w-4" />, permission: 'maintenance.view', was: '/procurement', Component: Procurement },
      { key: 'register', label: t('Suppliers'), icon: <Icon.Truck className="h-4 w-4" />, permission: 'vendors.view', was: '/vendors', Component: Vendors },
    ],
    [t],
  );

  return (
    <TabbedHub
      title={t('Suppliers')}
      subtitle={t('Who we buy from and what we owe them — outstanding balances by age, how each supplier performs, every payment that has left the account, and the supplier register itself.')}
      ariaLabel={t('Supplier sections')}
      tabs={tabs}
    />
  );
}
