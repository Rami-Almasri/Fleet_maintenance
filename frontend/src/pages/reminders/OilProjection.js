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
 * A car on a long rental burns through its oil interval days after it leaves, and the odometer we
 * hold stops being true the moment it drives off. So each day the backend projects where the car has
 * probably reached (a flat 200 km/day business assumption) and, when that projection crosses the
 * service point plus the fleet grace, it asks for a REAL number from the customer.
 *
 * This page is the human half of that loop: see who needs a call, phone them, type the number they
 * read off the dash. Entering it re-anchors the projection and re-arms the next check — which is why
 * the dialog shows the recalculated verdict immediately instead of just saying "saved".
 *
 * Everything shown here is computed by OilChangeProjectionService; the page derives no thresholds of
 * its own, and it renders the API's own `basis` string so the arithmetic is never a black box.
 */

const STATUS = {
  chase_due: { tone: 'red',   label: 'Needs a call' },
  ok:        { tone: 'green', label: 'Within limit' },
  no_data:   { tone: 'slate', label: 'Can’t project' },
};

/**
 * The alert in one plain sentence. Deliberately operational language — no "anchor", no "threshold",
 * no "projection" — because the person reading it is about to pick up a phone, not debug a model.
 */
export const reasonFor = (p) => {
  if (!p) return '—';
  if (p.status === 'no_data') {
    return 'No mileage was recorded when this car went out, so we can’t work out where it is now.';
  }
  const days = p.days_elapsed ?? 0;
  if (p.status === 'chase_due') {
    const over = (p.expected ?? 0) - (p.threshold ?? 0);
    return `Out ${days} ${days === 1 ? 'day' : 'days'} — likely around ${num(p.expected)} km, which is `
      + `${num(Math.max(over, 0))} km past the ${num(p.threshold)} km oil limit. Ask the customer for the real reading.`;
  }
  return `Likely around ${num(p.expected)} km — ${num(p.km_to_threshold)} km before the ${num(p.threshold)} km `
    + `oil limit. Next check around ${fmtDate(p.breach_on)}.`;
};

