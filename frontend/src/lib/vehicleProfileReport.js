// Vehicle Report — a one-click, standalone printable dossier for a single vehicle (Save-as-PDF from the
// browser). Same pattern as lib/vehicleReport.js and components/inspection/InspectionReport.js (build an
// HTML string → window.open → browser print). Input is the payload from GET /Vehicle/{id}/profile, so no
// extra network calls. Money fields are gated by SHOW_FINANCIALS like everywhere else.
//
// The report opens with the two questions a manager actually asks about one car — WHAT NEEDS ATTENTION
// (paperwork expiring, service overdue, car sitting in a shop, unresolved repairs) and WHICH FAULT KEEPS
// COMING BACK — before it drops into the reference tables. Everything on the page is a recorded fact
// counted from the profile payload: dates, counts, amounts. Nothing is scored, predicted or inferred.
//
// This module runs outside React, so it cannot call useI18n(). The translator is THREADED IN by the
// caller instead; the fallback below keeps the report readable (English, interpolation intact) when a
// caller has not been updated yet.

import { SHOW_FINANCIALS } from '../config/features';
import { aed, num, fmtDate } from './format';
import { visitFaults, isNonFaultVisit, faultTagSegments } from './faultCategories';

const identityT = (s, vars) => String(s).replace(/\{(\w+)\}/g, (m, k) => (vars && vars[k] != null ? vars[k] : m));

const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const money = (n) => (SHOW_FINANCIALS ? aed(n) : '—');
const dash = (s) => (s === null || s === undefined || s === '' ? '—' : esc(s));
const slug = (s) => String(s || 'vehicle').replace(/[^a-z0-9]+/gi, '_').replace(/^_+|_+$/g, '');
const km = (n) => (n == null || n === '' ? '—' : `${num(n)} km`);

const DAY = 86400000;
const startOfDay = (d) => { const x = new Date(d); x.setHours(0, 0, 0, 0); return x; };
/** Whole days from `a` to `b` (both date-ish); null when either is missing/unparseable. */
const daysBetween = (a, b) => {
  if (!a || !b) return null;
  const x = new Date(a); const y = new Date(b);
  if (isNaN(x) || isNaN(y)) return null;
  return Math.round((startOfDay(y) - startOfDay(x)) / DAY);
};
const daysAgo = (d) => daysBetween(d, new Date());
const daysUntil = (d) => daysBetween(new Date(), d);

export function openVehicleProfileReport(data, t = identityT, lang = 'en') {
  const w = window.open('', '_blank');
  if (!w) return; // pop-up blocked
  w.document.write(buildHtml(data, t, lang));
  w.document.close();
}

