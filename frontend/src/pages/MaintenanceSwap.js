import { useCallback, useEffect, useReducer, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { PageHeader } from '../components/ui/Misc';
import { Skeleton } from '../components/ui/Skeleton';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import Modal from '../components/ui/Modal';
import Button from '../components/ui/Button';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import { useToast } from '../components/ui/Toast';
import { aed2, num } from '../lib/format';

/*
 * Maintenance Swap  (LIVE DATA · SIMULATED ACTIONS)
 * ==========================================================
 * A three-column ops board for the maintenance-swap workflow, SEEDED FROM LIVE DATA
 * (GET /maintenance-swaps/board):
 *   1. Action Required  — Foresight 'Fix now' cars, each tagged WITH CUSTOMER or AVAILABLE
 *   2. Currently in Workshop  — cars whose operational_status is 'maintenance'
 *   3. Available Pool   — ready-to-rent cars
 *
 * The columns are real; the "Swap & Renew" transitions are SIMULATED in a useReducer store (opsReducer)
 * — moving cards between buckets + writing an activity-log line, nothing persisted. This is deliberate:
 * we never fabricate OfficeManager rental contracts (OM is the source of truth and a sync would
 * overwrite them). The real wiring would (a) record the link via the maintenance_swaps table and (b)
 * have staff open the replacement rental in OM. "Reset" re-seeds from the live snapshot to replay.
 *
 * Component architecture:
 *   <MaintenanceSwap>                   page — owns the reducer + the swap orchestration
 *     <CommandColumn>                   titled, scrollable column shell (mobile-first stacking)
 *       <ActionCard>                    a Fix-Now/Urgent car; WITH CUSTOMER vs AVAILABLE styling + button
 *       <WorkshopCard> / <PoolCard>     read-only cards for the other two columns
 *     <SwapRenewModal>                  pick a replacement + preview the close→renew transaction
 *     <ActivityLog>                     human-readable feed of every simulated transaction
 */

const emptyState = { actionRequired: [], workshop: [], pool: [], log: [], nextContract: 90001 };

// Map the live /maintenance-swaps/board payload into the three reducer buckets.
const mapBoard = (d) => ({
  actionRequired: (d.queue || []).map((c) => ({
    id: `v${c.vehicle_id}`, vehicle_id: c.vehicle_id, plate: c.plate, car: c.car, year: c.year,
    priority: c.tier === 'act_now' ? 'Fix Now' : 'Urgent',
    reason: c.reason || 'Maintenance due',
    status: c.status,                 // 'with_customer' | 'available'
    customer: c.customer, contractNo: c.contract_no,
  })),
  workshop: (d.workshop || []).map((w) => ({
    id: `v${w.vehicle_id}`, plate: w.plate, car: w.car, year: w.year, reason: w.reason, since: 'in shop',
  })),
  // Only genuinely free cars can be used as replacements.
  pool: (d.pool || []).filter((p) => !p.attached).map((p) => ({
    id: `v${p.vehicle_id}`, plate: p.plate, car: p.car, year: p.year, rate: p.day_rent_value,
    owesMaintenance: !!p.owes_maintenance, owesMaintenanceNote: p.owes_maintenance_note,
  })),
  log: [],
  nextContract: 90001,
});

// ── Mock state engine — the only place a "swap" mutates anything ──────────────────────────────────
function opsReducer(state, action) {
  switch (action.type) {
    /* AVAILABLE car → straight into the workshop. No contract involved. */
    case 'DIRECT_TO_WORKSHOP': {
      const car = state.actionRequired.find((c) => c.id === action.id);
      if (!car) return state;
      return {
        ...state,
        actionRequired: state.actionRequired.filter((c) => c.id !== action.id),
        workshop: [{ id: car.id, plate: car.plate, car: car.car, year: car.year, reason: car.reason, since: 'just now' }, ...state.workshop],
        log: [logLine('direct', `${car.plate} (${car.car}) sent to workshop — was Available, no contract to close.`), ...state.log],
      };
    }

    /*
     * WITH-CUSTOMER car → the full Swap & Renew transaction:
     *   1. Close the original rental (Completed / Swapped).
     *   2. Open a NEW rental for the SAME customer on the chosen replacement (mock contract no).
     *   3. Move the original car into the workshop.
     *   4. Remove the replacement from the available pool (it's now out on rent).
     */
    case 'SWAP_AND_RENEW': {
      const orig = state.actionRequired.find((c) => c.id === action.id);
      const repl = state.pool.find((c) => c.id === action.replacementId);
      if (!orig || !repl) return state;

      const newNo = state.nextContract;
      return {
        ...state,
        actionRequired: state.actionRequired.filter((c) => c.id !== orig.id),
        pool: state.pool.filter((c) => c.id !== repl.id),
        workshop: [{ id: orig.id, plate: orig.plate, car: orig.car, year: orig.year, reason: orig.reason, since: 'just now' }, ...state.workshop],
        nextContract: state.nextContract + 1,
        log: [
          logLine('renew', `${repl.plate} (${repl.car}) → new rental #${newNo} opened for ${orig.customer}.`),
          logLine('close', `Contract #${orig.contractNo} for ${orig.customer} marked Completed / Swapped (${orig.plate} → workshop).`),
          ...state.log,
        ],
      };
    }

    /* Seed (or re-seed) the board from a live snapshot. */
    case 'SEED':
      return { ...action.payload };

    default:
      return state;
  }
}

let _logId = 0;
const logLine = (kind, text) => ({ id: ++_logId, kind, text });

// ── Column shell ──────────────────────────────────────────────────────────────────────────────────
function CommandColumn({ title, hint, count, tone, icon, children }) {
  const tones = {
    red:     'bg-red-50 text-red-700 ring-red-200',
    amber:   'bg-amber-50 text-amber-700 ring-amber-200',
    emerald: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  };
  return (
    <div className="flex flex-col rounded-2xl bg-slate-50/70 ring-1 ring-inset ring-slate-200/70">
      <div className="flex items-center gap-2.5 border-b border-slate-200/70 px-4 py-3">
        <span className={`flex h-8 w-8 items-center justify-center rounded-lg ring-1 ring-inset ${tones[tone]}`}>{icon}</span>
        <div className="min-w-0">
          <h2 className="text-sm font-bold tracking-tight text-slate-800">{title}</h2>
          {hint && <p className="text-[11px] text-slate-400">{hint}</p>}
        </div>
        <span className="ml-auto rounded-full bg-white px-2.5 py-0.5 text-xs font-bold text-slate-600 ring-1 ring-inset ring-slate-200">{num(count)}</span>
      </div>
      <div className="flex max-h-[64vh] flex-col gap-2.5 overflow-y-auto p-3">
        {count === 0 ? <p className="py-10 text-center text-sm text-slate-400">Nothing here.</p> : children}
      </div>
    </div>
  );
}

// ── Action Required card (the only actionable column) ───────────────────────────────────────────────
function ActionCard({ car, onProcess }) {
  const withCustomer = car.status === 'with_customer';
  return (
    <div className={`rounded-xl border-l-4 bg-white p-3.5 shadow-sm transition hover:shadow ${withCustomer ? 'border-l-blue-500 border border-slate-200' : 'border-l-emerald-500 border border-slate-200'}`}>
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="font-bold text-slate-800">{car.plate}</p>
          <p className="truncate text-xs text-slate-400">{[car.car, car.year].filter(Boolean).join(' · ')}</p>
        </div>
        <Badge tone={car.priority === 'Fix Now' ? 'red' : 'amber'}>{car.priority}</Badge>
      </div>

      <p className="mt-2 inline-flex items-center gap-1.5 text-xs text-slate-500">
        <Icon.Wrench className="h-3.5 w-3.5 text-slate-400" /> <span className="capitalize">{car.reason}</span>
      </p>

      {/* Jump to this car's full breakdown on the Maintenance Foresight page (scrolls to + highlights it). */}
      {car.vehicle_id != null && (
        <Link
          to={`/maintenance-foresight#car-${car.vehicle_id}`}
          className="mt-2 inline-flex items-center gap-1 text-xs font-medium text-indigo-600 transition hover:text-indigo-700"
        >
          <Icon.Activity className="h-3.5 w-3.5" /> See details in Foresight
          <Icon.ArrowRight className="h-3.5 w-3.5" />
        </Link>
      )}

      {/* The crucial distinction: WITH CUSTOMER (blue, needs a renew) vs AVAILABLE (green, direct). */}
      <div className="mt-2.5">
        {withCustomer ? (
          <span className="inline-flex items-center gap-1.5 rounded-md bg-blue-50 px-2 py-1 text-[11px] font-semibold text-blue-700 ring-1 ring-inset ring-blue-200">
            <Icon.Users className="h-3.5 w-3.5" /> With customer · {car.customer} · #{car.contractNo}
          </span>
        ) : (
          <span className="inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200">
            <Icon.Check className="h-3.5 w-3.5" /> Available · no active rental
          </span>
        )}
      </div>

      <Button variant={withCustomer ? 'primary' : 'success'} size="sm" className="mt-3 w-full" onClick={() => onProcess(car)}>
        <Icon.Refresh className="mr-1.5 h-4 w-4" /> Process Swap
      </Button>
    </div>
  );
}

function WorkshopCard({ car }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-3.5 shadow-sm">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="font-bold text-slate-800">{car.plate}</p>
          <p className="truncate text-xs text-slate-400">{[car.car, car.year].filter(Boolean).join(' · ')}</p>
        </div>
        <Badge tone="red">In repair</Badge>
      </div>
      <p className="mt-2 inline-flex items-center gap-1.5 text-xs text-slate-500">
        <Icon.Wrench className="h-3.5 w-3.5 text-slate-400" /> <span className="capitalize">{car.reason}</span>
        {car.since && <span className="text-slate-400">· {car.since}</span>}
      </p>
    </div>
  );
}

