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
 * DATA ORIGIN — every page states where its numbers came from and what it left out.
 * Required of all pages; see the Traceability Visibility rule.
 */
export function DataOrigin({ provenance }) {
  if (!provenance) return null;

  const entries = [
    ['Read from', provenance.source],
    ['Window', provenance.window],
    ['Grouping', provenance.grouping],
    ['Split', provenance.split],
    ['Derived', provenance.derived],
    ['Left out', provenance.omitted],
  ].filter(([, v]) => v);

  return (
    <Panel title="Data Origin" hint="What this report read, and what it did not">
      <dl className="ir-origin">
        {entries.map(([term, value]) => (
          <div key={term}>
            <dt>{term}</dt>
            <dd>{value}</dd>
          </div>
        ))}
        {provenance.counts ? (
          <div>
            <dt>Counts</dt>
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
