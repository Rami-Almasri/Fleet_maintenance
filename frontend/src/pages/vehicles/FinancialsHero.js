import { useEffect, useState } from 'react';
import { useCountUp } from '../../components/ui/Gauge';
import { ChartTooltip } from '../../components/ui/Tooltip';
import { palette } from '../../components/ui/chartUtils';
import MetricCard, { MetricGrid } from '../../components/ui/MetricCard';
import Icon from '../../components/ui/Icon';
import { aed, aed2, fmtDate } from '../../lib/format';

/**
 * The "hero" of the Financials tab: a lifetime revenue-vs-cost dashboard for one car.
 *  • Cost Composition — an interactive donut (Acquisition / Maintenance / Operating) that draws
 *    itself on mount, shows a tooltip on hover, and DRILLS to the raw records on click.
 *  • Return on Investment — a prominent ROI ring highlighting the gross-revenue-÷-cost multiplier.
 * Purely presentational off the /Vehicle/{id}/profile payload the parent already loaded (no fetch);
 * money surface, so the parent gates it behind SHOW_FINANCIALS. `onDrill(key)` is fired when a
 * segment/legend row is clicked, so the parent can jump to the records behind that cost.
 */

// Each cost bucket → its donut colour + where "the raw records" live (for the click-through).
const SEGMENTS = [
  { key: 'acquisition', label: 'Acquisition', color: 'slate', drill: 'purchase details' },
  { key: 'maintenance', label: 'Maintenance', color: 'amber', drill: 'maintenance visits' },
  { key: 'operating',   label: 'Operating',   color: 'cyan',  drill: 'contract history' },
];

let RID = 0; // unique gradient ids so multiple rings never collide

// ── ROI ring — the gross-revenue-÷-cost multiplier as a filling arc (full = fully recovered) ──
function RoiRing({ recovery }) {
  const size = 176, stroke = 15;
  const r = (size - stroke) / 2;
  const circ = 2 * Math.PI * r;
  const ratio = recovery == null ? 0 : Math.max(0, Math.min(1, recovery));
  const over = recovery != null && recovery >= 1;
  const pal = palette(over ? 'emerald' : 'amber');
  const [gid] = useState(() => `roiGrad${++RID}`);

  const [shown, setShown] = useState(0);
  useEffect(() => { const id = requestAnimationFrame(() => setShown(ratio)); return () => cancelAnimationFrame(id); }, [ratio]);
  const display = useCountUp(recovery || 0);

  return (
    <div className="relative" style={{ width: size, height: size }}>
      <svg width={size} height={size} className="-rotate-90">
        <defs>
          <linearGradient id={gid} x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stopColor={pal.from} />
            <stop offset="100%" stopColor={pal.to} />
          </linearGradient>
        </defs>
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="rgb(var(--line))" strokeWidth={stroke} />
        <circle
          cx={size / 2} cy={size / 2} r={r} fill="none"
          stroke={`url(#${gid})`} strokeWidth={stroke} strokeLinecap="round"
          strokeDasharray={circ} strokeDashoffset={circ * (1 - shown)}
          style={{ transition: 'stroke-dashoffset 1.2s cubic-bezier(0.22,1,0.36,1)' }}
        />
      </svg>
      <div className="absolute inset-0 flex flex-col items-center justify-center">
        <span className={`font-display text-4xl font-bold tracking-tight tabular-nums ${over ? 'text-emerald-600' : 'text-amber-600'}`}>
          {recovery != null ? `${display.toFixed(1)}×` : '—'}
        </span>
        <span className="mt-0.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">earned back</span>
      </div>
    </div>
  );
}

