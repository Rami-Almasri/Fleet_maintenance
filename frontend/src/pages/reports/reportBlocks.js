import { useI18n } from '../../i18n/I18nContext';
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
