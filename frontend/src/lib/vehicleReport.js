// Car History Report — a one-click, standalone, printable vehicle-history document (Save-as-PDF from the
// browser). It reuses the app's existing "build an HTML string → window.open → browser print" pattern
// (see components/inspection/InspectionReport.js) — there is no server-side PDF library. Input is the
// exact payload from GET /car-status/vehicle/{id}, so the report needs no extra network calls.

import { SHOW_FINANCIALS } from '../config/features';
import { aed, num, fmtDate } from './format';

const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const money = (n) => (SHOW_FINANCIALS ? aed(n) : '—');
const hrs = (h) => (h == null ? '—' : h >= 48 ? `${Math.round(h / 24)}d` : `${h}h`);
const slug = (s) => String(s || 'vehicle').replace(/[^a-z0-9]+/gi, '_').replace(/^_+|_+$/g, '');

// Open the report in a new tab (the user then Prints / Saves as PDF). Title drives the suggested filename.
export function openVehicleReport(data) {
  const w = window.open('', '_blank');
  if (!w) return; // pop-up blocked
  w.document.write(buildVehicleReportHtml(data));
  w.document.close();
}

function buildVehicleReportHtml(data) {
  const v = data?.vehicle || {};
  const h = data?.header || {};
  const k = data?.kpis || {};
  const summary = data?.history_summary || [];
  const fa = data?.fault_analytics || {};
  const rel = data?.reliability || {};
  const garages = data?.garages || {};
  const history = data?.history || [];
  const cats = fa.categories || [];

  const today = new Date();
  const dateStr = today.toISOString().slice(0, 10);
  const fileTitle = `${slug(v.car)}_(${slug(v.plate_no)})_${dateStr}`;

  // ── Summary KPI tiles ──
  const kpi = (label, val) => `<div class="kpi"><div class="k">${esc(label)}</div><div class="v">${esc(val)}</div></div>`;
  const kpis = [
    kpi('Health', `${k.health_score ?? '—'}`),
    kpi('Reliability', `${k.reliability_score ?? '—'}`),
    kpi('Maintenance cases', num(k.total_cases)),
    kpi('Open faults', num(k.open_faults)),
    kpi('Closed faults', num(k.closed_faults)),
    kpi('High severity', num(k.high_severity_faults)),
    kpi('Repeat repairs', num(k.repeat_repairs)),
    kpi('Downtime (days)', num(k.lifetime_downtime_days)),
    kpi('Avg repair time', hrs(k.avg_repair_hours)),
    kpi('Warranty repairs', num(k.warranty_repairs)),
    kpi('Preventive', `${num(k.preventive_ratio)}%`),
    kpi('Total cost', money(k.total_cost)),
  ].join('');

  // ── History summary chips ──
  const chips = summary.map((s) => `<div class="chip"><span class="n">${num(s.count)}</span><span class="l">${esc(s.label)}</span></div>`).join('');

  // ── Fault analytics table ──
  const faultRows = cats.length
    ? cats.map((c) => `<tr>
        <td>${esc(c.label)}</td>
        <td class="r">${num(c.count)}</td>
        <td class="r">${c.percent}%</td>
        <td class="r">${hrs(c.avg_hours)}</td>
        <td class="r">${num(c.parts)}</td>
        ${SHOW_FINANCIALS ? `<td class="r">${money(c.cost)}</td>` : ''}
        <td>${esc((c.garages || []).join(', ') || '—')}</td>
      </tr>`).join('')
    : `<tr><td colspan="${SHOW_FINANCIALS ? 7 : 6}" class="muted">No categorised faults recorded.</td></tr>`;

  // ── Reliability ──
  const relRows = [
    ['MTBF (mean time between failures)', rel.mtbf_days != null ? `${num(rel.mtbf_days)} days` : '—'],
    ['MTTR (mean time to repair)', hrs(rel.mttr_hours)],
    ['Repair frequency', rel.repair_frequency != null ? `${rel.repair_frequency} / month` : '—'],
    ['Avg km between failures', rel.avg_km_between_failures ? `${num(rel.avg_km_between_failures)} km` : '—'],
    ['Cost per km', SHOW_FINANCIALS && rel.cost_per_km != null ? aed(rel.cost_per_km) : '—'],
    ['Cost per case', money(rel.cost_per_case)],
  ].map(([a, b]) => `<tr><td>${esc(a)}</td><td class="r">${esc(b)}</td></tr>`).join('');

  // ── Garage performance ──
  const garageRows = (garages.rows || []).length
    ? garages.rows.map((g) => `<tr>
        <td>${esc(g.garage)}</td>
        <td class="r">${num(g.repairs)}</td>
        <td class="r">${hrs(g.avg_hours)}</td>
        ${SHOW_FINANCIALS ? `<td class="r">${money(g.avg_cost)}</td>` : ''}
        <td class="r">${g.comeback_rate}%</td>
        <td class="r">${num(g.repeat_failures)}</td>
        <td class="r">${g.quality_score}</td>
      </tr>`).join('')
    : `<tr><td colspan="${SHOW_FINANCIALS ? 7 : 6}" class="muted">No garage history.</td></tr>`;

  // ── Full repair history ──
  const flag = (r) => [r.returned && 'Returned', r.reopened && 'Reopened', r.failed_inspection && 'Failed QC', r.repeat_repair && 'Repeat'].filter(Boolean).join(', ') || '—';
  const histRows = history.length
    ? history.map((r) => `<tr>
        <td>${esc(r.case_no)}</td>
        <td>${esc(r.type)}</td>
        <td>${r.open_date ? esc(fmtDate(r.open_date)) : '—'}</td>
        <td>${r.close_date ? esc(fmtDate(r.close_date)) : '—'}</td>
        <td class="r">${hrs(r.duration_hours)}</td>
        <td>${esc(r.garage || '—')}</td>
        <td>${esc(r.main_fault || '—')}</td>
        <td class="r">${num(r.faults)}</td>
        <td class="r">${num(r.parts_used)}</td>
        ${SHOW_FINANCIALS ? `<td class="r">${money(r.total_cost)}</td>` : ''}
        <td>${esc(r.result)}</td>
        <td>${esc(flag(r))}</td>
      </tr>`).join('')
    : `<tr><td colspan="${SHOW_FINANCIALS ? 12 : 11}" class="muted">No maintenance history.</td></tr>`;

  return `<!doctype html><html><head><meta charset="utf-8" />
  <title>${esc(fileTitle)}</title>
  <style>
    *{box-sizing:border-box} body{font:13px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;color:#0f172a;margin:0;padding:28px;background:#f8fafc}
    .sheet{max-width:1000px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden}
    .hd{padding:22px 26px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:flex-start;gap:16px}
    .hd h1{font-size:22px;margin:0 0 4px} .hd .sub{color:#64748b;font-size:13px}
    .hd .badges{margin-top:8px;display:flex;flex-wrap:wrap;gap:6px}
    .badge{border-radius:999px;padding:3px 11px;font-size:11px;font-weight:700;color:#fff}
    .hero{text-align:right} .hero .big{font-size:34px;font-weight:800;line-height:1} .hero .lbl{color:#64748b;font-size:12px}
    .sec{padding:20px 26px;border-top:1px solid #f1f5f9} .sec h2{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin:0 0 14px}
    .kpis{display:grid;grid-template-columns:repeat(6,1fr);gap:10px}
    .kpi{border:1px solid #eef2f7;border-radius:10px;padding:10px 12px;background:#fafcff}
    .kpi .k{color:#94a3b8;font-size:10px;text-transform:uppercase;letter-spacing:.03em} .kpi .v{font-size:18px;font-weight:800;margin-top:2px}
    .chips{display:grid;grid-template-columns:repeat(8,1fr);gap:8px}
    .chip{border:1px solid #eef2f7;border-radius:10px;padding:10px;text-align:center;background:#fafcff}
    .chip .n{display:block;font-size:18px;font-weight:800} .chip .l{color:#94a3b8;font-size:10px}
    table{width:100%;border-collapse:collapse;font-size:12px} th,td{text-align:left;padding:7px 10px;border-bottom:1px solid #f1f5f9}
    th{color:#94a3b8;font-size:10px;text-transform:uppercase;letter-spacing:.04em;background:#fafcff} td.r,th.r{text-align:right;font-variant-numeric:tabular-nums}
    .muted{color:#94a3b8;text-align:center;padding:18px}
    .two{display:grid;grid-template-columns:1fr 1fr;gap:26px}
    .ft{padding:14px 26px;color:#94a3b8;font-size:11px;border-top:1px solid #f1f5f9;display:flex;justify-content:space-between}
    .bar{max-width:1000px;margin:0 auto 14px;display:flex;justify-content:flex-end;gap:8px}
    .btn{background:#0f172a;color:#fff;border:0;border-radius:999px;padding:9px 18px;font-weight:700;font-size:13px;cursor:pointer}
    @media print{body{padding:0;background:#fff}.sheet{border:0}.bar{display:none}.kpis{grid-template-columns:repeat(4,1fr)}.chips{grid-template-columns:repeat(4,1fr)}}
  </style></head>
  <body onload="window.focus()">
    <div class="bar"><button class="btn" onclick="window.print()">Print / Save as PDF</button></div>
    <div class="sheet">
      <div class="hd">
        <div>
          <h1>${esc(v.car || 'Vehicle')}</h1>
          <div class="sub">${esc(v.plate_no || '—')}${v.vin ? ` · VIN ${esc(v.vin)}` : ''}${v.odometer ? ` · ${num(v.odometer)} km` : ''}</div>
          <div class="badges">
            <span class="badge" style="background:${toneColor(h.stage_tone)}">${esc(h.stage || '—')}</span>
            ${h.owner ? `<span class="badge" style="background:#475569">${esc(h.owner)}</span>` : ''}
            <span class="badge" style="background:${toneColor(h.risk && h.risk.tone)}">${esc((h.risk && h.risk.label) || '—')} risk</span>
          </div>
        </div>
        <div class="hero">
          <div class="big">${k.health_score ?? '—'}</div>
          <div class="lbl">Health score</div>
          <div class="lbl" style="margin-top:6px">Report ${esc(fmtDate(dateStr))}</div>
        </div>
      </div>

      <div class="sec"><h2>Overview</h2><div class="kpis">${kpis}</div></div>

      <div class="sec"><h2>Maintenance history summary</h2><div class="chips">${chips}</div></div>

      <div class="sec"><h2>Fault analytics${fa.total ? ` — ${num(fa.total)} faults · ${num(fa.recurrence_rate)}% recurrence` : ''}</h2>
        <table><thead><tr><th>Category</th><th class="r">Faults</th><th class="r">Share</th><th class="r">Avg time</th><th class="r">Parts</th>${SHOW_FINANCIALS ? '<th class="r">Cost</th>' : ''}<th>Garages</th></tr></thead>
        <tbody>${faultRows}</tbody></table>
      </div>

      <div class="sec two">
        <div><h2>Reliability</h2><table><tbody>${relRows}</tbody></table></div>
        <div><h2>Garage performance</h2>
          <table><thead><tr><th>Garage</th><th class="r">Visits</th><th class="r">Avg dur.</th>${SHOW_FINANCIALS ? '<th class="r">Avg cost</th>' : ''}<th class="r">Rework</th><th class="r">Fails</th><th class="r">Quality</th></tr></thead>
          <tbody>${garageRows}</tbody></table>
        </div>
      </div>

      <div class="sec"><h2>Repair history — ${num(history.length)} case${history.length === 1 ? '' : 's'}</h2>
        <table><thead><tr><th>Case</th><th>Type</th><th>Opened</th><th>Closed</th><th class="r">Duration</th><th>Garage</th><th>Main fault</th><th class="r">Faults</th><th class="r">Parts</th>${SHOW_FINANCIALS ? '<th class="r">Cost</th>' : ''}<th>Result</th><th>Flags</th></tr></thead>
        <tbody>${histRows}</tbody></table>
      </div>

      <div class="ft"><span>Generated by Faster · Car History Report</span><span>${esc(v.car || '')} · ${esc(v.plate_no || '')} · ${esc(fmtDate(dateStr))}</span></div>
    </div>
  </body></html>`;
}

// Badge tone → a solid hex (the report is a standalone document, so no Tailwind classes are available).
function toneColor(tone) {
  return {
    red: '#ef4444', amber: '#f59e0b', green: '#10b981', emerald: '#10b981', blue: '#3b82f6',
    violet: '#8b5cf6', teal: '#14b8a6', cyan: '#06b6d4', slate: '#64748b', gray: '#64748b',
  }[tone] || '#64748b';
}
