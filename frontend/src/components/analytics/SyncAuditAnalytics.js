// The chart strip for Sync Audit. A run log is read newest-first one line at a time;
// these charts turn it into the two things worth knowing about a sync:
//
//   1. What each run actually changed → new / updated / auto-corrected per run.
//      All three are counts of records, so they legitimately share one axis.
//   2. Are runs succeeding?           → outcome mix across the log
//
// A run that suddenly corrects far more than it updates is the signal this page
// exists for, and it's invisible in a table of numbers.
//
// Derived from the /Sync/audit runs already on the page.

import { useMemo } from 'react';
import { SectionCard } from '../ui/Table';
import GroupedBarChart from '../ui/GroupedBarChart';
import PieChart from '../ui/PieChart';
import { num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

const STATUS_COLOR = {
  success: 'emerald',
  completed: 'emerald',
  ok: 'emerald',
  partial: 'amber',
  running: 'blue',
  failed: 'red',
  error: 'red',
};

// "2026-07-28T09:12" → "28 Jul". Short enough to sit under a bar. Arabic must stay
// Gregorian with Latin digits so the axis lines up with every other date in the app.
const shortDate = (iso, lang) => {
  if (!iso) return '—';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '—';
  const locale = lang === 'ar' ? 'ar-AE-u-ca-gregory-nu-latn' : undefined;
  return d.toLocaleDateString(locale, { day: '2-digit', month: 'short' });
};

// Written out as literal t() calls so the phrase catalog can see every outcome.
const outcomeLabel = (k, t) => ({
  success: t('Success'),
  completed: t('Completed'),
  ok: t('OK'),
  partial: t('Partial'),
  running: t('Running'),
  failed: t('Failed'),
  error: t('Error'),
  unknown: t('Unknown'),
}[k] || k.charAt(0).toUpperCase() + k.slice(1));

export default function SyncAuditAnalytics({ runs = [] }) {
  const { t, lang } = useI18n();

  // Oldest → newest across the last dozen runs, so the trend reads left to right
  // like every other time axis in the app (the table itself is newest-first).
  const trend = useMemo(
    () =>
      [...runs]
        .slice(0, 12)
        .reverse()
        .map((r) => ({
          label: shortDate(r.started_at, lang),
          created: Number(r.created) || 0,
          updated: Number(r.updated) || 0,
          corrections: Number(r.corrections) || 0,
          scanned: Number(r.scanned) || 0,
          action: r.action,
        })),
    [runs, lang],
  );

  const outcomes = useMemo(() => {
    const totals = {};
    runs.forEach((r) => {
      const k = r.status || 'unknown';
      totals[k] = (totals[k] || 0) + 1;
    });
    return Object.entries(totals)
      .map(([k, value]) => ({
        label: outcomeLabel(k, t),
        value,
        color: STATUS_COLOR[k] || 'slate',
      }))
      .sort((a, b) => b.value - a.value);
  }, [runs, t]);

  if (!runs.length) return null;

  const corrections = runs.reduce((a, r) => a + (Number(r.corrections) || 0), 0);

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        className="lg:col-span-2"
        title={t('What each run changed')}
        subtitle={t('New, updated and auto-corrected records across the last 12 runs')}
        bodyClass="px-3 pb-3 pt-2"
      >
        <GroupedBarChart
          data={trend}
          series={[
            { key: 'created', label: t('New'), color: 'emerald' },
            { key: 'updated', label: t('Updated'), color: 'blue' },
            { key: 'corrections', label: t('Auto-corrected'), color: 'amber' },
          ]}
          height={240}
          integer
          format={(n) => num(Math.round(n))}
        />
        <p className="px-3 pb-1 pt-1 text-xs leading-relaxed text-slate-500">
          {corrections > 0
            ? (corrections === 1
              ? t('{n} record was auto-corrected across this log — a run that corrects more than it updates is worth opening.', { n: num(corrections) })
              : t('{n} records were auto-corrected across this log — a run that corrects more than it updates is worth opening.', { n: num(corrections) }))
            : t('Nothing has needed auto-correction in this log.')}
        </p>
      </SectionCard>

      <SectionCard
        title={t('Run outcomes')}
        subtitle={t('How the sync log ended')}
        bodyClass="flex items-center justify-center p-5"
      >
        <PieChart segments={outcomes} size={150} />
      </SectionCard>
    </div>
  );
}
