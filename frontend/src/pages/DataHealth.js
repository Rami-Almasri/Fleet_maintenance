import { useCallback } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import Badge from '../components/ui/Badge';
import { Card, PageHeader, Spinner, EmptyState } from '../components/ui/Misc';
import { usePageStat } from '../components/PageStat';
import IssueGroupsAnalytics from '../components/analytics/IssueGroupsAnalytics';
import { num } from '../lib/format';
import { useI18n } from '../i18n/I18nContext';
import StatusMismatch from './StatusMismatch';

// Severity presentation. Built per-render because the label is translated — the
// tones/keys are unchanged machinery.
const sevMeta = (t) => ({
  critical: { tone: 'red', dot: 'bg-red-500', label: t('Must fix'), ring: 'ring-red-200', head: 'bg-red-50/60 border-red-100 text-red-700' },
  warning: { tone: 'amber', dot: 'bg-amber-500', label: t('Incomplete'), ring: 'ring-amber-200', head: 'bg-amber-50/60 border-amber-100 text-amber-700' },
  info: { tone: 'blue', dot: 'bg-blue-500', label: t('Nice to fill'), ring: 'ring-blue-200', head: 'bg-blue-50/60 border-blue-100 text-blue-700' },
});

// The single best "open" link for a row: ticket → contract → car → customer.
function RowLink({ it }) {
  const { t } = useI18n();
  if (it.ticket_id) return <Link to={`/maintenance-workflow/${it.ticket_id}`} className="text-xs font-medium text-indigo-600 hover:text-indigo-700">{t('Ticket →')}</Link>;
  if (it.contract_id) return <Link to={`/contracts/${it.contract_id}`} className="text-xs font-medium text-indigo-600 hover:text-indigo-700">{t('Contract →')}</Link>;
  if (it.vehicle_id) return <Link to={`/vehicles/${it.vehicle_id}`} className="text-xs font-medium text-indigo-600 hover:text-indigo-700">{t('Car →')}</Link>;
  if (it.customer_id) return <Link to={`/customers/${it.customer_id}`} className="text-xs font-medium text-indigo-600 hover:text-indigo-700">{t('Customer →')}</Link>;
  return <span className="text-slate-300">—</span>;
}

function Record({ it }) {
  const { t } = useI18n();
  // A car-based row, a customer-based row, or a bare contract row.
  if (it.vehicle_id || it.plate) {
    return (
      <div>
        {it.vehicle_id
          ? <Link to={`/vehicles/${it.vehicle_id}`} className="font-medium text-indigo-600 hover:text-indigo-700">{it.plate || `#${it.vehicle_id}`}</Link>
          : <span className="font-medium text-slate-700">{it.plate || '—'}</span>}
        <div className="text-xs text-slate-400">{it.car || '—'}{it.contract_no ? ` · ${t('contract {no}', { no: it.contract_no })}` : ''}{it.ticket_id ? ` · ${t('ticket #{id}', { id: it.ticket_id })}` : ''}</div>
      </div>
    );
  }
  if (it.customer_id) {
    return (
      <Link to={`/customers/${it.customer_id}`} className="font-medium text-indigo-600 hover:text-indigo-700">{it.customer || t('Customer #{id}', { id: it.customer_id })}</Link>
    );
  }
  return <span className="text-slate-500">{it.contract_no ? t('Contract {no}', { no: it.contract_no }) : '—'}</span>;
}

