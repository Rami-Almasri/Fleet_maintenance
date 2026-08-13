import { useCallback, useMemo, useState } from 'react';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { useToast } from '../components/ui/Toast';
import { usePermissions } from '../hooks/usePermissions';
import { useI18n } from '../i18n/I18nContext';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import ConfirmDialog from '../components/ui/ConfirmDialog';
import Pagination from '../components/ui/Pagination';
import { Card, PageHeader, SearchInput, TableSkeleton, EmptyState } from '../components/ui/Misc';
import { Input, Select, Textarea } from '../components/ui/Field';
import { num } from '../lib/format';

const PAGE_SIZE = 15;

const TRACKING_TONE = { serialized: 'violet', batch: 'blue', consumable: 'gray' };

// The two synonym lists, and the ONE difference that matters: `identity_aliases` are trusted to prove
// two records are the same part, `aliases` are only ever searched. See the field's own hint text.
const ALIAS_FIELDS = ['identity_aliases', 'aliases'];

const emptyForm = {
  name: '',
  name_ar: '',
  identity_aliases: [],
  aliases: [],
  category_key: '',
  tracking_mode: 'batch',
  position_scheme: '',
  default_part_number: '',
  default_warranty_months: '',
  default_warranty_km: '',
  expected_life_km: '',
  expected_life_months: '',
  notes: '',
  is_active: true,
};

/**
 * Parts Catalog — the one list of part names the whole app selects from.
 *
 * This page exists so the vocabulary stops being a developer artefact. Everything a technician
 * needs to find a part is editable here: its English name, its Arabic name, and the aliases — the
 * other trade names and the SYMPTOM wording people use when they don't know the part name.
 *
 * Two states are surfaced deliberately rather than hidden:
 *   · "missing Arabic" is a filter, because a part without an Arabic term is invisible to half the
 *     workshop and that gap should be findable in one click, not discovered by a technician failing
 *     to search for it.
 *   · "in use on N cars" is shown per row, because it is the reason Delete becomes Retire. A user
 *     who is told why accepts the constraint; a greyed-out button just looks broken.
 */