// ── Attention board ──────────────────────────────────────────────────────────
// Every entry is a recorded fact with its own date/count behind it. `level` is presentation only:
// 'now'   — already expired / already overdue / already sitting open
// 'soon'  — dated, and the date is inside the next 30 days
// Nothing here is a prediction; a car with no entries prints "Nothing outstanding".
function attentionItems(data, t, repeats) {
  const v = data?.vehicle || {};
  const reg = data?.registration || {};
  const av = data?.availability || {};
  const svc = v.service_status && typeof v.service_status === 'object' ? v.service_status : null;
  const out = [];
  const add = (level, title, detail) => out.push({ level, title, detail });

  // Paperwork — expiry is the single most expensive thing to miss on a rental car.
  const expiries = [
    [t('Registration'), reg.expiry_date, reg.registration_days_left],
    [t('Insurance'), reg.insurance_expiry, reg.insurance_days_left],
  ];
  expiries.forEach(([label, date, left]) => {
    const d = left != null ? Number(left) : daysUntil(date);
    if (date == null || d == null) return;
    if (d < 0) add('now', t('{doc} expired', { doc: label }), t('{n} days ago · {date}', { n: num(Math.abs(d)), date: fmtDate(date) }));
    else if (d <= 30) add('soon', t('{doc} expires in {n} days', { doc: label, n: num(d) }), fmtDate(date));
  });

  if (Number(reg.fines_count || 0) > 0) {
    add('soon', Number(reg.fines_count) === 1 ? t('1 unpaid fine') : t('{n} unpaid fines', { n: num(reg.fines_count) }),
      SHOW_FINANCIALS && reg.fines_amount ? aed(reg.fines_amount) : t('Amount not recorded'));
  }

  // Service — the km-based verdict from the Oil Change baseline (Vehicle::serviceStatus).
  if (svc?.status === 'service_due') {
    add('now', t('Service overdue'), t('{n} km past the {interval} km interval', {
      n: num(svc.overdue_km || 0), interval: num(svc.interval || 0),
    }));
  } else if (svc?.status === 'ok' && svc.remaining != null && svc.remaining <= 1000) {
    add('soon', t('Service due soon'), t('{n} km remaining', { n: num(svc.remaining) }));
  } else if (svc?.status === 'no_data') {
    add('soon', t('No service baseline recorded'), t('Last-service odometer or interval is missing'));
  }
  if (v.service_due_date) {
    const d = daysUntil(v.service_due_date);
    if (d != null && d < 0) add('now', t('Service date passed'), fmtDate(v.service_due_date));
  }

  // Where the car is right now.
  if (av.state === 'maintenance') {
    const d = daysAgo(av.since);
    add('now', t('In the workshop'), [
      av.garage || t('Garage not recorded'),
      d != null ? t('{n} days so far', { n: num(d) }) : null,
      av.due ? t('due {date}', { date: fmtDate(av.due) }) : null,
    ].filter(Boolean).join(' · '));
  }
  if (v.is_deferred_maintenance) {
    add('now', t('Owes the garage a visit'), [
      t('Pulled out early'),
      v.deferred_maintenance_reason || null,
      v.deferred_maintenance_flagged_at ? fmtDate(v.deferred_maintenance_flagged_at) : null,
    ].filter(Boolean).join(' · '));
  }
  if (v.condition_grade === 'red' || v.condition_grade === 'yellow') {
    add('now', t('Condition grade: {grade}', { grade: v.condition_grade_label || v.condition_grade }),
      v.condition_note || t('Not rentable at this grade'));
  } else if (v.condition_grade === 'orange') {
    add('soon', t('Condition grade: {grade}', { grade: v.condition_grade_label || v.condition_grade }),
      v.condition_note || t('Rentable — tell the customer'));
  }

  // The repeat offenders, stated as counts and dates — the answer to "what keeps coming back".
  repeats.slice(0, 3).forEach((r) => {
    add(r.count >= 3 ? 'now' : 'soon', t('{fault} came back {n} times', { fault: r.label, n: num(r.count) }), [
      t('last {date}', { date: fmtDate(r.last) }),
      r.gapDays != null ? t('{n} days after the one before', { n: num(r.gapDays) }) : null,
    ].filter(Boolean).join(' · '));
  });

  // A visit that never recorded a return — either the car is still in, or the record was never closed.
  const openVisits = (data?.maintenance || []).filter((m) => m.date && !m.in_date);
  openVisits.slice(0, 2).forEach((m) => {
    const d = daysAgo(m.date);
    if (d != null && d >= 14) {
      add('soon', t('Workshop visit still open'), [
        fmtDate(m.date), m.garage || t('Garage not recorded'), t('{n} days, no return recorded', { n: num(d) }),
      ].filter(Boolean).join(' · '));
    }
  });

  const order = { now: 0, soon: 1 };
  return out.sort((a, b) => (order[a.level] ?? 9) - (order[b.level] ?? 9));
}

// ── Repeat faults ────────────────────────────────────────────────────────────
// The same fault label recorded on two or more separate visits. Counting is per VISIT (a fault named
// twice inside one visit is one occurrence), matched case-insensitively on the recorded label — this is
// a tally of what the workshop wrote down, not a judgement that the repair failed.
function repeatFaults(visits) {
  const by = {};
  visits.forEach((m) => {
    if (isNonFaultVisit(m)) return;
    const seen = new Set();
    visitFaults(m).forEach((f) => {
      const label = String(f).trim();
      const key = label.toLowerCase();
      if (!label || seen.has(key)) return;
      seen.add(key);
      const e = by[key] || (by[key] = { label, count: 0, dates: [], garages: new Set(), cost: 0 });
      e.count += 1;
      if (m.date) e.dates.push(m.date);
      if (m.garage) e.garages.add(m.garage);
      e.cost += Number(m.total || 0);
    });
  });

  return Object.values(by)
    .filter((e) => e.count >= 2)
    .map((e) => {
      const dates = e.dates.slice().sort();
      const last = dates[dates.length - 1] || null;
      const prev = dates[dates.length - 2] || null;
      return {
        label: e.label,
        count: e.count,
        first: dates[0] || null,
        last,
        gapDays: daysBetween(prev, last),
        garages: [...e.garages],
        cost: Math.round(e.cost * 100) / 100,
      };
    })
    .sort((a, b) => b.count - a.count || String(b.last).localeCompare(String(a.last)));
}

