// What Keeps Coming Back — the downloadable report.
//
// The card on the dashboard is a lazy drill-down: you open the one row you are arguing about. This is the
// other half of that question — "give me everything under this date range so I can send it to the garage" —
// and it is a FILE, not a print dialog. One .html the reader can save, forward, reopen offline a month
// later, or print to PDF; entirely self-contained, no stylesheet, no font, no script fetched from anywhere.
//
// It prints all three levels the card can open, expanded:
//   the thing that came back  →  the cars behind it  →  the records behind each car
// because the whole argument the report is meant to end lives at the third level. A bar saying "Tyres &
// Wheels · 22×" persuades nobody; the four dated workshop-log rows under one plate do.
//
// Everything is a recorded fact carried straight from GET /Dashboard/repeat-report: dates, counts, gaps,
// and the ledger each record was read from. Nothing here is scored, predicted or inferred, and no number is
// recomputed in the browser — a report that did its own arithmetic could disagree with the screen it was
// downloaded from, which is the one thing it must never do.
//
// This module runs outside React, so it cannot call useI18n(); the translator is threaded in by the caller,
// exactly as lib/vehicleProfileReport.js does it.

import { num, fmtDate } from './format';

const identityT = (s, vars) => String(s).replace(/\{(\w+)\}/g, (m, k) => (vars && vars[k] != null ? vars[k] : m));

const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const dash = (s) => (s === null || s === undefined || s === '' ? '—' : esc(s));

// Links must survive the download. On screen a href is app-relative ("/vehicles/12"); inside a file sitting
// in someone's Downloads folder that resolves to nothing, so every link is absolutized against the origin
// the report was generated from. A dead link in a document meant as evidence is worse than no link.
const abs = (origin, href) => (href ? `${origin}${String(href).startsWith('/') ? '' : '/'}${href}` : null);

// The three ledgers, each with its own accent. Deliberately the same three colours as the card's tabs —
// a reader who moves between the screen and the file should not have to re-learn which is which.
const SECTIONS = {
  faults:   { title: 'Faults',   accent: '#e11d48', soft: '#fff1f2', ring: '#fecdd3', lead: 'The fault that returned after it was repaired' },
  parts:    { title: 'Parts',    accent: '#d97706', soft: '#fffbeb', ring: '#fde68a', lead: 'The part that went on the same car twice' },
  services: { title: 'Services', accent: '#0284c7', soft: '#f0f9ff', ring: '#bae6fd', lead: 'The service that was done again too soon' },
};

// Same wording the card's footer uses. Repeated here rather than imported because the rules genuinely
// differ per tab and a report that stated one blanket rule over all three would be the lie.
const RULES = {
  REPEAT_FAULT_AFTER_REPAIR: 'A car back for the same fault in a separate workshop visit, counted across the workshop log and this system',
  REPEAT_PART_SAME_CAR: 'The same part fitted to the same car again within {days} days',
  REPEAT_SERVICE_SAME_CAR: 'The same service done on the same car again within {days} days',
};
const ORIGINS = {
  recurring_fault_reviews: 'Recurring-fault reviews',
  part_purchases: 'The parts purchase ledger',
  workshop_log_and_tickets: 'The workshop log and the ticket workflow',
};
const SOURCE_KEY = {
  sheet: 'Workshop log',
  ticket: 'Ticket',
  purchase: 'Purchase',
  review: 'Recurrence review',
  both: 'Log + ticket',
};
const SOURCE_BADGE = { SHEET: 'Sheet', SYSTEM: 'System', BOTH: 'Both' };

// "1 cars" is a typo the eye catches before it reads the number, and this file is the one thing here that
// gets forwarded outside the building. Singular is spelled out per phrase rather than pluralized by rule,
// because Arabic does not pluralize on the same rule English does.
// The open/shut arrow. Inline SVG rather than a glyph: a font character would render differently on every
// machine this file is forwarded to, and an image would have to be fetched from somewhere.
const CHEV = '<svg class="chev" viewBox="0 0 24 24" width="13" height="13" aria-hidden="true"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

const carsPhrase = (n, t) => (Number(n) === 1 ? t('1 car') : t('{n} cars', { n: num(n) }));
const returnsPhrase = (n, t) => (Number(n) === 1 ? t('1 return') : t('{n} returns', { n: num(n) }));

/**
 * The date range, said in words. The header must state which slice was asked for, or a filtered file reads
 * as the whole fleet the moment it leaves the screen it was downloaded from.
 */
