import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { usePermissions } from '../hooks/usePermissions';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import SearchSelect from '../components/ui/SearchSelect';
import { Select } from '../components/ui/Field';
import WorkshopEvents from '../components/WorkshopEvents';
import { PageHeader, EmptyState } from '../components/ui/Misc';
import { SectionCard } from '../components/ui/Table';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import { MetricGridSkeleton, Skeleton } from '../components/ui/Skeleton';
import { InfoTip } from '../components/ui/Tooltip';
import Icon from '../components/ui/Icon';
import Modal from '../components/ui/Modal';
import { usePageStat } from '../components/PageStat';
import { aed2, fmtDate, num } from '../lib/format';

// Traffic-light styling per SLA status (timeliness).
const LIGHT = {
  on_track: { dot: 'bg-emerald-500', text: 'On track', tone: 'green' },
  at_risk: { dot: 'bg-amber-500', text: 'At risk', tone: 'amber' },
  breached: { dot: 'bg-red-500', text: 'Overdue', tone: 'red' },
  // The linked visit already came back (latest sheet event = IN) — closed, never overdue.
  returned: { dot: 'bg-sky-500', text: 'Returned', tone: 'sky' },
  // No maintenance record is strictly linked to this contract (no stale visit borrowed).
  no_log: { dot: 'bg-gray-300', text: 'No log', tone: 'gray' },
  unknown: { dot: 'bg-gray-300', text: '—', tone: 'gray' },
};

// Severity classification per maintenance situation (reason -> status, from the sheet).
const PRIORITY = {
  critical: { label: 'Critical', tone: 'red', emoji: '🔴' },
  special: { label: 'Special', tone: 'violet', emoji: '🟣' },
  minor: { label: 'Minor', tone: 'amber', emoji: '🟡' },
  routine: { label: 'Routine', tone: 'green', emoji: '🟢' },
};

// Live workshop stage (sheet "OUT/IN" event log): where the car is in the repair flow.
// Labels mirror the raw sheet event_status (OUT / IN / Test / Follow up …); only the
// colour is added. 'unknown' is the synthetic "no linked event" case.
const STAGE = {
  OUT: { label: 'OUT', tone: 'blue' },
  IN: { label: 'IN', tone: 'green' },
  'Follow up': { label: 'Follow up', tone: 'amber' },
  Change: { label: 'Change', tone: 'violet' },
  Delay: { label: 'Delay', tone: 'red' },
  Test: { label: 'Test', tone: 'cyan' },
  'Under Test': { label: 'Under Test', tone: 'cyan' },
  unknown: { label: 'No log', tone: 'gray' },
};
const stageInfo = (s) => STAGE[s] || { label: s, tone: 'slate' };

// Short codes for the compact garage-history timeline (the "ping-pong" sequence).
const STAGE_ABBR = { OUT: 'OUT', IN: 'IN', 'Follow up': 'FU', Change: 'CHG', Delay: 'DLY', Test: 'TST', 'Under Test': 'TST' };
const abbr = (s) => STAGE_ABBR[s] || s;

// Compact "out → back → out again" history shown under the current stage. Collapses
// long chains (first 2 … last 2) and exposes the full dated sequence on hover.
function PingPong({ events }) {
  if (!events || events.length < 2) return null;
  const codes = events.map((e) => abbr(e.stage));
  const shown = codes.length > 4 ? [...codes.slice(0, 2), '…', ...codes.slice(-2)] : codes;
  const full = events
    .map((e) => `${e.stage} · out ${e.out_date || '—'}${e.actual_in_date ? ` · in ${e.actual_in_date}` : ''}`)
    .join('\n');
  return (
    <div className="mt-1 max-w-[150px] text-[10px] leading-tight text-gray-400" title={full}>
      {shown.join(' → ')} <span className="text-gray-300">({codes.length})</span>
    </div>
  );
}

