import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { PageHeader, SearchInput } from '../../components/ui/Misc';
import MetricCard, { MetricGrid } from '../../components/ui/MetricCard';
import DataTable, { SectionCard } from '../../components/ui/Table';
import { MetricGridSkeleton } from '../../components/ui/Skeleton';
import Badge from '../../components/ui/Badge';
import Button from '../../components/ui/Button';
import Modal from '../../components/ui/Modal';
import ConfirmDialog from '../../components/ui/ConfirmDialog';
import SearchSelect from '../../components/ui/SearchSelect';
import { Input, Select, Textarea } from '../../components/ui/Field';
import FilterChips from '../../components/ui/FilterChips';
import Icon from '../../components/ui/Icon';
import { useToast } from '../../components/ui/Toast';
import { usePermissions } from '../../hooks/usePermissions';
import { num, fmtDate, fmtAgo } from '../../lib/format';

// Shared status language for reminders/schedules — overdue reads red, due-soon amber.
const STATUS = {
  overdue:  { tone: 'red',   label: 'Overdue' },
  due_soon: { tone: 'amber', label: 'Due soon' },
  ok:       { tone: 'green', label: 'OK' },
  no_data:  { tone: 'slate', label: 'No data' },
};

// Mirrors ServiceReminder::TYPE_LABELS on the API — the picker + the group headings.
const TYPES = {
  oil_change:    'Oil Change',
  air_filter:    'Air Filter',
  oil_filter:    'Oil Filter',
  brake_pads:    'Brake Pads',
  tire_rotation: 'Tire Rotation',
  tire_change:   'Tire Change',
  battery:       'Battery',
  ac_service:    'A/C Service',
  transmission:  'Transmission Service',
  general:       'General Service',
};

// A compact "how far from due" note (km first, else days).
const remaining = (r) => {
  if (r.km_remaining != null) return `${num(Math.abs(r.km_remaining))} km ${r.km_remaining < 0 ? 'over' : 'left'}`;
  if (r.days_remaining != null) return `${Math.abs(r.days_remaining)}d ${r.days_remaining < 0 ? 'over' : 'left'}`;
  return '—';
};

const EMPTY_FORM = {
  vehicle_id: '', service_type: 'oil_change', name: '',
  interval_km: '', interval_days: '',
  last_service_odometer: '', last_service_at: '', notes: '',
};

/**
 * Create / edit a reminder. The cadence is the point of the whole record: give it an
 * interval (km, days, or both) and the last-service anchor, and the API recomputes the
 * next-due point on save. On edit the vehicle + type are fixed (one reminder per car per
 * type, enforced by a unique index).
 */
