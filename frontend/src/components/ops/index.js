/* =======================================================================
   Cockpit+ component system — the operational design language of FleetView.
   Every screen in the "enterprise operating system" is composed from these.
   All components are prop-driven and shape-agnostic; pages map API data to
   the small vocabularies below.  Styling lives in ./ops.css (scoped .opx).
   ======================================================================= */
import { useEffect, useRef, useState } from 'react';
import './ops.css';

/* ---------- state vocabularies (the two independent dimensions) ---------- */

// Dimension 1 — Rental availability, derived from operational_status / status.
export function rentalMeta(v = {}) {
  const s = String(v.operational_status || v.rental_state || v.status || '').toLowerCase();
  if (['rented', 'on_rent'].includes(s)) return { key: 'rented', label: 'Rented' };
  if (['reserved', 'booked'].includes(s)) return { key: 'reserved', label: 'Reserved' };
  if (['in_transit', 'transfer', 'transit'].includes(s)) return { key: 'reserved', label: 'In Transit' };
  if (v.available === false || ['blocked', 'out_of_order', 'suspended', 'grounded', 'under_maintenance'].includes(s))
    return { key: 'blocked', label: 'Blocked' };
  return { key: 'avail', label: 'Available' };
}

// Dimension 2 — Maintenance lifecycle, derived from workflow_status (never
// overwritten by the rental dimension). Returns a label + tone.
const WF_LIFECYCLE = {
  none: { label: 'None', tone: 'none' },
  inspection_pending: { label: 'Inspection', tone: 'reserved' },
  inspection_requested: { label: 'Inspection', tone: 'reserved' },
  complaint_triage: { label: 'Triage', tone: 'reserved' },
  inspection_diagnostic: { label: 'Diagnosis', tone: 'reserved' },
  recommendation_pending: { label: 'Needs Approval', tone: 'reserved' },
  awaiting_dispatch: { label: 'Dispatch', tone: 'reserved' },
  in_transit: { label: 'In Transit', tone: 'reserved' },
  under_repair: { label: 'Repair', tone: 'rented' },
  repair_review: { label: 'Review', tone: 'rented' },
  ready_for_pickup: { label: 'Ready', tone: 'avail' },
  ready_for_reinspection: { label: 'QA', tone: 'reserved' },
  reinspection_failed: { label: 'QA Failed', tone: 'crit' },
  pending_qa: { label: 'QA', tone: 'reserved' },
  paused_returned_to_service: { label: 'Paused', tone: 'paused' },
  paused_for_rental: { label: 'Paused', tone: 'paused' }, // real backend workflow_status value

  awaiting_invoice: { label: 'Invoice Due', tone: 'paused' },
  return_handover_pending: { label: 'Return Handover', tone: 'paused' },
  closed: { label: 'Completed', tone: 'avail' },
  completed: { label: 'Completed', tone: 'avail' },
};
export function maintenanceMeta(v = {}) {
  const raw = String(v.maintenance_state || v.workflow_status || '').toLowerCase();
  if (!raw || raw === 'none') return { label: 'None', tone: 'none', active: false };
  const m = WF_LIFECYCLE[raw] || { label: v.status_label || 'In Workshop', tone: 'reserved' };
  return { ...m, active: true, paused: raw === 'paused_returned_to_service' || raw === 'paused_for_rental' };
}

export function severityTone(sev) {
  const s = String(sev || '').toLowerCase();
  if (['critical', 'red', 'high'].includes(s)) return 'crit';
  if (['moderate', 'yellow', 'orange', 'medium'].includes(s)) return 'paused';
  return 'ok';
}