export default function PartsCatalog() {
  const toast = useToast();
  const { can } = usePermissions();
  const { t, lang } = useI18n();
  const canManage = can('components.manage');

  const fetcher = useCallback(async () => {
    const { data } = await api.get('/parts-catalog');
    return data.data || {};
  }, []);
  const { data, loading, error, reload } = useFetch(fetcher);

  const parts = useMemo(() => data?.parts || [], [data]);
  const categories = useMemo(() => data?.categories || [], [data]);
  const trackingModes = useMemo(() => data?.tracking_modes || [], [data]);
  const positionSchemes = useMemo(() => data?.position_schemes || [], [data]);
  const counts = data?.counts || { total: 0, active: 0, retired: 0, edited: 0, missing_ar: 0 };

  // Localised getters — Arabic first when the UI is Arabic, with graceful fallback to English.
  const catLabel = (key) => {
    const c = categories.find((x) => x.key === key);
    if (!c) return key;
    return lang === 'ar' ? c.label_ar || c.label : c.label;
  };
  const modeMeta = (key) => trackingModes.find((m) => m.key === key) || { label: key, hint: '' };
  const modeLabel = (key) => {
    const m = modeMeta(key);
    return lang === 'ar' ? m.label_ar || m.label : m.label;
  };
  const partPrimary = (p) => (lang === 'ar' ? p.name_ar || p.name : p.name);
  const partSecondary = (p) => {
    const other = lang === 'ar' ? p.name : p.name_ar;
    return other && other !== partPrimary(p) ? other : null;
  };

  const [search, setSearch] = useState('');
  const [category, setCategory] = useState('');
  const [mode, setMode] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);

  // create / edit modal
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [formErrors, setFormErrors] = useState({});
  const [saving, setSaving] = useState(false);
  // One in-progress chip per alias list — keyed by field so the two boxes cannot overwrite each other.
  const [aliasDrafts, setAliasDrafts] = useState({ identity_aliases: '', aliases: '' });

  // delete / retire confirm
  const [toDelete, setToDelete] = useState(null);
  const [deleting, setDeleting] = useState(false);
  const [toRetire, setToRetire] = useState(null);
  const [retiring, setRetiring] = useState(false);
  // Set when the server refused a delete: { part, references, canRetire }. Drives the explanation
  // dialog, which is the whole point of the 422 carrying counts.
  const [blocked, setBlocked] = useState(null);

  const openCreate = () => {
    setEditing(null);
    setForm({ ...emptyForm, category_key: categories[0]?.key || '' });
    setFormErrors({});
    setAliasDrafts({ identity_aliases: '', aliases: '' });
    setModalOpen(true);
  };

  const openEdit = (p) => {
    setEditing(p);
    setForm({
      name: p.name || '',
      name_ar: p.name_ar || '',
      identity_aliases: p.identity_aliases || [],
      aliases: p.aliases || [],
      category_key: p.category_key || '',
      tracking_mode: p.tracking_mode || 'batch',
      position_scheme: p.position_scheme || '',
      default_part_number: p.default_part_number || '',
      default_warranty_months: p.default_warranty_months ?? '',
      default_warranty_km: p.default_warranty_km ?? '',
      expected_life_km: p.expected_life_km ?? '',
      expected_life_months: p.expected_life_months ?? '',
      notes: p.notes || '',
      is_active: p.is_active,
    });
    setFormErrors({});
    setAliasDrafts({ identity_aliases: '', aliases: '' });
    setModalOpen(true);
  };

  const onField = (field, value) => setForm((f) => ({ ...f, [field]: value }));

  // A consumable is work performed, not a part fitted to a corner of the car — the backend rejects
  // the combination, so the form must not offer it in the first place.
  const isConsumable = form.tracking_mode === 'consumable';

  const onTrackingMode = (value) => {
    setForm((f) => ({ ...f, tracking_mode: value, position_scheme: value === 'consumable' ? '' : f.position_scheme }));
  };

  const setDraft = (field, value) => setAliasDrafts((d) => ({ ...d, [field]: value }));

  const addAlias = (field) => {
    const v = (aliasDrafts[field] || '').trim();
    if (!v) return;
    // Case-insensitive de-dupe, same rule the backend applies — so the form never shows the user a
    // list the server would silently collapse.
    if (form[field].some((a) => a.toLowerCase() === v.toLowerCase())) {
      setDraft(field, '');
      return;
    }
    setForm((f) => ({ ...f, [field]: [...f[field], v] }));
    setDraft(field, '');
  };

  const removeAlias = (field, alias) =>
    setForm((f) => ({ ...f, [field]: f[field].filter((a) => a !== alias) }));

  const onAliasKeyDown = (field) => (e) => {
    // Enter and comma both commit — people type lists both ways.
    if (e.key === 'Enter' || e.key === ',') {
      e.preventDefault();
      addAlias(field);
    } else if (e.key === 'Backspace' && !aliasDrafts[field] && form[field].length) {
      removeAlias(field, form[field][form[field].length - 1]);
    }
  };

  // '' → null so an emptied number field clears the value instead of failing integer validation.
  const numOrNull = (v) => (v === '' || v === null || v === undefined ? null : Number(v));

  const save = async () => {
    setSaving(true);
    setFormErrors({});
    try {
      // A half-typed alias is what the user meant to add — commit it rather than dropping it.
      const committed = {};
      ALIAS_FIELDS.forEach((field) => {
        const pending = (aliasDrafts[field] || '').trim();
        committed[field] = pending && !form[field].some((a) => a.toLowerCase() === pending.toLowerCase())
          ? [...form[field], pending]
          : form[field];
      });

      const payload = {
        name: form.name.trim(),
        name_ar: form.name_ar?.trim() || null,
        identity_aliases: committed.identity_aliases,
        aliases: committed.aliases,
        category_key: form.category_key,
        tracking_mode: form.tracking_mode,
        position_scheme: form.position_scheme || null,
        default_part_number: form.default_part_number?.trim() || null,
        default_warranty_months: numOrNull(form.default_warranty_months),
        default_warranty_km: numOrNull(form.default_warranty_km),
        expected_life_km: numOrNull(form.expected_life_km),
        expected_life_months: numOrNull(form.expected_life_months),
        notes: form.notes?.trim() || null,
        is_active: form.is_active,
      };

      if (editing) {
        await api.post(`/parts-catalog/${editing.id}`, payload);
        toast.success(t('partsCatalog.updated'));
      } else {
        await api.post('/parts-catalog', payload);
        toast.success(t('partsCatalog.added'));
      }
      setModalOpen(false);
      reload();
    } catch (err) {
      const res = err.response?.data;
      if (res?.errors) {
        setFormErrors(res.errors);
        toast.error(t('partsCatalog.fixFields'));
      } else {
        toast.error(res?.message || t('partsCatalog.saveError'));
      }
    } finally {
      setSaving(false);
    }
  };

  /**
   * One place that turns a failed request into something a person can act on.
   *
   * A missing `response` is the network, not the API — telling someone "could not remove" when the
   * request never arrived sends them looking for a problem in their data. 403 is a permissions
   * answer and deserves its own sentence rather than the endpoint's generic message.
   */
  const apiErrorMessage = (err, fallback) => {
    if (!err?.response) return t('partsCatalog.networkError');
    if (err.response.status === 403) return t('partsCatalog.permissionDenied');
    return err.response.data?.message || fallback;
  };

  /**
   * Delete means delete. The server refuses while the part is still referenced, and the frontend
   * does not paper over that: it explains it and offers the action that IS available.
   */
  const confirmDelete = async () => {
    setDeleting(true);
    try {
      await api.delete(`/parts-catalog/${toDelete.id}`);
      toast.success(t('partsCatalog.removed'));
      setToDelete(null);
      reload();
    } catch (err) {
      const payload = err?.response?.data?.data;

      // 422 here is NOT a generic failure: it is the server saying why, with the counts. Showing
      // "could not remove" would throw away the only useful part of the answer.
      if (err?.response?.status === 422 && payload?.references) {
        setBlocked({
          part: toDelete,
          references: payload.references,
          canRetire: payload.can_retire !== false,
        });
        setToDelete(null);
        // Reaching this means the list was loaded before something started referencing the part,
        // so what is on screen is already stale.
        reload();
      } else {
        toast.error(apiErrorMessage(err, t('partsCatalog.removeError')));
      }
    } finally {
      setDeleting(false);
    }
  };

  /**
   * Retire: stop offering the part while every row that already points at it keeps working.
   * Idempotent on the server, so a double click — or a part someone else retired a moment ago —
   * both succeed rather than erroring at the user.
   */
  const confirmRetire = async () => {
    setRetiring(true);
    try {
      await api.post(`/parts-catalog/${toRetire.id}/retire`);
      toast.success(t('partsCatalog.retiredOk'));
      setToRetire(null);
      setBlocked(null);
      reload();
    } catch (err) {
      toast.error(apiErrorMessage(err, t('partsCatalog.retireError')));
    } finally {
      setRetiring(false);
    }
  };

  const restore = async (p) => {
    try {
      await api.post(`/parts-catalog/${p.id}/restore`);
      toast.success(t('partsCatalog.restored'));
      reload();
    } catch (err) {
      toast.error(apiErrorMessage(err, t('partsCatalog.restoreError')));
    }
  };

  /**
   * What is in the way, as countable lines. Only non-zero references appear, so an empty result
   * genuinely means nothing blocks the delete.
   */
  const referenceLines = (references = {}) =>
    Object.entries(references)
      .filter(([, n]) => Number(n) > 0)
      .map(([key, n]) => ({ key, count: Number(n), label: t(`partsCatalog.ref.${key}`) }));

  /** Older payloads (before the references contract) only knew the fitted-component count. */
  const canDelete = (p) => (p.can_delete !== undefined ? p.can_delete : (p.usage_count || 0) === 0);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return parts.filter((p) => {
      const matchSearch =
        !q ||
        // Both synonym lists are searched — they differ in what they are TRUSTED for, not in whether
        // someone might type them into this box looking for the row.
        [p.name, p.name_ar, p.slug, p.default_part_number, ...(p.identity_aliases || []), ...(p.aliases || [])]
          .some((f) => (f || '').toLowerCase().includes(q));
      const matchCategory = !category || p.category_key === category;
      const matchMode = !mode || p.tracking_mode === mode;
      const matchStatus =
        status === '' ||
        (status === 'active' && p.is_active) ||
        (status === 'retired' && !p.is_active) ||
        (status === 'missing_ar' && !p.name_ar) ||
        (status === 'edited' && p.edited_in_app);
      return matchSearch && matchCategory && matchMode && matchStatus;
    });
  }, [parts, search, category, mode, status]);

  const pageCount = Math.ceil(filtered.length / PAGE_SIZE) || 1;
  const safePage = Math.min(page, pageCount);
  const paged = filtered.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE);

  const setStatusFilter = (v) => { setStatus(status === v ? '' : v); setPage(1); };

  // "12 months or 20,000 km" — both legs when both exist, because the promise is whichever runs out
  // first and showing only one of them overstates the cover.
  const warrantyText = (p) => {
    const legs = [];
    if (p.default_warranty_months) legs.push(t('partsCatalog.nMonths', { n: num(p.default_warranty_months) }));
    if (p.default_warranty_km) legs.push(t('partsCatalog.nKm', { n: num(p.default_warranty_km) }));
    return legs.length ? legs.join(t('partsCatalog.orJoin')) : null;
  };

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title={t('partsCatalog.title')}
          subtitle={loading ? '…' : t('partsCatalog.subtitle', { shown: num(filtered.length), total: num(counts.total) })}
        >
          {canManage && (
            <Button onClick={openCreate}>
              <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M12 5v14M5 12h14" />
              </svg>
              {t('partsCatalog.add')}
            </Button>
          )}
        </PageHeader>

        {/* Summary tiles. "Missing Arabic" is a tile and not a footnote because it is the gap that
            makes the catalog unusable for half the workshop — it should be one click away. */}
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
          <div className="rounded-2xl border border-slate-200/60 bg-white px-5 py-4 shadow-soft">
            <p className="text-xs font-medium text-slate-500">{t('partsCatalog.tileTotal')}</p>
            <p className="mt-1 font-display text-2xl font-bold tracking-tight text-slate-900">{loading ? '…' : num(counts.total)}</p>
          </div>
          <button
            onClick={() => setStatusFilter('active')}
            className={`hover-lift rounded-2xl border border-slate-200/60 bg-white px-5 py-4 text-start shadow-soft ${status === 'active' ? 'ring-2 ring-indigo-500' : ''}`}
          >
            <p className="text-xs font-medium text-slate-500">{t('partsCatalog.tileActive')}</p>
            <p className="mt-1 font-display text-2xl font-bold tracking-tight text-slate-900">{loading ? '…' : num(counts.active)}</p>
          </button>
          <button
            onClick={() => setStatusFilter('missing_ar')}
            className={`hover-lift rounded-2xl border border-slate-200/60 bg-white px-5 py-4 text-start shadow-soft ${status === 'missing_ar' ? 'ring-2 ring-indigo-500' : ''}`}
          >
            <p className="text-xs font-medium text-slate-500">{t('partsCatalog.tileMissingAr')}</p>
            <p className={`mt-1 font-display text-2xl font-bold tracking-tight ${counts.missing_ar > 0 ? 'text-amber-600' : 'text-slate-900'}`}>
              {loading ? '…' : num(counts.missing_ar)}
            </p>
          </button>
          <button
            onClick={() => setStatusFilter('retired')}
            className={`hover-lift rounded-2xl border border-slate-200/60 bg-white px-5 py-4 text-start shadow-soft ${status === 'retired' ? 'ring-2 ring-indigo-500' : ''}`}
          >
            <p className="text-xs font-medium text-slate-500">{t('partsCatalog.tileRetired')}</p>
            <p className="mt-1 font-display text-2xl font-bold tracking-tight text-slate-900">{loading ? '…' : num(counts.retired)}</p>
          </button>
        </div>

        {/* Filters */}
        <div className="flex flex-col gap-3 sm:flex-row">
          <SearchInput className="flex-1" value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t('partsCatalog.search')} />
          <Select className="sm:w-52" value={category} onChange={(e) => { setCategory(e.target.value); setPage(1); }}>
            <option value="">{t('partsCatalog.allCategories')}</option>
            {categories.map((c) => <option key={c.key} value={c.key}>{lang === 'ar' ? c.label_ar || c.label : c.label}</option>)}
          </Select>
          <Select className="sm:w-44" value={mode} onChange={(e) => { setMode(e.target.value); setPage(1); }}>
            <option value="">{t('partsCatalog.allModes')}</option>
            {trackingModes.map((m) => <option key={m.key} value={m.key}>{lang === 'ar' ? m.label_ar || m.label : m.label}</option>)}
          </Select>
          <Select className="sm:w-40" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
            <option value="">{t('partsCatalog.all')}</option>
            <option value="active">{t('partsCatalog.active')}</option>
            <option value="retired">{t('partsCatalog.retired')}</option>
            <option value="missing_ar">{t('partsCatalog.missingAr')}</option>
            <option value="edited">{t('partsCatalog.edited')}</option>
          </Select>
        </div>

        {error && (
          <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
        )}

        <Card>
          <div className="overflow-x-auto">
            <table className="min-w-full border-separate border-spacing-0 text-sm stagger-rows">
              <thead className="bg-slate-50/90">
                <tr className="text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('partsCatalog.colPart')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('partsCatalog.colCategory')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('partsCatalog.colTracking')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('partsCatalog.colWarranty')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('partsCatalog.colAliases')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('partsCatalog.colUsage')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-end">{t('partsCatalog.colActions')}</th>
                </tr>
              </thead>

              {loading ? (
                <TableSkeleton cols={7} />
              ) : (
                <tbody>
                  {paged.map((p) => {
                    const secondary = partSecondary(p);
                    const warranty = warrantyText(p);
                    return (
                      <tr key={p.id} className={`bg-white transition-colors even:bg-slate-50/40 hover:bg-indigo-50/40 ${p.is_active ? '' : 'opacity-60'}`}>
                        <td className="border-b border-slate-100 px-5 py-3.5">
                          <div className="flex items-center gap-2">
                            <span className="font-medium text-slate-900">{partPrimary(p)}</span>
                            {!p.is_active && <Badge tone="gray">{t('partsCatalog.retired')}</Badge>}
                          </div>
                          {secondary
                            ? <div className="text-xs text-slate-400" dir="auto">{secondary}</div>
                            : <div className="text-xs text-amber-600">{t('partsCatalog.noArabic')}</div>}
                        </td>
                        <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">{catLabel(p.category_key)}</td>
                        <td className="border-b border-slate-100 px-5 py-3.5">
                          <Badge tone={TRACKING_TONE[p.tracking_mode] || 'gray'}>{modeLabel(p.tracking_mode)}</Badge>
                        </td>
                        <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">
                          {warranty || <span className="text-slate-300">—</span>}
                        </td>
                        {/* Other names first and in their own colour: that count is the one with
                            consequences — it is how many spellings the repeat-buy check will recognise
                            as this part. The search count sits behind it as a plain total. */}
                        <td className="border-b border-slate-100 px-5 py-3.5">
                          {(p.identity_aliases?.length || p.aliases?.length) ? (
                            <span className="inline-flex items-center gap-1.5">
                              {p.identity_aliases?.length ? (
                                <span
                                  className="rounded bg-emerald-50 px-1.5 py-0.5 text-xs font-medium text-emerald-700"
                                  title={p.identity_aliases.join(' · ')}
                                >
                                  {t('partsCatalog.nOtherNames', { n: num(p.identity_aliases.length) })}
                                </span>
                              ) : null}
                              {p.aliases?.length ? (
                                <span className="text-slate-500" title={p.aliases.join(' · ')}>
                                  {t('partsCatalog.nAliases', { n: num(p.aliases.length) })}
                                </span>
                              ) : null}
                            </span>
                          ) : <span className="text-slate-300">—</span>}
                        </td>
                        {/* Everything that would block a delete, named. This is why the action on the
                            right is Retire and not Remove — stated as a fact, so the constraint reads
                            as a reason rather than as a button that mysteriously does something else. */}
                        <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">
                          {(() => {
                            const lines = referenceLines(p.references || { fitted_components: p.usage_count || 0 });
                            if (! lines.length) {
                              return <span className="text-slate-300">{t('partsCatalog.unused')}</span>;
                            }
                            return (
                              <span title={lines.map((l) => `${l.count} ${l.label}`).join(' · ')}>
                                {lines.map((l) => `${num(l.count)} ${l.label}`).join(' · ')}
                              </span>
                            );
                          })()}
                        </td>
                        <td className="border-b border-slate-100 px-5 py-3.5">
                          <div className="flex justify-end gap-2">
                            {canManage && (
                              <>
                                <Button variant="secondary" size="sm" onClick={() => openEdit(p)}>{t('common.edit')}</Button>

                                {/* Delete is offered ONLY when the server would allow it. When it
                                    would not, Retire is offered instead — the recommended action,
                                    not a disguised delete. The server still refuses independently. */}
                                {p.is_active && canDelete(p) && (
                                  <Button variant="ghost" size="sm" className="text-red-600 hover:bg-red-50" onClick={() => setToDelete(p)}>
                                    {t('partsCatalog.remove')}
                                  </Button>
                                )}
                                {p.is_active && ! canDelete(p) && (
                                  <Button variant="ghost" size="sm" onClick={() => setToRetire(p)}>
                                    {t('partsCatalog.retire')}
                                  </Button>
                                )}

                                {/* A retired part can still be deleted if nothing ever referenced it
                                    — retiring is not a one-way trip into permanence. */}
                                {! p.is_active && (
                                  <>
                                    <Button variant="ghost" size="sm" onClick={() => restore(p)}>{t('partsCatalog.restore')}</Button>
                                    {canDelete(p) && (
                                      <Button variant="ghost" size="sm" className="text-red-600 hover:bg-red-50" onClick={() => setToDelete(p)}>
                                        {t('partsCatalog.remove')}
                                      </Button>
                                    )}
                                  </>
                                )}
                              </>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              )}
            </table>

            {!loading && filtered.length === 0 && <EmptyState title={t('partsCatalog.empty')} message={t('partsCatalog.emptyHint')} />}
          </div>

          {!loading && filtered.length > 0 && (
            <Pagination page={safePage} pageCount={pageCount} total={filtered.length} pageSize={PAGE_SIZE} onPage={setPage} />
          )}
        </Card>

        {/* Data origin — where these rows come from and who owns them now. */}
        <p className="text-xs text-slate-500">
          {t('partsCatalog.dataOrigin', { edited: num(counts.edited) })}
        </p>
      </div>

      {/* Create / Edit modal */}
      <Modal
        open={modalOpen}
        onClose={() => !saving && setModalOpen(false)}
        title={editing ? t('partsCatalog.edit') : t('partsCatalog.add')}
        subtitle={editing ? partPrimary(editing) : t('partsCatalog.addSub')}
        size="lg"
        footer={
          <>
            <Button variant="secondary" onClick={() => setModalOpen(false)} disabled={saving}>{t('common.cancel')}</Button>
            <Button onClick={save} loading={saving}>{editing ? t('common.save') : t('partsCatalog.add')}</Button>
          </>
        }
      >
        <div className="space-y-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Input
              label={t('partsCatalog.fieldNameEn')}
              required
              placeholder={t('partsCatalog.nameEnPlaceholder')}
              value={form.name}
              error={formErrors.name?.[0]}
              onChange={(e) => onField('name', e.target.value)}
            />
            <Input
              label={t('partsCatalog.fieldNameAr')}
              dir="rtl"
              placeholder={t('partsCatalog.nameArPlaceholder')}
              value={form.name_ar}
              error={formErrors.name_ar?.[0]}
              onChange={(e) => onField('name_ar', e.target.value)}
            />
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Select
              label={t('partsCatalog.fieldCategory')}
              required
              value={form.category_key}
              error={formErrors.category_key?.[0]}
              onChange={(e) => onField('category_key', e.target.value)}
            >
              <option value="" disabled>{t('partsCatalog.selectCategory')}</option>
              {categories.map((c) => <option key={c.key} value={c.key}>{lang === 'ar' ? c.label_ar || c.label : c.label}</option>)}
            </Select>
            <Select
              label={t('partsCatalog.fieldTracking')}
              required
              value={form.tracking_mode}
              error={formErrors.tracking_mode?.[0]}
              onChange={(e) => onTrackingMode(e.target.value)}
            >
              {trackingModes.map((m) => <option key={m.key} value={m.key}>{lang === 'ar' ? m.label_ar || m.label : m.label}</option>)}
            </Select>
          </div>

          {/* Live explanation of the selected tracking mode — it changes what the part requires later. */}
          <div className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 ring-1 ring-inset ring-slate-200">
            <span className="font-medium">{modeLabel(form.tracking_mode)}:</span> {modeMeta(form.tracking_mode).hint}
          </div>

          {/* OTHER NAMES — the list that makes "bought again" survive a change of wording. Anything
              here is treated as this exact part, so a repeat buy is caught even when the earlier one
              was written "dynamo" and this one says "Alternator". Kept visually distinct from the
              search list below because the two carry completely different weight. */}
          <div>
            <label className="mb-1 block text-sm font-medium text-slate-700">
              {t('partsCatalog.fieldIdentityAliases')}
            </label>
            <div className="flex flex-wrap gap-1.5 rounded-lg border border-emerald-300 bg-white px-2 py-2 focus-within:ring-2 focus-within:ring-emerald-500">
              {form.identity_aliases.map((a) => (
                <span key={a} className="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2 py-1 text-xs text-emerald-700" dir="auto">
                  {a}
                  <button type="button" className="text-emerald-400 hover:text-emerald-700" onClick={() => removeAlias('identity_aliases', a)} aria-label={t('common.remove')}>×</button>
                </span>
              ))}
              <input
                className="min-w-[10rem] flex-1 border-0 p-1 text-sm outline-none focus:ring-0"
                dir="auto"
                value={aliasDrafts.identity_aliases}
                placeholder={form.identity_aliases.length ? '' : t('partsCatalog.identityAliasPlaceholder')}
                onChange={(e) => setDraft('identity_aliases', e.target.value)}
                onKeyDown={onAliasKeyDown('identity_aliases')}
                onBlur={() => addAlias('identity_aliases')}
              />
            </div>
            <p className="mt-1 text-xs text-slate-500">{t('partsCatalog.identityAliasHint')}</p>
            {formErrors.identity_aliases?.[0] && <p className="mt-1 text-xs text-red-600">{formErrors.identity_aliases[0]}</p>}
          </div>

          {/* SEARCH WORDS — the reason someone can find this part by describing the problem. Never used
              to decide that two records are the same part; see the hint. */}
          <div>
            <label className="mb-1 block text-sm font-medium text-slate-700">{t('partsCatalog.fieldAliases')}</label>
            <div className="flex flex-wrap gap-1.5 rounded-lg border border-slate-300 bg-white px-2 py-2 focus-within:ring-2 focus-within:ring-indigo-500">
              {form.aliases.map((a) => (
                <span key={a} className="inline-flex items-center gap-1 rounded-md bg-indigo-50 px-2 py-1 text-xs text-indigo-700" dir="auto">
                  {a}
                  <button type="button" className="text-indigo-400 hover:text-indigo-700" onClick={() => removeAlias('aliases', a)} aria-label={t('common.remove')}>×</button>
                </span>
              ))}
              <input
                className="min-w-[10rem] flex-1 border-0 p-1 text-sm outline-none focus:ring-0"
                dir="auto"
                value={aliasDrafts.aliases}
                placeholder={form.aliases.length ? '' : t('partsCatalog.aliasPlaceholder')}
                onChange={(e) => setDraft('aliases', e.target.value)}
                onKeyDown={onAliasKeyDown('aliases')}
                onBlur={() => addAlias('aliases')}
              />
            </div>
            <p className="mt-1 text-xs text-slate-500">{t('partsCatalog.aliasHint')}</p>
            {formErrors.aliases?.[0] && <p className="mt-1 text-xs text-red-600">{formErrors.aliases[0]}</p>}
          </div>

          {/* Warranty defaults — BOTH legs, because a supplier promise is time AND distance. */}
          <div className="rounded-lg border border-slate-200 p-3">
            <p className="mb-2 text-sm font-medium text-slate-700">{t('partsCatalog.warrantySection')}</p>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Input
                type="number" min="0"
                label={t('partsCatalog.fieldWarrantyMonths')}
                value={form.default_warranty_months}
                error={formErrors.default_warranty_months?.[0]}
                onChange={(e) => onField('default_warranty_months', e.target.value)}
              />
              <Input
                type="number" min="0"
                label={t('partsCatalog.fieldWarrantyKm')}
                value={form.default_warranty_km}
                error={formErrors.default_warranty_km?.[0]}
                onChange={(e) => onField('default_warranty_km', e.target.value)}
              />
            </div>
            <p className="mt-2 text-xs text-slate-500">{t('partsCatalog.warrantyHint')}</p>
          </div>

          {/* Expected service life — foresight inputs, not promises. */}
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Input
              type="number" min="0"
              label={t('partsCatalog.fieldLifeKm')}
              value={form.expected_life_km}
              error={formErrors.expected_life_km?.[0]}
              onChange={(e) => onField('expected_life_km', e.target.value)}
            />
            <Input
              type="number" min="0"
              label={t('partsCatalog.fieldLifeMonths')}
              value={form.expected_life_months}
              error={formErrors.expected_life_months?.[0]}
              onChange={(e) => onField('expected_life_months', e.target.value)}
            />
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Input
              label={t('partsCatalog.fieldPartNumber')}
              value={form.default_part_number}
              error={formErrors.default_part_number?.[0]}
              onChange={(e) => onField('default_part_number', e.target.value)}
            />
            <Select
              label={t('partsCatalog.fieldPosition')}
              value={form.position_scheme}
              disabled={isConsumable}
              error={formErrors.position_scheme?.[0]}
              onChange={(e) => onField('position_scheme', e.target.value)}
            >
              {positionSchemes.map((s) => <option key={s.key || 'none'} value={s.key || ''}>{s.label}</option>)}
            </Select>
          </div>
          {isConsumable && <p className="-mt-2 text-xs text-slate-500">{t('partsCatalog.consumableNoPosition')}</p>}

          <Textarea
            label={t('partsCatalog.fieldNotes')}
            rows={2}
            value={form.notes}
            error={formErrors.notes?.[0]}
            onChange={(e) => onField('notes', e.target.value)}
          />

          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
              checked={form.is_active}
              onChange={(e) => onField('is_active', e.target.checked)}
            />
            {t('partsCatalog.showInPicker')}
          </label>
        </div>
      </Modal>

      {/* Delete confirm. Only ever reached for a part the server should accept deleting — and it is
          still a confirmation, because deletion is permanent. */}
      <ConfirmDialog
        open={!!toDelete}
        onClose={() => !deleting && setToDelete(null)}
        onConfirm={confirmDelete}
        loading={deleting}
        title={t('partsCatalog.removeTitle')}
        confirmText={t('partsCatalog.remove')}
        message={toDelete ? t('partsCatalog.removeMsg', { part: partPrimary(toDelete) }) : ''}
      />

      {/* Retire confirm — its own deliberate action, never a delete in disguise. */}
      <ConfirmDialog
        open={!!toRetire}
        onClose={() => !retiring && setToRetire(null)}
        onConfirm={confirmRetire}
        loading={retiring}
        title={t('partsCatalog.retireTitle')}
        confirmText={t('partsCatalog.retire')}
        message={toRetire ? t('partsCatalog.retireMsg', { part: partPrimary(toRetire) }) : ''}
      />

      {/* THE 422, RENDERED AS AN ANSWER. The server refused the delete and said exactly what is in
          the way; showing a red "could not remove" toast would discard the useful half of that.
          Reached only when the list was stale — the row hides Delete once references are known. */}
      <Modal
        open={!!blocked}
        onClose={() => setBlocked(null)}
        title={t('partsCatalog.blockedTitle')}
        subtitle={blocked ? partPrimary(blocked.part) : ''}
        footer={
          <>
            <Button variant="secondary" onClick={() => setBlocked(null)}>{t('common.close')}</Button>
            {blocked?.canRetire && (
              <Button onClick={() => { setToRetire(blocked.part); setBlocked(null); }}>
                {t('partsCatalog.retire')}
              </Button>
            )}
          </>
        }
      >
        <div className="space-y-3">
          <p className="text-sm text-slate-700">{t('partsCatalog.blockedIntro')}</p>

          <ul className="space-y-1 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-700 ring-1 ring-inset ring-slate-200">
            {referenceLines(blocked?.references).map((line) => (
              <li key={line.key} className="flex items-center justify-between gap-4">
                <span>{line.label}</span>
                <span className="font-medium text-slate-900">{num(line.count)}</span>
              </li>
            ))}
          </ul>

          <p className="text-xs text-slate-500">{t('partsCatalog.blockedHint')}</p>
        </div>
      </Modal>
    </div>
  );
}
