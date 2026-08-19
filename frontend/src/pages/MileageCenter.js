import { useSearchParams } from 'react-router-dom';
import { useI18n } from '../i18n/I18nContext';
import { PageHeader } from '../components/ui/Misc';
import FuelMileage from './FuelMileage';
import MileageReconciliation from './MileageReconciliation';
import MileageChainAudit from './MileageChainAudit';
import MileageDiscrepancies from './oversight/MileageDiscrepancies';

// The three odometer/fuel tools, unified into one page. Each tab is the original page rendered
// in `embedded` mode (its own outer header/padding stripped) so all their logic — period presets,
// Apply-baseline, Quick-Fix overrides, drawers — keeps working untouched.
const TABS = [
  {
    key: 'fuel',
    label: 'Fuel & Mileage',
    width: 'max-w-[1500px]',
    subtitle: 'Per car, for the chosen period: real odometer travel vs. the kilometres your contracts explain — the gap is distance driven off-contract. Plus fuel debited back for under-fuelled returns. Click any car for its trip-by-trip ledger.',
  },
  {
    key: 'recon',
    label: 'Reconciliation',
    width: 'max-w-5xl',
    subtitle: 'Where the stored odometer disagrees with the mileage the scanner rebuilt from contract history. Review each gap and adopt the trusted value with one click.',
  },
  {
    key: 'chain',
    label: 'Chain Audit',
    width: 'max-w-6xl',
    subtitle: "Verifies the odometer hands off cleanly between a car's consecutive contracts: the mileage one contract recorded on return should equal the next contract's pickup reading. Fix a mis-typed reading with a non-destructive Quick Fix.",
  },
  {
    // Moved here from the Reports module: it was filed under Workflow Oversight because that is
    // where the drift gets noticed, but it is an odometer question, and every other odometer tool
    // is on this page. /oversight/mileage still works and redirects to this tab.
    key: 'discrepancies',
    label: 'Mileage Discrepancies',
    width: 'max-w-[1280px]',
    subtitle: 'Odometer readings that do not line up across the workflow — who recorded each one, at which stage, and whether a photo backs it up. A large gap on one car is a reading error; the same car again and again is a process problem.',
  },
];

export default function MileageCenter() {
  const { t } = useI18n();
  const [params, setParams] = useSearchParams();
  const requested = params.get('tab');
  const active = TABS.find((tab) => tab.key === requested) || TABS[0];
  const setTab = (key) => setParams(key === 'fuel' ? {} : { tab: key }, { replace: true });

  return (
    <div className="py-8">
      <div className={`mx-auto ${active.width} px-4 sm:px-6 lg:px-8`}>
        <PageHeader title={t('Fuel & Mileage')} subtitle={t(active.subtitle)} />

        {/* Tab strip */}
        <div className="mt-4 flex flex-wrap gap-1 border-b border-slate-200">
          {TABS.map((tab) => (
            <button
              key={tab.key}
              type="button"
              onClick={() => setTab(tab.key)}
              className={`-mb-px rounded-t-lg border-b-2 px-4 py-2 text-sm font-medium transition ${
                active.key === tab.key
                  ? 'border-indigo-600 text-indigo-600'
                  : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'
              }`}
            >
              {t(tab.label)}
            </button>
          ))}
        </div>
      </div>

      <div className="mt-6">
        {active.key === 'fuel' && <FuelMileage embedded />}
        {active.key === 'recon' && <MileageReconciliation embedded />}
        {active.key === 'chain' && <MileageChainAudit embedded />}
        {active.key === 'discrepancies' && <MileageDiscrepancies embedded />}
      </div>
    </div>
  );
}
