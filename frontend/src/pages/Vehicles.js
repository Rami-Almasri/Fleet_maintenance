import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { useToast } from '../components/ui/Toast';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import ConfirmDialog from '../components/ui/ConfirmDialog';
import ActionMenu from '../components/ui/ActionMenu';
import { usePageStat } from '../components/PageStat';
import { usePermissions } from '../hooks/usePermissions';
import VehicleForm, { VEHICLE_STATUSES, vehicleToForm, cleanPayload } from './vehicles/VehicleForm';
import { registerConflict } from '../components/ops/DualState';
import FleetKpiCard from '../components/vehicles/FleetKpiCard';
import FleetCompositionCard from '../components/vehicles/FleetCompositionCard';
import AgeProfileCard from '../components/vehicles/AgeProfileCard';
import FleetStatusDonut from '../components/vehicles/FleetStatusDonut';
import { VehicleThumb, StatusPill, LocationCell, vehicleState, vehicleLocation } from '../components/vehicles/RegistryCells';
import { vehicleName } from '../lib/carAssets';
import { useI18n } from '../i18n/I18nContext';

const PAGE_SIZE = 10;

// A plate's identity is CODE + digits — same digits under a different plate code (e.g. "P 76722"
// vs "U 76722") are different plates. Group by this, never by digits alone.
const plateId = (v) => (v && v.plate_key ? `${v.plate_code ?? ''}:${v.plate_key}` : null);

