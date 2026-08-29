import { useCallback, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { useI18n } from '../../i18n/I18nContext';
import {
  Alert, Chip, DataOrigin, Donut, Kpi, Panel, ReportShell, RiskComponent, toneFor,
} from './reportBlocks';
import { useTx } from './reportI18n';

/**
 * VEHICLE SYSTEM DASHBOARD — one car, one system, the whole story.
 *
 * The question is the one that gets asked about a problem car and has never had a single place to be
 * answered: *has this system actually been fixed, or have we been paying for the same failure again
 * and again?* The page isolates every recorded event belonging to ONE system (engine, brakes,
 * transmission …), puts them in order, shows what was replaced and whether a further failure followed,
 * and states what the record supports.
 *
 * ON THE ONE DERIVED NUMBER: the risk score is arithmetic over five counts, and the page prints each
 * component, the count it read and the points that count earned, right above the timeline those counts
 * came from. It is not a prediction and carries no confidence — a reader who disagrees can check the
 * sum against the timeline by hand, which is the whole point of showing it that way.
 */

const VERDICT_TONE = { danger: 'danger', warn: 'warn', ok: 'ok', neutral: 'neutral' };

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
          <div className="ir-small">{t('reportSystem.ui.healthIndex')}</div>
          <div className="ir-big">
            {loading ? '—' : t('reportSystem.ui.outOf', { n: risk?.health ?? '—', max: 100 })}
          </div>
          <div className="ir-small">{t('reportSystem.ui.currentReading')}</div>
          <div className="ir-big sm">
            {loading ? '—' : tx(verdict?.status_i18n, verdict?.status) || '—'}
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

            <Panel title={t('reportSystem.ui.decision')} hint={t('reportSystem.ui.decisionHint')}>
              <Alert
                tone={VERDICT_TONE[verdict?.tone] || 'neutral'}
                heading={tx(verdict?.decision_i18n, verdict?.decision)}
              >
                {tx(verdict?.status_i18n, verdict?.status)}. {tx(verdict?.rule_i18n, verdict?.rule)}
              </Alert>
              <Alert tone="neutral" heading={t('reportSystem.ui.cannotTellHeading')}>
                {t('reportSystem.ui.cannotTell')}
              </Alert>
              {data.vehicle?.vin ? (
                <Alert tone="neutral" heading={t('reportSystem.ui.vehicle')}>
                  {data.vehicle.label}
                  {data.vehicle.vin ? ` · ${t('reportSystem.ui.vin')} ${data.vehicle.vin}` : ''}
                </Alert>
              ) : null}
            </Panel>
          </section>

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

          <section className="ir-grid2">
            <Panel
              title={t('reportSystem.ui.timelineTitle', { system: systemLabel })}
              hint={tp('reportSystem.ui.recordedEvents', timeline.length)}
            >
              {timeline.length === 0 ? (
                <div className="ir-empty">
                  {t('reportSystem.ui.timelineEmpty', { system: systemLabel })}
                </div>
              ) : (
                <div className="ir-timeline">
                  {timeline.map((e, i) => (
                    <div key={`${e.ref}-${i}`} className={`ir-titem sev-${e.severity || 'none'}`}>
                      <div className="ir-tdate">
                        <span>{e.date}</span>
                        <span>·</span>
                        <span>{e.garage === 'Not recorded' ? t('reportSystem.notRecorded') : e.garage}</span>
                        <Chip tone={toneFor(e.severity)}>
                          {t(`reportSystem.severity.${e.severity || 'unrated'}`)}
                        </Chip>
                        <Chip tone="info">
                          {t(`reportSystem.source.${e.source === 'ticket' ? 'ticket' : 'workshopLog'}`)}
                        </Chip>
                        {e.recurred ? <Chip tone="danger">{t('reportSystem.ui.recurred')}</Chip> : null}
                        {e.major_work ? <Chip tone="warn">{t('reportSystem.ui.majorWork')}</Chip> : null}
                      </div>
                      <div className="ir-tphase">{e.finding}</div>
                      <div className="ir-tdesc">
                        {e.detail}
                        {'\n'}
                        <span className="ir-muted">
                          {t('reportSystem.ui.outcome')}: {tx(e.outcome_i18n, e.outcome)}
                        </span>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </Panel>

            <Panel title={t('reportSystem.ui.replacedTitle')} hint={t('reportSystem.ui.replacedHint')}>
              <table className="ir-side">
                <colgroup>
                  <col className="c-num" />
                  <col className="c-name" />
                  <col />
                </colgroup>
                <thead>
                  <tr>
                    <th>{t('reportSystem.ui.colDate')}</th>
                    <th>{t('reportSystem.ui.colWork')}</th>
                    <th>{t('reportSystem.ui.colHeld')}</th>
                  </tr>
                </thead>
                <tbody>
                  {(data.repairs || []).map((r, i) => (
                    <tr key={`${r.date}-${i}`}>
                      <td>{r.date || t('reportSystem.notRecorded')}</td>
                      <td>
                        <strong>{tx(r.work_i18n, r.work)}</strong>
                        {r.part_number ? <div className="ir-muted">{r.part_number}</div> : null}
                        <div className="ir-muted">
                          {r.garage === 'Not recorded' ? t('reportSystem.notRecorded') : r.garage}
                        </div>
                      </td>
                      <td>
                        <Chip tone={r.held_ok ? 'ok' : 'warn'}>{tx(r.held_i18n, r.held)}</Chip>
                      </td>
                    </tr>
                  ))}
                  {(data.repairs || []).length === 0 ? (
                    <tr>
                      <td colSpan={3}>
                        <div className="ir-empty">{t('reportSystem.ui.noRepairLines')}</div>
                      </td>
                    </tr>
                  ) : null}
                </tbody>
              </table>
            </Panel>
          </section>

          <DataOrigin provenance={provenance} />
        </>
      ) : null}
    </ReportShell>
  );
}