export function rangeLabel({ from, to, days }, t = identityT) {
  if (from && to) return t('{from} to {to}', { from: fmtDate(from), to: fmtDate(to) });
  if (from) return t('Since {from}', { from: fmtDate(from) });
  if (to) return t('Up to {to}', { to: fmtDate(to) });
  if (days) return t('The last {n} days', { n: num(days) });
  return t('All time');
}

/**
 * Which ledger the file was cut from, said in words. Only printed when one was chosen — "both" is the
 * absence of a filter, and a chip announcing it would imply a narrowing that never happened.
 */
export function sourceLabel(source, t = identityT) {
  if (source === 'sheet') return t('The workshop log only');
  if (source === 'system') return t('This system only');
  return null;
}

/** A filename that says what it is and when it was taken, so a folder of these stays readable. */
export function reportFileName({ from, to, days, source } = {}) {
  const stamp = new Date().toISOString().slice(0, 10);
  const slice = from || to ? `${from || 'start'}_${to || stamp}` : days ? `last-${days}d` : 'all-time';
  // Two files cut from the same dates but different ledgers are different documents, and a folder that
  // cannot tell them apart is a folder where the wrong one gets forwarded.
  const ledger = source === 'sheet' ? '_workshop-log' : source === 'system' ? '_system' : '';
  return `what-keeps-coming-back_${slice}${ledger}_${stamp}.html`;
}

// ── The pieces ───────────────────────────────────────────────────────────────

/** One record: the visit / buy / recurrence itself, with the gap that preceded it. */
function eventRow(e, origin, t) {
  const when = e.href
    ? `<a class="lk" href="${esc(abs(origin, e.href))}">${dash(e.at)}</a>`
    : `<span class="mono">${dash(e.at)}</span>`;
  const src = e.source && SOURCE_KEY[e.source]
    ? `<span class="tag">${esc(t(SOURCE_KEY[e.source]))}</span>` : '';
  const contract = e.contract_id
    ? `<a class="tag lk" href="${esc(abs(origin, `/contracts/${e.contract_id}`))}">#${esc(e.contract_no || e.contract_id)}</a>` : '';
  const gap = e.gap_days == null
    ? t('first on record')
    : t('{n}d after', { n: num(e.gap_days) });
  // The mark on the records the row above actually counted as a return. Services list EVERY visit — four
  // returns is five visits — so without this the list would not add up to the number it was opened from.
  const counted = e.counted ? `<span class="counted">${esc(t('counted'))}</span>` : '';

  return `<li class="ev${e.counted ? ' is-counted' : ''}">
    <span class="ev-l">${when}${e.detail ? `<span class="ev-d">${esc(e.detail)}</span>` : ''}${src}${contract}</span>
    <span class="ev-r"><span class="gap">${esc(gap)}</span>${counted}</span>
  </li>`;
}

/**
 * One car under one row: its count, how fast it came back, and every record behind it.
 *
 * Open when its row is opened — a reader who clicked "Engine" asked to see the records, not another list
 * of things to click — but collapsible on its own, so a row with 25 cars can be scanned as 25 plates.
 */
function carBlock(c, origin, t) {
  const events = (c.events || []);
  const fastest = c.fastest_days != null
    ? `<span class="pill">${esc(t('fastest {n}d', { n: num(c.fastest_days) }))}</span>` : '';

  return `<details class="car" open>
    <summary class="car-h">
      <span class="car-id">
        ${CHEV}
        <a class="plate lk" href="${esc(abs(origin, `/vehicles/${c.id}`))}">${dash(c.plate)}</a>
        ${c.car ? `<span class="car-m">${esc(c.car)}</span>` : ''}
      </span>
      <span class="car-n">${fastest}<b>${esc(num(c.count))}×</b></span>
    </summary>
    ${events.length
      ? `<ol class="evs">${events.map((e) => eventRow(e, origin, t)).join('')}</ol>`
      : `<p class="empty">${esc(t('No records to show.'))}</p>`}
  </details>`;
}

/**
 * One row of the leaderboard — the NAME the reader came for ("Engine", "Tyres & Wheels"), and everything
 * underneath it.
 *
 * CLOSED by default, and the name itself is the control. Opened, a 20-row report is thousands of lines of
 * records and the shape of the problem drowns in them; closed, the first screen is the ranked list of names
 * with its bars and counts — the same thing the card shows — and the reader opens the one they are arguing
 * about. The header keeps every number while shut (the bar, the car count, the ×), so a collapsed row still
 * states its whole case; only the evidence is folded away.
 *
 * Printing is not clicking, so the script at the foot of the document opens everything before the print
 * dialog and puts it back afterwards. A PDF with the evidence collapsed out of it would be worthless.
 */