// Sort rank for the Status column, so sorting groups the states in the order the KPI strip lists
// them rather than alphabetically — "Available, Reserved, Rented, In Maintenance" is the order
// people already read on this page.
const STATE_RANK = { available: 0, reserved: 1, rented: 2, maint: 3, idle: 4 };

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

  // The KPI strip's trend layer. Its own request because it is a different question answered from a
  // different source (contract dates, replayed) — and because a slow replay must never hold up the
  // car list. A failure here silently costs the tiles their percentages and nothing else.
  const [pulse, setPulse] = useState(null);
  useEffect(() => {
    let alive = true;
    api.get('/Vehicle/fleet-mix')
      .then(({ data }) => { if (alive) setPulse(data.data || null); })
      .catch(() => { /* the tiles simply show no trend */ });
    return () => { alive = false; };
  }, []);

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
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [sort, setSort] = useState({ key: 'vehicle', dir: 'asc' });
  const [selected, setSelected] = useState(() => new Set());

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

  /**
   * Column sort, applied on top of the filter result.
   *
   * Deliberately skipped while a reused plate is on screen: that view's whole value is its grouping
   * (each plate's current holder above its previous holders), and re-sorting by a column would
   * shuffle the history back into the general list. The header arrows are hidden in that state so
   * the table never looks sortable while refusing to sort.
   */
  const sorted = useMemo(() => {
    if (plateReuseInResults) return filtered;
    const dir = sort.dir === 'desc' ? -1 : 1;
    const key = (v) => {
      switch (sort.key) {
        case 'model': return vehicleName(v.make, v.model).toLowerCase();
        case 'year': return Number(v.year) || 0;
        case 'plate': return (v.plate_display || v.plate_no || '').toLowerCase();
        case 'state': return STATE_RANK[vehicleState(v)] ?? 9;
        case 'odometer': return Number(v.odometer) || 0;
        case 'location': return vehicleLocation(v, t).text.toLowerCase();
        default: return vehicleName(v.make, v.model).toLowerCase();
      }
    };
    return [...filtered].sort((a, b) => {
      const ka = key(a), kb = key(b);
      if (ka === kb) return 0;
      return (ka < kb ? -1 : 1) * dir;
    });
  }, [filtered, sort, plateReuseInResults, t]);

  const toggleSort = (key) =>
    setSort((s) => ({ key, dir: s.key === key && s.dir === 'asc' ? 'desc' : 'asc' }));

  // How many distinct plates are shared, for the filter-panel label.
  const sharedPlateCount = reusedKeys.size;

  // counts shown on the KPI tiles — over the active fleet (sold/disposed excluded)
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

  const byMake = useMemo(() => {
    const totals = {};
    inService.forEach((v) => {
      const k = (v.make || t('Unspecified')).toUpperCase();
      totals[k] = (totals[k] || 0) + 1;
    });
    return Object.entries(totals)
      .map(([label, value]) => ({ key: label, label, value }))
      .sort((a, b) => b.value - a.value)
      .slice(0, 10);
  }, [inService, t]);

  const resetFilters = (fn) => { fn(); setPage(1); };
  const toggleFlag = (f) => resetFilters(() => setFlag((cur) => (cur === f ? '' : f)));

  // Floating page gauge: share of the active fleet that's available to rent.
  usePageStat({
    percent: active.length ? (availableCount / active.length) * 100 : null,
    label: t('vehicles.kpi.available'),
    color: 'emerald',
    hint: t('vehicles.availableHint', { n: availableCount, total: active.length }),
  });

  const pageCount = Math.ceil(sorted.length / PAGE_SIZE) || 1;
  const safePage = Math.min(page, pageCount);
  const paged = sorted.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE);

  /* ----------------------------- selection ------------------------------ */
  // Selection is keyed on id and survives paging, so ticking rows across two pages and exporting
  // gives you both. The header box governs the CURRENT page only — that is what the reader can see
  // and therefore what they mean by "all".
  const pageAllSelected = paged.length > 0 && paged.every((v) => selected.has(v.id));
  const togglePageSelection = () =>
    setSelected((cur) => {
      const next = new Set(cur);
      paged.forEach((v) => (pageAllSelected ? next.delete(v.id) : next.add(v.id)));
      return next;
    });
  const toggleRow = (id) =>
    setSelected((cur) => {
      const next = new Set(cur);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });

  /* -------------------------------- export ------------------------------ */
  /**
   * CSV of what is on screen — the filtered list, or just the ticked rows when there are any.
   * Exports the columns the table shows, resolved exactly as the table resolved them, so the file
   * and the screen never disagree. Built in the browser: this is the list already fetched, and
   * round-tripping it through the server would risk exporting a different set than was shown.
   */
  const exportCsv = () => {
    const rows = selected.size ? sorted.filter((v) => selected.has(v.id)) : sorted;
    const head = [
      t('vehicles.col.makeModel'), t('vehicles.col.year'), t('vehicles.col.plate'),
      t('vehicles.col.status'), t('vehicles.col.mileage'), t('vehicles.col.location'), 'VIN',
    ];
    const esc = (s) => `"${String(s ?? '').replace(/"/g, '""')}"`;
    const body = rows.map((v) => [
      vehicleName(v.make, v.model),
      v.year ?? '',
      v.plate_display || v.plate_no || '',
      t(`vehicles.state.${vehicleState(v)}`),
      v.odometer ?? '',
      vehicleLocation(v, t).text,
      v.vin || '',
    ].map(esc).join(','));

    const blob = new Blob([`﻿${[head.map(esc).join(','), ...body].join('\r\n')}`], {
      type: 'text/csv;charset=utf-8;',
    });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `fleet-registry-${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    toast.success(t('vehicles.exported', { n: rows.length }));
  };

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

  const fmtKm = (n) => (n == null ? '—' : Number(n).toLocaleString(numLocale));

  /**
   * Two one-glyph markers that ride beside the plate.
   *
   * The old registry gave Condition and Warranty a full column each. This layout has no room for
   * them, and dropping them outright was not an option: a Yellow or Red car must not be handed to a
   * customer, and a car still under cover must not be paid for out of our own pocket. So they stay
   * on every row as dots with the full state in the tooltip — quiet when everything is normal,
   * present when it is not. Both remain filters in the Filters panel.
   */
  const conditionDot = (v) => {
    const g = v.condition_grade;
    if (!g || g === 'green') return null;
    const tone = g === 'red' || g === 'yellow' ? 'bg-rose-500' : 'bg-amber-500';
    return (
      <span
        className={`h-1.5 w-1.5 shrink-0 rounded-full ${tone}`}
        title={t(`vehicles.cond.${g === 'red' ? 'red' : g === 'yellow' ? 'yellow' : 'orange'}`)}
      />
    );
  };
  const warrantyDot = (v) => {
    const state = v.warranty?.state || 'none';
    if (state === 'none' || state === 'expired') return null;
    const w = v.warranty;
    const left = [
      w?.days_remaining != null ? t('warranty.remainingDays', { n: w.days_remaining }) : null,
      w?.km_remaining != null ? t('warranty.remainingKm', { n: Number(w.km_remaining).toLocaleString(numLocale) }) : null,
    ].filter(Boolean).join(' / ');
    return (
      <span
        className={`h-1.5 w-1.5 shrink-0 rounded-full ${state === 'under_warranty' ? 'bg-emerald-500' : 'bg-amber-500'}`}
        title={[t(`warranty.state.${state}`), left].filter(Boolean).join(' — ')}
      />
    );
  };

  /* --------------------------------- KPIs -------------------------------- */
  const trendTitle = pulse
    ? t('vehicles.kpi.trendBasis', { now: pulse.as_of, then: pulse.compared_to })
    : undefined;
  const KPIS = [
    { key: 'available', label: t('vehicles.kpi.available'), value: availableCount, tone: 'emerald', icon: 'car', hint: t('vehicles.kpi.availableHint'), goodWhen: 'up' },
    { key: 'reserved', label: t('vehicles.kpi.reserved'), value: reservedCount, tone: 'blue', icon: 'calendar', hint: t('vehicles.kpi.reservedHint'), goodWhen: 'up' },
    { key: 'rented', label: t('vehicles.kpi.rented'), value: rentedCount, tone: 'violet', icon: 'key', hint: t('vehicles.kpi.rentedHint'), goodWhen: 'up' },
    { key: 'maint', label: t('vehicles.kpi.maint'), value: maintCount, tone: 'amber', icon: 'wrench', hint: t('vehicles.kpi.maintHint'), goodWhen: 'down' },
  ];

  const donutSegments = [
    { key: 'available', label: t('vehicles.kpi.available'), value: availableCount, color: 'emerald' },
    { key: 'reserved', label: t('vehicles.kpi.reserved'), value: reservedCount, color: 'blue' },
    { key: 'rented', label: t('vehicles.kpi.rented'), value: rentedCount, color: 'violet' },
    { key: 'maint', label: t('vehicles.kpi.maint'), value: maintCount, color: 'amber' },
  ];

  const activeFilterCount = [status, warrantyState].filter(Boolean).length + (sharedOnly ? 1 : 0);

  const SortIcon = ({ column }) => {
    if (plateReuseInResults) return null;
    const on = sort.key === column;
    return (
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"
        className={`h-3 w-3 transition ${on ? 'text-slate-600 dark:text-slate-300' : 'text-slate-300 dark:text-slate-600'}`} aria-hidden="true">
        <path d={on && sort.dir === 'desc' ? 'M6 9l6 6 6-6' : 'M6 15l6-6 6 6'} />
      </svg>
    );
  };
  const Th = ({ column, children, align = 'start' }) => (
    <th scope="col" className={`px-4 py-3 text-${align} text-xs font-semibold text-slate-500 dark:text-slate-400`}>
      {column ? (
        <button type="button" onClick={() => toggleSort(column)}
          className="inline-flex items-center gap-1.5 transition hover:text-slate-800 dark:hover:text-slate-200">
          {children}<SortIcon column={column} />
        </button>
      ) : children}
    </th>
  );

  return (
    <>
      <div className="mx-auto max-w-7xl px-4 pb-12 pt-6 sm:px-6 lg:px-8">
        {error && (
          <div className="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-400">
            {error}
          </div>
        )}

        {/* KPI strip — each tile is a live filter over the active fleet (sold/disposed excluded). */}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {KPIS.map((k) => (
            <FleetKpiCard
              key={k.key}
              label={k.label}
              value={loading ? '—' : k.value.toLocaleString(numLocale)}
              hint={k.hint}
              tone={k.tone}
              icon={k.icon}
              goodWhen={k.goodWhen}
              delta={pulse?.delta?.[k.key] ?? null}
              series={pulse?.series?.[k.key] || []}
              trendTitle={trendTitle}
              active={flag === k.key}
              onClick={() => toggleFlag(k.key)}
            />
          ))}
        </div>

        {/* Charts — the IN-SERVICE fleet (ready or rented) for composition and age; the donut
            mirrors the KPI tiles above and so counts the whole active fleet. */}
        <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
          <FleetCompositionCard items={byMake} total={inService.length} />
          <AgeProfileCard vehicles={inService} />
          <FleetStatusDonut segments={donutSegments} total={active.length} />
        </div>

        {/* Fleet Registry */}
        <div className="mt-6 overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-sm dark:border-slate-700/60 dark:bg-slate-900">
          <div className="flex flex-wrap items-center gap-3 px-5 py-4">
            <h2 className="font-display text-lg font-bold tracking-tight text-slate-900 dark:text-slate-50">
              {t('vehicles.registry')}
            </h2>
            <span className="text-sm text-slate-400 tabular-nums">
              {loading ? t('common.loading') : t('vehicles.range', {
                from: sorted.length ? (safePage - 1) * PAGE_SIZE + 1 : 0,
                to: Math.min(safePage * PAGE_SIZE, sorted.length),
                total: sorted.length,
              })}
            </span>

            <div className="ms-auto flex flex-wrap items-center gap-2">
              <div className="relative">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"
                  className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true">
                  <circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" />
                </svg>
                <input
                  value={search}
                  onChange={(e) => resetFilters(() => setSearch(e.target.value))}
                  placeholder={t('vehicles.searchPlaceholder')}
                  aria-label={t('vehicles.searchPlaceholder')}
                  className="w-56 rounded-lg border border-slate-200 bg-white py-2 ps-9 pe-3 text-sm text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 sm:w-64"
                />
              </div>

              <button
                type="button"
                onClick={() => setFiltersOpen((o) => !o)}
                aria-expanded={filtersOpen}
                className={`inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition ${
                  activeFilterCount
                    ? 'border-indigo-300 bg-indigo-50 text-indigo-700 dark:border-indigo-500/40 dark:bg-indigo-500/10 dark:text-indigo-300'
                    : 'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800'
                }`}
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
                  <path d="M4 5h16l-6.5 7.5V19l-3 2v-8.5z" />
                </svg>
                {t('vehicles.filters')}
                {activeFilterCount > 0 && (
                  <span className="rounded-full bg-indigo-600 px-1.5 text-[11px] font-bold text-white tabular-nums">{activeFilterCount}</span>
                )}
              </button>

              <button
                type="button"
                onClick={exportCsv}
                disabled={loading || sorted.length === 0}
                className="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
              >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
                  <path d="M12 16V4m0 0L8 8m4-4l4 4M4 17v2a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-2" />
                </svg>
                {selected.size ? t('vehicles.exportN', { n: selected.size }) : t('vehicles.export')}
              </button>
            </div>
          </div>

          {filtersOpen && (
            <div className="flex flex-wrap items-center gap-3 border-t border-slate-100 bg-slate-50/70 px-5 py-3 dark:border-slate-800 dark:bg-slate-800/40">
              <select
                value={status}
                onChange={(e) => resetFilters(() => setStatus(e.target.value))}
                aria-label={t('vehicles.allStatuses')}
                className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-600 outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300"
              >
                <option value="">{t('vehicles.allStatuses')}</option>
                {VEHICLE_STATUSES.map((s) => <option key={s} value={s}>{t(`vehicles.status.${s}`)}</option>)}
              </select>

              {/* Warranty state. Its own control rather than a KPI tile because it is not a
                  movement — a car can be rented, in the garage or idle and still be under warranty,
                  so it composes with the filters beside it instead of replacing them. */}
              <select
                value={warrantyState}
                onChange={(e) => resetFilters(() => setWarrantyState(e.target.value))}
                aria-label={t('warranty.list.filterLabel')}
                className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-600 outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300"
              >
                <option value="">{t('warranty.list.filterAll')}</option>
                <option value="under_warranty">{t('warranty.list.filterUnder')}</option>
                <option value="expiring_soon">{t('warranty.list.filterSoon')}</option>
                <option value="expired">{t('warranty.list.filterExpired')}</option>
                <option value="none">{t('warranty.list.filterNone')}</option>
              </select>

              {/* Shared-plate filter: show every car sitting on a reused plate (incl. sold holders). */}
              <label title={t('vehicles.sharedPlatesHint')}
                className="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-3 py-1.5 text-sm text-slate-600 dark:border-slate-700 dark:text-slate-300">
                <input
                  type="checkbox"
                  checked={sharedOnly}
                  onChange={(e) => resetFilters(() => setSharedOnly(e.target.checked))}
                  className="h-3.5 w-3.5 accent-amber-500"
                />
                {t('vehicles.sharedPlatesOnly')}{sharedPlateCount ? ` (${sharedPlateCount})` : ''}
              </label>

              {/* Register mismatch: the cars where the "Faster" sheet and our live state disagree.
                  Hidden entirely when there are none — an always-visible zero would read as a
                  broken filter rather than a clean fleet. */}
              {registerClashCount > 0 && (
                <button
                  type="button"
                  onClick={() => toggleFlag('register_clash')}
                  title={t('vehicles.registerClashHint')}
                  className={`inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition ${
                    flag === 'register_clash'
                      ? 'border-amber-400 bg-amber-100 text-amber-800 dark:border-amber-500/50 dark:bg-amber-500/20 dark:text-amber-300'
                      : 'border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-400'
                  }`}
                >
                  ⚠ {t('vehicles.registerClash')} ({registerClashCount})
                </button>
              )}

              {(activeFilterCount > 0 || flag) && (
                <button
                  type="button"
                  onClick={() => resetFilters(() => { setStatus(''); setWarrantyState(''); setSharedOnly(false); setFlag(''); })}
                  className="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                >
                  {t('vehicles.clearFilter')}
                </button>
              )}
            </div>
          )}

          {plateReuseInResults && (
            <div className="flex items-start gap-2 border-t border-amber-200/70 bg-amber-50 px-5 py-3 text-sm text-amber-800 dark:border-amber-500/25 dark:bg-amber-500/10 dark:text-amber-300">
              <span aria-hidden="true">⚠️</span>
              {/* Plain strings rather than inline <b> fragments: the emphasised clauses sit at
                  different points in an Arabic sentence, so splitting the text around markup would
                  force a word order the translation can't honour. */}
              <span>{sharedOnly ? t('vehicles.reuse.sharedOnly') : t('vehicles.reuse.inResults')}</span>
            </div>
          )}

          <div className="overflow-x-auto">
            <table className="w-full min-w-[900px] border-collapse">
              <thead className="border-y border-slate-100 bg-slate-50/60 dark:border-slate-800 dark:bg-slate-800/40">
                <tr>
                  <th scope="col" className="w-10 px-4 py-3">
                    <input
                      type="checkbox"
                      checked={pageAllSelected}
                      onChange={togglePageSelection}
                      aria-label={t('vehicles.selectPage')}
                      className="h-4 w-4 rounded border-slate-300 accent-indigo-600"
                    />
                  </th>
                  <Th column="model">{t('vehicles.col.vehicle')}</Th>
                  <Th column="model">{t('vehicles.col.makeModel')}</Th>
                  <Th column="year">{t('vehicles.col.year')}</Th>
                  <Th column="plate">{t('vehicles.col.plate')}</Th>
                  <Th column="state">{t('vehicles.col.status')}</Th>
                  <Th column="odometer" align="end">{t('vehicles.col.mileage')}</Th>
                  <Th column="location">{t('vehicles.col.location')}</Th>
                  <Th align="end">{t('vehicles.col.actions')}</Th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {loading ? (
                  Array.from({ length: 6 }).map((_, i) => (
                    <tr key={i}>
                      <td colSpan={9} className="px-4 py-4">
                        <div className="h-11 animate-pulse rounded-lg bg-slate-100 dark:bg-slate-800" />
                      </td>
                    </tr>
                  ))
                ) : paged.length === 0 ? (
                  <tr>
                    <td colSpan={9} className="px-4 py-16 text-center text-sm text-slate-400">
                      {t('vehicles.empty')}
                    </td>
                  </tr>
                ) : paged.map((v) => (
                  <tr key={v.id} className="transition hover:bg-slate-50/70 dark:hover:bg-slate-800/40">
                    <td className="px-4 py-3">
                      <input
                        type="checkbox"
                        checked={selected.has(v.id)}
                        onChange={() => toggleRow(v.id)}
                        aria-label={v.plate_display || v.plate_no || String(v.id)}
                        className="h-4 w-4 rounded border-slate-300 accent-indigo-600"
                      />
                    </td>
                    <td className="px-4 py-3">
                      <Link to={`/vehicles/${v.id}`} aria-label={vehicleName(v.make, v.model)}>
                        <VehicleThumb vehicle={v} />
                      </Link>
                    </td>
                    <td className="px-4 py-3">
                      <Link to={`/vehicles/${v.id}`} className="text-sm font-semibold text-slate-800 hover:text-indigo-600 dark:text-slate-100 dark:hover:text-indigo-400">
                        {vehicleName(v.make, v.model)}
                      </Link>
                      {v.is_deferred_maintenance && (
                        <span className="mt-0.5 block text-[11px] font-medium text-amber-600 dark:text-amber-400"
                          title={v.deferred_maintenance_reason || undefined}>
                          {t('vehicles.owesGarage')}
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-sm text-slate-600 tabular-nums dark:text-slate-300">{v.year || '—'}</td>
                    <td className="px-4 py-3">
                      <span className="inline-flex items-center gap-1.5">
                        <span className="font-mono text-sm text-slate-700 dark:text-slate-200">
                          {v.plate_display || v.plate_no || '—'}
                        </span>
                        {conditionDot(v)}
                        {warrantyDot(v)}
                      </span>
                    </td>
                    <td className="px-4 py-3"><StatusPill vehicle={v} /></td>
                    <td className="px-4 py-3 text-end text-sm text-slate-600 tabular-nums dark:text-slate-300">{fmtKm(v.odometer)}</td>
                    <td className="px-4 py-3"><LocationCell vehicle={v} /></td>
                    <td className="px-4 py-3 text-end">
                      <ActionMenu
                        glyph="⋯"
                        label={t('vehicles.rowActions', { plate: v.plate_display || v.plate_no || '' })}
                        items={[
                          { key: 'view', label: t('vehicles.view'), onSelect: () => { window.location.href = `/vehicles/${v.id}`; } },
                          ...(can('vehicles.manage') ? [{ key: 'edit', label: t('common.edit'), onSelect: () => openEdit(v) }] : []),
                          ...(canResolveDefer && v.is_deferred_maintenance
                            ? [{ key: 'resolve', label: t('vehicles.resolve'), onSelect: () => resolveDefer(v) }]
                            : []),
                          ...(can('vehicles.manage') ? [{ key: 'delete', label: t('vehicles.delete'), danger: true, onSelect: () => setToDelete(v) }] : []),
                        ]}
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {!loading && sorted.length > 0 && (
            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-3 text-sm text-slate-500 dark:border-slate-800 dark:text-slate-400">
              <span className="tabular-nums">
                {selected.size
                  ? t('vehicles.selectedN', { n: selected.size })
                  : t('vehicles.range', {
                    from: (safePage - 1) * PAGE_SIZE + 1,
                    to: Math.min(safePage * PAGE_SIZE, sorted.length),
                    total: sorted.length,
                  })}
              </span>
              {/* ‹ / › are direction-dependent glyphs — swap them under RTL so
                  "previous" always points backwards in the reading direction. */}
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  disabled={safePage <= 1}
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  className="rounded-lg border border-slate-200 px-3 py-1.5 font-medium transition hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800"
                >
                  {isRTL ? '›' : '‹'} {t('vehicles.prev')}
                </button>
                <span className="tabular-nums">{t('vehicles.pageOf', { page: safePage, total: pageCount })}</span>
                <button
                  type="button"
                  disabled={safePage >= pageCount}
                  onClick={() => setPage((p) => Math.min(pageCount, p + 1))}
                  className="rounded-lg border border-slate-200 px-3 py-1.5 font-medium transition hover:bg-slate-50 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800"
                >
                  {t('vehicles.next')} {isRTL ? '‹' : '›'}
                </button>
              </div>
            </div>
          )}
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

      {/* The page header's "Add Vehicle" button lives in VehiclesHub and reaches this page through
          a window event: the button is above the tab strip, outside this component's tree, and a
          store or context for one button would be more machinery than the job deserves. */}
      <HeaderBridge onAdd={openCreate} onExport={exportCsv} />
    </>
  );
}

/**
 * Listens for the header's requests. Its own component so the subscriptions re-bind only when the
 * handlers change, rather than on every keystroke in the search box above.
 */
function HeaderBridge({ onAdd, onExport }) {
  // The handlers close over filter state and so are new on every keystroke. Held in a ref and read
  // at fire time, the listeners bind once instead of being torn down and rebuilt as you type.
  const latest = useRef({ onAdd, onExport });
  latest.current = { onAdd, onExport };

  useEffect(() => {
    const add = () => latest.current.onAdd();
    const exp = () => latest.current.onExport();
    window.addEventListener('fleet:add-vehicle', add);
    window.addEventListener('fleet:export-registry', exp);
    return () => {
      window.removeEventListener('fleet:add-vehicle', add);
      window.removeEventListener('fleet:export-registry', exp);
    };
  }, []);
  return null;
}
