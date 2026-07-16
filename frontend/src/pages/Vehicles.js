import { useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { useToast } from '../components/ui/Toast';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import ConfirmDialog from '../components/ui/ConfirmDialog';
import { usePageStat } from '../components/PageStat';
import { usePermissions } from '../hooks/usePermissions';
import VehicleForm, { VEHICLE_STATUSES, vehicleToForm, cleanPayload } from './vehicles/VehicleForm';
import DualState from '../components/ops/DualState';
import { CommandPanel, StatGaugeTile } from '../components/ops';

const PAGE_SIZE = 12;

export default function Vehicles() {
  const toast = useToast();
  const { can } = usePermissions();
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

  // Deferred Maintenance: supervisors/ops can Resolve (dismiss) the "owes maintenance" flag.
  const canResolveDefer = can('maintenance.manage');
  const resolveDefer = async (v) => {
    try {
      await api.delete(`/Vehicle/${v.id}/defer-maintenance`);
      toast.success('Deferred-maintenance flag cleared');
      reload();
    } catch (e) {
      toast.error(e.response?.data?.message || 'Could not clear the flag');
    }
  };

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
        const { data } = await api.post(`/Vehicle/${editing.id}`, payload);
        toast.success(data?.message || 'Vehicle updated');
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

  // Row presentation helpers (mission-control telemetry rows).
  const fmtKm = (n) => (n == null ? '—' : `${Number(n).toLocaleString()} km`);
  const rowTone = (v) => {
    if (v.is_deferred_maintenance) return 'rt-paused';
    if (['red', 'yellow'].includes(v.condition_grade)) return 'rt-crit';
    if (v.under_maintenance || v.operational_status === 'maintenance') return 'rt-maint';
    if (v.available) return 'rt-avail';
    return '';
  };
  const condChip = (v) => {
    const g = v.condition_grade;
    if (g === 'red' || g === 'yellow') return <span className="ds-chip sm ds-crit"><span className="ds-dot" />Grounded</span>;
    if (g === 'orange') return <span className="ds-chip sm ds-paused"><span className="ds-dot" />Watch</span>;
    return <span className="ds-chip sm ds-none"><span className="ds-dot" />OK</span>;
  };
  const KPIS = [
    { key: 'available', label: 'Available', value: availableCount, tone: 'avail', icon: 'car', hint: 'Free to rent or send for maintenance' },
    { key: 'reserved', label: 'Reserved', value: reservedCount, tone: 'reserved', icon: 'calendar', hint: 'Open reservation / booking' },
    { key: 'rented', label: 'Rented', value: rentedCount, tone: 'rented', icon: 'car', hint: 'Currently out on rent' },
    { key: 'maint', label: 'In Maintenance', value: maintCount, tone: 'maint', icon: 'wrench', hint: 'Currently in the garage' },
  ];

  return (
    <>
    <div className="opx">
      <div className="opx-body">
        {error && (
          <div style={{ marginBottom: 16, borderRadius: 12, border: '1px solid rgba(251,113,133,.3)', background: 'rgba(251,113,133,.08)', color: '#fb7185', padding: '12px 16px', fontSize: 13 }}>{error}</div>
        )}

        {/* KPI strip — each tile is a live filter over the active fleet (sold/disposed excluded). */}
        <div className="opx-grid opx-c12" style={{ marginBottom: 16 }}>
          {KPIS.map((k) => (
            <div className="opx-span-3" key={k.key}>
              <StatGaugeTile
                label={k.label}
                value={loading ? '—' : k.value}
                hint={k.hint}
                tone={k.tone}
                icon={k.icon}
                percent={active.length ? (k.value / active.length) * 100 : 0}
                active={flag === k.key}
                onClick={() => toggleFlag(k.key)}
              />
            </div>
          ))}
        </div>

        {/* Fleet Registry — the mission-control telemetry table. */}
        <CommandPanel
          title="Fleet Registry"
          dotColor="#22d3ee"
          label={loading ? 'loading' : `${filtered.length} of ${list.length}`}
          meta={`${availableCount} available · ${rentedCount} on rent · ${maintCount} in shop`}
          bodyFlush
          action={can('vehicles.manage') ? (
            <button className="opx-btn primary" onClick={openCreate}>+ Add Vehicle</button>
          ) : null}
        >
          <div className="opx-toolbar">
            <input
              className="opx-input"
              value={search}
              placeholder="Search plate, VIN, make or model…"
              onChange={(e) => resetFilters(() => setSearch(e.target.value))}
            />
            <select className="opx-select" value={status} onChange={(e) => resetFilters(() => setStatus(e.target.value))}>
              <option value="">All statuses</option>
              {VEHICLE_STATUSES.map((s) => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
            </select>
            {flag && <button className="opx-ibtn" onClick={() => toggleFlag(flag)}>✕ Clear filter</button>}
          </div>

          <div className="opx-tblwrap">
            <table className="opx-tbl">
              <thead>
                <tr>
                  <th>Vehicle</th>
                  <th>Year · VIN</th>
                  <th className="r">Odometer</th>
                  <th>Rental · Maintenance</th>
                  <th>Condition</th>
                  <th className="r">Actions</th>
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  <tr><td colSpan={6}><div className="opx-skel" style={{ height: 260 }} /></td></tr>
                ) : paged.length === 0 ? (
                  <tr><td colSpan={6}><div className="opx-empty"><div className="big">🛰️</div>No vehicles match your search or filters.</div></td></tr>
                ) : paged.map((v) => (
                  <tr key={v.id} className={rowTone(v)}>
                    <td>
                      <Link to={`/vehicles/${v.id}`} className="opx-plate2">{v.plate_no || '—'}</Link>
                      <div className="opx-sub">{[v.make, v.model].filter(Boolean).join(' ') || '—'}</div>
                    </td>
                    <td>
                      <div className="opx-mono2">{v.year || '—'}</div>
                      <div className="opx-sub" style={{ fontFamily: 'var(--mono)' }}>{v.vin || '—'}</div>
                    </td>
                    <td className="r opx-mono2">{fmtKm(v.odometer)}</td>
                    <td><DualState vehicle={v} /></td>
                    <td>{condChip(v)}</td>
                    <td>
                      <div className="opx-actions">
                        {canResolveDefer && v.is_deferred_maintenance && <button className="opx-ibtn" onClick={() => resolveDefer(v)} title="Clear the deferred-maintenance flag">✓ Resolve</button>}
                        <Link to={`/vehicles/${v.id}`} className="opx-ibtn go">View</Link>
                        <button className="opx-ibtn" onClick={() => openEdit(v)}>Edit</button>
                        <button className="opx-ibtn danger" onClick={() => setToDelete(v)}>Delete</button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {!loading && filtered.length > 0 && (
            <div className="opx-pager">
              <span>{(safePage - 1) * PAGE_SIZE + 1}–{Math.min(safePage * PAGE_SIZE, filtered.length)} of {filtered.length}</span>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <button className="opx-ibtn" disabled={safePage <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))} style={{ opacity: safePage <= 1 ? 0.4 : 1 }}>‹ Prev</button>
                <span>Page {safePage} / {pageCount}</span>
                <button className="opx-ibtn" disabled={safePage >= pageCount} onClick={() => setPage((p) => Math.min(pageCount, p + 1))} style={{ opacity: safePage >= pageCount ? 0.4 : 1 }}>Next ›</button>
              </div>
            </div>
          )}
        </CommandPanel>
      </div>
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
    </>
  );
}
