import { useCallback, useMemo, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { useI18n } from '../../i18n/I18nContext';
import { SHOW_FINANCIALS } from '../../config/features';
import { openVehicleProfileReport } from '../../lib/vehicleProfileReport';
import {
  Alert, Chip, DataOrigin, DateRangeFilter, Kpi, Panel, PeriodBanner, PeriodProblems,
  ReportShell, toneFor, VehicleBrief,
} from './reportBlocks';
import {
  ContractTable, GarageTable, MonthColumns, RankedFaults, SystemFilter, SystemMix,
} from './vehicleReportBlocks';
import { useTx } from './reportI18n';

/**
 * VEHICLE REPORT — one car, everything wrong with it, on one page.
 *
 * This page replaces two things that were never one: a printable dossier of what the car IS, and a
 * system dashboard of what the car has SUFFERED — which opened on the engine and made a reader pick a
 * system before it would tell them anything. Neither answered the question people actually walk up
 * with, which is *what is wrong with this car?*
 *
 * THE ORDER OF THE PAGE IS THE ANSWER TO THAT QUESTION:
 *
 *   A  the headline — how many problems, how many came back
 *   B  the ranked problem list — "this car has problems with 1, 2, 3"
 *   C  the shape — which systems, and when the trouble happened
 *   D  the detail — every occurrence, with the garage, the contract and the workshop's own words
 *   E  where and on what paper — garages and contracts
 *   F  the car itself, and where every number came from
 *
 * A reader who stops after B has the answer. A reader who doubts it walks into D and reads the note
 * the fitter typed. Nothing is summarised without its evidence being one section away.
 *
 * WHY THE FILTERS ARE CLIENT-SIDE AND THE PERIOD IS NOT. The period narrows the QUERY — a three-month
 * window reads three months of rows in SQL, and the URL carries it so a filtered report can be sent to
 * somebody and printed as what it says it is. The system and fault filters narrow what is already on
 * the page: every system is in the payload by construction (the ranking is meaningless otherwise), so
 * asking the server again would return the same rows and cost a round trip to hide some of them.
 */
export default function VehicleReport() {
  const { vehicleId } = useParams();
  const [searchParams, setSearchParams] = useSearchParams();
  const from = searchParams.get('from') || null;
  const to = searchParams.get('to') || null;
  /*
   * THE SYSTEM IS IN THE URL AND IN THE REQUEST, not in a filter over what is already on screen.
   *
   * Choosing "Bodywork" has to narrow the WHOLE report — the counters, the ranking, the month chart,
   * the garages, the contracts — and the service is the only place that can do that without a second
   * implementation of every rollup living in the browser and drifting from it. Being in the URL is
   * what makes the narrowed report a document: it survives a refresh, it can be sent to somebody, and
   * Print produces the report the address bar describes.
   */
  const system = searchParams.get('system') || null;
  const { t, tf, tp, lang } = useI18n();
  const tx = useTx();

  // The fault drill-down stays local: it narrows to one row of what the server already sent, and
  // putting it in the history stack would make Back mean "un-click" rather than "leave the page".
  const [fault, setFault] = useState(null);
  const [repeatsOnly, setRepeatsOnly] = useState(false);

  const setParams = (next) => {
    const merged = { from, to, system, ...next };
    setSearchParams(
      Object.fromEntries(Object.entries(merged).filter(([, v]) => v !== null && v !== '' && v !== undefined)),
    );
  };

  const { data, loading, error, reload } = useFetch(
    useCallback(async () => {
      const res = await api.get(`/reports/vehicle-overview/${vehicleId}`, {
        params: {
          ...(from ? { from } : {}),
          ...(to ? { to } : {}),
          ...(system ? { system } : {}),
        },
      });
      return res.data?.data;
    }, [vehicleId, from, to, system]),
    [vehicleId, from, to, system],
    // A history report is a document, not a live board — refresh is the explicit button.
    { revalidateOnFocus: false },
  );

  /*
   * The dossier payload, for the printable PDF only. It is fetched beside the report rather than
   * folded into it because the two answer different questions and one must not be able to break the
   * other: if this request fails the page still renders in full, and only the PDF button goes away.
   */
  const { data: profile } = useFetch(
    useCallback(async () => (await api.get(`/Vehicle/${vehicleId}/profile`)).data.data, [vehicleId]),
    [vehicleId],
    { revalidateOnFocus: false },
  );

  const summary = data?.summary;
  const systems = data?.systems || [];

  /**
   * WORST FIRST. The service already ranks, and this re-asserts it here rather than trusting the
   * order an array happened to arrive in: the whole page is an argument about what matters most, and
   * a list that silently renders in payload order looks identical until the day it is wrong.
   */
  const ranked = useMemo(
    () => [...(data?.problems || [])].sort(
      (a, b) => b.occurrences - a.occurrences || String(b.last_seen || '').localeCompare(String(a.last_seen || '')),
    ),
    [data],
  );

  /**
   * The detail list. The SYSTEM is already applied — the payload only contains that system — so what
   * is left here narrows within it: one fault, or only the faults that came back.
   */
  const shown = useMemo(() => {
    let list = ranked;
    if (repeatsOnly) list = list.filter((p) => p.repeated);
    if (fault) list = list.filter((p) => p.key === fault);
    return list;
  }, [ranked, fault, repeatsOnly]);

  /** Visits that named nothing are not part of one fault's story, so a drill-down drops them. */
  const shownUnnamed = useMemo(
    () => (fault || repeatsOnly ? [] : data?.workshop_only || []),
    [data, fault, repeatsOnly],
  );

  const filtered = Boolean(system || fault || repeatsOnly);
  const clearFilters = () => { setFault(null); setRepeatsOnly(false); setParams({ system: null }); };

  /** Choosing a system re-reads the report; the fault drill-down inside the old one cannot survive it. */
  const chooseSystem = (next) => { setFault(null); setParams({ system: next }); };

  const provenance = data?.provenance
    ? Object.fromEntries(
        ['source', 'window', 'grouping', 'split', 'derived', 'omitted']
          .map((k) => [k, tx(data.provenance.i18n?.[k], data.provenance[k])])
          .concat([['counts', data.provenance.counts]]),
      )
    : null;

  return (
    <ReportShell footer={data ? t('reportVehicle.footer', { vehicle: data.vehicle.label }) : null}>
      {/* ── A. WHAT THIS CAR IS, AND WHAT IS WRONG WITH IT ───────────────────────────────────── */}
      <section className="ir-hero">
        <div className="ir-panel ir-hero-main">
          <div className="ir-eyebrow">{t('reportVehicle.eyebrow')}</div>
          <h1>{data?.vehicle?.label || t('reportVehicle.vehicleN', { id: vehicleId })}</h1>
          <div className="ir-sub">{t('reportVehicle.intro')}</div>
        </div>
        <div className={`ir-panel ir-hero-side tone-${summary?.repeated_faults ? 'warn' : 'neutral'}`}>
          <div className="ir-small">{t('reportVehicle.hero.label')}</div>
          <div className="ir-big">
            {loading || !summary ? '—' : tp('reportVehicle.hero.problems', summary.named_faults)}
          </div>
          <div className="ir-small">
            {summary
              ? summary.repeated_faults
                ? t('reportVehicle.hero.repeats', {
                    repeated: summary.repeated_faults, returns: summary.returns,
                  })
                : t('reportVehicle.hero.noRepeats')
              : ''}
          </div>
          {summary?.worst_severity ? (
            <div className="ir-confidence">
              <Chip tone={toneFor(summary.worst_severity)}>
                {t(`reportSystem.severity.${summary.worst_severity}`)}
              </Chip>
            </div>
          ) : null}
        </div>
      </section>

      <div className="ir-controls ir-no-print">
        <Link className="ir-button" to={`/vehicles/${vehicleId}`}>{t('reportVehicle.openVehicle')}</Link>
        {/* The deep dive: one system's whole argument, with the risk score and its arithmetic. It opens
            on whichever system the reader is filtered to, so the two pages agree about the subject. */}
        <Link className="ir-button" to={`/reports/vehicle-system/${vehicleId}?system=${system || systems[0]?.key || 'engine'}`}>
          {t('reportVehicle.openSystem')}
        </Link>
        <button type="button" className="ir-button" onClick={() => reload()}>{t('reportVehicle.refresh')}</button>
        <button type="button" className="ir-button" onClick={() => window.print()}>{t('reportVehicle.print')}</button>
        {profile ? (
          <button type="button" className="ir-button" onClick={() => openVehicleProfileReport(profile, t, lang)}>
            {t('reportVehicle.dossier')}
          </button>
        ) : null}
      </div>

      <DateRangeFilter
        from={from}
        to={to}
        busy={loading}
        onApply={(nextFrom, nextTo) => setParams({ from: nextFrom, to: nextTo })}
        onClear={() => setParams({ from: null, to: null })}
      />

      <PeriodBanner period={data?.period} />

      {error ? (
        <Panel title={t('reportVehicle.errorTitle')}>
          <Alert tone="danger" heading={t('reportVehicle.errorHeading')}>{error}</Alert>
        </Panel>
      ) : null}

      {loading ? <Panel><div className="ir-empty">{t('reportVehicle.loading')}</div></Panel> : null}

      {!loading && data ? (
        <>
          {/*
            THE SYSTEM PICKER SITS AT THE TOP, because what it changes is the whole page below it. It
            used to live above the detail list, which made a page-wide filter look like a filter on one
            section — the reader chose Bodywork and every chart above still showed the whole car.
          */}
          <SystemFilter systems={systems} value={system} onChange={chooseSystem} />

          {/* A narrowed report says so, on screen and on the printed page. */}
          {system ? (
            <div className="ir-filter-note">
              {t('reportVehicle.filter.scoped', {
                system: tf(`reportSystem.systems.${system}`, data.system?.label || system),
              })}
            </div>
          ) : null}

          {/*
            The counters, each saying what it counts. Visits and problems and occurrences are three
            different numbers over the same records, and a row of unlabelled figures would read as one
            number stated four ways. @see VehicleReportOverviewService::summary
          */}
          <section className="ir-kpis">
            <Kpi label={t('reportVehicle.kpi.visits')} value={summary.workshop_visits}
                 note={tp('reportVehicle.kpi.visitsNote', summary.source_records)} />
            <Kpi label={t('reportVehicle.kpi.problems')} value={summary.named_faults}
                 note={tp('reportVehicle.kpi.problemsNote', summary.fault_occurrences)} />
            <Kpi label={t('reportVehicle.kpi.repeats')} value={summary.repeated_faults}
                 note={tp('reportVehicle.kpi.repeatsNote', summary.returns)} />
            <Kpi label={t('reportVehicle.kpi.systems')} value={summary.systems_affected}
                 note={t('reportVehicle.kpi.systemsNote')} />
            <Kpi label={t('reportVehicle.kpi.garages')} value={summary.garages}
                 note={t('reportVehicle.kpi.garagesNote')} />
            <Kpi label={t('reportVehicle.kpi.unnamed')} value={summary.workshop_only_visits}
                 note={t('reportVehicle.kpi.unnamedNote')} />
          </section>

          {/* ── B + C. THE ANSWER, AND ITS SHAPE ──────────────────────────────────────────────── */}
          <section className="ir-grid2">
            <Panel
              title={t('reportVehicle.ranked.title')}
              hint={t('reportVehicle.ranked.hint')}
            >
              <RankedFaults
                items={ranked.slice(0, 12)}
                selected={fault}
                onSelect={setFault}
                empty={t('reportVehicle.empty')}
              />
            </Panel>

            <div className="ir-stack">
              {/* THE ONE PANEL THAT STAYS WHOLE-CAR, and says so. Under a system filter it is the
                  context for the choice — where this system sits in the car's trouble — and a donut
                  narrowed to one slice would be no chart at all. */}
              <Panel
                title={t('reportVehicle.mix.title')}
                hint={system ? t('reportVehicle.mix.hintWhole') : t('reportVehicle.mix.hint')}
              >
                <SystemMix systems={systems} selected={system} empty={t('reportVehicle.empty')} />
              </Panel>
              <Panel title={t('reportVehicle.months.title')} hint={t('reportVehicle.months.hint')}>
                <MonthColumns months={data.months} empty={t('reportVehicle.empty')} />
              </Panel>
            </div>
          </section>

          {/* ── D. EVERY OCCURRENCE, under whatever the reader has narrowed to ─────────────────── */}
          <Panel
            title={t('reportVehicle.detail.title')}
            hint={t('reportVehicle.detail.hint')}
            actions={
              <div className="ir-filter-bar ir-no-print">
                <button
                  type="button"
                  className={`ir-filter-chip ${repeatsOnly ? 'is-active' : ''}`}
                  onClick={() => setRepeatsOnly((v) => !v)}
                  aria-pressed={repeatsOnly}
                >
                  {t('reportVehicle.filter.repeatsOnly')}
                </button>
                {filtered ? (
                  <button type="button" className="ir-filter-chip" onClick={clearFilters}>
                    {t('reportVehicle.filter.clear')}
                  </button>
                ) : null}
              </div>
            }
          >
            {/* The filter says out loud what it is hiding. A filtered list that looks unfiltered is
                how a reader concludes a car is clean when they are looking at one system of nine. */}
            {filtered ? (
              <div className="ir-filter-note">
                {t('reportVehicle.filter.showing', {
                  shown: shown.length,
                  total: ranked.length,
                  system: system ? tf(`reportSystem.systems.${system}`, data.system?.label || system) : t('reportVehicle.filter.all'),
                })}
                {fault ? ` · ${shown[0]?.fault || ''}` : ''}
              </div>
            ) : null}

            <PeriodProblems problems={shown} workshopOnly={shownUnnamed} period={data.period} />
          </Panel>

          {/* ── E. WHERE, AND ON WHAT PAPER ───────────────────────────────────────────────────── */}
          <section className="ir-grid2">
            <Panel title={t('reportVehicle.garages.title')} hint={t('reportVehicle.garages.hint')}>
              <GarageTable garages={data.garages} empty={t('reportVehicle.empty')} />
            </Panel>
            <Panel title={t('reportVehicle.contracts.title')} hint={t('reportVehicle.contracts.hint')}>
              <ContractTable contracts={data.contracts} empty={t('reportVehicle.contracts.empty')} />
            </Panel>
          </section>

          {/* ── F. THE CAR, AND WHERE EVERY NUMBER CAME FROM ──────────────────────────────────── */}
          <Panel title={t('reportVehicle.about.title')} hint={t('reportVehicle.about.hint')}>
            <VehicleBrief rows={data.brief || []} showMoney={SHOW_FINANCIALS} />
          </Panel>

          <DataOrigin provenance={provenance} />
        </>
      ) : null}
    </ReportShell>
  );
}