// ── Interactive cost-composition donut — hover tooltip, dim-others highlight, click to drill ──
function CostDonut({ segments, total, onDrill }) {
  const size = 208, stroke = 26;
  const r = (size - stroke) / 2;
  const circ = 2 * Math.PI * r;
  const data = segments.filter((s) => s.value > 0);

  const [grow, setGrow] = useState(0);
  useEffect(() => { const id = requestAnimationFrame(() => setGrow(1)); return () => cancelAnimationFrame(id); }, [total]);
  const display = useCountUp(total);

  const [active, setActive] = useState(null);
  const [tip, setTip] = useState(null);

  let acc = 0;
  const arcs = data.map((s, i) => {
    const frac = total ? s.value / total : 0;
    const a = { ...s, frac, offset: acc, i };
    acc += frac;
    return a;
  });

  const showTip = (a) => (e) => {
    setActive(a.i);
    setTip({
      x: e.clientX, y: e.clientY,
      content: (
        <div>
          <div className="text-[10px] font-semibold uppercase tracking-wide text-white/60">{a.label}</div>
          <div className="mt-0.5 font-bold tabular-nums">{aed2(a.value)}</div>
          <div className="tabular-nums text-white/70">{Math.round(a.frac * 100)}% · click for {a.drill}</div>
        </div>
      ),
    });
  };
  const clear = () => { setActive(null); setTip(null); };

  return (
    <div className="flex flex-col items-center gap-6 sm:flex-row sm:gap-8">
      <div className="relative shrink-0" style={{ width: size, height: size }}>
        <svg width={size} height={size} className="-rotate-90">
          <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="rgb(var(--line))" strokeWidth={stroke} />
          {arcs.map((a) => {
            const pal = palette(a.color);
            const len = circ * a.frac * grow;
            const dim = active != null && active !== a.i;
            return (
              <circle
                key={a.key}
                cx={size / 2} cy={size / 2} r={r} fill="none"
                stroke={pal.from} strokeWidth={stroke} strokeLinecap="butt"
                strokeDasharray={`${len} ${circ - len}`}
                strokeDashoffset={-circ * a.offset * grow}
                opacity={dim ? 0.35 : 1}
                role="button" tabIndex={0}
                aria-label={`${a.label}: ${aed2(a.value)}, ${Math.round(a.frac * 100)} percent — view ${a.drill}`}
                onMouseMove={showTip(a)}
                onMouseLeave={clear}
                onClick={() => onDrill?.(a.key)}
                onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onDrill?.(a.key); } }}
                style={{ cursor: 'pointer', transition: 'stroke-dasharray 1.1s cubic-bezier(0.22,1,0.36,1), stroke-dashoffset 1.1s cubic-bezier(0.22,1,0.36,1), opacity 0.2s' }}
              />
            );
          })}
        </svg>
        <div className="absolute inset-0 flex flex-col items-center justify-center">
          <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Total cost</span>
          <span className="font-display text-2xl font-bold tracking-tight tabular-nums text-slate-900">{aed(display)}</span>
        </div>
      </div>

      {/* legend — clickable rows, hover-synced with the arcs */}
      <div className="w-full space-y-1">
        {arcs.map((a) => {
          const pal = palette(a.color);
          const dim = active != null && active !== a.i;
          return (
            <button
              key={a.key}
              type="button"
              onMouseEnter={() => setActive(a.i)}
              onMouseLeave={() => setActive(null)}
              onClick={() => onDrill?.(a.key)}
              className={`flex w-full items-center gap-3 rounded-xl px-3 py-2 text-left transition hover:bg-slate-50 ${dim ? 'opacity-50' : ''}`}
            >
              <span className="h-3 w-3 shrink-0 rounded-full" style={{ backgroundColor: pal.from }} />
              <span className="min-w-0 flex-1">
                <span className="block text-sm font-medium text-slate-700">{a.label}</span>
                <span className="block text-[11px] text-slate-400">{Math.round(a.frac * 100)}% · view {a.drill} →</span>
              </span>
              <span className="text-sm font-bold tabular-nums text-slate-900">{aed2(a.value)}</span>
            </button>
          );
        })}
      </div>
      <ChartTooltip tip={tip} />
    </div>
  );
}