function PoolCard({ car }) {
  return (
    <div className="flex items-center justify-between gap-2 rounded-xl border border-slate-200 bg-white p-3.5 shadow-sm">
      <div className="min-w-0">
        <p className="font-bold text-slate-800">{car.plate}</p>
        <p className="truncate text-xs text-slate-400">{[car.car, car.year].filter(Boolean).join(' · ')}</p>
        {car.owesMaintenance && (
          <span
            title={car.owesMaintenanceNote ? `Owes maintenance — ${car.owesMaintenanceNote}` : 'Pulled from the workshop for a customer — must go back to the garage.'}
            className="mt-1 inline-flex items-center gap-1 rounded-full bg-red-50 px-1.5 py-0.5 text-[10px] font-semibold text-red-700"
          >
            🛠️↩️ Owes maintenance
          </span>
        )}
      </div>
      <div className="flex shrink-0 flex-col items-end gap-1">
        {car.rate != null && <span className="text-xs font-semibold text-slate-500">{aed2(car.rate)}/d</span>}
        <Badge tone="green">Ready</Badge>
      </div>
    </div>
  );
}

// ── Swap & Renew modal (only for WITH CUSTOMER cars) ────────────────────────────────────────────────
function SwapRenewModal({ original, pool, onClose, onConfirm }) {
  const [picked, setPicked] = useState(null);

  return (
    <Modal
      open={!!original}
      onClose={onClose}
      title={original ? `Swap & Renew — ${original.plate}` : ''}
      subtitle={original ? `Close #${original.contractNo} for ${original.customer} and open a fresh rental on the replacement` : ''}
      size="lg"
      footer={
        <div className="flex items-center justify-between gap-3">
          <div className="flex min-w-0 items-center gap-2 text-sm">
            <span className="font-semibold text-blue-700">{original?.plate}</span>
            <Icon.ArrowRight className="h-4 w-4 text-slate-400" />
            <span className={`font-semibold ${picked ? 'text-emerald-600' : 'text-slate-300'}`}>{picked ? picked.plate : 'pick replacement'}</span>
          </div>
          <div className="flex items-center gap-2">
            <Button variant="secondary" onClick={onClose}>Cancel</Button>
            <Button variant="primary" disabled={!picked} onClick={() => onConfirm(picked)}>
              <Icon.Check className="mr-1.5 h-4 w-4" /> Confirm Swap & Renew
            </Button>
          </div>
        </div>
      }
    >
      {/* Plain-language preview of the simulated transaction. */}
      <ol className="mb-4 space-y-1.5 rounded-xl bg-slate-50 p-3 text-xs text-slate-600 ring-1 ring-inset ring-slate-200">
        <li>① Close contract <b>#{original?.contractNo}</b> ({original?.customer}) — marked <b>Completed / Swapped</b>.</li>
        <li>② Open a <b>new rental</b> for {original?.customer} on the chosen replacement.</li>
        <li>③ Move <b>{original?.plate}</b> into the workshop.</li>
      </ol>

      <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Choose a replacement</p>
      <div className="grid max-h-[40vh] grid-cols-1 gap-2 overflow-y-auto sm:grid-cols-2">
        {pool.map((c) => {
          const on = picked?.id === c.id;
          return (
            <button
              key={c.id}
              onClick={() => setPicked(c)}
              className={`flex items-center justify-between gap-2 rounded-xl border p-3 text-left transition ${on ? 'border-emerald-400 bg-emerald-50 ring-1 ring-emerald-300' : 'border-slate-200 bg-white hover:border-indigo-200 hover:bg-slate-50'}`}
            >
              <span className="min-w-0">
                <span className="block font-bold text-slate-800">{c.plate}</span>
                <span className="block truncate text-xs text-slate-400">{[c.car, c.year].filter(Boolean).join(' · ')}</span>
              </span>
              <span className="flex shrink-0 items-center gap-2">
                {c.rate != null && <span className="text-xs font-semibold text-slate-500">{aed2(c.rate)}/d</span>}
                {on && <Icon.Check className="h-5 w-5 text-emerald-600" />}
              </span>
            </button>
          );
        })}
      </div>
    </Modal>
  );
}