/** Workshop months for the trailing 12-month strip: [{ key:'2026-03', label:'Mar', visits, cost }]. */
function monthlyWorkshop(visits) {
  const months = [];
  const now = new Date();
  for (let i = 11; i >= 0; i--) {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
    months.push({
      key: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`,
      label: d.toLocaleDateString('en', { month: 'short' }),
      year: d.getFullYear(),
      visits: 0,
      cost: 0,
    });
  }
  const index = Object.fromEntries(months.map((m) => [m.key, m]));
  visits.forEach((v) => {
    if (!v.date) return;
    const m = index[String(v.date).slice(0, 7)];
    if (!m) return;
    m.visits += 1;
    m.cost += Number(v.total || 0);
  });
  return months;
}

function buildHtml(data, t, lang) {
  const rtl = lang === 'ar';
  const v = data?.vehicle || {};
  const reg = data?.registration || {};
  const av = data?.availability || {};
  const stats = data?.stats || {};
  const contracts = data?.contracts || [];
  const maint = data?.maintenance || [];
  const svc = v.service_status && typeof v.service_status === 'object' ? v.service_status : null;

  const today = new Date().toISOString().slice(0, 10);
  // Filename stays transliterated ASCII regardless of language — it is a file path, not prose.
  const title = `${slug(v.make)}_${slug(v.model)}${v.year ? '_' + v.year : ''}_(${slug(v.plate_display || v.plate_no)})_${today}`;
  const name = [v.make, v.model, v.year].filter(Boolean).join(' ') || t('Vehicle');

  const repeats = repeatFaults(maint);
  const attention = attentionItems(data, t, repeats);

  // ── counted facts ──
  const faultVisits = maint.filter((m) => !isNonFaultVisit(m));
  const faultCount = maint.reduce((n, m) => n + (isNonFaultVisit(m) ? 0 : visitFaults(m).length), 0);
  const repeatOccurrences = repeats.reduce((n, r) => n + r.count, 0);
  // Days the car spent in a shop, counted per visit from the go-in date to the recorded return
  // (an unreturned visit counts to today). Visits with no dates simply don't count.
  const workshopDays = maint.reduce((n, m) => {
    const d = daysBetween(m.date, m.in_date || today);
    return n + (d != null && d >= 0 ? d : 0);
  }, 0);
  const maintSpend = maint.reduce((n, m) => n + Number(m.total || 0), 0);
  const drivenKm = v.odometer != null && v.baseline_odometer != null
    ? Math.max(0, Number(v.odometer) - Number(v.baseline_odometer))
    : null;
  const spendPerThousand = drivenKm > 0 ? (maintSpend / drivenKm) * 1000 : null;
  const lastVisit = maint.map((m) => m.date).filter(Boolean).sort().pop() || null;

  // Fault mix — the same per-fault tally the Overview donut draws, rendered here as ranked bars
  // (a donut does not survive a black-and-white print).
  const segments = faultTagSegments(faultVisits, { top: 8 }).map((s) => ({
    ...s,
    label: s.key === 'Unspecified' ? t('Unspecified') : s.label,
  }));
  const segTotal = segments.reduce((n, s) => n + s.value, 0);

  // Where the work is done — garages ranked by visits, so a boss sees concentration at a glance.
  const garageMap = {};
  maint.forEach((m) => {
    const g = m.garage || t('Not recorded');
    const e = garageMap[g] || (garageMap[g] = { garage: g, visits: 0, cost: 0, last: null });
    e.visits += 1;
    e.cost += Number(m.total || 0);
    if (m.date && (!e.last || m.date > e.last)) e.last = m.date;
  });
  const garages = Object.values(garageMap).sort((a, b) => b.visits - a.visits).slice(0, 6);

  const months = monthlyWorkshop(maint);
  const monthPeak = Math.max(1, ...months.map((m) => (SHOW_FINANCIALS ? m.cost : m.visits)));

  // ── small builders ──
  const dl = (rows) => `<div class="dl">${rows.map(([k, val]) => `<div><dt>${esc(k)}</dt><dd>${val}</dd></div>`).join('')}</div>`;
  const stat = (n, l, tone = '') => `<div class="kpi ${tone}"><div class="n">${n}</div><div class="l">${esc(l)}</div></div>`;
  const bar = (pct, color) => `<span class="track"><span class="fill" style="width:${Math.max(2, pct)}%;background:${color}"></span></span>`;

  const specs = dl([
    [t('Plate'), dash(v.plate_display || v.plate_no)],
    [t('VIN / Chassis'), dash(v.vin || reg.chasis_no)],
    [t('Year'), dash(v.year)],
    [t('Colour'), dash(v.color)],
    [t('Category'), dash(v.category)],
    [t('Cylinders'), dash(v.cylinders)],
    [t('Horsepower'), dash(v.horse_power)],
    [t('Doors / Seats'), `${dash(v.doors)} / ${dash(v.seats)}`],
    [t('Transmission'), v.auto_gear == null ? '—' : esc(v.auto_gear ? t('Automatic') : t('Manual'))],
    [t('Drive'), dash(v.wheel_drive)],
    [t('Keys'), dash(v.keys_number)],
    [t('Odometer'), `${km(v.odometer)}${v.odometer_source_label ? ` <span class="hint">${esc(v.odometer_source_label)}</span>` : ''}`],
  ]);

  // A days-left counter reads as a countdown; once it goes negative it is no longer a countdown but a
  // breach, so it says so in words instead of printing "-26d".
  const expiryHint = (left) => {
    if (left == null) return '';
    const n = Number(left);
    return n < 0
      ? ` <span class="bad">${esc(t('expired {n}d ago', { n: num(Math.abs(n)) }))}</span>`
      : ` <span class="hint">${esc(t('{n}d', { n: num(n) }))}</span>`;
  };

  const regHtml = dl([
    [t('Registration expiry'), `${dash(fmtDate(reg.expiry_date))}${expiryHint(reg.registration_days_left)}`],
    [t('Registration status'), dash(reg.status)],
    [t('Insurer'), dash(reg.insurer)],
    [t('Insurance expiry'), `${dash(fmtDate(reg.insurance_expiry))}${expiryHint(reg.insurance_days_left)}`],
    [t('Mortgaged by'), dash(reg.mortgaged_by)],
    [t('Fines'), `${num(reg.fines_count || 0)}${SHOW_FINANCIALS && reg.fines_amount ? ` · ${aed(reg.fines_amount)}` : ''}`],
  ]);

  const serviceHtml = dl([
    [t('Service status'), dash(svc ? svc.label : v.service_status)],
    [t('Since last service'), svc?.distance != null ? km(svc.distance) : '—'],
    [t('Remaining to service'), svc?.status === 'service_due'
      ? `<span class="bad">${esc(t('{n} km overdue', { n: num(svc.overdue_km || 0) }))}</span>`
      : (svc?.remaining != null ? km(svc.remaining) : '—')],
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
    [t('Last workshop visit'), lastVisit ? `${fmtDate(lastVisit)} <span class="hint">${esc(t('{n}d ago', { n: num(daysAgo(lastVisit)) }))}</span>` : '—'],
    ...(SHOW_FINANCIALS ? [
      [t('Purchase price'), money(v.purchase_price)],
      [t('Day / Month rent'), `${money(v.day_rent_value)} / ${money(v.month_rent_value)}`],
      [t('Lifetime net profit'), stats.is_new
        ? esc(t('New — not yet rented'))
        : `<span class="${Number(stats.lifetime_net_profit || 0) < 0 ? 'bad' : 'good'}">${money(stats.lifetime_net_profit)}</span>`],
    ] : []),
  ]);

  // ── Needs attention ──
  const attentionHtml = attention.length
    ? `<div class="alerts">${attention.map((a) => `<div class="alert ${a.level}">
        <div class="at">${esc(a.title)}</div><div class="ad">${esc(a.detail)}</div>
      </div>`).join('')}</div>`
    : `<div class="allclear">${esc(t('Nothing outstanding — paperwork, service and workshop records are all clear.'))}</div>`;

  // ── Repeat faults ──
  const repeatHtml = repeats.length
    ? `<table><thead><tr>
        <th>${esc(t('Fault'))}</th><th class="r">${esc(t('Times'))}</th><th>${esc(t('First'))}</th><th>${esc(t('Last'))}</th>
        <th class="r">${esc(t('Gap'))}</th><th>${esc(t('Garages'))}</th>${SHOW_FINANCIALS ? `<th class="r">${esc(t('Spend'))}</th>` : ''}
      </tr></thead><tbody>${repeats.slice(0, 12).map((r) => `<tr>
        <td><b>${esc(r.label)}</b></td>
        <td class="r"><span class="pill ${r.count >= 3 ? 'hot' : 'warm'}">${num(r.count)}×</span></td>
        <td>${dash(fmtDate(r.first))}</td>
        <td>${dash(fmtDate(r.last))}</td>
        <td class="r">${r.gapDays != null ? esc(t('{n}d', { n: num(r.gapDays) })) : '—'}</td>
        <td>${dash(r.garages.join(', '))}</td>
        ${SHOW_FINANCIALS ? `<td class="r">${money(r.cost)}</td>` : ''}
      </tr>`).join('')}</tbody></table>`
    : `<div class="muted">${esc(t('No fault has been recorded more than once on this car.'))}</div>`;

  // ── Fault mix (ranked bars) ──
  const faultMixHtml = segments.length
    ? `<div class="ranked">${segments.map((s) => {
      const pct = segTotal ? (s.value / segTotal) * 100 : 0;
      return `<div class="row">
          <span class="lbl" title="${esc(s.label)}">${esc(s.label)}</span>
          ${bar(pct, s.color)}
          <span class="val">${num(s.value)}</span>
          <span class="pct">${pct.toFixed(pct >= 10 ? 0 : 1)}%</span>
        </div>`;
    }).join('')}</div>`
    : `<div class="muted">${esc(t('No fault history recorded yet.'))}</div>`;

  // ── 12-month workshop strip ──
  const monthsHtml = `<div class="months">${months.map((m) => {
    const value = SHOW_FINANCIALS ? m.cost : m.visits;
    const h = Math.round((value / monthPeak) * 46);
    const tip = SHOW_FINANCIALS
      ? `${m.label} ${m.year} · ${m.visits} · ${aed(m.cost)}`
      : `${m.label} ${m.year} · ${m.visits}`;
    return `<div class="m" title="${esc(tip)}">
        <span class="col"><span class="colfill" style="height:${m.visits ? Math.max(3, h) : 0}px"></span></span>
        <span class="ml">${esc(m.label)}</span>
        <span class="mv">${m.visits || ''}</span>
      </div>`;
  }).join('')}</div>`;

  const garageHtml = garages.length
    ? `<table><thead><tr><th>${esc(t('Garage'))}</th><th class="r">${esc(t('Visits'))}</th><th class="r">${esc(t('Share'))}</th><th>${esc(t('Last'))}</th>${SHOW_FINANCIALS ? `<th class="r">${esc(t('Spend'))}</th>` : ''}</tr></thead>
        <tbody>${garages.map((g) => `<tr>
          <td>${esc(g.garage)}</td>
          <td class="r">${num(g.visits)}</td>
          <td class="r">${maint.length ? Math.round((g.visits / maint.length) * 100) : 0}%</td>
          <td>${dash(fmtDate(g.last))}</td>
          ${SHOW_FINANCIALS ? `<td class="r">${money(g.cost)}</td>` : ''}
        </tr>`).join('')}</tbody></table>`
    : `<div class="muted">${esc(t('No garage recorded.'))}</div>`;

  // ── Maintenance history table ──
  const priorityPill = (p) => (p
    ? `<span class="pill ${p === 'critical' ? 'hot' : p === 'special' ? 'warm' : 'cool'}">${esc(p)}</span>`
    : '—');
  const maintRows = maint.length
    ? maint.slice(0, 30).map((m) => {
      const days = daysBetween(m.date, m.in_date || today);
      const faults = visitFaults(m);
      return `<tr>
        <td class="nw">${dash(fmtDate(m.date))}</td>
        <td class="r">${days != null && days >= 0 ? esc(t('{n}d', { n: num(days) })) : '—'}${!m.in_date && m.date ? ' <span class="hint">•</span>' : ''}</td>
        <td>${dash(m.garage)}</td>
        <td>${priorityPill(m.priority)}</td>
        <td>${faults.length ? esc(faults.join(', ')) : `<span class="hint">${dash(m.reason)}</span>`}</td>
        <td>${dash(Array.isArray(m.service_tags) && m.service_tags.length ? m.service_tags.join(', ') : '')}</td>
        <td>${dash(m.stage)}</td>
        ${SHOW_FINANCIALS ? `<td class="r">${money(m.total)}</td>` : ''}
      </tr>`;
    }).join('')
    : `<tr><td colspan="${SHOW_FINANCIALS ? 8 : 7}" class="muted">${esc(t('No maintenance recorded.'))}</td></tr>`;

  // ── Contracts (recent) table ──
  const contractRows = contracts.length
    ? contracts.slice(0, 15).map((c) => `<tr>
        <td>${dash(c.contract_no)}</td>
        <td>${dash(c.contract_type)}</td>
        <td>${dash(c.state)}</td>
        <td>${dash(c.customer)}</td>
        <td class="nw">${dash(fmtDate(c.out_date))}</td>
        <td class="nw">${dash(fmtDate(c.in_date))}</td>
        ${SHOW_FINANCIALS ? `<td class="r">${money(c.balance)}</td>` : ''}
      </tr>`).join('')
    : `<tr><td colspan="${SHOW_FINANCIALS ? 7 : 6}" class="muted">${esc(t('No contracts recorded.'))}</td></tr>`;

  const maintHeading = maint.length > 30
    ? t('Workshop history (latest 30 of {n})', { n: num(maint.length) })
    : t('Workshop history');
  const contractsHeading = contracts.length > 15
    ? t('Rental contracts (latest 15 of {n})', { n: num(contracts.length) })
    : t('Rental contracts');

  const nowCount = attention.filter((a) => a.level === 'now').length;
  const headlineTone = nowCount ? 'hot' : attention.length ? 'warm' : 'ok';
  const headline = nowCount
    ? (nowCount === 1 ? t('1 item needs attention now') : t('{n} items need attention now', { n: num(nowCount) }))
    : attention.length
      ? (attention.length === 1 ? t('1 item to watch') : t('{n} items to watch', { n: num(attention.length) }))
      : t('Nothing outstanding');

  return `<!doctype html><html lang="${rtl ? 'ar' : 'en'}" dir="${rtl ? 'rtl' : 'ltr'}"><head><meta charset="utf-8" />
  <title>${esc(title)}</title>
  <style>
    *{box-sizing:border-box}
    html{-webkit-print-color-adjust:exact;print-color-adjust:exact}
    body{font:13px/1.55 -apple-system,Segoe UI,Roboto,sans-serif;color:#0f172a;margin:0;padding:26px 20px 40px;background:#eef2f7}
    .sheet{max-width:1040px;margin:0 auto;background:#fff;border:1px solid #dde5ee;border-radius:18px;overflow:hidden;box-shadow:0 10px 34px rgba(15,23,42,.07)}

    /* header band */
    .hd{padding:24px 28px;background:linear-gradient(135deg,#0b1220 0%,#16233c 55%,#1e3a5f 100%);color:#fff;display:flex;justify-content:space-between;align-items:flex-start;gap:20px}
    .hd h1{font-size:24px;margin:0 0 6px;letter-spacing:-.01em}
    .hd .sub{color:#a8bbd4;font-size:12.5px}
    .hd .chips{margin-top:12px;display:flex;flex-wrap:wrap;gap:6px}
    .chip{background:rgba(255,255,255,.11);border:1px solid rgba(255,255,255,.16);color:#e8f0fb;border-radius:999px;padding:3px 10px;font-size:11px;font-weight:600}
    .chip.hot{background:rgba(239,68,68,.22);border-color:rgba(248,113,113,.5);color:#ffe4e4}
    .chip.warm{background:rgba(245,158,11,.2);border-color:rgba(251,191,36,.45);color:#fff2d6}
    .chip.ok{background:rgba(16,185,129,.2);border-color:rgba(52,211,153,.45);color:#d7fbee}
    .hero{text-align:end;color:#a8bbd4;font-size:11.5px}
    .hero .big{font-size:14px;font-weight:800;color:#fff;letter-spacing:.02em;text-transform:uppercase}

    /* kpi strip */
    .kpis{display:grid;grid-template-columns:repeat(6,1fr);gap:0;border-bottom:1px solid #edf1f7;background:#fafcff}
    .kpi{padding:14px 16px;border-inline-end:1px solid #edf1f7}
    .kpi:last-child{border-inline-end:0}
    .kpi .n{font-size:19px;font-weight:800;letter-spacing:-.02em;font-variant-numeric:tabular-nums}
    .kpi .l{color:#8fa0b6;font-size:10px;text-transform:uppercase;letter-spacing:.05em;margin-top:2px}
    .kpi.hot .n{color:#dc2626} .kpi.warm .n{color:#b45309} .kpi.ok .n{color:#047857}

    .sec{padding:20px 28px;border-top:1px solid #f1f5f9;break-inside:avoid}
    .sec h2{font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:#7c8ca3;margin:0 0 14px;display:flex;align-items:center;gap:8px}
    .sec h2:after{content:'';flex:1;height:1px;background:#eef2f7}
    .cols{display:grid;grid-template-columns:1fr 1fr;gap:26px}
    .cols-3{display:grid;grid-template-columns:1.25fr 1fr;gap:26px}

    /* attention */
    .alerts{display:grid;grid-template-columns:1fr 1fr;gap:9px}
    .alert{border:1px solid #e6ecf4;border-inline-start-width:4px;border-radius:10px;padding:9px 12px;background:#fbfdff}
    .alert.now{border-inline-start-color:#dc2626;background:#fff6f6}
    .alert.soon{border-inline-start-color:#f59e0b;background:#fffcf3}
    .alert .at{font-weight:700;font-size:12.5px}
    .alert .ad{color:#64748b;font-size:11.5px;margin-top:1px}
    .allclear{border:1px solid #b9ead6;background:#f2fdf8;color:#046c4e;border-radius:10px;padding:12px 14px;font-weight:600}

    .dl{display:grid;grid-template-columns:1fr 1fr;gap:9px 18px}
    .dl dt{color:#95a5ba;font-size:9.5px;text-transform:uppercase;letter-spacing:.04em;margin:0}
    .dl dd{margin:0;font-weight:600;font-size:12.5px}
    .hint{color:#9aa9bd;font-weight:500;font-size:11px}
    .bad{color:#dc2626} .good{color:#047857}

    table{width:100%;border-collapse:collapse;font-size:11.5px}
    th,td{text-align:start;padding:7px 10px;border-bottom:1px solid #f1f5f9;vertical-align:top}
    th{color:#8fa0b6;font-size:9.5px;text-transform:uppercase;letter-spacing:.05em;background:#f8fbff;border-bottom:1px solid #e6edf6}
    tbody tr:nth-child(even){background:#fcfdff}
    td.r,th.r{text-align:end;font-variant-numeric:tabular-nums}
    td.nw{white-space:nowrap}
    .muted{color:#9aa9bd;text-align:center;padding:18px;background:#fbfdff;border:1px dashed #e6ecf4;border-radius:10px}

    .pill{display:inline-block;border-radius:999px;padding:1px 8px;font-size:10.5px;font-weight:800;text-transform:capitalize}
    .pill.hot{background:#fee2e2;color:#b91c1c} .pill.warm{background:#fef3c7;color:#92400e} .pill.cool{background:#e2e8f0;color:#475569}

    /* ranked bars */
    .ranked{display:grid;gap:7px}
    .ranked .row{display:grid;grid-template-columns:minmax(90px,1.1fr) 3fr 38px 40px;align-items:center;gap:9px;font-size:11.5px}
    .ranked .lbl{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .track{display:block;height:9px;border-radius:999px;background:#eef2f7;overflow:hidden}
    .fill{display:block;height:100%;border-radius:999px}
    .ranked .val{text-align:end;font-weight:700;font-variant-numeric:tabular-nums}
    .ranked .pct{text-align:end;color:#94a3b8;font-variant-numeric:tabular-nums}

    /* 12-month strip */
    .months{display:grid;grid-template-columns:repeat(12,1fr);gap:5px;align-items:end}
    .m{display:flex;flex-direction:column;align-items:center;gap:3px}
    .col{display:flex;align-items:flex-end;justify-content:center;height:48px;width:100%;background:#f4f7fb;border-radius:5px 5px 3px 3px}
    .colfill{display:block;width:100%;border-radius:5px 5px 3px 3px;background:linear-gradient(180deg,#3b82f6,#1d4ed8)}
    .ml{font-size:9.5px;color:#94a3b8;text-transform:uppercase;letter-spacing:.03em}
    .mv{font-size:10.5px;font-weight:700;color:#334155;height:13px}

    .ft{padding:14px 28px;color:#94a3b8;font-size:10.5px;border-top:1px solid #f1f5f9;display:flex;justify-content:space-between;gap:12px}
    .bar{max-width:1040px;margin:0 auto 14px;display:flex;justify-content:flex-end}
    .btn{background:#0f172a;color:#fff;border:0;border-radius:999px;padding:9px 20px;font-weight:700;font-size:13px;cursor:pointer}
    @media print{
      body{padding:0;background:#fff}
      .sheet{border:0;border-radius:0;box-shadow:none;max-width:none}
      .bar{display:none}
      .cols,.cols-3{gap:16px}
      .sec{padding:14px 20px}
    }
  </style></head>
  <body onload="window.focus()">
    <div class="bar"><button class="btn" onclick="window.print()">${esc(t('Print / Save as PDF'))}</button></div>
    <div class="sheet">
      <div class="hd">
        <div>
          <h1>${esc(name)}</h1>
          <div class="sub">${dash(v.plate_display || v.plate_no)}${v.vin ? ` · VIN ${esc(v.vin)}` : ''}${v.color ? ` · ${esc(v.color)}` : ''}</div>
          <div class="chips">
            <span class="chip ${headlineTone}">${esc(headline)}</span>
            <span class="chip">${esc(av.label || v.operational_status_label || v.status || '—')}</span>
            <span class="chip">${esc(km(v.odometer))}</span>
            ${repeats.length ? `<span class="chip warm">${esc(repeats.length === 1 ? t('1 repeat fault') : t('{n} repeat faults', { n: num(repeats.length) }))}</span>` : ''}
          </div>
        </div>
        <div class="hero"><div class="big">${esc(t('Vehicle Report'))}</div><div>${esc(fmtDate(today))}</div></div>
      </div>

      <div class="kpis">
        ${stat(num(faultCount), t('faults recorded'))}
        ${stat(num(repeatOccurrences), t('repeat visits'), repeats.length ? 'warm' : '')}
        ${stat(num(stats.maintenance_count || maint.length), t('workshop visits'))}
        ${stat(num(workshopDays), t('days in workshop'), workshopDays > 60 ? 'warm' : '')}
        ${stat(num(stats.contracts_count || 0), t('contracts'))}
        ${SHOW_FINANCIALS
          ? stat(aed(maintSpend), t('workshop spend'))
          : stat(km(v.odometer), t('odometer'))}
      </div>

      <div class="sec"><h2>${esc(t('Needs attention'))}</h2>${attentionHtml}</div>

      <div class="sec"><h2>${esc(t('Faults that came back'))}</h2>${repeatHtml}</div>

      <div class="sec cols-3">
        <div><h2>${esc(t('What goes wrong on this car'))}</h2>${faultMixHtml}</div>
        <div><h2>${esc(SHOW_FINANCIALS ? t('Workshop spend · last 12 months') : t('Workshop visits · last 12 months'))}</h2>${monthsHtml}
          ${SHOW_FINANCIALS && spendPerThousand != null ? `<div class="hint" style="margin-top:10px">${esc(t('{amount} of workshop spend per 1,000 km driven ({km} km on record).', { amount: aed(spendPerThousand), km: num(drivenKm) }))}</div>` : ''}
        </div>
      </div>

      <div class="sec"><h2>${esc(t('Where the work is done'))}</h2>${garageHtml}</div>

      <div class="sec cols">
        <div><h2>${esc(t('Specifications'))}</h2>${specs}</div>
        <div><h2>${esc(t('Registration & Insurance'))}</h2>${regHtml}</div>
      </div>
      <div class="sec cols">
        <div><h2>${esc(t('Service'))}</h2>${serviceHtml}</div>
        <div><h2>${esc(t('Commercial & Status'))}</h2>${commercialHtml}</div>
      </div>

      <div class="sec"><h2>${esc(maintHeading)}</h2>
        <table><thead><tr><th>${esc(t('Date'))}</th><th class="r">${esc(t('Days'))}</th><th>${esc(t('Garage'))}</th><th>${esc(t('Priority'))}</th><th>${esc(t('Faults'))}</th><th>${esc(t('Service'))}</th><th>${esc(t('Stage'))}</th>${SHOW_FINANCIALS ? `<th class="r">${esc(t('Cost'))}</th>` : ''}</tr></thead>
        <tbody>${maintRows}</tbody></table>
      </div>

      <div class="sec"><h2>${esc(contractsHeading)}</h2>
        <table><thead><tr><th>${esc(t('Contract'))}</th><th>${esc(t('Type'))}</th><th>${esc(t('State'))}</th><th>${esc(t('Customer'))}</th><th>${esc(t('Out'))}</th><th>${esc(t('In'))}</th>${SHOW_FINANCIALS ? `<th class="r">${esc(t('Balance'))}</th>` : ''}</tr></thead>
        <tbody>${contractRows}</tbody></table>
      </div>

      <div class="ft"><span>${esc(t('Generated by Faster · Vehicle Report'))} · ${esc(t('every figure is counted from recorded workshop, contract and registration data'))}</span><span>${esc(name)} · ${dash(v.plate_display || v.plate_no)} · ${esc(fmtDate(today))}</span></div>
    </div>
  </body></html>`;
}
