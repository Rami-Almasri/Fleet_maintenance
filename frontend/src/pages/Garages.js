// GARAGES — the supplier page, not the workload page it used to be.
//
// It previously answered only "how busy is this garage and how late is it": jobs, cars in now,
// spend, average delay. Every one of those is a fact about the FLEET's logistics, and none of them
// says whether the work is any good. You could read the old page top to bottom and still not know
// that the shop holding six of your cars is the worst place in the fleet to send a suspension job.
//
// Three questions now, in the order people actually ask them:
//   1. I have this fault — who should fix it?      (the same engine the assign step uses)
//   2. Who is good at what?                        (score, strengths, problems, the area matrix)
//   3. What is happening in each garage right now?  (the old directory, kept whole)
//
// The two data sources are deliberately separate calls. The directory is live operational state and
// has to be fast; the scorecard is a pass over ~37k historical repairs and is cached. Tying the
// freshness of one to the cost of the other is how pages get slow and stay slow.

import { useCallback, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { Card, PageHeader, Spinner, EmptyState } from '../components/ui/Misc';
import Tabs from '../components/ui/Tabs';
import GaragesAnalytics from '../components/analytics/GaragesAnalytics';
import GarageScoreboard from '../components/garages/GarageScoreboard';
import DomainMatrix from '../components/garages/DomainMatrix';
import DomainLeaderboard from '../components/garages/DomainLeaderboard';
import GarageCard from '../components/garages/GarageCard';
import FaultGarageFinder from '../components/garages/FaultGarageFinder';
import ScorecardOrigin from '../components/garages/ScorecardOrigin';
import { num } from '../lib/format';
import { useI18n } from '../i18n/I18nContext';

function Stat({ label, value, tone = 'text-slate-900', hint }) {
  return (
    <Card className="px-5 py-4">
      <p className="text-xs font-medium text-slate-500">{label}</p>
      <p className={`mt-0.5 text-lg font-bold tracking-tight ${tone}`}>{value}</p>
      {hint && <p className="mt-0.5 text-[11px] text-slate-400">{hint}</p>}
    </Card>
  );
}

export default function Garages() {
  const { t, isRTL } = useI18n();
  const g = useCallback((k, v) => t(`garages.${k}`, v), [t]);

  const perfFetcher = useCallback(async () => {
    const { data } = await api.get('/Maintenance/garages');
    return data.data?.garages || [];
  }, []);
  const scoreFetcher = useCallback(async () => {
    const { data } = await api.get('/Maintenance/garage-scorecards');
    return data.data || null;
  }, []);

  const { data: perfData, loading, error } = useFetch(perfFetcher);
  // Its own request: a slow historical pass must never hold the live directory hostage, and a
  // failure in it must leave the operational half of the page working.
  const { data: scoreData, error: scoreError } = useFetch(scoreFetcher);

  const [tab, setTab] = useState('quality');
  const [focus, setFocus] = useState(null);
  const listRef = useRef(null);

  const garages = useMemo(() => perfData || [], [perfData]);
  const cards = useMemo(() => scoreData?.garages || [], [scoreData]);
  const byVendor = useMemo(() => Object.fromEntries(cards.map((c) => [c.vendor_id, c])), [cards]);

  // Jump from any chart into the garage it names. Charts that cannot be drilled into make people
  // screenshot them and go looking manually.
  const pick = useCallback((vendorId) => {
    setFocus(vendorId);
    setTab('directory');
    requestAnimationFrame(() => listRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
  }, []);

  const totals = garages.reduce(
    (a, x) => ({ inNow: a.inNow + x.in_garage_now, overdue: a.overdue + x.overdue_now }),
    { inNow: 0, overdue: 0 },
  );

  // Scored garages first (best first), then the rest by workload — the same ordering rule the
  // scoreboard uses, so moving between tabs does not reshuffle the world.
  const ordered = useMemo(() => {
    const rows = garages.map((x) => ({ perf: x, card: byVendor[x.vendor_id] || null }));
    rows.sort((a, b) => {
      const av = a.card?.score?.value ?? null;
      const bv = b.card?.score?.value ?? null;
      if (av !== bv) return (bv ?? -1) - (av ?? -1);
      return (b.perf.jobs || 0) - (a.perf.jobs || 0);
    });
    return focus ? [...rows].sort((a, b) => (b.perf.vendor_id === focus) - (a.perf.vendor_id === focus)) : rows;
  }, [garages, byVendor, focus]);

  if (loading) return <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>;

  const fleet = scoreData?.fleet || {};

  const TABS = [
    { key: 'quality', label: g('tab.quality') },
    { key: 'areas', label: g('tab.areas') },
    { key: 'directory', label: g('tab.directory') },
  ];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title={g('title')} subtitle={g('subtitle')}>
          <Link to="/maintenance-workflow" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">
            {g('board')} {isRTL ? '←' : '→'}
          </Link>
        </PageHeader>

        {error && <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>}

        <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
          <Stat label={g('kpi.garages')} value={num(garages.length)} />
          <Stat label={g('kpi.inNow')} value={num(totals.inNow)} />
          <Stat label={g('kpi.overdue')} value={num(totals.overdue)} tone={totals.overdue > 0 ? 'text-red-600' : 'text-slate-900'} />
          <Stat
            label={g('kpi.comeback')}
            value={fleet.comeback_pct != null ? `${Math.round(fleet.comeback_pct)}%` : '—'}
            hint={fleet.comeback_n ? g('kpi.comebackHint', { n: num(fleet.comeback_n) }) : undefined}
          />
          <Stat
            label={g('kpi.scored')}
            value={fleet.garages_total ? `${fleet.garages_scored}/${fleet.garages_total}` : '—'}
            hint={g('kpi.scoredHint')}
          />
        </div>

        {garages.length === 0 && <Card><EmptyState title={g('emptyTitle')} message={g('emptyMessage')} /></Card>}

        {garages.length > 0 && (
          <>
            <Tabs tabs={TABS} active={tab} onChange={setTab} ariaLabel={g('title')} />

            {/* The scorecard half degrades on its own. The page keeps working without it. */}
            {scoreError && tab !== 'directory' && (
              <div className="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-inset ring-amber-600/20">
                {g('scoreUnavailable')}
              </div>
            )}

            {tab === 'quality' && (
              <div className="space-y-6">
                <FaultGarageFinder />
                {scoreData && (
                  <>
                    <GarageScoreboard garages={cards} fleet={fleet} onPick={pick} />
                    <DomainMatrix garages={cards} domains={scoreData.domains} onPick={pick} />
                    <ScorecardOrigin provenance={scoreData.provenance} fleet={scoreData.fleet} />
                  </>
                )}
              </div>
            )}

            {tab === 'areas' && (
              <div className="space-y-6">
                {scoreData && (
                  <>
                    <DomainLeaderboard leaderboard={scoreData.leaderboard} domains={scoreData.domains} onPick={pick} />
                    <DomainMatrix garages={cards} domains={scoreData.domains} onPick={pick} />
                    <ScorecardOrigin provenance={scoreData.provenance} fleet={scoreData.fleet} />
                  </>
                )}
              </div>
            )}

            {tab === 'directory' && (
              <div className="space-y-6" ref={listRef}>
                <GaragesAnalytics garages={garages} />
                <div className="space-y-4">
                  {ordered.map(({ perf, card }) => (
                    <GarageCard
                      key={perf.vendor_id}
                      perf={perf}
                      card={card}
                      defaultOpen={focus === perf.vendor_id}
                    />
                  ))}
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}