// ── Activity log ────────────────────────────────────────────────────────────────────────────────────
const LOG_TONE = {
  close:  { dot: 'bg-blue-500',    icon: <Icon.XCircle className="h-3.5 w-3.5" /> },
  renew:  { dot: 'bg-emerald-500', icon: <Icon.Spark className="h-3.5 w-3.5" /> },
  direct: { dot: 'bg-amber-500',   icon: <Icon.Wrench className="h-3.5 w-3.5" /> },
};
function ActivityLog({ log }) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-soft">
      <h3 className="mb-3 inline-flex items-center gap-2 text-sm font-bold text-slate-800">
        <Icon.Activity className="h-4 w-4 text-indigo-500" /> Swap activity (simulated)
      </h3>
      {log.length === 0 ? (
        <p className="text-sm text-slate-400">No swaps yet — hit <b>Process Swap</b> on a card to see the engine run.</p>
      ) : (
        <ol className="space-y-2">
          {log.map((l) => {
            const t = LOG_TONE[l.kind] || LOG_TONE.direct;
            return (
              <li key={l.id} className="flex items-start gap-2.5 text-xs text-slate-600">
                <span className={`mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-white ${t.dot}`}>{t.icon}</span>
                <span>{l.text}</span>
              </li>
            );
          })}
        </ol>
      )}
    </div>
  );
}