/* ----------------------------- Sparkline ------------------------------- */
export function Sparkline({ data = [], color = '#22d3ee', w = 74, h = 28 }) {
  const ref = useRef(null);
  useEffect(() => {
    const cv = ref.current;
    if (!cv || !data.length) return;
    const dpr = window.devicePixelRatio || 1;
    cv.width = w * dpr; cv.height = h * dpr;
    const ctx = cv.getContext('2d');
    ctx.scale(dpr, dpr);
    ctx.clearRect(0, 0, w, h);
    const mn = Math.min(...data), mx = Math.max(...data), rng = (mx - mn) || 1;
    const pts = data.map((val, i) => [i / (data.length - 1 || 1) * (w - 4) + 2, h - 3 - ((val - mn) / rng) * (h - 8)]);
    const grd = ctx.createLinearGradient(0, 0, 0, h);
    grd.addColorStop(0, color + '40'); grd.addColorStop(1, color + '00');
    ctx.beginPath(); ctx.moveTo(pts[0][0], h);
    pts.forEach((p) => ctx.lineTo(p[0], p[1]));
    ctx.lineTo(pts[pts.length - 1][0], h); ctx.closePath();
    ctx.fillStyle = grd; ctx.fill();
    ctx.beginPath();
    pts.forEach((p, i) => (i ? ctx.lineTo(p[0], p[1]) : ctx.moveTo(p[0], p[1])));
    ctx.strokeStyle = color; ctx.lineWidth = 1.5; ctx.lineJoin = 'round'; ctx.stroke();
    const last = pts[pts.length - 1];
    ctx.beginPath(); ctx.arc(last[0], last[1], 2, 0, 7); ctx.fillStyle = color; ctx.fill();
  }, [data, color, w, h]);
  return <canvas ref={ref} />;
}

/* ------------------------------ KPI tile ------------------------------- */
export function KpiTile({ label, value, unit, foot, footTone = 'flat', tone = '', spark, sparkColor, onClick, active }) {
  return (
    <div className={`opx-kpi ${tone} ${onClick ? 'click' : ''} ${active ? 'active' : ''}`}
      onClick={onClick} role={onClick ? 'button' : undefined} tabIndex={onClick ? 0 : undefined}
      onKeyDown={onClick ? (e) => (e.key === 'Enter' ? onClick() : null) : undefined}>
      <div className="lbl" dangerouslySetInnerHTML={{ __html: label }} />
      <div className="v tnum">{value}{unit ? <small>{unit}</small> : null}</div>
      {foot ? <div className="foot"><span className={`opx-${footTone}`}>{foot}</span></div> : null}
      {spark ? <Sparkline data={spark} color={sparkColor || '#22d3ee'} /> : null}
    </div>
  );
}

/* --------------------- Stat gauge tile (rich KPI) ---------------------- */
// Mid tones that read on BOTH white (Platinum) and dark (Cockpit) surfaces.
const STAT_TONE = {
  avail: { c: '#10b981', g: 'rgba(16,185,129,.16)' },
  reserved: { c: '#3b82f6', g: 'rgba(59,130,246,.16)' },
  rented: { c: '#8b5cf6', g: 'rgba(139,92,246,.16)' },
  maint: { c: '#f59e0b', g: 'rgba(245,158,11,.16)' },
  crit: { c: '#f43f5e', g: 'rgba(244,63,94,.16)' },
  cyan: { c: '#0e9dc0', g: 'rgba(34,211,238,.16)' },
};
const STAT_ICON = {
  car: 'M5 13l1.5-4.5A2 2 0 0 1 8.4 7h7.2a2 2 0 0 1 1.9 1.5L19 13m-14 0h14m-14 0a2 2 0 0 0-2 2v3a1 1 0 0 0 1 1h1m14-6a2 2 0 0 1 2 2v3a1 1 0 0 1-1 1h-1M7 17h.01M17 17h.01',
  calendar: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z',
  wrench: 'M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z',
  check: 'M20 6L9 17l-5-5',
  alert: 'M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
  pause: 'M10 4H6v16h4zM18 4h-4v16h4z',
};
function StatIcon({ k }) {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round">
      <path d={STAT_ICON[k] || STAT_ICON.car} />
    </svg>
  );
}
export function StatGaugeTile({ label, value, hint, tone = 'avail', icon = 'car', percent = 0, active = false, onClick }) {
  const T = STAT_TONE[tone] || STAT_TONE.avail;
  const size = 66, stroke = 6, r = (size - stroke) / 2, c = 2 * Math.PI * r;
  const pct = Math.max(0, Math.min(100, percent || 0));
  const off = c * (1 - pct / 100);
  return (
    <div className={`opx-stat ${active ? 'active' : ''} ${onClick ? 'click' : ''}`} style={{ '--sc': T.c, '--sg': T.g }}
      onClick={onClick} role={onClick ? 'button' : undefined} tabIndex={onClick ? 0 : undefined}
      onKeyDown={onClick ? (e) => (e.key === 'Enter' ? onClick() : null) : undefined}>
      <div className="opx-stat-main">
        <div className="opx-stat-lbl"><span className="opx-stat-ic"><StatIcon k={icon} /></span>{label}</div>
        <div className="opx-stat-v">{value}</div>
        {hint ? <div className="opx-stat-hint">{hint}</div> : null}
      </div>
      <div className="opx-stat-ring">
        <svg width={size} height={size}>
          <circle className="trk" cx={size / 2} cy={size / 2} r={r} strokeWidth={stroke} />
          <circle className="arc" cx={size / 2} cy={size / 2} r={r} strokeWidth={stroke} strokeDasharray={c} strokeDashoffset={off} />
        </svg>
        <span className="opx-stat-ringic"><StatIcon k={icon} /></span>
      </div>
    </div>
  );
}

