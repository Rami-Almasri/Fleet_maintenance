// A shared chart strip for the "issue groups" pages — Data Health and Financial
// Conflicts both return the same shape (a list of groups, each with a title, a count
// and a severity), so they get the same two charts rather than two near-identical
// components:
//
//   1. Biggest problems → groups ranked by how many records they hold, coloured by
//      severity, so the largest pile and the most urgent pile are both visible
//   2. Severity mix     → how much of the backlog genuinely matters
//
// Severity colours are the app's reserved status palette (red / amber / blue) and
// are never reused as ordinary series hues, so a red bar always means "must fix".
// Totals are derived from the groups themselves, so the strip is correct on any
// page with this shape regardless of what its summary object happens to be called.

import { useMemo } from 'react';
import { SectionCard } from '../ui/Table';
import RankedBar from '../ui/RankedBar';
import CompositionDonut from '../ui/CompositionDonut';
import { num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

const SEV_COLOR = { critical: 'red', warning: 'amber', info: 'blue' };

export default function IssueGroupsAnalytics({
  groups = [],
  criticalLabel = 'Must fix',
  warningLabel = 'Incomplete',
  infoLabel = 'Nice to fill',
  rankTitle = 'Biggest problems',
  rankSubtitle = 'Issue groups ranked by how many records they hold',
  centerLabel = 'Open issues',
  criticalNote,
}) {
  const { t } = useI18n();
  const open = useMemo(() => groups.filter((g) => (g.count || 0) > 0), [groups]);

  const labelFor = useMemo(
    () => ({ critical: t(criticalLabel), warning: t(warningLabel), info: t(infoLabel) }),
    [t, criticalLabel, warningLabel, infoLabel],
  );

  const board = useMemo(
    () =>
      [...open]
        .sort((a, b) => (b.count || 0) - (a.count || 0))
        .slice(0, 10)
        .map((g) => ({
          key: g.key,
          label: g.title,
          sub: labelFor[g.severity] || g.severity,
          value: g.count || 0,
          color: SEV_COLOR[g.severity] || 'slate',
          description: g.description,
        })),
    [open, labelFor],
  );

  const mix = useMemo(() => {
    const totals = {};
    open.forEach((g) => { totals[g.severity] = (totals[g.severity] || 0) + (g.count || 0); });
    return ['critical', 'warning', 'info']
      .filter((k) => totals[k] > 0)
      .map((k) => ({ key: k, label: labelFor[k], value: totals[k], color: SEV_COLOR[k] }));
  }, [open, labelFor]);

  const total = mix.reduce((a, s) => a + s.value, 0);
  if (!total || !board.length) return null;

  const critical = mix.find((s) => s.key === 'critical')?.value || 0;
  const share = Math.round((critical / total) * 100);

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <SectionCard
        className="lg:col-span-2"
        title={t(rankTitle)}
        subtitle={t(rankSubtitle)}
        bodyClass="p-5"
      >
        <RankedBar
          items={board}
          showRank
          format={(n) => num(Math.round(n))}
          valueLabel={t('Records')}
          labelWidth={190}
          valueWidth={56}
          tooltip={(r) => r.description || r.sub}
          empty={t('Nothing to clean up.')}
        />
      </SectionCard>

      <SectionCard
        title={t('Severity mix')}
        subtitle={t('How much of the backlog really matters')}
        bodyClass="p-5"
      >
        <CompositionDonut
          segments={mix}
          total={total}
          centerLabel={t(centerLabel)}
          format={(n) => num(Math.round(n))}
          size={150}
          stroke={20}
        />
        <p className="mt-4 border-t border-slate-100 pt-3 text-xs leading-relaxed text-slate-500">
          {critical > 0
            ? (critical === 1
              ? t('{share}% of what is open is {label} — {n} record {note}.', {
                share, label: t(criticalLabel).toLowerCase(), n: num(critical),
                note: t(criticalNote || 'that break something downstream'),
              })
              : t('{share}% of what is open is {label} — {n} records {note}.', {
                share, label: t(criticalLabel).toLowerCase(), n: num(critical),
                note: t(criticalNote || 'that break something downstream'),
              }))
            : t('Nothing urgent is open — what is left is detail rather than damage.')}
        </p>
      </SectionCard>
    </div>
  );
}
