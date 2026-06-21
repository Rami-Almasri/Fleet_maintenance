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
import { Card, PageHeader, Spinner, EmptyState } from '../components/ui/Misc';
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
    setContractId(car?.id ? String(car.id) : '');
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

function Kpi({ label, value, tone }) {
  return (
    <div className="rounded-2xl border border-gray-100 bg-white px-5 py-4 shadow-sm ring-1 ring-gray-900/5">
      <p className="text-xs font-medium text-gray-500">{label}</p>
      <p className={`mt-1 text-2xl font-bold tracking-tight ${tone}`}>{value}</p>
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

  if (loading) return <div className="flex justify-center py-24"><Spinner className="h-8 w-8" /></div>;

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

        {/* SLA timeliness summary */}
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <Kpi label="In maintenance" value={num(s.total)} tone="text-gray-900" />
          <Kpi label="🟢 On track" value={num(s.on_track)} tone="text-emerald-600" />
          <Kpi label="🟡 At risk" value={num(s.at_risk)} tone="text-amber-600" />
          <Kpi label="🔴 Overdue" value={num(s.breached)} tone="text-red-600" />
        </div>

        {/* Priority filter (severity of the maintenance situation) */}
        <div className="flex flex-wrap items-center gap-2">
          <span className="mr-1 text-xs font-medium uppercase tracking-wide text-gray-500">Priority</span>
          {priorityFilters.map((f) => (
            <button
              key={f.key || 'all'}
              onClick={() => setPriority(f.key)}
              className={`rounded-full px-3 py-1 text-xs font-medium ring-1 transition ${
                priority === f.key
                  ? 'bg-indigo-600 text-white ring-indigo-600'
                  : 'bg-white text-gray-600 ring-gray-300 hover:bg-gray-50'
              }`}
            >
              {f.label}{f.n != null ? ` (${num(f.n)})` : ''}
            </button>
          ))}
        </div>

        {/* Workshop-stage filter (live OUT / IN / Follow-up flow from the maintenance log) */}
        <div className="flex flex-wrap items-center gap-2">
          <span className="mr-1 text-xs font-medium uppercase tracking-wide text-gray-500">Stage</span>
          {stageFilters.map((f) => (
            <button
              key={f.key || 'all'}
              onClick={() => setStage(f.key)}
              className={`rounded-full px-3 py-1 text-xs font-medium ring-1 transition ${
                stage === f.key
                  ? 'bg-indigo-600 text-white ring-indigo-600'
                  : 'bg-white text-gray-600 ring-gray-300 hover:bg-gray-50'
              }`}
            >
              {f.label}{f.n != null ? ` (${num(f.n)})` : ''}
            </button>
          ))}
        </div>

        <Card>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-100 text-sm">
              <thead className="bg-gray-50/60">
                <tr className="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                  <th className="whitespace-nowrap px-3 py-3">Status</th>
                  <th className="whitespace-nowrap px-3 py-3">Stage</th>
                  <th className="whitespace-nowrap px-3 py-3">Priority</th>
                  <th className="whitespace-nowrap px-3 py-3">Car</th>
                  <th className="whitespace-nowrap px-3 py-3">Type</th>
                  <th className="whitespace-nowrap px-3 py-3">Garage</th>
                  <th className="px-3 py-3">Issues</th>
                  <th className="whitespace-nowrap px-3 py-3">Out <span className="font-normal normal-case text-gray-400">· API</span></th>
                  <th className="whitespace-nowrap px-3 py-3">In <span className="font-normal normal-case text-gray-400">· API</span></th>
                  <th className="whitespace-nowrap px-3 py-3">Due <span className="font-normal normal-case text-gray-400">· sheet</span></th>
                  <th className="whitespace-nowrap px-3 py-3">Back <span className="font-normal normal-case text-gray-400">· sheet</span></th>
                  <th className="whitespace-nowrap px-3 py-3 text-center">Days out</th>
                  <th className="whitespace-nowrap px-3 py-3 text-right">Cost</th>
                  <th className="whitespace-nowrap px-3 py-3 text-right">Net margin <span className="font-normal normal-case text-gray-400">· car lifetime</span></th>
                  {canManage && <th className="whitespace-nowrap px-3 py-3 text-right">Manage</th>}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {shown.map((c) => {
                  const light = LIGHT[c.status] || LIGHT.unknown;
                  const prio = PRIORITY[c.priority] || PRIORITY.routine;
                  const stg = stageInfo(c.stage || 'unknown');
                  return (
                    <tr key={c.id} className="hover:bg-gray-50/60">
                      <td className="px-3 py-3">
                        <span className="inline-flex items-center gap-2">
                          <span className={`h-2.5 w-2.5 rounded-full ${light.dot}`} />
                          <span className="text-xs font-medium text-gray-600">{light.text}</span>
                          {c.overdue_days > 0 && <span className="text-xs text-red-500">+{c.overdue_days}d</span>}
                        </span>
                      </td>
                      <td className="px-3 py-3">
                        <button
                          type="button"
                          onClick={() => setLogCar(c)}
                          className="group text-left"
                          title="Click to see the full garage log from the sheet"
                        >
                          <Badge tone={stg.tone}>{stg.label}</Badge>
                          <PingPong events={c.events} />
                          {c.events && c.events.length > 0 && (
                            <span className="mt-0.5 block text-[10px] font-medium text-indigo-500 opacity-0 transition group-hover:opacity-100">
                              View log →
                            </span>
                          )}
                        </button>
                      </td>
                      <td className="px-3 py-3">
                        <span title={c.priority_matched ? `Matched keyword: "${c.priority_matched}"` : 'No critical/minor keywords — treated as routine'}>
                          <Badge tone={prio.tone}>{prio.emoji} {prio.label}</Badge>
                        </span>
                      </td>
                      <td className="px-3 py-3">
                        <Link to={`/contracts/${c.id}`} className="font-medium text-indigo-600 hover:text-indigo-700">{c.plate || `#${c.contract_no || c.id}`}</Link>
                        <div className="text-xs text-gray-400">{c.car || '—'}</div>
                      </td>
                      <td className="px-3 py-3">
                        {c.type ? <Badge tone="slate">{c.type}</Badge> : <span className="text-gray-300">—</span>}
                      </td>
                      <td className="px-3 py-3 text-gray-700">{c.garage || <span className="text-gray-300">—</span>}</td>
                      <td className="px-3 py-3">
                        <div className="flex flex-wrap gap-1">
                          {(c.issues || []).slice(0, 4).map((t) => <Badge key={t} tone="indigo">{t}</Badge>)}
                          {(!c.issues || c.issues.length === 0) && <span className="text-xs text-gray-300">—</span>}
                        </div>
                        {c.notes && (
                          <p className="mt-1 max-w-xs truncate text-xs text-gray-400" title={c.notes}>{c.notes}</p>
                        )}
                      </td>
                      <td className="whitespace-nowrap px-3 py-3 text-gray-500">{c.out_date ? fmtDate(c.out_date) : '—'}</td>
                      <td className="whitespace-nowrap px-3 py-3 text-gray-500">{c.in_date ? fmtDate(c.in_date) : <span className="text-gray-300">—</span>}</td>
                      <td className="whitespace-nowrap px-3 py-3 text-gray-500">{c.due_sheet ? fmtDate(c.due_sheet) : <span className="text-gray-300">—</span>}</td>
                      <td className="whitespace-nowrap px-3 py-3 text-gray-500">{c.sheet_back ? fmtDate(c.sheet_back) : <span className="text-gray-300">—</span>}</td>
                      <td className="whitespace-nowrap px-3 py-3 text-center font-medium text-gray-700">{c.days_out != null ? `${c.days_out}d` : '—'}</td>
                      <td className="whitespace-nowrap px-3 py-3 text-right text-gray-700">{c.cost ? aed2(c.cost) : '—'}</td>
                      <td className="whitespace-nowrap px-3 py-3 text-right">
                        {c.net_margin != null ? (
                          <span
                            className={`font-semibold ${c.net_margin >= 0 ? 'text-emerald-600' : 'text-red-600'}`}
                            title={`Income ${aed2(c.vehicle_income || 0)}  −  maintenance ${aed2(c.vehicle_maintenance_cost || 0)}`}
                          >
                            {c.net_margin >= 0 ? '+' : '−'}{aed2(Math.abs(c.net_margin))}
                          </span>
                        ) : <span className="text-gray-300">—</span>}
                      </td>
                      {canManage && (
                        <td className="whitespace-nowrap px-3 py-3 text-right">
                          <button
                            type="button"
                            onClick={() => setManage({ car: c })}
                            className="text-xs font-medium text-indigo-600 hover:text-indigo-700"
                          >
                            Manage
                          </button>
                        </td>
                      )}
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          {shown.length === 0 && (
            <EmptyState
              title={cars.length === 0 ? 'No cars in maintenance' : `No ${priority} maintenance`}
              message={cars.length === 0 ? 'Nothing is currently in the garage.' : 'No cars match this priority filter.'}
            />
          )}
        </Card>

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