function ReminderDialog({ reminder, vehicles, onClose, onSaved }) {
  const toast = useToast();
  const editing = Boolean(reminder?.id);
  const [form, setForm] = useState(() => (editing
    ? {
      vehicle_id: reminder.vehicle_id ?? '',
      service_type: reminder.service_type || 'oil_change',
      name: reminder.name || '',
      interval_km: reminder.interval_km ?? '',
      interval_days: reminder.interval_days ?? '',
      last_service_odometer: reminder.last_service_odometer ?? '',
      last_service_at: reminder.last_service_at || '',
      notes: reminder.notes || '',
    }
    : EMPTY_FORM));
  const [busy, setBusy] = useState(false);
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const options = useMemo(
    () => vehicles.map((v) => ({
      id: v.id,
      label: v.plate_no || v.code || `#${v.id}`,
      sub: [v.make, v.model].filter(Boolean).join(' '),
    })),
    [vehicles],
  );

  const submit = async () => {
    if (!editing && !form.vehicle_id) return toast.error('Pick a vehicle first');
    if (!form.interval_km && !form.interval_days) return toast.error('Give an interval — km, days, or both');
    setBusy(true);
    try {
      // Blank strings must go over as null, not '' — the API validates integers/dates.
      const payload = {
        service_type: form.service_type,
        name: form.name || null,
        interval_km: form.interval_km === '' ? null : Number(form.interval_km),
        interval_days: form.interval_days === '' ? null : Number(form.interval_days),
        last_service_odometer: form.last_service_odometer === '' ? null : Number(form.last_service_odometer),
        last_service_at: form.last_service_at || null,
        notes: form.notes || null,
      };
      if (editing) await api.post(`/ServiceReminders/${reminder.id}`, payload);
      else await api.post('/ServiceReminders', { ...payload, vehicle_id: Number(form.vehicle_id) });
      toast.success(editing ? 'Reminder updated' : 'Reminder created');
      onSaved();
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not save the reminder');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open
      onClose={() => !busy && onClose()}
      title={editing ? `Edit ${reminder.name}` : 'New service reminder'}
      subtitle={editing
        ? 'Saving marks this reminder as manually maintained — the auto-seeder will stop overwriting it.'
        : 'Set the cadence and the last-service anchor; the next-due point is computed from them.'}
      footer={(
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button variant="primary" loading={busy} onClick={submit}>{editing ? 'Save' : 'Create'}</Button>
        </div>
      )}
    >
      <div className="space-y-4">
        {editing ? (
          <div className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
            {reminder.vehicle?.label || `#${reminder.vehicle_id}`} · {TYPES[reminder.service_type] || reminder.service_type}
          </div>
        ) : (
          <>
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">Vehicle<span className="ms-0.5 text-red-500">*</span></span>
              <SearchSelect
                value={form.vehicle_id}
                onChange={(id) => setForm((f) => ({ ...f, vehicle_id: id }))}
                options={options}
                placeholder="Search plate…"
              />
            </div>
            <Select label="Service" value={form.service_type} onChange={set('service_type')}>
              {Object.entries(TYPES).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
            </Select>
          </>
        )}

        <div className="grid grid-cols-2 gap-3">
          <Input label="Every (km)" type="number" min="1" value={form.interval_km} onChange={set('interval_km')} placeholder="e.g. 5000" />
          <Input label="Every (days)" type="number" min="1" value={form.interval_days} onChange={set('interval_days')} placeholder="e.g. 180" />
        </div>
        <div className="grid grid-cols-2 gap-3">
          <Input label="Last service odometer" type="number" min="0" value={form.last_service_odometer} onChange={set('last_service_odometer')} placeholder="km at last service" />
          <Input label="Last service date" type="date" value={form.last_service_at} onChange={set('last_service_at')} />
        </div>
        <Input label="Custom name (optional)" value={form.name} onChange={set('name')} placeholder={TYPES[form.service_type] || ''} />
        <Textarea label="Notes (optional)" rows={2} value={form.notes} onChange={set('notes')} />
      </div>
    </Modal>
  );
}

/**
 * "Schedule service" — POST /complete. This does NOT close the reminder here: it opens a
 * maintenance ticket seeded with this service. The car's record and the reminder's next-due
 * point advance only when that ticket is closed, keeping the ticket the single source of truth.
 */
function ScheduleDialog({ reminder, onClose, onDone }) {
  const toast = useToast();
  const navigate = useNavigate();
  const [odometer, setOdometer] = useState(reminder.vehicle?.odometer ?? '');
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    setBusy(true);
    try {
      const { data } = await api.post(`/ServiceReminders/${reminder.id}/complete`, {
        odometer: odometer === '' ? null : Number(odometer),
      });
      const url = data?.data?.ticket?.url;
      toast.success(data?.message || 'Maintenance ticket opened');
      onDone();
      if (url) navigate(url);
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not open the ticket');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open
      onClose={() => !busy && onClose()}
      size="sm"
      title={`Schedule ${reminder.name}`}
      subtitle={`${reminder.vehicle?.label || `#${reminder.vehicle_id}`} — opens a maintenance ticket for this service. The reminder rolls forward when that ticket is closed.`}
      footer={(
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button variant="primary" loading={busy} onClick={submit}>Open ticket</Button>
        </div>
      )}
    >
      <Input
        label="Odometer now"
        type="number"
        min="0"
        value={odometer}
        onChange={(e) => setOdometer(e.target.value)}
        placeholder="Defaults to the car's current reading"
      />
    </Modal>
  );
}

/**
 * Service Reminders — the fleet's recurring maintenance due points (oil, filters, brakes,
 * tires, battery…). Oil-change and tire reminders are auto-seeded per car from the Oil Change
 * sheet anchors; anything a human edits here flips to manual and the seeder leaves it alone.
 *
 * Three things happen on this page: see what's due, tell the team about it (Notify), and act
 * on it (Schedule service → opens a maintenance ticket). Closing the loop is the ticket's job.
 */
export default function ServiceReminders() {
  const { can } = usePermissions();
  const canManage = can('reminders.manage');
  const toast = useToast();
  // Open on the cars that actually need service (overdue + due soon). OK / No-data rows
  // are hidden by default but one click away via the "All" / "OK" chips.
  const [filter, setFilter] = useState('action');
  // Secondary status filter that lives inside the Tires view (open on cars that need a wrench).
  const [tireStatus, setTireStatus] = useState('action');
  const [q, setQ] = useState('');
  const [type, setType] = useState('all');
  const [busyId, setBusyId] = useState(null);
  const [editing, setEditing] = useState(null);     // reminder | {} for "new"
  const [scheduling, setScheduling] = useState(null);
  const [deleting, setDeleting] = useState(null);
  const [vehicles, setVehicles] = useState([]);

  const fetcher = useCallback(async () => {
    const { data } = await api.get('/ServiceReminders');
    return data.data || [];
  }, []);
  const { data, loading, error, reload } = useFetch(fetcher, [], { refreshInterval: 60000 });

  // Vehicle list for the create picker — only fetched for users who can actually create.
  useEffect(() => {
    if (!canManage) return undefined;
    let alive = true;
    api.get('/Vehicle')
      .then((v) => {
        if (!alive) return;
        const list = v.data?.data;
        setVehicles(Array.isArray(list) ? list : list?.items || []);
      })
      .catch(() => {});
    return () => { alive = false; };
  }, [canManage]);

  const all = useMemo(() => data || [], [data]);

  // Free-text search runs BEFORE the status/type chips so the chip counts always describe
  // what the user is currently looking at.
  const rows = useMemo(() => {
    const needle = q.trim().toLowerCase();
    if (!needle) return all;
    return all.filter((r) => `${r.vehicle?.label || ''} ${r.vehicle?.make || ''} ${r.vehicle?.model || ''} ${r.name || ''}`
      .toLowerCase().includes(needle));
  }, [all, q]);

  // Tire reminders (rotation + change) are auto-seeded fleet-wide — grouped so they can be surfaced
  // on their own, answering "which cars have active tire reminders?".
  const isTire = (r) => r.service_type === 'tire_rotation' || r.service_type === 'tire_change';
  const counts = useMemo(() => {
    const c = { all: rows.length, overdue: 0, due_soon: 0, ok: 0, no_data: 0, tires: 0 };
    rows.forEach((r) => { c[r.status] = (c[r.status] || 0) + 1; if (isTire(r)) c.tires += 1; });
    return c;
  }, [rows]);

  // Which service types actually exist in the current result set — drives the type dropdown.
  const typesPresent = useMemo(() => {
    const seen = new Map();
    rows.forEach((r) => seen.set(r.service_type, (seen.get(r.service_type) || 0) + 1));
    return [...seen.entries()].sort((a, b) => b[1] - a[1]);
  }, [rows]);

  const shown = useMemo(() => {
    let list = rows;
    if (type !== 'all') list = list.filter((r) => r.service_type === type);
    if (filter === 'all') return list;
    if (filter === 'tires') return list.filter(isTire);
    // "Action needed" = the two states that call for a wrench: overdue + due soon.
    if (filter === 'action') return list.filter((r) => r.status === 'overdue' || r.status === 'due_soon');
    return list.filter((r) => r.status === filter);
  }, [rows, filter, type]);

  // Tires get their own organized view: status counts + a per-type split (rotation / change),
  // so the 362-strong list reads as two focused tables instead of one long dump.
  const tire = useMemo(() => {
    const list = rows.filter(isTire);
    const byStatus = { all: list.length, overdue: 0, due_soon: 0, ok: 0, no_data: 0 };
    list.forEach((r) => { byStatus[r.status] = (byStatus[r.status] || 0) + 1; });
    return { list, byStatus };
  }, [rows]);

  const tireShown = useMemo(() => tire.list.filter((r) => {
    if (tireStatus === 'all') return true;
    if (tireStatus === 'action') return r.status === 'overdue' || r.status === 'due_soon';
    return r.status === tireStatus;
  }), [tire.list, tireStatus]);

  const tireGroups = useMemo(() => ([
    { key: 'tire_rotation', label: 'Tire Rotation', hint: 'Even out tread wear — swap positions.', rows: tireShown.filter((r) => r.service_type === 'tire_rotation') },
    { key: 'tire_change',   label: 'Tire Change',   hint: 'Replace worn tires — end of tread life.', rows: tireShown.filter((r) => r.service_type === 'tire_change') },
  ]), [tireShown]);

  // Active communication: alert the fleet team (drivers/technicians) that this car needs a service.
  // Distinct from "Schedule service" — this only tells people, it opens no ticket.
  const notify = async (r) => {
    setBusyId(r.id);
    try {
      const { data: res } = await api.post(`/ServiceReminders/${r.id}/notify`);
      toast.success(res?.message || `Team alerted — ${r.name} on ${r.vehicle?.label || 'vehicle'}`);
      reload({ silent: true });
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not send the notification');
    } finally {
      setBusyId(null);
    }
  };

  // Mute keeps the reminder computing but greys it out — for cars we knowingly park.
  const toggleMute = async (r) => {
    setBusyId(r.id);
    try {
      await api.post(`/ServiceReminders/${r.id}`, { is_muted: !r.is_muted });
      toast.success(r.is_muted ? 'Reminder un-muted' : 'Reminder muted');
      reload({ silent: true });
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not update the reminder');
    } finally {
      setBusyId(null);
    }
  };

  const remove = async () => {
    try {
      await api.delete(`/ServiceReminders/${deleting.id}`);
      toast.success('Reminder deleted');
      setDeleting(null);
      reload({ silent: true });
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not delete the reminder');
    }
  };

  const columns = [
    {
      key: 'vehicle', header: 'Vehicle', cellClass: 'font-medium',
      render: (r) => (
        <div className="min-w-0">
          {r.vehicle_id ? (
            <Link to={`/vehicles/${r.vehicle_id}`} className="font-semibold text-slate-900 hover:text-indigo-600">
              {r.vehicle?.label || `#${r.vehicle_id}`}
            </Link>
          ) : <span className="font-semibold text-slate-900">—</span>}
          <p className="truncate text-xs text-slate-400">{[r.vehicle?.make, r.vehicle?.model].filter(Boolean).join(' ') || '—'}</p>
        </div>
      ),
    },
    {
      key: 'name', header: 'Service',
      render: (r) => (
        <div className="flex items-center gap-2">
          <span className={`font-medium ${r.is_muted ? 'text-slate-400' : 'text-slate-700'}`}>{r.name}</span>
          {r.source === 'auto' && <Badge tone="slate">auto</Badge>}
          {r.is_muted && <Badge tone="gray">muted</Badge>}
        </div>
      ),
    },
    {
      key: 'interval', header: 'Interval', cellClass: 'text-slate-500 whitespace-nowrap',
      render: (r) => [r.interval_km ? `${num(r.interval_km)} km` : null, r.interval_days ? `${r.interval_days}d` : null].filter(Boolean).join(' · ') || '—',
    },
    {
      key: 'last', header: 'Last service', cellClass: 'text-slate-500 whitespace-nowrap',
      render: (r) => r.last_service_odometer != null ? `${num(r.last_service_odometer)} km` : (r.last_service_at ? fmtDate(r.last_service_at) : '—'),
    },
    {
      key: 'next', header: 'Next due', cellClass: 'whitespace-nowrap font-medium text-slate-700',
      render: (r) => r.next_due_odometer != null ? `${num(r.next_due_odometer)} km` : (r.next_due_at ? fmtDate(r.next_due_at) : '—'),
    },
    {
      key: 'status', header: 'Status', align: 'right',
      render: (r) => {
        const s = STATUS[r.status] || STATUS.no_data;
        return (
          <div className="flex items-center justify-end gap-2">
            <span className="text-xs text-slate-400">{remaining(r)}</span>
            <Badge tone={s.tone}>{s.label}</Badge>
          </div>
        );
      },
    },
    {
      key: 'notified', header: 'Alert',
      render: (r) => r.notified ? (
        <div className="flex items-center gap-1.5">
          <Badge tone="green">Notified</Badge>
          {r.last_notified_at && <span className="whitespace-nowrap text-xs text-slate-400" title={fmtDate(r.last_notified_at)}>{fmtAgo(r.last_notified_at)}</span>}
        </div>
      ) : <Badge tone="slate">Not notified</Badge>,
    },
    {
      key: 'actions', header: '', align: 'right',
      render: (r) => {
        if (!canManage) return null;
        return (
          <div className="flex items-center justify-end gap-1.5">
            {/* Tell the team (once), then lock to a disabled "Notified" state. */}
            {r.notified ? (
              <Button size="sm" variant="secondary" disabled title={r.last_notified_at ? `Alert sent ${fmtAgo(r.last_notified_at)}${r.notified_by_name ? ` by ${r.notified_by_name}` : ''}` : 'Alert sent'}>
                <Icon.Check className="h-3.5 w-3.5" /> Notified
              </Button>
            ) : (
              <Button size="sm" variant="secondary" loading={busyId === r.id} onClick={() => notify(r)} title="Alert the drivers and technicians">
                <Icon.Alert className="h-3.5 w-3.5" /> Notify
              </Button>
            )}
            {/* The act: open the maintenance ticket that actually performs the service. */}
            <Button size="sm" variant="primary" onClick={() => setScheduling(r)} title="Open a maintenance ticket for this service">
              <Icon.Wrench className="h-3.5 w-3.5" /> Schedule
            </Button>
            <RowMenu
              onEdit={() => setEditing(r)}
              onMute={() => toggleMute(r)}
              onDelete={() => setDeleting(r)}
              muted={r.is_muted}
            />
          </div>
        );
      },
    },
  ];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Service Reminders"
          subtitle="Every car's recurring service due points — oil, filters, brakes, tires, battery. Oil-change and tire reminders are seeded automatically per car; add or edit any to override. “Schedule” opens the maintenance ticket that performs the work."
        >
          {canManage && (
            <Button variant="primary" onClick={() => setEditing({})}>
              <Icon.Plus className="h-4 w-4" /> New reminder
            </Button>
          )}
        </PageHeader>

        {error && <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>}

        <div className="flex flex-wrap items-center gap-3">
          <SearchInput value={q} onChange={setQ} placeholder="Search plate, make / model or service…" className="w-full max-w-xs" />
          <select
            value={type}
            onChange={(e) => setType(e.target.value)}
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700"
          >
            <option value="all">All services</option>
            {typesPresent.map(([k, n]) => (
              <option key={k} value={k}>{TYPES[k] || k} ({n})</option>
            ))}
          </select>
          <FilterChips
            value={filter}
            onChange={setFilter}
            options={[
              { key: 'action', label: 'Action needed', count: counts.overdue + counts.due_soon, tone: 'amber' },
              { key: 'all', label: 'All', count: counts.all },
              { key: 'overdue', label: 'Overdue', count: counts.overdue, tone: 'red' },
              { key: 'due_soon', label: 'Due soon', count: counts.due_soon, tone: 'amber' },
              { key: 'ok', label: 'OK', count: counts.ok, tone: 'green' },
              { key: 'tires', label: 'Tires 🛞', count: counts.tires, tone: 'indigo' },
            ]}
          />
        </div>

        {loading ? (
          <>
            <MetricGridSkeleton count={3} />
            <SectionCard title="Service reminders"><DataTable loading columns={columns} /></SectionCard>
          </>
        ) : filter === 'tires' ? (
          <>
            <MetricGrid cols={4}>
              <MetricCard label="Tire reminders" value={num(tire.byStatus.all)} tone="indigo" icon={<Icon.Wrench className="h-5 w-5" />} hint="Rotation + change, fleet-wide" />
              <MetricCard label="Overdue" value={num(tire.byStatus.overdue)} tone="red" icon={<Icon.Alert className="h-5 w-5" />} hint="Past their due point — service now" />
              <MetricCard label="Due soon" value={num(tire.byStatus.due_soon)} tone="amber" icon={<Icon.Clock className="h-5 w-5" />} hint="Within 500 km / 7 days of due" />
              <MetricCard label="OK" value={num(tire.byStatus.ok)} tone="green" icon={<Icon.Check className="h-5 w-5" />} hint="Healthy — no action needed" />
            </MetricGrid>

            <FilterChips
              value={tireStatus}
              onChange={setTireStatus}
              options={[
                { key: 'action', label: 'Action needed', count: tire.byStatus.overdue + tire.byStatus.due_soon, tone: 'amber' },
                { key: 'all', label: 'All', count: tire.byStatus.all },
                { key: 'overdue', label: 'Overdue', count: tire.byStatus.overdue, tone: 'red' },
                { key: 'due_soon', label: 'Due soon', count: tire.byStatus.due_soon, tone: 'amber' },
                { key: 'ok', label: 'OK', count: tire.byStatus.ok, tone: 'green' },
              ]}
            />

            {tireGroups.map((g) => (
              <SectionCard
                key={g.key}
                title={`🛞 ${g.label}`}
                subtitle={g.hint}
                actions={<span className="text-xs text-slate-400">{num(g.rows.length)} shown</span>}
              >
                <DataTable
                  rows={g.rows}
                  rowKey={(r) => r.id}
                  columns={columns}
                  highlightRow={(r) => r.status === 'overdue'}
                  empty={tireStatus === 'action' ? 'All caught up — no tires need attention.' : 'No tire reminders in this view.'}
                />
              </SectionCard>
            ))}
          </>
        ) : (
          <>
            <MetricGrid cols={3}>
              <MetricCard label="Total reminders" value={num(counts.all)} tone="indigo" icon={<Icon.Wrench className="h-5 w-5" />} hint="Across the fleet" />
              <MetricCard label="Overdue" value={num(counts.overdue)} tone="red" icon={<Icon.Alert className="h-5 w-5" />} hint="Past their due point — service now" />
              <MetricCard label="Due soon" value={num(counts.due_soon)} tone="amber" icon={<Icon.Clock className="h-5 w-5" />} hint="Within 500 km / 7 days of due" />
            </MetricGrid>

            <SectionCard
              title="Service reminders"
              subtitle="Soonest due first. “Notify” alerts the drivers/technicians; “Schedule” opens the maintenance ticket that performs the service."
              actions={<span className="text-xs text-slate-400">{num(shown.length)} shown</span>}
            >
              <DataTable
                rows={shown}
                rowKey={(r) => r.id}
                columns={columns}
                highlightRow={(r) => r.status === 'overdue'}
                empty={filter === 'action' ? 'All caught up — nothing due right now.' : 'No service reminders in this view.'}
              />
            </SectionCard>
          </>
        )}
      </div>

      {editing && (
        <ReminderDialog
          reminder={editing.id ? editing : null}
          vehicles={vehicles}
          onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); reload({ silent: true }); }}
        />
      )}

      {scheduling && (
        <ScheduleDialog
          reminder={scheduling}
          onClose={() => setScheduling(null)}
          onDone={() => { setScheduling(null); reload({ silent: true }); }}
        />
      )}

      <ConfirmDialog
        open={Boolean(deleting)}
        title="Delete this reminder?"
        message={deleting ? `${deleting.name} on ${deleting.vehicle?.label || `#${deleting.vehicle_id}`} will stop being tracked. Auto-seeded reminders come back on the next sync.` : ''}
        confirmText="Delete"
        variant="danger"
        onConfirm={remove}
        onClose={() => setDeleting(null)}
      />
    </div>
  );
}

/** Kebab menu for the row's secondary actions (edit / mute / delete). */
function RowMenu({ onEdit, onMute, onDelete, muted }) {
  const [open, setOpen] = useState(false);
  const item = 'block w-full px-3 py-1.5 text-left text-slate-600 hover:bg-slate-50';
  return (
    <div className="relative">
      <button type="button" onClick={() => setOpen((o) => !o)} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="More actions">
        <svg className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 6a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm0 5.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm0 5.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z" /></svg>
      </button>
      {open && (
        <>
          <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} />
          <div className="absolute right-0 z-20 mt-1 w-40 overflow-hidden rounded-lg bg-white py-1 text-sm shadow-lg ring-1 ring-slate-200">
            <button type="button" className={item} onClick={() => { setOpen(false); onEdit(); }}>Edit reminder</button>
            <button type="button" className={item} onClick={() => { setOpen(false); onMute(); }}>{muted ? 'Un-mute' : 'Mute'}</button>
            <button type="button" className={`${item} text-red-600 hover:bg-red-50`} onClick={() => { setOpen(false); onDelete(); }}>Delete</button>
          </div>
        </>
      )}
    </div>
  );
}
