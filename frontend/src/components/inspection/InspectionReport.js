import { useMemo, useState } from 'react';
import {
  damageTypeLabel,
  severityMeta,
  damageStatus,
  damageStatusMeta,
  fuelStatusMeta,
  fuelFraction,
  FUEL,
} from '../../lib/inspections';
import { SHOW_FINANCIALS } from '../../config/features';

// Money helper — fuel charge only renders when financials are enabled.
const money = (n) => `${FUEL.currency} ${Number(n || 0).toFixed(2)}`;

// ── Condition Report ─────────────────────────────────────────────────────────
//
// The deliverable. The diagram + slider capture data; this turns that data into
// the artefact an inspector actually hands over: a completeness ring, a severity
// -ordered damage breakdown, and a one-click standalone HTML report (Save-as-PDF
// from the browser) with every captured photo embedded as a data URL so the file
// is fully self-contained — no S3, no backend, works offline.
//
// Purely a function of the records the parent already owns; renders nothing
// stateful that the prototype depends on, so it's safe to drop in as-is.

const SEVERITY_ORDER = { high: 0, medium: 1, low: 2 };

// A blob/object URL → data URL, so the exported report carries its own pixels.
function toDataUrl(url) {
  return fetch(url)
    .then((r) => r.blob())
    .then(
      (blob) =>
        new Promise((resolve) => {
          const fr = new FileReader();
          fr.onload = () => resolve(fr.result);
          fr.onerror = () => resolve(null);
          fr.readAsDataURL(blob);
        })
    )
    .catch(() => null);
}