export default function FinancialsHero({ vehicle, stats, onDrill }) {
  const v = vehicle || {};
  const bridge = stats?.profit_bridge || {};

  const amounts = {
    acquisition: Number(v.purchase_price || 0),
    maintenance: Number(stats?.maintenance_total || 0),
    operating: Number(bridge.operating_cost || 0),
  };
  const tco = amounts.acquisition + amounts.maintenance + amounts.operating;
  const gross = Number(bridge.gross_revenue || 0);
  const recovery = tco > 0 ? gross / tco : null;
  const segments = SEGMENTS.map((s) => ({ ...s, value: amounts[s.key] }));

  return (
    <section className="overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-card">
      <div className="border-b border-slate-100 px-6 py-4">
        <h3 className="text-base font-semibold text-slate-900">Financial Performance</h3>
        <p className="mt-0.5 text-xs text-slate-400">Lifetime revenue vs. every cost this car has incurred.</p>
      </div>

      {tco <= 0 ? (
        <p className="px-6 py-12 text-center text-sm text-slate-400">
          No ownership costs on file yet — add a purchase price (FASTER Asset sheet) or maintenance to build the picture.
        </p>
      ) : (
        <div className="space-y-6 p-6 lg:space-y-8 lg:p-8">
          {/* Polished KPI tiles — the design-system MetricCard (label + info tip + value + delta) */}
          <MetricGrid cols={3}>
            <MetricCard
              label="Total Cost of Ownership"
              value={aed(tco)}
              tone="indigo"
              icon={<Icon.Cash className="h-5 w-5" />}
              tooltip="Acquisition + lifetime maintenance + operating. Excludes VAT, insurance, financing & depreciation."
              hint="Acquisition + maintenance + operating"
            />
            <MetricCard
              label="Gross Revenue"
              value={aed(gross)}
              tone="emerald"
              icon={<Icon.Chart className="h-5 w-5" />}
              tooltip="Lifetime rental revenue, reverse-engineered from OfficeManager billing via RealProfitService."
              delta={recovery != null ? `${recovery.toFixed(1)}×` : undefined}
              trend={recovery != null && recovery >= 1 ? 'up' : 'down'}
              hint="earned vs. total cost"
            />
            <MetricCard
              label="Purchase Price"
              value={amounts.acquisition > 0 ? aed(amounts.acquisition) : '—'}
              tone="slate"
              icon={<Icon.Invoice className="h-5 w-5" />}
              tooltip="From the FASTER Asset sheet — the single source of truth for purchase price & date."
              hint={v.purchase_date ? `Purchased ${fmtDate(v.purchase_date)}` : 'Purchase date not on file'}
            />
          </MetricGrid>

          {/* Visuals — ROI ring + interactive cost-composition donut */}
          <div className="grid gap-6 lg:grid-cols-[0.85fr_1.15fr] lg:gap-8">
            <div className="flex flex-col items-center justify-center gap-4 rounded-2xl bg-gradient-to-br from-emerald-50/70 via-white to-white p-6 ring-1 ring-emerald-100/70">
              <p className="text-xs font-semibold uppercase tracking-wide text-emerald-700/70">Return on investment</p>
              <RoiRing recovery={recovery} />
              <p className="text-center text-xs text-slate-500">
                Gross revenue is <span className={`font-bold ${recovery >= 1 ? 'text-emerald-600' : 'text-amber-600'}`}>{recovery != null ? `${recovery.toFixed(1)}×` : '—'}</span> the total cost
              </p>
            </div>

            <div className="flex flex-col justify-center">
              <p className="mb-4 text-xs font-semibold uppercase tracking-wide text-slate-400">Cost composition</p>
              <CostDonut segments={segments} total={tco} onDrill={onDrill} />
            </div>
          </div>
        </div>
      )}

      <div className="border-t border-slate-100 bg-slate-50/40 px-6 py-2.5">
        <p className="text-[11px] leading-relaxed text-slate-400">
          <span className="font-semibold text-slate-500">Data origin</span> · Source: Ledger / Contracts via RealProfitService · purchase price from the FASTER Asset sheet. Excludes VAT, insurance, financing &amp; depreciation.
        </p>
      </div>
    </section>
  );
}
