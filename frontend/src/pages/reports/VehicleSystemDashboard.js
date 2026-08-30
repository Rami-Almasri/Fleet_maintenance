import { useCallback, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { useI18n } from '../../i18n/I18nContext';
import { SHOW_FINANCIALS } from '../../config/features';
import {
  Alert, Chip, CONFIDENCE_TONE, DataOrigin, DataQuality, Donut, Durability, IncidentList, Kpi,
  Panel, ReportShell, RiskComponent, SystemStory, VehicleBrief, WorkLedger,
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
  const [systems, setSystems] = useState([]);
  const { t, tf, tp } = useI18n();
  const tx = useTx();

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
      const res = await api.get(`/reports/vehicle-system/${vehicleId}`, { params: { system } });
      return res.data?.data;
    }, [vehicleId, system]),
    [vehicleId, system],
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
          onChange={(e) => setSearchParams({ system: e.target.value })}
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
              <div className="ir-empty">{t('reportSystem.ui.noEvents')}</div>
            ) : (
              <IncidentList incidents={data.incidents} />
            )}
          </Panel>

          {/* ── The supporting numbers, AFTER the story rather than in front of it. */}
          <section className="ir-kpis">
            {(data.kpis || []).map((k) => (
              <Kpi
                key={k.key || k.label}
                label={tx(k.label_i18n, k.label)}
                value={k.value}
                note={tx(k.note_i18n, k.note)}
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
            title={t('reportSystem.ui.riskTitle')}
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
          </Panel>

          {/* ── H. Data quality — what is weak in the records themselves. */}
          <Panel title={t('reportSystem.ui.dataQualityTitle')} hint={t('reportSystem.ui.dataQualityHint')}>
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
                <div className="ir-empty">{t('reportSystem.ui.noEvents')}</div>
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
