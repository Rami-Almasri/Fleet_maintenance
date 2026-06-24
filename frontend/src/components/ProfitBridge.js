import { aed2 } from '../lib/format';

/**
 * The "Profit Bridge" — the gross→net reconciliation that lets you instantly distinguish what a car
 * generated on paper from what was actually pocketed. Driven by RealProfitService on the API so the
 * numbers always reconcile: Gross Revenue − Maintenance − Operating Costs = Net Profit.
 *
 * Reused anywhere a per-vehicle (or fleet-wide) lifetime figure is shown — the car profile and the
 * fleet profitability table — so the breakdown is identical platform-wide.
 *
 * @param {{gross_revenue:number, maintenance:number, operating_cost:number, net_profit:number}} bridge
 * @param {(n:number)=>string} [fmt]   money formatter (defaults to 2-dp AED)
 * @param {string} [title]             optional heading shown above the rows
 * @param {string} [netLabel]         label for the final line (default "Lifetime Net Profit")
 */
export default function ProfitBridge({ bridge, fmt = aed2, title, netLabel = 'Lifetime Net Profit', className = '' }) {
  const b = bridge || {};
  const gross = Number(b.gross_revenue || 0);
  const maintenance = Number(b.maintenance || 0);
  const operating = Number(b.operating_cost || 0);
  const net = b.net_profit != null ? Number(b.net_profit) : gross - maintenance - operating;
  const netPositive = net >= 0;

  return (
    <div className={`rounded-2xl border border-slate-200/60 bg-white px-5 py-4 shadow-soft ${className}`}>
      {title && <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{title}</p>}
      <dl className="space-y-2 text-sm">
        <Row label="Gross Lifetime Revenue" value={fmt(gross)} hint="Rent − discount + collected usage" valueClass="text-slate-900" />
        <Row label="− Total Maintenance Costs" value={fmt(maintenance)} hint="Sum of all recorded repairs" valueClass="text-amber-600" />
        <Row label="− Total Operating Costs" value={fmt(operating)} hint="Commissions + co-driver fees" valueClass="text-slate-500" />
        <div className="!mt-3 border-t border-dashed border-slate-200 pt-3">
          <Row
            label={`= ${netLabel}`}
            value={fmt(net)}
            labelClass="font-semibold text-slate-900"
            valueClass={`text-base font-bold ${netPositive ? 'text-emerald-600' : 'text-red-600'}`}
          />
        </div>
      </dl>
    </div>
  );
}

function Row({ label, value, hint, labelClass = 'text-slate-600', valueClass = 'text-slate-900' }) {
  return (
    <div className="flex items-baseline justify-between gap-4">
      <dt className={labelClass}>
        {label}
        {hint && <span className="ml-2 hidden text-xs font-normal text-slate-400 sm:inline">{hint}</span>}
      </dt>
      <dd className={`tabular-nums font-medium ${valueClass}`}>{value}</dd>
    </div>
  );
}
