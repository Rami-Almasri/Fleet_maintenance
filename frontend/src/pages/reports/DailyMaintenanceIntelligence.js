import { useCallback, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import {
  Alert, BarRow, CaseTimeline, Chip, DataOrigin, Donut, Kpi, Panel, ReportShell, toneFor,
} from './reportBlocks';

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

/** Worst first, so the severity mix and the priority queue both lead with the thing that matters. */
const SEVERITY_ORDER = ['critical', 'high', 'moderate', 'routine'];

/**
 * The systems the fleet's own extractor assigns — all twelve of config/fault_extraction.php, not the
 * nine the per-car system report offers. The extra three (interior, lights, routine) are real
 * categories that show up on the daily file every day, and an unmapped key would print here as a bare
 * lowercase slug. An unrecognised key still falls through to itself rather than being dropped: a
 * category this list has not learned yet must show up looking odd, never vanish from the count.
 */
const CATEGORY_LABELS = {
  engine: 'Engine', transmission: 'Transmission & Drivetrain', brakes: 'Brakes',
  suspension: 'Suspension & Steering', electrical: 'Electrical', ac: 'Climate / A/C',
  tyres: 'Tyres & Wheels', bodywork: 'Bodywork & Exterior', fluids: 'Fluids & Cooling',
  interior: 'Interior & Upholstery', lights: 'Lights', routine: 'Routine Service',
};

/**
 * A case is HELD LONG when it has been at a garage beyond a normal turnaround.
 *
 * Seven days, and the number is here rather than buried in a condition so it can be argued with.
 * It is a reading aid, not a rule the fleet operates by: the panel says how many cases exceed it and
 * names them, and nothing downstream changes because of it.
 */
const LONG_STAY_DAYS = 7;

/** Counts by a key, biggest first — the one shape both charts on this page need. */
const tally = (rows, keyOf) => {
  const counts = new Map();
  rows.forEach((r) => {
    [].concat(keyOf(r) ?? []).forEach((k) => {
      if (k === null || k === undefined || k === '') return;
      counts.set(k, (counts.get(k) || 0) + 1);
    });
  });
  return [...counts.entries()].map(([key, count]) => ({ key, count })).sort((a, b) => b.count - a.count);
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

  /*
   * THE THREE READINGS OF THE SAME DAY, all counted over the SAME rows the table below shows — so a
   * number in a chart can always be found in the table, and the two can never disagree. They follow
   * the bucket toggle for exactly that reason.
   */
  const severityMix = useMemo(
    () =>
      tally(cases, (c) => c.severity || 'unrated')
        .sort((a, b) => {
          const rank = (k) => (k === 'unrated' ? 9 : SEVERITY_ORDER.indexOf(k));
          return rank(a.key) - rank(b.key);
        })
        .map((s) => ({
          label: cases.find((c) => (c.severity || 'unrated') === s.key)?.severity_label || 'Unrated',
          count: s.count,
        })),
    [cases],
  );

  // Cars can carry more than one system, so these sum to more than the case count on purpose — the
  // panel hint says so rather than letting the reader assume an error.
  const workload = useMemo(() => tally(cases, (c) => c.categories), [cases]);
  const workloadMax = workload.length ? workload[0].count : 0;

  /** Read-first cases: Critical and High, worst first, then longest-held. */
  const priority = useMemo(
    () =>
      cases
        .filter((c) => c.severity === 'critical' || c.severity === 'high')
        .sort(
          (a, b) =>
            SEVERITY_ORDER.indexOf(a.severity) - SEVERITY_ORDER.indexOf(b.severity) ||
            (b.days ?? 0) - (a.days ?? 0),
        ),
    [cases],
  );

  const longStay = useMemo(() => cases.filter((c) => (c.days ?? 0) >= LONG_STAY_DAYS), [cases]);
  const noReturn = useMemo(() => cases.filter((c) => c.bucket === 'still_out'), [cases]);
  const unrated = useMemo(() => cases.filter((c) => !c.severity), [cases]);

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

          {/* ── The day at a glance: how bad, what kind of work, and what blocks a release. ──── */}
          <section className="ir-grid3">
            <Panel title="Case Severity Mix" hint="Vehicles by severity">
              {severityMix.length === 0 ? (
                <div className="ir-empty">No case on this view.</div>
              ) : (
                <Donut slices={severityMix} unit={cases.length === 1 ? 'vehicle' : 'vehicles'} />
              )}
            </Panel>

            <Panel title="Workload by System" hint="A car with two faults is counted under both">
              {workload.length === 0 ? (
                <div className="ir-empty">
                  No case on this view names a system the extractor recognises.
                </div>
              ) : (
                workload.map((w) => (
                  <BarRow
                    key={w.key}
                    label={CATEGORY_LABELS[w.key] || w.key}
                    value={w.count}
                    max={workloadMax}
                    display={String(w.count)}
                  />
                ))
              )}
            </Panel>

            {/*
              RELEASE READINESS — every line is a count over the rows above, and each one names the
              cars behind it. No line here is a judgement about whether a car is roadworthy: that is
              a person's call, and the panel gives them the three facts they would ask for first.
            */}
            <Panel title="Release Readiness" hint="Management view">
              {longStay.length > 0 ? (
                <Alert tone="warn" heading={`Long-stay cases: ${longStay.length}`}>
                  {longStay
                    .map((c) => `${c.vehicle} (${c.days} days)`)
                    .join(', ')}{' '}
                  — held beyond {LONG_STAY_DAYS} days, which is this report’s reading aid for a normal
                  turnaround, not a rule the fleet operates by.
                </Alert>
              ) : (
                <Alert tone="ok" heading="No long-stay case">
                  Nothing on this view has been held {LONG_STAY_DAYS} days or more.
                </Alert>
              )}

              {noReturn.length > 0 ? (
                <Alert tone="warn" heading={`No return logged: ${noReturn.length}`}>
                  These cars went to a garage on an earlier day and no return has ever been recorded.
                  That is a gap in the log as often as it is a car still out, and the report cannot
                  tell which from here.
                </Alert>
              ) : null}

              {unrated.length > 0 ? (
                <Alert tone="neutral" heading={`Severity not set: ${unrated.length} of ${cases.length}`}>
                  The severity mix above describes only the {cases.length - unrated.length} cases
                  somebody rated. An unrated case is not a low-severity case.
                </Alert>
              ) : null}
            </Panel>
          </section>

          {/* ── The read-first queue, as a sequence rather than a set. ───────────────────────── */}
          <Panel
            title="Priority Case Sequence"
            hint={`${priority.length} Critical and High ${priority.length === 1 ? 'case' : 'cases'}`}
          >
            <CaseTimeline
              empty="No case on this view is rated Critical or High. With severity unset on some cases, that is a statement about what was recorded — not a clean bill of health."
              items={priority.map((c) => ({
                key: `${c.id}-${c.vehicle_id ?? 'x'}`,
                severity: c.severity,
                meta: [
                  c.vehicle,
                  c.garage,
                  c.days === null ? 'No start date' : `${c.days} days`,
                  c.status,
                ],
                chip: <Chip tone={toneFor(c.severity)}>{c.severity_label}</Chip>,
                title: c.issues,
                desc: c.progress,
              }))}
            />
          </Panel>

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
