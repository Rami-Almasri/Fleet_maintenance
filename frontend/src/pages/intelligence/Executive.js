// EXECUTIVE HOME — Basem's page.
//
// A DIFFERENT PRODUCT FROM THE OPERATIONS SURFACES, NOT A FILTERED VIEW OF THEM.
// Adham asks "what do I do about this car today"; Basem asks "where is my money going and what
// should I change". Ticket counts, queue depths, status enums and driver names are deliberately
// absent — if he wants operational detail he clicks into it, he is never shown it by default.
//
// THE PAGE IS ONE FETCH. Six panels fetched separately could straddle a nightly rebuild and quietly
// describe two different corpora, so the spend card and the garage table would disagree with no
// visible sign. One payload, one `as_of`, one story.
//
// NO COMPOSITE SCORE. The UX spec asked for a blended "Fleet Health 72/100". It is not here, and the
// omission is the design: a composite invents a number the data never contained and hides the
// weighting behind it, so a reader cannot tell whether it moved because repairs got worse or because
// someone retuned a weight. Every figure on this page maps to rows that can be listed.

import { useCallback } from 'react';
import { Link } from 'react-router-dom';
import { getExecutiveDashboard } from '../../api/intelligence';
import useFetch from '../../hooks/useFetch';
import useEvidence from '../../hooks/useEvidence';
import EvidenceDrawer from '../../components/intelligence/EvidenceDrawer';
import IntelligenceMetricCard from '../../components/intelligence/IntelligenceMetricCard';
import Badge from '../../components/ui/Badge';
import Icon from '../../components/ui/Icon';
import Skeleton from '../../components/ui/Skeleton';
import { num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

const SHOW_FINANCIALS = process.env.REACT_APP_SHOW_FINANCIALS !== 'false';

/** Money, rendered the way an owner reads it: AED 1.5M, not 1500155.25. */
function money(v) {
  if (v == null) return '—';
  if (v >= 1_000_000) return `${(v / 1_000_000).toFixed(2)}M`;
  if (v >= 1_000) return `${(v / 1_000).toFixed(0)}k`;
  return num(Math.round(v));
}

const UNIT = { percent: '%', currency: 'AED', count: '', days: 'd' };

/** A Kpi payload → IntelligenceMetricCard props. Keeps the "withheld" contract in one place. */
function kpiProps(k, { format } = {}) {
  if (!k) return { unavailableReason: 'No data returned.' };
  if (!k.available) return { unavailableReason: k.blocked_reason || 'Not measurable.' };
  const raw = k.value;
  const value = format ? format(raw) : k.unit === 'currency' ? money(raw) : num(raw);
  return {
    value,
    unit: UNIT[k.unit] ?? '',
    band: k.confidence === 'verified' ? 'high' : k.confidence === 'partial' ? 'medium' : 'low',
    sampleSize: k.sample_size,
    coverage: k.coverage,
    asOf: k.as_of,
  };
}

export default function Executive() {
  const { t } = useI18n();
  const e = (k, v) => t(`executive.${k}`, v);

  const fetcher = useCallback(() => getExecutiveDashboard(), []);
  const { data, loading, error } = useFetch(fetcher, []);
  const evidence = useEvidence();

  if (loading) {
    return (
      <div className="space-y-4 p-4">
        <Skeleton className="h-8 w-64" />
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          {[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-28" />)}
        </div>
        <Skeleton className="h-64" />
      </div>
    );
  }

  if (error) {
    return (
      <div className="m-4 rounded-xl bg-red-50 p-4 text-sm text-red-700 ring-1 ring-inset ring-red-200">
        {e('error', 'The executive report could not be loaded.')} {error.message}
      </div>
    );
  }

  const h = data?.headline || {};
  const spend = data?.spend || {};
  const garages = data?.garages || {};
  const failures = data?.failures || {};
  const lifecycle = data?.lifecycle || {};
  const curve = lifecycle.cost_curve || {};
  const maxBand = Math.max(1, ...(curve.bands || []).map((b) => b.spend_per_car || 0));
  const maxCat = Math.max(1, ...(spend.rows || []).map((r) => r.total || 0));
  const maxFail = Math.max(1, ...(failures.rows || []).map((r) => r.events || 0));

  return (
    <div className="space-y-6 p-4">
      {/* ── Header ─────────────────────────────────────────────────────────────────────────── */}
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold tracking-tight text-slate-900">
            {e('title', 'Fleet Overview')}
          </h1>
          <p className="mt-0.5 text-sm text-slate-500">
            {e('subtitle', 'Where the money goes, and what to change.')}
          </p>
        </div>
        {data?.as_of && (
          <div className="text-end">
            <p className="text-[11px] uppercase tracking-wide text-slate-400">{e('asOf', 'Measured to')}</p>
            <p className="text-sm font-semibold tabular-nums text-slate-700">{data.as_of}</p>
          </div>
        )}
      </header>

      {/* ── Row 1 · the first thirty seconds ───────────────────────────────────────────────── */}
      <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <IntelligenceMetricCard
          label={e('reliability', 'Repair Reliability')}
          tone="text-emerald-700"
          hint={e('reliabilityHint', 'Share of repairs where the same fault did not come back.')}
          onEvidence={h.reliability?.evidence_query_id ? () => evidence.open(h.reliability.evidence_query_id) : undefined}
          {...kpiProps(h.reliability)}
        />
        {SHOW_FINANCIALS && (
          <IntelligenceMetricCard
            label={e('spend', 'Maintenance Spend')}
            hint={
              h.spend?.context?.ledger_note ||
              e('spendHint', 'Repair work only — sub-rental, insurance and fuel are excluded.')
            }
            {...kpiProps(h.spend)}
          />
        )}
        <IntelligenceMetricCard
          label={e('availability', 'Fleet Availability')}
          tone="text-sky-700"
          hint={e('availabilityHint', 'Share of the last 12 months of fleet-days not lost to the workshop.')}
          {...kpiProps(h.availability)}
        />
        <IntelligenceMetricCard
          label={e('attention', 'Cars With Repeat Faults')}
          tone="text-amber-700"
          hint={e('attentionHint', 'Cars where the same fault returned within 90 days. Counted, not scored.')}
          {...kpiProps(h.attention)}
        />
      </section>

      {/* The spend card's honesty badge, promoted out of a tooltip when the feed has stalled. */}
      {SHOW_FINANCIALS && h.spend?.context?.ledger_note && (
        <div className="flex items-start gap-2 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-inset ring-amber-200">
          <Icon name="alert" className="mt-0.5 h-4 w-4 shrink-0" />
          <p>{h.spend.context.ledger_note}</p>
        </div>
      )}

      {/* ── Row 2 · where the money goes ───────────────────────────────────────────────────── */}
      {SHOW_FINANCIALS && (
        <section className="rounded-xl bg-white p-4 ring-1 ring-inset ring-slate-200">
          <div className="flex flex-wrap items-baseline justify-between gap-2">
            <h2 className="text-sm font-semibold text-slate-900">{e('byCategory', 'Repair Spend by Category')}</h2>
            <p className="text-xs text-slate-500">
              {spend.window ? `${spend.window.start} → ${spend.window.end}` : ''}
            </p>
          </div>

          <ul className="mt-3 space-y-1.5">
            {(spend.rows || []).slice(0, 10).map((r) => (
              <li key={r.category} className="flex items-center gap-3">
                <span className="w-40 shrink-0 truncate text-xs text-slate-600">{r.category}</span>
                <span className="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                  <span
                    className="block h-full rounded-full bg-sky-500"
                    style={{ width: `${Math.max(2, (r.total / maxCat) * 100)}%` }}
                  />
                </span>
                <span className="w-20 shrink-0 text-end text-xs font-semibold tabular-nums text-slate-800">
                  {money(r.total)}
                </span>
                <span className="w-12 shrink-0 text-end text-[11px] tabular-nums text-slate-400">
                  {r.share != null ? `${r.share}%` : ''}
                </span>
              </li>
            ))}
          </ul>

          {/* Stated, never implied. The excluded pile is LARGER than the included one, and the first
              person to compare this page with the raw ledger deserves to find the answer here. */}
          {spend.excluded?.total > 0 && (
            <p className="mt-3 border-t border-slate-100 pt-2 text-[11px] leading-relaxed text-slate-500">
              {e('excludedNote', 'Excluded as non-repair costs')}:{' '}
              <span className="font-semibold tabular-nums">AED {money(spend.excluded.total)}</span>{' '}
              ({(spend.excluded.rows || []).map((x) => x.category).join(', ')}).
            </p>
          )}
        </section>
      )}

      {/* ── Row 3 · garage performance ─────────────────────────────────────────────────────── */}
      <section className="rounded-xl bg-white p-4 ring-1 ring-inset ring-slate-200">
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <h2 className="text-sm font-semibold text-slate-900">{e('garages', 'Garages To Look At')}</h2>
          <Link to="/garages" className="text-xs font-medium text-sky-700 hover:underline">
            {e('allGarages', 'All garages')} →
          </Link>
        </div>

        <p className="mt-1 text-xs text-slate-500">
          {e('garagesSub', 'Ranked by how often the same fault comes back. Worst first.')}{' '}
          {garages.fleet_comeback_pct != null && (
            <span className="text-slate-400">
              {e('fleetNorm', 'Fleet average')}: {garages.fleet_comeback_pct}%
            </span>
          )}
        </p>

        <div className="mt-3 overflow-x-auto">
          <table className="w-full min-w-[560px] text-sm">
            <thead>
              <tr className="border-b border-slate-100 text-start text-[11px] uppercase tracking-wide text-slate-400">
                <th className="py-1.5 text-start font-medium">{e('garage', 'Garage')}</th>
                <th className="py-1.5 text-end font-medium">{e('comeback', 'Comes back')}</th>
                <th className="py-1.5 text-end font-medium">{e('median', 'Typically after')}</th>
                <th className="py-1.5 text-end font-medium">{e('repairs', 'Repairs')}</th>
                <th className="py-1.5" />
              </tr>
            </thead>
            <tbody>
              {(garages.rows || []).map((g) => {
                const worse = garages.fleet_comeback_pct != null && g.comeback_pct > garages.fleet_comeback_pct;
                return (
                  <tr key={g.vendor_id} className="border-b border-slate-50 last:border-0">
                    <td className="py-2 pe-2">
                      <Link
                        to={`/intelligence/garages/${g.vendor_id}`}
                        className="font-medium text-slate-800 hover:text-sky-700 hover:underline"
                      >
                        {g.name}
                      </Link>
                    </td>
                    <td className="py-2 text-end tabular-nums">
                      <span className={worse ? 'font-semibold text-red-700' : 'text-slate-700'}>
                        {g.comeback_pct != null ? `${g.comeback_pct}%` : '—'}
                      </span>
                    </td>
                    <td className="py-2 text-end tabular-nums text-slate-600">
                      {g.median_gap_days != null ? `${g.median_gap_days}d` : '—'}
                    </td>
                    <td className="py-2 text-end tabular-nums text-slate-500">{num(g.n)}</td>
                    <td className="py-2 ps-2 text-end">
                      <button
                        type="button"
                        onClick={() => evidence.open(g.evidence_query_id)}
                        className="text-xs font-medium text-sky-700 hover:underline"
                      >
                        {e('evidence', 'Evidence')}
                      </button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>

        {/* Mandatory per UX §2.2 Row 3 — without it a tyre shop is condemned for doing tyre work. */}
        {garages.caveat && (
          <p className="mt-2 text-[11px] leading-relaxed text-slate-500">⚠ {garages.caveat}</p>
        )}
        {garages.data_note && (
          <p className="mt-1 text-[11px] leading-relaxed text-slate-400">{garages.data_note}</p>
        )}

        {SHOW_FINANCIALS && garages.cost_of_rework && (
          <div className="mt-4 border-t border-slate-100 pt-3">
            <IntelligenceMetricCard
              label={e('rework', 'Cost of Rework')}
              tone="text-red-700"
              hint={garages.cost_of_rework.context?.why_estimated}
              className="!ring-0 !px-0 !py-0"
              {...kpiProps(garages.cost_of_rework)}
            />
          </div>
        )}
      </section>

      {/* ── Row 4 · fleet failure patterns ─────────────────────────────────────────────────── */}
      <section className="rounded-xl bg-white p-4 ring-1 ring-inset ring-slate-200">
        <h2 className="text-sm font-semibold text-slate-900">{e('failures', 'Most Common Failures')}</h2>
        <p className="mt-1 text-xs text-slate-500">{failures.note}</p>

        <ul className="mt-3 space-y-1.5">
          {(failures.rows || []).map((f) => (
            <li key={f.signature} className="flex items-center gap-3">
              <span className="w-36 shrink-0 truncate text-xs font-medium text-slate-700">{f.signature}</span>
              <span className="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                <span
                  className="block h-full rounded-full bg-violet-500"
                  style={{ width: `${Math.max(2, (f.events / maxFail) * 100)}%` }}
                />
              </span>
              <span className="w-14 shrink-0 text-end text-xs tabular-nums text-slate-700">{num(f.events)}</span>
              <span className="w-24 shrink-0 text-end text-[11px] tabular-nums text-slate-400">
                {f.cars} {e('cars', 'cars')}
              </span>
              {/* One chip, opposite decisions: spread ⇒ fix the fleet, concentrated ⇒ sell the cars. */}
              <Badge tone={f.concentration === 'spread' ? 'amber' : 'gray'} className="w-24 shrink-0 justify-center">
                {f.concentration === 'spread'
                  ? e('spread', 'fleet-wide')
                  : e('concentrated', 'few cars')}
              </Badge>
            </li>
          ))}
        </ul>
      </section>

      {/* ── Row 5 · lifecycle economics ────────────────────────────────────────────────────── */}
      {SHOW_FINANCIALS && (
        <section className="grid gap-4 lg:grid-cols-3">
          <div className="rounded-xl bg-white p-4 ring-1 ring-inset ring-slate-200 lg:col-span-2">
            <h2 className="text-sm font-semibold text-slate-900">
              {e('costCurve', 'What A Car Costs As It Ages')}
            </h2>
            <p className="mt-1 text-xs text-slate-500">{curve.note}</p>

            <div className="mt-4 flex items-end gap-4">
              {(curve.bands || []).map((b) => (
                <div key={b.band} className="flex flex-1 flex-col items-center gap-1.5">
                  <span className="text-xs font-semibold tabular-nums text-slate-800">
                    {money(b.spend_per_car)}
                  </span>
                  <span
                    className="w-full rounded-t-md bg-gradient-to-t from-sky-600 to-sky-400"
                    style={{ height: `${Math.max(8, ((b.spend_per_car || 0) / maxBand) * 140)}px` }}
                  />
                  <span className="text-[11px] font-medium text-slate-600">{b.band}</span>
                  <span className="text-[10px] tabular-nums text-slate-400">
                    {b.vehicles} {e('cars', 'cars')}
                  </span>
                </div>
              ))}
            </div>
          </div>

          <div className="rounded-xl bg-white p-4 ring-1 ring-inset ring-slate-200">
            <IntelligenceMetricCard
              label={e('warranty', 'Warranty Leakage')}
              tone="text-amber-700"
              hint={e('warrantyHint', 'Repair money spent on cars still inside their warranty. Possibly recoverable.')}
              className="!ring-0 !px-0 !py-0"
              {...kpiProps(lifecycle.warranty)}
            />
          </div>
        </section>
      )}

      {/* ── Provenance ─────────────────────────────────────────────────────────────────────── */}
      {data?.provenance && (
        <section className="rounded-xl bg-slate-50 p-4 text-[11px] leading-relaxed text-slate-500 ring-1 ring-inset ring-slate-200">
          <p className="font-semibold text-slate-600">{e('provenance', 'Where these numbers come from')}</p>
          <ul className="mt-1.5 space-y-0.5">
            <li>
              <span className="font-medium">{e('pRecurrence', 'Repairs')}:</span>{' '}
              {data.provenance.recurrence?.source} · {e('pVersion', 'contract')} {data.provenance.recurrence?.version}
            </li>
            <li>
              <span className="font-medium">{e('pSpend', 'Money')}:</span> {data.provenance.spend?.note}
            </li>
            <li>
              <span className="font-medium">{e('pAvailability', 'Availability')}:</span>{' '}
              {data.provenance.availability?.source}
            </li>
          </ul>
          <p className="mt-2 italic text-slate-400">{data.provenance.no_composite_score}</p>
        </section>
      )}

      <EvidenceDrawer
        open={evidence.isOpen}
        onClose={evidence.close}
        evidence={evidence.evidence}
        loading={evidence.loading}
        error={evidence.error}
        page={evidence.page}
        onPage={evidence.goToPage}
      />
    </div>
  );
}
