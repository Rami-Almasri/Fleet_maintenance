// Vehicle Report — a one-click, standalone printable dossier for a single vehicle (Save-as-PDF from the
// browser). Same pattern as lib/vehicleReport.js and components/inspection/InspectionReport.js (build an
// HTML string → window.open → browser print). Input is the payload from GET /Vehicle/{id}/profile, so no
// extra network calls. Money fields are gated by SHOW_FINANCIALS like everywhere else.
//
// This module runs outside React, so it cannot call useI18n(). The translator is THREADED IN by the
// caller instead; the fallback below keeps the report readable (English, interpolation intact) when a
// caller has not been updated yet.

import { SHOW_FINANCIALS } from '../config/features';
import { aed, num, fmtDate } from './format';

const identityT = (s, vars) => String(s).replace(/\{(\w+)\}/g, (m, k) => (vars && vars[k] != null ? vars[k] : m));

const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const money = (n) => (SHOW_FINANCIALS ? aed(n) : '—');
const dash = (s) => (s === null || s === undefined || s === '' ? '—' : esc(s));
const slug = (s) => String(s || 'vehicle').replace(/[^a-z0-9]+/gi, '_').replace(/^_+|_+$/g, '');
const km = (n) => (n == null || n === '' ? '—' : `${num(n)} km`);

export function openVehicleProfileReport(data, t = identityT, lang = 'en') {
  const w = window.open('', '_blank');
  if (!w) return; // pop-up blocked
  w.document.write(buildHtml(data, t, lang));
  w.document.close();
}