// ── Page ────────────────────────────────────────────────────────────────────────────────────────────
export default function MaintenanceSwap() {
  const toast = useToast();
  const [state, dispatch] = useReducer(opsReducer, emptyState);
  const [swapFor, setSwapFor] = useState(null);   // with-customer card awaiting a replacement choice
  const snapshotRef = useRef(null);               // the live snapshot, for Reset / replay

  // Seed the three columns from LIVE data (same endpoint the swap board uses).
  const fetcher = useCallback(async () => (await api.get('/maintenance-swaps/board')).data.data, []);
  const { data, loading, error, reload } = useFetch(fetcher, []);
  useEffect(() => {
    if (!data) return;
    const mapped = mapBoard(data);
    snapshotRef.current = mapped;            // remember the starting point so Reset can replay
    dispatch({ type: 'SEED', payload: mapped });
  }, [data]);

  // Reset replays from the live snapshot (clears the simulated moves); Refresh re-pulls live data.
  const resetBoard = () => {
    if (snapshotRef.current) {
      dispatch({ type: 'SEED', payload: { ...snapshotRef.current, log: [] } });
      toast.info('Board reset to the live snapshot.');
    }
  };

  // Process Swap — routes by the card's status (the core branch the spec asks for).
  const process = (car) => {
    if (car.status === 'with_customer') {
      setSwapFor(car);                    // needs a replacement → open the renew modal
    } else {
      dispatch({ type: 'DIRECT_TO_WORKSHOP', id: car.id });
      toast.success(`${car.plate} sent straight to the workshop (was available).`);
    }
  };

  const confirmRenew = (replacement) => {
    dispatch({ type: 'SWAP_AND_RENEW', id: swapFor.id, replacementId: replacement.id });
    toast.success(`Swapped ${swapFor.plate} → ${replacement.plate}; ${swapFor.customer}'s rental renewed.`);
    setSwapFor(null);
  };

  const withCustomerCount = state.actionRequired.filter((c) => c.status === 'with_customer').length;

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <PageHeader
            title="Maintenance Swap"
            subtitle="Live triage of the fleet: what needs maintenance, what's in the workshop, and what's ready to deploy. The Swap & Renew engine keeps a customer on the road while their car gets fixed."
          />
          <div className="flex items-center gap-2">
            <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200">Live data · simulated actions</span>
            <Button variant="ghost" size="sm" onClick={() => reload()} disabled={loading}>
              <Icon.Download className="mr-1.5 h-4 w-4" /> Refresh
            </Button>
            <Button variant="secondary" size="sm" onClick={resetBoard} disabled={!snapshotRef.current}>
              <Icon.Refresh className="mr-1.5 h-4 w-4" /> Reset
            </Button>
          </div>
        </div>

        {error && <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>}

        {loading && !snapshotRef.current ? (
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <Skeleton className="h-80 rounded-2xl" />
            <Skeleton className="h-80 rounded-2xl" />
            <Skeleton className="h-80 rounded-2xl" />
          </div>
        ) : (
          <>
            <MetricGrid cols={4}>
              <MetricCard label="Action required" value={num(state.actionRequired.length)} tone="red" icon={<Icon.Flag className="h-5 w-5" />} hint="Fix now (Foresight)" />
              <MetricCard label="With customer" value={num(withCustomerCount)} tone="blue" icon={<Icon.Users className="h-5 w-5" />} hint="need a swap & renew" />
              <MetricCard label="In workshop" value={num(state.workshop.length)} tone="amber" icon={<Icon.Wrench className="h-5 w-5" />} hint="currently in repair" />
              <MetricCard label="Available pool" value={num(state.pool.length)} tone="emerald" icon={<Icon.Car className="h-5 w-5" />} hint="free now · no active contract" />
            </MetricGrid>

            {/* Mobile-first: columns stack, then go 3-up from lg. */}
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
              <CommandColumn title="Action Required" hint="Maintenance Foresight · Fix now" count={state.actionRequired.length} tone="red" icon={<Icon.Flag className="h-4.5 w-4.5" />}>
                {state.actionRequired.map((car) => <ActionCard key={car.id} car={car} onProcess={process} />)}
              </CommandColumn>

              <CommandColumn title="Currently in Workshop" hint="operational status = maintenance" count={state.workshop.length} tone="amber" icon={<Icon.Wrench className="h-4.5 w-4.5" />}>
                {state.workshop.map((car) => <WorkshopCard key={car.id} car={car} />)}
              </CommandColumn>

              <CommandColumn title="Available Pool" hint="Free now — no open rental or maintenance" count={state.pool.length} tone="emerald" icon={<Icon.Car className="h-4.5 w-4.5" />}>
                {state.pool.map((car) => <PoolCard key={car.id} car={car} />)}
              </CommandColumn>
            </div>

            <ActivityLog log={state.log} />
          </>
        )}
      </div>

      <SwapRenewModal
        original={swapFor}
        pool={state.pool}
        onClose={() => setSwapFor(null)}
        onConfirm={confirmRenew}
      />
    </div>
  );
}
