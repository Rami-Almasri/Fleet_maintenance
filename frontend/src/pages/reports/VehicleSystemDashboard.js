import { useCallback, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { Alert, BarRow, Chip, DataOrigin, Kpi, Panel, ReportShell, toneFor } from './reportBlocks';

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
  const mixMax = Math.max(1, ...(data?.failure_mix || []).map((m) => m.count));

  return (
    <ReportShell
      footer={
        data
          ? `${data.system.label} history for ${data.vehicle.label} · Read from the maintenance record · FASTER Fleet System`
          : null
      }
    >
      <section className="ir-hero">
        <div className="ir-panel ir-hero-main">
          <div className="ir-eyebrow">
            FASTER Fleet System · {data?.system?.label || 'System'} Intelligence
          </div>
          <h1>
            {data?.system?.label || 'System'} Dashboard
            <br />
            {data?.vehicle?.label || `Vehicle ${vehicleId}`}
          </h1>
          <div className="ir-sub">
            Every recorded failure of this one system on this one car, in order, with the garage that
            saw it and what was done. Below the timeline: what was replaced, and whether the same
            system failed again afterwards. Nothing here is predicted — it is the record, counted.
          </div>
        </div>
        <div className={`ir-panel ir-hero-side tone-${verdict?.tone || 'neutral'}`}>
          <div className="ir-small">What the record supports</div>
          <div className={`ir-big ${verdict?.tone === 'danger' ? 'tone-danger' : verdict?.tone === 'warn' ? 'tone-warntext' : verdict?.tone === 'ok' ? 'tone-oktext' : ''}`}>
            {loading ? '—' : verdict?.decision || '—'}
          </div>
          <div className="ir-small">System health index</div>
          <div className="ir-big">{loading ? '—' : `${risk?.health ?? '—'} / 100`}</div>
          <div className="ir-small">Current reading</div>
          <div className="ir-big sm">{loading ? '—' : verdict?.status || '—'}</div>
        </div>
      </section>

      <div className="ir-controls ir-no-print">
        <label className="ir-small" style={{ marginTop: 0 }} htmlFor="ir-system">
          System
        </label>
        <select
          id="ir-system"
          className="ir-control"
          value={system}
          onChange={(e) => setSearchParams({ system: e.target.value })}
        >
          {systems.map((s) => (
            <option key={s.key} value={s.key}>
              {s.label}
            </option>
          ))}
        </select>
        <Link className="ir-button" to={`/vehicles/${vehicleId}`}>
          Open the vehicle
        </Link>
        <button type="button" className="ir-button" onClick={() => reload()}>
          Refresh
        </button>
        <button type="button" className="ir-button" onClick={() => window.print()}>
          Print / Save as PDF
        </button>
      </div>

      {error ? (
        <Panel title="The dashboard could not be built">
          <Alert tone="danger" heading="Request failed">
            {error}
          </Alert>
        </Panel>
      ) : null}

      {loading ? (
        <Panel>
          <div className="ir-empty">Reading this vehicle's maintenance record…</div>
        </Panel>
      ) : null}

      {!loading && data ? (
        <>
          <section className="ir-kpis">
            {(data.kpis || []).map((k) => (
              <Kpi key={k.label} label={k.label} value={k.value} note={k.note} />
            ))}
          </section>

          <section className="ir-grid3">
            <Panel title="What goes wrong" hint="Findings, most frequent first">
              {(data.failure_mix || []).length === 0 ? (
                <div className="ir-empty">No event on this system has been recorded.</div>
              ) : (
                (data.failure_mix || []).map((m) => (
                  <BarRow
                    key={m.label}
                    label={m.label}
                    value={m.count}
                    max={mixMax}
                    display={`${m.count}×`}
                    tone={m.count > 1 ? 'hot' : 'cool'}
                  />
                ))
              )}
            </Panel>

            <Panel title="How the risk score was reached" hint={`Total ${risk?.score ?? 0} / 100`}>
              {(risk?.components || []).map((c) => (
                <BarRow
                  key={c.key}
                  label={c.label}
                  value={c.points}
                  max={c.max}
                  display={`${c.points} / ${c.max}`}
                  rule={`${c.input} — ${c.rule}`}
                />
              ))}
              <Alert tone="neutral" heading="How to read this">
                {risk?.basis}
              </Alert>
            </Panel>

            <Panel title="The decision" hint="And the rule behind it">
              <Alert tone={VERDICT_TONE[verdict?.tone] || 'neutral'} heading={verdict?.decision}>
                {verdict?.status}. {verdict?.rule}
              </Alert>
              <Alert tone="neutral" heading="What this page cannot tell you">
                It reports what was recorded. A failure nobody logged is invisible here, and a system
                with a thin record scores low because it is thin — not because the car is healthy.
                Check the Data Origin block below before treating a low score as reassurance.
              </Alert>
              {data.vehicle?.vin ? (
                <Alert tone="neutral" heading="Vehicle">
                  {data.vehicle.label}
                  {data.vehicle.vin ? ` · VIN ${data.vehicle.vin}` : ''}
                </Alert>
              ) : null}
            </Panel>
          </section>

          <section className="ir-grid2">
            <Panel title={`${data.system.label} timeline`} hint={`${timeline.length} recorded ${timeline.length === 1 ? 'event' : 'events'}`}>
              {timeline.length === 0 ? (
                <div className="ir-empty">
                  No {data.system.label.toLowerCase()} event has ever been logged against this car.
                </div>
              ) : (
                <div className="ir-timeline">
                  {timeline.map((e, i) => (
                    <div key={`${e.ref}-${i}`} className={`ir-titem sev-${e.severity || 'none'}`}>
                      <div className="ir-tdate">
                        <span>{e.date}</span>
                        <span>·</span>
                        <span>{e.garage}</span>
                        <Chip tone={toneFor(e.severity)}>
                          {e.severity ? e.severity[0].toUpperCase() + e.severity.slice(1) : 'Unrated'}
                        </Chip>
                        <Chip tone="info">{e.source}</Chip>
                        {e.recurred ? <Chip tone="danger">Recurred</Chip> : null}
                        {e.major_work ? <Chip tone="warn">Major work</Chip> : null}
                      </div>
                      <div className="ir-tphase">{e.finding}</div>
                      <div className="ir-tdesc">
                        {e.detail}
                        {'\n'}
                        <span className="ir-muted">Outcome: {e.outcome}</span>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </Panel>

            <Panel title="What was replaced" hint="And whether it held">
              <table className="ir-side">
                <colgroup>
                  <col className="c-num" />
                  <col className="c-name" />
                  <col />
                </colgroup>
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Work / part</th>
                    <th>Did it hold?</th>
                  </tr>
                </thead>
                <tbody>
                  {(data.repairs || []).map((r, i) => (
                    <tr key={`${r.date}-${i}`}>
                      <td>{r.date || 'Not recorded'}</td>
                      <td>
                        <strong>{r.work}</strong>
                        {r.part_number ? <div className="ir-muted">{r.part_number}</div> : null}
                        <div className="ir-muted">{r.garage}</div>
                      </td>
                      <td>
                        <Chip tone={r.held_ok ? 'ok' : 'warn'}>{r.held}</Chip>
                      </td>
                    </tr>
                  ))}
                  {(data.repairs || []).length === 0 ? (
                    <tr>
                      <td colSpan={3}>
                        <div className="ir-empty">
                          No itemised part or labour line has been recorded against this system. That
                          means the invoices for these visits were never broken down — not that no
                          work was done.
                        </div>
                      </td>
                    </tr>
                  ) : null}
                </tbody>
              </table>
            </Panel>
          </section>

          <DataOrigin provenance={data.provenance} />
        </>
      ) : null}
    </ReportShell>
  );
}
