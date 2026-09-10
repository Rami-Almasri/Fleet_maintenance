// THE SAVED VEHICLE REPORT — the page at /reports/vehicle/{id}, rebuilt as a standalone .html file.
//
// IT IS DOWNLOADED, NOT OPENED. What people do with this report is forward it, and a browser tab is
// not something you can attach to a mail. The file is self-contained by construction — inline CSS,
// marks drawn as divs, no request of any kind — so it opens on a machine that has never heard of this
// system, and its own toolbar still prints it to PDF.
//
// WHY IT IS NOT window.print() ON THE PAGE. The report page is a dark cockpit surface built for a
// screen: panels, chips, a donut, filter buttons, a fixed backdrop. Printing it asked the browser to
// re-flow that on paper, and what came out depended on whether the reader had "background graphics"
// ticked — a black page, a white page with invisible chips, or a stack of half-cut panels. None of
// them is the document a manager forwards.
//
// So Print builds its own HTML instead: one white sheet, print-safe colour, tables that break between
// rows, and the marks redrawn as bars rather than as an SVG donut that a black-and-white printer turns
// into a grey ring. Same numbers, same order, same sections as the page — this is a re-render of the
// payload the page is already showing, not a second query, so the printed report cannot disagree with
// the screen it was printed from.
//
// THE PRINTED REPORT SAYS WHAT IT IS NARROWED TO. Period and system live in the URL, so a report sent
// to somebody is a document about a stated window; the header repeats both in words, because a
// filtered page that prints as if it were the whole car is how a reader concludes a car is clean.
//
// This module runs outside React, so it cannot call useI18n(). The resolvers are THREADED IN by the
// caller — the same t/tf/tp the page itself renders with, so the print carries the reader's language.

import { SHOW_FINANCIALS } from '../config/features';
import { num, fmtDate } from './format';

/** The report family's fixed categorical palette, in its fixed order — see vehicleReportBlocks.js. */
const MIX_COLORS = ['#ef4444', '#f59e0b', '#8b5cf6', '#38bdf8', '#22c55e', '#f97316', '#ec4899', '#14b8a6'];

/** One accent for a count. The ranked bars vary in length only; a repeat is marked in red and in words. */
const COUNT_COLOR = '#2563eb';
const REPEAT_COLOR = '#dc2626';

const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const dash = (s) => (s === null || s === undefined || s === '' ? '—' : esc(s));
const slug = (s) => String(s || 'vehicle').replace(/[^a-z0-9]+/gi, '_').replace(/^_+|_+$/g, '');
const pct = (part, whole) => (whole ? (part / whole) * 100 : 0);

/** The document's own name: plate, what it is, and the day it was taken. ASCII, so it is a filename. */
export function vehicleOverviewReportName({ data, t }) {
  const vehicle = data?.vehicle || {};
  const today = new Date().toISOString().slice(0, 10);
  return `${slug(vehicle.plate || vehicle.label || 'vehicle')}_${slug(t('reportVehicle.eyebrow'))}_${today}.html`;
}

/**
 * SAVE THE REPORT AS A FILE rather than as a new tab.
 *
 * A new tab is a thing you have to keep open. The people who forward this report wanted the document
 * itself — one .html file they can attach to a mail or drop in a folder — so the button hands the
 * same standalone sheet to the browser's downloader. It is self-contained by construction (inline
 * CSS, marks drawn as divs, no network of any kind), so the saved file opens years later on a
 * machine that has never heard of this system, and its own toolbar still prints it to PDF.
 */