// Full "what happened in the sheet" log for one car — the whole linked event sequence
// (oldest→newest) with each event's garage, services and progress note. Opened by
// clicking the Stage cell on the board.
function GarageLog({ car, onClose }) {
  return (
    <Modal
      open={!!car}
      onClose={onClose}
      size="xl"
      title={car ? `Garage log · ${car.plate || `#${car.contract_no || car.id}`}` : ''}
      subtitle={car ? [car.car, car.contract_no && `contract #${car.contract_no}`].filter(Boolean).join(' · ') : ''}
    >
      {car && (car.events && car.events.length > 0 ? (
        <ol className="space-y-3">
          {car.events.map((e, i) => {
            const si = stageInfo(e.stage);
            return (
              <li key={i} className="rounded-xl border border-gray-100 p-3">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                  <span className="font-medium text-gray-400">{i + 1}.</span>
                  <Badge tone={si.tone}>{si.label}</Badge>
                  {e.out_date && <span className="text-gray-500">out {fmtDate(e.out_date)}</span>}
                  {e.expected_return_date && <span className="text-gray-400">· due {fmtDate(e.expected_return_date)}</span>}
                  {e.actual_in_date && <span className="text-emerald-600">· back {fmtDate(e.actual_in_date)}</span>}
                  {e.garage && <span className="text-gray-400">· {e.garage}</span>}
                  {e.cost ? <span className="ml-auto font-medium text-gray-600">{aed2(e.cost)}</span> : null}
                </div>
                {e.services && e.services.length > 0 && (
                  <div className="mt-1.5 flex flex-wrap gap-1">
                    {e.services.map((t) => <Badge key={t} tone="indigo">{t}</Badge>)}
                  </div>
                )}
                {e.notes && <p className="mt-1.5 whitespace-pre-wrap text-xs leading-relaxed text-gray-600">{e.notes}</p>}
              </li>
            );
          })}
        </ol>
      ) : (
        <EmptyState title="No sheet log" message="No maintenance-sheet events are linked to this contract." />
      ))}
    </Modal>
  );
}

// Full per-car workshop-events CRUD (add / edit / delete), reusing the same
// <WorkshopEvents> timeline used on the contract detail page. In "car" mode (a
// row's Manage button) it opens straight onto that row's CONTRACT; with no car
// (the page's "+ Add event" button) it asks which car AND which contract/visit
// to log against, so each event is tied to a specific maintenance contract.
function ManageModal({ open, car, vehicles, onClose }) {
  const [vehicleId, setVehicleId] = useState('');
  const [contractId, setContractId] = useState('');     // '' = whole car (all visits)
  const [contracts, setContracts] = useState([]);
  const [loadingContracts, setLoadingContracts] = useState(false);

  // Reset selections each time the modal opens / switches target. A board row IS a
  // contract, so scope straight to it; the page-level add starts with nothing chosen.
  useEffect(() => {
    if (!open) return;
    setVehicleId(car?.vehicle_id ? String(car.vehicle_id) : '');
    // A contract-less garage card has a synthetic id ('m<vid>'), not a real contract — scope the
    // manage view to the whole car so it doesn't pass a bogus contract id to the events API.
    setContractId(car?.is_contract === false ? '' : (car?.id ? String(car.id) : ''));
    setContracts([]);
  }, [open, car]);

  // Page-level add: once a car is chosen, load its maintenance contracts so the user
  // can say which visit the event belongs to.
  useEffect(() => {
    if (!open || car || !vehicleId) { setContracts([]); return; }
    let alive = true;
    setLoadingContracts(true);
    setContractId('');
    api.get('/Contract', { params: { vehicle_id: vehicleId, contract_type: 'U' } })
      .then(({ data }) => { if (alive) setContracts(data.data?.items || []); })
      .catch(() => { if (alive) setContracts([]); })
      .finally(() => { if (alive) setLoadingContracts(false); });
    return () => { alive = false; };
  }, [open, car, vehicleId]);

  const carOptions = useMemo(
    () => vehicles.map((v) => ({
      id: v.id,
      label: v.plate_no || `#${v.id}`,
      sub: [v.make, v.model].filter(Boolean).join(' '),
    })),
    [vehicles],
  );

  // The contract whose window scopes the log + seeds the new-event dates: the board row
  // in row mode, or the picked one in add mode.
  const chosenContract = car || contracts.find((c) => String(c.id) === String(contractId)) || null;

  const title = car
    ? `Manage maintenance · ${car.plate || `#${car.contract_no || car.id}`}`
    : 'Add maintenance event';
  const subtitle = car
    ? [car.car, car.contract_no && `contract #${car.contract_no}`].filter(Boolean).join(' · ')
    : 'Pick a car and the contract/visit, then log or edit its workshop events';

  return (
    <Modal open={open} onClose={onClose} size="xl" title={title} subtitle={subtitle}>
      {!car && (
        <div className="mb-4 grid gap-4 sm:grid-cols-2">
          <div>
            <span className="mb-1 block text-sm font-medium text-gray-700">Car</span>
            <SearchSelect value={vehicleId} onChange={setVehicleId} options={carOptions} placeholder="Search plate / make / model…" />
          </div>
          {vehicleId && (
            <div>
              <span className="mb-1 block text-sm font-medium text-gray-700">Contract / visit</span>
              <Select value={contractId} onChange={(e) => setContractId(e.target.value)} disabled={loadingContracts}>
                <option value="">{loadingContracts ? 'Loading contracts…' : 'Whole car — all visits'}</option>
                {contracts.map((co) => (
                  <option key={co.id} value={co.id}>
                    #{co.contract_no || co.id} · {co.out_date ? fmtDate(co.out_date) : '—'}{co.in_date ? ` → ${fmtDate(co.in_date)}` : ' → open'} · {co.state}
                  </option>
                ))}
              </Select>
              {!loadingContracts && contracts.length === 0 && (
                <p className="mt-1 text-xs text-gray-400">No maintenance contracts for this car — logging against the whole car.</p>
              )}
            </div>
          )}
        </div>
      )}
      {vehicleId ? (
        <WorkshopEvents
          key={`${vehicleId}:${contractId}`}
          vehicleId={Number(vehicleId)}
          contractId={contractId ? Number(contractId) : undefined}
          defaultDate={chosenContract?.out_date}
          expectedReturn={chosenContract?.expected_return_date}
        />
      ) : (
        <p className="py-8 text-center text-sm text-gray-400">Choose a car above to see and manage its workshop events.</p>
      )}
    </Modal>
  );
}

// One labelled cell in a card's facts grid.
function Fact({ label, hint, children }) {
  return (
    <div className="min-w-0">
      <dt className="text-[10px] font-semibold uppercase tracking-wide text-gray-400">
        {label}
        {hint && <span className="ml-1 font-normal normal-case text-gray-300">· {hint}</span>}
      </dt>
      <dd className="mt-0.5 truncate text-xs font-medium text-gray-700">{children}</dd>
    </div>
  );
}

// One car in the garage, as a card. Replaces a single dense table row — same data,
// same interactions (click Stage → garage log, Manage → events CRUD), laid out for
// scanning instead of side-scrolling. `onLog`/`onManage` mirror the old row buttons.
function MaintenanceCard({ c, canManage, onLog, onManage }) {
  const light = LIGHT[c.status] || LIGHT.unknown;
  const prio = PRIORITY[c.priority] || PRIORITY.routine;
  const stg = stageInfo(c.stage || 'unknown');
  const hasEvents = c.events && c.events.length > 0;

  return (
    <div className="group relative flex flex-col overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-soft ring-1 ring-slate-900/5 transition hover:-translate-y-0.5 hover:shadow-md">
      {/* Left accent bar = SLA status traffic light */}
      <span className={`absolute inset-y-0 left-0 w-1.5 ${light.dot}`} />

      <div className="flex flex-col gap-3 p-4 pl-5">
        {/* Header: plate + car, with priority on the right */}
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-1.5">
              <Link
                to={c.is_contract === false ? `/vehicles/${c.vehicle_id}` : `/contracts/${c.id}`}
                className="text-base font-semibold text-indigo-600 hover:text-indigo-700"
              >
                {c.plate || (c.is_contract === false ? `#${c.vehicle_id}` : `#${c.contract_no || c.id}`)}
              </Link>
              {c.is_contract === false && (
                <Badge tone="amber" title="In the garage on a logged workshop event — no maintenance contract">log</Badge>
              )}
              {c.type && <Badge tone="slate">{c.type}</Badge>}
            </div>
            <p className="mt-0.5 truncate text-xs text-gray-400">{c.car || '—'}</p>
          </div>
          <span
            className="shrink-0"
            title={c.priority_matched ? `Matched keyword: "${c.priority_matched}"` : 'No critical/minor keywords — treated as routine'}
          >
            <Badge tone={prio.tone}>{prio.emoji} {prio.label}</Badge>
          </span>
        </div>

        {/* Status + live stage + days out */}
        <div className="flex flex-wrap items-center gap-2">
          <span className="inline-flex items-center gap-1.5 rounded-full bg-gray-50 px-2.5 py-1 ring-1 ring-inset ring-gray-200">
            <span className={`h-2 w-2 rounded-full ${light.dot}`} />
            <span className="text-xs font-medium text-gray-600">{light.text}</span>
            {c.overdue_days > 0 && <span className="text-xs font-semibold text-red-500">+{c.overdue_days}d</span>}
          </span>
          <button
            type="button"
            onClick={() => onLog(c)}
            className="group/stage inline-flex items-center gap-1.5"
            title="Click to see the full garage log from the sheet"
          >
            <Badge tone={stg.tone}>{stg.label}</Badge>
            {hasEvents && (
              <span className="text-[10px] font-medium text-indigo-500 opacity-0 transition group-hover/stage:opacity-100">
                log →
              </span>
            )}
          </button>
          {c.days_out != null && (
            <span className="ml-auto text-xs font-medium text-gray-500">{c.days_out}d out</span>
          )}
        </div>
        <PingPong events={c.events} />

        {/* Issues + note */}
        <div>
          <div className="flex flex-wrap gap-1">
            {(c.issues || []).slice(0, 6).map((t) => <Badge key={t} tone="indigo">{t}</Badge>)}
            {(!c.issues || c.issues.length === 0) && <span className="text-xs text-gray-300">No issues logged</span>}
          </div>
          {c.notes && (
            <p className="mt-1.5 line-clamp-2 text-xs leading-relaxed text-gray-400" title={c.notes}>{c.notes}</p>
          )}
        </div>

        {/* Facts grid */}
        <dl className="grid grid-cols-2 gap-x-4 gap-y-2.5 border-t border-gray-100 pt-3">
          <Fact label="Garage">{c.garage || <span className="text-gray-300">—</span>}</Fact>
          <Fact label="Cost">{c.cost ? aed2(c.cost) : <span className="text-gray-300">—</span>}</Fact>
          <Fact label="Out" hint="API">{c.out_date ? fmtDate(c.out_date) : <span className="text-gray-300">—</span>}</Fact>
          <Fact label="In" hint="API">{c.in_date ? fmtDate(c.in_date) : <span className="text-gray-300">—</span>}</Fact>
          <Fact label="Due" hint="sheet">{c.due_sheet ? fmtDate(c.due_sheet) : <span className="text-gray-300">—</span>}</Fact>
          <Fact label="Back" hint="sheet">{c.sheet_back ? fmtDate(c.sheet_back) : <span className="text-gray-300">—</span>}</Fact>
          <div className="col-span-2 min-w-0">
            <dt className="text-[10px] font-semibold uppercase tracking-wide text-gray-400">
              Net margin <span className="font-normal normal-case text-gray-300">· car lifetime</span>
            </dt>
            <dd className="mt-0.5">
              {c.net_margin != null ? (
                <span
                  className={`text-sm font-semibold ${c.net_margin >= 0 ? 'text-emerald-600' : 'text-red-600'}`}
                  title={`Income ${aed2(c.vehicle_income || 0)}  −  maintenance ${aed2(c.vehicle_maintenance_cost || 0)}`}
                >
                  {c.net_margin >= 0 ? '+' : '−'}{aed2(Math.abs(c.net_margin))}
                </span>
              ) : <span className="text-xs text-gray-300">—</span>}
            </dd>
          </div>
        </dl>

        {canManage && (
          <div className="flex justify-end border-t border-gray-100 pt-3">
            <button
              type="button"
              onClick={() => onManage(c)}
              className="inline-flex items-center gap-1 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-600 transition hover:bg-indigo-100"
            >
              Manage events
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

// The board body (summary KPIs + priority filter + table), without the page
// chrome — reused both on its own page and embedded on the Dashboard so the
// two never drift apart.
export function MaintenanceBoardPanel({ publishStat = false, manageable = false }) {
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/Maintenance/board');
    return data.data;
  }, []);
  const { data, loading, error, reload } = useFetch(fetcher);
  const { can } = usePermissions();
  const canManage = manageable && can('maintenance.manage');

  const [priority, setPriority] = useState(''); // '', 'critical', 'special', 'minor', 'routine'
  const [stage, setStage] = useState('');       // '', 'OUT', 'IN', 'Follow up', …
  const [logCar, setLogCar] = useState(null);   // car whose full garage log is open
  const [manage, setManage] = useState(null);   // { car } (row) | { pick: true } (page-level add) | null

  // Vehicles for the "add against any car" picker — fetched lazily on first add.
  const [vehicles, setVehicles] = useState([]);
  const [vehiclesLoaded, setVehiclesLoaded] = useState(false);
  const ensureVehicles = useCallback(async () => {
    if (vehiclesLoaded) return;
    try {
      const { data: v } = await api.get('/Vehicle');
      setVehicles(v.data || []);
    } catch { /* picker just stays empty */ }
    setVehiclesLoaded(true);
  }, [vehiclesLoaded]);

  // Closing the manage modal re-pulls the board — an edit may have changed a car's
  // latest stage, priority, garage or cost.
  const closeManage = useCallback(() => { setManage(null); reload(); }, [reload]);
  const openAdd = () => { ensureVehicles(); setManage({ pick: true }); };

  // Floating page gauge: share of in-garage cars that are critical priority.
  // Only the standalone page publishes (publishStat); the Dashboard embed stays
  // silent so it doesn't fight the Dashboard's own utilization gauge.
  const totalCars = data?.cars?.length || 0;
  const criticalCars = data?.summary?.critical || 0;
  usePageStat(publishStat ? {
    percent: totalCars ? (criticalCars / totalCars) * 100 : null,
    label: 'Critical',
    color: 'red',
    hint: `${criticalCars} of ${totalCars} cars in the garage are critical priority`,
  } : {});

  if (loading) {
    return (
      <div className="space-y-6">
        <MetricGridSkeleton count={4} />
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          {Array.from({ length: 6 }).map((_, i) => (
            <Skeleton key={i} className="h-72 rounded-2xl" />
          ))}
        </div>
      </div>
    );
  }

  const cars = data?.cars || [];
  const s = data?.summary || {};
  const stages = s.stages || {};
  const shown = cars.filter(
    (c) => (!priority || c.priority === priority) && (!stage || (c.stage || 'unknown') === stage)
  );

  const priorityFilters = [
    { key: '', label: 'All', n: cars.length },
    { key: 'critical', label: '🔴 Critical', n: s.critical },
    { key: 'special', label: '🟣 Special', n: s.special },
    { key: 'minor', label: '🟡 Minor', n: s.minor },
    { key: 'routine', label: '🟢 Routine', n: s.routine },
  ];

  // Workshop-stage filter chips, driven by whatever stages are actually present.
  const stageFilters = [
    { key: '', label: 'All', n: cars.length },
    ...Object.entries(stages)
      .sort((a, b) => b[1] - a[1])
      .map(([key, n]) => ({ key, label: stageInfo(key).label, n })),
  ];

  return (
    <div className="space-y-6">
        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        {canManage && (
          <div className="flex items-center justify-end">
            <Button onClick={openAdd}>+ Add event</Button>
          </div>
        )}

        {/* SLA timeliness summary (timeliness = is each car back on time?) */}
        <MetricGrid cols={4}>
          <MetricCard
            label="In maintenance"
            value={num(s.total)}
            tone="slate"
            icon={<Icon.Wrench className="h-5 w-5" />}
            hint="Cars currently in the garage"
            tooltip="Total cars with an open maintenance contract or a live workshop event."
          />
          <MetricCard
            label="On track"
            value={num(s.on_track)}
            tone="emerald"
            icon={<Icon.Check className="h-5 w-5" />}
            hint="Within expected return window"
            tooltip="Cars whose repair is still inside its expected return window (SLA on track)."
          />
          <MetricCard
            label="At risk"
            value={num(s.at_risk)}
            tone="amber"
            icon={<Icon.Clock className="h-5 w-5" />}
            hint="Nearing the return deadline"
            tooltip="Cars approaching their expected return date — at risk of breaching the SLA."
          />
          <MetricCard
            label="Overdue"
            value={num(s.breached)}
            tone="red"
            icon={<Icon.Alert className="h-5 w-5" />}
            hint="Past expected return"
            tooltip="Cars past their expected return date — the maintenance SLA is breached."
          />
        </MetricGrid>

        <SectionCard
          title="In the garage"
          subtitle={`${num(shown.length)} of ${num(cars.length)} cars shown`}
          actions={
            <span className="hidden items-center gap-1.5 text-xs text-slate-400 sm:inline-flex">
              <Icon.Filter className="h-4 w-4" />
              Filter by priority &amp; stage
            </span>
          }
          bodyClass="p-4 sm:p-5 space-y-4"
        >
          {/* Priority filter (severity of the maintenance situation) */}
          <div className="flex flex-wrap items-center gap-2">
            <span className="mr-1 inline-flex items-center gap-1 text-xs font-medium uppercase tracking-wide text-slate-500">
              Priority
              <InfoTip content="Severity of the maintenance situation, derived from the sheet reason keywords (critical / special / minor / routine)." />
            </span>
            {priorityFilters.map((f) => (
              <button
                key={f.key || 'all'}
                onClick={() => setPriority(f.key)}
                className={`rounded-full px-3 py-1 text-xs font-medium ring-1 transition ${
                  priority === f.key
                    ? 'bg-indigo-600 text-white ring-indigo-600'
                    : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'
                }`}
              >
                {f.label}{f.n != null ? ` (${num(f.n)})` : ''}
              </button>
            ))}
          </div>

          {/* Workshop-stage filter (live OUT / IN / Follow-up flow from the maintenance log) */}
          <div className="flex flex-wrap items-center gap-2">
            <span className="mr-1 inline-flex items-center gap-1 text-xs font-medium uppercase tracking-wide text-slate-500">
              Stage
              <InfoTip content="Live workshop stage from the maintenance-sheet event log (OUT / IN / Follow up …) — where the car is in the repair flow." />
            </span>
            {stageFilters.map((f) => (
              <button
                key={f.key || 'all'}
                onClick={() => setStage(f.key)}
                className={`rounded-full px-3 py-1 text-xs font-medium ring-1 transition ${
                  stage === f.key
                    ? 'bg-indigo-600 text-white ring-indigo-600'
                    : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'
                }`}
              >
                {f.label}{f.n != null ? ` (${num(f.n)})` : ''}
              </button>
            ))}
          </div>

          {shown.length > 0 ? (
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
              {shown.map((c) => (
                <MaintenanceCard
                  key={c.id}
                  c={c}
                  canManage={canManage}
                  onLog={setLogCar}
                  onManage={(car) => setManage({ car })}
                />
              ))}
            </div>
          ) : (
            <EmptyState
              icon={<Icon.Wrench className="h-7 w-7" />}
              title={cars.length === 0 ? 'No cars in maintenance' : `No ${priority || 'matching'} maintenance`}
              message={cars.length === 0 ? 'Nothing is currently in the garage.' : 'No cars match the selected filters.'}
            />
          )}
        </SectionCard>

        <GarageLog car={logCar} onClose={() => setLogCar(null)} />

        {canManage && (
          <ManageModal
            open={!!manage}
            car={manage?.car || null}
            vehicles={vehicles}
            onClose={closeManage}
          />
        )}
    </div>
  );
}

export default function MaintenanceBoard() {
  return (
    <div className="py-8">
      <div className="mx-auto max-w-[1700px] space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title="Maintenance Board" subtitle="Cars currently in the garage — due dates, issues, cost and live status.">
          <Link to="/maintenance-analytics" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">Cost Analytics →</Link>
        </PageHeader>
        <MaintenanceBoardPanel publishStat manageable />
      </div>
    </div>
  );
}
