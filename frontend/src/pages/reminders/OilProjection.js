import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { PageHeader, SearchInput, EmptyState, ErrorState } from '../../components/ui/Misc';
import MetricCard, { MetricGrid } from '../../components/ui/MetricCard';
import DataTable, { SectionCard } from '../../components/ui/Table';
import Badge from '../../components/ui/Badge';
import Button from '../../components/ui/Button';
import Modal from '../../components/ui/Modal';
import FilterChips from '../../components/ui/FilterChips';
import { Input, Textarea } from '../../components/ui/Field';
import { useToast } from '../../components/ui/Toast';
import { usePermissions } from '../../hooks/usePermissions';
import { num, fmtDate } from '../../lib/format';

/**
 * Oil Mileage Follow-up — the queue Leen and Marwa work.
 *
 * A car on a long rental burns through its oil interval days after it leaves, and the odometer we hold
 * stops being true the moment it drives off. So each day the backend projects where the car has probably
 * reached (a flat 200 km/day business assumption) and, when that projection crosses the service point,
 * it asks for a REAL number from the customer.
 *
 * What that number is FOR is the point of this page. Reaching the oil limit is not an emergency — the
 * fleet allows a car to run a tolerance past it. The operational question is narrower:
 *
 *     will this car still be inside the allowance when the customer brings it back?
 *
 * Answering it takes the reading, the days the rental still has to run, and nothing else. Most cars come
 * back inside the allowance and are simply serviced on return, with nobody interrupted. Only a car that
 * cannot finish inside it reaches a human, and that human has exactly two answers: recall it now, or
 * accept the overrun and change the oil at close.
 *
 * Everything shown here is computed by OilChangeProjectionService; the page derives no thresholds of its
 * own, and it renders the API's own `basis` string so the arithmetic is never a black box.
 */

/** The chase axis — "do we have a number we can trust?" */
const STATUS = {
  chase_due: { tone: 'red',   label: 'Needs a call' },
  ok:        { tone: 'green', label: 'Within limit' },
  no_data:   { tone: 'slate', label: 'Can’t project' },
};

/** The decision axis — "given the number we have, what happens to this car?" */
const OIL_STATUS = {
  decision_required:          { tone: 'red',    label: 'Decision needed' },
  recall_required:            { tone: 'amber',  label: 'Recall — bring it back' },
  service_required_on_return: { tone: 'amber',  label: 'Oil change on return' },
  within_tolerance:           { tone: 'green',  label: 'Within tolerance' },
  no_data:                    { tone: 'slate',  label: 'Can’t project' },
};

const DECISION_LABEL = {
  recall: 'Recall now',
  defer:  'Do it on return',
};

/** Why a recall was ordered. The API sends a code; the sentence is built here. */
const RECALL_REASON = {
  oil_tolerance_exceeded_before_return:
    'The oil tolerance will be exceeded before the rental ends.',
};

const RECALL_STATUS = {
  open:      { tone: 'red',   label: 'To call' },
  contacted: { tone: 'amber', label: 'Customer contacted' },
  done:      { tone: 'green', label: 'Done' },
  cancelled: { tone: 'slate', label: 'Withdrawn' },
};

/** How the rental's remaining run is phrased. Null days = the contract carries no duration. */
const dueBackIn = (p) => {
  if (!p?.return_date_known) return 'with no return date on the contract';
  const d = p.remaining_days;
  if (d === 0) return 'due back today';
  return `due back in ${d} ${d === 1 ? 'day' : 'days'}`;
};

/**
 * The verdict in one plain sentence. Deliberately operational language — no "anchor", no "threshold",
 * no "projection" — because the person reading it is about to pick up a phone, not debug a model.
 * Every sentence carries the three numbers the decision is actually made on: where it will land, what
 * it is allowed to reach, and how long it has left to run.
 */
