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

// Risk tones/emoji mirror the backend (FindingKeyword::RISK_META) & the app-wide fault severity
// scale. `routine` is the low / "minor" tier. Labels + hints come from i18n so they read in Arabic.
const RISK_TONE = { critical: 'red', moderate: 'amber', routine: 'green' };
const RISK_EMOJI = { critical: '🔴', moderate: '🟡', routine: '🟢' };
const RISK_ORDER = ['critical', 'moderate', 'routine'];

const emptyForm = { category_key: '', keyword: '', keyword_ar: '', risk: 'moderate', description: '', is_active: true };

export default function FindingKeywords() {
  const toast = useToast();
  const { can } = usePermissions();
  const { t, lang } = useI18n();
  const canManage = can('maintenance.manage');                  // edit / re-grade / delete (curation)
  const canAdd = canManage || can('maintenance.initiate');      // add a keyword (inspectors contribute)

  const fetcher = useCallback(async () => {
    const { data } = await api.get('/finding-keywords');
    return data.data || {};
  }, []);
  const { data, loading, error, reload } = useFetch(fetcher);

  const keywords = useMemo(() => data?.keywords || [], [data]);
  const categories = useMemo(() => data?.categories || [], [data]);
  const counts = data?.counts || { total: 0, critical: 0, moderate: 0, routine: 0 };

  // Localised getters — Arabic when the UI is Arabic, English otherwise (with graceful fallback).
  const riskLabel = (r) => t(`findingKeywords.risk.${r}`);
  const riskHint = (r) => t(`findingKeywords.risk.${r}Hint`);
  const catLabel = (c) => (lang === 'ar' ? c.label_ar || c.label : c.label);
  const kwPrimary = (k) => (lang === 'ar' ? k.keyword_ar || k.keyword : k.keyword);
  const kwSecondary = (k) => {
    const other = lang === 'ar' ? k.keyword : k.keyword_ar;
    return other && other !== kwPrimary(k) ? other : null;
  };
  const rowCatLabel = (k) => (lang === 'ar' ? k.category_label_ar || k.category_label : k.category_label);

  const [search, setSearch] = useState('');
  const [category, setCategory] = useState('');
  const [risk, setRisk] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);

  // create / edit modal
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [formErrors, setFormErrors] = useState({});
  const [saving, setSaving] = useState(false);

  // delete confirm
  const [toDelete, setToDelete] = useState(null);
  const [deleting, setDeleting] = useState(false);

  const openCreate = () => {
    setEditing(null);
    setForm({ ...emptyForm, category_key: categories[0]?.key || '' });
    setFormErrors({});
    setModalOpen(true);
  };
  const openEdit = (k) => {
    setEditing(k);
    setForm({
      category_key: k.category_key,
      keyword: k.keyword,
      keyword_ar: k.keyword_ar || '',
      risk: k.risk,
      description: k.description || '',
      is_active: k.is_active,
    });
    setFormErrors({});
    setModalOpen(true);
  };
  const onField = (field, value) => setForm((f) => ({ ...f, [field]: value }));

  const save = async () => {
    setSaving(true);
    setFormErrors({});
    try {
      const payload = {
        category_key: form.category_key,
        keyword: form.keyword.trim(),
        keyword_ar: form.keyword_ar?.trim() || null,
        risk: form.risk,
        description: form.description?.trim() || null,
        is_active: form.is_active,
      };
      if (editing) {
        await api.post(`/finding-keywords/${editing.id}`, payload);
        toast.success(t('findingKeywords.updated'));
      } else {
        await api.post('/finding-keywords', payload);
        toast.success(t('findingKeywords.added'));
      }
      setModalOpen(false);
      reload();
    } catch (err) {
      const res = err.response?.data;
      if (res?.errors) {
        setFormErrors(res.errors);
        toast.error(t('findingKeywords.fixFields'));
      } else {
        toast.error(res?.message || res?.msg || t('findingKeywords.saveError'));
      }
    } finally {
      setSaving(false);
    }
  };

  const confirmDelete = async () => {
    setDeleting(true);
    try {
      await api.delete(`/finding-keywords/${toDelete.id}`);
      toast.success(t('findingKeywords.removed'));
      setToDelete(null);
      reload();
    } catch (err) {
      toast.error(err.response?.data?.message || t('findingKeywords.removeError'));
    } finally {
      setDeleting(false);
    }
  };

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return keywords.filter((k) => {
      const matchSearch =
        !q ||
        [k.keyword, k.keyword_ar, k.description, k.category_label, k.category_label_ar].some((f) => (f || '').toLowerCase().includes(q));
      const matchCategory = !category || k.category_key === category;
      const matchRisk = !risk || k.risk === risk;
      const matchStatus = status === '' || (status === 'active' ? k.is_active : !k.is_active);
      return matchSearch && matchCategory && matchRisk && matchStatus;
    });
  }, [keywords, search, category, risk, status]);

  const pageCount = Math.ceil(filtered.length / PAGE_SIZE) || 1;
  const safePage = Math.min(page, pageCount);
  const paged = filtered.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE);

  const toggleRisk = (r) => { setRisk(risk === r ? '' : r); setPage(1); };

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title={t('findingKeywords.title')}
          subtitle={loading ? '…' : t('findingKeywords.subtitle', { shown: num(filtered.length), total: num(counts.total) })}
        >
          {canAdd && (
            <Button onClick={openCreate}>
              <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M12 5v14M5 12h14" />
              </svg>
              {t('findingKeywords.add')}
            </Button>
          )}
        </PageHeader>

        {/* Risk summary tiles (click to filter) */}
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
          <div className="rounded-2xl border border-slate-200/60 bg-white px-5 py-4 shadow-soft">
            <p className="text-xs font-medium text-slate-500">{t('findingKeywords.tileAll')}</p>
            <p className="mt-1 font-display text-2xl font-bold tracking-tight text-slate-900">{loading ? '…' : num(counts.total)}</p>
          </div>
          {RISK_ORDER.map((r) => (
            <button
              key={r}
              onClick={() => toggleRisk(r)}
              className={`hover-lift relative flex items-center justify-between rounded-2xl border border-slate-200/60 bg-white px-5 py-4 text-start shadow-soft ${risk === r ? 'ring-2 ring-indigo-500' : ''}`}
            >
              <div>
                <div className="flex items-center gap-2">
                  <span>{RISK_EMOJI[r]}</span>
                  <p className="text-xs font-medium text-slate-500">{riskLabel(r)}</p>
                </div>
                <p className="mt-1 font-display text-2xl font-bold tracking-tight text-slate-900">{loading ? '…' : num(counts[r] || 0)}</p>
              </div>
              {risk === r && <span className="text-xs font-medium text-indigo-600">✓</span>}
            </button>
          ))}
        </div>

        {/* Filters */}
        <div className="flex flex-col gap-3 sm:flex-row">
          <SearchInput className="flex-1" value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t('findingKeywords.search')} />
          <Select className="sm:w-52" value={category} onChange={(e) => { setCategory(e.target.value); setPage(1); }}>
            <option value="">{t('findingKeywords.allCategories')}</option>
            {categories.map((c) => <option key={c.key} value={c.key}>{catLabel(c)}</option>)}
          </Select>
          <Select className="sm:w-40" value={risk} onChange={(e) => { setRisk(e.target.value); setPage(1); }}>
            <option value="">{t('findingKeywords.allRisk')}</option>
            {RISK_ORDER.map((r) => <option key={r} value={r}>{riskLabel(r)}</option>)}
          </Select>
          <Select className="sm:w-36" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
            <option value="">{t('findingKeywords.all')}</option>
            <option value="active">{t('findingKeywords.active')}</option>
            <option value="inactive">{t('findingKeywords.inactive')}</option>
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
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('findingKeywords.colKeyword')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('findingKeywords.colCategory')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('findingKeywords.colRisk')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('findingKeywords.colDetail')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('findingKeywords.colStatus')}</th>
                  {canManage && <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-end">{t('findingKeywords.colActions')}</th>}
                </tr>
              </thead>

              {loading ? (
                <TableSkeleton cols={canManage ? 6 : 5} />
              ) : (
                <tbody>
                  {paged.map((k) => {
                    const secondary = kwSecondary(k);
                    return (
                      <tr key={k.id} className={`bg-white transition-colors even:bg-slate-50/40 hover:bg-indigo-50/40 ${k.is_active ? '' : 'opacity-60'}`}>
                        <td className="border-b border-slate-100 px-5 py-3.5">
                          <div className="font-medium text-slate-900">{kwPrimary(k)}</div>
                          {secondary && <div className="text-xs text-slate-400" dir="auto">{secondary}</div>}
                        </td>
                        <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">{rowCatLabel(k)}</td>
                        <td className="border-b border-slate-100 px-5 py-3.5">
                          <Badge tone={RISK_TONE[k.risk] || 'amber'}>{RISK_EMOJI[k.risk]} {riskLabel(k.risk)}</Badge>
                        </td>
                        <td className="border-b border-slate-100 px-5 py-3.5 max-w-md text-slate-600">{k.description || <span className="text-slate-300">—</span>}</td>
                        <td className="border-b border-slate-100 px-5 py-3.5">
                          {k.is_active ? <Badge tone="green">{t('findingKeywords.active')}</Badge> : <Badge tone="gray">{t('findingKeywords.hidden')}</Badge>}
                        </td>
                        {canManage && (
                          <td className="border-b border-slate-100 px-5 py-3.5">
                            <div className="flex justify-end gap-2">
                              <Button variant="secondary" size="sm" onClick={() => openEdit(k)}>{t('common.edit')}</Button>
                              <Button variant="ghost" size="sm" className="text-red-600 hover:bg-red-50" onClick={() => setToDelete(k)}>{t('findingKeywords.remove')}</Button>
                            </div>
                          </td>
                        )}
                      </tr>
                    );
                  })}
                </tbody>
              )}
            </table>

            {!loading && filtered.length === 0 && <EmptyState title={t('findingKeywords.empty')} message={t('findingKeywords.emptyHint')} />}
          </div>

          {!loading && filtered.length > 0 && (
            <Pagination page={safePage} pageCount={pageCount} total={filtered.length} pageSize={PAGE_SIZE} onPage={setPage} />
          )}
        </Card>
      </div>

      {/* Create / Edit modal */}
      <Modal
        open={modalOpen}
        onClose={() => !saving && setModalOpen(false)}
        title={editing ? t('findingKeywords.edit') : t('findingKeywords.add')}
        subtitle={editing ? kwPrimary(editing) : t('findingKeywords.addSub')}
        size="lg"
        footer={
          <>
            <Button variant="secondary" onClick={() => setModalOpen(false)} disabled={saving}>{t('common.cancel')}</Button>
            <Button onClick={save} loading={saving}>{editing ? t('common.save') : t('findingKeywords.add')}</Button>
          </>
        }
      >
        <div className="space-y-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Select
              label={t('findingKeywords.fieldCategory')}
              required
              value={form.category_key}
              error={formErrors.category_key?.[0]}
              onChange={(e) => onField('category_key', e.target.value)}
            >
              <option value="" disabled>{t('findingKeywords.selectCategory')}</option>
              {categories.map((c) => <option key={c.key} value={c.key}>{catLabel(c)}</option>)}
            </Select>
            <Select
              label={t('findingKeywords.fieldRisk')}
              required
              value={form.risk}
              error={formErrors.risk?.[0]}
              onChange={(e) => onField('risk', e.target.value)}
            >
              {RISK_ORDER.map((r) => <option key={r} value={r}>{RISK_EMOJI[r]} {riskLabel(r)}</option>)}
            </Select>
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Input
              label={t('findingKeywords.fieldKeywordEn')}
              required
              placeholder={t('findingKeywords.keywordEnPlaceholder')}
              value={form.keyword}
              error={formErrors.keyword?.[0]}
              onChange={(e) => onField('keyword', e.target.value)}
            />
            <Input
              label={t('findingKeywords.fieldKeywordAr')}
              dir="rtl"
              placeholder={t('findingKeywords.keywordArPlaceholder')}
              value={form.keyword_ar}
              error={formErrors.keyword_ar?.[0]}
              onChange={(e) => onField('keyword_ar', e.target.value)}
            />
          </div>

          {/* Live risk hint */}
          <div className={`rounded-lg px-3 py-2 text-xs ring-1 ring-inset ${form.risk === 'critical' ? 'bg-red-50 text-red-700 ring-red-600/20' : form.risk === 'moderate' ? 'bg-amber-50 text-amber-700 ring-amber-600/20' : 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'}`}>
            {RISK_EMOJI[form.risk]} <span className="font-medium">{riskLabel(form.risk)}:</span> {riskHint(form.risk)}
          </div>

          <Textarea
            label={t('findingKeywords.fieldDetail')}
            rows={3}
            placeholder={t('findingKeywords.detailPlaceholder')}
            value={form.description}
            error={formErrors.description?.[0]}
            onChange={(e) => onField('description', e.target.value)}
          />

          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
              checked={form.is_active}
              onChange={(e) => onField('is_active', e.target.checked)}
            />
            {t('findingKeywords.showInPicker')}
          </label>
        </div>
      </Modal>

      {/* Delete confirm */}
      <ConfirmDialog
        open={!!toDelete}
        onClose={() => !deleting && setToDelete(null)}
        onConfirm={confirmDelete}
        loading={deleting}
        title={t('findingKeywords.removeTitle')}
        confirmText={t('findingKeywords.remove')}
        message={toDelete ? t('findingKeywords.removeMsg', { kw: kwPrimary(toDelete) }) : ''}
      />
    </div>
  );
}
