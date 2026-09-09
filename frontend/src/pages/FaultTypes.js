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
import { Input, Select } from '../components/ui/Field';
import { num } from '../lib/format';

/**
 * Fault Types — WHAT a fault is, curated in the app instead of in a deploy.
 *
 * The companion to Vehicle Locations (WHERE) and Keyword Risk (HOW SERIOUS). Until this page existed,
 * adding a fault to the picker meant editing config/maintenance_findings.php and shipping a release:
 * the Knowledge page could give a word meaning but not make it tappable, so a fault added by the
 * office was matchable, enrichable, and invisible at the one step that matters.
 *
 * THE ONE HONEST THING THIS PAGE INSISTS ON. A fault added here is selectable immediately, but the
 * matcher has no words for it — synonyms, workshop slang and misspellings are authored in the ontology
 * seeders, in commits, not typed into a form. So every row says whether it has vocabulary, and the
 * header leads with how many active types do NOT. That number is the page's real subject: a chip an
 * inspector can tap that the engine cannot read in a sentence still works, but it will never be
 * recognised in a garage's written note, and pretending otherwise is how the picker and the matcher
 * drifted apart in the first place.
 */

const PAGE_SIZE = 15;

const SEVERITY_TONE = { critical: 'red', moderate: 'amber', routine: 'green' };
const SEVERITY_EMOJI = { critical: '🔴', moderate: '🟡', routine: '🟢' };

const emptyForm = { name: '', name_ar: '', category_key: '', default_severity: 'routine', on_site: false };