/* --------------------------- Command panel ----------------------------- */
export function CommandPanel({ title, label, meta, dotColor, children, className = '', bodyFlush = false, action }) {
  return (
    <div className={`opx-panel ${className}`}>
      {(title || label) && (
        <div className="opx-panel-hd">
          <div className="t">
            {dotColor ? <span className="dot" style={{ background: dotColor }} /> : null}
            {title}
            {label ? <span className="lbl">{label}</span> : null}
          </div>
          {meta ? <span className="meta">{meta}</span> : null}
          {action || null}
        </div>
      )}
      <div className={`opx-panel-bd ${bodyFlush ? 'flush' : ''}`}>{children}</div>
    </div>
  );
}

/* ------------------------- Dual-state badge ---------------------------- */
export function DualStateBadge({ vehicle = {}, row = false, showNone = false }) {
  const r = rentalMeta(vehicle);
  const m = maintenanceMeta(vehicle);
  const sev = vehicle.fault_severity || vehicle.severity;
  const showM = m.active || showNone;
  return (
    <div className={`opx-dual ${row ? 'row' : ''}`}>
      <span className={`opx-chip ${r.key}`}><span className="cd" />{r.label}</span>
      {showM ? (
        <span className={`opx-chip ${m.paused && severityTone(sev) === 'crit' ? 'crit' : m.tone}`}>
          <span className="cd" />
          {m.paused ? '⏸ ' : ''}{m.label}
        </span>
      ) : null}
    </div>
  );
}

/* ------------------------------ Score ring ----------------------------- */
export function ScoreRing({ value = 0, label, size = 72, stroke = 7, color }) {
  const pct = Math.max(0, Math.min(100, value));
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  const off = c * (1 - pct / 100);
  const auto = color || (pct >= 80 ? '#34d399' : pct >= 55 ? '#f5a524' : '#fb7185');
  return (
    <div className="opx-ring">
      <svg width={size} height={size}>
        <circle className="opx-ring-track" cx={size / 2} cy={size / 2} r={r} fill="none" strokeWidth={stroke} />
        <circle className="opx-ring-fill" cx={size / 2} cy={size / 2} r={r} fill="none" stroke={auto}
          strokeWidth={stroke} strokeDasharray={c} strokeDashoffset={off} />
      </svg>
      <div className="num" style={{ marginTop: -size / 2 - 8, marginBottom: size / 2 - 8, color: auto, fontSize: size * 0.26 }}>
        {Math.round(pct)}
      </div>
      {label ? <span className="rlbl">{label}</span> : null}
    </div>
  );
}

/* --------------------------- Vehicle ops card -------------------------- */
export function VehicleOpsCard({ vehicle = {}, onClick, subtitle, progress, progressColor }) {
  const m = maintenanceMeta(vehicle);
  const sev = severityTone(vehicle.fault_severity || vehicle.severity);
  const sevClass = m.paused ? (sev === 'crit' ? 'sev-crit' : 'sev-paused')
    : sev === 'crit' ? 'sev-crit' : m.active ? 'sev-info' : 'sev-ok';
  const plate = vehicle.plate || vehicle.car_no || vehicle.CarNo || vehicle.id;
  const model = vehicle.model || vehicle.make_model || vehicle.name || '—';
  return (
    <div className={`opx-card ${sevClass}`} onClick={onClick} role="button" tabIndex={0}
      onKeyDown={(e) => (e.key === 'Enter' && onClick ? onClick() : null)}>
      <div className="sev" />
      <div className="top">
        <span className="opx-plate">{plate}</span>
        <div className="model">{model}<span>{vehicle.make || vehicle.year || ''}</span></div>
        {vehicle.age_label ? <span className="now">{vehicle.age_label}</span> : null}
      </div>
      {subtitle ? <div className="line">{subtitle}</div> : null}
      <div className="badges"><DualStateBadge vehicle={vehicle} row showNone /></div>
      {typeof progress === 'number' ? (
        <div className="prog"><i style={{ width: `${Math.max(3, Math.min(100, progress))}%`, background: progressColor || '#22d3ee' }} /></div>
      ) : null}
    </div>
  );
}

