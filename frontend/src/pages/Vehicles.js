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
import DualState, { ContractLines, RegisterStatus, registerConflict } from '../components/ops/DualState';
import { CommandPanel, StatGaugeTile } from '../components/ops';
import VehiclesAnalytics from '../components/analytics/VehiclesAnalytics';
import { useI18n } from '../i18n/I18nContext';

const PAGE_SIZE = 12;

// A plate's identity is CODE + digits — same digits under a different plate code (e.g. "P 76722"
// vs "U 76722") are different plates. Group by this, never by digits alone.
const plateId = (v) => (v && v.plate_key ? `${v.plate_code ?? ''}:${v.plate_key}` : null);

export default function Vehicles() {
  const toast = useToast();
  const { can } = usePermissions();
  const { t, lang, isRTL } = useI18n();
  // Thousands separators follow the language; Arabic keeps Latin digits so the
  // numbers stay aligned with the monospace/tabular columns they sit in.
  const numLocale = lang === 'ar' ? 'ar-AE-u-nu-latn' : 'en-US';
  const fetcher = useCallback(async () => {
    const { data } = await api.get('/Vehicle');
    return data.data || [];
  }, []);
  const { data: vehicles, loading, error, reload } = useFetch(fetcher);

  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [flag, setFlag] = useState(''); // '' | 'available' | 'reserved' | 'rented' | 'maint'
  // Warranty state filter — '' | 'under_warranty' | 'expiring_soon' | 'expired' | 'none'.
  // Filtered client-side against the state the SERVER computed per row (VehicleResource.warranty):
  // whether cover still holds depends on months OR kilometres against each car's odometer, so the
  // browser never derives it, only groups by it. @see lib/warranty.js
  const [warrantyState, setWarrantyState] = useState('');
  const [sharedOnly, setSharedOnly] = useState(false); // show only vehicles on a reused (shared) plate
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
      toast.success(t('vehicles.deferCleared'));
      reload();
    } catch (e) {
      toast.error(e.response?.data?.message || t('vehicles.deferClearFailed'));
    }
  };

  const list = useMemo(() => vehicles || [], [vehicles]);

  // Plate reuse: plate identities (code+digits) that live on more than one vehicle row (a plate
  // re-issued after a sale). Used to surface previous/sold holders and to badge them.
  const reusedKeys = useMemo(() => {
    const counts = {};
    list.forEach((v) => { const id = plateId(v); if (id) counts[id] = (counts[id] || 0) + 1; });
    return new Set(Object.keys(counts).filter((k) => counts[k] > 1));
  }, [list]);

  // Order a reused-plate list: reuse groups first, clustered by plate (code+digits), current
  // holder on top, then newest — so every shared plate reads "current car, then its history".
  const orderByPlateGroup = (keys) => (a, b) => {
    const ida = plateId(a), idb = plateId(b);
    const ra = ida && keys.has(ida) ? 0 : 1;
    const rb = idb && keys.has(idb) ? 0 : 1;
    if (ra !== rb) return ra - rb;
    if ((ida || '') !== (idb || '')) return (ida || '') < (idb || '') ? -1 : 1;
    if (!!a.is_current_plate_holder !== !!b.is_current_plate_holder)
      return a.is_current_plate_holder ? -1 : 1;
    return (Number(b.car_serial) || 0) - (Number(a.car_serial) || 0);
  };

  const { rows: filtered, reused: plateReuseInResults } = useMemo(() => {
    const q = search.trim().toLowerCase();
    // Quick filters use the car's live (contract-derived) movement.
    const matchFlag = (v) =>
      !flag ||
      (flag === 'available' && v.available) ||
      (flag === 'reserved' && v.reserved) ||
      (flag === 'rented' && v.rented) ||
      (flag === 'maint' && v.under_maintenance) ||
      // Cars where the fleet register and our live state contradict each other. Not a movement
      // flag like the others — it's the reconciliation list, and it should stay short.
      (flag === 'register_clash' && registerConflict(v));
    const matchSearch = (v) =>
      !q || [v.plate_display, v.plate_no, v.vin, v.make, v.model].some((f) => (f || '').toLowerCase().includes(q));
    // A row with no `warranty` block simply wasn't shipped one (the relation was not eager-loaded).
    // Treated as `none` rather than hidden: filtering a car out because of a payload shape would
    // make the list quietly incomplete, which is worse than showing it as unrecorded.
    const matchWarranty = (v) => !warrantyState || (v.warranty?.state || 'none') === warrantyState;

    // "Shared plate only" filter: EVERY vehicle sitting on a reused plate — including the sold /
    // previous holders (we deliberately don't hide them here, that's the whole point) — grouped
    // current-holder-first. Honors the search box, quick flags, and an explicitly chosen status.
    if (sharedOnly) {
      const rows = list
        .filter((v) => reusedKeys.has(plateId(v))
          && (status ? v.status === status : true) && matchFlag(v) && matchSearch(v) && matchWarranty(v))
        .sort(orderByPlateGroup(reusedKeys));
      return { rows, reused: rows.length > 0 };
    }

    const base = list.filter((v) => {
      // Displayed status is the OfficeManager lifecycle status (status_no). Cars that have
      // left the fleet (sold / disposed) are hidden unless explicitly picked from the dropdown.
      const matchStatus = status ? v.status === status : (v.status !== 'sold' && v.status !== 'disposed');
      return matchStatus && matchFlag(v) && matchSearch(v) && matchWarranty(v);
    });

    // Only when a search actually surfaces a REUSED plate do we change the view: pull the
    // previous/sold holders back in (normally hidden by the status filter) and group each plate
    // current-holder-first, so "search a plate → see the current car first, then its history".
    const involvesReuse = !!q && base.some((v) => reusedKeys.has(plateId(v)));
    if (!involvesReuse) return { rows: base, reused: false };

    const byId = new Map(base.map((v) => [v.id, v]));
    const keys = new Set(base.map((v) => plateId(v)).filter((k) => k && reusedKeys.has(k)));
    list.forEach((v) => {
      const id = plateId(v);
      if (id && keys.has(id) && !byId.has(v.id)) byId.set(v.id, v);
    });
    const merged = Array.from(byId.values()).sort(orderByPlateGroup(keys));
    return { rows: merged, reused: true };
  }, [list, search, status, flag, warrantyState, sharedOnly, reusedKeys]);

  // How many distinct plates are shared, for the checkbox label.
  const sharedPlateCount = reusedKeys.size;

  // counts shown on the quick-filter buttons — over the active fleet (sold/disposed excluded)
  const active = useMemo(() => list.filter((v) => v.status !== 'sold' && v.status !== 'disposed'), [list]);
  const maintCount = useMemo(() => active.filter((v) => v.under_maintenance).length, [active]);
  const rentedCount = useMemo(() => active.filter((v) => v.rented).length, [active]);
  const reservedCount = useMemo(() => active.filter((v) => v.reserved).length, [active]);
  const availableCount = useMemo(() => active.filter((v) => v.available).length, [active]);
  // The chart strip describes the cars that are actually in service — ready to rent or
  // out on a contract. Cars parked in maintenance, out of order, suspended, office use or
  // returned would distort what the fleet is made of, so they're excluded from the charts
  // (the KPI tiles above still count the whole active fleet).
  const inService = useMemo(
    () => list.filter((v) => v.status === 'ready' || v.status === 'rented'),
    [list],
  );
  // Cars whose register word contradicts our live state. Counted over the whole list, not just
  // `active`: a car the register still calls "Active" while we have it marked sold is exactly the
  // kind of mismatch worth surfacing, and excluding sold cars would hide that direction of it.
  const registerClashCount = useMemo(() => list.filter(registerConflict).length, [list]);

  const resetFilters = (fn) => { fn(); setPage(1); };
  const toggleFlag = (f) => resetFilters(() => setFlag((cur) => (cur === f ? '' : f)));

  // Floating page gauge: share of the active fleet that's available to rent.
  usePageStat({
    percent: active.length ? (availableCount / active.length) * 100 : null,
    label: t('vehicles.kpi.available'),
    color: 'emerald',
    hint: t('vehicles.availableHint', { n: availableCount, total: active.length }),
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
        toast.success(data?.message || t('vehicles.updated'));
      } else {
        await api.post('/Vehicle', payload);
        toast.success(t('vehicles.created'));
      }
      setModalOpen(false);
      reload();
    } catch (err) {
      const res = err.response?.data;
      if (res?.errors) {
        setFormErrors(res.errors);
        toast.error(t('vehicles.fixFields'));
      } else {
        toast.error(res?.message || res?.msg || t('vehicles.saveFailed'));
      }
    } finally {
      setSaving(false);
    }
  };

  const confirmDelete = async () => {
    setDeleting(true);
    try {
      await api.delete(`/Vehicle/${toDelete.id}`);
      toast.success(t('vehicles.deleted'));
      setToDelete(null);
      reload();
    } catch (err) {
      toast.error(err.response?.data?.message || t('vehicles.deleteFailed'));
    } finally {
      setDeleting(false);
    }
  };

  // Row presentation helpers (mission-control telemetry rows).
  const fmtKm = (n) => (n == null ? '—' : t('vehicles.km', { n: Number(n).toLocaleString(numLocale) }));
  const rowTone = (v) => {
    if (v.is_deferred_maintenance) return 'rt-paused';
    if (['red', 'yellow'].includes(v.condition_grade)) return 'rt-crit';
    if (v.under_maintenance || v.operational_status === 'maintenance') return 'rt-maint';
    if (v.available) return 'rt-avail';
    return '';
  };
  // Plate-reuse badge: which role this vehicle plays for a shared plate. Only rendered for
  // reused plates so the ordinary fleet list stays clean.
  const plateBadge = (v) => {
    if (!reusedKeys.has(plateId(v))) return null;
    const base = { display: 'inline-flex', alignItems: 'center', gap: 4, marginTop: 3, padding: '1px 7px', borderRadius: 999, fontSize: 10, fontWeight: 700, letterSpacing: '.02em' };
    if (v.is_current_plate_holder) {
      return <span style={{ ...base, background: 'rgba(16,185,129,.14)', color: '#10b981', border: '1px solid rgba(16,185,129,.3)' }}>● {t('vehicles.plate.current')}</span>;
    }
    const gone = ['sold', 'disposed', 'returned'].includes(v.status);
    return gone
      ? <span style={{ ...base, background: 'rgba(148,163,184,.14)', color: '#94a3b8', border: '1px solid rgba(148,163,184,.3)' }}>◍ {t('vehicles.plate.sold')}</span>
      : <span style={{ ...base, background: 'rgba(245,158,11,.14)', color: '#f59e0b', border: '1px solid rgba(245,158,11,.3)' }}>◍ {t('vehicles.plate.previous')}</span>;
  };
  const condChip = (v) => {
    const g = v.condition_grade;
    if (g === 'red') return <span className="ds-chip sm ds-crit"><span className="ds-dot" />{t('vehicles.cond.red')}</span>;
    if (g === 'yellow') return <span className="ds-chip sm ds-crit"><span className="ds-dot" />{t('vehicles.cond.yellow')}</span>;
    if (g === 'orange') return <span className="ds-chip sm ds-paused"><span className="ds-dot" />{t('vehicles.cond.orange')}</span>;
    return <span className="ds-chip sm ds-none"><span className="ds-dot" />{t('vehicles.cond.ok')}</span>;
  };

  /**
   * The warranty chip — could somebody else still be paying for this car?
   *
   * The state is whatever the SERVER computed for this row; nothing here re-derives it. Cover ends
   * on months OR kilometres, whichever comes first, judged against the car's odometer — a browser
   * comparing `expires_on` to today would report cover on exactly the hard-driven cars whose
   * warranties are worth the most and lapse the soonest.
   *
   * `none` is neutral, NOT critical: a car whose booklet is still in the glovebox has not failed at
   * anything, and colouring it like a problem trains people to ignore the colour everywhere else.
   * The tooltip carries what is left on both legs, so the column stays one glyph wide.
   */
  const warrantyChip = (v) => {
    const w = v.warranty;
    const state = w?.state || 'none';
    if (state === 'none') return <span className="ds-chip sm ds-none">{t('warranty.stateShort.none')}</span>;

    const cls = state === 'under_warranty' ? 'ds-ok' : state === 'expiring_soon' ? 'ds-paused' : 'ds-none';
    const left = [
      w?.days_remaining != null ? t('warranty.remainingDays', { n: w.days_remaining }) : null,
      w?.km_remaining != null ? t('warranty.remainingKm', { n: Number(w.km_remaining).toLocaleString(numLocale) }) : null,
      // …and how far PAST the limit, once it is passed. The tooltip on an expired car used to say
      // only "Warranty expired", which cannot distinguish last week from two years ago.
      w?.km_over != null ? t('warranty.overKm', { n: Number(w.km_over).toLocaleString(numLocale) }) : null,
      w?.days_over != null ? t('warranty.overDays', { n: Number(w.days_over).toLocaleString(numLocale) }) : null,
    ].filter(Boolean).join(' / ');

    return (
      <span
        className={`ds-chip sm ${cls}`}
        title={[t(`warranty.state.${state}`), left, w?.distance_unknown ? t('warranty.distanceUnknown') : null]
          .filter(Boolean).join(' — ')}
      >
        <span className="ds-dot" />{t(`warranty.stateShort.${state}`)}
      </span>
    );
  };
  const KPIS = [
    { key: 'available', label: t('vehicles.kpi.available'), value: availableCount, tone: 'avail', icon: 'car', hint: t('vehicles.kpi.availableHint') },
    { key: 'reserved', label: t('vehicles.kpi.reserved'), value: reservedCount, tone: 'reserved', icon: 'calendar', hint: t('vehicles.kpi.reservedHint') },
    { key: 'rented', label: t('vehicles.kpi.rented'), value: rentedCount, tone: 'rented', icon: 'car', hint: t('vehicles.kpi.rentedHint') },
    { key: 'maint', label: t('vehicles.kpi.maint'), value: maintCount, tone: 'maint', icon: 'wrench', hint: t('vehicles.kpi.maintHint') },
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

        {/* Analytics — the in-service fleet only (status ready or rented). */}
        {!loading && <VehiclesAnalytics vehicles={inService} />}

        {/* Fleet Registry — the mission-control telemetry table. */}
        <CommandPanel
          title={t('vehicles.registry')}
          dotColor="#22d3ee"
          label={loading ? t('common.loading') : t('vehicles.countOf', { n: filtered.length, total: list.length })}
          meta={t('vehicles.meta', { available: availableCount, rented: rentedCount, maint: maintCount })}
          bodyFlush
          action={can('vehicles.manage') ? (
            <button className="opx-btn primary" onClick={openCreate}>{t('vehicles.add')}</button>
          ) : null}
        >
          <div className="opx-toolbar">
            <input
              className="opx-input"
              value={search}
              placeholder={t('vehicles.searchPlaceholder')}
              onChange={(e) => resetFilters(() => setSearch(e.target.value))}
            />
            <select className="opx-select" value={status} onChange={(e) => resetFilters(() => setStatus(e.target.value))}>
              <option value="">{t('vehicles.allStatuses')}</option>
              {VEHICLE_STATUSES.map((s) => <option key={s} value={s}>{t(`vehicles.status.${s}`)}</option>)}
            </select>
            {/* Warranty state. Its own dropdown rather than a quick-flag button because it is not a
                movement — a car can be rented, in the garage or idle and still be under warranty,
                so it composes with the filters beside it instead of replacing them. */}
            <select
              className="opx-select"
              value={warrantyState}
              onChange={(e) => resetFilters(() => setWarrantyState(e.target.value))}
              title={t('warranty.list.filterLabel')}
            >
              <option value="">{t('warranty.list.filterAll')}</option>
              <option value="under_warranty">{t('warranty.list.filterUnder')}</option>
              <option value="expiring_soon">{t('warranty.list.filterSoon')}</option>
              <option value="expired">{t('warranty.list.filterExpired')}</option>
              <option value="none">{t('warranty.list.filterNone')}</option>
            </select>
            {/* Shared-plate filter: show every car sitting on a reused plate (incl. sold holders). */}
            <label
              title={t('vehicles.sharedPlatesHint')}
              style={{ display: 'inline-flex', alignItems: 'center', gap: 7, cursor: 'pointer', fontSize: 12.5,
                padding: '0 10px', borderRadius: 8, border: '1px solid var(--line)',
                background: sharedOnly ? 'rgba(245,158,11,.12)' : 'transparent',
                color: sharedOnly ? '#f59e0b' : 'var(--ink-2)' }}
            >
              <input
                type="checkbox"
                checked={sharedOnly}
                onChange={(e) => resetFilters(() => setSharedOnly(e.target.checked))}
                style={{ accentColor: '#f59e0b', cursor: 'pointer' }}
              />
              {t('vehicles.sharedPlatesOnly')}{sharedPlateCount ? ` (${sharedPlateCount})` : ''}
            </label>
            {/* Register mismatch: the cars where the "Faster" sheet and our live state disagree.
                Hidden entirely when there are none — an always-visible zero would read as a
                broken filter rather than a clean fleet. */}
            {registerClashCount > 0 && (
              <button
                className="opx-ibtn"
                onClick={() => toggleFlag('register_clash')}
                title={t('vehicles.registerClashHint')}
                style={{ borderColor: 'rgba(245,158,11,.38)',
                  background: flag === 'register_clash' ? 'rgba(245,158,11,.16)' : 'rgba(245,158,11,.07)',
                  color: '#d97706' }}
              >
                ⚠ {t('vehicles.registerClash')} ({registerClashCount})
              </button>
            )}
            {flag && <button className="opx-ibtn" onClick={() => toggleFlag(flag)}>✕ {t('vehicles.clearFilter')}</button>}
          </div>

          {plateReuseInResults && (
            <div style={{ margin: '0 0 4px', padding: '9px 14px', borderRadius: 10, border: '1px solid rgba(245,158,11,.3)', background: 'rgba(245,158,11,.08)', color: '#f59e0b', fontSize: 12.5, display: 'flex', gap: 8, alignItems: 'center' }}>
              <span>⚠️</span>
              {/* Plain strings rather than inline <b> fragments: the emphasised
                  clauses sit at different points in an Arabic sentence, so
                  splitting the text around markup would force a word order the
                  translation can't honour. */}
              <span>{sharedOnly ? t('vehicles.reuse.sharedOnly') : t('vehicles.reuse.inResults')}</span>
            </div>
          )}

          <div className="opx-tblwrap">
            <table className="opx-tbl">
              <thead>
                <tr>
                  <th>{t('vehicles.col.vehicle')}</th>
                  <th>{t('vehicles.col.yearVin')}</th>
                  <th className="r">{t('vehicles.col.odometer')}</th>
                  {/* Status chips + the live paperwork underneath — no longer maintenance-only. */}
                  <th>{t('vehicles.col.statusContracts')}</th>
                  <th>{t('vehicles.col.condition')}</th>
                  {/* WARRANTY — the column that answers "before we spend on this car, could the
                      manufacturer be paying?" at a glance, next to the condition it so often
                      determines the cost of. */}
                  <th>{t('warranty.list.column')}</th>
                  <th className="r">{t('vehicles.col.actions')}</th>
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  <tr><td colSpan={7}><div className="opx-skel" style={{ height: 260 }} /></td></tr>
                ) : paged.length === 0 ? (
                  <tr><td colSpan={7}><div className="opx-empty"><div className="big">🛰️</div>{t('vehicles.empty')}</div></td></tr>
                ) : paged.map((v) => (
                  <tr key={v.id} className={rowTone(v)}>
                    <td>
                      <Link to={`/vehicles/${v.id}`} className="opx-plate2">{v.plate_display || v.plate_no || '—'}</Link>
                      <div className="opx-sub">{[v.make, v.model].filter(Boolean).join(' ') || '—'}</div>
                      {plateBadge(v)}
                    </td>
                    <td>
                      <div className="opx-mono2">{v.year || '—'}</div>
                      <div className="opx-sub" style={{ fontFamily: 'var(--mono)' }}>{v.vin || '—'}</div>
                    </td>
                    <td className="r opx-mono2">{fmtKm(v.odometer)}</td>
                    <td>
                      <DualState vehicle={v} />
                      {/* What the fleet register calls this car, in its own words. Quiet when it
                          agrees with the chips above, amber when it doesn't — that mismatch is
                          why the dashboard count and the sheet used to drift apart. */}
                      <RegisterStatus vehicle={v} />
                      {/* The live paperwork: every open contract (linked), plus a note wherever
                          our own workflow raised the repair and no OM contract exists. */}
                      <ContractLines
                        vehicle={v}
                        linkTo={(id, node) => <Link to={`/contracts/${id}`}>{node}</Link>}
                      />
                    </td>
                    <td>{condChip(v)}</td>
                    <td>{warrantyChip(v)}</td>
                    <td>
                      <div className="opx-actions">
                        {canResolveDefer && v.is_deferred_maintenance && <button className="opx-ibtn" onClick={() => resolveDefer(v)} title={t('vehicles.resolveDeferHint')}>✓ {t('vehicles.resolve')}</button>}
                        <Link to={`/vehicles/${v.id}`} className="opx-ibtn go">{t('vehicles.view')}</Link>
                        <button className="opx-ibtn" onClick={() => openEdit(v)}>{t('common.edit')}</button>
                        <button className="opx-ibtn danger" onClick={() => setToDelete(v)}>{t('vehicles.delete')}</button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {!loading && filtered.length > 0 && (
            <div className="opx-pager">
              <span>{t('vehicles.range', {
                from: (safePage - 1) * PAGE_SIZE + 1,
                to: Math.min(safePage * PAGE_SIZE, filtered.length),
                total: filtered.length,
              })}</span>
              {/* ‹ / › are direction-dependent glyphs — swap them under RTL so
                  "previous" always points backwards in the reading direction. */}
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <button className="opx-ibtn" disabled={safePage <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))} style={{ opacity: safePage <= 1 ? 0.4 : 1 }}>{isRTL ? '›' : '‹'} {t('vehicles.prev')}</button>
                <span>{t('vehicles.pageOf', { page: safePage, total: pageCount })}</span>
                <button className="opx-ibtn" disabled={safePage >= pageCount} onClick={() => setPage((p) => Math.min(pageCount, p + 1))} style={{ opacity: safePage >= pageCount ? 0.4 : 1 }}>{t('vehicles.next')} {isRTL ? '‹' : '›'}</button>
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
        title={editing ? t('vehicles.editTitle') : t('vehicles.addTitle')}
        subtitle={editing ? editing.vin : t('vehicles.addSubtitle')}
        size="xl"
        footer={
          <>
            <Button variant="secondary" onClick={() => setModalOpen(false)} disabled={saving}>{t('common.cancel')}</Button>
            <Button onClick={save} loading={saving}>{editing ? t('vehicles.saveChanges') : t('vehicles.createVehicle')}</Button>
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
        title={t('vehicles.deleteTitle')}
        confirmText={t('vehicles.delete')}
        message={toDelete ? t('vehicles.deleteMessage', {
          vehicle: [toDelete.make, toDelete.model].filter(Boolean).join(' '),
          plate: toDelete.plate_no || toDelete.vin,
        }) : ''}
      />
    </>
  );
}
