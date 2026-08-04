// ONE GARAGE, IN FULL.
//
// The list answers "who should I worry about". This page answers "what is actually going on with
// this one" — the score, the two axes behind it, every repair area it works in, and the evidence
// under each figure.
//
// Every number here comes from the SAME cached report the list is built from. The obvious
// alternative — a leaner per-garage query — would have been faster and would have reintroduced the
// exact failure this platform spent a convergence removing: a profile quietly disagreeing with the
// page it was opened from.

import { useCallback, useMemo } from 'react';
import { useParams, Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import useEvidence from '../../hooks/useEvidence';
import EvidenceDrawer from '../../components/intelligence/EvidenceDrawer';
import IntelligenceMetricCard from '../../components/intelligence/IntelligenceMetricCard';
import ConfidenceBadge from '../../components/intelligence/ConfidenceBadge';
import ScorecardOrigin from '../../components/garages/ScorecardOrigin';
import Badge from '../../components/ui/Badge';
import Icon from '../../components/ui/Icon';
import Skeleton from '../../components/ui/Skeleton';
import { num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

const GRADE_TONE = { strong: 'green', on_par: 'gray', weak: 'red', thin: 'gray', not_graded_exposure: 'gray' };

export default function GarageProfile() {
  const { id } = useParams();
  const { t } = useI18n();
  const g = (k, v) => t(`garages.${k}`, v);
  const p = (k, v) => t(`intelligence.profile.${k}`, v);

  const fetcher = useCallback(
    () => api.get(`/intelligence/garages/${id}`).then((r) => r.data?.data),
    [id]
  );
  const { data, loading, error } = useFetch(fetcher, [id]);

  const evidence = useEvidence();

  const card = data?.garage;
  const domains = useMemo(
    () => (card?.domains || []).slice().sort((a, b) => b.jobs - a.jobs),
    [card]
  );

  if (loading) {
    return (
      <div className="mx-auto max-w-6xl space-y-4 p-6">
        <Skeleton className="h-24 w-full rounded-2xl" />
        <Skeleton className="h-32 w-full rounded-2xl" />
      </div>
    );
  }

  if (error || !card) {
    return (
      <div className="mx-auto max-w-3xl p-6">
        <div className="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800 ring-1 ring-inset ring-rose-200">
          {error || p('notFound')}
        </div>
        <Link to="/garages" className="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-blue-600">
          <Icon.ChevronDown className="h-4 w-4 rotate-90" /> {p('back')}
        </Link>
      </div>
    );
  }

  const rel = card.reliability || {};
  const speed = card.speed || {};

  return (
    <div className="mx-auto max-w-6xl space-y-5 p-6">
      <Link to="/garages" className="inline-flex items-center gap-1 text-sm font-semibold text-slate-500 hover:text-slate-700">
        <Icon.ChevronDown className="h-4 w-4 rotate-90" /> {p('back')}
      </Link>

      {/* Header — the verdict, and immediately how much evidence stands behind it. */}
      <header className="rounded-2xl bg-white px-6 py-5 shadow-soft ring-1 ring-inset ring-slate-200">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="min-w-0">
            <h1 className="font-display text-2xl font-bold text-slate-900">{card.garage}</h1>
            <p className="mt-1 text-sm text-slate-500">
              {p('summary', { jobs: num(card.volume), repairs: num(card.repairs) })}
              {data.rank && <> · {p('rank', { rank: data.rank, of: data.ranked_of })}</>}
            </p>
          </div>
          <div className="flex items-center gap-3">
            {card.score?.value != null ? (
              <>
                <span className="font-display text-4xl font-bold tabular-nums text-slate-900">{card.score.value}</span>
                <ConfidenceBadge
                  band={card.score.band}
                  sampleSize={rel.n}
                  coverage={data.coverage}
                  asOf={data.as_of}
                />
              </>
            ) : (
              <span className="max-w-xs text-sm text-slate-500">{card.score?.reason}</span>
            )}
          </div>
        </div>

        {/* Which measures actually entered the score, and which were skipped for want of data. */}
        {card.score?.coverage?.length > 0 && (
          <p className="mt-3 text-[11px] text-slate-400">
            {p('scoredOn', { axes: card.score.coverage.join(', ') })}
            {card.score.reason && <> — {card.score.reason}</>}
          </p>
        )}
      </header>

      {/* The two axes, each openable onto its repairs. */}
      <div className="grid gap-3 sm:grid-cols-2">
        <IntelligenceMetricCard
          label={g('stat.comeback')}
          value={rel.comeback_pct != null ? Math.round(rel.comeback_pct) : null}
          unit="%"
          tone={rel.vs_expected_pts > 8 ? 'text-red-600' : rel.vs_expected_pts < -8 ? 'text-emerald-600' : 'text-slate-900'}
          hint={rel.expected_pct != null ? p('expectedHint', { exp: Math.round(rel.expected_pct) }) : undefined}
          band={card.score?.band}
          sampleSize={rel.n}
          coverage={data.coverage}
          asOf={data.as_of}
          unavailableReason={rel.measured ? null : rel.reason}
          onEvidence={card.evidence_query_id ? () => evidence.open(card.evidence_query_id) : undefined}
        />
        <IntelligenceMetricCard
          label={g('stat.turnaround')}
          value={speed.days ?? null}
          unit={t('dash.unit.d')}
          hint={speed.expected_days != null ? p('expectedDaysHint', { exp: speed.expected_days }) : undefined}
          sampleSize={speed.n}
          asOf={data.as_of}
          unavailableReason={speed.measured ? null : speed.reason}
        />
      </div>

      {/* Every area it works in — the actionable part. */}
      <section className="rounded-2xl bg-white shadow-soft ring-1 ring-inset ring-slate-200">
        <h2 className="border-b border-slate-100 px-6 py-3 text-sm font-semibold text-slate-800">{p('areas')}</h2>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[42rem] text-xs">
            <thead>
              <tr className="border-b border-slate-100 text-[11px] uppercase tracking-wide text-slate-400">
                <th className="px-6 py-2 text-start font-semibold">{p('col.area')}</th>
                <th className="px-3 py-2 text-end font-semibold">{p('col.jobs')}</th>
                <th className="px-3 py-2 text-end font-semibold">{p('col.comeback')}</th>
                <th className="px-3 py-2 text-end font-semibold">{p('col.fleet')}</th>
                <th className="px-3 py-2 text-end font-semibold">{p('col.days')}</th>
                <th className="px-3 py-2 text-start font-semibold">{p('col.verdict')}</th>
                <th className="px-6 py-2 text-end font-semibold" />
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {domains.map((d) => (
                <tr key={d.key} className="hover:bg-slate-50/60">
                  <td className="px-6 py-2 font-medium text-slate-800">{d.label}</td>
                  <td className="px-3 py-2 text-end tabular-nums text-slate-600">{num(d.jobs)}</td>
                  <td className="px-3 py-2 text-end tabular-nums text-slate-800">
                    {d.graded ? `${Math.round(d.comeback_pct)}%` : '—'}
                  </td>
                  <td className="px-3 py-2 text-end tabular-nums text-slate-400">
                    {d.fleet_comeback_pct != null ? `${Math.round(d.fleet_comeback_pct)}%` : '—'}
                  </td>
                  <td className="px-3 py-2 text-end tabular-nums text-slate-600">{d.return_days ?? '—'}</td>
                  <td className="px-3 py-2">
                    {d.graded ? (
                      <Badge tone={GRADE_TONE[d.grade] || 'gray'} dot>{g(`matrix.cell.${d.grade === 'on_par' ? 'par' : d.grade}`, d.grade)}</Badge>
                    ) : (
                      // An ungraded row says WHY. Silence here reads as a broken page.
                      <span className="text-[11px] text-slate-400">{d.not_graded_reason}</span>
                    )}
                  </td>
                  <td className="px-6 py-2 text-end">
                    {d.graded && d.evidence_query_id && (
                      <button
                        type="button"
                        onClick={() => evidence.open(d.evidence_query_id)}
                        className="text-[11px] font-semibold text-blue-600 hover:text-blue-700"
                      >
                        {t('intelligence.evidence.showRepairs')}
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <ScorecardOrigin provenance={data.provenance} fleet={data.fleet} />

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
