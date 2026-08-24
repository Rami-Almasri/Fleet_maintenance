import { useCallback, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { Alert, Chip, DataOrigin, Kpi, Panel, ReportShell, toneFor } from './reportBlocks';

/**
 * DAILY FLEET MAINTENANCE INTELLIGENCE — the morning report, off the live log.
 *
 * This is the document the Controllers used to assemble by hand out of the maintenance sheet: who is
 * in a garage, how bad it is, how long it has been there, what the garage did, and which cases need a
 * manager before anything else. Everything on the page is a field a person entered — the page adds
 * counts, and nothing else.
 *
 * The one judgement the page DOES make is to keep two populations apart, because merging them is how
 * a quiet day reads as a busy one:
 *
 *   TODAY'S ENTRIES — the day recorded something: the car went out, was followed up, or came back.
 *   STILL OUT       — the car went in on an earlier day and no return has ever been logged. Real, and
 *                     the fleet's problem, but not today's activity.
 *
 * Print / Save-as-PDF is a first-class output: the stylesheet has a print block, so Ctrl-P produces
 * the same report as a document to send on.
 */

const BUCKETS = [
  { key: 'today', label: "Today's entries" },
  { key: 'still_out', label: 'Still out' },
  { key: 'all', label: 'Everything' },
];

const todayIso = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

export default function DailyMaintenanceIntelligence() {
  // The date lives in the URL so a report can be linked to. "Yesterday's report" is a thing people
  // send each other, and a page that always means "today" cannot be sent.
  const [searchParams, setSearchParams] = useSearchParams();
  const date = searchParams.get('date') || todayIso();
  const setDate = (next) => setSearchParams(next ? { date: next } : {}, { replace: true });
  const [bucket, setBucket] = useState('today');

  const { data, loading, error, reload } = useFetch(
    useCallback(async () => {
      const res = await api.get('/reports/daily-maintenance', { params: { date } });
      return res.data?.data;
    }, [date]),
    [date],
    // A dated report is a point-in-time document, not a live board. Re-fetching every time the tab
    // regains focus re-renders a table that can run to a few hundred long-text rows — enough to lock
    // the tab up while someone alt-tabs between this and the sheet. Refresh is an explicit button.
    { revalidateOnFocus: false }
  );

  const cases = useMemo(() => {
    const all = data?.cases || [];
    return bucket === 'all' ? all : all.filter((c) => c.bucket === bucket);
  }, [data, bucket]);

  const meta = data?.meta;

  return (
    <ReportShell
      footer={
        meta
          ? `Generated ${meta.generated} · Read from the live workshop log · FASTER Fleet System`
          : null
      }
    >
      <section className="ir-hero">
        <div className="ir-panel ir-hero-main">
          <div className="ir-eyebrow">FASTER Fleet System · Daily Maintenance Intelligence</div>
          <h1>
            Daily Fleet Maintenance Intelligence
            <br />
            {meta?.date_label || date}
          </h1>
          <div className="ir-sub">
            Every car the day touched, and every car still sitting at a garage from an earlier day.
            Severity, garage, days and work are reproduced from the ticket exactly as they were
            entered — a field nobody filled in is reported as not recorded, never guessed at.
          </div>
        </div>
        <div className="ir-panel ir-hero-side">
          <div className="ir-small">Cases on this report</div>
          <div className="ir-big">{loading ? '—' : data?.cases?.length ?? 0}</div>
          <div className="ir-small">Needs reading first</div>
          <div className="ir-big sm tone-danger">
            {loading ? '—' : meta?.top_alert || 'Nothing was logged for this day.'}
          </div>
        </div>
      </section>

      <div className="ir-controls ir-no-print">
        <label className="ir-small" style={{ marginTop: 0 }} htmlFor="ir-date">
          Report date
        </label>
        <input
          id="ir-date"
          type="date"
          className="ir-control"
          value={date}
          onChange={(e) => setDate(e.target.value)}
        />
        <div className="ir-toggle" role="group" aria-label="Which cases to show">
          {BUCKETS.map((b) => (
            <button
              key={b.key}
              type="button"
              aria-pressed={bucket === b.key}
              onClick={() => setBucket(b.key)}
            >
              {b.label}
            </button>
          ))}
        </div>
        <button type="button" className="ir-button" onClick={() => reload()}>
          Refresh
        </button>
        <button type="button" className="ir-button" onClick={() => window.print()}>
          Print / Save as PDF
        </button>
      </div>

      {error ? (
        <Panel title="The report could not be built">
          <Alert tone="danger" heading="Request failed">
            {error}
          </Alert>
        </Panel>
      ) : null}

      {loading ? (
        <Panel>
          <div className="ir-empty">Reading the workshop log…</div>
        </Panel>
      ) : null}

      {!loading && data ? (
        <>
          <section className="ir-kpis">
            {(data.kpis || []).map((k) => (
              <Kpi key={k.key} label={k.label} value={k.value} note={k.note} />
            ))}
          </section>

          <section className="ir-grid2">
            <Panel
              title="Vehicle Operations"
              hint={`${cases.length} ${cases.length === 1 ? 'case' : 'cases'} · ${
                BUCKETS.find((b) => b.key === bucket)?.label
              }`}
            >
              <div className="ir-table-wrap">
                <table className="ir-ops">
                  {/* Fixed table layout sizes columns from these alone — see intelReport.css. */}
                  <colgroup>
                    <col className="c-vehicle" />
                    <col className="c-severity" />
                    <col className="c-stage" />
                    <col className="c-garage" />
                    <col className="c-days" />
                    <col className="c-issue" />
                    <col className="c-work" />
                  </colgroup>
                  <thead>
                    <tr>
                      <th>Vehicle</th>
                      <th>Severity</th>
                      <th>Stage</th>
                      <th>Garage</th>
                      <th>Days</th>
                      <th>Reported issue</th>
                      <th>Work recorded</th>
                    </tr>
                  </thead>
                  <tbody>
                    {cases.map((c) => (
                      <tr key={`${c.id}-${c.vehicle_id ?? 'x'}`}>
                        <td>
                          {c.vehicle_id ? (
                            <Link className="ir-link" to={`/vehicles/${c.vehicle_id}`}>
                              {c.vehicle}
                            </Link>
                          ) : (
                            c.vehicle
                          )}
                          {c.bucket === 'still_out' ? (
                            <div style={{ marginTop: 4 }}>
                              <Chip tone="neutral">No return logged</Chip>
                            </div>
                          ) : null}
                        </td>
                        <td>
                          <Chip tone={toneFor(c.severity)}>{c.severity_label}</Chip>
                        </td>
                        <td>{c.status}</td>
                        <td>{c.garage}</td>
                        <td>{c.days === null ? 'No start date' : c.days}</td>
                        <td>{c.issues}</td>
                        <td className="ir-pre">{c.progress}</td>
                      </tr>
                    ))}
                    {cases.length === 0 ? (
                      <tr>
                        <td colSpan={7}>
                          <div className="ir-empty">
                            <div>Nothing was logged under this view for {meta?.date_label || date}.</div>
                            {/* An empty report and a broken one look identical, so say which this is:
                                name the last date the log actually has entries for. A big gap between
                                that date and today usually means the sheet import has stalled. */}
                            {meta?.latest_entry_date && meta.latest_entry_date !== date ? (
                              <div style={{ marginTop: 10 }}>
                                The most recent day with entries is {meta.latest_entry_date}.{' '}
                                <button
                                  type="button"
                                  className="ir-button ir-no-print"
                                  onClick={() => setDate(meta.latest_entry_date)}
                                >
                                  Go to {meta.latest_entry_date}
                                </button>
                              </div>
                            ) : null}
                          </div>
                        </td>
                      </tr>
                    ) : null}
                  </tbody>
                </table>
              </div>
            </Panel>

            <div>
              <Panel title="Read these first" hint="Critical and High only">
                {(data.alerts || []).length === 0 ? (
                  <Alert tone="neutral" heading="Nothing rated Critical or High">
                    No case on this report carries a Critical or High severity. Note that{' '}
                    {data.provenance?.counts?.unrated ?? 0} of {data.provenance?.counts?.cases ?? 0}{' '}
                    cases have no severity set at all, so this is a statement about what was
                    recorded — not a clean bill of health.
                  </Alert>
                ) : (
                  (data.alerts || []).map((a, i) => (
                    <Alert
                      key={`${a.vehicle}-${i}`}
                      tone={a.severity === 'critical' ? 'danger' : 'warn'}
                      heading={a.label}
                    >
                      {a.text}
                    </Alert>
                  ))
                )}
              </Panel>

              <Panel title="Garage Load" hint="Cars held per garage">
                <table className="ir-side">
                  <colgroup>
                    <col className="c-name" />
                    <col className="c-num" />
                    <col />
                  </colgroup>
                  <thead>
                    <tr>
                      <th>Garage</th>
                      <th>Load</th>
                      <th>Cars</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(data.garages || []).map((g) => (
                      <tr key={g.garage}>
                        <td>
                          {g.garage}
                          {g.critical > 0 ? (
                            <div style={{ marginTop: 4 }}>
                              <Chip tone="danger">{g.critical} critical</Chip>
                            </div>
                          ) : null}
                        </td>
                        <td>{g.load}</td>
                        <td>{g.activity}</td>
                      </tr>
                    ))}
                    {(data.garages || []).length === 0 ? (
                      <tr>
                        <td colSpan={3}>
                          <div className="ir-empty">No garages on this report.</div>
                        </td>
                      </tr>
                    ) : null}
                  </tbody>
                </table>
              </Panel>
            </div>
          </section>

          <DataOrigin provenance={data.provenance} />
        </>
      ) : null}
    </ReportShell>
  );
}
