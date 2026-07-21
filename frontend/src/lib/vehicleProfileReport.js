// Vehicle Report — a one-click, standalone printable dossier for a single vehicle (Save-as-PDF from the
// browser). Same pattern as lib/vehicleReport.js and components/inspection/InspectionReport.js (build an
// HTML string → window.open → browser print). Input is the payload from GET /Vehicle/{id}/profile, so no
// extra network calls. Money fields are gated by SHOW_FINANCIALS like everywhere else.

import { SHOW_FINANCIALS } from '../config/features';
import { aed, num, fmtDate } from './format';

const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const money = (n) => (SHOW_FINANCIALS ? aed(n) : '—');
const dash = (s) => (s === null || s === undefined || s === '' ? '—' : esc(s));
const slug = (s) => String(s || 'vehicle').replace(/[^a-z0-9]+/gi, '_').replace(/^_+|_+$/g, '');
const km = (n) => (n == null || n === '' ? '—' : `${num(n)} km`);

export function openVehicleProfileReport(data) {
  const w = window.open('', '_blank');
  if (!w) return; // pop-up blocked
  w.document.write(buildHtml(data));
  w.document.close();
}

function buildHtml(data) {
  const v = data?.vehicle || {};
  const reg = data?.registration || {};
  const av = data?.availability || {};
  const stats = data?.stats || {};
  const contracts = data?.contracts || [];
  const maint = data?.maintenance || [];

  const today = new Date().toISOString().slice(0, 10);
  const title = `${slug(v.make)}_${slug(v.model)}${v.year ? '_' + v.year : ''}_(${slug(v.plate_display || v.plate_no)})_${today}`;
  const name = [v.make, v.model, v.year].filter(Boolean).join(' ') || 'Vehicle';

  // ── definition-list helper ──
  const dl = (rows) => `<div class="dl">${rows.map(([k, val]) => `<div><dt>${esc(k)}</dt><dd>${val}</dd></div>`).join('')}</div>`;

  const specs = dl([
    ['Plate', dash(v.plate_display || v.plate_no)],
    ['VIN / Chassis', dash(v.vin || reg.chasis_no)],
    ['Year', dash(v.year)],
    ['Colour', dash(v.color)],
    ['Category', dash(v.category)],
    ['Cylinders', dash(v.cylinders)],
    ['Horsepower', dash(v.horse_power)],
    ['Doors / Seats', `${dash(v.doors)} / ${dash(v.seats)}`],
    ['Transmission', dash(v.auto_gear)],
    ['Drive', dash(v.wheel_drive)],
    ['Keys', dash(v.keys_number)],
    ['Odometer', km(v.odometer)],
  ]);

  const regHtml = dl([
    ['Registration expiry', `${dash(fmtDate(reg.expiry_date))}${reg.registration_days_left != null ? ` (${reg.registration_days_left}d)` : ''}`],
    ['Registration status', dash(reg.status)],
    ['Insurer', dash(reg.insurer)],
    ['Insurance expiry', `${dash(fmtDate(reg.insurance_expiry))}${reg.insurance_days_left != null ? ` (${reg.insurance_days_left}d)` : ''}`],
    ['Mortgaged by', dash(reg.mortgaged_by)],
    ['Fines', `${num(reg.fines_count || 0)}${SHOW_FINANCIALS && reg.fines_amount ? ` · ${aed(reg.fines_amount)}` : ''}`],
  ]);

  const serviceHtml = dl([
    ['Service status', dash(v.service_status)],
    ['Service due', `${dash(fmtDate(v.service_due_date))}${v.service_due_km ? ` · ${km(v.service_due_km)}` : ''}`],
    ['Last service odo', km(v.last_service_odometer)],
    ['Service interval', v.service_interval_km ? km(v.service_interval_km) : '—'],
    ['Battery changed', dash(fmtDate(v.battery_last_changed))],
    ['Warranty end', `${dash(fmtDate(v.warranty_end_date))}${v.warranty_end_km ? ` · ${km(v.warranty_end_km)}` : ''}`],
  ]);

  const commercialHtml = dl([
    ['Status', dash(v.operational_status_label || v.status)],
    ['Availability', dash(av.label)],
    ['With customer', dash(av.customer)],
    ['Condition grade', dash(v.condition_grade_label || v.condition_grade)],
    ['Purchase date', dash(fmtDate(v.purchase_date))],
    ...(SHOW_FINANCIALS ? [
      ['Purchase price', money(v.purchase_price)],
      ['Day / Month rent', `${money(v.day_rent_value)} / ${money(v.month_rent_value)}`],
      ['Lifetime net profit', money(stats.lifetime_net_profit)],
    ] : []),
  ]);

  // ── Maintenance history table ──
  const maintRows = maint.length
    ? maint.slice(0, 30).map((m) => `<tr>
        <td>${dash(fmtDate(m.date))}</td>
        <td>${dash(m.garage)}</td>
        <td>${dash(m.reason)}</td>
        <td>${dash(m.stage)}</td>
        <td>${dash(Array.isArray(m.tags) ? m.tags.join(', ') : m.tags)}</td>
        ${SHOW_FINANCIALS ? `<td class="r">${money(m.total)}</td>` : ''}
      </tr>`).join('')
    : `<tr><td colspan="${SHOW_FINANCIALS ? 6 : 5}" class="muted">No maintenance recorded.</td></tr>`;

  // ── Contracts (recent) table ──
  const contractRows = contracts.length
    ? contracts.slice(0, 15).map((c) => `<tr>
        <td>${dash(c.contract_no)}</td>
        <td>${dash(c.contract_type)}</td>
        <td>${dash(c.state)}</td>
        <td>${dash(c.customer)}</td>
        <td>${dash(fmtDate(c.out_date))}</td>
        <td>${dash(fmtDate(c.in_date))}</td>
        ${SHOW_FINANCIALS ? `<td class="r">${money(c.balance)}</td>` : ''}
      </tr>`).join('')
    : `<tr><td colspan="${SHOW_FINANCIALS ? 7 : 6}" class="muted">No contracts recorded.</td></tr>`;

  return `<!doctype html><html><head><meta charset="utf-8" />
  <title>${esc(title)}</title>
  <style>
    *{box-sizing:border-box} body{font:13px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;color:#0f172a;margin:0;padding:28px;background:#f8fafc}
    .sheet{max-width:1000px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden}
    .hd{padding:22px 26px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:flex-start;gap:16px}
    .hd h1{font-size:22px;margin:0 0 4px} .hd .sub{color:#64748b;font-size:13px}
    .hero{text-align:right;color:#64748b;font-size:12px} .hero .big{font-size:15px;font-weight:800;color:#0f172a}
    .sec{padding:20px 26px;border-top:1px solid #f1f5f9} .sec h2{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin:0 0 14px}
    .cols{display:grid;grid-template-columns:1fr 1fr;gap:26px}
    .dl{display:grid;grid-template-columns:1fr 1fr;gap:8px 18px}
    .dl dt{color:#94a3b8;font-size:10px;text-transform:uppercase;letter-spacing:.03em;margin:0} .dl dd{margin:0;font-weight:600;font-size:13px}
    table{width:100%;border-collapse:collapse;font-size:12px} th,td{text-align:left;padding:7px 10px;border-bottom:1px solid #f1f5f9}
    th{color:#94a3b8;font-size:10px;text-transform:uppercase;letter-spacing:.04em;background:#fafcff} td.r,th.r{text-align:right;font-variant-numeric:tabular-nums}
    .muted{color:#94a3b8;text-align:center;padding:18px}
    .stat{display:inline-block;margin-right:22px} .stat .n{font-size:18px;font-weight:800} .stat .l{color:#94a3b8;font-size:11px}
    .ft{padding:14px 26px;color:#94a3b8;font-size:11px;border-top:1px solid #f1f5f9;display:flex;justify-content:space-between}
    .bar{max-width:1000px;margin:0 auto 14px;display:flex;justify-content:flex-end}
    .btn{background:#0f172a;color:#fff;border:0;border-radius:999px;padding:9px 18px;font-weight:700;font-size:13px;cursor:pointer}
    @media print{body{padding:0;background:#fff}.sheet{border:0}.bar{display:none}.cols{gap:16px}}
  </style></head>
  <body onload="window.focus()">
    <div class="bar"><button class="btn" onclick="window.print()">Print / Save as PDF</button></div>
    <div class="sheet">
      <div class="hd">
        <div>
          <h1>${esc(name)}</h1>
          <div class="sub">${dash(v.plate_display || v.plate_no)}${v.vin ? ` · VIN ${esc(v.vin)}` : ''}${v.color ? ` · ${esc(v.color)}` : ''}</div>
        </div>
        <div class="hero"><div class="big">Vehicle Report</div><div>${esc(fmtDate(today))}</div></div>
      </div>

      <div class="sec">
        <div class="stat"><span class="n">${num(stats.contracts_count || 0)}</span><span class="l"> contracts</span></div>
        <div class="stat"><span class="n">${num(stats.maintenance_count || 0)}</span><span class="l"> maintenance visits</span></div>
        ${SHOW_FINANCIALS ? `<div class="stat"><span class="n">${aed(stats.maintenance_total || 0)}</span><span class="l"> maintenance spend</span></div>` : ''}
        <div class="stat"><span class="n">${km(v.odometer)}</span><span class="l"> odometer</span></div>
      </div>

      <div class="sec cols">
        <div><h2>Specifications</h2>${specs}</div>
        <div><h2>Registration & Insurance</h2>${regHtml}</div>
      </div>
      <div class="sec cols">
        <div><h2>Service</h2>${serviceHtml}</div>
        <div><h2>Commercial & Status</h2>${commercialHtml}</div>
      </div>

      <div class="sec"><h2>Maintenance history${maint.length > 30 ? ' (latest 30)' : ''}</h2>
        <table><thead><tr><th>Date</th><th>Garage</th><th>Reason</th><th>Stage</th><th>Tags</th>${SHOW_FINANCIALS ? '<th class="r">Cost</th>' : ''}</tr></thead>
        <tbody>${maintRows}</tbody></table>
      </div>

      <div class="sec"><h2>Rental contracts${contracts.length > 15 ? ` (latest 15 of ${num(contracts.length)})` : ''}</h2>
        <table><thead><tr><th>Contract</th><th>Type</th><th>State</th><th>Customer</th><th>Out</th><th>In</th>${SHOW_FINANCIALS ? '<th class="r">Balance</th>' : ''}</tr></thead>
        <tbody>${contractRows}</tbody></table>
      </div>

      <div class="ft"><span>Generated by Faster · Vehicle Report</span><span>${esc(name)} · ${dash(v.plate_display || v.plate_no)} · ${esc(fmtDate(today))}</span></div>
    </div>
  </body></html>`;
}