function Group({ g }) {
  const { t } = useI18n();
  const SEV = sevMeta(t);
  const sev = SEV[g.severity] || SEV.warning;
  return (
    <Card className={`ring-1 ${sev.ring}`}>
      <div className={`border-b px-6 py-4 ${sev.head}`}>
        <div className="flex items-center justify-between gap-4">
          <h3 className="flex items-center gap-2 text-base font-semibold">
            <span className={`h-2.5 w-2.5 rounded-full ${sev.dot}`} />
            {g.title}
            <Badge tone={sev.tone}>{num(g.count)}</Badge>
          </h3>
          <span className="text-xs font-medium uppercase tracking-wide opacity-70">{sev.label}</span>
        </div>
        <p className="mt-1 text-xs font-normal text-slate-500">{g.description}</p>
      </div>

      <div className="overflow-x-auto">
        <table className="min-w-full border-separate border-spacing-0 text-sm">
          <thead>
            <tr>
              <th className="whitespace-nowrap border-b border-slate-200 bg-slate-50/90 px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{t('Record')}</th>
              <th className="whitespace-nowrap border-b border-slate-200 bg-slate-50/90 px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{t("What's missing")}</th>
              <th className="whitespace-nowrap border-b border-slate-200 bg-slate-50/90 px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{t('Open')}</th>
            </tr>
          </thead>
          <tbody>
            {g.items.map((it, i) => (
              <tr key={i} className="bg-white transition-colors even:bg-slate-50/40 hover:bg-indigo-50/40">
                <td className="border-b border-slate-100 px-5 py-3.5"><Record it={it} /></td>
                <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">{it.detail}</td>
                <td className="border-b border-slate-100 px-5 py-3.5 text-end"><RowLink it={it} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {g.shown < g.count && (
        <div className="border-t border-slate-100 px-6 py-2 text-xs text-slate-400">
          {t('Showing the first {shown} of {count}.', { shown: num(g.shown), count: num(g.count) })}
        </div>
      )}
    </Card>
  );
}

function Stat({ label, value, dot, tone = 'text-slate-900' }) {
  return (
    <div className="rounded-2xl border border-slate-200/60 bg-white px-5 py-4 shadow-soft">
      <p className="flex items-center gap-1.5 text-xs font-medium text-slate-500">
        {dot && <span className={`h-1.5 w-1.5 rounded-full ${dot}`} />}{label}
      </p>
      <p className={`mt-1 font-display text-2xl font-bold tracking-tight ${tone}`}>{value}</p>
    </div>
  );
}

// Group keys promoted to their own top-level tabs (2026-07-11). They're pulled out of the
// "Data Quality" tab so each has a dedicated view — see promotedTabs().
const PROMOTED_KEYS = ['cars_no_vin', 'cars_no_mileage', 'contracts_no_car', 'customers_no_name'];

const promotedTabs = (t) => [
  {
    key: 'cars_no_vin',
    label: t('Cars without a VIN'),
    subtitle: t('Active cars with no chassis number (VIN) on file — VIN is how the sheet and the API match a car, so a missing VIN means it can never be enriched or linked automatically.'),
  },
  {
    key: 'cars_no_mileage',
    label: t('Cars without mileage'),
    subtitle: t('Active cars whose odometer is empty, 0 or 1 (a placeholder). Mileage drives service-due and replacement planning, so these need a real reading.'),
  },
  {
    key: 'contracts_no_car',
    label: t('Contracts without a car'),
    subtitle: t('Contracts whose car could not be matched to a vehicle in the fleet — usually the car has not been imported yet. Import the car (Cars from API) and re-run contracts to link them.'),
  },
  {
    key: 'customers_no_name',
    label: t('Customers without a name'),
    subtitle: t('Customers still nameless after the bulk name fill — either masked at the source ("***") or not present in the OfficeManager customer list.'),
  },
];

// The Data Health content. `data`/`loading`/`error` are lifted to the container (so the tab strip
// can show per-group counts). `only` restricts the panel to a single group key (a promoted tab);
// otherwise it shows every group except the promoted ones + the fleet-wide summary strip.
function DataHealthPanel({ data, loading, error, only = null }) {
  const { t } = useI18n();
  // Floating page gauge: of all data issues, the share that are must-fix.
  const totalIssues = data?.total_issues || 0;
  const mustFix = data?.critical || 0;
  usePageStat({
    percent: loading || !totalIssues ? null : (mustFix / totalIssues) * 100,
    label: t('Must-fix'),
    color: 'red',
    hint: t('{mustFix} of {total} data issues are must-fix', { mustFix, total: totalIssues }),
  });

  if (loading) return <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>;

  const allGroups = (data?.groups || []).filter((g) => g.count > 0);
  const groups = only
    ? allGroups.filter((g) => g.key === only)
    : allGroups.filter((g) => !PROMOTED_KEYS.includes(g.key));

  return (
    <>
      {error && (
        <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
      )}

      {/* Fleet-wide summary strip only on the overview tab (the single-group tabs speak for themselves). */}
      {!only && (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <Stat label={t('Total issues')} value={num(data?.total_issues)} />
          <Stat label={t('Must fix')} dot="bg-red-500" value={num(data?.critical)} tone="text-red-600" />
          <Stat label={t('Incomplete')} dot="bg-amber-500" value={num(data?.warning)} tone="text-amber-600" />
          <Stat label={t('Nice to fill')} dot="bg-blue-500" value={num(data?.info)} tone="text-blue-600" />
        </div>
      )}

      {/* Analytics — ranks the WHOLE backlog, including the groups promoted to their
          own tabs, so the priority view matches the totals in the strip above. */}
      {!only && <IssueGroupsAnalytics groups={allGroups} />}

      {groups.length === 0
        ? <Card><EmptyState title={t('All clean 🎉')} message={only ? t('No cars in this category.') : t('Every car, contract and customer has its key fields filled in.')} /></Card>
        : groups.map((g) => <Group key={g.key} g={g} />)}
    </>
  );
}

// Status Mismatch was folded in here as a tab (2026-07-11); VIN / mileage promoted to tabs the same day.
const buildTabs = (t) => [
  { key: 'health', label: t('Data Quality'), subtitle: t('Incomplete or broken records to clean up — unlinked contracts, duplicate VINs, nameless customers and more. Fix these to keep the fleet data reliable.') },
  ...promotedTabs(t),
  { key: 'status', label: t('Status Mismatches'), subtitle: t("Cars whose status doesn't match their contracts — out on a contract but not flagged busy, or flagged busy with no open contract.") },
];

export default function DataHealth() {
  const { t } = useI18n();
  const [params, setParams] = useSearchParams();
  const requested = params.get('tab');
  const tabs = buildTabs(t);
  const active = tabs.find((tab) => tab.key === requested) || tabs[0];
  const setTab = (key) => setParams(key === 'health' ? {} : { tab: key }, { replace: true });

  // Fetch once here so both the tab counts and the active panel share the same data.
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/DataHealth');
    return data.data;
  }, []);
  const { data, loading, error } = useFetch(fetcher);
  const groupCount = (key) => (data?.groups || []).find((g) => g.key === key)?.count;

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title={t('Data Health')} subtitle={active.subtitle} />

        {/* Tab strip */}
        <div className="flex flex-wrap gap-1 border-b border-slate-200">
          {tabs.map((tab) => {
            const count = PROMOTED_KEYS.includes(tab.key) ? groupCount(tab.key) : undefined;
            return (
              <button
                key={tab.key}
                type="button"
                onClick={() => setTab(tab.key)}
                className={`-mb-px inline-flex items-center gap-1.5 rounded-t-lg border-b-2 px-4 py-2 text-sm font-medium transition ${
                  active.key === tab.key
                    ? 'border-indigo-600 text-indigo-600'
                    : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'
                }`}
              >
                {tab.label}
                {count != null && (
                  <span className={`rounded-full px-1.5 py-0.5 text-[11px] font-semibold tabular-nums ${
                    active.key === tab.key ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-500'
                  }`}>
                    {num(count)}
                  </span>
                )}
              </button>
            );
          })}
        </div>

        {active.key === 'status'
          ? <StatusMismatch embedded />
          : <DataHealthPanel data={data} loading={loading} error={error} only={PROMOTED_KEYS.includes(active.key) ? active.key : null} />}
      </div>
    </div>
  );
}