export const reasonFor = (p) => {
  if (!p) return '—';
  if (p.status === 'no_data' || p.oil_status === 'no_data') {
    return 'No mileage was recorded when this car went out, so we can’t work out where it is now.';
  }

  const at = `${num(p.expected_return)} km`;
  const allowance = `${num(p.allowed_max)} km allowance`;
  const over = num(Math.max(p.over_tolerance_km ?? 0, 0));

  switch (p.oil_status) {
    case 'decision_required':
      return `Likely around ${num(p.expected)} km now and ${dueBackIn(p)} — it would come back on about `
        + `${at}, which is ${over} km past the ${allowance}. `
        + (p.decision_ready
          ? 'Recall it now, or accept that and change the oil the day it returns.'
          : 'That is an estimate, not a reading — get the real number from the customer before deciding anything.');

    case 'recall_required':
      return `Recall agreed${p.decision?.decided_by ? ` by ${p.decision.decided_by}` : ''} — projected to `
        + `reach ${at} against a ${allowance}. Arrange the return with the customer; the oil change is `
        + 'raised automatically once the car is back.';

    case 'service_required_on_return':
      return `${dueBackIn(p).replace(/^with/, 'Out with')} and would come back on about ${at}, inside the `
        + `${allowance}. Let the rental finish — the oil change is booked for the return.`;

    case 'within_tolerance':
      return `Likely around ${num(p.expected)} km — it comes back on about ${at}, still short of the `
        + `${num(p.oil_limit)} km oil point. Nothing to do.`;

    default:
      return `Likely around ${num(p.expected)} km. Next check around ${fmtDate(p.breach_on)}.`;
  }
};

/**
 * Enter the number the customer read off the dash.
 *
 * Loads the contract's own projection + every previous reading, so whoever is on the call can see what
 * was reported last time before typing a new figure. On save it shows the RECALCULATED verdict — the
 * whole point of entering the number is finding out what it changes, and the caller needs to know that
 * before they put the phone down.
 */
