// WHERE ON THE CAR — the control room for the fault location picker.
//
// A fault is three facts: WHAT it is, HOW MANY there are, and WHERE on the car they are. The first is
// curated on the Keyword Risk page; this page owns the other two. Everything the inspector sees when
// the picker says "Say where on the car — this fault cannot be filed without a location" is decided
// here, and nothing about it is hard-coded per fault type:
//
//   Places        the chips themselves — add, rename (EN + AR), re-group, re-grade precision, alias,
//                 reorder, retire. What the picker offers.
//   Sections      the collapsible headings (Exterior, Wheels & Tyres, …) the places sit under.
//   Fault types   whether a type MUST name a place, MAY name one, or is never asked. This is the
//                 switch that makes the red asterisk and the refusal message appear.
//   How many      the cap on a single fault's quantity.
//
// Every tab shows where its answer came from, and the last tab renders the real picker component
// against the live vocabulary — so a change is checked against what the inspector will see, not
// against a description of it. See [[traceability-visibility-requirement]].

import { useCallback, useMemo, useState } from 'react';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { useToast } from '../components/ui/Toast';
import { usePermissions } from '../hooks/usePermissions';
import { useI18n } from '../i18n/I18nContext';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Icon from '../components/ui/Icon';
import Modal from '../components/ui/Modal';
import Tabs from '../components/ui/Tabs';
import Segmented from '../components/ui/Segmented';
import ConfirmDialog from '../components/ui/ConfirmDialog';
import { Card, PageHeader, SearchInput, TableSkeleton, EmptyState, ErrorState } from '../components/ui/Misc';
import { Input, Select } from '../components/ui/Field';
import FaultDetailPicker from '../components/workflow/FaultDetailPicker';
import { num } from '../lib/format';

const MODES = ['required', 'optional', 'none'];
const MODE_TONE = { required: 'red', optional: 'amber', none: 'gray' };

// Where a policy row's word comes from. `keyword` is the library word no fault or damage TYPE owns —
// it still reaches the inspector's picker, so it belongs on this tab like the other two.
const CATALOG_TONE = { fault: 'amber', damage: 'blue', keyword: 'violet' };

const emptyPlace = {
  name: '', name_ar: '', group_key: '', precision: 'panel',
  inspection_zone: '', area_key: '', aliases: '', is_active: true,
};
const emptySection = { label: '', label_ar: '', is_active: true };

/** The one place this page turns an API error into a sentence — every save funnels through it. */
function errorText(err, fallback) {
  const res = err.response?.data;
  const first = res?.errors && Object.values(res.errors)[0]?.[0];
  return first || res?.message || res?.msg || fallback;
}

