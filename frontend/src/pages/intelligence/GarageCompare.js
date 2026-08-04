// TWO TO FOUR GARAGES, SIDE BY SIDE.
//
// ── THE CAVEAT IS NOT DECORATION ─────────────────────────────────────────────────────────────────
// Case-mix adjustment is indirect standardisation. It is RIGOROUS for garage-vs-fleet and only
// APPROXIMATE for garage-vs-garage when the two do materially different work — the classic SMR
// limitation. This page does exactly the approximate thing, so it shows each garage's work mix
// beside its score and says so in words.
//
// Without the mix, a reader would compare a 74 against a 69 and conclude the first shop is better,
// when one does 36% interior work and the other 28% suspension. The number is not wrong; the
// comparison is weaker than it looks, and hiding that would make this page state a claim the
// statistics do not support.
//
// Every figure comes from the same cached report the list and the profile use. No second
// calculation path — that was the whole point of the convergence.

import { useCallback, useMemo } from 'react';
import { useSearchParams, Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import useEvidence from '../../hooks/useEvidence';
import EvidenceDrawer from '../../components/intelligence/EvidenceDrawer';
import ConfidenceBadge from '../../components/intelligence/ConfidenceBadge';
import Icon from '../../components/ui/Icon';
import Skeleton from '../../components/ui/Skeleton';
import { num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

/** Best value in a row gets the emphasis — but only when the row is comparable at all. */
function bestOf(values, lowerIsBetter) {
  const real = values.filter((v) => v != null);
  if (real.length < 2) return null;
  return lowerIsBetter ? Math.min(...real) : Math.max(...real);
}

export default function GarageCompare() {
  const [params] = useSearchParams();
  const ids = params.get('ids') || '';
  const { t } = useI18n();
  const g = (k, v) => t(`garages.${k}`, v);
  const c = (k, v) => t(`intelligence.compare.${k}`, v);

  const fetcher = useCallback(
    () => api.get('/intelligence/garages/compare', { params: { ids } }).then((r) => r.data?.data),
    [ids]
  );
  const { data, loading, error } = useFetch(fetcher, [ids]);

  const evidence = useEvidence();
  const garages = useMemo(() => data?.garages || [], [data]);

  if (loading) {
    return <div className="mx-auto max-w-6xl p-6"><Skeleton className="h-72 w-full rounded-2xl" /></div>;
  }

  if (error || garages.length < 2) {
    return (
      <div className="mx-auto max-w-3xl p-6">
        <div className="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-200">
          {error || c('needTwo')}
        </div>
        <Link to="/garages" className="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-blue-600">
          <Icon.ChevronDown className="h-4 w-4 rotate-90" /> {t('intelligence.profile.back')}
        </Link>
      </div>
    );
  }

  const fleet = data.fleet || {};

  const rows = [
    {
      key: 'score',
      label: c('row.score'),
      values: garages.map((x) => x.score?.value ?? null),
      fleet: null,
      lowerIsBetter: false,
      fmt: (v) => (v == null ? '—' : v),
    },
    {
      key: 'comeback',
      label: g('stat.comeback'),
      values: garages.map((x) => x.reliability?.comeback_pct ?? null),
      fleet: fleet.comeback_pct,
      lowerIsBetter: true,
      fmt: (v) => (v == null ? '—' : `${Math.round(v)}%`),
    },
    {
      key: 'expected',
      label: c('row.expected'),
      values: garages.map((x) => x.reliability?.expected_pct ?? null),
      fleet: null,
      lowerIsBetter: null, // an expectation is not better or worse — it is the bar this shop is held to
      fmt: (v) => (v == null ? '—' : `${Math.round(v)}%`),
    },
    {
      key: 'repairs',
      label: c('row.repairs'),
      values: garages.map((x) => x.reliability?.n ?? null),
      fleet: null,
      lowerIsBetter: null,
      fmt: (v) => (v == null ? '—' : num(v)),
    },
    {
      key: 'turnaround',
      label: g('stat.turnaround'),
      values: garages.map((x) => x.speed?.days ?? null),
      fleet: fleet.turnaround_days,
      lowerIsBetter: true,
      fmt: (v) => (v == null ? '—' : `${v}${t('dash.unit.d')}`),
    },
  ];

  return (
    <div className="mx-auto max-w-6xl space-y-5 p-6">
      <Link to="/garages" className="inline-flex items-center gap-1 text-sm font-semibold text-slate-500 hover:text-slate-700">
        <Icon.ChevronDown className="h-4 w-4 rotate-90" /> {t('intelligence.profile.back')}
      </Link>

      <h1 className="font-display text-2xl font-bold text-slate-900">{c('title')}</h1>

      {/* THE CAVEAT, above the table rather than under it. Below, it reads as a disclaimer nobody
          gets to; above, it is the frame the reader compares within. */}
      <p className="rounded-xl bg-amber-50 px-4 py-3 text-xs leading-relaxed text-amber-900 ring-1 ring-inset ring-amber-200">
        <span className="font-semibold">{c('caveat.title')} </span>
        {c('caveat.body')}
      </p>

      <div className="overflow-x-auto rounded-2xl bg-white shadow-soft ring-1 ring-inset ring-slate-200">
        <table className="w-full min-w-[40rem] text-sm">
          <thead>
            <tr className="border-b border-slate-100">
              <th className="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-400">
                {c('row.metric')}
              </th>
              {garages.map((x) => (
                <th key={x.vendor_id} className="px-4 py-3 text-start">
                  <Link to={`/intelligence/garages/${x.vendor_id}`} className="block truncate font-semibold text-slate-900 hover:text-blue-600">
                    {x.garage}
                  </Link>
                  <ConfidenceBadge band={x.score?.band} sampleSize={x.reliability?.n} asOf={data.as_of} className="mt-1" />
                </th>
              ))}
              <th className="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-400">
                {c('row.fleet')}
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {rows.map((r) => {
              const best = r.lowerIsBetter == null ? null : bestOf(r.values, r.lowerIsBetter);
              return (
                <tr key={r.key} className="hover:bg-slate-50/60">
                  <td className="px-5 py-2.5 text-xs font-medium text-slate-600">{r.label}</td>
                  {r.values.map((v, i) => (
                    <td
                      key={garages[i].vendor_id}
                      className={`px-4 py-2.5 tabular-nums ${best != null && v === best ? 'font-bold text-emerald-700' : 'text-slate-800'}`}
                    >
                      {r.fmt(v)}
                    </td>
                  ))}
                  <td className="px-4 py-2.5 tabular-nums text-slate-400">{r.fleet != null ? r.fmt(r.fleet) : '—'}</td>
                </tr>
              );
            })}

            {/* THE MIX — what makes the rows above readable. */}
            <tr className="bg-slate-50/60">
              <td className="px-5 py-2.5 align-top text-xs font-semibold text-slate-600">{c('row.mix')}</td>
              {garages.map((x) => (
                <td key={x.vendor_id} className="px-4 py-2.5 align-top">
                  <ul className="space-y-0.5">
                    {(x.mix || []).map((m) => (
                      <li key={m.key} className="flex items-baseline justify-between gap-2 text-[11px]">
                        <span className="truncate text-slate-600">{m.label}</span>
                        <span className="shrink-0 tabular-nums text-slate-400">{m.share_pct}%</span>
                      </li>
                    ))}
                  </ul>
                </td>
              ))}
              <td className="px-4 py-2.5" />
            </tr>

            <tr>
              <td className="px-5 py-2.5 text-xs font-medium text-slate-600">{t('intelligence.evidence.showRepairs')}</td>
              {garages.map((x) => (
                <td key={x.vendor_id} className="px-4 py-2.5">
                  {x.evidence_query_id && (
                    <button
                      type="button"
                      onClick={() => evidence.open(x.evidence_query_id)}
                      className="inline-flex items-center gap-1 text-[11px] font-semibold text-blue-600 hover:text-blue-700"
                    >
                      <Icon.Search className="h-3 w-3" />
                      {c('openEvidence')}
                    </button>
                  )}
                </td>
              ))}
              <td className="px-4 py-2.5" />
            </tr>
          </tbody>
        </table>
      </div>

      <EvidenceDrawer
        open={evidence.isOpen}
        onClose={evidence.close}
        evidence={evidence.evidence}
        loading={evidence.loading}
        error={evidence.error}
        page={evidence.page}
        onPage={evidence.goToPage}
      />
    </div>
  );
}