export function ReadingDialog({ row, onClose, onSaved }) {
  const toast = useToast();
  const [detail, setDetail] = useState(null);
  const [odometer, setOdometer] = useState('');
  const [reportedBy, setReportedBy] = useState('');
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState(null);

  useEffect(() => {
    let alive = true;
    api.get(`/Contract/${row.contract_id}/oil-projection`)
      .then((res) => { if (alive) setDetail(res.data?.data || null); })
      .catch(() => { if (alive) setDetail(null); });
    return () => { alive = false; };
  }, [row.contract_id]);

  const projection = detail?.projection || row.projection;
  const readings = detail?.readings || [];

  const submit = async () => {
    if (odometer === '' || Number.isNaN(Number(odometer))) {
      return toast.error('Enter the odometer reading the customer gave you');
    }
    setBusy(true);
    try {
      const { data } = await api.post(`/Contract/${row.contract_id}/mileage-reading`, {
        odometer: Number(odometer),
        reported_by: reportedBy || null,
        note: note || null,
      });
      setResult(data?.data?.projection || null);
      toast.success('Reading saved — recalculated');
      onSaved();
    } catch (e) {
      // The API rejects a reading that runs backwards; surface its sentence, not a generic failure.
      toast.error(e.response?.data?.message || 'Could not save the reading');
    } finally {
      setBusy(false);
    }
  };

  // The recalculated answer, tone-matched: a car that is now a decision must not be reported in the
  // same reassuring green as one that just cleared itself.
  const resultTone = result?.oil_status === 'decision_required'
    ? 'border-rose-200 bg-rose-50 text-rose-800'
    : 'border-emerald-200 bg-emerald-50 text-emerald-800';

  return (
    <Modal
      open
      onClose={() => !busy && onClose()}
      size="lg"
      title={`Mileage reading — ${row.plate || row.car || `contract ${row.contract_id}`}`}
      subtitle={row.customer ? `${row.customer} · contract ${row.contract_no || row.contract_id}` : undefined}
      footer={(
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose} disabled={busy}>Close</Button>
          <Button onClick={submit} loading={busy}>Save reading</Button>
        </div>
      )}
    >
      <div className="space-y-4">
        <div className="rounded-lg bg-slate-50 p-3 text-sm text-slate-700">{reasonFor(projection)}</div>

        {result && (
          <div className={`rounded-lg border p-3 text-sm ${resultTone}`}>
            <div className="font-semibold">Recalculated</div>
            <div>{reasonFor(result)}</div>
            {result.oil_status === 'decision_required' && (
              <div className="mt-1 text-xs">
                Close this and choose <strong>Recall now</strong> or <strong>Do it on return</strong>.
              </div>
            )}
          </div>
        )}

        <Input
          label="Odometer reported by the customer (km)"
          type="number"
          required
          value={odometer}
          onChange={(e) => setOdometer(e.target.value)}
          placeholder="e.g. 41200"
        />
        <Input
          label="Who gave the reading (optional)"
          value={reportedBy}
          onChange={(e) => setReportedBy(e.target.value)}
          placeholder="Customer, over the phone"
        />
        <Textarea
          label="Note (optional)"
          value={note}
          onChange={(e) => setNote(e.target.value)}
          rows={2}
        />

        <div>
          <div className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
            Previous readings
          </div>
          {readings.length === 0 ? (
            <div className="text-sm text-slate-500">
              None yet — the projection is still running from the mileage recorded at handover.
            </div>
          ) : (
            <ul className="divide-y divide-slate-100 text-sm">
              {readings.map((r) => (
                <li key={r.id} className="flex items-center justify-between py-1.5">
                  <span className="font-medium text-slate-800">{num(r.odometer)} km</span>
                  <span className="text-slate-500">{fmtDate(r.reported_on)}{r.reported_by ? ` · ${r.reported_by}` : ''}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </Modal>
  );
}

/**
 * The call itself, on a car that cannot finish the rental inside its allowance.
 *
 * Confirmed rather than fired from a bare table button, because one of the two answers means phoning a
 * paying customer to ask for their car back — and the person doing that should see the figures they are
 * doing it on, spelled out, one more time.
 */
export function DecisionDialog({ row, decision, onClose, onDecided }) {
  const toast = useToast();
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const p = row.projection || {};
  const recall = decision === 'recall';

  const submit = async () => {
    setBusy(true);
    try {
      await api.post(`/Contract/${row.contract_id}/oil-decision`, { decision, note: note || null });
      toast.success(recall ? 'Recall recorded' : 'Oil change booked for the return');
      onDecided();
      onClose();
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not record the decision');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open
      onClose={() => !busy && onClose()}
      title={`${DECISION_LABEL[decision]} — ${row.plate || row.car || `contract ${row.contract_id}`}`}
      subtitle={row.customer ? `${row.customer} · contract ${row.contract_no || row.contract_id}` : undefined}
      footer={(
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button variant={recall ? 'danger' : 'primary'} onClick={submit} loading={busy}>
            {DECISION_LABEL[decision]}
          </Button>
        </div>
      )}
    >
      <div className="space-y-3 text-sm">
        <div className="rounded-lg bg-slate-50 p-3 text-slate-700">
          <div>Projected on return: <strong>{num(p.expected_return)} km</strong></div>
          <div>Allowed maximum: <strong>{num(p.allowed_max)} km</strong>
            {' '}({num(p.oil_limit)} km oil limit + {num(p.tolerance)} km tolerance)</div>
          <div>Rental still to run: <strong>{p.return_date_known ? `${p.remaining_days} days` : 'not stated on the contract'}</strong></div>
          <div className="mt-1 font-semibold text-rose-700">
            {num(Math.max(p.over_tolerance_km ?? 0, 0))} km past what this car is allowed to run.
          </div>
        </div>

        <p className="text-slate-700">
          {recall
            ? 'This raises a call for you to make: contact the customer and agree a day to bring the car '
              + 'in. No driver is dispatched and no collection is booked — that stays a conversation. The '
              + 'oil-change ticket is raised on its own the moment the car is back.'
            : 'The rental runs to its end and the overrun is accepted. Nothing to chase — the oil-change '
              + 'ticket is raised on its own the moment the car is back.'}
        </p>

        <Textarea
          label="Why (optional)"
          value={note}
          onChange={(e) => setNote(e.target.value)}
          rows={2}
          placeholder={recall ? 'Customer agreed to bring it in Thursday' : 'Customer is mid-trip; overrun accepted'}
        />
      </div>
    </Modal>
  );
}

/**
 * The recall call queue.
 *
 * A recall is a CONVERSATION, not a movement: phone the customer, explain that the car is about to
 * run past what its oil is good for, agree a day to bring it in. So this queue carries the figures
 * that conversation is about and nothing else — no route, no driver, no ETA. Those belong to a
 * logistics module the fleet doesn't have yet, and inventing one here would put half-specified
 * transport jobs in front of drivers.
 *
 * The numbers are FROZEN as of the decision, not live: someone halfway through a call must see the
 * figures the recall was ordered on, not figures that moved under them thirty seconds ago.
 */
export function RecallQueue({ tasks, canRecord, onChanged }) {
  const toast = useToast();
  const [busyId, setBusyId] = useState(null);

  const move = async (task, status) => {
    setBusyId(task.id);
    try {
      await api.patch(`/OilRecallTasks/${task.id}`, { status });
      toast.success(status === 'contacted' ? 'Marked as contacted' : 'Recall closed');
      onChanged();
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not update the recall');
    } finally {
      setBusyId(null);
    }
  };

  if (!tasks.length) return null;

  return (
    <SectionCard
      title={`Recalls to arrange (${tasks.length})`}
      subtitle="Call the customer and agree a day to bring the car in. The oil-change ticket is raised on its own once the car is back."
    >
      <ul className="divide-y divide-slate-100">
        {tasks.map((t) => {
          const s = RECALL_STATUS[t.status] || RECALL_STATUS.open;
          return (
            <li key={t.id} className="flex flex-wrap items-start justify-between gap-3 py-3">
              <div className="min-w-0 space-y-1">
                <div className="flex flex-wrap items-center gap-2">
                  {t.vehicle_id
                    ? <Link to={`/vehicles/${t.vehicle_id}`} className="font-semibold text-slate-900 hover:text-indigo-600">{t.plate || `#${t.vehicle_id}`}</Link>
                    : <span className="font-semibold text-slate-900">{t.plate || '—'}</span>}
                  <span className="text-xs text-slate-500">{t.car}</span>
                  <Badge tone={s.tone}>{s.label}</Badge>
                </div>
                <div className="text-sm text-slate-700">
                  {t.customer || 'Customer'}{t.contract_no ? ` · contract ${t.contract_no}` : ''}
                </div>
                <div className="text-sm text-slate-600">
                  {RECALL_REASON[t.reason_code] || 'Recall ordered.'}
                </div>
                {/* Everything the caller needs to say, in the order they'd say it. */}
                <div className="text-xs text-slate-500">
                  Customer reported <strong>{num(t.customer_reading)} km</strong>
                  {t.customer_reading_on ? ` on ${fmtDate(t.customer_reading_on)}` : ''}
                  {' · '}oil limit {num(t.oil_limit)} km
                  {' · '}allowed {num(t.allowed_max)} km
                  {' · '}would return on <strong>{num(t.expected_return_odometer)} km</strong>
                  {t.over_tolerance_km ? ` (${num(t.over_tolerance_km)} km over)` : ''}
                  {t.remaining_days != null ? ` · ${t.remaining_days}d left on the contract` : ''}
                </div>
                <div className="text-xs text-slate-400">
                  Recalled by {t.created_by || 'system'}{t.decided_at ? ` · ${fmtDate(t.decided_at)}` : ''}
                  {t.claimed_by ? ` · picked up by ${t.claimed_by}` : ''}
                </div>
                {t.note && <div className="text-xs italic text-slate-500">“{t.note}”</div>}
              </div>

              {canRecord && (
                <div className="flex shrink-0 gap-1.5">
                  {t.status === 'open' && (
                    <Button size="sm" variant="secondary" loading={busyId === t.id} onClick={() => move(t, 'contacted')}>
                      Customer contacted
                    </Button>
                  )}
                  <Button size="sm" variant="ghost" loading={busyId === t.id} onClick={() => move(t, 'done')}>
                    Close
                  </Button>
                </div>
              )}
            </li>
          );
        })}
      </ul>
    </SectionCard>
  );
}

export default function OilProjection() {
  const { can } = usePermissions();
  const canRecord = can('reminders.manage');
  const [filter, setFilter] = useState('decision_required');
  const [q, setQ] = useState('');
  const [active, setActive] = useState(null);
  const [deciding, setDeciding] = useState(null);   // { row, decision }

  const fetcher = useCallback(async () => {
    // The board and the recall queue are one screen's worth of work, so they load together — a
    // controller should never have to go looking for the calls their own decisions created.
    const [board, recalls] = await Promise.all([
      api.get('/OilProjection'),
      api.get('/OilRecallTasks').catch(() => ({ data: { data: { tasks: [] } } })),
    ]);
    return { ...(board.data.data || {}), recallTasks: recalls.data?.data?.tasks || [] };
  }, []);
  // Modal open ⇒ pause polling, so the table can't reshuffle under someone mid-call.
  const { data, loading, error, reload } = useFetch(fetcher, [], {
    refreshInterval: 60000,
    paused: () => Boolean(active || deciding),
  });

  const contracts = useMemo(() => data?.contracts || [], [data]);
  const summary = data?.summary || {
    chase_due: 0, ok: 0, no_data: 0, total: 0,
    decision_required: 0, recall_required: 0, service_required_on_return: 0, within_tolerance: 0,
  };

  const rows = useMemo(() => {
    const needle = q.trim().toLowerCase();
    let list = contracts;
    // "Decision needed" means answerable TODAY — a decision resting on a days-old projection is a
    // phone call, not a choice, and the backend says which is which (`decision_ready`). The chase
    // filter reads the chase axis; the rest read the decision axis. A car can appear under both.
    if (filter === 'decision_required') list = list.filter((r) => r.projection?.decision_ready);
    else if (filter === 'awaiting_reading') {
      list = list.filter((r) => r.projection?.oil_status === 'decision_required' && !r.projection?.decision_ready);
    } else if (filter === 'chase_due') list = list.filter((r) => r.projection?.status === 'chase_due');
    else if (filter !== 'all') list = list.filter((r) => r.projection?.oil_status === filter);
    if (!needle) return list;
    return list.filter((r) => `${r.plate || ''} ${r.car || ''} ${r.customer || ''} ${r.contract_no || ''}`
      .toLowerCase().includes(needle));
  }, [contracts, filter, q]);

  const columns = [
    {
      key: 'vehicle',
      header: 'Vehicle',
      cellClass: 'font-medium',
      render: (r) => (
        <div className="min-w-0">
          {r.vehicle_id ? (
            <Link to={`/vehicles/${r.vehicle_id}`} className="font-semibold text-slate-900 hover:text-indigo-600">
              {r.plate || `#${r.vehicle_id}`}
            </Link>
          ) : <span className="font-semibold text-slate-900">{r.plate || '—'}</span>}
          <div className="truncate text-xs text-slate-500">{r.car}</div>
        </div>
      ),
    },
    {
      key: 'customer',
      header: 'Customer',
      render: (r) => (
        <div className="min-w-0">
          <div className="truncate text-slate-800">{r.customer || '—'}</div>
          <div className="truncate text-xs text-slate-500">{r.contract_no}</div>
        </div>
      ),
    },
    {
      key: 'due',
      header: 'Due back',
      render: (r) => {
        const p = r.projection || {};
        if (!p.return_date_known) return <span className="text-slate-400">Not stated</span>;
        return (
          <div>
            <div className="text-slate-800">{fmtDate(p.return_due_on)}</div>
            <div className="text-xs text-slate-500">
              {p.remaining_days === 0 ? 'today' : `${p.remaining_days}d to run`}
            </div>
          </div>
        );
      },
    },
    {
      key: 'projected',
      header: 'Now',
      render: (r) => (r.projection?.expected != null
        ? <span className="text-slate-700">{num(r.projection.expected)} km</span>
        : <span className="text-slate-400">—</span>),
    },
    {
      key: 'on_return',
      header: 'On return',
      render: (r) => (r.projection?.expected_return != null
        ? <span className="font-semibold text-slate-900">{num(r.projection.expected_return)} km</span>
        : <span className="text-slate-400">—</span>),
    },
    {
      key: 'allowance',
      header: 'Allowed max',
      render: (r) => {
        const p = r.projection || {};
        if (p.allowed_max == null) return <span className="text-slate-400">—</span>;
        return (
          <div>
            <div className="text-slate-700">{num(p.allowed_max)} km</div>
            <div className="text-xs text-slate-500">{num(p.oil_limit)} + {num(p.tolerance)}</div>
          </div>
        );
      },
    },
    {
      key: 'margin',
      header: 'Against allowance',
      render: (r) => {
        const m = r.projection?.over_tolerance_km;
        if (m == null) return <span className="text-slate-400">—</span>;
        return m > 0
          ? <Badge tone="red">{num(m)} km over</Badge>
          : <Badge tone="green">{num(Math.abs(m))} km spare</Badge>;
      },
    },
    {
      key: 'status',
      header: 'Status',
      render: (r) => {
        const p = r.projection || {};
        // A car that will bust its allowance on an ESTIMATE is not reported as a decision — it is
        // reported as what it is: a car whose real mileage we don't know yet.
        const s = (p.oil_status === 'decision_required' && !p.decision_ready)
          ? { tone: 'amber', label: 'Needs a number first' }
          : (OIL_STATUS[p.oil_status] || OIL_STATUS.no_data);
        return (
          <div className="space-y-1">
            <Badge tone={s.tone}>{s.label}</Badge>
            {p.status === 'chase_due' && <div><Badge tone={STATUS.chase_due.tone}>{STATUS.chase_due.label}</Badge></div>}
          </div>
        );
      },
    },
    {
      key: 'why',
      header: 'Why',
      render: (r) => <div className="max-w-md text-xs text-slate-600">{reasonFor(r.projection)}</div>,
    },
    {
      key: 'action',
      header: '',
      render: (r) => {
        if (!canRecord) return null;
        const p = r.projection || {};
        return (
          <div className="flex flex-wrap justify-end gap-1.5">
            <Button size="sm" variant={p.status === 'chase_due' ? 'primary' : 'ghost'} onClick={() => setActive(r)}>
              Enter reading
            </Button>
            {/* Only offered once the projection rests on a fresh reading — nobody should be asked to
                recall a customer's car on the strength of a 200 km/day assumption. */}
            {p.decision_ready && (
              <>
                <Button size="sm" variant="danger" onClick={() => setDeciding({ row: r, decision: 'recall' })}>
                  Recall now
                </Button>
                <Button size="sm" variant="secondary" onClick={() => setDeciding({ row: r, decision: 'defer' })}>
                  Do it on return
                </Button>
              </>
            )}
          </div>
        );
      },
    },
  ];

  if (error) return <ErrorState title="Could not load the follow-up queue" message={error} onRetry={reload} />;

  return (
    <div className="space-y-5">
      <PageHeader
        title="Oil Mileage Follow-up"
        subtitle="Cars out on rental whose oil limit is coming up. Get the real odometer from the customer — the question is whether the car can finish the rental inside its allowance, not whether it has passed the limit."
      />

      <MetricGrid cols={4}>
        <MetricCard label="Decision needed" value={summary.decision_required} tone="red" loading={loading}
          tooltip="Projected on a fresh customer reading to finish past the allowed maximum. Recall it, or accept the overrun and service it on return." />
        <MetricCard label="Needs a call" value={summary.chase_due} tone="amber" loading={loading}
          tooltip="The number we hold is a projection, not a reading. Phone the customer — a long rental will always look like it busts its allowance until a real number says otherwise." />
        <MetricCard label="Oil change on return" value={summary.service_required_on_return} tone="amber" loading={loading}
          tooltip="Will pass the oil point but finish inside the allowance. The rental runs on; the change is raised when the car is back." />
        <MetricCard label="Cars out" value={summary.total} tone="slate" loading={loading} />
      </MetricGrid>

      {/* The calls the decisions created, above the board that creates them. */}
      <RecallQueue
        tasks={data?.recallTasks || []}
        canRecord={canRecord}
        onChanged={() => reload({ silent: true })}
      />

      <SectionCard
        title="Follow-up queue"
        subtitle={data?.model
          ? `Projected at ${num(data.model.rate_km_per_day)} km/day, with a ${num(data.model.tolerance_km ?? data.model.grace_km)} km tolerance above each car's oil limit.`
          : undefined}
        actions={(
          <div className="flex flex-wrap items-center gap-2">
            <SearchInput value={q} onChange={setQ} placeholder="Plate, customer, contract…" />
            <FilterChips
              value={filter}
              onChange={setFilter}
              options={[
                { key: 'decision_required', label: `Decision needed (${summary.decision_required})` },
                { key: 'chase_due', label: `Needs a call (${summary.chase_due})` },
                { key: 'awaiting_reading', label: `Needs a number first (${summary.awaiting_reading})` },
                { key: 'recall_required', label: `Recall (${summary.recall_required})` },
                { key: 'service_required_on_return', label: `On return (${summary.service_required_on_return})` },
                { key: 'within_tolerance', label: `Within tolerance (${summary.within_tolerance})` },
                { key: 'no_data', label: `Can’t project (${summary.no_data})` },
                { key: 'all', label: `All (${summary.total})` },
              ]}
            />
          </div>
        )}
      >
        {!loading && rows.length === 0 ? (
          <EmptyState
            title="Nothing to decide"
            message="No car currently out on rental is projected to finish past its oil allowance."
          />
        ) : (
          <DataTable
            columns={columns}
            rows={rows}
            rowKey={(r) => r.contract_id}
            loading={loading}
            highlightRow={(r) => r.projection?.oil_status === 'decision_required'}
          />
        )}
      </SectionCard>

      {/* The three states, stated once, so nobody has to infer them from badge colours. */}
      <div className="grid gap-2 text-xs text-slate-600 sm:grid-cols-3">
        <div className="rounded-lg border border-slate-200 p-2.5">
          <Badge tone="green">Within tolerance</Badge>
          <div className="mt-1.5">Comes back before it even reaches the oil point. No action.</div>
        </div>
        <div className="rounded-lg border border-slate-200 p-2.5">
          <Badge tone="amber">Oil change on return</Badge>
          <div className="mt-1.5">
            Passes the oil point but finishes inside the allowance. No decision needed — the rental
            continues and the oil change is raised as soon as the car is back.
          </div>
        </div>
        <div className="rounded-lg border border-slate-200 p-2.5">
          <Badge tone="red">Decision needed</Badge>
          <div className="mt-1.5">
            Cannot finish inside the allowance. Someone must choose: <strong>recall now</strong> (raises a
            call to arrange the return) or <strong>do it on return</strong> (defer until the contract closes).
          </div>
        </div>
      </div>

      {/* Traceability: the page states the arithmetic behind every number it shows. */}
      {data?.model?.basis && (
        <div className="text-xs text-slate-500">
          <span className="font-semibold">Data origin:</span>{' '}
          {data.model.basis}. Oil limit comes from the Oil Change sheet anchors on each car; remaining days
          come from the contract’s own duration; customer-reported readings are stored against the contract
          and never change the car’s odometer.
        </div>
      )}

      {active && (
        <ReadingDialog
          row={active}
          onClose={() => setActive(null)}
          onSaved={() => reload({ silent: true })}
        />
      )}

      {deciding && (
        <DecisionDialog
          row={deciding.row}
          decision={deciding.decision}
          onClose={() => setDeciding(null)}
          onDecided={() => reload({ silent: true })}
        />
      )}
    </div>
  );
}