/* --------------------------- Operational lanes ------------------------- */
const LANE_RAIL = { avail: '#34d399', rented: '#60a5fa', reserved: '#a78bfa', paused: '#f5a524', crit: '#fb7185', blocked: '#8592ab', info: '#22d3ee' };
// How many cards a lane shows before collapsing the rest behind a "Show more" button.
const LANE_PAGE_SIZE = 3;

function LaneColumn({ lane }) {
  const [expanded, setExpanded] = useState(false);
  const items = lane.items || [];
  const shown = expanded ? items : items.slice(0, LANE_PAGE_SIZE);
  const hidden = items.length - shown.length;
  return (
    <div className="opx-lane">
      <div className="opx-lane-hd">
        <span className="rail" style={{ background: LANE_RAIL[lane.tone] || '#22d3ee' }} />
        <span className="name">{lane.name}</span>
        <span className="ct">{items.length}</span>
      </div>
      <div className="opx-lane-body">
        {items.length
          ? shown.map((it, i) => <div key={it.id || i}>{lane.render ? lane.render(it) : null}</div>)
          : <div className="opx-empty" style={{ padding: '20px 10px' }}>—</div>}
        {hidden > 0 && (
          <button type="button" className="opx-lane-more" onClick={() => setExpanded(true)}>
            Show {hidden} more
          </button>
        )}
        {expanded && items.length > LANE_PAGE_SIZE && (
          <button type="button" className="opx-lane-more" onClick={() => setExpanded(false)}>
            Show less
          </button>
        )}
      </div>
    </div>
  );
}

export function LaneBoard({ lanes = [] }) {
  return (
    <div className="opx-lanes">
      {lanes.map((lane) => (
        <LaneColumn lane={lane} key={lane.key} />
      ))}
    </div>
  );
}

