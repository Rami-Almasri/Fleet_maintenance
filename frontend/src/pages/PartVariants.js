// WHICH ONE IS ACTUALLY WORTH BUYING — the payoff for typing a spec in at the counter.
//
// A purchase ledger sorted by price says a 380 battery is cheaper than a 700 one. This page says
// what that leaves out: how long each one stayed on the car. A part that costs half as much and
// dies twice as fast is the SAME money, and one that dies three times as fast is the dearer part.
// Cost per month of service is the only column that settles it, and it is the one this page is
// built around.
//
// Nothing here is ranked or recommended. Buckets are ordered by how many were bought — never by
// which looks best — because ordering by cost-per-month would put a two-observation bucket at the
// top and present a coincidence as the fleet's best option. The `observations` count sits beside
// every figure for the same reason.

import { useCallback, useMemo, useState } from 'react';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import DataTable, { SectionCard } from '../components/ui/Table';
import Icon from '../components/ui/Icon';
import { EmptyState, ErrorState, SearchInput } from '../components/ui/Misc';
import { Skeleton } from '../components/ui/Skeleton';
import { aed, num } from '../lib/format';
import { useI18n } from '../i18n/I18nContext';

const MONTHS = (days) => (days == null ? null : Math.round((days / 30.44) * 10) / 10);