function rowBlock(it, max, origin, t) {
  const width = Math.max(4, Math.round((it.value / (max || 1)) * 100));
  const vehicles = it.vehicles || [];
  const hidden = Math.max(0, (it.car_count || it.cars || 0) - vehicles.length);
  const badge = it.source_code && SOURCE_BADGE[it.source_code]
    ? `<span class="pill" title="${esc(t('Sheet history: {s} · System records: {y}', { s: num(it.sheet_cars || 0), y: num(it.system_cars || 0) }))}">${esc(t(SOURCE_BADGE[it.source_code]))}</span>` : '';
  const fastest = it.fastest_days != null
    ? `<span class="pill">${esc(t('fastest {n}d', { n: num(it.fastest_days) }))}</span>` : '';

  return `<details class="row">
    <summary class="row-h">
      <div class="row-t">
        <h3>${CHEV}${esc(it.label)}</h3>
        <span class="row-n">
          ${badge}${fastest}
          <span class="chip">${esc(carsPhrase(it.cars, t))}</span>
          <b>${esc(num(it.value))}×</b>
        </span>
      </div>
      <div class="bar"><i style="width:${width}%"></i></div>
      <p class="row-sub">
        <span>${esc(carsPhrase(it.car_count || it.cars || 0, t))} · ${esc(returnsPhrase(it.value, t))}</span>
        <span class="hint">${esc(t('Click the name to see the records'))}</span>
      </p>
    </summary>
    <div class="cars">${vehicles.map((c) => carBlock(c, origin, t)).join('')}</div>
    ${hidden > 0 ? `<p class="more">${esc(hidden === 1 ? t('and 1 more car') : t('and {n} more cars', { n: num(hidden) }))}</p>` : ''}
  </details>`;
}

/** One ledger: its lead line, its rows, and the Data Origin footer that says what it counted. */
function sectionBlock(key, section, origin, t, ledger) {
  const sec = SECTIONS[key] || SECTIONS.faults;
  const items = section.items || [];
  const max = items.reduce((m, it) => Math.max(m, it.value), 0) || 1;
  const rule = RULES[section.rule] ? t(RULES[section.rule], { days: section.window_days }) : '';

  return `<section class="sec" style="--accent:${sec.accent};--soft:${sec.soft};--ring:${sec.ring}">
    <header class="sec-h">
      <div>
        <h2>${esc(t(sec.title))}</h2>
        <p>${esc(t(sec.lead))}</p>
      </div>
      <span class="sec-n"><b>${esc(num(section.total || 0))}</b><span>${esc(t('returns'))}</span></span>
    </header>
    ${items.length
      ? items.map((it) => rowBlock(it, max, origin, t)).join('')
      : `<p class="empty big">${esc(t('Nothing came back in this period.'))}</p>`}
    <footer class="sec-f">
      ${rule ? `<span>${esc(rule)}</span>` : ''}
      <span>${esc(t('Source: {origin}', { origin: t(ORIGINS[section.origin] || section.origin || '—') }))}</span>
      ${/* Only the faults ledger can be filtered by source, so only it says so — printing the clause under
           parts or services would claim a narrowing that was never applied to them. */
        key === 'faults' && ledger ? `<span class="f-on">${esc(t('Filtered to: {ledger}', { ledger }))}</span>` : ''}
      ${section.truncated ? `<span>${esc(t('Showing the most recent repeats only — there are more.'))}</span>` : ''}
    </footer>
  </section>`;
}

// ── The document ─────────────────────────────────────────────────────────────

/**
 * Build the standalone report.
 *
 * @param {object} payload  GET /Dashboard/repeat-report → data
 * @param {object} opts     { range:{from,to,days}, windowDays, only, t, lang, origin }
 */
