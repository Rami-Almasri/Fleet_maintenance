import { useI18n } from '../../i18n/I18nContext';
import { Chip, toneFor, useDay } from './reportBlocks';

/**
 * THE PIECES THE WHOLE-CAR REPORT IS MADE OF.
 *
 * The per-system report is a written argument — you read it top to bottom. This one is a page a
 * manager SCANS: they arrive asking "what is wrong with this car" and they should have the answer
 * before they have read a sentence. So the same facts are given a shape you can read at a glance —
 * a ranked list of problems, a mix of systems, activity over time — and every one of them repeats
 * its number as text beside the mark. No figure on this page exists only as a length.
 *
 * ON COLOUR, because it is load-bearing here:
 *   • A COUNT IS ONE HUE. The ranked bars are the report's accent at a single value; the bar's length
 *     is the magnitude and nothing else varies. A rainbow of "top problems" would make the reader
 *     hunt for a meaning that isn't there.
 *   • SEVERITY AND REPEAT KEEP THE STATUS COLOURS, and always with a word beside them. Red on this
 *     page means "this came back" or "this is critical" — never "this is the fourth series".
 *   • The system mix is the only CATEGORICAL chart, and it uses the shared palette in its fixed order
 *     and never cycles it: the ninth system folds into "Other" rather than borrowing the first
 *     system's colour, which would put two different things in the same red.
 */

/** Fixed, ordered: the shared categorical palette this report family already uses. */
const MIX_COLORS = ['#ef4444', '#f59e0b', '#8b5cf6', '#38bdf8', '#22c55e', '#f97316', '#ec4899', '#14b8a6'];

/**
 * "THIS CAR HAS PROBLEMS WITH 1, 2, 3" — the ranked list, longest bar first.
 *
 * The bar is a reading aid; the count, the systems, the garages and the contracts are all printed as
 * text, so the row can be checked without measuring anything. A repeated fault is marked with the
 * word "came back" as well as the colour — the whole point of the row is that it happened more than
 * once, and colour alone would hide that from a reader who cannot see it.
 *
 * Clicking a row filters the detail below to that fault. It is a button, not a link: it changes what
 * this page shows, and the browser's back button should not be the way out of a filter.
 */
export function RankedFaults({ items = [], selected = null, onSelect = null, empty = null }) {
  const { t, tp } = useI18n();

  if (!items.length) return <div className="ir-empty">{empty}</div>;

  const max = Math.max(...items.map((i) => i.occurrences), 1);

  return (
    <ol className="ir-ranked">
      {items.map((p, i) => {
        const pct = Math.max(3, Math.round((p.occurrences / max) * 100));
        const active = selected === p.key;

        return (
          <li key={p.key}>
            <button
              type="button"
              className={`ir-rank-row ${active ? 'is-active' : ''} ${p.repeated ? 'is-repeat' : ''}`}
              onClick={onSelect ? () => onSelect(active ? null : p.key) : undefined}
              disabled={!onSelect}
              aria-pressed={active}
              title={`${p.fault} — ${tp('reportVehicle.ranked.times', p.occurrences)}`}
            >
              <span className="ir-rank-no">{i + 1}</span>
              <span className="ir-rank-body">
                <span className="ir-rank-head">
                  <span className="ir-rank-fault">{p.fault}</span>
                  <span className="ir-rank-count">{tp('reportVehicle.ranked.times', p.occurrences)}</span>
                </span>
                <span className="ir-rank-track">
                  <span className={`ir-rank-fill ${p.repeated ? 'is-repeat' : ''}`} style={{ width: `${pct}%` }} />
                </span>
                <span className="ir-rank-meta">
                  {(p.system_labels || [p.system_label]).filter(Boolean).join(' · ')}
                  {p.garages?.length ? ` · ${p.garages.join(', ')}` : ''}
                </span>
              </span>
              <span className="ir-rank-tags">
                {p.repeated ? <Chip tone="danger">{t('reportVehicle.ranked.cameBack')}</Chip> : null}
                {p.worst_severity ? (
                  <Chip tone={toneFor(p.worst_severity)}>{t(`reportSystem.severity.${p.worst_severity}`)}</Chip>
                ) : null}
                {p.contracts?.length ? (
                  <Chip tone="info">{tp('reportVehicle.ranked.contracts', p.contracts.length)}</Chip>
                ) : null}
              </span>
            </button>
          </li>
        );
      })}
    </ol>
  );
}