export function downloadVehicleOverviewReport(ctx) {
  try {
    const blob = new Blob([buildHtml(ctx)], { type: 'text/html;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = vehicleOverviewReportName(ctx);
    document.body.appendChild(a);
    a.click();
    a.remove();
    // Revoked on the next tick: revoking in the same one raced the click in Safari and saved nothing.
    setTimeout(() => URL.revokeObjectURL(url), 0);
    return true;
  } catch {
    return false; // the caller says so on screen — a dead button is the one failure with no symptom
  }
}

/** Severity/tone → the printed pill class. Same three words the screen uses, never a bare colour. */
const SEVERITY_TONE = {
  critical: 'hot', high: 'hot', moderate: 'warm', routine: 'cool', unrated: 'cool',
};

function buildHtml({ data, provenance, view = {}, t, tf, tp, lang = 'en' }) {
  const rtl = lang === 'ar';
  /*
   * WHAT THE READER HAS NARROWED TO ON SCREEN, carried into the document.
   *
   * The period and the system are in the URL, so they are already applied to the payload. The fault
   * drill-down and "only what came back" are not — they are client-side on the page — and a sheet
   * printed while one of them was on used to come out holding every fault. That is the whole
   * complaint: what you see and what you get were two different documents.
   */
  const openFault = view.fault || null;
  const openRepeatsOnly = Boolean(view.repeatsOnly);
  const vehicle = data?.vehicle || {};
  const summary = data?.summary || {};
  const systems = data?.systems || [];
  const problems = data?.problems || [];
  const workshopOnly = data?.workshop_only || [];
  const months = data?.months || [];
  const garages = data?.garages || [];
  const contracts = data?.contracts || [];
  const brief = data?.brief || [];
  const period = data?.period || null;
  const scopedTo = data?.system
    ? tf(`reportSystem.systems.${data.system.key}`, data.system.label)
    : null;

  const today = new Date().toISOString().slice(0, 10);
  // The document's title IS its filename, minus the extension — the tab and the saved file agree.
  const title = vehicleOverviewReportName({ data, t }).replace(/\.html$/, '');

  // Worst first, exactly as the page ranks it — the printed order IS the report's argument.
  const ranked = [...problems].sort(
    (a, b) => b.occurrences - a.occurrences || String(b.last_seen || '').localeCompare(String(a.last_seen || '')),
  );

  // ── the period, in words ──────────────────────────────────────────────────
  const day = (d) => (d ? fmtDate(d) : '—');
  const periodLine = period
    ? ({
        all_history: () => t('reportSystem.period.allHistory'),
        between: () => t('reportSystem.period.between', { from: day(period.from), to: day(period.to) }),
        single_day: () => t('reportSystem.period.singleDay', { date: day(period.from) }),
        since: () => t('reportSystem.period.since', { from: day(period.from) }),
        until: () => t('reportSystem.period.until', { to: day(period.to) }),
      }[period.shape] || (() => t('reportSystem.period.allHistory')))()
    : t('reportSystem.period.allHistory');
  const coveredLine = period?.active && period.covered_from
    ? t('reportSystem.period.covered', { from: day(period.covered_from), to: day(period.covered_to) })
    : null;

  // ── small builders ────────────────────────────────────────────────────────
  const kpi = (value, label, note, tone = '') => `<div class="kpi ${tone}">
      <div class="n">${esc(value ?? '—')}</div>
      <div class="l">${esc(label)}</div>
      <div class="note">${esc(note)}</div>
    </div>`;
  const pill = (text, tone = 'cool') => `<span class="pill ${tone}">${esc(text)}</span>`;
  const bar = (width, color) => `<span class="track"><span class="fill" style="width:${Math.max(2, width)}%;background:${color}"></span></span>`;

  /**
   * Everything about a problem a reader might type into the search box: its name, the systems it was
   * filed under, the garages that saw it, and — the reason this exists — every line the fitter wrote.
   * Searching only fault names would miss "airbag", which appears nowhere but in a note.
   */
  const searchText = (p) => [
    p.fault,
    (p.system_labels || [p.system_label]).filter(Boolean).join(' '),
    (p.garages || []).join(' '),
    (p.contracts || []).map((c) => c.no || c.id).join(' '),
    (p.events || []).flatMap((o) => [...(o.note_lines || []), o.action_text || '']).join(' '),
  ].filter(Boolean).join(' ').toLowerCase();

  /** The filter handles the document's script uses. Kept on both the ranked row and the detail block
      so clicking one finds the other without either knowing the other's markup. */
  const filterAttrs = (p) => `data-key="${esc(p.key)}" data-fault="${esc(p.fault)}" `
    + `data-repeat="${p.repeated ? '1' : '0'}" data-text="${esc(searchText(p))}"`;

  // ── B. the ranked problem list ────────────────────────────────────────────
  // TWELVE, the same as the page. It used to print fifteen, so a car with fourteen problems showed
  // twelve on screen and fourteen on paper — two documents claiming to be one report.
  const maxOccurrences = Math.max(1, ...ranked.map((p) => p.occurrences));
  const rankedHtml = ranked.length
    ? `<ol class="ranked">${ranked.slice(0, 12).map((p, i) => `<li ${filterAttrs(p)} data-rank role="button" tabindex="0"
        title="${esc(t('reportVehicle.ranked.hint'))}">
        <span class="no">${i + 1}</span>
        <span class="body">
          <span class="head">
            <span class="fault">${esc(p.fault)}</span>
            <span class="times">${esc(tp('reportVehicle.ranked.times', p.occurrences))}</span>
          </span>
          ${bar(pct(p.occurrences, maxOccurrences), p.repeated ? REPEAT_COLOR : COUNT_COLOR)}
          <span class="meta">${esc([
            (p.system_labels || [p.system_label]).filter(Boolean).map((l) => l).join(' · '),
            p.garages?.length ? p.garages.join(', ') : null,
          ].filter(Boolean).join(' · '))}</span>
        </span>
        <span class="tags">
          ${p.repeated ? pill(t('reportVehicle.ranked.cameBack'), 'hot') : ''}
          ${p.worst_severity ? pill(t(`reportSystem.severity.${p.worst_severity}`), SEVERITY_TONE[p.worst_severity] || 'cool') : ''}
          ${p.contracts?.length ? pill(tp('reportVehicle.ranked.contracts', p.contracts.length), 'info') : ''}
        </span>
      </li>`).join('')}</ol>`
    : `<div class="muted">${esc(t('reportVehicle.empty'))}</div>`;

  // ── C. the shape: systems, and when ───────────────────────────────────────
  // The donut is redrawn as ranked bars. A ring survives a colour screen and nothing else: on a mono
  // printer eight hues collapse to four greys and the legend stops labelling anything.
  const systemTotal = systems.reduce((n, s) => n + s.visits, 0);
  // SEVEN PLUS "OTHER", the same slicing the donut uses. The palette has eight fixed hues and reusing
  // one would put two systems in the same colour; the eighth system folding into Other is the page's
  // answer to that, and the print has to give the same one or the two legends disagree.
  const mixRows = [
    ...systems.slice(0, 7).map((s, i) => ({
      label: tf(`reportSystem.systems.${s.key}`, s.label), visits: s.visits, color: MIX_COLORS[i],
    })),
    ...(systems.length > 7
      ? [{
          label: t('reportVehicle.mix.other'),
          visits: systems.slice(7).reduce((n, s) => n + s.visits, 0),
          color: MIX_COLORS[7],
        }]
      : []),
  ];
  const mixHtml = systemTotal
    ? `<div class="mix-total">${esc(tp('reportVehicle.mix.visits', systemTotal))}</div>
      <div class="ranked-bars">${mixRows.map((s) => {
      const share = pct(s.visits, systemTotal);
      return `<div class="row">
          <span class="lbl">${esc(s.label)}</span>
          ${bar(share, s.color)}
          <span class="val">${esc(num(s.visits))}</span>
          <span class="pctv">${share.toFixed(share >= 10 ? 0 : 1)}%</span>
        </div>`;
    }).join('')}</div>`
    : `<div class="muted">${esc(t('reportVehicle.empty'))}</div>`;

  const monthPeak = Math.max(1, ...months.map((m) => m.visits));
  const peakMonth = months.reduce((best, m) => (m.visits > (best?.visits ?? -1) ? m : best), null);
  const monthsHtml = months.length
    ? `<div class="months" style="--cols:${months.length}">${months.map((m) => {
      const h = m.visits ? Math.max(3, Math.round((m.visits / monthPeak) * 46)) : 0;
      return `<div class="m" title="${esc(`${m.month} — ${tp('reportVehicle.months.visits', m.visits)} · ${tp('reportVehicle.months.faults', m.faults)}`)}">
          <span class="col"><span class="colfill" style="height:${h}px"></span></span>
          <!-- Direct label on the busiest month only — a number on every column is noise, and the
               foot line underneath names the span and the peak in words. -->
          <span class="mv">${peakMonth && m.month === peakMonth.month && m.visits ? esc(num(m.visits)) : ''}</span>
          <span class="ml">${esc(m.month.endsWith('-01') ? m.month.slice(0, 4) : '')}</span>
        </div>`;
    }).join('')}</div>
      <div class="hint months-foot">${esc(t('reportVehicle.months.foot', {
        from: months[0].month,
        to: months[months.length - 1].month,
        peak: peakMonth?.month || '—',
      }))}</div>`
    : `<div class="muted">${esc(t('reportVehicle.empty'))}</div>`;

  // ── D. every occurrence, with the workshop's own words ────────────────────
  const occurrence = (o) => `<li class="occ">
      <div class="occ-head">
        <span class="occ-date">${esc(day(o.date) || t('reportSystem.notRecorded'))}</span>
        <span class="occ-garage">${esc((o.garages || []).join(', ') || o.garage || t('reportSystem.notRecorded'))}</span>
        ${o.severity ? pill(t(`reportSystem.severity.${o.severity}`), SEVERITY_TONE[o.severity] || 'cool') : ''}
        ${pill(t(`reportSystem.period.action.${o.action}`), o.action_evidence === 'strong' ? 'ok' : o.action_evidence === 'mentioned' ? 'warm' : 'cool')}
        ${o.gap_days != null ? pill(tp('reportSystem.fault.after', o.gap_days), 'hot') : ''}
        ${o.contract ? pill(t('reportVehicle.occurrence.contract', { no: o.contract.no || `#${o.contract.id}` }), 'info') : ''}
      </div>
      ${o.note_lines?.length
        ? `<blockquote class="record">${o.note_lines.map((l) => `<p>${esc(l)}</p>`).join('')}</blockquote>`
        : `<div class="record empty">${esc(t('reportSystem.fault.noNote'))}</div>`}
      <div class="occ-foot">
        <span>${esc(t('reportSystem.period.actionLabel'))}: ${esc(t(`reportSystem.period.action.${o.action}`))}${
          o.action_text ? ` — “${esc(o.action_text)}”` : ''}</span>
        <span class="hint">${esc(t(`reportSystem.period.evidence.${o.action_evidence}`))}${
          o.row_count > 1 ? ` · ${esc(tp('reportSystem.incident.rowCount', o.row_count))}` : ''}</span>
      </div>
    </li>`;

  /** "Did it come back?" — built only from this fault's own dates, same sentence the page prints. */
  const returnLine = (p) => {
    if (p.returned?.status !== 'yes') {
      return p.followed_by
        ? t('reportSystem.period.returnedNoButOther', { date: day(p.followed_by.date), fault: p.followed_by.fault })
        : t('reportSystem.period.returnedNo');
    }
    const base = p.returned.days == null
      ? t('reportSystem.period.returnedYesUndated', { times: p.returned.times })
      : tp('reportSystem.period.returnedYes', p.returned.days, { times: p.returned.times });
    return p.returned.after_repair ? `${base} ${t('reportSystem.period.returnedAfterRepair')}` : base;
  };

  const detailHtml = ranked.length || workshopOnly.length
    ? `${ranked.map((p, n) => `<section class="problem ${p.repeated ? 'is-repeat' : ''}" ${filterAttrs(p)} data-problem>
        <header>
          <h3><span class="pno">${n + 1}.</span> ${esc(p.fault)}</h3>
          <div class="problem-tags">
            ${pill(tp('reportSystem.period.occurrences', p.occurrences), p.repeated ? 'hot' : 'cool')}
            ${p.repeated ? pill(t('reportSystem.period.repeated'), 'hot') : ''}
            ${p.worst_severity ? pill(t(`reportSystem.severity.${p.worst_severity}`), SEVERITY_TONE[p.worst_severity] || 'cool') : ''}
          </div>
          <div class="problem-line">
            ${p.system_labels?.length ? `<b>${esc(p.system_labels.join(' · '))}</b> — ` : ''}
            ${esc(p.first_seen === p.last_seen
              ? t('reportSystem.period.seenOnce', { date: day(p.first_seen) })
              : t('reportSystem.period.seenBetween', { from: day(p.first_seen), to: day(p.last_seen), days: p.span_days }))}
            ${p.garages?.length ? ` · ${esc(p.garages.join(', '))}` : ''}
            ${p.contracts?.length
              ? ` · ${esc(t('reportVehicle.problem.onContracts', { list: p.contracts.map((c) => c.no || `#${c.id}`).join(', ') }))}`
              : ''}
          </div>
        </header>
        <ol class="occurrences">${(p.events || []).map(occurrence).join('')}</ol>
        <p class="returned"><b>${esc(t('reportSystem.period.returnedLabel'))}:</b> ${esc(returnLine(p))}</p>
      </section>`).join('')}
      ${workshopOnly.length
        ? `<section class="problem workshop-only" data-unnamed>
            <header>
              <h3>${esc(t('reportSystem.period.workshopOnlyTitle'))}</h3>
              <div class="problem-tags">${pill(tp('reportSystem.period.occurrences', workshopOnly.length), 'cool')}</div>
              <div class="problem-line">${esc(t('reportSystem.period.workshopOnlyHint'))}</div>
            </header>
            <ol class="occurrences">${workshopOnly.map(occurrence).join('')}</ol>
          </section>`
        : ''}`
    : `<div class="muted">${esc(period?.active ? t('reportSystem.period.emptyFiltered') : t('reportSystem.ui.noEvents'))}</div>`;

  // ── E. where, and on what paper ───────────────────────────────────────────
  const garageHtml = garages.length
    ? `<table><thead><tr>
        <th>${esc(t('reportVehicle.garages.garage'))}</th>
        <th class="r">${esc(t('reportVehicle.garages.visits'))}</th>
        <th class="r">${esc(t('reportVehicle.garages.faults'))}</th>
        <th>${esc(t('reportVehicle.garages.when'))}</th>
      </tr></thead><tbody>${garages.map((g) => `<tr>
        <td>${dash(g.garage)}</td>
        <td class="r">${esc(num(g.visits))}</td>
        <td class="r">${esc(num(g.faults))}</td>
        <td class="nw">${esc(g.first === g.last ? day(g.last) : `${day(g.first)} → ${day(g.last)}`)}</td>
      </tr>`).join('')}</tbody></table>`
    : `<div class="muted">${esc(t('reportVehicle.empty'))}</div>`;

  const contractHtml = contracts.length
    ? `<table><thead><tr>
        <th>${esc(t('reportVehicle.contracts.no'))}</th>
        <th>${esc(t('reportVehicle.contracts.out'))}</th>
        <th class="r">${esc(t('reportVehicle.contracts.faults'))}</th>
        <th>${esc(t('reportVehicle.contracts.what'))}</th>
      </tr></thead><tbody>${contracts.map((c) => `<tr>
        <td class="mono">${dash(c.no || `#${c.id}`)}</td>
        <td class="nw">${esc(c.in_date ? `${day(c.out_date)} → ${day(c.in_date)}` : day(c.out_date))}</td>
        <td class="r">${esc(num(c.occurrences))}</td>
        <td>${dash((c.faults || []).join(', '))}</td>
      </tr>`).join('')}</tbody></table>`
    : `<div class="muted">${esc(t('reportVehicle.contracts.empty'))}</div>`;

  // ── F. the car itself ─────────────────────────────────────────────────────
  // A money row with financials off renders nothing at all — not an empty row, which reads as a
  // figure the record does not carry rather than one this reader is not shown.
  const briefValue = (r) => {
    switch (r.kind) {
      case 'km': return esc(t('reportSystem.brief.km', { n: num(r.value) }));
      case 'money': return SHOW_FINANCIALS ? esc(t('reportSystem.brief.money', { n: num(r.value) })) : null;
      case 'chip': return pill(t(`reportSystem.brief.value.${r.value}`), 'cool');
      case 'tr': return esc(t(r.value));
      case 'date': return esc(day(r.value));
      default: return esc(r.value);
    }
  };
  const briefHtml = `<div class="dl">${brief.map((r) => {
    const value = briefValue(r);
    if (value === null) return '';
    return `<div><dt>${esc(t(`reportSystem.brief.${r.key}`))}</dt><dd class="${r.kind === 'mono' ? 'mono' : ''}">${value}${
      r.note ? ` <span class="hint">${esc(t(`reportSystem.brief.source.${r.note}`))}</span>` : ''}</dd></div>`;
  }).join('')}</div>`;

  // ── the traceability block, which travels with the document ───────────────
  const originRows = provenance
    ? ['source', 'window', 'grouping', 'split', 'derived', 'omitted']
      .filter((k) => provenance[k])
      .map((k) => `<div><dt>${esc(t(`reportBlocks.origin.${k}`))}</dt><dd>${esc(provenance[k])}</dd></div>`)
      .concat(provenance.counts
        ? [`<div><dt>${esc(t('reportBlocks.origin.counts'))}</dt><dd>${esc(
            Object.entries(provenance.counts).map(([k, v]) => `${k.replace(/_/g, ' ')}: ${v}`).join(' · '),
          )}</dd></div>`]
        : [])
      .join('')
    : '';

  const headline = summary.named_faults != null
    ? tp('reportVehicle.hero.problems', summary.named_faults)
    : '—';
  const headlineNote = summary.repeated_faults
    ? t('reportVehicle.hero.repeats', { repeated: summary.repeated_faults, returns: summary.returns })
    : t('reportVehicle.hero.noRepeats');

  return `<!doctype html><html lang="${rtl ? 'ar' : 'en'}" dir="${rtl ? 'rtl' : 'ltr'}"><head><meta charset="utf-8" />
  <title>${esc(title)}</title>
  <style>
    *{box-sizing:border-box}
    html{-webkit-print-color-adjust:exact;print-color-adjust:exact}
    body{font:13px/1.55 -apple-system,Segoe UI,Roboto,sans-serif;color:#0f172a;margin:0;padding:26px 20px 40px;background:#eef2f7}
    .sheet{max-width:1040px;margin:0 auto;background:#fff;border:1px solid #dde5ee;border-radius:18px;overflow:hidden;box-shadow:0 10px 34px rgba(15,23,42,.07)}

    /* header band */
    .hd{padding:24px 28px;background:linear-gradient(135deg,#0b1220 0%,#16233c 55%,#1e3a5f 100%);color:#fff;display:flex;justify-content:space-between;align-items:flex-start;gap:20px}
    .hd h1{font-size:23px;margin:0 0 6px;letter-spacing:-.01em}
    .hd .sub{color:#a8bbd4;font-size:12px;max-width:620px}
    .hd .chips{margin-top:12px;display:flex;flex-wrap:wrap;gap:6px}
    .chip{background:rgba(255,255,255,.11);border:1px solid rgba(255,255,255,.16);color:#e8f0fb;border-radius:999px;padding:3px 10px;font-size:11px;font-weight:600}
    .chip.hot{background:rgba(239,68,68,.22);border-color:rgba(248,113,113,.5);color:#ffe4e4}
    .chip.warm{background:rgba(245,158,11,.2);border-color:rgba(251,191,36,.45);color:#fff2d6}
    .chip.ok{background:rgba(16,185,129,.2);border-color:rgba(52,211,153,.45);color:#d7fbee}
    .hero{text-align:end;color:#a8bbd4;font-size:11.5px;white-space:nowrap}
    .hero .big{font-size:14px;font-weight:800;color:#fff;text-transform:uppercase;letter-spacing:.02em}

    /* the scope banner — what this document is narrowed to, in words */
    .scope{padding:10px 28px;background:#fff8e6;border-bottom:1px solid #f4e2b8;color:#7c5a10;font-size:12px;font-weight:600}
    .scope.plain{background:#f7fafd;border-bottom-color:#e8eef6;color:#5b6b82;font-weight:500}
    .scope .muted-inline{font-weight:500;color:#94a3b8;margin-inline-start:8px}

    /* kpi strip */
    .kpis{display:grid;grid-template-columns:repeat(6,1fr);border-bottom:1px solid #edf1f7;background:#fafcff}
    .kpi{padding:13px 14px;border-inline-end:1px solid #edf1f7}
    .kpi:last-child{border-inline-end:0}
    .kpi .n{font-size:19px;font-weight:800;letter-spacing:-.02em;font-variant-numeric:tabular-nums}
    .kpi .l{color:#64748b;font-size:10.5px;font-weight:700;margin-top:2px}
    .kpi .note{color:#9aa9bd;font-size:9.5px;margin-top:2px;line-height:1.35}
    .kpi.hot .n{color:#dc2626} .kpi.warm .n{color:#b45309}

    .sec{padding:20px 28px;border-top:1px solid #f1f5f9}
    .sec h2{font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:#7c8ca3;margin:0 0 4px;display:flex;align-items:center;gap:8px}
    .sec h2:after{content:'';flex:1;height:1px;background:#eef2f7}
    .sec .h-hint{color:#9aa9bd;font-size:11px;margin:0 0 13px}
    .cols{display:grid;grid-template-columns:1fr 1fr;gap:26px}
    .avoid{break-inside:avoid}

    /* ranked problems */
    .ranked{list-style:none;margin:0;padding:0;display:grid;gap:9px;counter-reset:none}
    .ranked li{display:grid;grid-template-columns:22px 1fr auto;align-items:center;gap:10px;break-inside:avoid}
    .ranked .no{color:#b3c0d2;font-weight:800;font-size:12px;text-align:end;font-variant-numeric:tabular-nums}
    .ranked .head{display:flex;justify-content:space-between;gap:10px;align-items:baseline}
    .ranked .fault{font-weight:700;font-size:12.5px}
    .ranked .times{color:#64748b;font-size:11px;font-variant-numeric:tabular-nums;white-space:nowrap}
    .ranked .meta{display:block;color:#94a3b8;font-size:10.5px;margin-top:3px}
    .ranked .tags{display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end;max-width:230px}

    .track{display:block;height:8px;border-radius:999px;background:#eef2f7;overflow:hidden;margin-top:5px}
    .fill{display:block;height:100%;border-radius:999px}

    /* system mix, as bars */
    .ranked-bars{display:grid;gap:7px}
    .ranked-bars .row{display:grid;grid-template-columns:minmax(90px,1.2fr) 3fr 34px 40px;align-items:center;gap:9px;font-size:11.5px}
    .ranked-bars .lbl{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .ranked-bars .track{margin-top:0}
    .ranked-bars .val{text-align:end;font-weight:700;font-variant-numeric:tabular-nums}
    .ranked-bars .pctv{text-align:end;color:#94a3b8;font-variant-numeric:tabular-nums}

    /* month strip */
    .months{display:grid;grid-template-columns:repeat(var(--cols),1fr);gap:3px;align-items:end}
    .m{display:flex;flex-direction:column;align-items:center;gap:2px}
    .col{display:flex;align-items:flex-end;justify-content:center;height:48px;width:100%;background:#f4f7fb;border-radius:4px 4px 2px 2px}
    .colfill{display:block;width:100%;border-radius:4px 4px 2px 2px;background:linear-gradient(180deg,#3b82f6,#1d4ed8)}
    .mv{font-size:9.5px;font-weight:700;color:#334155;height:12px}
    .ml{font-size:9px;color:#94a3b8}
    .months-foot{margin-top:8px}

    /* problem detail */
    /* A problem with a dozen occurrences is TALLER THAN A PAGE, so it is allowed to break — but never
       between a heading and its first occurrence, and never through the middle of one. What must stay
       whole is the unit a reader checks: one date, one garage, one note. */
    .problem{border:1px solid #e8eef6;border-radius:12px;padding:14px 16px;margin-bottom:12px;background:#fcfdff}
    .problem header{break-after:avoid;break-inside:avoid}
    .problem.is-repeat{border-inline-start:4px solid #dc2626}
    .problem.workshop-only{border-inline-start:4px solid #cbd5e1;background:#fafbfd}
    .problem h3{margin:0;font-size:14px}
    .problem .pno{color:#b3c0d2;font-weight:800}
    .problem-tags{display:flex;gap:5px;flex-wrap:wrap;margin:6px 0 4px}
    .problem-line{color:#64748b;font-size:11.5px;margin-bottom:10px}
    .occurrences{list-style:none;margin:0;padding:0;display:grid;gap:8px}
    .occ{border:1px solid #eef2f7;border-radius:10px;padding:9px 11px;background:#fff;break-inside:avoid}
    .occ-head{display:flex;flex-wrap:wrap;align-items:center;gap:6px;font-size:11.5px}
    .occ-date{font-weight:700;white-space:nowrap}
    .occ-garage{color:#64748b}
    .record{margin:7px 0 0;padding:7px 11px;border-inline-start:3px solid #e2e8f0;background:#f8fafc;border-radius:0 8px 8px 0;font-size:11.5px;color:#334155}
    .record p{margin:0 0 3px}
    .record p:last-child{margin:0}
    .record.empty{color:#9aa9bd;font-style:italic}
    .occ-foot{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:6px;font-size:10.5px;color:#475569}
    .returned{margin:10px 0 0;font-size:11.5px;color:#334155}

    /* tables and lists */
    table{width:100%;border-collapse:collapse;font-size:11.5px}
    th,td{text-align:start;padding:7px 10px;border-bottom:1px solid #f1f5f9;vertical-align:top}
    th{color:#8fa0b6;font-size:9.5px;text-transform:uppercase;letter-spacing:.05em;background:#f8fbff;border-bottom:1px solid #e6edf6}
    tbody tr:nth-child(even){background:#fcfdff}
    td.r,th.r{text-align:end;font-variant-numeric:tabular-nums}
    td.nw{white-space:nowrap}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
    .muted{color:#9aa9bd;text-align:center;padding:18px;background:#fbfdff;border:1px dashed #e6ecf4;border-radius:10px}
    .hint{color:#9aa9bd;font-size:10.5px;font-weight:500}

    .dl{display:grid;grid-template-columns:1fr 1fr 1fr;gap:9px 18px}
    .dl dt{color:#95a5ba;font-size:9.5px;text-transform:uppercase;letter-spacing:.04em;margin:0}
    .dl dd{margin:0;font-weight:600;font-size:12.5px}
    .origin{display:grid;gap:8px}
    .origin div{display:grid;grid-template-columns:110px 1fr;gap:12px}
    .origin dt{color:#95a5ba;font-size:10px;text-transform:uppercase;letter-spacing:.04em;margin:0}
    .origin dd{margin:0;font-size:11.5px;color:#475569}

    .pill{display:inline-block;border-radius:999px;padding:1px 8px;font-size:10.5px;font-weight:700}
    .pill.hot{background:#fee2e2;color:#b91c1c}
    .pill.warm{background:#fef3c7;color:#92400e}
    .pill.ok{background:#d1fae5;color:#047857}
    .pill.info{background:#dbeafe;color:#1d4ed8}
    .pill.cool{background:#e2e8f0;color:#475569}

    .ft{padding:14px 28px;color:#94a3b8;font-size:10.5px;border-top:1px solid #f1f5f9;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
    .bar{max-width:1040px;margin:0 auto 14px;display:flex;justify-content:flex-end;align-items:center;gap:8px;flex-wrap:wrap}
    .btn{background:#0f172a;color:#fff;border:0;border-radius:999px;padding:9px 20px;font-weight:700;font-size:13px;cursor:pointer}
    .tool-input{flex:1;min-width:180px;max-width:320px;border:1px solid #d7e0ec;background:#fff;border-radius:999px;padding:8px 16px;font:inherit;font-size:13px;color:#0f172a}
    .tool-input:focus{outline:2px solid #2563eb;outline-offset:1px}
    .tool-chip{border:1px solid #d7e0ec;background:#fff;color:#475569;border-radius:999px;padding:8px 15px;font:inherit;font-size:12.5px;font-weight:600;cursor:pointer}
    .tool-chip:hover{border-color:#94a3b8}
    .tool-chip[aria-pressed="true"]{background:#0f172a;border-color:#0f172a;color:#fff}
    .filter-note{margin:0 0 13px;padding:8px 12px;border-radius:9px;background:#eff6ff;border:1px solid #cfe0fb;color:#1d4ed8;font-size:11.5px;font-weight:600}
    .mix-total{font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:9px}
    /* A ranked row is a control here, exactly as it is on the page: click it to read only its history. */
    .ranked li[data-rank]{cursor:pointer;border-radius:9px;padding:3px 5px;margin:-3px -5px}
    .ranked li[data-rank]:hover{background:#f4f8fd}
    .ranked li[data-rank].is-open{background:#eff6ff;box-shadow:inset 0 0 0 1px #cfe0fb}
    [hidden]{display:none !important}

    @media print{
      body{padding:0;background:#fff}
      .sheet{border:0;border-radius:0;box-shadow:none;max-width:none}
      .bar{display:none}
      .sec{padding:14px 20px}
      .cols{gap:16px}
      .kpi .note{font-size:9px}
      /* A table that breaks mid-page repeats its own header on the next one — a column of bare
         numbers under no heading is not a table a reader can check. */
      thead{display:table-header-group}
      tr{break-inside:avoid}
      h2{break-after:avoid}
      /* The drill-down highlight is a screen affordance. On paper the filter note already says what
         is being shown, and a blue-washed row would read as a category the report does not have. */
      .ranked li[data-rank]{background:none !important;box-shadow:none !important;cursor:auto}
    }
  </style></head>
  <body onload="window.focus()">
    <!--
      THE TOOLBAR IS THE PAGE'S FILTER BAR, and it does the same three things: search, "only what came
      back", and drilling into one fault by clicking its ranked row. It narrows the PROBLEM LIST only —
      the counters and the charts above keep describing the whole report, exactly as they do on screen —
      and the note under it says so, so a narrowed view can never be mistaken for a clean car.

      It never prints. Whatever is on screen when you press Print is what comes out.
    -->
    <div class="bar">
      <input id="q" type="search" class="tool-input" placeholder="${esc(t('reportVehicle.search'))}"
             aria-label="${esc(t('reportVehicle.search'))}" />
      <button type="button" id="repeats" class="tool-chip" aria-pressed="false">${esc(t('reportVehicle.filter.repeatsOnly'))}</button>
      <button type="button" id="clear" class="tool-chip" hidden>${esc(t('reportVehicle.filter.clear'))}</button>
      <button class="btn" onclick="window.print()">${esc(t('reportVehicle.printSheet'))}</button>
    </div>
    <div class="sheet">
      <div class="hd">
        <div>
          <h1>${dash(vehicle.label)}</h1>
          <div class="sub">${esc(t('reportVehicle.intro'))}</div>
          <div class="chips">
            <span class="chip ${summary.repeated_faults ? 'hot' : 'ok'}">${esc(headline)}</span>
            <span class="chip">${esc(headlineNote)}</span>
            ${/* The worst severity on record. The page states it beside the headline and the print
                 was dropping it — the single most consequential word about the car. */
              summary.worst_severity
                ? `<span class="chip ${SEVERITY_TONE[summary.worst_severity] === 'hot' ? 'hot' : 'warm'}">${
                    esc(t(`reportSystem.severity.${summary.worst_severity}`))}</span>`
                : ''}
            ${vehicle.plate ? `<span class="chip">${esc(vehicle.plate)}</span>` : ''}
            ${vehicle.vin ? `<span class="chip">VIN ${esc(vehicle.vin)}</span>` : ''}
          </div>
        </div>
        <div class="hero">
          <div class="big">${esc(t('reportVehicle.eyebrow'))}</div>
          <div>${esc(fmtDate(today))}</div>
        </div>
      </div>

      <div class="scope ${scopedTo || period?.active ? '' : 'plain'}">
        ${esc(t('reportSystem.period.label'))}: ${esc(periodLine)}
        ${coveredLine ? `<span class="muted-inline">${esc(coveredLine)}</span>` : ''}
        ${scopedTo ? `<div>${esc(t('reportVehicle.filter.scoped', { system: scopedTo }))}</div>` : ''}
      </div>

      <div class="kpis">
        ${kpi(num(summary.workshop_visits), t('reportVehicle.kpi.visits'), tp('reportVehicle.kpi.visitsNote', summary.source_records || 0))}
        ${kpi(num(summary.named_faults), t('reportVehicle.kpi.problems'), tp('reportVehicle.kpi.problemsNote', summary.fault_occurrences || 0))}
        ${kpi(num(summary.repeated_faults), t('reportVehicle.kpi.repeats'), tp('reportVehicle.kpi.repeatsNote', summary.returns || 0), summary.repeated_faults ? 'hot' : '')}
        ${kpi(num(summary.systems_affected), t('reportVehicle.kpi.systems'), t('reportVehicle.kpi.systemsNote'))}
        ${kpi(num(summary.garages), t('reportVehicle.kpi.garages'), t('reportVehicle.kpi.garagesNote'))}
        ${kpi(num(summary.workshop_only_visits), t('reportVehicle.kpi.unnamed'), t('reportVehicle.kpi.unnamedNote'), summary.workshop_only_visits ? 'warm' : '')}
      </div>

      <div class="sec avoid">
        <h2>${esc(t('reportVehicle.ranked.title'))}</h2>
        <p class="h-hint">${esc(t('reportVehicle.ranked.hint'))}</p>
        ${rankedHtml}
      </div>

      <div class="sec cols avoid">
        <div>
          <h2>${esc(t('reportVehicle.mix.title'))}</h2>
          <p class="h-hint">${esc(scopedTo ? t('reportVehicle.mix.hintWhole') : t('reportVehicle.mix.hint'))}</p>
          ${mixHtml}
        </div>
        <div>
          <h2>${esc(t('reportVehicle.months.title'))}</h2>
          <p class="h-hint">${esc(t('reportVehicle.months.hint'))}</p>
          ${monthsHtml}
        </div>
      </div>

      <div class="sec">
        <h2>${esc(t('reportVehicle.detail.title'))}</h2>
        <p class="h-hint">${esc(t('reportVehicle.detail.hint'))}</p>
        <!-- The filter says out loud what it is hiding, on screen AND on the printed page. -->
        <div class="filter-note" id="filter-note" hidden></div>
        ${detailHtml}
      </div>

      <div class="sec cols avoid">
        <div>
          <h2>${esc(t('reportVehicle.garages.title'))}</h2>
          <p class="h-hint">${esc(t('reportVehicle.garages.hint'))}</p>
          ${garageHtml}
        </div>
        <div>
          <h2>${esc(t('reportVehicle.contracts.title'))}</h2>
          <p class="h-hint">${esc(t('reportVehicle.contracts.hint'))}</p>
          ${contractHtml}
        </div>
      </div>

      <div class="sec avoid">
        <h2>${esc(t('reportVehicle.about.title'))}</h2>
        <p class="h-hint">${esc(t('reportVehicle.about.hint'))}</p>
        ${briefHtml}
      </div>

      ${originRows
        ? `<div class="sec avoid">
            <h2>${esc(t('reportBlocks.dataOrigin'))}</h2>
            <p class="h-hint">${esc(t('reportBlocks.dataOriginHint'))}</p>
            <dl class="origin">${originRows}</dl>
          </div>`
        : ''}

      <div class="ft">
        <span>${esc(t('reportVehicle.footer', { vehicle: vehicle.label || '' }))}</span>
        <span>${esc(fmtDate(today))}</span>
      </div>
    </div>
    <script>${filterScript({
      // The count sentence is a catalog string with params, never assembled here — a baked-in number
      // can never be translated. The script substitutes the two that change as you type.
      showing: t('reportVehicle.filter.showing', {
        shown: '__SHOWN__', total: '__TOTAL__',
        system: scopedTo || t('reportVehicle.filter.all'),
      }),
      total: ranked.length,
      fault: openFault,
      repeats: openRepeatsOnly,
    })}</script>
  </body></html>`;
}

/**
 * THE DOCUMENT'S OWN FILTER BAR — the page's three client-side narrowings, reimplemented in ~50 lines
 * of vanilla JS against `data-` attributes, because a saved report has no React and no server.
 *
 * It narrows the PROBLEM LIST and nothing else. The counters, the charts and the tables keep
 * describing the whole report, which is exactly what the page does with these same three controls —
 * and the note it writes says how many of how many are on screen, so a narrowed view can never be
 * read as a clean car.
 *
 * Config is injected as JSON with `<` escaped, so a fault named after an HTML tag cannot close the
 * script element it is embedded in.
 */
function filterScript(config) {
  const json = JSON.stringify(config).replace(/</g, '\\u003c');

  return `(function () {
  var cfg = ${json};
  var q = document.getElementById('q');
  var repeatsBtn = document.getElementById('repeats');
  var clearBtn = document.getElementById('clear');
  var note = document.getElementById('filter-note');
  var unnamed = document.querySelector('[data-unnamed]');
  var problems = Array.prototype.slice.call(document.querySelectorAll('[data-problem]'));
  var rows = Array.prototype.slice.call(document.querySelectorAll('[data-rank]'));
  var state = { q: '', repeats: !!cfg.repeats, fault: cfg.fault || null };

  function apply() {
    var shown = 0;
    var openName = '';
    problems.forEach(function (el) {
      var ok = true;
      if (state.repeats && el.getAttribute('data-repeat') !== '1') { ok = false; }
      if (state.fault && el.getAttribute('data-key') !== state.fault) { ok = false; }
      if (state.q && el.getAttribute('data-text').indexOf(state.q) === -1) { ok = false; }
      el.hidden = !ok;
      if (ok) { shown++; if (!openName) { openName = el.getAttribute('data-fault') || ''; } }
    });

    var narrowed = !!(state.fault || state.repeats || state.q);
    // A visit that named nothing belongs to no fault's story, so any narrowing drops it.
    if (unnamed) { unnamed.hidden = narrowed; }

    rows.forEach(function (r) {
      var open = state.fault === r.getAttribute('data-key');
      if (open) { r.classList.add('is-open'); } else { r.classList.remove('is-open'); }
      r.setAttribute('aria-pressed', open ? 'true' : 'false');
    });

    repeatsBtn.setAttribute('aria-pressed', state.repeats ? 'true' : 'false');
    clearBtn.hidden = !narrowed;
    note.hidden = !narrowed;
    note.textContent = String(cfg.showing)
      .replace('__SHOWN__', shown)
      .replace('__TOTAL__', cfg.total)
      + (state.fault && openName ? ' \\u00b7 ' + openName : '');
  }

  q.addEventListener('input', function () { state.q = q.value.trim().toLowerCase(); apply(); });
  repeatsBtn.addEventListener('click', function () { state.repeats = !state.repeats; apply(); });
  clearBtn.addEventListener('click', function () {
    state.q = ''; q.value = ''; state.repeats = false; state.fault = null; apply();
  });

  rows.forEach(function (r) {
    function toggle() {
      var k = r.getAttribute('data-key');
      state.fault = state.fault === k ? null : k;
      apply();
    }
    r.addEventListener('click', toggle);
    r.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
    });
  });

  apply();
}());`;
}
