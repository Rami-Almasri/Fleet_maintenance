import { useEffect, useState } from 'react';
import { useI18n } from '../../i18n/I18nContext';
import { dateLocale, numberLocale } from '../../i18n/translate';
import './intelReport.css';

/**
 * The pieces both intelligence reports are built from — the panel, the KPI tile, the severity chip,
 * the alert card, the bar row and the Data Origin block.
 *
 * They are kept here rather than in components/ui because they are deliberately NOT the app's design
 * system: these two pages render on their own fixed dark surface so they read the same on a wall
 * screen and in a printed PDF (see intelReport.css). Reaching for a Badge or a MetricCard here would
 * pull in theme-aware colours and break that.
 */

/** Severity → chip tone. An unrated row gets a neutral chip, never a green one: unknown is not good. */
export const SEVERITY_TONE = {
  critical: 'danger',
  high: 'warn',
  moderate: 'warn',
  routine: 'ok',
};

export const toneFor = (severity) => SEVERITY_TONE[severity] || 'neutral';

/** Condition grade / operational status → chip tone. Anything unmapped stays neutral, never green. */
export const CONDITION_TONE = {
  green: 'ok',
  orange: 'warn',
  red: 'danger',
  ready: 'ok',
  maintenance: 'warn',
  rented: 'info',
  sold: 'neutral',
};

export function Panel({ title, hint, children, actions }) {
  return (
    <section className="ir-panel">
      {(title || hint || actions) && (
        <div className="ir-titlebar">
          {title ? <h2>{title}</h2> : <span />}
          {actions || (hint ? <div className="ir-muted">{hint}</div> : null)}
        </div>
      )}
      <div className="ir-body">{children}</div>
    </section>
  );
}

export function Kpi({ label, value, note }) {
  return (
    <div className="ir-kpi">
      <div className="ir-label">{label}</div>
      <div className="ir-value">{value}</div>
      {note ? <div className="ir-note">{note}</div> : null}
    </div>
  );
}

export function Chip({ tone = 'neutral', children }) {
  return <span className={`ir-chip ${tone}`}>{children}</span>;
}

export function Alert({ tone = 'warn', heading, children }) {
  return (
    <div className={`ir-alert ${tone === 'danger' ? 'critical' : tone === 'neutral' ? 'neutral' : ''}`}>
      {heading ? <h3>{heading}</h3> : null}
      <p>{children}</p>
    </div>
  );
}

/**
 * The categorical series palette. Fixed and ordered, so the biggest finding is always the same red
 * and the reader learns the ranking from position rather than re-reading the legend each time.
 */
export const SERIES_COLORS = [
  '#ef4444', '#f59e0b', '#8b5cf6', '#38bdf8', '#22c55e', '#f97316', '#ec4899', '#14b8a6',
];

/**
 * A donut with the total in the hole and a legend that carries the count for every slice.
 *
 * SVG rather than canvas: it re-renders with React state, scales to any panel width without a resize
 * listener, and — the reason that decides it — a canvas prints as a blurry bitmap while this prints
 * as vector. Every slice's number is repeated in the legend, so the chart is never the only place a
 * figure appears.
 */