export function buildRepeatReportHtml(payload, opts = {}) {
  const t = opts.t || identityT;
  const lang = opts.lang || 'en';
  const rtl = lang === 'ar';
  const origin = opts.origin ?? (typeof window !== 'undefined' ? window.location.origin : '');
  const sections = payload?.sections || {};
  // Print the tabs in the card's order — faults first, because a car that broke again is the worst news
  // in the file — and only the ones this reader was actually served.
  const keys = ['faults', 'parts', 'services'].filter((k) => sections[k] && (opts.only ? opts.only.includes(k) : true));

  const range = rangeLabel(opts.range || {}, t);
  // Highlighted rather than sat among the other chips: a file cut to one ledger is a narrower claim than
  // its title makes, and the reader it is forwarded to did not choose the filter.
  const ledger = sourceLabel(opts.source, t);
  const generated = `${fmtDate(new Date())} ${new Date().toLocaleTimeString()}`;

  // The header tiles: one per ledger, so the first thing the reader sees is the shape of the problem —
  // which of the three is actually bleeding — before any single row argues its case.
  const tiles = keys.map((k) => {
    const s = sections[k];
    const sec = SECTIONS[k];
    const cars = (s.items || []).reduce((m, it) => Math.max(m, it.cars || 0), 0);
    return `<div class="tile" style="--accent:${sec.accent};--soft:${sec.soft};--ring:${sec.ring}">
      <span class="tile-k">${esc(t(sec.title))}</span>
      <b>${esc(num(s.total || 0))}</b>
      <span class="tile-s">${esc(t('{n} things came back', { n: num((s.items || []).length) }))}${cars ? ` · ${esc(t('worst: {phrase}', { phrase: carsPhrase(cars, t) }))}` : ''}</span>
    </div>`;
  }).join('');

  return `<!doctype html>
<html lang="${esc(lang)}"${rtl ? ' dir="rtl"' : ''}>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(t('What Keeps Coming Back'))} — ${esc(range)}</title>
<style>
  /* Self-contained on purpose: this file is expected to be opened offline, from a mail attachment. */
  *,*::before,*::after{box-sizing:border-box}
  html{-webkit-text-size-adjust:100%}
  body{margin:0;background:#f1f5f9;color:#0f172a;
    font:14px/1.55 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,"Noto Sans Arabic",sans-serif}
  .wrap{max-width:1040px;margin:0 auto;padding:28px 20px 64px}
  a{color:inherit}
  .lk{color:#4f46e5;text-decoration:none;border-bottom:1px solid #c7d2fe}
  .lk:hover{border-bottom-color:#4f46e5}
  .mono{font-variant-numeric:tabular-nums;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}

  /* Masthead */
  .top{position:relative;overflow:hidden;border-radius:20px;padding:30px 32px;color:#fff;
    background:linear-gradient(135deg,#0f172a 0%,#1e1b4b 55%,#312e81 100%)}
  .top::after{content:"";position:absolute;inset:auto -80px -140px auto;width:340px;height:340px;border-radius:50%;
    background:radial-gradient(circle,rgba(99,102,241,.45),transparent 70%)}
  .top>*{position:relative;z-index:1}
  .eyebrow{margin:0;font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:#a5b4fc;font-weight:700}
  .top h1{margin:6px 0 4px;font-size:30px;line-height:1.15;letter-spacing:-.02em}
  .top p{margin:0;color:#cbd5e1;font-size:14px}
  .meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:18px}
  .meta span{border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08);
    border-radius:999px;padding:5px 12px;font-size:12px;font-weight:600;color:#e2e8f0}
  .meta .meta-on{border-color:rgba(165,180,252,.6);background:rgba(99,102,241,.35);color:#fff}

  /* Tiles */
  .tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin:16px 0 4px}
  .tile{background:#fff;border:1px solid #e2e8f0;border-top:3px solid var(--accent);border-radius:14px;padding:14px 16px}
  .tile-k{display:block;font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--accent)}
  .tile b{display:block;font-size:30px;line-height:1.1;margin:2px 0 2px;letter-spacing:-.02em}
  .tile-s{font-size:12px;color:#64748b}

  /* Section */
  .sec{margin-top:28px;background:#fff;border:1px solid #e2e8f0;border-radius:18px;overflow:hidden}
  .sec-h{display:flex;align-items:center;justify-content:space-between;gap:16px;
    padding:16px 20px;background:var(--soft);border-bottom:1px solid var(--ring)}
  .sec-h h2{margin:0;font-size:18px;letter-spacing:-.01em;color:var(--accent)}
  .sec-h p{margin:2px 0 0;font-size:12.5px;color:#475569}
  .sec-n{text-align:center;line-height:1.1}
  .sec-n b{display:block;font-size:24px;color:var(--accent)}
  .sec-n span{font-size:10.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8}
  .sec-f{display:flex;flex-wrap:wrap;gap:4px 14px;padding:12px 20px;background:#f8fafc;
    border-top:1px solid #e2e8f0;font-size:11.5px;color:#64748b}
  .sec-f .f-on{font-weight:700;color:#4338ca}

  /* Open/shut. The name is the control at both levels, so both get the pointer and the arrow. */
  summary{cursor:pointer;list-style:none}
  summary::-webkit-details-marker{display:none}
  summary::marker{content:""}
  .chev{flex:0 0 auto;color:#94a3b8;transition:transform .18s ease;margin-inline-end:2px}
  details[open]>summary .chev{transform:rotate(180deg)}
  summary:focus-visible{outline:2px solid #6366f1;outline-offset:-2px;border-radius:8px}

  /* Row */
  .row{padding:18px 20px;border-top:1px solid #f1f5f9}
  .row:first-of-type{border-top:0}
  .row-h{border-radius:10px;margin:-6px -8px 0;padding:6px 8px 2px;transition:background .15s ease}
  .row-h:hover{background:#f8fafc}
  .row-t{display:flex;align-items:baseline;justify-content:space-between;gap:12px}
  .row-h h3{display:flex;align-items:center;gap:6px;margin:0;font-size:16px;font-weight:700;letter-spacing:-.01em}
  .row-n{display:flex;align-items:center;gap:8px;white-space:nowrap}
  .row-n b{font-size:19px;font-variant-numeric:tabular-nums}
  /* The nudge that says the name opens. Shown on hover only, and never on a row already open. */
  .hint{opacity:0;transition:opacity .15s ease;font-weight:500}
  .row-h:hover .hint{opacity:1}
  details[open] .hint{display:none}
  .pill{border:1px solid #e2e8f0;border-radius:6px;padding:1px 6px;font-size:10.5px;font-weight:600;color:#64748b}
  .chip{background:var(--soft);border:1px solid var(--ring);color:var(--accent);
    border-radius:999px;padding:2px 9px;font-size:11px;font-weight:700}
  .bar{height:8px;border-radius:999px;background:#f1f5f9;overflow:hidden;margin:9px 0 6px}
  .bar i{display:block;height:100%;border-radius:999px;background:var(--accent);opacity:.85}
  .row-sub{display:flex;align-items:baseline;justify-content:space-between;gap:12px;
    margin:0;font-size:11.5px;font-weight:600;color:#94a3b8}
  .more{margin:10px 0 0;font-size:11.5px;color:#94a3b8}

  /* Car */
  .cars{display:grid;gap:10px;margin-top:12px}
  .car{border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;break-inside:avoid}
  .car-h{display:flex;align-items:center;justify-content:space-between;gap:12px;
    padding:8px 12px;background:#f8fafc}
  .car[open]>.car-h{border-bottom:1px solid #eef2f7}
  .car-h:hover{background:#f1f5f9}
  .car-id{display:flex;align-items:center;gap:8px;min-width:0}
  .plate{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:14px;font-weight:700}
  .car-m{font-size:11.5px;color:#94a3b8}
  .car-n{display:flex;align-items:center;gap:8px;white-space:nowrap}
  .car-n b{font-size:15px;font-variant-numeric:tabular-nums}

  /* Events */
  .evs{list-style:none;margin:0;padding:0}
  .ev{display:flex;align-items:baseline;justify-content:space-between;gap:12px;
    padding:6px 12px;font-size:12px;border-top:1px solid #f5f7fa}
  .ev:first-child{border-top:0}
  .ev.is-counted{background:#fff7f8}
  .ev-l{display:flex;align-items:baseline;flex-wrap:wrap;gap:7px;min-width:0}
  .ev-l .lk,.ev-l .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-variant-numeric:tabular-nums}
  .ev-d{color:#475569}
  .tag{border:1px solid #e2e8f0;border-radius:5px;padding:0 5px;font-size:10px;color:#94a3b8;background:#fff}
  .ev-r{display:flex;align-items:baseline;gap:8px;white-space:nowrap;font-variant-numeric:tabular-nums}
  .gap{color:#94a3b8;font-size:11.5px}
  .counted{background:#fff1f2;border:1px solid #fecdd3;color:#e11d48;
    border-radius:999px;padding:0 7px;font-size:10px;font-weight:800}

  .empty{margin:0;padding:8px 12px;font-size:12px;color:#94a3b8}
  .empty.big{padding:28px 20px;text-align:center;font-size:14px}

  /* Toolbar — the way out of clicking twenty names one at a time. */
  .tools{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:16px 0 -8px}
  .tools button{cursor:pointer;font:inherit;font-size:12px;font-weight:700;color:#334155;
    background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:6px 12px;transition:.15s ease}
  .tools button:hover{border-color:#c7d2fe;color:#4338ca;background:#eef2ff}
  .tools span{font-size:11.5px;color:#94a3b8}

  .foot{margin-top:26px;text-align:center;font-size:11.5px;color:#94a3b8;line-height:1.7}

  @media print{
    body{background:#fff}
    .wrap{max-width:none;padding:0}
    .top{border-radius:0}
    .tools,.hint,.chev{display:none}
    .sec,.car,.tile{break-inside:auto;box-shadow:none}
    .row,.car{break-inside:avoid}
    .lk{color:#1e293b;border:0}
    @page{margin:14mm}
  }
</style>
</head>
<body>
<div class="wrap">
  <header class="top">
    <p class="eyebrow">${esc(t('Fleet report'))}</p>
    <h1>${esc(t('What Keeps Coming Back'))}</h1>
    <p>${esc(t('Not what happens most — what does not stay fixed'))}</p>
    <div class="meta">
      <span>${esc(range)}</span>
      ${/* Named as the FAULTS filter, not as the file's. Only that ledger has two sources to choose
            between; parts and services each read one and are untouched by this control, so a bare
            "This system only" in the masthead would claim a narrowing two thirds of the file never had. */
        ledger ? `<span class="meta-on">${esc(t('Faults: {ledger}', { ledger: ledger.toLowerCase() }))}</span>` : ''}
      <span>${esc(t('Again within {n} days', { n: num(opts.windowDays || 30) }))}</span>
      <span>${esc(t('Generated {when}', { when: generated }))}</span>
    </div>
  </header>

  ${tiles ? `<div class="tiles">${tiles}</div>` : ''}

  <div class="tools">
    <button type="button" data-all="1">${esc(t('Open everything'))}</button>
    <button type="button" data-all="0">${esc(t('Close everything'))}</button>
    <span>${esc(t('Or click any name to open just that one.'))}</span>
  </div>

  ${keys.length
    ? keys.map((k) => sectionBlock(k, sections[k], origin, t, ledger)).join('')
    : `<p class="empty big">${esc(t('Nothing to report for this period.'))}</p>`}

  <p class="foot">
    ${esc(t('Every line here is a recorded fact — a dated visit, purchase or recurrence — read from the ledgers named under each section. Nothing is predicted or scored.'))}<br>
    ${esc(t('The date on each record links back to the system it was read from.'))}
  </p>
</div>
<script>
// Two jobs, both of them about the fold.
//
// 1. The toolbar: open or close every row at once, for the reader who wants the whole thing rather than
//    one name. Nothing else on this page needs script — the open/shut behaviour is native <details>, so
//    the report still works fully with scripting turned off. Only these buttons stop working.
// 2. PRINTING. A collapsed <details> does not print its contents, so a PDF taken while rows were shut
//    would silently drop the evidence — the one failure this document cannot afford. Everything is
//    opened before the dialog and restored after it, so the reader's place on screen is not lost.
(function () {
  var all = function () { return document.querySelectorAll('details'); };
  document.querySelectorAll('.tools button').forEach(function (b) {
    b.addEventListener('click', function () {
      var open = b.dataset.all === '1';
      // Cars stay open when everything is closed: shutting a row already hides them, and reopening one
      // to find its cars also shut would be two clicks to get back to where the reader started.
      all().forEach(function (d) { d.open = open || d.classList.contains('car'); });
    });
  });

  var wasShut = [];
  window.addEventListener('beforeprint', function () {
    wasShut = [];
    all().forEach(function (d) { if (!d.open) { wasShut.push(d); d.open = true; } });
  });
  window.addEventListener('afterprint', function () {
    wasShut.forEach(function (d) { d.open = false; });
    wasShut = [];
  });
}());
</script>
</body>
</html>`;
}

/**
 * Build the report and hand it to the browser as a download.
 *
 * A download rather than a print dialog: the reader almost always wants to attach this to a message to the
 * garage, and "Save as PDF" is still one keystroke away once the file is open.
 */
export function downloadRepeatReport(payload, opts = {}) {
  const html = buildRepeatReportHtml(payload, opts);
  const url = URL.createObjectURL(new Blob([html], { type: 'text/html;charset=utf-8' }));
  const a = document.createElement('a');
  a.href = url;
  a.download = reportFileName({ ...(opts.range || {}), source: opts.source });
  document.body.appendChild(a);
  a.click();
  a.remove();
  // Revoked on the next tick, not immediately: Safari reads the blob after the click returns.
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
