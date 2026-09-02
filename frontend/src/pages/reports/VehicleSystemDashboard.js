import { useCallback, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { useI18n } from '../../i18n/I18nContext';
import { SHOW_FINANCIALS } from '../../config/features';
import {
  Alert, CaseTimeline, Chip, CONFIDENCE_TONE, DataOrigin, DataQuality, DateRangeFilter, Donut,
  Durability, IncidentList, Kpi, Panel, PeriodBanner, PeriodProblems, PeriodSummary, ReportShell,
  RiskComponent, SystemStory, toneFor, VehicleBrief, WorkLedger,
} from './reportBlocks';
import { useTx } from './reportI18n';

/**
 * VEHICLE SYSTEM DASHBOARD — one car, one system, and what the record can actually establish.
 *
 * The question is the one asked about every problem car: *has this system been fixed, or are we
 * paying for the same failure again and again?*
 *
 * THE ORDER OF THIS PAGE IS THE ARGUMENT IT MAKES. An earlier version opened with raw workshop notes
 * and a bare 50/100, which read as a confident finding about the car when it was largely a finding
 * about the paperwork. So the page now descends from conclusion to evidence:
 *
 *   A status · B takeaway · C metrics · D incidents · E work · F durability
 *   G scoring · H data quality · I source records
 *
 * A reader who stops after B has the decision and its confidence. A reader who doubts it can walk
 * down to G, H and I and check every number against the rows it came from. Nothing is hidden — the
 * raw notes are one disclosure away inside each incident — but nothing raw is allowed to lead.
 *
 * ON THE DERIVED NUMBERS: the risk score is arithmetic over INCIDENTS (see
 * VehicleSystemEvidenceService), and confidence grades the evidence rather than predicting anything.
 * Neither is a forecast; both can be recomputed by hand from the sections below them.
 */

export default function VehicleSystemDashboard() {
  const { vehicleId } = useParams();
  const [searchParams, setSearchParams] = useSearchParams();
  const system = searchParams.get('system') || 'engine';
  /*
   * THE URL IS THE FILTER. Keeping the period in the query string rather than in component state is
   * what makes a filtered report a real document: it survives a refresh, it can be sent to somebody,
   * Print / Save as PDF prints the period that is in the address bar, and the browser's back button
   * walks through the periods the reader actually looked at. Absent params mean ALL HISTORY, so every
   * existing link keeps returning exactly the report it returned before this filter existed.
   */
  const from = searchParams.get('from') || null;
  const to = searchParams.get('to') || null;
  const [systems, setSystems] = useState([]);
  const { t, tf, tp } = useI18n();
  const tx = useTx();

  /** Rewrite the query string, dropping keys that are back to their default. */
  const setParams = (next) => {
    const merged = { system, from, to, ...next };
    setSearchParams(
      Object.fromEntries(Object.entries(merged).filter(([, v]) => v !== null && v !== '' && v !== undefined)),
    );
  };

  useFetch(
    useCallback(async () => {
      const res = await api.get('/reports/systems');
      setSystems(res.data?.data || []);
      return res.data?.data;
    }, []),
    [],
    { revalidateOnFocus: false }
  );

  const { data, loading, error, reload } = useFetch(
    useCallback(async () => {
      // The period is a QUERY parameter, not a client-side filter: the service narrows by vehicle,
      // system and date in SQL, so a three-month window reads three months of rows and no more.
      const res = await api.get(`/reports/vehicle-system/${vehicleId}`, {
        params: { system, ...(from ? { from } : {}), ...(to ? { to } : {}) },
      });
      return res.data?.data;
    }, [vehicleId, system, from, to]),
    [vehicleId, system, from, to],
    // A history dashboard is a document, not a live board — refresh is the explicit button.
    { revalidateOnFocus: false }
  );

  const verdict = data?.verdict;
  const risk = data?.risk;
  const timeline = data?.timeline || [];
  // The donut's hole shows the total across the slices, not the timeline length: the mix is capped at
  // the top eight findings, so the two numbers legitimately differ on a long history.
  const mixTotal = (data?.failure_mix || []).reduce((sum, m) => sum + m.count, 0);

  // The system's name is a label, not data, so it comes from the catalog; the service's English is
  // the fallback for a system key the catalog has not learned yet.
  // tf(), not t(): a key the catalog lacks must fall back to the service's English, and t() would
  // return the dot-path itself.
  const systemLabel = data?.system
    ? tf(`reportSystem.systems.${data.system.key}`, data.system.label)
    : t('reportSystem.ui.system');

  /* Every generated sentence arrives as a node; resolve it once here so the presentation blocks below
     stay dumb and are shared unchanged with the daily report. */
  const components = (risk?.components || []).map((c) => ({
    ...c,
    label: tx(c.i18n?.label, c.label),
    display: t('reportSystem.ui.outOf', { n: c.points, max: c.max }),
    input: tx(c.i18n?.input, c.input),
    rule: tx(c.i18n?.rule, c.rule),
    detail: c.i18n?.detail ? tx(c.i18n.detail) : c.detail,
    caveat: c.i18n?.caveat ? tx(c.i18n.caveat) : c.caveat,
    evidence: (c.evidence || []).map((e) => ({
      ...e,
      text: tx(e.text_i18n, e.text),
      meta: tx(e.meta_i18n, e.meta),
    })),
  }));

  const provenance = data?.provenance
    ? Object.fromEntries(
        ['source', 'window', 'grouping', 'split', 'derived', 'omitted']
          .map((k) => [k, tx(data.provenance.i18n?.[k], data.provenance[k])])
          .concat([['counts', data.provenance.counts]]),
      )
    : null;

  return (
    <ReportShell
      footer={
        data
          ? t('reportSystem.ui.footer', { system: systemLabel, vehicle: data.vehicle.label })
          : null
      }
    >
      <section className="ir-hero">
        <div className="ir-panel ir-hero-main">
          <div className="ir-eyebrow">{t('reportSystem.ui.eyebrow', { system: systemLabel })}</div>
          <h1>
            {t('reportSystem.ui.title', { system: systemLabel })}
            <br />
            {data?.vehicle?.label || t('reportSystem.ui.vehicleN', { id: vehicleId })}
          </h1>
          <div className="ir-sub">{t('reportSystem.ui.intro')}</div>
        </div>
        <div className={`ir-panel ir-hero-side tone-${verdict?.tone || 'neutral'}`}>
          <div className="ir-small">{t('reportSystem.ui.whatRecordSupports')}</div>
          <div className={`ir-big ${verdict?.tone === 'danger' ? 'tone-danger' : verdict?.tone === 'warn' ? 'tone-warntext' : verdict?.tone === 'ok' ? 'tone-oktext' : ''}`}>
            {loading ? '—' : tx(verdict?.decision_i18n, verdict?.decision) || '—'}
          </div>
          {/*
            Two lines only. The score and the evidence grade used to sit here as well, and a reader
            met four numbers before a single sentence. They now live in the story panel below, where
            the score is a footnote pointing at its own arithmetic.
          */}
          <div className="ir-small">{t('reportSystem.confidenceLevel.label')}</div>
          <div className="ir-confidence">
            {loading || !data ? (
              '—'
            ) : (
              <Chip tone={CONFIDENCE_TONE[data.confidence.level]}>
                {t(`reportSystem.confidenceLevel.${data.confidence.level}`)}
              </Chip>
            )}
          </div>
        </div>
      </section>

      <div className="ir-controls ir-no-print">
        <label className="ir-small" style={{ marginTop: 0 }} htmlFor="ir-system">
          {t('reportSystem.ui.system')}
        </label>
        <select
          id="ir-system"
          className="ir-control"
          value={system}
          // Changing the system keeps the period: a reader comparing engine and brakes over one
          // window should not have to retype the window.
          onChange={(e) => setParams({ system: e.target.value })}
        >
          {systems.map((s) => (
            <option key={s.key} value={s.key}>
              {tf(`reportSystem.systems.${s.key}`, s.label)}
            </option>
          ))}
        </select>
        <Link className="ir-button" to={`/vehicles/${vehicleId}`}>
          {t('reportSystem.ui.openVehicle')}
        </Link>
        <button type="button" className="ir-button" onClick={() => reload()}>
          {t('reportSystem.ui.refresh')}
        </button>
        <button type="button" className="ir-button" onClick={() => window.print()}>
          {t('reportSystem.ui.print')}
        </button>
      </div>

      <DateRangeFilter
        from={from}
        to={to}
        busy={loading}
        onApply={(nextFrom, nextTo) => setParams({ from: nextFrom, to: nextTo })}
        onClear={() => setParams({ from: null, to: null })}
      />

      {/* Prints. A PDF that does not say which period it covers reads as the whole history. */}
      <PeriodBanner period={data?.period} />

      {error ? (
        <Panel title={t('reportSystem.ui.errorTitle')}>
          <Alert tone="danger" heading={t('reportSystem.ui.errorHeading')}>
            {error}
          </Alert>
        </Panel>
      ) : null}

      {loading ? (
        <Panel>
          <div className="ir-empty">{t('reportSystem.ui.loading')}</div>
        </Panel>
      ) : null}

      {!loading && data ? (
        <>
          {/* ── THE PERIOD, COUNTED. Visits, distinct problems and repeats kept apart. ───── */}
          <Panel
            title={t('reportSystem.period.summaryTitle')}
            hint={
              data.period?.active
                ? t('reportSystem.period.summaryHintFiltered')
                : t('reportSystem.period.summaryHintAll')
            }
          >
            <PeriodSummary summary={data.period_summary} period={data.period} />
          </Panel>

          {/* ── WHAT WENT WRONG, grouped by fault rather than listed by date. ───────────── */}
          <Panel
            title={
              data.period?.active
                ? t('reportSystem.period.problemsTitle')
                : t('reportSystem.period.problemsTitleAll')
            }
            hint={t('reportSystem.period.problemsHint')}
          >
            <PeriodProblems
              problems={data.problems}
              workshopOnly={data.workshop_only}
              period={data.period}
            />
          </Panel>

          {/*
            ── THE FAILURE SEQUENCE ────────────────────────────────────────────────────────────
            The same rail the printed report has always used: one dot per incident, coloured by
            severity, oldest at the top. The card list below carries the audit trail (source rows,
            join reasons); this carries the SHAPE — how often, how close together, and whether the
            dots cluster. A stack of cards cannot show that, which is why both exist.
          */}
          <Panel
            title={t('reportSystem.sequence.title', { system: systemLabel })}
            hint={t('reportSystem.sequence.hint')}
          >
            <CaseTimeline
              empty={
                data.period?.active
                  ? t('reportSystem.period.emptyFiltered')
                  : t('reportSystem.ui.noEvents')
              }
              items={(data.incidents || []).map((inc, i) => ({
                key: `${inc.start}-${i}`,
                severity: inc.severity,
                meta: [
                  inc.end && inc.end !== inc.start ? `${inc.start} → ${inc.end}` : inc.start,
                  inc.garages?.length ? inc.garages.join(', ') : t('reportSystem.notRecorded'),
                  inc.row_count > 1 ? tp('reportSystem.incident.rowCount', inc.row_count) : null,
                ],
                chip: inc.severity ? (
                  <Chip tone={toneFor(inc.severity)}>{t(`reportSystem.severity.${inc.severity}`)}</Chip>
                ) : (
                  <Chip tone="neutral">{t('reportSystem.incident.notConfirmed')}</Chip>
                ),
                // The fault as the record names it, never this page's paraphrase of it.
                title: inc.fault || t('reportSystem.incident.noFaultNamed'),
                desc: (inc.records || [])
                  .flatMap((r) => (r.system_lines?.length ? r.system_lines : r.all_lines || []))
                  .filter((v, j, a) => v && a.indexOf(v) === j)
                  .join('\n'),
              }))}
            />
          </Panel>

          {/* ── THE ANSWER. Plain sentences, nothing else competing with them. ───────────── */}
          <Panel title={t('reportSystem.story.title', { system: systemLabel })}>
            <SystemStory data={data} systemLabel={systemLabel} />
          </Panel>

          {/* ── D. What happened, visit by visit. */}
          <Panel
            title={t('reportSystem.ui.incidentsTitle')}
            hint={t('reportSystem.ui.incidentsHint', {
              incidents: data.facts.incidents,
              records: data.facts.records,
            })}
          >
            {data.incidents.length === 0 ? (
              // "No event has ever been recorded" is a claim about the whole record and would be
              // false under a filter. An empty PERIOD says so in its own words.
              <div className="ir-empty">
                {data.period?.active
                  ? t('reportSystem.period.emptyFiltered')
                  : t('reportSystem.ui.noEvents')}
              </div>
            ) : (
              <IncidentList incidents={data.incidents} />
            )}
          </Panel>

          {/*
            The supporting numbers, AFTER the story rather than in front of it. Each tile says which
            question it answers: five count the selected period, and the risk score is all-history by
            construction. Six unmarked numbers in a row would read as six answers to one question, and
            the odd one out is the one a manager would act on.
          */}
          <section className="ir-kpis">
            {(data.kpis || []).map((k) => (
              <Kpi
                key={k.key || k.label}
                label={tx(k.label_i18n, k.label)}
                value={k.value}
                note={
                  data.period?.active && k.scope
                    ? `${t(`reportSystem.period.scope.${k.scope}`)} · ${tx(k.note_i18n, k.note)}`
                    : tx(k.note_i18n, k.note)
                }
              />
            ))}
          </section>

          <section className="ir-grid2">
            {/* ── E. Repairs & replacements, read out of free text. */}
            <Panel title={t('reportSystem.ui.workTitle')} hint={t('reportSystem.ui.workHint')}>
              <WorkLedger work={data.work} />
            </Panel>

            {/* ── F. Did the repair hold? */}
            <Panel title={t('reportSystem.ui.durabilityTitle')} hint={t('reportSystem.ui.durabilityHint')}>
              <Durability items={data.durability} />
            </Panel>
          </section>

          {/* ── G. Evidence & calculations. */}
          <Panel
            title={
              data.period?.active
                ? t('reportSystem.period.riskTitleHistory')
                : t('reportSystem.ui.riskTitle')
            }
            hint={
              risk?.ceiling < 100
                ? t('reportSystem.ui.riskHintCeiling', { score: risk?.score ?? 0, ceiling: risk.ceiling })
                : t('reportSystem.ui.riskHint', { score: risk?.score ?? 0 })
            }
          >
            <div className="ir-risk-grid">
              {components.map((c) => (
                <RiskComponent key={c.key} component={c} />
              ))}
            </div>
            <Alert tone="neutral" heading={t('reportSystem.ui.howToRead')}>
              {tx(risk?.basis_i18n, risk?.basis)}
            </Alert>
            {/*
              THE ONE NUMBER ON THIS PAGE THAT DOES NOT MOVE WITH THE FILTER, said out loud. Its
              arithmetic is all-history by construction — recency counts days from today, the ceiling
              describes the whole record — so recomputing it over a chosen window would produce a
              number on the same 0–100 scale that means something else entirely.
            */}
            {data.period?.active ? (
              <Alert tone="warn" heading={t('reportSystem.period.riskScopeHeading')}>
                {t('reportSystem.period.riskScopeBody')}
              </Alert>
            ) : null}
          </Panel>

          {/* ── H. Data quality — what is weak in the records themselves. */}
          <Panel
            title={t('reportSystem.ui.dataQualityTitle')}
            hint={
              data.data_quality_scope === 'period'
                ? t('reportSystem.period.dataQualityHintPeriod')
                : t('reportSystem.ui.dataQualityHint')
            }
          >
            <DataQuality warnings={data.data_quality} />
          </Panel>

          {/* ── Reference: the car itself and the shape of its faults. Neither answers the
                 question this page exists for, so both sit at the bottom. */}
          <section className="ir-grid2">
            <Panel title={t('reportSystem.ui.aboutCar')} hint={t('reportSystem.ui.aboutCarHint')}>
              <VehicleBrief rows={data.brief || []} showMoney={SHOW_FINANCIALS} />
            </Panel>

            <Panel
              title={t('reportSystem.ui.whatGoesWrong')}
              hint={
                mixTotal < timeline.length
                  ? t('reportSystem.ui.mixCapped', {
                      shown: (data.failure_mix || []).length,
                      hidden: timeline.length - mixTotal,
                    })
                  : t('reportSystem.ui.mixHint')
              }
            >
              {(data.failure_mix || []).length === 0 ? (
                <div className="ir-empty">
                  {data.period?.active
                    ? t('reportSystem.period.emptyFiltered')
                    : t('reportSystem.ui.noEvents')}
                </div>
              ) : (
                <Donut slices={data.failure_mix} unit={tp('reportSystem.ui.eventUnit', mixTotal)} />
              )}
            </Panel>
          </section>

          {/* ── I. Provenance. The per-record source rows live inside each incident above. */}
          <DataOrigin provenance={provenance} />
        </>
      ) : null}
    </ReportShell>
  );
}
