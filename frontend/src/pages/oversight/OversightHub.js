import { useMemo } from 'react';
import TabbedHub from '../../components/ui/TabbedHub';
import Icon from '../../components/ui/Icon';
import { useI18n } from '../../i18n/I18nContext';
import GarageInvoiceQueue from './GarageInvoiceQueue';
import SeverityReview from './SeverityReview';
import Misdiagnoses from './Misdiagnoses';
import ResolvedTransfers from './ResolvedTransfers';
import CheckpointCompliance from './CheckpointCompliance';

/**
 * Workflow Oversight — the accountability suite over the maintenance workflow, in one page.
 *
 * These five reports all answer the same kind of question: did the process actually hold? They were
 * five separate tiles in the launcher; each is now a tab.
 *
 *   • Left the garage      → cars out of the workshop while the maintenance contract stayed open
 *   • Diagnostic review    → the severity QC gate: keep the call or upgrade it
 *   • Mis-diagnosis        → faults later marked incorrect
 *   • Transferred — fixed  → cars moved on with every fault repaired
 *   • Checkpoint chase     → supervisors who never answered the "car is due back" reminder
 *
 * Mileage Discrepancies used to sit in this group. It moved to /mileage instead, where every other
 * odometer surface lives — an odometer question is an odometer question wherever it was noticed.
 *
 * Each old /oversight/<name> URL still works; App.js redirects it to its tab.
 */
export default function OversightHub() {
  const { t } = useI18n();

  const tabs = useMemo(
    () => [
      { key: 'left-garage', label: t('Left-the-Garage Invoices'), icon: <Icon.Truck className="h-4 w-4" />, permission: 'insights.view', was: '/oversight/left-garage', Component: GarageInvoiceQueue },
      { key: 'severity', label: t('Diagnostic Review'), icon: <Icon.Flag className="h-4 w-4" />, permission: 'insights.view', was: '/oversight/severity', Component: SeverityReview },
      { key: 'misdiagnoses', label: t('Mis-Diagnosis'), icon: <Icon.XCircle className="h-4 w-4" />, permission: 'insights.view', was: '/oversight/misdiagnoses', Component: Misdiagnoses },
      { key: 'resolved-transfers', label: t('Transferred — Faults Fixed'), icon: <Icon.ArrowRight className="h-4 w-4" />, permission: 'insights.view', was: '/oversight/resolved-transfers', Component: ResolvedTransfers },
      { key: 'checkpoint-compliance', label: t('Checkpoint Compliance'), icon: <Icon.Clock className="h-4 w-4" />, permission: 'insights.view', was: '/oversight/checkpoint-compliance', Component: CheckpointCompliance },
    ],
    [t],
  );

  return (
    <TabbedHub
      title={t('Workflow Oversight')}
      subtitle={t('Did the process hold? Five checks over the maintenance workflow — each one names the cars, and the people, behind the number.')}
      ariaLabel={t('Oversight sections')}
      tabs={tabs}
    />
  );
}