export default function PartVariants() {
  const { t, lang } = useI18n();
  const [selected, setSelected] = useState(null);
  const [query, setQuery] = useState('');

  const fetchIndex = useCallback(
    () => api.get('/part-variants', { params: { locale: lang } }).then((r) => r?.data?.data || []),
    [lang]
  );
  const { data: index, loading, error, reload } = useFetch(fetchIndex, [lang]);

  const fetchDetail = useCallback(
    () =>
      selected
        ? api.get(`/part-variants/${selected}`, { params: { locale: lang } }).then((r) => r?.data?.data)
        : Promise.resolve(null),
    [selected, lang]
  );
  const { data: detail, loading: detailLoading } = useFetch(fetchDetail, [selected, lang]);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    const rows = index || [];
    return q ? rows.filter((r) => (r.name || '').toLowerCase().includes(q)) : rows;
  }, [index, query]);

  return (
    <div className="space-y-6">
      <SectionCard
        title={t('What to buy')}
        subtitle={t('Every part the fleet has fitted, grouped by what it was — compared on cost per month of service, not sticker price.')}
        bodyClass="p-5"
        actions={<SearchInput value={query} onChange={setQuery} placeholder={t('Search a part')} />}
      >
        {loading && <Skeleton className="h-40 w-full" />}
        {error && <ErrorState onRetry={reload} />}

        {!loading && !error && (
          <DataTable
            rows={filtered}
            rowKey={(r) => r.id}
            onRowClick={(r) => setSelected(r.id === selected ? null : r.id)}
            columns={[
              {
                key: 'name',
                header: t('Part'),
                render: (r) => (
                  <div className="min-w-0">
                    <div className="font-semibold text-slate-900">{r.name}</div>
                    {/* The honest headline on a thin row: you cannot compare what nobody described.
                        This number IS the call to action for the whole feature. */}
                    <div className="text-xs text-slate-400">
                      {r.spec_coverage === 0
                        ? t('No specs recorded yet — nothing to compare')
                        : t('{n}% have a spec recorded', { n: r.spec_coverage })}
                    </div>
                  </div>
                ),
              },
              {
                key: 'variants',
                header: t('Kinds bought'),
                render: (r) =>
                  r.variants > 1 ? (
                    <span className="font-semibold text-slate-900">{num(r.variants)}</span>
                  ) : (
                    <span className="text-slate-400">1</span>
                  ),
              },
              { key: 'fitted', header: t('Fitted'), render: (r) => num(r.fitted) },
              {
                key: 'completed_lives',
                header: t('Finished lives'),
                // The number of parts whose lifespan can actually be measured — the sample size
                // behind everything on the detail panel.
                render: (r) => (r.completed_lives ? num(r.completed_lives) : <span className="text-slate-400">—</span>),
              },
              { key: 'total_spend', header: t('Total spent'), render: (r) => aed(r.total_spend) },
            ]}
            empty={<EmptyState title={t('Nothing fitted yet')} />}
          />
        )}
      </SectionCard>

      {selected && (
        <SectionCard
          title={detail?.part_type?.name || t('Loading…')}
          subtitle={t('One row per kind of part bought. Cost per month is what decides it.')}
          bodyClass="p-5"
        >
          {detailLoading && <Skeleton className="h-32 w-full" />}

          {!detailLoading && detail && (
            <>
              {/* The state of the evidence, stated before any figure is read. A page whose numbers
                  all rest on one undescribed bucket must say so, or it reads as an answer. */}
              <div className="mb-4 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-700 ring-1 ring-inset ring-slate-200">
                {detail.totals.spec_coverage === 0 ? (
                  <div className="flex items-start gap-2">
                    <Icon.Info className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                    <span>
                      {t('None of these have a spec recorded, so they all sit in one group. Record the spec when you buy the part and this splits into a real comparison.')}
                    </span>
                  </div>
                ) : (
                  t('{specced} of {fitted} have a spec recorded · {lives} have a finished life to measure', {
                    specced: num(detail.totals.specced),
                    fitted: num(detail.totals.fitted),
                    lives: num(detail.totals.completed_lives),
                  })
                )}
              </div>

              <DataTable
                rows={detail.variants}
                rowKey={(r) => r.key || 'unspecced'}
                columns={[
                  {
                    key: 'label',
                    header: t('What it was'),
                    render: (r) => (
                      <div className="min-w-0">
                        <div className={r.is_unspecced ? 'font-medium text-slate-400' : 'font-semibold text-slate-900'}>
                          {r.label}
                        </div>
                        {r.brands?.length > 0 && (
                          <div className="truncate text-xs text-slate-400">{r.brands.join(', ')}</div>
                        )}
                      </div>
                    ),
                  },
                  { key: 'fitted', header: t('Bought'), render: (r) => num(r.fitted) },
                  {
                    key: 'life',
                    header: t('Typical life'),
                    render: (r) =>
                      r.median_life_months == null ? (
                        <span className="text-slate-400">{t('Still too early')}</span>
                      ) : (
                        <div>
                          <div className="font-semibold text-slate-900">
                            {t('{n} months', { n: r.median_life_months })}
                          </div>
                          <div className="text-xs text-slate-400">
                            {t('{shortest}–{longest} months', {
                              shortest: MONTHS(r.shortest_life_days),
                              longest: MONTHS(r.longest_life_days),
                            })}
                          </div>
                        </div>
                      ),
                  },
                  { key: 'avg_cost', header: t('Typical price'), render: (r) => (r.avg_cost == null ? '—' : aed(r.avg_cost)) },
                  {
                    key: 'cost_per_month',
                    header: t('Cost per month'),
                    // THE column. Emphasised because it is the only one that answers the question,
                    // and greyed when it cannot be computed rather than filled with a guess.
                    render: (r) =>
                      r.cost_per_month == null ? (
                        <span className="text-slate-400">—</span>
                      ) : (
                        <span className="text-base font-bold text-slate-900">{aed(r.cost_per_month)}</span>
                      ),
                  },
                  {
                    key: 'observations',
                    header: t('Based on'),
                    render: (r) => (
                      <div>
                        <span className={r.thin_evidence ? 'text-amber-600' : 'text-slate-600'}>
                          {t('{n} finished', { n: num(r.observations) })}
                        </span>
                        {r.still_running > 0 && (
                          <div className="text-xs text-slate-400">
                            {t('{n} still running', { n: num(r.still_running) })}
                          </div>
                        )}
                        {/* Said plainly rather than shown as an asterisk: a five-part bucket is not
                            evidence, and the reader has to know that before acting on the row. */}
                        {r.thin_evidence && r.observations > 0 && (
                          <div className="text-xs text-amber-600">{t('too few to rely on')}</div>
                        )}
                      </div>
                    ),
                  },
                ]}
                empty={<EmptyState title={t('Nothing fitted yet')} />}
              />
            </>
          )}
        </SectionCard>
      )}
    </div>
  );
}
