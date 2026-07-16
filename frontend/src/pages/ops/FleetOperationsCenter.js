/* =======================================================================
   Fleet Operations Center — the flagship command surface.
   An always-dark "mission control" view built from the Cockpit+ system.
   Reads only existing endpoints (no backend changes); every vehicle shows
   BOTH its rental dimension and its maintenance dimension simultaneously.
   ======================================================================= */
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../../api/client';
import {
  CommandPanel, KpiTile, LaneBoard, VehicleOpsCard, StatusMatrix, FleetHeatmap,
  LiveActivityFeed, JourneyMap, ContextualDrawer, ScoreRing, DualStateBadge, OpsClock,
  rentalMeta, severityTone,
} from '../../components/ops';

const payload = (r) => (r && r.data && 'data' in r.data ? r.data.data : r?.data);
const isoDaysAgo = (d) => { const t = new Date(); t.setDate(t.getDate() - d); return t.toISOString(); };

function fmtDur(sec) {
  if (!sec && sec !== 0) return '—';
  const s = Math.max(0, Math.floor(sec));
  const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60);
  if (d) return `${d}d ${h}h`;
  if (h) return `${h}h ${m}m`;
  return `${m}m`;
}
function timeAgo(iso) {
  if (!iso) return '';
  const diff = (Date.now() - new Date(iso).getTime()) / 1000;
  if (diff < 60) return 'now';
  if (diff < 3600) return `${Math.floor(diff / 60)}m`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h`;
  return `${Math.floor(diff / 86400)}d`;
}

/* ------- per-vehicle operational scores (transparent heuristics) ------- */
const CONDITION_PENALTY = { green: 0, orange: 12, yellow: 45, red: 75 };
function rentalReadiness(v) {
  let s = v.available ? 96 : v.rented ? 74 : v.reserved ? 82 : 22;
  s -= CONDITION_PENALTY[v.condition_grade] || 0;
  if (v.is_deferred_maintenance) s -= 14;
  return Math.max(2, Math.min(100, s));
}
function maintReadiness(v, ticket) {
  if (!ticket && !v.under_maintenance && !v.is_deferred_maintenance) return 100;
  if (v.is_deferred_maintenance && !ticket) return 46; // paused / deferred
  const stage = String(ticket?.workflow_status || '').toLowerCase();
  const prog = { inspection_pending: 20, awaiting_dispatch: 32, in_transit: 44, under_repair: 60,
    repair_review: 74, ready_for_pickup: 88, ready_for_reinspection: 80, reinspection_failed: 50,
    paused_returned_to_service: 46 }[stage];
  return prog || 55;
}
function healthScore(v, ticket) {
  let s = 100 - (CONDITION_PENALTY[v.condition_grade] || 0);
  if (v.is_deferred_maintenance) s -= 18;
  if (ticket && severityTone(ticket.fault_severity) === 'crit') s -= 22;
  else if (v.under_maintenance) s -= 12;
  return Math.max(4, Math.min(100, s));
}
const avg = (arr) => (arr.length ? Math.round(arr.reduce((a, b) => a + b, 0) / arr.length) : 0);

/* ---------------- board columns → curated operational lanes ------------- */
const LANE_MAP = [
  { key: 'awaiting', name: 'Awaiting Dispatch', tone: 'reserved', cols: ['triage', 'requested', 'diagnostic', 'pending', 'on_site', 'awaiting_pickup'] },
  { key: 'transit', name: 'In Transit', tone: 'info', cols: ['in_transit'] },
  { key: 'workshop', name: 'In Workshop', tone: 'crit', cols: ['under_repair'] },
  { key: 'qa', name: 'QA / Review', tone: 'reserved', cols: ['repair_review', 'qa_reinspection', 'reinspection_failed'] },
  { key: 'ready', name: 'Ready', tone: 'avail', cols: ['ready_for_pickup', 'in_our_park'] },
];

export default function FleetOperationsCenter() {
  const navigate = useNavigate();
  const [loading, setLoading] = useState(true);
  const [dash, setDash] = useState(null);
  const [vehicles, setVehicles] = useState([]);
  const [board, setBoard] = useState({ columns: {}, counts: {} });
  const [feed, setFeed] = useState([]);
  const [trends, setTrends] = useState(null);
  const [, setTick] = useState(0); // re-render ticker to refresh time-in-stage / "ago" labels

  // Drawer: a vehicle's complete operational story.
  const [story, setStory] = useState(null); // { vehicle, ticket, events, loading }

  const load = useCallback(async (silent) => {
    if (!silent) setLoading(true);
    const [d, v, b, a, t] = await Promise.allSettled([
      api.get('/Dashboard', { params: { expiring_days: 7 } }),
      api.get('/Vehicle'),
      api.get('/maintenance-tickets/board'),
      api.get('/Activity', { params: { from: isoDaysAgo(10), limit: 120 } }),
      api.get('/Dashboard/trends', { params: { months: 12 } }),
    ]);
    if (d.status === 'fulfilled') setDash(payload(d.value));
    if (v.status === 'fulfilled') setVehicles(payload(v.value) || []);
    if (b.status === 'fulfilled') setBoard(payload(b.value) || { columns: {}, counts: {} });
    if (a.status === 'fulfilled') setFeed((payload(a.value)?.events) || []);
    if (t.status === 'fulfilled') setTrends(payload(t.value));
    setLoading(false);
  }, []);

  useEffect(() => { load(false); }, [load]);
  // Live refresh every 45s (silent), plus a re-render tick for the clock-driven ages.
  useEffect(() => {
    const r = setInterval(() => { if (document.visibilityState === 'visible') load(true); }, 45000);
    const c = setInterval(() => setTick((x) => x + 1), 30000);
    return () => { clearInterval(r); clearInterval(c); };
  }, [load]);

  /* --------- ticket-by-vehicle map from the board columns ---------- */
  const ticketByVehicle = useMemo(() => {
    const map = {};
    Object.values(board.columns || {}).forEach((col) => (col || []).forEach((tk) => { if (tk.vehicle_id) map[tk.vehicle_id] = tk; }));
    return map;
  }, [board]);

  /* ----------------------- derived fleet model -------------------------- */
  const model = useMemo(() => {
    const paused = vehicles.filter((v) => v.is_deferred_maintenance);
    const criticalOnRent = vehicles.filter((v) => v.rented && ['red', 'yellow'].includes(v.condition_grade));
    const rr = [], mr = [], hs = [];
    vehicles.forEach((v) => { const tk = ticketByVehicle[v.id]; rr.push(rentalReadiness(v)); mr.push(maintReadiness(v, tk)); hs.push(healthScore(v, tk)); });

    // Live status matrix: maintenance lifecycle (rows) × rental availability (cols)
    const cols = [
      { key: 'avail', label: 'Available' }, { key: 'reserved', label: 'Reserved' },
      { key: 'rented', label: 'Rented' }, { key: 'blocked', label: 'Blocked' },
    ];
    const rows = [
      { key: 'none', label: 'None' }, { key: 'active', label: 'In Workshop' },
      { key: 'paused', label: 'Paused' }, { key: 'awaiting', label: 'Awaiting Parts' },
    ];
    const data = {};
    rows.forEach((r) => { data[r.key] = { avail: 0, reserved: 0, rented: 0, blocked: 0 }; });
    const heat = [];
    vehicles.forEach((v) => {
      const rk = rentalMeta(v).key;
      const tk = ticketByVehicle[v.id];
      let mk = 'none';
      if (v.is_deferred_maintenance) mk = 'paused';
      else if (tk && tk.workflow_status === 'awaiting_parts') mk = 'awaiting';
      else if (tk || v.under_maintenance) mk = 'active';
      if (data[mk]) data[mk][rk] = (data[mk][rk] || 0) + 1;
      // heatmap cell
      let hstate = 'idle';
      if (v.is_deferred_maintenance) hstate = 'paused';
      else if (v.rented) hstate = 'rented';
      else if (v.reserved) hstate = 'reserved';
      else if (tk || v.under_maintenance) hstate = 'maint';
      else if (v.available) hstate = 'avail';
      heat.push({ id: v.id, plate: v.plate_no || v.code || v.id, state: hstate, model: v, title: `${v.plate_no || v.id} — ${rentalMeta(v).label}` });
    });

    return {
      paused, criticalOnRent,
      fleetRental: avg(rr), fleetMaint: avg(mr), fleetHealth: avg(hs),
      matrix: { rows, cols, data }, heat,
    };
  }, [vehicles, ticketByVehicle]);

  /* --------------------------- lanes ----------------------------------- */
  const normTicket = (tk) => ({
    id: tk.id, plate: tk.plate, model: tk.car, operational_status: 'maintenance',
    workflow_status: tk.workflow_status, fault_severity: tk.fault_severity,
    _tk: tk,
  });
  const lanes = useMemo(() => {
    const cols = board.columns || {};
    const built = [];
    // Flagship lane first: Paused — Returned to Service (from deferred-maintenance vehicles).
    built.push({
      key: 'paused', name: 'Paused — Returned to Service', tone: 'paused',
      items: model.paused.map((v) => ({ ...v, _paused: true })),
      render: (v) => {
        const tk = ticketByVehicle[v.id];
        return (
          <VehicleOpsCard
            vehicle={{ plate: v.plate_no || v.code, model: v.model, make: v.make, operational_status: v.operational_status, available: v.available, rented: v.rented, reserved: v.reserved, is_deferred_maintenance: true, maintenance_state: 'paused_returned_to_service', fault_severity: tk?.fault_severity || (['red', 'yellow'].includes(v.condition_grade) ? 'critical' : 'moderate') }}
            subtitle={<>⏸ {v.deferred_maintenance_reason || 'Repair on hold — car released to service'}</>}
            onClick={() => openStory(v)}
          />
        );
      },
    });
    LANE_MAP.forEach((lm) => {
      const items = lm.cols.flatMap((c) => cols[c] || []);
      built.push({
        key: lm.key, name: lm.name, tone: lm.tone, items,
        render: (tk) => (
          <VehicleOpsCard
            vehicle={normTicket(tk)}
            subtitle={<>{tk.position?.label || tk.status_label} · <span className="mono">{fmtDur(tk.seconds_in_stage)}</span></>}
            onClick={() => openStory(vehicles.find((x) => x.id === tk.vehicle_id) || { id: tk.vehicle_id, plate_no: tk.plate, model: tk.car }, tk)}
          />
        ),
      });
    });
    return built;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [board, model.paused, ticketByVehicle, vehicles]);

  /* --------------------- vehicle story drawer -------------------------- */
  const openStory = useCallback(async (vehicle, ticket) => {
    const tk = ticket || ticketByVehicle[vehicle.id];
    setStory({ vehicle, ticket: tk, events: [], loading: true });
    try {
      const r = await api.get('/Activity', { params: { vehicle_id: vehicle.id, limit: 60 } });
      const events = (payload(r)?.events) || [];
      setStory({ vehicle, ticket: tk, events, loading: false });
    } catch {
      setStory({ vehicle, ticket: tk, events: [], loading: false });
    }
  }, [ticketByVehicle]);

  const storyNodes = useMemo(() => {
    if (!story) return [];
    const evs = [...story.events].reverse(); // chronological
    return evs.map((ev, i) => {
      const s = String(ev.stage || ev.action || '').toLowerCase();
      let kind = 'done';
      if (s.includes('pause') || s.includes('released')) kind = 'pause';
      else if (s.includes('rent') ) kind = 'rental';
      if (i === evs.length - 1 && story.ticket?.is_open) kind = 'now';
      const bits = [];
      if (ev.actor_name) bits.push(`by <b>${ev.actor_name}</b>`);
      if (ev.odometer) bits.push(`odo ${Number(ev.odometer).toLocaleString()}`);
      if (ev.description && ev.description !== ev.action) bits.push(ev.description);
      return { kind, title: ev.action || ev.stage || 'Event', time: timeAgo(ev.occurred_at), meta: bits.join(' · ') };
    });
  }, [story]);

  /* ------------------------------ KPIs --------------------------------- */
  const fs = dash?.fleet_status || {};
  const downtimeSpark = (trends?.downtime || []).map((x) => x.value).slice(-12);
  const pausedCount = model.paused.length;
  const critRent = model.criticalOnRent.length;

  return (
    <div className="opx">
      <header className="opx-head">
        <h1>
          Fleet Operations Center
          <span className="live"><span className="d" />Live</span>
        </h1>
        <OpsClock />
        <div className="seg" style={{ marginLeft: 12 }}>
          <button className="on">Now</button>
          <button onClick={() => navigate('/')}>Classic</button>
        </div>
      </header>

      <div className="opx-body">
        {/* KPI STRIP */}
        <div className="opx-grid opx-c12" style={{ marginBottom: 16 }}>
          <div className="opx-span-2">
            <KpiTile tone="good" label="Available<br>for rent" value={loading ? '—' : (fs.available ?? 0)} foot="rental pool" footTone="up" />
          </div>
          <div className="opx-span-2">
            <KpiTile label="On rent<br>now" value={loading ? '—' : (fs.rented ?? 0)} foot={`${fs.booked ?? 0} reserved`} sparkColor="#60a5fa" />
          </div>
          <div className="opx-span-2">
            <KpiTile tone="warm" label="In active<br>maintenance" value={loading ? '—' : (dash?.cars_in_maintenance ?? 0)} foot="in the shop" spark={downtimeSpark.length ? downtimeSpark : undefined} sparkColor="#f5a524" />
          </div>
          <div className="opx-span-2">
            <KpiTile tone="warm" label="Paused for<br>rental" value={loading ? '—' : pausedCount} foot="earning · repair on hold" footTone="flat" />
          </div>
          <div className="opx-span-2">
            <KpiTile tone={critRent ? 'hot' : ''} label="Critical issue<br>on rent" value={loading ? '—' : critRent} foot={critRent ? 'safety watch' : 'clear'} footTone={critRent ? 'down' : 'up'} />
          </div>
          <div className="opx-span-2">
            <KpiTile tone={dash?.overdue_rentals ? 'hot' : ''} label="Overdue<br>rentals" value={loading ? '—' : (dash?.overdue_rentals ?? 0)} foot={dash?.overdue_maintenance ? `+${dash.overdue_maintenance} maint` : 'on time'} footTone={dash?.overdue_rentals ? 'down' : 'up'} />
          </div>
        </div>

        {/* ROW A: lanes + live feed */}
        <div className="opx-grid opx-c12" style={{ marginBottom: 16 }}>
          <div className="opx-span-8">
            <CommandPanel title="Operational Lanes" label="maintenance pipeline · dual-state" dotColor="#22d3ee"
              meta={`${board.counts?.open_total ?? 0} open · ${pausedCount} paused`} bodyFlush>
              <div style={{ padding: '14px 16px' }}>
                {loading ? <LaneSkeleton /> : <LaneBoard lanes={lanes} />}
              </div>
            </CommandPanel>
          </div>
          <div className="opx-span-4">
            <CommandPanel title="Live Activity" label="fleet event stream" dotColor="#34d399" meta={feed.length ? `${feed.length}` : ''}>
              {loading ? <FeedSkeleton /> : (
                <LiveActivityFeed
                  events={feed.slice(0, 40).map((ev) => ({ ...ev, time_label: timeAgo(ev.occurred_at) }))}
                  freshCount={3}
                  onEvent={(ev) => ev.vehicle_id && openStory(vehicles.find((x) => x.id === ev.vehicle_id) || { id: ev.vehicle_id, plate_no: ev.plate, model: ev.model })}
                />
              )}
            </CommandPanel>
          </div>
        </div>

        {/* ROW B: matrix + heatmap + scores */}
        <div className="opx-grid opx-c12">
          <div className="opx-span-5">
            <CommandPanel title="Live Status Matrix" label="maintenance × rental" dotColor="#8b7bfb"
              meta="two independent dimensions">
              <StatusMatrix
                rows={model.matrix.rows} cols={model.matrix.cols} data={model.matrix.data}
                flagCell={(r, c) => r === 'paused' && (c === 'avail' || c === 'rented')}
                hotCell={(r, c, n) => r === 'active' && c === 'rented' && n > 0}
              />
              <div className="opx-hint" style={{ marginTop: 12 }}>
                Cyan = the flagship state: a car <b style={{ color: '#22d3ee' }}>available or rented</b> while its repair is <b style={{ color: '#f5a524' }}>paused</b>. Maintenance is never overwritten by availability.
              </div>
            </CommandPanel>
          </div>
          <div className="opx-span-4">
            <CommandPanel title="Fleet Heatmap" label={`${vehicles.length} vehicles`} dotColor="#60a5fa">
              {loading ? <div className="opx-skel" style={{ height: 180 }} /> : (
                <FleetHeatmap cells={model.heat} onCell={(c) => c.model && openStory(c.model)} />
              )}
            </CommandPanel>
          </div>
          <div className="opx-span-3">
            <CommandPanel title="Readiness Scores" label="fleet composite" dotColor="#22d3ee">
              <div style={{ display: 'flex', justifyContent: 'space-around', gap: 6, padding: '8px 0 4px' }}>
                <ScoreRing value={model.fleetHealth} label="Health" size={78} />
                <ScoreRing value={model.fleetRental} label="Rental" size={78} />
                <ScoreRing value={model.fleetMaint} label="Maint" size={78} />
              </div>
              <div className="opx-hint" style={{ marginTop: 10, textAlign: 'center' }}>
                Composite of condition grade, deferred defects &amp; live workflow stage.
              </div>
            </CommandPanel>
          </div>
        </div>
      </div>

      {/* VEHICLE OPERATIONAL STORY DRAWER */}
      <ContextualDrawer
        open={!!story}
        onClose={() => setStory(null)}
        title={story ? (story.vehicle.plate_no || story.vehicle.plate || `Vehicle ${story.vehicle.id}`) : ''}
        subtitle={story ? (story.vehicle.model || story.vehicle.make || 'Operational story') : ''}
        footer={story ? (
          <>
            <button className="opx-btn primary" onClick={() => navigate(`/vehicles/${story.vehicle.id}`)}>Open vehicle profile</button>
            {story.ticket ? <button className="opx-btn" onClick={() => navigate(`/maintenance-workflow/${story.ticket.id}`)}>Open ticket</button> : null}
          </>
        ) : null}
      >
        {story ? (
          <>
            <div style={{ marginBottom: 16 }}>
              <DualStateBadge
                row
                showNone
                vehicle={{
                  operational_status: story.vehicle.operational_status,
                  available: story.vehicle.available, rented: story.vehicle.rented, reserved: story.vehicle.reserved,
                  is_deferred_maintenance: story.vehicle.is_deferred_maintenance,
                  maintenance_state: story.ticket?.workflow_status || (story.vehicle.is_deferred_maintenance ? 'paused_returned_to_service' : 'none'),
                  fault_severity: story.ticket?.fault_severity,
                }}
              />
            </div>
            {story.vehicle.is_deferred_maintenance && (
              <div style={{ border: '1px solid rgba(245,165,36,.3)', background: 'linear-gradient(90deg,rgba(245,165,36,.08),transparent)', borderRadius: 12, padding: '12px 14px', marginBottom: 16 }}>
                <div style={{ fontFamily: 'var(--mono)', fontSize: 10.5, letterSpacing: '.1em', textTransform: 'uppercase', color: 'var(--paused)' }}>Maintenance Paused · Deferred Defect</div>
                <div style={{ fontSize: 13.5, color: 'var(--ink)', marginTop: 5 }}>{story.vehicle.deferred_maintenance_reason || 'Repair on hold — vehicle released to service.'}</div>
              </div>
            )}
            <div style={{ display: 'flex', gap: 16, justifyContent: 'space-around', marginBottom: 18 }}>
              <ScoreRing value={healthScore(story.vehicle, story.ticket)} label="Health" size={64} />
              <ScoreRing value={rentalReadiness(story.vehicle)} label="Rental" size={64} />
              <ScoreRing value={maintReadiness(story.vehicle, story.ticket)} label="Maint" size={64} />
            </div>
            <div style={{ fontFamily: 'var(--mono)', fontSize: 10.5, letterSpacing: '.1em', textTransform: 'uppercase', color: 'var(--ink-3)', marginBottom: 10 }}>Operational Story</div>
            {story.loading ? <div className="opx-skel" style={{ height: 200 }} />
              : storyNodes.length ? <JourneyMap nodes={storyNodes} />
              : <div className="opx-empty">No recorded events for this vehicle yet.</div>}
          </>
        ) : null}
      </ContextualDrawer>
    </div>
  );
}

/* ------------------------------ skeletons ------------------------------ */
function LaneSkeleton() {
  return (
    <div className="opx-lanes">
      {[0, 1, 2, 3].map((i) => (
        <div className="opx-lane" key={i}>
          <div className="opx-skel" style={{ height: 34, marginBottom: 10 }} />
          <div className="opx-skel" style={{ height: 92, marginBottom: 9 }} />
          <div className="opx-skel" style={{ height: 92 }} />
        </div>
      ))}
    </div>
  );
}
function FeedSkeleton() {
  return <div>{[0, 1, 2, 3, 4, 5].map((i) => <div key={i} className="opx-skel" style={{ height: 40, marginBottom: 6 }} />)}</div>;
}