/* --------------------------- Live status matrix ------------------------ */
// rows/cols: [{key,label}]; data: { [rowKey]: { [colKey]: number } }; flags: Set("row:col")
export function StatusMatrix({ rows = [], cols = [], data = {}, flagCell, hotCell }) {
  return (
    <div className="opx-mx">
      <table>
        <thead>
          <tr>
            <th style={{ textAlign: 'left' }}>Maint ↓ / Rental →</th>
            {cols.map((c) => <th key={c.key}>{c.label}</th>)}
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.key}>
              <th>{r.label}</th>
              {cols.map((c) => {
                const n = data?.[r.key]?.[c.key] || 0;
                const flag = flagCell ? flagCell(r.key, c.key, n) : false;
                const hot = hotCell ? hotCell(r.key, c.key, n) : false;
                return (
                  <td key={c.key}>
                    <div className={`mcell ${n === 0 ? 'z' : ''} ${flag ? 'flag' : ''} ${hot ? 'hot' : ''}`}>
                      {n === 0 ? '·' : n}
                    </div>
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/* ----------------------------- Fleet heatmap --------------------------- */
// cells: [{ id, plate, state: 'avail'|'rented'|'reserved'|'paused'|'maint'|'idle', title }]
export function FleetHeatmap({ cells = [], onCell }) {
  return (
    <div>
      <div className="opx-heat">
        {cells.map((c, i) => (
          <div key={c.id || i} className={`cellh h-${c.state || 'idle'}`} title={c.title || c.plate}
            onClick={() => onCell && onCell(c)} />
        ))}
      </div>
      <div className="opx-heat-legend">
        <span><i style={{ background: 'rgba(52,211,153,.55)' }} />Available</span>
        <span><i style={{ background: 'rgba(96,165,250,.6)' }} />Rented</span>
        <span><i style={{ background: 'rgba(167,139,250,.55)' }} />Reserved</span>
        <span><i style={{ background: 'rgba(245,165,36,.7)' }} />Paused</span>
        <span><i style={{ background: 'rgba(251,113,133,.55)' }} />Maintenance</span>
        <span><i style={{ background: 'rgba(133,146,171,.28)' }} />Idle</span>
      </div>
    </div>
  );
}

/* --------------------------- Live activity feed ------------------------ */
const FEED_ICON = (ev) => {
  const s = String(ev.stage || ev.action || ev.event_type || '').toLowerCase();
  if (s.includes('pause') || s.includes('released')) return { ic: '⏸', tone: 'paused' };
  if (s.includes('resume')) return { ic: '▶', tone: 'good' };
  if (s.includes('repair') || s.includes('garage')) return { ic: '🔧', tone: '' };
  if (s.includes('transit') || s.includes('dispatch') || s.includes('move')) return { ic: '🚚', tone: '' };
  if (s.includes('inspect')) return { ic: '🔎', tone: '' };
  if (s.includes('ready') || s.includes('service') || s.includes('complete') || s.includes('closed')) return { ic: '✓', tone: 'good' };
  if (s.includes('breakdown') || s.includes('critical') || s.includes('fail')) return { ic: '⚠', tone: 'crit' };
  return { ic: '◆', tone: '' };
};
export function LiveActivityFeed({ events = [], freshCount = 0, onEvent }) {
  return (
    <div className="opx-feed">
      {events.length === 0 ? <div className="opx-empty">No activity in range</div> : null}
      {events.map((ev, i) => {
        const meta = FEED_ICON(ev);
        return (
          <div key={ev.id || i} className={`opx-fe ${i < freshCount ? 'fresh' : ''}`}
            onClick={() => onEvent && onEvent(ev)} style={onEvent ? { cursor: 'pointer' } : undefined}>
            <div className={`ic ${meta.tone}`}>{meta.ic}</div>
            <div className="body">
              <div className="h">
                {ev.plate ? <b>{ev.plate}</b> : null} {ev.description || ev.stage || ev.action}
              </div>
              <div className="sub">
                {ev.stage ? <span>{ev.stage}</span> : null}
                {ev.actor_name ? <span>{ev.actor_name}</span> : null}
                {ev.odometer ? <span>{Number(ev.odometer).toLocaleString()} km</span> : null}
                {ev.time_label ? <span>{ev.time_label}</span> : null}
              </div>
            </div>
          </div>
        );
      })}
    </div>
  );
}

/* -------------------------- Animated journey map ----------------------- */
// nodes: [{ kind:'done'|'now'|'pause'|'rental'|'pending', title, time, meta }]
export function JourneyMap({ nodes = [] }) {
  return (
    <div className="opx-journey">
      <div className="opx-jl">
        {nodes.map((n, i) => (
          <div key={i} className={`opx-jn ${n.kind || ''}`}>
            <div className="h">{n.title}{n.time ? <span className="time">{n.time}</span> : null}</div>
            {n.meta ? <div className="meta" dangerouslySetInnerHTML={{ __html: n.meta }} /> : null}
          </div>
        ))}
      </div>
    </div>
  );
}

/* ------------------------- Contextual action drawer -------------------- */
// The old `rtl` prop is gone: .opx-drawer now anchors to the trailing edge with
// inset-inline-end, so it already mirrors with the document direction. No caller
// ever passed it.
export function ContextualDrawer({ open, onClose, title, subtitle, children, footer }) {
  useEffect(() => {
    if (!open) return undefined;
    const onKey = (e) => e.key === 'Escape' && onClose && onClose();
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, onClose]);
  if (!open) return null;
  return (
    <>
      <div className="opx-drawer-scrim" onClick={onClose} />
      <div className="opx-drawer">
        <div className="opx-drawer-hd">
          <div>
            <div style={{ fontSize: 15, fontWeight: 700 }}>{title}</div>
            {subtitle ? <div className="opx-hint" style={{ marginTop: 2 }}>{subtitle}</div> : null}
          </div>
          <button className="opx-x" onClick={onClose} aria-label="Close">✕</button>
        </div>
        <div className="opx-drawer-bd">{children}</div>
        {footer ? <div className="opx-drawer-ft">{footer}</div> : null}
      </div>
    </>
  );
}

/* -------------------------------- Clock -------------------------------- */
export function OpsClock() {
  const [now, setNow] = useState(() => new Date());
  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 1000);
    return () => clearInterval(id);
  }, []);
  return (
    <span className="clock tnum">
      <b>{now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}</b>
      {' · '}{now.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' })}
    </span>
  );
}