export default function InspectionReport({ records, labelFor, phaseLabel, session, inspectorName, fuelAudit = null }) {
  const [exporting, setExporting] = useState(false);

  const summary = useMemo(() => {
    const entries = Object.entries(records);
    const captured = entries.filter(([, r]) => (r.photos?.length || 0) > 0);
    const damages = entries
      .filter(([, r]) => r.damage)
      .map(([id, r]) => ({ zone: id, ...r.damage, status: damageStatus(r.damage), photo: r.photos?.[r.photos.length - 1] || null }))
      .sort((a, b) => (SEVERITY_ORDER[a.severity] ?? 9) - (SEVERITY_ORDER[b.severity] ?? 9));
    const photoCount = entries.reduce((n, [, r]) => n + (r.photos?.length || 0), 0);
    const statusTally = damages.reduce((acc, d) => {
      acc[d.status] = (acc[d.status] || 0) + 1;
      return acc;
    }, {});
    return { capturedCount: captured.length, capturedZones: captured.map(([id]) => id), damages, photoCount, statusTally };
  }, [records]);

  // 15 inspectable zones in total (11 exterior + 4 interior). Kept as a constant
  // rather than imported so the report stays decoupled from the page's geometry.
  const TOTAL_ZONES = 15;
  const pct = Math.round((summary.capturedCount / TOTAL_ZONES) * 100);
  const hasData = summary.capturedCount > 0 || summary.damages.length > 0 || !!fuelAudit;

  const sevTally = summary.damages.reduce((acc, d) => {
    acc[d.severity] = (acc[d.severity] || 0) + 1;
    return acc;
  }, {});

  async function exportReport() {
    setExporting(true);
    try {
      // Inline every flagged-zone photo as a data URL so the report is portable.
      const damageRows = await Promise.all(
        summary.damages.map(async (d) => {
          const img = d.photo?.url ? await toDataUrl(d.photo.url) : null;
          return { ...d, img };
        })
      );
      const html = buildReportHtml({
        damageRows,
        summary,
        labelFor,
        phaseLabel,
        session,
        inspectorName,
        pct,
        total: TOTAL_ZONES,
        fuelAudit,
      });
      const win = window.open('', '_blank');
      if (!win) return; // popup blocked — silently no-op, on-screen report still stands
      win.document.write(html);
      win.document.close();
    } finally {
      setExporting(false);
    }
  }

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-sm font-semibold text-slate-900">Condition Report</h2>
          <p className="text-xs text-slate-500">
            Live summary of this {phaseLabel.toLowerCase()} inspection — export a standalone, photo-embedded report.
          </p>
        </div>
        <button
          onClick={exportReport}
          disabled={!hasData || exporting}
          className="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-40"
        >
          <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
            <path d="M6 9V4h12v5M6 18H4a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-2M6 14h12v6H6z" />
          </svg>
          {exporting ? 'Building…' : 'Export / Save as PDF'}
        </button>
      </div>

      {!hasData ? (
        <div className="flex h-28 items-center justify-center rounded-xl border border-dashed border-slate-200 text-sm text-slate-400">
          Capture photos or flag damage above — the report builds itself as you go
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-5 sm:grid-cols-[auto,1fr]">
          {/* completeness ring */}
          <div className="flex items-center gap-4 sm:flex-col sm:items-center sm:justify-center">
            <ProgressRing pct={pct} />
            <div className="text-center">
              <p className="text-2xl font-bold text-slate-900">
                {summary.capturedCount}
                <span className="text-base font-medium text-slate-400">/{TOTAL_ZONES}</span>
              </p>
              <p className="text-xs text-slate-500">zones captured</p>
            </div>
          </div>

          {/* tallies + damage breakdown */}
          <div className="space-y-4">
            <div className="flex flex-wrap gap-2">
              <Stat tone="bg-emerald-50 text-emerald-700 ring-emerald-200" value={summary.photoCount} label="photos" />
              <Stat
                tone={summary.damages.length ? 'bg-rose-50 text-rose-700 ring-rose-200' : 'bg-slate-50 text-slate-500 ring-slate-200'}
                value={summary.damages.length}
                label="damage flags"
              />
              {['high', 'medium', 'low'].map((s) =>
                sevTally[s] ? (
                  <Stat
                    key={s}
                    tone={`${severityMeta(s).tone} ring-1`}
                    value={sevTally[s]}
                    label={severityMeta(s).label.toLowerCase()}
                  />
                ) : null
              )}
            </div>

            {summary.damages.length === 0 ? (
              <div className="flex items-center gap-2 rounded-xl bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 ring-1 ring-emerald-100">
                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M20 6 9 17l-5-5" />
                </svg>
                No damage flagged — vehicle reads clean for this phase.
              </div>
            ) : (
              <ul className="divide-y divide-slate-100 overflow-hidden rounded-xl ring-1 ring-slate-200">
                {summary.damages.map((d, i) => {
                  const meta = severityMeta(d.severity);
                  const sMeta = damageStatusMeta(d.status);
                  return (
                    <li key={i} className="flex items-start gap-3 bg-white px-4 py-3">
                      {d.photo?.url && (
                        <img src={d.photo.url} alt={labelFor(d.zone)} className="h-12 w-12 shrink-0 rounded-lg object-cover ring-1 ring-slate-200" />
                      )}
                      <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="text-sm font-semibold text-slate-900">{labelFor(d.zone)}</span>
                          <span className={`rounded-full px-2 py-0.5 text-[10px] font-bold text-white ${meta.toneActive}`}>
                            {meta.label}
                          </span>
                          <span className={`rounded-full px-2 py-0.5 text-[10px] font-bold text-white ${sMeta.toneActive}`}>
                            {sMeta.label}
                          </span>
                        </div>
                        <p className="text-xs text-slate-500">
                          {damageTypeLabel(d.type)} · {meta.sub}
                          {d.status === 'charged' && d.invoiceId && (
                            <span className="font-medium text-rose-600"> · {d.invoiceId}</span>
                          )}
                        </p>
                        {d.note && <p className="mt-0.5 text-xs italic text-slate-600">“{d.note}”</p>}
                      </div>
                    </li>
                  );
                })}
              </ul>
            )}

            {fuelAudit && <FuelBlock audit={fuelAudit} />}
          </div>
        </div>
      )}
    </div>
  );
}