export function Donut({ slices = [], unit = 'events' }) {
  const total = slices.reduce((sum, s) => sum + (s.count || 0), 0);
  const R = 45;
  const C = 2 * Math.PI * R;
  let consumed = 0;

  return (
    <div className="ir-donut">
      <svg className="ir-donut-svg" viewBox="0 0 120 120" role="img" aria-label={`${total} ${unit} by finding`}>
        <circle cx="60" cy="60" r={R} fill="none" stroke="rgba(148,163,184,0.14)" strokeWidth="26" />
        <g transform="rotate(-90 60 60)">
          {slices.map((s, i) => {
            const len = total > 0 ? ((s.count || 0) / total) * C : 0;
            const offset = -consumed;
            consumed += len;
            return (
              <circle
                key={s.label}
                cx="60"
                cy="60"
                r={R}
                fill="none"
                stroke={SERIES_COLORS[i % SERIES_COLORS.length]}
                strokeWidth="26"
                strokeDasharray={`${len} ${Math.max(0, C - len)}`}
                strokeDashoffset={offset}
              />
            );
          })}
        </g>
        <text className="ir-donut-total" x="60" y="58" textAnchor="middle">{total}</text>
        <text className="ir-donut-unit" x="60" y="74" textAnchor="middle">{unit}</text>
      </svg>

      <ul className="ir-legend">
        {slices.map((s, i) => (
          <li key={s.label}>
            <span className="ir-swatch" style={{ background: SERIES_COLORS[i % SERIES_COLORS.length] }} />
            <span className="ir-legend-label" title={s.label}>{s.label}</span>
            <span className="ir-legend-value">{s.count}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

/**
 * A horizontal bar. `value` and `max` are printed as text beside it, so the bar is a reading aid and
 * never the only place a number appears — a bar alone cannot be checked against the table below it.
 */
export function BarRow({ label, value, max, display, rule, tone }) {
  const pct = max > 0 ? Math.min(100, Math.round((value / max) * 100)) : 0;
  const fill = tone || (pct >= 75 ? 'hot' : pct >= 40 ? 'warm' : 'cool');

  return (
    <>
      <div className="ir-bar-row">
        <div className="ir-bar-label">{label}</div>
        <div className="ir-bar-track">
          <div className={`ir-bar-fill ${fill}`} style={{ width: `${pct}%` }} />
        </div>
        <div className="ir-bar-value">{display ?? `${value} / ${max}`}</div>
      </div>
      {rule ? <div className="ir-bar-rule">{rule}</div> : null}
    </>
  );
}

/**
 * ONE RISK COMPONENT, with the records that produced its count.
 *
 * The bar and the points are the claim; `evidence` is the proof, and it is printed open rather than
 * behind a disclosure. A reader checking whether a score is fair should not have to click five times
 * to find out — and a component whose count could not have been anything else (no replacement on
 * record, so nothing to count after one) says so in its caveat instead of printing a bare 0.
 */
export function RiskComponent({ component }) {
  const { label, points, max, input, rule, detail, caveat, display, evidence = [] } = component;

  return (
    <div className="ir-risk-item">
      <BarRow label={label} value={points} max={max} display={display ?? `${points} / ${max}`} />
      <div className="ir-risk-input">
        <strong>{input}</strong> — {rule}
      </div>
      {detail ? <div className="ir-risk-detail">{detail}</div> : null}
      {caveat ? <div className="ir-risk-caveat">{caveat}</div> : null}
      {evidence.length > 0 ? (
        <ul className="ir-evidence">
          {evidence.map((e, i) => (
            <li key={`${e.date || ''}-${e.text}-${i}`}>
              {e.date ? <span className="ir-ev-date">{e.date}</span> : null}
              <span className="ir-ev-text">{e.text}</span>
              {e.meta ? <span className="ir-ev-meta">{e.meta}</span> : null}
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}

/**
 * WHAT THIS CAR IS — the recorded identity, before any of the history is read.
 *
 * The service omits any column it has no value for, so this list is short exactly when the record is
 * thin. Nothing is filled in with a dash or a guess. `kind` decides the rendering only; the units and
 * the labels come from the catalog, so the same payload reads correctly in either language.
 */
export function VehicleBrief({ rows = [], showMoney = true }) {
  const { t } = useI18n();
  const num = (v) => Number(v).toLocaleString(numberLocale());

  const render = (r) => {
    switch (r.kind) {
      case 'km':
        return t('reportSystem.brief.km', { n: num(r.value) });
      case 'money':
        return showMoney ? t('reportSystem.brief.money', { n: num(r.value) }) : null;
      case 'chip':
        return <Chip tone={CONDITION_TONE[r.value] || 'neutral'}>{t(`reportSystem.brief.value.${r.value}`)}</Chip>;
      case 'tr':
        return t(r.value);
      default:
        return r.value;
    }
  };

  return (
    <dl className="ir-brief">
      {rows.map((r) => {
        const value = render(r);
        // A money row with financials switched off renders nothing at all rather than an empty row.
        if (value === null) return null;

        return (
          <div key={r.key}>
            <dt>{t(`reportSystem.brief.${r.key}`)}</dt>
            <dd className={r.kind === 'mono' ? 'ir-mono' : undefined}>
              {value}
              {/* An odometer is only checkable if you know which writer last moved it. */}
              {r.note ? <span className="ir-brief-note">{t(`reportSystem.brief.source.${r.note}`)}</span> : null}
            </dd>
          </div>
        );
      })}
    </dl>
  );
}

/**
 * EVERY FAULT, AND EVERY DATE IT WAS LOGGED.
 *
 * Grouped by finding rather than by time, because the question is not "what happened when" — the
 * timeline answers that — but "when did THIS fault happen, each time". The gap since the previous
 * occurrence is printed against each repeat: a fault returning after nine days and the same fault
 * returning after nine months are not the same fact, and a bare list of dates hides the difference.
 */
export function FaultHistory({ faults = [] }) {
  const { t, tp } = useI18n();

  /** "came back twice, 40–55 days apart" — the shape of the recurrence in one line. */
  const returnSummary = (f) => {
    if (!f.returns) return null;
    const gap = f.shortest_gap === f.longest_gap
      ? tp('reportSystem.fault.gapOne', f.shortest_gap)
      : t('reportSystem.fault.gapRange', { min: f.shortest_gap, max: f.longest_gap });
    return `${tp('reportSystem.fault.returns', f.returns)}, ${gap}`;
  };

  return (
    <div className="ir-faults">
      {faults.map((f) => (
        <section className={`ir-fault ${f.returns ? 'is-repeat' : ''}`} key={f.finding}>
          <header className="ir-fault-head">
            <h3>{f.finding}</h3>
            <div className="ir-fault-meta">
              <Chip tone={f.returns ? 'danger' : 'neutral'}>{tp('reportSystem.fault.times', f.count)}</Chip>
              {/* Only worth saying when it differs from the row count — otherwise it is noise. */}
              {f.visits !== f.count ? (
                <Chip tone="neutral">{tp('reportSystem.fault.visits', f.visits)}</Chip>
              ) : null}
              <Chip tone={toneFor(f.worst)}>{t(`reportSystem.severity.${f.worst || 'unrated'}`)}</Chip>
            </div>
            <div className="ir-fault-line">
              {f.first && f.last && f.first !== f.last ? (
                <span className="ir-fault-span">
                  <span className="ir-occ-date">{f.first}</span>
                  <span aria-hidden="true"> → </span>
                  <span className="ir-occ-date">{f.last}</span>
                  {f.span_days != null ? <> · {tp('reportSystem.fault.span', f.span_days)}</> : null}
                </span>
              ) : null}
              {returnSummary(f) ? <strong className="ir-fault-returns">{returnSummary(f)}</strong> : null}
            </div>
          </header>

          <ol className="ir-occurrences">
            {f.occurrences.map((o, i) => (
              <li key={`${o.date}-${i}`} className={o.same_visit ? 'is-same-visit' : o.gap_days != null ? 'is-return' : 'is-first'}>
                <div className="ir-occ-head">
                  <span className="ir-occ-date">{o.date || t('reportSystem.notRecorded')}</span>
                  {o.gap_days == null ? (
                    <Chip tone="neutral">{t('reportSystem.fault.first')}</Chip>
                  ) : o.same_visit ? (
                    <Chip tone="neutral">{t('reportSystem.fault.sameVisit')}</Chip>
                  ) : (
                    <Chip tone="danger">{tp('reportSystem.fault.after', o.gap_days)}</Chip>
                  )}
                  <span className="ir-occ-garage">{o.garage}</span>
                  {o.severity ? (
                    <Chip tone={toneFor(o.severity)}>{t(`reportSystem.severity.${o.severity}`)}</Chip>
                  ) : null}
                  {o.major_work ? <Chip tone="warn">{t('reportSystem.ui.majorWork')}</Chip> : null}
                </div>

                {/* THE RECORD. Quoted, because these are the garage's words and not the report's. */}
                {o.record?.lines?.length ? (
                  <blockquote className="ir-record">
                    {o.record.lines.map((line, j) => (
                      <p key={j}>{line}</p>
                    ))}
                    {o.record.dropped ? (
                      <span className="ir-record-note">
                        {tp('reportSystem.fault.otherLines', o.record.dropped)}
                      </span>
                    ) : null}
                    {!o.record.filtered ? (
                      <span className="ir-record-note">{t('reportSystem.fault.unfiltered')}</span>
                    ) : null}
                  </blockquote>
                ) : (
                  <div className="ir-record is-empty">{t('reportSystem.fault.noNote')}</div>
                )}

                <div className="ir-occ-foot">
                  <span>{o.outcomeText}</span>
                  <span className="ir-muted">{o.sourceText}</span>
                </div>
              </li>
            ))}
          </ol>
        </section>
      ))}
    </div>
  );
}

/**
 * THE ANSWER, IN PLAIN SENTENCES — the only part of this page most readers will ever need.
 *
 * Everything below this block is the working: incidents, the score and its components, evidence
 * grades, data-quality warnings. All of it is true and none of it is readable at a glance, which made
 * the report useless to the person it exists for. A fleet manager opening this needs four things in
 * ten seconds — how often, what was found, is it the same thing coming back, and what to do — and
 * needs them as sentences, not as chips and jargon.
 *
 * So this states them plainly and takes the vocabulary of the evidence layer OUT of the reader's way:
 * no "workshop_mention", no "medium evidence", no score in the headline. Those words are precise and
 * they belong in the working below, where someone checking the report will go looking for them.
 */
export function SystemStory({ data, systemLabel }) {
  const { t, tp } = useI18n();

  const facts = data.facts;
  const rec = data.recurrence;
  const found = (data.incidents || []).filter((i) => i.is_confirmed);
  const repairs = data.durability || [];

  return (
    <div className="ir-story">
      <p className="ir-story-lead">
        {facts.incidents === 1 || !facts.latest_incident
          ? t('reportSystem.story.lookedAtOnce', {
              system: systemLabel.toLowerCase(),
              date: facts.latest_incident?.start ?? '—',
            })
          : tp('reportSystem.story.lookedAt', facts.incidents, {
              system: systemLabel.toLowerCase(),
              from: data.incidents[0]?.start,
              to: facts.latest_incident?.start,
            })}
      </p>

      {found.length ? (
        <>
          <h3 className="ir-story-h">{t('reportSystem.story.found')}</h3>
          <ul className="ir-story-found">
            {found.map((i, n) => (
              <li key={n}>
                <span className="ir-occ-date">{i.start}</span>
                <span className="ir-story-fault">{i.fault}</span>
              </li>
            ))}
          </ul>
        </>
      ) : (
        <p className="ir-story-note">{t('reportSystem.story.nothingFound')}</p>
      )}

      {/* The one question the old report answered wrongly, now answered in a sentence. */}
      <p className="ir-story-verdict">
        <strong>{t(`reportSystem.story.recurrence.${rec.status}.head`)}</strong>{' '}
        {t(`reportSystem.story.recurrence.${rec.status}.body`, {
          n: found.length,
          fault: rec.repeated_faults[0]?.fault ?? '',
          times: rec.repeated_faults[0]?.count ?? 0,
        })}
      </p>

      <p className="ir-story-verdict">
        <strong>
          {repairs.length
            ? t('reportSystem.story.repairs.head')
            : t('reportSystem.story.noRepair.head')}
        </strong>{' '}
        {/* tp(), not t(): this key has plural forms, and t() would hand React the form object. */}
        {repairs.length
          ? tp('reportSystem.story.repairs.body', repairs.length)
          : t('reportSystem.story.noRepair.body')}
      </p>

      {data.takeaway?.actions?.length ? (
        <>
          <h3 className="ir-story-h">{t('reportSystem.story.whatToDo')}</h3>
          <ul className="ir-story-actions">
            {data.takeaway.actions.map((a) => (
              <li key={a}>{t(`reportSystem.takeaway.action.${a}`)}</li>
            ))}
          </ul>
        </>
      ) : null}

      {/*
        The score is demoted on purpose. It led the old page and was read as a fact about the car
        when it was often a fact about the paperwork; here it is a footnote pointing at its own
        arithmetic, which is where an argument about it should happen.
      */}
      <p className="ir-story-score">
        {t('reportSystem.story.scoreNote', {
          score: data.risk.score,
          band: t(`reportSystem.band.${data.risk.band}`).toLowerCase(),
          confidence: t(`reportSystem.confidenceLevel.${data.confidence.level}`).toLowerCase(),
        })}
      </p>
    </div>
  );
}

/** Evidence strength → chip tone. Weak is never green: unproven is not the same as fine. */
const STRENGTH_TONE = { strong: 'ok', medium: 'warn', weak: 'neutral' };
const CONFIDENCE_TONE = { high: 'ok', medium: 'warn', low: 'danger' };
const ACTION_TONE = {
  replacement: 'danger', repair: 'warn', inspection: 'info', recommended: 'neutral', mention: 'neutral',
};
const DURABILITY_TONE = {
  same_fault_returned: 'danger',
  different_fault_followed: 'warn',
  system_activity_followed: 'warn',
  nothing_recorded_after: 'neutral',
};

export { CONFIDENCE_TONE };

/**
 * INCIDENTS, NOT ROWS — the timeline the report is actually built on.
 *
 * Each card is one incident: what the record establishes about it, how strong that evidence is, and
 * — behind a disclosure — the individual source rows that were grouped into it, each stating WHY it
 * was joined. The raw notes are deliberately not shown by default: they used to dominate the page and
 * bury the finding, but hiding them entirely would make the grouping unauditable, so they are one
 * click away rather than absent.
 */
/** Work that was actually done or explicitly recommended — a bare mention of a part is not news. */
const realWork = (work = []) => work.filter((w) => w.action !== 'mention');

export function IncidentList({ incidents = [] }) {
  const { t, tp } = useI18n();
  const [open, setOpen] = useState({});

  return (
    <ol className="ir-incidents">
      {incidents.map((inc, idx) => {
        const isOpen = !!open[idx];
        return (
          <li className={`ir-incident kind-${inc.kind}`} key={`${inc.start}-${idx}`}>
            {/*
              ONE chip, not five. The old head carried kind + strength + severity + row count, which
              is four vocabularies the reader has to learn before the line means anything. The date
              and the fault are what matter; the grading is available in the working below.
            */}
            <div className="ir-incident-head">
              <span className="ir-incident-no">{idx + 1}</span>
              <span className="ir-occ-date">
                {inc.start}
                {inc.end && inc.end !== inc.start ? ` → ${inc.end}` : ''}
              </span>
              {inc.severity ? (
                <Chip tone={toneFor(inc.severity)}>{t(`reportSystem.severity.${inc.severity}`)}</Chip>
              ) : null}
              {!inc.is_confirmed ? (
                <Chip tone="neutral">{t('reportSystem.incident.notConfirmed')}</Chip>
              ) : null}
            </div>

            <div className="ir-incident-fault">
              {inc.fault || <span className="ir-muted">{t('reportSystem.incident.noFaultNamed')}</span>}
            </div>

            <div className="ir-incident-meta">
              <span>{inc.garages.length ? inc.garages.join(', ') : t('reportSystem.notRecorded')}</span>
              {inc.row_count > 1 ? (
                <span>{tp('reportSystem.incident.rowCount', inc.row_count)}</span>
              ) : null}
              {inc.odometer ? <span>{inc.odometer} km</span> : null}
            </div>

            {/*
              Only work that was actually DONE is worth a line here. A note that merely mentions a
              component told the reader nothing and produced five identical "Mentioned only" chips on
              one card — noise that buried the two lines that mattered.
            */}
            {realWork(inc.work).length ? (
              <ul className="ir-worklist">
                {realWork(inc.work).map((w, i) => (
                  <li key={i}>
                    <Chip tone={ACTION_TONE[w.action]}>{t(`reportSystem.action.${w.action}`)}</Chip>
                    <span className="ir-work-text">{w.text}</span>
                  </li>
                ))}
              </ul>
            ) : null}

            <button type="button" className="ir-disclose ir-no-print" onClick={() => setOpen((o) => ({ ...o, [idx]: !isOpen }))}>
              {isOpen ? t('reportSystem.incident.hideEvidence') : t('reportSystem.incident.showEvidence')}
              {` (${inc.row_count})`}
            </button>

            {isOpen ? (
              <ol className="ir-sources">
                {inc.records.map((r, i) => (
                  <li key={i}>
                    <div className="ir-source-head">
                      <span className="ir-mono">#{r.ref}</span>
                      <span className="ir-occ-date">{r.date}</span>
                      <span className="ir-occ-garage">{r.garage}</span>
                      <Chip tone="info">{t(`reportSystem.source.${r.source === 'ticket' ? 'ticket' : 'workshopLog'}`)}</Chip>
                      <Chip tone={STRENGTH_TONE[r.strength]}>{t(`reportSystem.strength.${r.strength}`)}</Chip>
                    </div>
                    <div className="ir-source-join">
                      <em>{t('reportSystem.incident.joined')}:</em> {r.join_reason}
                    </div>
                    {r.all_lines.length ? (
                      <blockquote className="ir-record">
                        {r.all_lines.map((l, j) => (
                          <p key={j} className={r.system_lines.includes(l) ? 'is-relevant' : 'is-other'}>{l}</p>
                        ))}
                      </blockquote>
                    ) : (
                      <div className="ir-record is-empty">{t('reportSystem.fault.noNote')}</div>
                    )}
                  </li>
                ))}
              </ol>
            ) : null}
          </li>
        );
      })}
    </ol>
  );
}

/** Every work claim read out of the notes, with how strong the claim is. */
export function WorkLedger({ work = [] }) {
  const { t } = useI18n();

  if (!work.length) return <div className="ir-empty">{t('reportSystem.dq.no_structured_parts')}</div>;

  return (
    <table className="ir-side">
      <thead>
        <tr>
          <th>{t('reportSystem.ui.colDate')}</th>
          <th>{t('reportSystem.ui.colClaim')}</th>
          <th>{t('reportSystem.ui.colEvidence')}</th>
        </tr>
      </thead>
      <tbody>
        {work.map((w, i) => (
          <tr key={i}>
            <td className="ir-occ-date">{w.date}</td>
            <td>
              <Chip tone={ACTION_TONE[w.action]}>{t(`reportSystem.action.${w.action}`)}</Chip>
              {w.component ? <div className="ir-muted">{w.component}</div> : null}
            </td>
            <td className="ir-work-text">{w.text}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

/** For each completed repair: what the record shows afterwards — never "it held". */
export function Durability({ items = [] }) {
  const { t } = useI18n();

  if (!items.length) return <div className="ir-empty">{t('reportSystem.risk.postRepair.detailNone')}</div>;

  return (
    <div className="ir-durability">
      {items.map((d, i) => (
        <div className="ir-durability-item" key={i}>
          <div className="ir-durability-head">
            <span className="ir-occ-date">{d.date}</span>
            <Chip tone={ACTION_TONE[d.work.action]}>{t(`reportSystem.action.${d.work.action}`)}</Chip>
            {d.work.component ? <span>{d.work.component}</span> : null}
          </div>
          <blockquote className="ir-record"><p>{d.work.text}</p></blockquote>
          <div className="ir-durability-verdict">
            <Chip tone={DURABILITY_TONE[d.verdict]}>
              {t(`reportSystem.durability.${d.verdict}`, {
                days: d.next_confirmed?.days ?? d.next_incident?.days ?? 0,
              })}
            </Chip>
            <span className="ir-muted">
              {d.next_confirmed?.km != null
                ? `${d.next_confirmed.km} km`
                : t('reportSystem.ui.kmUnavailable')}
            </span>
          </div>
        </div>
      ))}
    </div>
  );
}

/** What is missing or weak in the records this report was built from. */
export function DataQuality({ warnings = [] }) {
  const { t } = useI18n();

  return (
    <ul className="ir-dq">
      {warnings.map((w) => (
        <li key={w.code}>
          <span aria-hidden="true">⚠</span>
          <span>{t(`reportSystem.dq.${w.code}`, { n: w.n, total: w.total })}</span>
        </li>
      ))}
    </ul>
  );
}

/**
 * DATA ORIGIN — every page states where its numbers came from and what it left out.
 * Required of all pages; see the Traceability Visibility rule.
 */
export function DataOrigin({ provenance }) {
  const { t } = useI18n();

  if (!provenance) return null;

  const entries = [
    ['source', provenance.source],
    ['window', provenance.window],
    ['grouping', provenance.grouping],
    ['split', provenance.split],
    ['derived', provenance.derived],
    ['omitted', provenance.omitted],
  ].filter(([, v]) => v);

  return (
    <Panel title={t('reportBlocks.dataOrigin')} hint={t('reportBlocks.dataOriginHint')}>
      <dl className="ir-origin">
        {entries.map(([term, value]) => (
          <div key={term}>
            <dt>{t(`reportBlocks.origin.${term}`)}</dt>
            <dd>{value}</dd>
          </div>
        ))}
        {provenance.counts ? (
          <div>
            <dt>{t('reportBlocks.origin.counts')}</dt>
            <dd>
              {Object.entries(provenance.counts)
                .map(([k, v]) => `${k.replace(/_/g, ' ')}: ${v}`)
                .join(' · ')}
            </dd>
          </div>
        ) : null}
      </dl>
    </Panel>
  );
}

/**
 * THE VERTICAL SEQUENCE — one rail, one dot per case, newest reading downward.
 *
 * This is the shape the printed PRO reports have always used, and the reason is that a rail reads as
 * a *sequence* where a stack of cards reads as a *set*. On the daily report it is the queue a manager
 * works down; on a car's system report it is the failure history in order. Both are the same object:
 * a dot coloured by severity, a meta line (who/where/when), one bold line naming the thing, and the
 * recorded text underneath.
 *
 * `meta` is an array so the caller decides what identifies a row — garage and days on the daily
 * report, date and garage on a car's history — without this block knowing either domain.
 *
 * NOTHING HERE IS GENERATED. `title` and `desc` are fields a person entered; this block only lays
 * them out.
 */
export function CaseTimeline({ items = [], empty = null }) {
  if (!items.length) return <div className="ir-empty">{empty}</div>;

  return (
    <div className="ir-timeline">
      {items.map((it, i) => (
        <div className={`ir-titem sev-${it.severity || 'unrated'}`} key={it.key ?? i}>
          <div className="ir-tdate">
            {(it.meta || []).filter(Boolean).map((m, j) => (
              <span key={j}>
                {j > 0 ? <span aria-hidden="true">· </span> : null}
                {m}
              </span>
            ))}
            {it.chip}
          </div>
          <div className="ir-tphase">{it.title}</div>
          {it.desc ? <div className="ir-tdesc">{it.desc}</div> : null}
        </div>
      ))}
    </div>
  );
}

/* ── THE SELECTED PERIOD ───────────────────────────────────────────────────────────────────────
 *
 * Everything below answers one question: *between these two dates, what went wrong with this car,
 * how often, when, what did the workshop write, and what was done?*
 *
 * Three rules hold across all of it:
 *   • THE DEFAULT IS ALL HISTORY. A reader who sets nothing sees the report they saw yesterday.
 *   • A VISIT IS NOT A FAULT and a fault seen twice is not two faults. The counters keep those apart
 *     by name, because a report that blurs them tells a fleet to replace a part that never failed.
 *   • THE GARAGE'S OWN WORDS ARE ALWAYS SHOWN next to the normalised fault name, never instead of it.
 */

/** A recorded day, written the way the reader's language writes days. Never re-interpreted. */
export function useDay() {
  return (iso) => {
    if (!iso) return null;
    const d = new Date(`${String(iso).slice(0, 10)}T00:00:00`);
    if (Number.isNaN(d.getTime())) return String(iso);
    return d.toLocaleDateString(dateLocale(), { day: '2-digit', month: 'short', year: 'numeric' });
  };
}

/**
 * THE PERIOD, STATED — and stated on the printed page too.
 *
 * This deliberately does NOT carry `ir-no-print`. A PDF of a filtered report that does not say which
 * period it covers is worse than no PDF: it looks like the whole history and reads as one.
 */
export function PeriodBanner({ period }) {
  const { t } = useI18n();
  const day = useDay();

  if (!period) return null;

  const label = {
    all_history: () => t('reportSystem.period.allHistory'),
    between: () => t('reportSystem.period.between', { from: day(period.from), to: day(period.to) }),
    single_day: () => t('reportSystem.period.singleDay', { date: day(period.from) }),
    since: () => t('reportSystem.period.since', { from: day(period.from) }),
    until: () => t('reportSystem.period.until', { to: day(period.to) }),
  }[period.shape];

  return (
    <div className={`ir-period-banner ${period.active ? 'is-filtered' : ''}`}>
      <span className="ir-period-term">{t('reportSystem.period.label')}</span>
      <strong className="ir-period-value">{label ? label() : t('reportSystem.period.allHistory')}</strong>
      {period.active && period.covered_from ? (
        <span className="ir-muted">
          {t('reportSystem.period.covered', { from: day(period.covered_from), to: day(period.covered_to) })}
        </span>
      ) : null}
    </div>
  );
}

/**
 * THE DATE-RANGE CONTROL.
 *
 * Typed into local state and committed on Apply, not on every keystroke: a half-typed year is a
 * different period, and re-querying the report on "2" then "20" then "202" would flicker through
 * three wrong answers on the way to the right one.
 *
 * The inverted range is caught here as well as on the server — but caught, not corrected. Silently
 * swapping the two dates answers a question the reader did not ask.
 */
export function DateRangeFilter({ from, to, onApply, onClear, busy = false }) {
  const { t } = useI18n();
  const [draftFrom, setDraftFrom] = useState(from || '');
  const [draftTo, setDraftTo] = useState(to || '');

  // The URL is the source of truth for the applied range — a shared link, a refresh or the browser's
  // back button all arrive here as new props, and the inputs must follow them.
  useEffect(() => { setDraftFrom(from || ''); }, [from]);
  useEffect(() => { setDraftTo(to || ''); }, [to]);

  const inverted = draftFrom && draftTo && draftFrom > draftTo;

  const submit = (e) => {
    e.preventDefault();
    if (inverted) return;
    onApply(draftFrom || null, draftTo || null);
  };

  /** Quick ranges are conveniences, not different features: each just fills the same two fields. */
  const quick = (days) => {
    const end = new Date();
    const start = new Date();
    start.setDate(start.getDate() - days);
    onApply(start.toISOString().slice(0, 10), end.toISOString().slice(0, 10));
  };

  const thisYear = () => {
    const y = new Date().getFullYear();
    onApply(`${y}-01-01`, `${y}-12-31`);
  };

  return (
    <form className="ir-daterange ir-no-print" onSubmit={submit}>
      <span className="ir-small ir-daterange-title">{t('reportSystem.period.filterLabel')}</span>

      <label className="ir-daterange-field" htmlFor="ir-from">
        <span>{t('reportSystem.period.from')}</span>
        <input
          id="ir-from"
          className="ir-control"
          type="date"
          value={draftFrom}
          max={draftTo || undefined}
          onChange={(e) => setDraftFrom(e.target.value)}
        />
      </label>

      <span className="ir-daterange-dash" aria-hidden="true">—</span>

      <label className="ir-daterange-field" htmlFor="ir-to">
        <span>{t('reportSystem.period.to')}</span>
        <input
          id="ir-to"
          className="ir-control"
          type="date"
          value={draftTo}
          min={draftFrom || undefined}
          onChange={(e) => setDraftTo(e.target.value)}
        />
      </label>

      <button type="submit" className="ir-button" disabled={!!inverted || busy}>
        {t('reportSystem.period.apply')}
      </button>
      <button type="button" className="ir-button" onClick={onClear} disabled={busy}>
        {t('reportSystem.period.clear')}
      </button>

      <span className="ir-daterange-quick">
        <button type="button" className="ir-button ir-button-quiet" onClick={() => quick(30)}>
          {t('reportSystem.period.last30')}
        </button>
        <button type="button" className="ir-button ir-button-quiet" onClick={() => quick(90)}>
          {t('reportSystem.period.last90')}
        </button>
        <button type="button" className="ir-button ir-button-quiet" onClick={thisYear}>
          {t('reportSystem.period.thisYear')}
        </button>
        <button type="button" className="ir-button ir-button-quiet" onClick={onClear}>
          {t('reportSystem.period.allHistoryShort')}
        </button>
      </span>

      {inverted ? (
        <span className="ir-daterange-error" role="alert">{t('reportSystem.period.inverted')}</span>
      ) : null}
    </form>
  );
}

/**
 * THE COUNTERS — and the reason there are five of them rather than one.
 *
 * "3 engine events" was the number this report used to lead with, and it answered none of the three
 * questions a reader actually has. Visits, distinct problems and repeats are separate facts, so they
 * are separate tiles, and the workshop-only count is shown because it is the difference between the
 * first two. A reader who sees 3 visits and 2 problems and no explanation assumes an error.
 */
export function PeriodSummary({ summary, period }) {
  const { t, tp } = useI18n();

  if (!summary) return null;

  const tiles = [
    ['systemVisits', summary.system_visits, tp('reportSystem.period.sum.visitsNote', summary.source_records)],
    ['namedFaults', summary.named_faults, tp('reportSystem.period.sum.namedNote', summary.named_fault_visits)],
    ['repeatedFaults', summary.repeated_faults, t('reportSystem.period.sum.repeatedNote')],
    ['repairs', summary.repairs, t('reportSystem.period.sum.repairsNote')],
    ['workshopOnly', summary.workshop_only_visits, t('reportSystem.period.sum.workshopOnlyNote')],
  ];

  return (
    <div className="ir-period-summary">
      <div className="ir-period-sentence">
        {period?.active
          ? t('reportSystem.period.sum.sentence', {
              visits: summary.system_visits,
              faults: summary.named_faults,
              repeated: summary.repeated_faults,
              repairs: summary.repairs,
            })
          : t('reportSystem.period.sum.sentenceAll', {
              visits: summary.system_visits,
              faults: summary.named_faults,
              repeated: summary.repeated_faults,
              repairs: summary.repairs,
            })}
      </div>
      <div className="ir-kpis">
        {tiles.map(([key, value, note]) => (
          <Kpi key={key} label={t(`reportSystem.period.sum.${key}`)} value={value} note={note} />
        ))}
      </div>
    </div>
  );
}

/** How firmly the record supports the action claimed for an occurrence. Never green for "none". */
const ACTION_EVIDENCE_TONE = { strong: 'ok', mentioned: 'warn', none: 'neutral' };

/**
 * "PROBLEMS DURING THIS PERIOD" — grouped by fault, not listed by date.
 *
 * A chronological list makes the reader do the grouping in their head, which is exactly the work this
 * page exists to remove: the two occurrences of one fault sit apart, with other faults between them,
 * and nobody notices they are the same thing. So each problem is one section carrying every time it
 * happened, and the "did it come back?" sentence is stated rather than left to be inferred.
 *
 * Every occurrence prints THE ORIGINAL WORKSHOP NOTE beneath the normalised fault name. The two are
 * different claims — one is the record, one is this report's reading of it — and showing only the
 * second would make the report unauditable.
 */
export function PeriodProblems({ problems = [], workshopOnly = [], period }) {
  const { t, tp } = useI18n();
  const day = useDay();

  if (!problems.length && !workshopOnly.length) {
    return (
      <div className="ir-empty">
        {period?.active
          ? t('reportSystem.period.emptyFiltered')
          : t('reportSystem.ui.noEvents')}
      </div>
    );
  }

  /** The one-sentence answer to "did it come back?", built only from this fault's own dates. */
  const returnLine = (p) => {
    if (p.returned.status !== 'yes') {
      return p.followed_by
        ? t('reportSystem.period.returnedNoButOther', {
            date: day(p.followed_by.date), fault: p.followed_by.fault,
          })
        : t('reportSystem.period.returnedNo');
    }

    const base = p.returned.days == null
      ? t('reportSystem.period.returnedYesUndated', { times: p.returned.times })
      : tp('reportSystem.period.returnedYes', p.returned.days, { times: p.returned.times });

    return p.returned.after_repair
      ? `${base} ${t('reportSystem.period.returnedAfterRepair')}`
      : base;
  };

  const occurrence = (o, i) => (
    <li key={`${o.date}-${i}`} className="ir-occurrence">
      <div className="ir-occ-head">
        <span className="ir-occ-date">{day(o.date) || t('reportSystem.notRecorded')}</span>
        <span className="ir-occ-garage">{o.garage || t('reportSystem.notRecorded')}</span>
        {o.severity ? (
          <Chip tone={toneFor(o.severity)}>{t(`reportSystem.severity.${o.severity}`)}</Chip>
        ) : null}
        <Chip tone={ACTION_EVIDENCE_TONE[o.action_evidence] || 'neutral'}>
          {t(`reportSystem.period.action.${o.action}`)}
        </Chip>
        {o.gap_days != null ? (
          <Chip tone="danger">{tp('reportSystem.fault.after', o.gap_days)}</Chip>
        ) : null}
        {/*
          WHICH TRIP THIS WAS. Only the whole-car report resolves it, so the chip is absent rather
          than empty on the per-system page — and "not on a contract" is printed as its own answer,
          because a missing chip and a visit outside the contract system are different facts.
        */}
        {o.contract ? (
          <Chip tone="info">
            {t('reportVehicle.occurrence.contract', { no: o.contract.no || `#${o.contract.id}` })}
          </Chip>
        ) : null}
      </div>

      {/* THE RECORD, quoted. These are the garage's words, not the report's. */}
      {o.note_lines?.length ? (
        <blockquote className="ir-record">
          {o.note_lines.map((line, j) => <p key={j}>{line}</p>)}
        </blockquote>
      ) : (
        <div className="ir-record is-empty">{t('reportSystem.fault.noNote')}</div>
      )}

      <div className="ir-occ-foot">
        <span>
          {t('reportSystem.period.actionLabel')}: {t(`reportSystem.period.action.${o.action}`)}
          {o.action_text ? <span className="ir-work-text"> — “{o.action_text}”</span> : null}
        </span>
        <span className="ir-muted">
          {t(`reportSystem.period.evidence.${o.action_evidence}`)}
          {o.row_count > 1 ? ` · ${tp('reportSystem.incident.rowCount', o.row_count)}` : ''}
        </span>
      </div>
    </li>
  );

  return (
    <div className="ir-problems">
      {problems.map((p, n) => (
        <section className={`ir-problem ${p.repeated ? 'is-repeat' : ''}`} key={p.fault_key || p.fault}>
          <header className="ir-problem-head">
            <h3>
              <span className="ir-problem-no">{n + 1}.</span> {p.fault}
            </h3>
            <div className="ir-problem-meta">
              <Chip tone={p.repeated ? 'danger' : 'neutral'}>
                {tp('reportSystem.period.occurrences', p.occurrences)}
              </Chip>
              {p.repeated ? <Chip tone="danger">{t('reportSystem.period.repeated')}</Chip> : null}
              {p.worst_severity ? (
                <Chip tone={toneFor(p.worst_severity)}>{t(`reportSystem.severity.${p.worst_severity}`)}</Chip>
              ) : null}
            </div>
            <div className="ir-problem-line">
              {/* The system is named only when the caller resolved one — the per-system report is
                  already titled with it, and repeating it on every problem would be noise. */}
              {p.system_labels?.length ? <strong>{p.system_labels.join(' · ')}</strong> : null}
              {p.system_labels?.length ? ' — ' : ''}
              {p.first_seen === p.last_seen
                ? t('reportSystem.period.seenOnce', { date: day(p.first_seen) })
                : t('reportSystem.period.seenBetween', {
                    from: day(p.first_seen), to: day(p.last_seen), days: p.span_days,
                  })}
              {p.garages?.length ? ` · ${p.garages.join(', ')}` : ''}
              {p.contracts?.length
                ? ` · ${t('reportVehicle.problem.onContracts', {
                    list: p.contracts.map((c) => c.no || `#${c.id}`).join(', '),
                  })}`
                : ''}
            </div>
          </header>

          <ol className="ir-occurrences">{p.events.map(occurrence)}</ol>

          <p className="ir-problem-return">
            <strong>{t('reportSystem.period.returnedLabel')}:</strong> {returnLine(p)}
          </p>
        </section>
      ))}

      {workshopOnly.length ? (
        <section className="ir-problem is-workshop-only">
          <header className="ir-problem-head">
            <h3>{t('reportSystem.period.workshopOnlyTitle')}</h3>
            <div className="ir-problem-meta">
              <Chip tone="neutral">{tp('reportSystem.period.occurrences', workshopOnly.length)}</Chip>
            </div>
            <div className="ir-problem-line">{t('reportSystem.period.workshopOnlyHint')}</div>
          </header>
          <ol className="ir-occurrences">{workshopOnly.map(occurrence)}</ol>
        </section>
      ) : null}
    </div>
  );
}

/** Shell: the fixed dark surface, the container, and the report footer. */
export function ReportShell({ children, footer }) {
  return (
    <div className="intel-report">
      <div className="ir-container">
        {children}
        {footer ? <div className="ir-footer">{footer}</div> : null}
      </div>
    </div>
  );
}