function buildHtml(data, t, lang) {
  const rtl = lang === 'ar';
  const v = data?.vehicle || {};
  const reg = data?.registration || {};
  const av = data?.availability || {};
  const stats = data?.stats || {};
  const contracts = data?.contracts || [];
  const maint = data?.maintenance || [];

  const today = new Date().toISOString().slice(0, 10);
  // Filename stays transliterated ASCII regardless of language — it is a file path, not prose.
  const title = `${slug(v.make)}_${slug(v.model)}${v.year ? '_' + v.year : ''}_(${slug(v.plate_display || v.plate_no)})_${today}`;
  const name = [v.make, v.model, v.year].filter(Boolean).join(' ') || t('Vehicle');

  // ── definition-list helper ──
  const dl = (rows) => `<div class="dl">${rows.map(([k, val]) => `<div><dt>${esc(k)}</dt><dd>${val}</dd></div>`).join('')}</div>`;

  const specs = dl([
    [t('Plate'), dash(v.plate_display || v.plate_no)],
    [t('VIN / Chassis'), dash(v.vin || reg.chasis_no)],
    [t('Year'), dash(v.year)],
    [t('Colour'), dash(v.color)],
    [t('Category'), dash(v.category)],
    [t('Cylinders'), dash(v.cylinders)],
    [t('Horsepower'), dash(v.horse_power)],
    [t('Doors / Seats'), `${dash(v.doors)} / ${dash(v.seats)}`],
    [t('Transmission'), dash(v.auto_gear)],
    [t('Drive'), dash(v.wheel_drive)],
    [t('Keys'), dash(v.keys_number)],
    [t('Odometer'), km(v.odometer)],
  ]);

  const regHtml = dl([
    [t('Registration expiry'), `${dash(fmtDate(reg.expiry_date))}${reg.registration_days_left != null ? ` (${reg.registration_days_left}d)` : ''}`],
    [t('Registration status'), dash(reg.status)],
    [t('Insurer'), dash(reg.insurer)],
    [t('Insurance expiry'), `${dash(fmtDate(reg.insurance_expiry))}${reg.insurance_days_left != null ? ` (${reg.insurance_days_left}d)` : ''}`],
    [t('Mortgaged by'), dash(reg.mortgaged_by)],
    [t('Fines'), `${num(reg.fines_count || 0)}${SHOW_FINANCIALS && reg.fines_amount ? ` · ${aed(reg.fines_amount)}` : ''}`],
  ]);

  const serviceHtml = dl([
    [t('Service status'), dash(v.service_status)],
    [t('Service due'), `${dash(fmtDate(v.service_due_date))}${v.service_due_km ? ` · ${km(v.service_due_km)}` : ''}`],
    [t('Last service odo'), km(v.last_service_odometer)],
    [t('Service interval'), v.service_interval_km ? km(v.service_interval_km) : '—'],
    [t('Battery changed'), dash(fmtDate(v.battery_last_changed))],
    [t('Warranty end'), `${dash(fmtDate(v.warranty_end_date))}${v.warranty_end_km ? ` · ${km(v.warranty_end_km)}` : ''}`],
  ]);

  const commercialHtml = dl([
    [t('Status'), dash(v.operational_status_label || v.status)],
    [t('Availability'), dash(av.label)],
    [t('With customer'), dash(av.customer)],
    [t('Condition grade'), dash(v.condition_grade_label || v.condition_grade)],
    [t('Purchase date'), dash(fmtDate(v.purchase_date))],
    ...(SHOW_FINANCIALS ? [
      [t('Purchase price'), money(v.purchase_price)],
      [t('Day / Month rent'), `${money(v.day_rent_value)} / ${money(v.month_rent_value)}`],
      [t('Lifetime net profit'), money(stats.lifetime_net_profit)],
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
    : `<tr><td colspan="${SHOW_FINANCIALS ? 6 : 5}" class="muted">${esc(t('No maintenance recorded.'))}</td></tr>`;

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
    : `<tr><td colspan="${SHOW_FINANCIALS ? 7 : 6}" class="muted">${esc(t('No contracts recorded.'))}</td></tr>`;

  const maintHeading = maint.length > 30 ? t('Maintenance history (latest 30)') : t('Maintenance history');
  const contractsHeading = contracts.length > 15
    ? t('Rental contracts (latest 15 of {n})', { n: num(contracts.length) })
    : t('Rental contracts');

  return `<!doctype html><html lang="${rtl ? 'ar' : 'en'}" dir="${rtl ? 'rtl' : 'ltr'}"><head><meta charset="utf-8" />
  <title>${esc(title)}</title>
  <style>
    *{box-sizing:border-box} body{font:13px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;color:#0f172a;margin:0;padding:28px;background:#f8fafc}
    .sheet{max-width:1000px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden}
    .hd{padding:22px 26px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:flex-start;gap:16px}
    .hd h1{font-size:22px;margin:0 0 4px} .hd .sub{color:#64748b;font-size:13px}
    .hero{text-align:end;color:#64748b;font-size:12px} .hero .big{font-size:15px;font-weight:800;color:#0f172a}
    .sec{padding:20px 26px;border-top:1px solid #f1f5f9} .sec h2{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin:0 0 14px}
    .cols{display:grid;grid-template-columns:1fr 1fr;gap:26px}
    .dl{display:grid;grid-template-columns:1fr 1fr;gap:8px 18px}
    .dl dt{color:#94a3b8;font-size:10px;text-transform:uppercase;letter-spacing:.03em;margin:0} .dl dd{margin:0;font-weight:600;font-size:13px}
    table{width:100%;border-collapse:collapse;font-size:12px} th,td{text-align:start;padding:7px 10px;border-bottom:1px solid #f1f5f9}
    th{color:#94a3b8;font-size:10px;text-transform:uppercase;letter-spacing:.04em;background:#fafcff} td.r,th.r{text-align:end;font-variant-numeric:tabular-nums}
    .muted{color:#94a3b8;text-align:center;padding:18px}
    .stat{display:inline-block;margin-inline-end:22px} .stat .n{font-size:18px;font-weight:800} .stat .l{color:#94a3b8;font-size:11px}
    .ft{padding:14px 26px;color:#94a3b8;font-size:11px;border-top:1px solid #f1f5f9;display:flex;justify-content:space-between}
    .bar{max-width:1000px;margin:0 auto 14px;display:flex;justify-content:flex-end}
    .btn{background:#0f172a;color:#fff;border:0;border-radius:999px;padding:9px 18px;font-weight:700;font-size:13px;cursor:pointer}
    @media print{body{padding:0;background:#fff}.sheet{border:0}.bar{display:none}.cols{gap:16px}}
  </style></head>
  <body onload="window.focus()">
    <div class="bar"><button class="btn" onclick="window.print()">${esc(t('Print / Save as PDF'))}</button></div>
    <div class="sheet">
      <div class="hd">
        <div>
          <h1>${esc(name)}</h1>
          <div class="sub">${dash(v.plate_display || v.plate_no)}${v.vin ? ` · VIN ${esc(v.vin)}` : ''}${v.color ? ` · ${esc(v.color)}` : ''}</div>
        </div>
        <div class="hero"><div class="big">${esc(t('Vehicle Report'))}</div><div>${esc(fmtDate(today))}</div></div>
      </div>

      <div class="sec">
        <div class="stat"><span class="n">${num(stats.contracts_count || 0)}</span><span class="l"> ${esc(t('contracts'))}</span></div>
        <div class="stat"><span class="n">${num(stats.maintenance_count || 0)}</span><span class="l"> ${esc(t('maintenance visits'))}</span></div>
        ${SHOW_FINANCIALS ? `<div class="stat"><span class="n">${aed(stats.profit_bridge?.maintenance || 0)}</span><span class="l"> ${esc(t('maintenance spend'))}</span></div>` : ''}
        <div class="stat"><span class="n">${km(v.odometer)}</span><span class="l"> ${esc(t('odometer'))}</span></div>
      </div>

      <div class="sec cols">
        <div><h2>${esc(t('Specifications'))}</h2>${specs}</div>
        <div><h2>${esc(t('Registration & Insurance'))}</h2>${regHtml}</div>
      </div>
      <div class="sec cols">
        <div><h2>${esc(t('Service'))}</h2>${serviceHtml}</div>
        <div><h2>${esc(t('Commercial & Status'))}</h2>${commercialHtml}</div>
      </div>

      <div class="sec"><h2>${esc(maintHeading)}</h2>
        <table><thead><tr><th>${esc(t('Date'))}</th><th>${esc(t('Garage'))}</th><th>${esc(t('Reason'))}</th><th>${esc(t('Stage'))}</th><th>${esc(t('Tags'))}</th>${SHOW_FINANCIALS ? `<th class="r">${esc(t('Cost'))}</th>` : ''}</tr></thead>
        <tbody>${maintRows}</tbody></table>
      </div>

      <div class="sec"><h2>${esc(contractsHeading)}</h2>
        <table><thead><tr><th>${esc(t('Contract'))}</th><th>${esc(t('Type'))}</th><th>${esc(t('State'))}</th><th>${esc(t('Customer'))}</th><th>${esc(t('Out'))}</th><th>${esc(t('In'))}</th>${SHOW_FINANCIALS ? `<th class="r">${esc(t('Balance'))}</th>` : ''}</tr></thead>
        <tbody>${contractRows}</tbody></table>
      </div>

      <div class="ft"><span>${esc(t('Generated by Faster · Vehicle Report'))}</span><span>${esc(name)} · ${dash(v.plate_display || v.plate_no)} · ${esc(fmtDate(today))}</span></div>
    </div>
  </body></html>`;
}