/**
 * WHICH SYSTEMS THIS CAR IS SPENDING ITS TIME ON — a donut over the visits per system.
 *
 * It counts VISITS, not faults: a visit that found three engine faults is one trip to the workshop
 * for the engine, and counting faults here would make a thorough write-up look like a worse car.
 *
 * Capped at eight slices with the remainder gathered into "Other" — the palette has eight fixed hues
 * and reusing them would put two systems in the same colour.
 */
export function SystemMix({ systems = [], selected = null, empty = null }) {
  const { t, tf, tp } = useI18n();

  const total = systems.reduce((sum, s) => sum + s.visits, 0);

  if (!total) return <div className="ir-empty">{empty}</div>;

  const top  = systems.slice(0, 7);
  const rest = systems.slice(7);
  const slices = [
    ...top.map((s) => ({ key: s.key, label: tf(`reportSystem.systems.${s.key}`, s.label), count: s.visits })),
    ...(rest.length
      ? [{ key: 'other', label: t('reportVehicle.mix.other'), count: rest.reduce((sum, s) => sum + s.visits, 0) }]
      : []),
  ];

  const R = 45;
  const C = 2 * Math.PI * R;
  let consumed = 0;

  return (
    <div className="ir-donut">
      <svg className="ir-donut-svg" viewBox="0 0 120 120" role="img" aria-label={tp('reportVehicle.mix.aria', total)}>
        <circle cx="60" cy="60" r={R} fill="none" stroke="rgba(148,163,184,0.14)" strokeWidth="26" />
        <g transform="rotate(-90 60 60)">
          {slices.map((s, i) => {
            const len = (s.count / total) * C;
            const offset = -consumed;
            consumed += len;
            return (
              <circle
                key={s.key}
                cx="60"
                cy="60"
                r={R}
                fill="none"
                stroke={MIX_COLORS[i]}
                strokeWidth="26"
                /* A 2px gap between neighbouring slices, so two adjacent segments never read as one. */
                strokeDasharray={`${Math.max(0, len - 2)} ${Math.max(0, C - len + 2)}`}
                strokeDashoffset={offset}
              >
                <title>{`${s.label} — ${tp('reportVehicle.mix.visits', s.count)}`}</title>
              </circle>
            );
          })}
        </g>
        <text className="ir-donut-total" x="60" y="58" textAnchor="middle">{total}</text>
        <text className="ir-donut-unit" x="60" y="74" textAnchor="middle">{t('reportVehicle.mix.unit')}</text>
      </svg>

      <ul className="ir-legend">
        {slices.map((s, i) => (
          // The chosen system is marked in the legend by WEIGHT, not by a colour change: colour here
          // is the system's identity and repainting it would break the chart it labels.
          <li key={s.key} className={selected === s.key ? 'is-selected' : ''}>
            <span className="ir-swatch" style={{ background: MIX_COLORS[i] }} />
            <span className="ir-legend-label" title={s.label}>{s.label}</span>
            <span className="ir-legend-value">{s.count}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

/**
 * WHEN THE TROUBLE HAPPENED — one column per month, across the span the record covers.
 *
 * One series, one hue: the column is how many times the car was in a workshop that month. Quiet
 * months are drawn as empty columns rather than skipped, because a gap is the most useful thing on
 * this chart — a car whose trouble is four visits in one quarter is a different car from one with
 * four spread over two years, and a chart that omits the quiet months makes them look identical.
 *
 * The span can be years, so only the first month of each year and the busiest month are labelled;
 * every column carries its own numbers in its tooltip, and the months are listed in full underneath
 * the panel by the problem list itself.
 */
export function MonthColumns({ months = [], empty = null }) {
  const { t, tp } = useI18n();

  if (!months.length) return <div className="ir-empty">{empty}</div>;

  const max = Math.max(...months.map((m) => m.visits), 1);
  const peak = months.reduce((best, m) => (m.visits > (best?.visits ?? -1) ? m : best), null);

  return (
    <div className="ir-months">
      <div className="ir-months-plot" style={{ '--ir-months': months.length }}>
        {months.map((m) => {
          const pct = m.visits ? Math.max(4, Math.round((m.visits / max) * 100)) : 0;

          return (
            <div className="ir-month" key={m.month}>
              <div className="ir-month-track">
                <div
                  className={`ir-month-fill ${m.visits ? '' : 'is-empty'}`}
                  style={{ height: `${pct}%` }}
                  title={`${m.month} — ${tp('reportVehicle.months.visits', m.visits)} · ${tp('reportVehicle.months.faults', m.faults)}`}
                />
              </div>
              {/* Direct label on the busiest month only — a number on every column is noise. */}
              <div className="ir-month-value">{peak && m.month === peak.month && m.visits ? m.visits : ''}</div>
              <div className="ir-month-label">{m.month.endsWith('-01') ? m.month.slice(0, 4) : ''}</div>
            </div>
          );
        })}
      </div>
      <div className="ir-months-foot">
        {t('reportVehicle.months.foot', {
          from: months[0].month,
          to: months[months.length - 1].month,
          peak: peak?.month || '—',
        })}
      </div>
    </div>
  );
}

/**
 * WHERE THE CAR WENT — one row per workshop, busiest first.
 *
 * Visits and distinct faults are separate columns because they answer different questions, and this
 * table is deliberately NOT a ranking of garages: it says where the work happened, not how well it
 * went. Judging a workshop is the scorecard's job and needs data this page does not read.
 */
export function GarageTable({ garages = [], empty = null }) {
  const { t } = useI18n();
  const day = useDay();

  if (!garages.length) return <div className="ir-empty">{empty}</div>;

  return (
    <table className="ir-side">
      <colgroup><col className="c-name" /><col className="c-num" /><col className="c-num" /><col /></colgroup>
      <thead>
        <tr>
          <th>{t('reportVehicle.garages.garage')}</th>
          <th>{t('reportVehicle.garages.visits')}</th>
          <th>{t('reportVehicle.garages.faults')}</th>
          <th>{t('reportVehicle.garages.when')}</th>
        </tr>
      </thead>
      <tbody>
        {garages.map((g) => (
          <tr key={g.garage}>
            <td>{g.garage}</td>
            <td>{g.visits}</td>
            <td>{g.faults}</td>
            <td>{g.first === g.last ? day(g.last) : `${day(g.first)} → ${day(g.last)}`}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

/**
 * THE CONTRACTS THE FAULTS HAPPENED ON — the paper trail behind every count on this page.
 *
 * A repeat fault a manager cannot trace to specific trips is an assertion. With the contract number
 * against each occurrence, they can pull the file. Contracts are resolved by date, so a visit logged
 * outside the contract system honestly has none — see VisitContractResolver.
 */
export function ContractTable({ contracts = [], empty = null }) {
  const { t } = useI18n();
  const day = useDay();

  if (!contracts.length) return <div className="ir-empty">{empty}</div>;

  return (
    <table className="ir-side">
      <colgroup><col className="c-num" /><col /><col className="c-num" /><col /></colgroup>
      <thead>
        <tr>
          <th>{t('reportVehicle.contracts.no')}</th>
          <th>{t('reportVehicle.contracts.out')}</th>
          <th>{t('reportVehicle.contracts.faults')}</th>
          <th>{t('reportVehicle.contracts.what')}</th>
        </tr>
      </thead>
      <tbody>
        {contracts.map((c) => (
          <tr key={c.id}>
            <td className="ir-mono">{c.no || `#${c.id}`}</td>
            <td>{c.in_date ? `${day(c.out_date)} → ${day(c.in_date)}` : day(c.out_date)}</td>
            <td>{c.occurrences}</td>
            <td>{c.faults.join(', ')}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

/**
 * THE FILTER, as chips rather than a dropdown.
 *
 * A dropdown hides what the options are. These say, on the page, which systems this car has ever had
 * a record against and how many visits each — so choosing a filter and reading the summary are the
 * same action. "All systems" is always first and always available, because the whole-car view is the
 * one this page is for.
 */
export function SystemFilter({ systems = [], value = null, onChange }) {
  const { t, tf } = useI18n();

  return (
    <div className="ir-filter ir-no-print">
      <button
        type="button"
        className={`ir-filter-chip ${value === null ? 'is-active' : ''}`}
        onClick={() => onChange(null)}
        aria-pressed={value === null}
      >
        {t('reportVehicle.filter.all')}
      </button>
      {systems.map((s) => (
        <button
          type="button"
          key={s.key}
          className={`ir-filter-chip ${value === s.key ? 'is-active' : ''}`}
          onClick={() => onChange(value === s.key ? null : s.key)}
          aria-pressed={value === s.key}
        >
          {tf(`reportSystem.systems.${s.key}`, s.label)}
          <span className="ir-filter-count">{s.visits}</span>
        </button>
      ))}
    </div>
  );
}