/**
 * Enter the number the customer read off the dash.
 *
 * Loads the contract's own projection + every previous reading, so whoever is on the call can see
 * what was reported last time before typing a new figure. On save it shows the RECALCULATED verdict —
 * the whole point of entering the number is finding out what it changes.
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
      toast.success('Reading saved — projection recalculated');
      onSaved();
    } catch (e) {
      // The API rejects a reading that runs backwards; surface its sentence, not a generic failure.
      toast.error(e.response?.data?.message || 'Could not save the reading');
    } finally {
      setBusy(false);
    }
  };

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
          <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
            <div className="font-semibold">Recalculated</div>
            <div>{reasonFor(result)}</div>
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

export default function OilProjection() {
  const { can } = usePermissions();
  const canRecord = can('reminders.manage');
  const [filter, setFilter] = useState('chase_due');
  const [q, setQ] = useState('');
  const [active, setActive] = useState(null);

  const fetcher = useCallback(async () => {
    const { data } = await api.get('/OilProjection');
    return data.data || null;
  }, []);
  // Modal open ⇒ pause polling, so the table can't reshuffle under someone mid-call.
  const { data, loading, error, reload } = useFetch(fetcher, [], {
    refreshInterval: 60000,
    paused: () => Boolean(active),
  });

  const contracts = useMemo(() => data?.contracts || [], [data]);
  const summary = data?.summary || { chase_due: 0, ok: 0, no_data: 0, total: 0 };

  const rows = useMemo(() => {
    const needle = q.trim().toLowerCase();
    let list = contracts;
    if (filter !== 'all') list = list.filter((r) => r.projection?.status === filter);
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
      key: 'out',
      header: 'Out since',
      render: (r) => (
        <div>
          <div className="text-slate-800">{fmtDate(r.out_date)}</div>
          <div className="text-xs text-slate-500">
            {r.projection?.days_elapsed != null ? `${r.projection.days_elapsed}d on this reading` : '—'}
          </div>
        </div>
      ),
    },
    {
      key: 'projected',
      header: 'Projected now',
      render: (r) => (r.projection?.expected != null
        ? <span className="font-semibold text-slate-900">{num(r.projection.expected)} km</span>
        : <span className="text-slate-400">—</span>),
    },
    {
      key: 'limit',
      header: 'Oil limit',
      render: (r) => (r.projection?.threshold != null
        ? <span className="text-slate-700">{num(r.projection.threshold)} km</span>
        : <span className="text-slate-400">—</span>),
    },
    {
      key: 'margin',
      header: 'Margin',
      render: (r) => {
        const m = r.projection?.km_to_threshold;
        if (m == null) return <span className="text-slate-400">—</span>;
        return m < 0
          ? <Badge tone="red">{num(Math.abs(m))} km over</Badge>
          : <Badge tone="green">{num(m)} km left</Badge>;
      },
    },
    {
      key: 'status',
      header: 'Status',
      render: (r) => {
        const s = STATUS[r.projection?.status] || STATUS.no_data;
        return <Badge tone={s.tone}>{s.label}</Badge>;
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
      render: (r) => (canRecord ? (
        <Button size="sm" variant={r.projection?.status === 'chase_due' ? 'primary' : 'ghost'} onClick={() => setActive(r)}>
          Enter reading
        </Button>
      ) : null),
    },
  ];

  if (error) return <ErrorState title="Could not load the follow-up queue" message={error} onRetry={reload} />;

  return (
    <div className="space-y-5">
      <PageHeader
        title="Oil Mileage Follow-up"
        subtitle="Cars out on rental whose oil limit is coming up. Call the customer, get the real odometer, and the next check recalculates from it."
      />

      <MetricGrid cols={4}>
        <MetricCard label="Needs a call" value={summary.chase_due} tone="red" loading={loading} />
        <MetricCard label="Within limit" value={summary.ok} tone="green" loading={loading} />
        <MetricCard label="Can’t project" value={summary.no_data} tone="slate" loading={loading}
          tooltip="No mileage was recorded at handover, so there is nothing to project from." />
        <MetricCard label="Cars out" value={summary.total} tone="slate" loading={loading} />
      </MetricGrid>

      <SectionCard
        title="Follow-up queue"
        subtitle={data?.model
          ? `Projected at ${num(data.model.rate_km_per_day)} km/day with a ${num(data.model.grace_km)} km grace.`
          : undefined}
        actions={(
          <div className="flex flex-wrap items-center gap-2">
            <SearchInput value={q} onChange={setQ} placeholder="Plate, customer, contract…" />
            <FilterChips
              value={filter}
              onChange={setFilter}
              options={[
                { key: 'chase_due', label: `Needs a call (${summary.chase_due})` },
                { key: 'ok', label: `Within limit (${summary.ok})` },
                { key: 'no_data', label: `Can’t project (${summary.no_data})` },
                { key: 'all', label: `All (${summary.total})` },
              ]}
            />
          </div>
        )}
      >
        {!loading && rows.length === 0 ? (
          <EmptyState
            title="Nothing to chase"
            message="No car currently out on rental is projected past its oil limit."
          />
        ) : (
          <DataTable
            columns={columns}
            rows={rows}
            rowKey={(r) => r.contract_id}
            loading={loading}
            highlightRow={(r) => r.projection?.status === 'chase_due'}
          />
        )}
      </SectionCard>

      {/* Traceability: the page states the arithmetic behind every number it shows. */}
      {data?.model?.basis && (
        <div className="text-xs text-slate-500">
          <span className="font-semibold">Data origin:</span>{' '}
          {data.model.basis}. Oil limit comes from the Oil Change sheet anchors on each car;
          customer-reported readings are stored against the contract and never change the car’s odometer.
        </div>
      )}

      {active && (
        <ReadingDialog
          row={active}
          onClose={() => setActive(null)}
          onSaved={() => reload({ silent: true })}
        />
      )}
    </div>
  );
}