// On-screen fuel audit row: delivery → return, status, and (when financials are
// enabled) the computed Fuel Charge. The shortage %/litres always show.
function FuelBlock({ audit }) {
  const meta = fuelStatusMeta(audit.status);
  const short = audit.status === 'shortage';
  return (
    <div className={`rounded-xl px-4 py-3 ring-1 ${short ? 'bg-rose-50 ring-rose-100' : 'bg-emerald-50 ring-emerald-100'}`}>
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2 text-sm font-semibold text-slate-800">
          <svg className="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
            <path d="M4 20V6a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v14M3 20h12M14 9h2.5a2 2 0 0 1 2 2v5a1.5 1.5 0 0 0 3 0V8l-3-3M7 9h4" />
          </svg>
          Fuel audit
        </div>
        <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ${meta.ring}`}>
          <span className={`h-1.5 w-1.5 rounded-full ${meta.dot}`} />
          {meta.label}
        </span>
      </div>
      <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-600">
        <span>
          Delivery <b className="text-slate-800">{Math.round(audit.delivered)}%</b> ({fuelFraction(audit.delivered)})
        </span>
        <span className="text-slate-400">→</span>
        <span>
          Return <b className="text-slate-800">{Math.round(audit.returned)}%</b> ({fuelFraction(audit.returned)})
        </span>
        {short ? (
          <span className="font-semibold text-rose-600">
            Shortage {audit.shortagePct}% · {audit.litresShort} L
            {SHOW_FINANCIALS && <> · {money(audit.charge)}</>}
          </span>
        ) : (
          <span className="font-medium text-emerald-700">{audit.surplus ? 'Returned fuller — no charge' : 'No fuel shortage'}</span>
        )}
      </div>
    </div>
  );
}

function ProgressRing({ pct }) {
  const r = 34;
  const c = 2 * Math.PI * r;
  const off = c - (pct / 100) * c;
  const tone = pct >= 100 ? 'text-emerald-500' : pct >= 50 ? 'text-indigo-500' : 'text-amber-500';
  return (
    <div className="relative h-24 w-24">
      <svg viewBox="0 0 80 80" className="h-24 w-24 -rotate-90">
        <circle cx="40" cy="40" r={r} className="fill-none stroke-slate-100" strokeWidth="8" />
        <circle
          cx="40"
          cy="40"
          r={r}
          className={`fill-none ${tone} transition-all duration-500`}
          strokeWidth="8"
          strokeLinecap="round"
          strokeDasharray={c}
          strokeDashoffset={off}
        />
      </svg>
      <span className="absolute inset-0 flex items-center justify-center text-lg font-bold text-slate-800">{pct}%</span>
    </div>
  );
}

function Stat({ tone, value, label }) {
  return (
    <span className={`inline-flex items-baseline gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ring-1 ${tone}`}>
      <span className="text-sm">{value}</span>
      {label}
    </span>
  );
}

// Builds a fully standalone HTML document (inline CSS, embedded photos) the user
// can print or Save-as-PDF straight from the new tab. Deliberately framework-free.
function buildReportHtml({ damageRows, summary, labelFor, phaseLabel, session, inspectorName, pct, total, fuelAudit }) {
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const sevColor = { high: '#e11d48', medium: '#f97316', low: '#f59e0b' };
  const statusColor = { existing: '#64748b', new: '#f59e0b', charged: '#e11d48' };

  const damageHtml = damageRows.length
    ? damageRows
        .map(
          (d) => `
        <div class="dmg">
          ${d.img ? `<img src="${d.img}" alt="${esc(labelFor(d.zone))}" />` : '<div class="noimg">no photo</div>'}
          <div class="dmg-body">
            <div class="dmg-head">
              <strong>${esc(labelFor(d.zone))}</strong>
              <span class="badge" style="background:${sevColor[d.severity] || '#64748b'}">${esc(severityMeta(d.severity).label)}</span>
              <span class="badge" style="background:${statusColor[d.status] || '#64748b'}">${esc(damageStatusMeta(d.status).label)}</span>
            </div>
            <div class="dmg-meta">${esc(damageTypeLabel(d.type))} · ${esc(severityMeta(d.severity).sub)}${
              d.status === 'charged' && d.invoiceId ? ` · <strong style="color:#e11d48">${esc(d.invoiceId)}</strong>` : ''
            }</div>
            ${d.note ? `<div class="dmg-note">“${esc(d.note)}”</div>` : ''}
          </div>
        </div>`
        )
        .join('')
    : '<div class="clean">✓ No damage flagged — vehicle reads clean for this phase.</div>';

  // Fuel audit section — only rendered once both readings exist.
  const newCount = summary.statusTally?.new || 0;
  const fuelShort = fuelAudit && fuelAudit.status === 'shortage';
  const alerts = [];
  if (newCount) alerts.push(`${newCount} new damage${newCount > 1 ? 's' : ''} (needs assessment)`);
  if (fuelShort) {
    alerts.push(
      `Fuel shortage ${fuelAudit.shortagePct}% (${fuelAudit.litresShort} L${SHOW_FINANCIALS ? ` · ${money(fuelAudit.charge)}` : ''})`
    );
  }
  const alertHtml = alerts.length
    ? `<div class="alert"><strong>⚠ Charges to settle at check-in</strong><ul>${alerts
        .map((a) => `<li>${esc(a)}</li>`)
        .join('')}</ul></div>`
    : '';

  const fuelHtml = fuelAudit
    ? `<div class="sec">
        <h2>Fuel audit</h2>
        <div class="fuel ${fuelShort ? 'short' : 'ok'}">
          <div class="fuel-row">
            <span>Delivery <strong>${Math.round(fuelAudit.delivered)}%</strong></span>
            <span class="arrow">→</span>
            <span>Return <strong>${Math.round(fuelAudit.returned)}%</strong></span>
          </div>
          <div class="fuel-verdict">${
            fuelShort
              ? `Shortage ${fuelAudit.shortagePct}% · ${fuelAudit.litresShort} L${SHOW_FINANCIALS ? ` · <strong>${money(fuelAudit.charge)}</strong>` : ''}`
              : fuelAudit.surplus
              ? 'Returned fuller — no charge'
              : 'Balanced — no shortage'
          }</div>
        </div>
      </div>`
    : '';

  return `<!doctype html><html><head><meta charset="utf-8" />
  <title>Inspection Report — ${esc(session.contract_no)}</title>
  <style>
    *{box-sizing:border-box} body{font:14px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;color:#0f172a;margin:0;padding:32px;background:#f8fafc}
    .sheet{max-width:760px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden}
    .hd{padding:24px 28px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:flex-start}
    .hd h1{font-size:20px;margin:0 0 4px} .hd .sub{color:#64748b;font-size:13px}
    .phase{background:#eef2ff;color:#4338ca;border-radius:999px;padding:6px 14px;font-weight:600;font-size:12px}
    .grid{padding:20px 28px;display:flex;gap:28px;border-bottom:1px solid #f1f5f9}
    .meta div{margin-bottom:8px} .meta .k{color:#94a3b8;font-size:11px;text-transform:uppercase;letter-spacing:.04em}
    .meta .v{font-weight:600}
    .stats{margin-left:auto;text-align:right} .stats .big{font-size:32px;font-weight:800} .stats .lbl{color:#64748b;font-size:12px}
    .sec{padding:22px 28px} .sec h2{font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin:0 0 14px}
    .dmg{display:flex;gap:14px;padding:12px 0;border-bottom:1px solid #f1f5f9} .dmg:last-child{border-bottom:0}
    .dmg img{width:84px;height:84px;object-fit:cover;border-radius:10px;border:1px solid #e2e8f0}
    .noimg{width:84px;height:84px;border:1px dashed #cbd5e1;border-radius:10px;color:#94a3b8;font-size:11px;display:flex;align-items:center;justify-content:center}
    .dmg-head{display:flex;align-items:center;gap:10px} .badge{color:#fff;border-radius:999px;padding:2px 10px;font-size:11px;font-weight:700}
    .dmg-meta{color:#64748b;font-size:13px;margin-top:2px} .dmg-note{font-style:italic;color:#475569;margin-top:4px}
    .clean{background:#ecfdf5;color:#047857;border-radius:10px;padding:14px;font-weight:600}
    .alert{margin:0 28px 4px;background:#fff1f2;border:1px solid #fecdd3;border-radius:12px;padding:14px 18px;color:#9f1239}
    .alert strong{display:block;margin-bottom:6px;font-size:13px;text-transform:uppercase;letter-spacing:.04em}
    .alert ul{margin:0;padding-left:18px} .alert li{margin:2px 0;font-weight:600}
    .fuel{border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px} .fuel.short{background:#fff1f2;border-color:#fecdd3}
    .fuel.ok{background:#ecfdf5;border-color:#a7f3d0}
    .fuel-row{display:flex;align-items:center;gap:14px;font-size:15px} .fuel-row .arrow{color:#94a3b8}
    .fuel-verdict{margin-top:8px;font-weight:600;color:#0f172a}
    .ft{padding:16px 28px;color:#94a3b8;font-size:11px;border-top:1px solid #f1f5f9}
    @media print{body{padding:0;background:#fff}.sheet{border:0}}
  </style></head>
  <body onload="window.focus()">
    <div class="sheet">
      <div class="hd">
        <div><h1>Vehicle Condition Report</h1><div class="sub">${esc(phaseLabel)} inspection</div></div>
        <span class="phase">${esc(phaseLabel)}</span>
      </div>
      <div class="grid">
        <div class="meta">
          <div><div class="k">Contract</div><div class="v">${esc(session.contract_no)}</div></div>
          <div><div class="k">Vehicle</div><div class="v">${esc(session.vehicle)}</div></div>
          <div><div class="k">Inspector</div><div class="v">${esc(inspectorName)}</div></div>
        </div>
        <div class="stats">
          <div class="big">${pct}%</div><div class="lbl">${summary.capturedCount}/${total} zones · ${summary.photoCount} photos</div>
          <div class="lbl" style="margin-top:6px;color:${summary.damages.length ? '#e11d48' : '#047857'}">
            ${summary.damages.length ? summary.damages.length + ' damage flag(s)' : 'clean'}
          </div>
        </div>
      </div>
      ${alertHtml}
      <div class="sec">
        <h2>Damage findings</h2>
        ${damageHtml}
      </div>
      ${fuelHtml}
      <div class="ft">Generated by FleetView · prototype condition report · embedded photos are device-compressed</div>
    </div>
  </body></html>`;
}