export default function VehicleLocations() {
  const toast = useToast();
  const { can } = usePermissions();
  const { t, lang } = useI18n();
  const canManage = can('maintenance.manage');

  const fetcher = useCallback(async () => {
    const { data } = await api.get('/vehicle-locations');
    return data.data || {};
  }, []);
  const { data, loading, error, reload } = useFetch(fetcher);

  const groups = useMemo(() => data?.groups || [], [data]);
  const locations = useMemo(() => data?.locations || [], [data]);
  const policy = useMemo(() => data?.policy || [], [data]);
  const precisions = useMemo(() => data?.precisions || [], [data]);
  const zones = useMemo(() => data?.zones || [], [data]);
  const areaKeys = useMemo(() => data?.area_keys || [], [data]);
  const counts = data?.counts || { total: 0, active: 0, retired: 0, sections: 0, in_use: 0 };
  const maxQuantity = data?.max_quantity || 40;
  const defaultMaxQuantity = data?.defaults?.max_quantity || 40;

  const [tab, setTab] = useState('places');
  const [busy, setBusy] = useState(false);

  // Labels in the active language, with the other one shown as a subtitle so a missing Arabic term is
  // visible on this page rather than only in production Arabic.
  const groupLabel = (key) => {
    const g = groups.find((x) => x.key === key);
    if (!g) return key;
    return lang === 'ar' && g.label_ar ? g.label_ar : g.label;
  };
  const precisionLabel = (key) => t(`vehicleLocations.precision.${key}`);
  const modeLabel = (m) => t(`vehicleLocations.mode.${m}`);
  const catalogLabel = (c) => t(`vehicleLocations.catalog.${c}`);

  // ── Places ──────────────────────────────────────────────────────────────────
  const [search, setSearch] = useState('');
  const [sectionFilter, setSectionFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [placeModal, setPlaceModal] = useState(false);
  const [editingPlace, setEditingPlace] = useState(null);
  const [placeForm, setPlaceForm] = useState(emptyPlace);
  const [placeToDelete, setPlaceToDelete] = useState(null);

  const visiblePlaces = useMemo(() => {
    const q = search.trim().toLowerCase();
    return locations.filter((l) => {
      const hit = !q
        || [l.name, l.name_ar, l.slug, l.inspection_zone, l.area_key, ...(l.aliases || [])]
          .some((f) => String(f || '').toLowerCase().includes(q));
      const inSection = !sectionFilter || l.group_key === sectionFilter;
      const status = !statusFilter
        || (statusFilter === 'active' ? l.is_active : statusFilter === 'retired' ? !l.is_active : l.in_use);
      return hit && inSection && status;
    });
  }, [locations, search, sectionFilter, statusFilter]);

  const openCreatePlace = () => {
    setEditingPlace(null);
    setPlaceForm({ ...emptyPlace, group_key: sectionFilter || groups[0]?.key || '' });
    setPlaceModal(true);
  };
  const openEditPlace = (l) => {
    setEditingPlace(l);
    setPlaceForm({
      name: l.name,
      name_ar: l.name_ar || '',
      group_key: l.group_key,
      precision: l.precision,
      inspection_zone: l.inspection_zone || '',
      area_key: l.area_key || '',
      aliases: (l.aliases || []).join(', '),
      is_active: l.is_active,
    });
    setPlaceModal(true);
  };

  const savePlace = async () => {
    setBusy(true);
    try {
      const payload = {
        name: placeForm.name.trim(),
        name_ar: placeForm.name_ar.trim() || null,
        group_key: placeForm.group_key,
        precision: placeForm.precision,
        inspection_zone: placeForm.inspection_zone || null,
        area_key: placeForm.area_key || null,
        // Comma-separated in the box, a list on the wire. Aliases are what makes the picker's search
        // find a place by the word the inspector actually says ("جنط", "bonnet", "front lip").
        aliases: placeForm.aliases.split(',').map((a) => a.trim()).filter(Boolean),
        is_active: placeForm.is_active,
      };
      const { data: res } = editingPlace
        ? await api.post(`/vehicle-locations/${editingPlace.id}`, payload)
        : await api.post('/vehicle-locations', payload);
      toast.success(res.message);
      setPlaceModal(false);
      reload();
    } catch (err) {
      toast.error(errorText(err, t('vehicleLocations.saveError')));
    } finally {
      setBusy(false);
    }
  };

  const togglePlace = async (l) => {
    setBusy(true);
    try {
      const { data: res } = await api.post(`/vehicle-locations/${l.id}/toggle`, { is_active: !l.is_active });
      toast.success(res.message);
      reload();
    } catch (err) {
      toast.error(errorText(err, t('vehicleLocations.saveError')));
    } finally {
      setBusy(false);
    }
  };

  const deletePlace = async () => {
    setBusy(true);
    try {
      const { data: res } = await api.delete(`/vehicle-locations/${placeToDelete.id}`);
      toast.success(res.message);
      setPlaceToDelete(null);
      reload();
    } catch (err) {
      toast.error(errorText(err, t('vehicleLocations.saveError')));
    } finally {
      setBusy(false);
    }
  };

  /**
   * Move a place one step within its own section.
   *
   * Order is saved as the section's full ordered id list, not as one row's number — the server spaces
   * them evenly, so a section can never end up with two places claiming the same position.
   */
  const movePlace = async (l, delta) => {
    const siblings = locations
      .filter((x) => x.group_key === l.group_key)
      .sort((a, b) => a.sort_order - b.sort_order || a.name.localeCompare(b.name));
    const i = siblings.findIndex((x) => x.id === l.id);
    const j = i + delta;
    if (i < 0 || j < 0 || j >= siblings.length) return;

    const order = siblings.map((x) => x.id);
    [order[i], order[j]] = [order[j], order[i]];

    setBusy(true);
    try {
      await api.post('/vehicle-locations/reorder', { order });
      reload();
    } catch (err) {
      toast.error(errorText(err, t('vehicleLocations.saveError')));
    } finally {
      setBusy(false);
    }
  };

  // ── Sections ────────────────────────────────────────────────────────────────
  const [sectionModal, setSectionModal] = useState(false);
  const [editingSection, setEditingSection] = useState(null);
  const [sectionForm, setSectionForm] = useState(emptySection);
  const [sectionToDelete, setSectionToDelete] = useState(null);

  const openCreateSection = () => {
    setEditingSection(null);
    setSectionForm(emptySection);
    setSectionModal(true);
  };
  const openEditSection = (g) => {
    setEditingSection(g);
    setSectionForm({ label: g.label, label_ar: g.label_ar || '', is_active: g.is_active });
    setSectionModal(true);
  };

  const saveSection = async () => {
    setBusy(true);
    try {
      const payload = {
        label: sectionForm.label.trim(),
        label_ar: sectionForm.label_ar.trim() || null,
        is_active: sectionForm.is_active,
      };
      const { data: res } = editingSection
        ? await api.post(`/vehicle-locations/groups/${editingSection.id}`, payload)
        : await api.post('/vehicle-locations/groups', payload);
      toast.success(res.message);
      setSectionModal(false);
      reload();
    } catch (err) {
      toast.error(errorText(err, t('vehicleLocations.saveError')));
    } finally {
      setBusy(false);
    }
  };

  const deleteSection = async () => {
    setBusy(true);
    try {
      const { data: res } = await api.delete(`/vehicle-locations/groups/${sectionToDelete.id}`);
      toast.success(res.message);
      setSectionToDelete(null);
      reload();
    } catch (err) {
      toast.error(errorText(err, t('vehicleLocations.saveError')));
    } finally {
      setBusy(false);
    }
  };

  const moveSection = async (g, delta) => {
    const i = groups.findIndex((x) => x.id === g.id);
    const j = i + delta;
    if (i < 0 || j < 0 || j >= groups.length) return;
    const order = groups.map((x) => x.id);
    [order[i], order[j]] = [order[j], order[i]];

    setBusy(true);
    try {
      await api.post('/vehicle-locations/groups/reorder', { order });
      reload();
    } catch (err) {
      toast.error(errorText(err, t('vehicleLocations.saveError')));
    } finally {
      setBusy(false);
    }
  };

  // ── Fault types (the policy) ────────────────────────────────────────────────
  const [policySearch, setPolicySearch] = useState('');
  const [policyMode, setPolicyMode] = useState('');
  const [policyCatalog, setPolicyCatalog] = useState('');

  const visiblePolicy = useMemo(() => {
    const q = policySearch.trim().toLowerCase();
    return policy.filter((p) => {
      const hit = !q || [p.name, p.name_ar, p.slug, p.category_key].some((f) => String(f || '').toLowerCase().includes(q));
      return hit && (!policyMode || p.mode === policyMode) && (!policyCatalog || p.catalog === policyCatalog);
    });
  }, [policy, policySearch, policyMode, policyCatalog]);

  const setMode = async (row, mode) => {
    if (mode === row.mode) return;
    setBusy(true);
    try {
      const { data: res } = await api.post('/vehicle-locations/policy', { catalog: row.catalog, id: row.id, mode });
      toast.success(res.message);
      reload();
    } catch (err) {
      toast.error(errorText(err, t('vehicleLocations.saveError')));
    } finally {
      setBusy(false);
    }
  };

  const resetMode = async (row) => {
    setBusy(true);
    try {
      const { data: res } = await api.post('/vehicle-locations/policy/reset', { catalog: row.catalog, id: row.id });
      toast.success(res.message);
      reload();
    } catch (err) {
      toast.error(errorText(err, t('vehicleLocations.saveError')));
    } finally {
      setBusy(false);
    }
  };

  // ── How many + live preview ─────────────────────────────────────────────────
  const [maxInput, setMaxInput] = useState('');
  const [previewValue, setPreviewValue] = useState({});
  const [previewType, setPreviewType] = useState('');

  const saveMax = async () => {
    setBusy(true);
    try {
      const { data: res } = await api.post('/vehicle-locations/settings', { max_quantity: Number(maxInput) });
      toast.success(res.message);
      setMaxInput('');
      reload();
    } catch (err) {
      toast.error(errorText(err, t('vehicleLocations.saveError')));
    } finally {
      setBusy(false);
    }
  };

  // The picker takes the exact shapes the workflow endpoint ships, rebuilt here from the live rows —
  // so the preview cannot be "close to" what the inspector sees; it IS what the inspector sees.
  const previewGroups = useMemo(() => groups
    .filter((g) => g.is_active)
    .map((g) => ({
      key: g.key,
      label: g.label,
      label_ar: g.label_ar,
      locations: locations
        .filter((l) => l.group_key === g.key && l.is_active)
        .sort((a, b) => a.sort_order - b.sort_order)
        .map((l) => ({ key: l.slug, label: l.name, label_ar: l.name_ar, precision: l.precision, aliases: l.aliases })),
    }))
    .filter((g) => g.locations.length > 0), [groups, locations]);

  const previewPolicy = useMemo(
    () => Object.fromEntries(policy.map((p) => [p.name, p.mode])),
    [policy],
  );
  const previewChoices = useMemo(
    () => policy.filter((p) => p.is_active).slice(0, 400),
    [policy],
  );

  const tabs = [
    { key: 'places', label: t('vehicleLocations.tabPlaces'), badge: num(counts.total) },
    { key: 'sections', label: t('vehicleLocations.tabSections'), badge: num(counts.sections) },
    { key: 'types', label: t('vehicleLocations.tabTypes'), badge: num(policy.length) },
    { key: 'preview', label: t('vehicleLocations.tabPreview') },
  ];

  if (error) {
    return (
      <div className="py-8">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <ErrorState title={t('vehicleLocations.loadError')} message={error} onRetry={reload} />
        </div>
      </div>
    );
  }

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader title={t('vehicleLocations.title')} subtitle={t('vehicleLocations.subtitle')}>
          {canManage && tab === 'places' && (
            <Button onClick={openCreatePlace}><Icon.Plus className="h-4 w-4" />{t('vehicleLocations.addPlace')}</Button>
          )}
          {canManage && tab === 'sections' && (
            <Button onClick={openCreateSection}><Icon.Plus className="h-4 w-4" />{t('vehicleLocations.addSection')}</Button>
          )}
        </PageHeader>

        {/* Headline counts. `in_use` is the one that matters before changing anything: it is how many
            places already carry recorded faults and therefore can only be retired, never deleted. */}
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
          {[
            { k: 'active', v: counts.active },
            { k: 'retired', v: counts.retired },
            { k: 'inUse', v: counts.in_use },
            { k: 'sections', v: counts.sections },
          ].map(({ k, v }) => (
            <div key={k} className="rounded-2xl border border-slate-200/60 bg-white px-5 py-4 shadow-soft">
              <p className="text-xs font-medium text-slate-500">{t(`vehicleLocations.tile.${k}`)}</p>
              <p className="mt-1 font-display text-2xl font-bold tracking-tight text-slate-900">{loading ? '…' : num(v)}</p>
            </div>
          ))}
        </div>

        <Tabs tabs={tabs} active={tab} onChange={setTab} ariaLabel={t('vehicleLocations.title')} />

        {/* ── PLACES ─────────────────────────────────────────────────────────── */}
        {tab === 'places' && (
          <div className="space-y-4" id="panel-places" role="tabpanel" aria-labelledby="tab-places">
            <div className="flex flex-col gap-3 sm:flex-row">
              <SearchInput className="flex-1" value={search} onChange={setSearch} placeholder={t('vehicleLocations.searchPlaces')} />
              <Select className="sm:w-52" value={sectionFilter} onChange={(e) => setSectionFilter(e.target.value)}>
                <option value="">{t('vehicleLocations.allSections')}</option>
                {groups.map((g) => <option key={g.key} value={g.key}>{lang === 'ar' && g.label_ar ? g.label_ar : g.label}</option>)}
              </Select>
              <Select className="sm:w-44" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
                <option value="">{t('vehicleLocations.allStatuses')}</option>
                <option value="active">{t('vehicleLocations.statusActive')}</option>
                <option value="retired">{t('vehicleLocations.statusRetired')}</option>
                <option value="used">{t('vehicleLocations.statusUsed')}</option>
              </Select>
            </div>

            <Card>
              <div className="overflow-x-auto">
                <table className="min-w-full border-separate border-spacing-0 text-sm">
                  <thead className="bg-slate-50/90">
                    <tr className="text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                      <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('vehicleLocations.colPlace')}</th>
                      <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('vehicleLocations.colSection')}</th>
                      <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('vehicleLocations.colPrecision')}</th>
                      <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('vehicleLocations.colAliases')}</th>
                      <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('vehicleLocations.colUsed')}</th>
                      <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('vehicleLocations.colStatus')}</th>
                      <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-end">{t('vehicleLocations.colActions')}</th>
                    </tr>
                  </thead>

                  {loading ? <TableSkeleton cols={7} /> : (
                    <tbody>
                      {visiblePlaces.map((l) => (
                        <tr key={l.id} className={`bg-white transition-colors even:bg-slate-50/40 hover:bg-indigo-50/40 ${l.is_active ? '' : 'opacity-60'}`}>
                          <td className="border-b border-slate-100 px-5 py-3.5">
                            <div className="font-medium text-slate-900">{lang === 'ar' && l.name_ar ? l.name_ar : l.name}</div>
                            <div className="text-xs text-slate-400" dir="auto">
                              {(lang === 'ar' ? l.name : l.name_ar) || <span className="text-amber-500">{t('vehicleLocations.noArabic')}</span>}
                              <span className="mx-1 text-slate-300">·</span>
                              <code className="text-[11px] text-slate-400">{l.slug}</code>
                            </div>
                          </td>
                          <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">{groupLabel(l.group_key)}</td>
                          <td className="border-b border-slate-100 px-5 py-3.5">
                            <Badge tone="violet">{precisionLabel(l.precision)}</Badge>
                            {/* The join back to the inspection hotspot diagram. Named because a place
                                WITH a zone can be cross-read against inspection photos and one
                                without cannot — that is a real difference, not a blank field. */}
                            {l.inspection_zone && (
                              <div className="mt-1 text-[11px] text-slate-400">{t('vehicleLocations.zoneOn', { zone: l.inspection_zone })}</div>
                            )}
                          </td>
                          <td className="border-b border-slate-100 px-5 py-3.5 max-w-xs text-xs text-slate-500" dir="auto">
                            {(l.aliases || []).join(', ') || <span className="text-slate-300">—</span>}
                          </td>
                          <td className="border-b border-slate-100 px-5 py-3.5 tabular-nums text-slate-600">{num(l.usage_count)}</td>
                          <td className="border-b border-slate-100 px-5 py-3.5">
                            {l.is_active
                              ? <Badge tone="green">{t('vehicleLocations.statusActive')}</Badge>
                              : <Badge tone="gray">{t('vehicleLocations.statusRetired')}</Badge>}
                            {l.edited_in_app && (
                              <div className="mt-1 text-[11px] text-slate-400">{t('vehicleLocations.editedInApp')}</div>
                            )}
                          </td>
                          <td className="border-b border-slate-100 px-5 py-3.5">
                            {canManage ? (
                              <div className="flex items-center justify-end gap-1">
                                <button type="button" onClick={() => movePlace(l, -1)} disabled={busy} aria-label={t('vehicleLocations.moveUp')} title={t('vehicleLocations.moveUp')} className="rounded px-1.5 py-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-40">↑</button>
                                <button type="button" onClick={() => movePlace(l, 1)} disabled={busy} aria-label={t('vehicleLocations.moveDown')} title={t('vehicleLocations.moveDown')} className="rounded px-1.5 py-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-40">↓</button>
                                <Button variant="secondary" size="sm" onClick={() => openEditPlace(l)}>{t('common.edit')}</Button>
                                <Button variant="ghost" size="sm" onClick={() => togglePlace(l)} disabled={busy}>
                                  {l.is_active ? t('vehicleLocations.retire') : t('vehicleLocations.restore')}
                                </Button>
                                {/* Delete only exists for a place nothing has been filed at. Anything
                                    in use is WHERE a historical fault was, and that outranks tidiness. */}
                                {!l.in_use && (
                                  <Button variant="ghost" size="sm" className="text-red-600 hover:bg-red-50" onClick={() => setPlaceToDelete(l)}>{t('common.delete')}</Button>
                                )}
                              </div>
                            ) : <span className="text-xs text-slate-300">—</span>}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  )}
                </table>

                {!loading && visiblePlaces.length === 0 && (
                  <EmptyState title={t('vehicleLocations.emptyPlaces')} message={t('vehicleLocations.emptyPlacesHint')} />
                )}
              </div>
            </Card>
          </div>
        )}

        {/* ── SECTIONS ───────────────────────────────────────────────────────── */}
        {tab === 'sections' && (
          <div className="space-y-4" id="panel-sections" role="tabpanel" aria-labelledby="tab-sections">
            <p className="rounded-xl bg-slate-50 px-4 py-3 text-xs text-slate-500 ring-1 ring-inset ring-slate-200/70">
              {t('vehicleLocations.sectionsHint')}
            </p>
            <Card>
              <div className="overflow-x-auto">
                <table className="min-w-full border-separate border-spacing-0 text-sm">
                  <thead className="bg-slate-50/90">
                    <tr className="text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                      <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('vehicleLocations.colSection')}</th>
                      <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('vehicleLocations.colPlaces')}</th>
                      <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('vehicleLocations.colStatus')}</th>
                      <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-end">{t('vehicleLocations.colActions')}</th>
                    </tr>
                  </thead>
                  {loading ? <TableSkeleton cols={4} /> : (
                    <tbody>
                      {groups.map((g) => (
                        <tr key={g.key} className={`bg-white transition-colors even:bg-slate-50/40 ${g.is_active ? '' : 'opacity-60'}`}>
                          <td className="border-b border-slate-100 px-5 py-3.5">
                            <div className="font-medium text-slate-900">{lang === 'ar' && g.label_ar ? g.label_ar : g.label}</div>
                            <div className="text-xs text-slate-400" dir="auto">
                              {(lang === 'ar' ? g.label : g.label_ar) || <span className="text-amber-500">{t('vehicleLocations.noArabic')}</span>}
                              <span className="mx-1 text-slate-300">·</span>
                              <code className="text-[11px] text-slate-400">{g.key}</code>
                            </div>
                          </td>
                          <td className="border-b border-slate-100 px-5 py-3.5 tabular-nums text-slate-600">{num(g.location_count)}</td>
                          <td className="border-b border-slate-100 px-5 py-3.5">
                            {g.is_active
                              ? <Badge tone="green">{t('vehicleLocations.statusActive')}</Badge>
                              : <Badge tone="gray">{t('vehicleLocations.statusHidden')}</Badge>}
                          </td>
                          <td className="border-b border-slate-100 px-5 py-3.5">
                            {canManage && g.id ? (
                              <div className="flex items-center justify-end gap-1">
                                <button type="button" onClick={() => moveSection(g, -1)} disabled={busy} aria-label={t('vehicleLocations.moveUp')} className="rounded px-1.5 py-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-40">↑</button>
                                <button type="button" onClick={() => moveSection(g, 1)} disabled={busy} aria-label={t('vehicleLocations.moveDown')} className="rounded px-1.5 py-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-40">↓</button>
                                <Button variant="secondary" size="sm" onClick={() => openEditSection(g)}>{t('common.edit')}</Button>
                                {g.location_count === 0 && (
                                  <Button variant="ghost" size="sm" className="text-red-600 hover:bg-red-50" onClick={() => setSectionToDelete(g)}>{t('common.delete')}</Button>
                                )}
                              </div>
                            ) : <span className="text-xs text-slate-300">—</span>}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  )}
                </table>
              </div>
            </Card>
          </div>
        )}

        {/* ── FAULT TYPES: does this type even HAVE a where? ─────────────────── */}
        {tab === 'types' && (
          <div className="space-y-4" id="panel-types" role="tabpanel" aria-labelledby="tab-types">
            {/* The legend is the whole point of the tab: these three words are what the inspector's
                screen does, and they should be readable before anything is changed. */}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              {MODES.map((m) => (
                <div key={m} className="rounded-xl border border-slate-200/60 bg-white px-4 py-3 shadow-soft">
                  <Badge tone={MODE_TONE[m]}>{modeLabel(m)}</Badge>
                  <p className="mt-1.5 text-xs text-slate-500">{t(`vehicleLocations.modeHint.${m}`)}</p>
                </div>
              ))}
            </div>

            <div className="flex flex-col gap-3 sm:flex-row">
              <SearchInput className="flex-1" value={policySearch} onChange={setPolicySearch} placeholder={t('vehicleLocations.searchTypes')} />
              <Select className="sm:w-44" value={policyCatalog} onChange={(e) => setPolicyCatalog(e.target.value)}>
                <option value="">{t('vehicleLocations.allCatalogs')}</option>
                {/* `keyword` = a word the picker offers that no fault or damage TYPE owns — curated
                    on the Fault keywords tab, and until now missing from this one entirely. */}
                {['fault', 'damage', 'keyword'].map((c) => (
                  <option key={c} value={c}>{catalogLabel(c)}</option>
                ))}
              </Select>
              <Select className="sm:w-44" value={policyMode} onChange={(e) => setPolicyMode(e.target.value)}>
                <option value="">{t('vehicleLocations.allModes')}</option>
                {MODES.map((m) => <option key={m} value={m}>{modeLabel(m)}</option>)}
              </Select>
            </div>

            <Card>
              <div className="overflow-x-auto">
                <table className="min-w-full border-separate border-spacing-0 text-sm">
                  <thead className="bg-slate-50/90">
                    <tr className="text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                      <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('vehicleLocations.colType')}</th>
                      <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('vehicleLocations.colCategory')}</th>
                      <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('vehicleLocations.colAsks')}</th>
                      <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('vehicleLocations.colDecidedBy')}</th>
                      <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-end">{t('vehicleLocations.colActions')}</th>
                    </tr>
                  </thead>
                  {loading ? <TableSkeleton cols={5} /> : (
                    <tbody>
                      {visiblePolicy.slice(0, 300).map((p) => (
                        <tr key={`${p.catalog}-${p.id}`} className={`bg-white transition-colors even:bg-slate-50/40 ${p.is_active ? '' : 'opacity-60'}`}>
                          <td className="border-b border-slate-100 px-5 py-3.5">
                            <div className="font-medium text-slate-900">{lang === 'ar' && p.name_ar ? p.name_ar : p.name}</div>
                            <div className="text-xs text-slate-400">
                              <Badge tone={CATALOG_TONE[p.catalog] || 'gray'}>{catalogLabel(p.catalog)}</Badge>
                            </div>
                          </td>
                          <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">{p.category_key}</td>
                          <td className="border-b border-slate-100 px-5 py-3.5">
                            {/* `locked` rows are planned work ("Oil Change", "Tire Rotation"): the
                                answer follows from what KIND of work it is, so a switch here would
                                move nothing. The badge says so rather than pretending. */}
                            {canManage && !p.locked ? (
                              <Segmented
                                value={p.mode}
                                onChange={(m) => setMode(p, m)}
                                options={MODES.map((m) => ({ key: m, label: modeLabel(m) }))}
                              />
                            ) : <Badge tone={MODE_TONE[p.mode]}>{modeLabel(p.mode)}</Badge>}
                          </td>
                          {/* WHERE THIS ANSWER CAME FROM. A screen that shows "required" without
                              saying whether a person chose it or the category implied it is a black
                              box — and this page exists to make the picker legible. */}
                          <td className="border-b border-slate-100 px-5 py-3.5 text-xs text-slate-500">
                            {t(`vehicleLocations.source.${p.source}`, { category: p.category_key })}
                            {p.overridden && (
                              <div className="mt-1">
                                <Badge tone="violet">{t('vehicleLocations.overridden', { authored: modeLabel(p.authored_mode) })}</Badge>
                              </div>
                            )}
                          </td>
                          <td className="border-b border-slate-100 px-5 py-3.5 text-end">
                            {canManage && p.overridden && (
                              <Button variant="ghost" size="sm" onClick={() => resetMode(p)} disabled={busy}>{t('vehicleLocations.resetToStandard')}</Button>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  )}
                </table>
                {!loading && visiblePolicy.length === 0 && (
                  <EmptyState title={t('vehicleLocations.emptyTypes')} message={t('vehicleLocations.emptyTypesHint')} />
                )}
                {!loading && visiblePolicy.length > 300 && (
                  <p className="px-5 py-3 text-xs text-slate-400">{t('vehicleLocations.truncated', { shown: num(300), total: num(visiblePolicy.length) })}</p>
                )}
              </div>
            </Card>
          </div>
        )}

        {/* ── PREVIEW + THE QUANTITY RAIL ────────────────────────────────────── */}
        {tab === 'preview' && (
          <div className="space-y-4" id="panel-preview" role="tabpanel" aria-labelledby="tab-preview">
            <Card className="p-5">
              <h3 className="font-display text-base font-semibold text-slate-900">{t('vehicleLocations.maxTitle')}</h3>
              <p className="mt-1 text-xs text-slate-500">{t('vehicleLocations.maxHint')}</p>
              <div className="mt-3 flex flex-wrap items-end gap-3">
                <Input
                  label={t('vehicleLocations.maxLabel')}
                  type="number"
                  min={1}
                  max={999}
                  className="w-32"
                  value={maxInput === '' ? maxQuantity : maxInput}
                  onChange={(e) => setMaxInput(e.target.value)}
                  disabled={!canManage}
                />
                {canManage && (
                  <Button onClick={saveMax} loading={busy} disabled={maxInput === '' || Number(maxInput) === maxQuantity}>
                    {t('common.save')}
                  </Button>
                )}
                <p className="pb-2 text-xs text-slate-400">{t('vehicleLocations.maxAuthored', { n: num(defaultMaxQuantity) })}</p>
              </div>
            </Card>

            <Card className="p-5">
              <h3 className="font-display text-base font-semibold text-slate-900">{t('vehicleLocations.previewTitle')}</h3>
              <p className="mt-1 text-xs text-slate-500">{t('vehicleLocations.previewHint')}</p>

              <Select
                className="mt-3 sm:w-80"
                label={t('vehicleLocations.previewPick')}
                value={previewType}
                onChange={(e) => { setPreviewType(e.target.value); setPreviewValue({}); }}
              >
                <option value="">{t('vehicleLocations.previewPickPlaceholder')}</option>
                {previewChoices.map((p) => (
                  <option key={`${p.catalog}-${p.id}`} value={p.name}>
                    {p.name} — {modeLabel(p.mode)}
                  </option>
                ))}
              </Select>

              <div className="mt-4">
                {previewType ? (
                  <FaultDetailPicker
                    symptoms={[previewType]}
                    groups={previewGroups}
                    policy={previewPolicy}
                    value={previewValue}
                    onChange={setPreviewValue}
                    maxQuantity={maxQuantity}
                  />
                ) : (
                  <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-400 ring-1 ring-inset ring-slate-200/70">
                    {t('vehicleLocations.previewEmpty')}
                  </p>
                )}
              </div>
            </Card>
          </div>
        )}

        {/* WHERE EVERY NUMBER ON THIS PAGE COMES FROM. Non-negotiable on a page that changes what
            other people's screens demand of them. */}
        <p className="rounded-xl bg-slate-50 px-4 py-3 text-xs text-slate-500 ring-1 ring-inset ring-slate-200/70">
          {t('vehicleLocations.dataOrigin', { used: num(counts.in_use) })}
        </p>
      </div>

      {/* ── Place modal ──────────────────────────────────────────────────────── */}
      <Modal
        open={placeModal}
        onClose={() => !busy && setPlaceModal(false)}
        title={editingPlace ? t('vehicleLocations.editPlace') : t('vehicleLocations.addPlace')}
        subtitle={editingPlace ? editingPlace.slug : t('vehicleLocations.addPlaceSub')}
        size="lg"
        footer={
          <>
            <Button variant="secondary" onClick={() => setPlaceModal(false)} disabled={busy}>{t('common.cancel')}</Button>
            <Button onClick={savePlace} loading={busy} disabled={!placeForm.name.trim() || !placeForm.group_key}>
              {editingPlace ? t('common.save') : t('vehicleLocations.addPlace')}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Input label={t('vehicleLocations.fieldName')} required value={placeForm.name} onChange={(e) => setPlaceForm((f) => ({ ...f, name: e.target.value }))} />
            <Input label={t('vehicleLocations.fieldNameAr')} dir="rtl" value={placeForm.name_ar} onChange={(e) => setPlaceForm((f) => ({ ...f, name_ar: e.target.value }))} />
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Select label={t('vehicleLocations.fieldSection')} required value={placeForm.group_key} onChange={(e) => setPlaceForm((f) => ({ ...f, group_key: e.target.value }))}>
              <option value="" disabled>{t('vehicleLocations.pickSection')}</option>
              {groups.map((g) => <option key={g.key} value={g.key}>{g.label}</option>)}
            </Select>
            <Select label={t('vehicleLocations.fieldPrecision')} required value={placeForm.precision} onChange={(e) => setPlaceForm((f) => ({ ...f, precision: e.target.value }))}>
              {precisions.map((p) => <option key={p.key} value={p.key}>{precisionLabel(p.key)}</option>)}
            </Select>
          </div>

          <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500 ring-1 ring-inset ring-slate-200/70">
            {t(`vehicleLocations.precisionHint.${placeForm.precision}`)}
          </p>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {/* Both of these are JOINS, not decoration: the zone is how a place lines up with an
                inspection photo, the area key is how it lines up with a damage type. Left blank they
                simply mean "this place has no counterpart there". */}
            <Select label={t('vehicleLocations.fieldZone')} value={placeForm.inspection_zone} onChange={(e) => setPlaceForm((f) => ({ ...f, inspection_zone: e.target.value }))}>
              <option value="">{t('vehicleLocations.noZone')}</option>
              {zones.map((z) => <option key={z} value={z}>{z}</option>)}
            </Select>
            <Select label={t('vehicleLocations.fieldArea')} value={placeForm.area_key} onChange={(e) => setPlaceForm((f) => ({ ...f, area_key: e.target.value }))}>
              <option value="">{t('vehicleLocations.noArea')}</option>
              {areaKeys.map((a) => <option key={a} value={a}>{a}</option>)}
            </Select>
          </div>

          <Input
            label={t('vehicleLocations.fieldAliases')}
            placeholder={t('vehicleLocations.aliasesPlaceholder')}
            value={placeForm.aliases}
            onChange={(e) => setPlaceForm((f) => ({ ...f, aliases: e.target.value }))}
          />
          <p className="text-xs text-slate-400">{t('vehicleLocations.aliasesHint')}</p>

          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" checked={placeForm.is_active} onChange={(e) => setPlaceForm((f) => ({ ...f, is_active: e.target.checked }))} />
            {t('vehicleLocations.showInPicker')}
          </label>
        </div>
      </Modal>

      {/* ── Section modal ────────────────────────────────────────────────────── */}
      <Modal
        open={sectionModal}
        onClose={() => !busy && setSectionModal(false)}
        title={editingSection ? t('vehicleLocations.editSection') : t('vehicleLocations.addSection')}
        subtitle={editingSection ? editingSection.key : t('vehicleLocations.addSectionSub')}
        footer={
          <>
            <Button variant="secondary" onClick={() => setSectionModal(false)} disabled={busy}>{t('common.cancel')}</Button>
            <Button onClick={saveSection} loading={busy} disabled={!sectionForm.label.trim()}>
              {editingSection ? t('common.save') : t('vehicleLocations.addSection')}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Input label={t('vehicleLocations.fieldLabel')} required value={sectionForm.label} onChange={(e) => setSectionForm((f) => ({ ...f, label: e.target.value }))} />
            <Input label={t('vehicleLocations.fieldLabelAr')} dir="rtl" value={sectionForm.label_ar} onChange={(e) => setSectionForm((f) => ({ ...f, label_ar: e.target.value }))} />
          </div>
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" checked={sectionForm.is_active} onChange={(e) => setSectionForm((f) => ({ ...f, is_active: e.target.checked }))} />
            {t('vehicleLocations.showSection')}
          </label>
          {editingSection && <p className="text-xs text-slate-400">{t('vehicleLocations.keyFixed', { key: editingSection.key })}</p>}
        </div>
      </Modal>

      <ConfirmDialog
        open={!!placeToDelete}
        onClose={() => !busy && setPlaceToDelete(null)}
        onConfirm={deletePlace}
        loading={busy}
        title={t('vehicleLocations.deletePlaceTitle')}
        confirmText={t('common.delete')}
        message={placeToDelete ? t('vehicleLocations.deletePlaceMsg', { name: placeToDelete.name }) : ''}
      />

      <ConfirmDialog
        open={!!sectionToDelete}
        onClose={() => !busy && setSectionToDelete(null)}
        onConfirm={deleteSection}
        loading={busy}
        title={t('vehicleLocations.deleteSectionTitle')}
        confirmText={t('common.delete')}
        message={sectionToDelete ? t('vehicleLocations.deleteSectionMsg', { name: sectionToDelete.label }) : ''}
      />
    </div>
  );
}