export default function FaultTypes() {
  const toast = useToast();
  const { can } = usePermissions();
  const { t, lang } = useI18n();
  const canManage = can('maintenance.manage');

  const fetcher = useCallback(async () => {
    const { data } = await api.get('/fault-catalog');
    return data.data || {};
  }, []);
  const { data, loading, error, reload } = useFetch(fetcher);

  const faults = useMemo(() => data?.faults || [], [data]);
  const categories = useMemo(() => data?.categories || [], [data]);
  const severities = useMemo(() => data?.severities || ['routine', 'moderate', 'critical'], [data]);
  const summary = data?.summary || { total: 0, active: 0, no_vocabulary: 0 };

  const catLabel = (key) => {
    const c = categories.find((x) => x.key === key);
    if (!c) return key;
    return lang === 'ar' ? c.label_ar || c.label : c.label;
  };
  const faultPrimary = (f) => (lang === 'ar' ? f.name_ar || f.name : f.name);
  const faultSecondary = (f) => {
    const other = lang === 'ar' ? f.name : f.name_ar;
    return other && other !== faultPrimary(f) ? other : null;
  };

  const [search, setSearch] = useState('');
  const [categoryFilter, setCategoryFilter] = useState('');
  const [onlyGaps, setOnlyGaps] = useState(false);
  const [page, setPage] = useState(1);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return faults.filter((f) => {
      if (categoryFilter && f.category_key !== categoryFilter) return false;
      if (onlyGaps && (f.has_vocabulary || !f.is_active)) return false;
      if (!q) return true;
      return [f.name, f.name_ar, f.slug].filter(Boolean).some((v) => String(v).toLowerCase().includes(q));
    });
  }, [faults, search, categoryFilter, onlyGaps]);

  const pageCount = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
  const safePage = Math.min(page, pageCount);
  const paged = filtered.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE);

  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [formErrors, setFormErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [toDelete, setToDelete] = useState(null);
  const [deleting, setDeleting] = useState(false);
  const [busyId, setBusyId] = useState(null);

  const onField = (k, v) => {
    setForm((f) => ({ ...f, [k]: v }));
    setFormErrors((e) => ({ ...e, [k]: undefined }));
  };

  const openAdd = () => {
    setEditing(null);
    setForm({ ...emptyForm, category_key: categoryFilter || categories[0]?.key || '' });
    setFormErrors({});
    setModalOpen(true);
  };

  const openEdit = (f) => {
    setEditing(f);
    setForm({
      name: f.name || '',
      name_ar: f.name_ar || '',
      category_key: f.category_key || '',
      default_severity: f.default_severity || 'routine',
      on_site: !!f.on_site,
    });
    setFormErrors({});
    setModalOpen(true);
  };

  const save = async () => {
    setSaving(true);
    setFormErrors({});
    try {
      const payload = { ...form, on_site: form.on_site ? 1 : 0 };
      const { data: res } = editing
        ? await api.post(`/fault-catalog/${editing.id}`, payload)
        : await api.post('/fault-catalog', payload);
      setModalOpen(false);
      // The backend's message is the interesting one — it says whether the new fault has any
      // vocabulary behind it, which is the thing a curator needs to hear and cannot see from the form.
      toast.success(res?.message || t('Saved'));
      reload();
    } catch (e) {
      const errs = e?.response?.data?.data;
      if (errs && typeof errs === 'object') setFormErrors(errs);
      toast.error(e?.response?.data?.message || t('Could not save this fault type'));
    } finally {
      setSaving(false);
    }
  };

  const toggle = async (f) => {
    setBusyId(f.id);
    try {
      const { data: res } = await api.post(`/fault-catalog/${f.id}/toggle`);
      toast.success(res?.message || t('Updated'));
      reload();
    } catch (e) {
      // Retiring a config-authored row is refused with an explanation of where the word really lives.
      toast.error(e?.response?.data?.message || t('Could not change this fault type'));
    } finally {
      setBusyId(null);
    }
  };

  const confirmDelete = async () => {
    if (!toDelete) return;
    setDeleting(true);
    try {
      await api.delete(`/fault-catalog/${toDelete.id}`);
      toast.success(t('Fault type deleted'));
      setToDelete(null);
      reload();
    } catch (e) {
      toast.error(e?.response?.data?.message || t('Could not delete this fault type'));
    } finally {
      setDeleting(false);
    }
  };

  if (error) {
    return (
      <div className="p-6">
        <EmptyState title={t('Could not load the fault types')} message={String(error?.message || error)} />
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title={t('Fault Types')}
        subtitle={t('What an inspector can report. Adding one here puts it in the picker straight away.')}
        actions={canManage ? <Button onClick={openAdd}>{t('Add fault type')}</Button> : null}
      />

      <div className="space-y-4 p-4 sm:p-6">
        {/* THE HEADLINE. Not a decorative stat row: `no_vocabulary` is the count of words an inspector
            can tap that the matcher cannot read in a sentence, and it is the number this page exists
            to drive down. It is called out on its own, in amber, rather than buried beside a total. */}
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <Card className="px-5 py-4">
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{t('Fault types')}</div>
            <div className="mt-1 text-2xl font-semibold text-slate-900">{num(summary.total)}</div>
          </Card>
          <Card className="px-5 py-4">
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{t('Offered in the picker')}</div>
            <div className="mt-1 text-2xl font-semibold text-slate-900">{num(summary.active)}</div>
          </Card>
          <Card className={`px-5 py-4 ${summary.no_vocabulary > 0 ? 'ring-1 ring-inset ring-amber-500/30' : ''}`}>
            <div className="text-xs font-medium uppercase tracking-wide text-slate-500">{t('No words behind them')}</div>
            <div className={`mt-1 text-2xl font-semibold ${summary.no_vocabulary > 0 ? 'text-amber-600' : 'text-slate-900'}`}>
              {num(summary.no_vocabulary)}
            </div>
            {summary.no_vocabulary > 0 && (
              <p className="mt-1 text-xs text-amber-700">
                {t('Tappable, but the matcher cannot recognise them in written notes.')}
              </p>
            )}
          </Card>
        </div>

        <Card>
          <div className="flex flex-wrap items-center gap-3 border-b border-slate-200 px-5 py-3">
            <SearchInput value={search} onChange={setSearch} placeholder={t('Search a fault type…')} />
            <Select value={categoryFilter} onChange={(e) => setCategoryFilter(e.target.value)} className="max-w-[16rem]">
              <option value="">{t('All categories')}</option>
              {categories.map((c) => <option key={c.key} value={c.key}>{lang === 'ar' ? c.label_ar || c.label : c.label}</option>)}
            </Select>
            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                checked={onlyGaps}
                onChange={(e) => { setOnlyGaps(e.target.checked); setPage(1); }}
              />
              {t('Only ones missing words')}
            </label>
          </div>

          <div className="overflow-x-auto">
            <table className="min-w-full border-separate border-spacing-0 text-sm stagger-rows">
              <thead className="bg-slate-50/90">
                <tr className="text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('Fault')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('Category')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('Severity')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('Words')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('Recorded on')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('Status')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-end">{t('Actions')}</th>
                </tr>
              </thead>

              {loading ? (
                <TableSkeleton cols={7} />
              ) : (
                <tbody>
                  {paged.map((f) => {
                    const secondary = faultSecondary(f);
                    return (
                      <tr key={f.id} className={`bg-white transition-colors even:bg-slate-50/40 hover:bg-indigo-50/40 ${f.is_active ? '' : 'opacity-60'}`}>
                        <td className="border-b border-slate-100 px-5 py-3.5">
                          <div className="flex items-center gap-2">
                            <span className="font-medium text-slate-900">{faultPrimary(f)}</span>
                            {f.on_site && <Badge tone="blue">{t('On site')}</Badge>}
                          </div>
                          {secondary && <div className="text-xs text-slate-400" dir="auto">{secondary}</div>}
                        </td>
                        <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">{catLabel(f.category_key)}</td>
                        <td className="border-b border-slate-100 px-5 py-3.5">
                          <Badge tone={SEVERITY_TONE[f.default_severity] || 'gray'}>
                            {SEVERITY_EMOJI[f.default_severity]} {t(f.default_severity || '—')}
                          </Badge>
                        </td>
                        {/* The honest column. "No words" is not an error state — the fault works, it
                            just cannot be found in a sentence until somebody authors a concept. */}
                        <td className="border-b border-slate-100 px-5 py-3.5">
                          {f.has_vocabulary
                            ? <Badge tone="violet" dot>{t('Understood')}</Badge>
                            : <Badge tone="amber">{t('No words yet')}</Badge>}
                        </td>
                        <td className="border-b border-slate-100 px-5 py-3.5">
                          <span className={f.usage_count > 0 ? 'text-slate-700' : 'text-slate-300'}>
                            {f.usage_count > 0 ? num(f.usage_count) : '—'}
                          </span>
                        </td>
                        <td className="border-b border-slate-100 px-5 py-3.5">
                          <div className="flex flex-wrap items-center gap-1.5">
                            {f.is_active ? <Badge tone="green">{t('Offered')}</Badge> : <Badge tone="gray">{t('Retired')}</Badge>}
                            {/* Where the row came from. An authored row can be renamed here but not
                                retired — the config would put it straight back on the next deploy. */}
                            {f.authored && <Badge tone="gray">{t('In config')}</Badge>}
                          </div>
                        </td>
                        <td className="border-b border-slate-100 px-5 py-3.5">
                          <div className="flex justify-end gap-2">
                            {canManage && (
                              <>
                                <Button variant="secondary" size="sm" onClick={() => openEdit(f)}>{t('Edit')}</Button>
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  loading={busyId === f.id}
                                  disabled={f.authored && f.is_active}
                                  title={f.authored && f.is_active ? t('Authored in the config file — remove it there') : undefined}
                                  onClick={() => toggle(f)}
                                >
                                  {f.is_active ? t('Retire') : t('Restore')}
                                </Button>
                                {f.usage_count === 0 && !f.authored && (
                                  <Button variant="ghost" size="sm" className="text-red-600 hover:bg-red-50" onClick={() => setToDelete(f)}>
                                    {t('Delete')}
                                  </Button>
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

            {!loading && filtered.length === 0 && (
              <EmptyState title={t('No fault types match')} message={t('Try a different search, or clear the filters.')} />
            )}
          </div>

          {!loading && filtered.length > 0 && (
            <Pagination page={safePage} pageCount={pageCount} total={filtered.length} pageSize={PAGE_SIZE} onPage={setPage} />
          )}
        </Card>
      </div>

      <Modal
        open={modalOpen}
        onClose={() => !saving && setModalOpen(false)}
        title={editing ? t('Edit fault type') : t('Add fault type')}
        subtitle={editing ? faultPrimary(editing) : t('It becomes selectable in the findings picker as soon as you save.')}
        size="lg"
        footer={
          <>
            <Button variant="secondary" onClick={() => setModalOpen(false)} disabled={saving}>{t('Cancel')}</Button>
            <Button onClick={save} loading={saving}>{editing ? t('Save') : t('Add fault type')}</Button>
          </>
        }
      >
        <div className="space-y-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Input
              label={t('Fault name (English)')}
              required
              placeholder={t('e.g. Detached trim')}
              value={form.name}
              error={formErrors.name?.[0]}
              onChange={(e) => onField('name', e.target.value)}
            />
            <Input
              label={t('Fault name (Arabic)')}
              dir="rtl"
              value={form.name_ar}
              error={formErrors.name_ar?.[0]}
              onChange={(e) => onField('name_ar', e.target.value)}
            />
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Select
              label={t('Category')}
              required
              value={form.category_key}
              error={formErrors.category_key?.[0]}
              onChange={(e) => onField('category_key', e.target.value)}
            >
              <option value="" disabled>{t('Pick a category')}</option>
              {categories.map((c) => <option key={c.key} value={c.key}>{lang === 'ar' ? c.label_ar || c.label : c.label}</option>)}
            </Select>
            <Select
              label={t('Default severity')}
              required
              value={form.default_severity}
              error={formErrors.default_severity?.[0]}
              onChange={(e) => onField('default_severity', e.target.value)}
            >
              {severities.map((s) => <option key={s} value={s}>{SEVERITY_EMOJI[s]} {t(s)}</option>)}
            </Select>
          </div>

          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
              checked={form.on_site}
              onChange={(e) => onField('on_site', e.target.checked)}
            />
            {t('Can be repaired on site (no need to send the car to a garage)')}
          </label>

          {/* Said before saving, not after. A curator who expects the AI to start recognising this
              fault in garage notes should find out here that it will not, yet. */}
          {!editing && (
            <div className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-inset ring-amber-600/20">
              {t('Inspectors can tap this straight away. The matcher will not recognise it in written notes until a developer adds its wording to the fault ontology.')}
            </div>
          )}

          {editing?.authored && (
            <div className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 ring-1 ring-inset ring-slate-300">
              {t('This fault is also written in the application config. Renaming it here sticks, and the next deployment will leave your version alone.')}
            </div>
          )}
        </div>
      </Modal>

      <ConfirmDialog
        open={!!toDelete}
        onClose={() => !deleting && setToDelete(null)}
        onConfirm={confirmDelete}
        loading={deleting}
        title={t('Delete this fault type?')}
        confirmText={t('Delete')}
        message={toDelete ? t('“{name}” has never been used on a task, so deleting it loses nothing.').replace('{name}', faultPrimary(toDelete)) : ''}
      />
    </div>
  );
}
