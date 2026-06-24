import { useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { useToast } from '../components/ui/Toast';
import Badge, { VehicleStatusBadge, OperationalBadge } from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import ConfirmDialog from '../components/ui/ConfirmDialog';
import Pagination from '../components/ui/Pagination';
import { PageHeader, SearchInput } from '../components/ui/Misc';
import { Select } from '../components/ui/Field';
import MetricCard, { MetricGrid } from '../components/ui/MetricCard';
import DataTable, { SectionCard } from '../components/ui/Table';
import { MetricGridSkeleton } from '../components/ui/Skeleton';
import Icon from '../components/ui/Icon';
import { usePageStat } from '../components/PageStat';
import VehicleForm, { VEHICLE_STATUSES, vehicleToForm, cleanPayload } from './vehicles/VehicleForm';

const PAGE_SIZE = 12;

export default function Vehicles() {
  const toast = useToast();
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/Vehicle');
    return data.data || [];
  }, []);
  const { data: vehicles, loading, error, reload } = useFetch(fetcher);

  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [flag, setFlag] = useState(''); // '' | 'available' | 'reserved' | 'rented' | 'maint'
  const [page, setPage] = useState(1);

  // create / edit modal
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState({});
  const [formErrors, setFormErrors] = useState({});
  const [saving, setSaving] = useState(false);

  // delete confirm
  const [toDelete, setToDelete] = useState(null);
  const [deleting, setDeleting] = useState(false);

  const list = useMemo(() => vehicles || [], [vehicles]);
  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return list.filter((v) => {
      // Displayed status is the OfficeManager lifecycle status (status_no). Cars that have
      // left the fleet (sold / disposed) are hidden unless explicitly picked from the dropdown.
      const matchStatus = status ? v.status === status : (v.status !== 'sold' && v.status !== 'disposed');
      // Quick filters use the car's live (contract-derived) movement.
      const matchFlag =
        !flag ||
        (flag === 'available' && v.available) ||
        (flag === 'reserved' && v.reserved) ||
        (flag === 'rented' && v.rented) ||
        (flag === 'maint' && v.under_maintenance);
      const matchSearch =
        !q ||
        [v.plate_no, v.vin, v.make, v.model].some((f) => (f || '').toLowerCase().includes(q));
      return matchStatus && matchFlag && matchSearch;
    });
  }, [list, search, status, flag]);

  // counts shown on the quick-filter buttons — over the active fleet (sold/disposed excluded)
  const active = useMemo(() => list.filter((v) => v.status !== 'sold' && v.status !== 'disposed'), [list]);
  const maintCount = useMemo(() => active.filter((v) => v.under_maintenance).length, [active]);
  const rentedCount = useMemo(() => active.filter((v) => v.rented).length, [active]);
  const reservedCount = useMemo(() => active.filter((v) => v.reserved).length, [active]);
  const availableCount = useMemo(() => active.filter((v) => v.available).length, [active]);
  const resetFilters = (fn) => { fn(); setPage(1); };
  const toggleFlag = (f) => resetFilters(() => setFlag((cur) => (cur === f ? '' : f)));

  // Floating page gauge: share of the active fleet that's available to rent.
  usePageStat({
    percent: active.length ? (availableCount / active.length) * 100 : null,
    label: 'Available',
    color: 'emerald',
    hint: `${availableCount} of ${active.length} active cars available to rent`,
  });

  const pageCount = Math.ceil(filtered.length / PAGE_SIZE) || 1;
  const safePage = Math.min(page, pageCount);
  const paged = filtered.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE);

  const openCreate = () => {
    setEditing(null);
    setForm(vehicleToForm(null));
    setFormErrors({});
    setModalOpen(true);
  };
  const openEdit = (v) => {
    setEditing(v);
    setForm(vehicleToForm(v));
    setFormErrors({});
    setModalOpen(true);
  };
  const onField = (field, value) => setForm((f) => ({ ...f, [field]: value }));

  const save = async () => {
    setSaving(true);
    setFormErrors({});
    try {
      const payload = cleanPayload(form);
      if (editing) {
        await api.post(`/Vehicle/${editing.id}`, payload);
        toast.success('Vehicle updated');
      } else {
        await api.post('/Vehicle', payload);
        toast.success('Vehicle created');
      }
      setModalOpen(false);
      reload();
    } catch (err) {
      const res = err.response?.data;
      if (res?.errors) {
        setFormErrors(res.errors);
        toast.error('Please fix the highlighted fields');
      } else {
        toast.error(res?.message || res?.msg || 'Could not save vehicle');
      }
    } finally {
      setSaving(false);
    }
  };

  const confirmDelete = async () => {
    setDeleting(true);
    try {
      await api.delete(`/Vehicle/${toDelete.id}`);
      toast.success('Vehicle deleted');
      setToDelete(null);
      reload();
    } catch (err) {
      toast.error(err.response?.data?.message || 'Could not delete vehicle');
    } finally {
      setDeleting(false);
    }
  };

  // Vehicle list columns — presentation only; all values come straight from the row.
  const columns = [
    {
      key: 'plate', header: 'Plate No.', cellClass: 'font-medium',
      render: (v) => (
        <Link to={`/vehicles/${v.id}`} className="text-indigo-600 hover:text-indigo-700">{v.plate_no || '—'}</Link>
      ),
    },
    {
      key: 'makeModel', header: 'Make / Model', cellClass: 'text-slate-700',
      render: (v) => [v.make, v.model].filter(Boolean).join(' ') || '—',
    },
    {
      key: 'year', header: 'Year', align: 'right', cellClass: 'tabular-nums text-slate-500',
      render: (v) => v.year || '—',
    },
    {
      key: 'vin', header: 'VIN', cellClass: 'font-mono text-xs text-slate-500',
      render: (v) => v.vin,
    },
    {
      // Car status = the OfficeManager lifecycle status (status_no).
      key: 'status', header: 'Car Status',
      tooltip: 'OfficeManager lifecycle status (status_no) — the car’s standing in the fleet, e.g. ready, sold, out of order.',
      render: (v) => (
        <div className="flex flex-wrap items-center gap-1.5">
          <VehicleStatusBadge status={v.status} />
          {v.for_sale && <Badge tone="amber">🏷️ For sale</Badge>}
        </div>
      ),
    },
    {
      // Contract status = the car's live movement from its open contract.
      key: 'contractStatus', header: 'Contract Status',
      tooltip: 'Live operational status derived from the car’s open contract (rented, in maintenance, reserved) — distinct from the OM lifecycle status.',
      render: (v) => (
        <div className="flex flex-wrap items-center gap-1.5">
          <OperationalBadge status={v.operational_status} />
          {v.reserved && <Badge tone="violet">📅 Reserved</Badge>}
          {!v.operational_status && !v.reserved && <span className="text-slate-400">—</span>}
        </div>
      ),
    },
    {
      key: 'actions', header: 'Actions', align: 'right', headerClass: 'sr-only',
      render: (v) => (
        <div className="flex justify-end gap-2">
          <Link to={`/vehicles/${v.id}`}>
            <Button variant="secondary" size="sm">View</Button>
          </Link>
          <Button variant="secondary" size="sm" onClick={() => openEdit(v)}>Edit</Button>
          <Button variant="ghost" size="sm" className="text-red-600 hover:bg-red-50" onClick={() => setToDelete(v)}>
            Delete
          </Button>
        </div>
      ),
    },
  ];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title="Vehicles"
          subtitle={loading ? 'Loading…' : `${filtered.length} of ${list.length} vehicles`}
        >
          <Button onClick={openCreate}>
            <Icon.Plus className="h-4 w-4" />
            Add New Vehicle
          </Button>
        </PageHeader>

        {/* Quick-filter KPI tiles — each card toggles its live-movement filter (sold/disposed excluded). */}
        {loading ? (
          <MetricGridSkeleton count={4} />
        ) : (
          <MetricGrid cols={4}>
            <MetricCard
              label="Available"
              value={availableCount}
              tone={flag === 'available' ? 'emerald' : 'slate'}
              icon={<Icon.Check className="h-5 w-5" />}
              hint="Free to rent or send for maintenance"
              tooltip="Cars with no open contract — free to rent out or send for maintenance."
              onClick={() => toggleFlag('available')}
            />
            <MetricCard
              label="Reserved"
              value={reservedCount}
              tone={flag === 'reserved' ? 'violet' : 'slate'}
              icon={<Icon.Calendar className="h-5 w-5" />}
              hint="Open reservation / booking"
              tooltip="Cars with an open reservation/booking."
              onClick={() => toggleFlag('reserved')}
            />
            <MetricCard
              label="Rented"
              value={rentedCount}
              tone={flag === 'rented' ? 'blue' : 'slate'}
              icon={<Icon.Car className="h-5 w-5" />}
              hint="Currently out on rent"
              tooltip="Cars currently out on rent."
              onClick={() => toggleFlag('rented')}
            />
            <MetricCard
              label="In maintenance"
              value={maintCount}
              tone={flag === 'maint' ? 'amber' : 'slate'}
              icon={<Icon.Wrench className="h-5 w-5" />}
              hint="Currently in the garage"
              tooltip="Cars currently in the garage."
              onClick={() => toggleFlag('maint')}
            />
          </MetricGrid>
        )}

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        <SectionCard
          title="Fleet"
          subtitle={loading ? 'Loading…' : `${filtered.length} of ${list.length} vehicles`}
          actions={
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
              <SearchInput
                className="sm:w-72"
                value={search}
                onChange={(v) => resetFilters(() => setSearch(v))}
                placeholder="Search plate, VIN, make or model…"
              />
              <Select className="sm:w-48" value={status} onChange={(e) => resetFilters(() => setStatus(e.target.value))}>
                <option value="">All statuses</option>
                {VEHICLE_STATUSES.map((s) => (
                  <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>
                ))}
              </Select>
            </div>
          }
        >
          <DataTable
            columns={columns}
            rows={paged}
            rowKey={(v) => v.id}
            loading={loading}
            skeletonRows={PAGE_SIZE}
            empty="No vehicles found. Try adjusting your search or filters."
          />

          {!loading && filtered.length > 0 && (
            <Pagination page={safePage} pageCount={pageCount} total={filtered.length} pageSize={PAGE_SIZE} onPage={setPage} />
          )}
        </SectionCard>
      </div>

      {/* Create / Edit modal */}
      <Modal
        open={modalOpen}
        onClose={() => !saving && setModalOpen(false)}
        title={editing ? 'Edit Vehicle' : 'Add New Vehicle'}
        subtitle={editing ? editing.vin : 'Enter the vehicle details'}
        size="xl"
        footer={
          <>
            <Button variant="secondary" onClick={() => setModalOpen(false)} disabled={saving}>Cancel</Button>
            <Button onClick={save} loading={saving}>{editing ? 'Save Changes' : 'Create Vehicle'}</Button>
          </>
        }
      >
        <VehicleForm values={form} onChange={onField} errors={formErrors} />
      </Modal>

      {/* Delete confirm */}
      <ConfirmDialog
        open={!!toDelete}
        onClose={() => !deleting && setToDelete(null)}
        onConfirm={confirmDelete}
        loading={deleting}
        title="Delete vehicle?"
        confirmText="Delete"
        message={toDelete ? `This will remove ${[toDelete.make, toDelete.model].filter(Boolean).join(' ')} (${toDelete.plate_no || toDelete.vin}). This can be undone via the database (soft delete).` : ''}
      />
    </div>
  );
}
