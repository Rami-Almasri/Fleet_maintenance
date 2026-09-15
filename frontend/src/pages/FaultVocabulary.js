import { useCallback, useMemo, useState } from 'react';
import KeywordRiskAnalytics from '../components/analytics/KeywordRiskAnalytics';
import KeywordKnowledgeDrawer from '../components/keywords/KeywordKnowledgeDrawer';
import KeywordMatchTester from '../components/keywords/KeywordMatchTester';
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

/**
 * Fault Vocabulary — one word, one row, one page.
 *
 * ── WHAT WAS WRONG WITH TWO PAGES ────────────────────────────────────────────────────────────────
 * A fault type is two database rows: `fault_catalog` decides whether an inspector can TAP the word,
 * `finding_keywords` decides what it MEANS and how serious it is. Since FaultTypeRegistrar started
 * writing both halves on every door, adding a fault has been ONE act — but reading one stayed two
 * screens. "Fault Types" showed the half that is tappable; "Keyword Risk" showed the half that is
 * graded; each printed its own total, and two totals that differ by design left "one of these is
 * broken" as the only available reading. Curating one word meant grading it on one page and deciding
 * whether it can be repaired on site on another.
 *
 * So the READ is merged and the WRITES are not. The page loads one joined list (GET /fault-vocabulary)
 * and sends each save to the door that OWNS that row — the catalog endpoint where a fault type exists,
 * the library endpoint where only a keyword does. Both doors call the registrar, which carries the
 * change to the other half. There is no third writer, so nothing new can drift.
 *
 * ── THE THREE THINGS EVERY ROW HAS TO SAY, SEPARATELY ────────────────────────────────────────────
 * Collapsing these is what made two pages feel necessary, and merging them into one green tick would
 * be the same mistake with fewer clicks:
 *
 *   WHERE IT LIVES   in the picker · recorded by the garage (withheld on purpose) · missing from the
 *                    picker (amber — the half-added word, the only one of the three that is broken).
 *   WHAT IT IS WORTH the grade both halves share. One column, because the registrar holds them equal.
 *   WHAT THE MATCHER a term count and whether a concept was authored for it. "No words yet" is not an
 *   KNOWS           error: the fault works, it simply cannot be found in a written sentence until
 *                    somebody authors its wording in database/seeders/ontology/*.php — in a commit,
 *                    not in this form. The header leads with that count because it should be falling.
 */

const PAGE_SIZE = 15;

const GRADE_TONE = { critical: 'red', moderate: 'amber', routine: 'green' };
const GRADE_EMOJI = { critical: '🔴', moderate: '🟡', routine: '🟢' };
const GRADE_ORDER = ['critical', 'moderate', 'routine'];

// What each grade MEANS, in the words the inspector's picker uses. Shown live in the form, because a
// grade picked without them is a guess at a word rather than a decision about a fault.
const GRADE_HINT = {
  critical: 'Stop renting the car until it is fixed.',
  moderate: 'Fix it at the next visit — the car can keep working.',
  routine: 'Housekeeping. Fix it when the car is in anyway.',
};

const emptyForm = {
  name: '',
  name_ar: '',
  category_key: '',
  grade: 'routine',
  on_site: false,
  description: '',
};

export default function FaultVocabulary() {
  const toast = useToast();
  const { can } = usePermissions();
  const { t, lang } = useI18n();
  const canManage = can('maintenance.manage');

  const fetcher = useCallback(async () => {
    const { data } = await api.get('/fault-vocabulary');
    return data.data || {};
  }, []);
  const { data, loading, error, reload } = useFetch(fetcher);

  const words = useMemo(() => data?.words || [], [data]);
  const categories = useMemo(() => data?.categories || [], [data]);
  const grades = useMemo(() => data?.grades || GRADE_ORDER, [data]);
  const summary = data?.summary || {};
  const knowledge = data?.knowledge || { ai_available: false, enriched: 0, term_total: 0 };
  const fullyCovered = summary.total > 0 && knowledge.enriched >= summary.total;

  const catLabel = (key) => {
    const c = categories.find((x) => x.key === key);
    if (!c) return key;
    return lang === 'ar' ? c.label_ar || c.label : c.label;
  };
  const primary = (w) => (lang === 'ar' ? w.name_ar || w.name : w.name);
  const secondary = (w) => {
    const other = lang === 'ar' ? w.name : w.name_ar;
    return other && other !== primary(w) ? other : null;
  };

  const [search, setSearch] = useState('');
  const [categoryFilter, setCategoryFilter] = useState('');
  const [gradeFilter, setGradeFilter] = useState('');
  // ONE lens rather than four checkboxes. Each value is a question a curator actually arrives with,
  // and the tiles above set it — so a number you can see is a list you can open.
  const [lens, setLens] = useState('');
  const [page, setPage] = useState(1);

  const matchesLens = useCallback((w) => {
    switch (lens) {
      case 'picker':        return w.is_active && w.in_picker;
      case 'not_in_picker': return w.is_active && !w.in_picker && !w.withheld;
      case 'garage_only':   return w.is_active && !w.in_picker && w.withheld;
      case 'no_vocabulary': return w.is_active && !w.has_vocabulary;
      case 'disagrees':     return w.grade_disagrees;
      case 'retired':       return !w.is_active;
      default:              return true;
    }
  }, [lens]);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return words.filter((w) => {
      if (categoryFilter && w.category_key !== categoryFilter) return false;
      if (gradeFilter && w.grade !== gradeFilter) return false;
      if (!matchesLens(w)) return false;
      if (!q) return true;
      return [w.name, w.name_ar, w.description, w.category_label, w.category_label_ar]
        .filter(Boolean)
        .some((v) => String(v).toLowerCase().includes(q));
    });
  }, [words, search, categoryFilter, gradeFilter, matchesLens]);

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
  const [busyKey, setBusyKey] = useState(null);
  const [knowledgeFor, setKnowledgeFor] = useState(null);

  const onField = (k, v) => {
    setForm((f) => ({ ...f, [k]: v }));
    setFormErrors((e) => ({ ...e, [k]: undefined }));
  };

  const setLensFrom = (value) => {
    setLens((prev) => (prev === value ? '' : value));
    setPage(1);
  };

  const openAdd = () => {
    setEditing(null);
    setForm({ ...emptyForm, category_key: categoryFilter || categories[0]?.key || '' });
    setFormErrors({});
    setModalOpen(true);
  };

  const openEdit = (w) => {
    setEditing(w);
    setForm({
      name: w.name || '',
      name_ar: w.name_ar || '',
      category_key: w.category_key || '',
      grade: w.grade || 'routine',
      on_site: !!w.on_site,
      description: w.description || '',
    });
    setFormErrors({});
    setModalOpen(true);
  };

  /**
   * Save through the door that owns the row.
   *
   * A word with a fault type is written on the catalog endpoint (it carries `on_site`, which only that
   * half has); a word that exists only in the library is written on the keyword endpoint. Either way
   * the registrar behind that endpoint carries the change to the other half — which is why this is one
   * request and not two, and why the two halves cannot end up describing the same fault differently.
   */
  const save = async () => {
    setSaving(true);
    setFormErrors({});

    const throughCatalog = !editing || !!editing.fault_id;

    try {
      let message;

      if (throughCatalog) {
        const payload = {
          name: form.name.trim(),
          name_ar: form.name_ar?.trim() || null,
          category_key: form.category_key,
          default_severity: form.grade,
          on_site: form.on_site ? 1 : 0,
          description: form.description?.trim() || null,
        };
        const { data: res } = editing
          ? await api.post(`/fault-catalog/${editing.fault_id}`, payload)
          : await api.post('/fault-catalog', payload);
        // The backend's own message is the interesting one on a create — it says whether the new word
        // has any vocabulary behind it, which the form cannot show.
        message = res?.message;
      } else {
        const { data: res } = await api.post(`/finding-keywords/${editing.keyword_id}`, {
          category_key: form.category_key,
          keyword: form.name.trim(),
          keyword_ar: form.name_ar?.trim() || null,
          risk: form.grade,
          description: form.description?.trim() || null,
          is_active: editing.is_active,
        });
        message = res?.message;
      }

      setModalOpen(false);
      toast.success(message || t('Saved'));
      reload();
    } catch (e) {
      const res = e?.response?.data;
      const errs = res?.data && typeof res.data === 'object' ? res.data : res?.errors;
      if (errs && typeof errs === 'object') {
        // The two doors name the same field differently. Mapped back onto the form's own names so an
        // error lands on the box that produced it rather than nowhere.
        setFormErrors({
          ...errs,
          name: errs.name || errs.keyword,
          name_ar: errs.name_ar || errs.keyword_ar,
          grade: errs.default_severity || errs.risk,
        });
      }
      toast.error(res?.message || t('Could not save this fault'));
    } finally {
      setSaving(false);
    }
  };

  /** Retire / restore. Both halves move together — the endpoint's registrar sees to the other one. */
  const toggle = async (w) => {
    setBusyKey(w.key);
    try {
      let message;
      if (w.fault_id) {
        const { data: res } = await api.post(`/fault-catalog/${w.fault_id}/toggle`);
        message = res?.message;
      } else {
        const { data: res } = await api.post(`/finding-keywords/${w.keyword_id}`, {
          category_key: w.category_key,
          keyword: w.name,
          keyword_ar: w.name_ar || null,
          risk: w.grade,
          description: w.description || null,
          is_active: !w.is_active,
        });
        message = res?.message;
      }
      toast.success(message || t('Updated'));
      reload();
    } catch (e) {
      // Retiring a config-authored row is refused with an explanation of where the word really lives.
      toast.error(e?.response?.data?.message || t('Could not change this fault'));
    } finally {
      setBusyKey(null);
    }
  };

  const confirmDelete = async () => {
    if (!toDelete) return;
    setDeleting(true);
    try {
      if (toDelete.fault_id) await api.delete(`/fault-catalog/${toDelete.fault_id}`);
      else await api.delete(`/finding-keywords/${toDelete.keyword_id}`);
      toast.success(t('Fault removed from the vocabulary'));
      setToDelete(null);
      reload();
    } catch (e) {
      toast.error(e?.response?.data?.message || t('Could not delete this fault'));
    } finally {
      setDeleting(false);
    }
  };

  if (error) {
    return (
      <div className="p-6">
        <EmptyState title={t('Could not load the fault vocabulary')} message={String(error?.message || error)} />
      </div>
    );
  }

  const tiles = [
    { key: '', label: t('Words in the vocabulary'), value: summary.total, tone: 'plain' },
    { key: 'picker', label: t('Offered in the picker'), value: summary.selectable, tone: 'plain' },
    { key: 'garage_only', label: t('Recorded by the garage'), value: summary.garage_only, tone: 'plain' },
    { key: 'not_in_picker', label: t('Missing from the picker'), value: summary.not_in_picker, tone: 'amber' },
  ];

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <PageHeader
          title={t('Fault Vocabulary')}
          subtitle={t('What an inspector can report, how serious it is, and whether the matcher can read it in a written note.')}
        >
          {canManage && <Button onClick={openAdd}>{t('Add a fault')}</Button>}
        </PageHeader>

        {/* WHERE THE WORDS LIVE. Four separate facts about one library, not four attempts at one
            number: the dictionary is bigger than the menu on purpose, and the page says why instead of
            printing a subtraction that would be arithmetically neat and wrong. Every tile is a filter,
            so a number you can see is a list you can open. */}
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
          {tiles.map((tile) => {
            const active = tile.key && lens === tile.key;
            const amber = tile.tone === 'amber' && (tile.value || 0) > 0;
            return (
              <button
                key={tile.label}
                type="button"
                onClick={() => setLensFrom(tile.key)}
                className={`hover-lift rounded-2xl border bg-white px-5 py-4 text-start shadow-soft ${
                  active ? 'border-indigo-300 ring-2 ring-indigo-500' : amber ? 'border-amber-300/70' : 'border-slate-200/60'
                }`}
              >
                <p className="text-xs font-medium text-slate-500">{tile.label}</p>
                <p className={`mt-1 font-display text-2xl font-bold tracking-tight ${amber ? 'text-amber-600' : 'text-slate-900'}`}>
                  {loading ? '…' : num(tile.value || 0)}
                </p>
              </button>
            );
          })}
        </div>

        {/* Half-added words: graded, matchable, and nobody can tap them. The state that reads as "I
            added this fault and it isn't there". Stated above the table rather than discovered by
            failing to find the chip on the picker screen. */}
        {!loading && summary.not_in_picker > 0 && (
          <div className="rounded-2xl border border-amber-300/70 bg-amber-50 px-5 py-4 text-sm text-amber-900">
            <p className="font-semibold">
              {t('{n} words are graded and matchable, and no inspector can tap them.').replace('{n}', num(summary.not_in_picker))}
            </p>
            <p className="mt-1 text-amber-800">
              {t('Each one is half a fault type — the library knows it, the picker never got it. Open one and save it to write the missing half.')}
            </p>
          </div>
        )}

        {/* The other gap, and a different one. These words ARE tappable; the matcher just cannot find
            them in a sentence, because their wording is authored in commits, not in this form. */}
        {!loading && summary.no_vocabulary > 0 && (
          <button
            type="button"
            onClick={() => setLensFrom('no_vocabulary')}
            className={`block w-full rounded-2xl border px-5 py-4 text-start text-sm ${
              lens === 'no_vocabulary' ? 'border-indigo-300 bg-indigo-50 ring-2 ring-indigo-500' : 'border-slate-200/70 bg-white'
            }`}
          >
            <p className="font-semibold text-slate-800">
              {t('{n} words the matcher cannot recognise in a written note.').replace('{n}', num(summary.no_vocabulary))}
            </p>
            <p className="mt-1 text-slate-500">
              {t('They work in the picker. Their synonyms and workshop slang are authored in the fault ontology, by a developer — not here.')}
            </p>
          </button>
        )}

        {/* Graded twice, differently. Only visible at all because the two halves are finally on one
            page — each screen used to show its own grade and neither could see the other. Not amber:
            nothing is broken and no inspector is blocked, it is a reconciliation to work through. */}
        {!loading && summary.grade_disagrees > 0 && (
          <button
            type="button"
            onClick={() => setLensFrom('disagrees')}
            className={`block w-full rounded-2xl border px-5 py-4 text-start text-sm ${
              lens === 'disagrees' ? 'border-indigo-300 bg-indigo-50 ring-2 ring-indigo-500' : 'border-slate-200/70 bg-white'
            }`}
          >
            <p className="font-semibold text-slate-800">
              {t('{n} faults are graded one way in the picker and another in the library.').replace('{n}', num(summary.grade_disagrees))}
            </p>
            <p className="mt-1 text-slate-500">
              {t('They were graded on two separate screens before this one existed. Opening a row and saving it settles both halves on one grade.')}
            </p>
          </button>
        )}

        {/* Grade filter. Pills rather than tiles: the grade is a property of a word, not a count of
            how healthy the library is, and giving it four big cards would say otherwise. */}
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-xs font-medium uppercase tracking-wide text-slate-500">{t('Grade')}</span>
          {GRADE_ORDER.map((g) => (
            <button
              key={g}
              type="button"
              onClick={() => { setGradeFilter(gradeFilter === g ? '' : g); setPage(1); }}
              className={`rounded-full border px-3 py-1.5 text-sm transition ${
                gradeFilter === g ? 'border-indigo-400 bg-indigo-50 font-semibold text-indigo-700' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
              }`}
            >
              {GRADE_EMOJI[g]} {t(g)} <span className="text-slate-400">{loading ? '' : num(summary[g] || 0)}</span>
            </button>
          ))}
        </div>

        {/* AI knowledge base. The coverage strip answers "how much of my vocabulary does the system
            actually understand?", and the tester proves it on real sentences — the honest way to find
            the under-described words rather than trusting a green tick. */}
        {!loading && (
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div className="rounded-2xl border border-slate-200/60 bg-white px-5 py-4 shadow-soft">
              <p className="text-xs font-medium text-slate-500">{t('keywordAi.coverage')}</p>
              <p className="mt-1 font-display text-2xl font-bold tracking-tight text-slate-900">
                {num(knowledge.enriched)}<span className="text-base font-medium text-slate-400"> / {num(summary.total || 0)}</span>
              </p>
              <p className="mt-1 text-xs text-slate-500">{t('keywordAi.coverageHint', { terms: num(knowledge.term_total) })}</p>
              {/* No API key is a GAP only while something is still undescribed. At full coverage the
                  seeded library already answers every fault, so the same fact is a footnote — amber
                  there reads as "your install is broken" when nothing is. */}
              {!knowledge.ai_available && (
                fullyCovered
                  ? <p className="mt-2 text-xs text-slate-500">{t('keywordAi.notConfiguredComplete')}</p>
                  : <p className="mt-2 text-xs text-amber-600">{t('keywordAi.notConfiguredShort')}</p>
              )}
            </div>
            <div className="lg:col-span-2">
              <KeywordMatchTester />
            </div>
          </div>
        )}

        {/* Analytics — the filtered set, matching the table below. */}
        {!loading && filtered.length > 0 && <KeywordRiskAnalytics keywords={filtered} />}

        <Card>
          <div className="flex flex-wrap items-center gap-3 border-b border-slate-200 px-5 py-3">
            <SearchInput className="flex-1" value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t('Search a fault…')} />
            <Select value={categoryFilter} onChange={(e) => { setCategoryFilter(e.target.value); setPage(1); }} className="max-w-[16rem]">
              <option value="">{t('All categories')}</option>
              {categories.map((c) => <option key={c.key} value={c.key}>{lang === 'ar' ? c.label_ar || c.label : c.label}</option>)}
            </Select>
            <Select value={lens} onChange={(e) => { setLens(e.target.value); setPage(1); }} className="max-w-[16rem]">
              <option value="">{t('Everything')}</option>
              <option value="not_in_picker">{t('Missing from the picker')}</option>
              <option value="garage_only">{t('Recorded by the garage')}</option>
              <option value="no_vocabulary">{t('No words behind them')}</option>
              <option value="disagrees">{t('Graded differently in each half')}</option>
              <option value="retired">{t('Retired')}</option>
            </Select>
          </div>

          <div className="overflow-x-auto">
            <table className="min-w-full border-separate border-spacing-0 text-sm stagger-rows">
              <thead className="bg-slate-50/90">
                <tr className="text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('Fault')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('Category')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('Grade')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('Where it lives')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('What the matcher knows')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-start">{t('Recorded on')}</th>
                  <th className="whitespace-nowrap border-b border-slate-200 px-5 py-3 text-end">{t('Actions')}</th>
                </tr>
              </thead>

              {loading ? (
                <TableSkeleton cols={7} />
              ) : (
                <tbody>
                  {paged.map((w) => {
                    const other = secondary(w);
                    return (
                      <tr key={w.key} className={`bg-white transition-colors even:bg-slate-50/40 hover:bg-indigo-50/40 ${w.is_active ? '' : 'opacity-60'}`}>
                        <td className="border-b border-slate-100 px-5 py-3.5">
                          <div className="flex flex-wrap items-center gap-2">
                            <span className="font-medium text-slate-900">{primary(w)}</span>
                            {w.on_site && <Badge tone="blue">{t('On site')}</Badge>}
                            {/* Where the word came from. Either way the config file owns it: it can be
                                renamed here but not retired, because the next deploy would assert the
                                chip straight back. */}
                            {(w.authored || w.config_offered) && <Badge tone="gray">{t('In config')}</Badge>}
                          </div>
                          {other && <div className="text-xs text-slate-400" dir="auto">{other}</div>}
                          {w.description && <div className="mt-0.5 max-w-md text-xs text-slate-500">{w.description}</div>}
                        </td>
                        <td className="border-b border-slate-100 px-5 py-3.5 text-slate-600">{catLabel(w.category_key)}</td>
                        <td className="border-b border-slate-100 px-5 py-3.5">
                          <Badge tone={GRADE_TONE[w.grade] || 'gray'}>
                            {GRADE_EMOJI[w.grade]} {t(w.grade || '—')}
                          </Badge>
                          {/* Predates the registrar: the two halves were graded on two screens that
                              could not see each other. The OTHER value is shown, not a bare "these
                              differ" flag — the curator is choosing between two grades. Saving the row
                              writes this grade over the other one. */}
                          {w.grade_disagrees && (
                            <div className="mt-1 text-xs text-amber-700">
                              {t('Library says {grade}').replace('{grade}', `${GRADE_EMOJI[w.keyword_grade]} ${t(w.keyword_grade)}`)}
                            </div>
                          )}
                        </td>
                        {/* THE HONEST COLUMN. Withheld-on-purpose and half-added look identical until
                            they are told apart, and flagging the deliberate ones in amber would train
                            the eye to ignore the real one. */}
                        <td className="border-b border-slate-100 px-5 py-3.5">
                          {!w.is_active
                            ? <Badge tone="gray">{t('Retired')}</Badge>
                            : w.in_picker
                              ? <Badge tone="green">{t('In the picker')}</Badge>
                              : w.withheld
                                ? <Badge tone="gray">{t('Garage records it')}</Badge>
                                : <Badge tone="amber">{t('Not in the picker')}</Badge>}
                          {w.is_active && w.half === 'picker_only' && (
                            <div className="mt-1 text-xs text-slate-400">{t('No library row')}</div>
                          )}
                        </td>
                        <td className="border-b border-slate-100 px-5 py-3.5">
                          <div className="flex items-center gap-2">
                            {w.term_count != null && (
                              <>
                                <span className={`text-sm font-medium ${w.term_count > 3 ? 'text-slate-700' : 'text-slate-400'}`}>{num(w.term_count)}</span>
                                <span className="text-xs text-slate-400">{t('keywordAi.termsShort')}</span>
                              </>
                            )}
                            {w.has_vocabulary
                              ? <Badge tone="violet" dot>{t('Understood')}</Badge>
                              : <Badge tone="amber">{t('No words yet')}</Badge>}
                          </div>
                        </td>
                        <td className="border-b border-slate-100 px-5 py-3.5">
                          <span className={w.usage_count > 0 ? 'text-slate-700' : 'text-slate-300'}>
                            {w.usage_count > 0 ? num(w.usage_count) : '—'}
                          </span>
                        </td>
                        <td className="border-b border-slate-100 px-5 py-3.5">
                          <div className="flex justify-end gap-2">
                            {/* The knowledge drawer is the library half's own screen — every surface
                                form, the engineering profile, the enrichment run log. Only a word that
                                HAS a library row has one. */}
                            {w.keyword_id && (
                              <Button variant="secondary" size="sm" onClick={() => setKnowledgeFor(w)}>{t('keywordAi.open')}</Button>
                            )}
                            {canManage && (
                              <>
                                <Button variant="secondary" size="sm" onClick={() => openEdit(w)}>{t('Edit')}</Button>
                                {/* A word the config file owns cannot be retired from here at all —
                                    through EITHER door. The catalog endpoint refuses and says so; the
                                    library endpoint would happily deactivate the grade and leave the
                                    chip standing, which is worse than being told no. */}
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  loading={busyKey === w.key}
                                  disabled={(w.authored || w.config_offered) && w.is_active}
                                  title={(w.authored || w.config_offered) && w.is_active ? t('Authored in the config file — remove it there') : undefined}
                                  onClick={() => toggle(w)}
                                >
                                  {w.is_active ? t('Retire') : t('Restore')}
                                </Button>
                                {!w.authored && !w.config_offered && !w.usage_count && (
                                  <Button variant="ghost" size="sm" className="text-red-600 hover:bg-red-50" onClick={() => setToDelete(w)}>
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
              <EmptyState title={t('No faults match')} message={t('Try a different search, or clear the filters.')} />
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
        title={editing ? t('Edit fault') : t('Add a fault')}
        subtitle={editing ? primary(editing) : t('One save writes both halves: the chip an inspector taps and the grade the engine reads.')}
        size="lg"
        footer={
          <>
            <Button variant="secondary" onClick={() => setModalOpen(false)} disabled={saving}>{t('Cancel')}</Button>
            <Button onClick={save} loading={saving}>{editing ? t('Save') : t('Add a fault')}</Button>
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
              label={t('Grade')}
              required
              value={form.grade}
              error={formErrors.grade?.[0]}
              onChange={(e) => onField('grade', e.target.value)}
            >
              {grades.map((g) => <option key={g} value={g}>{GRADE_EMOJI[g]} {t(g)}</option>)}
            </Select>
          </div>

          {/* What the grade will DO, in the words the picker uses. */}
          <div className={`rounded-lg px-3 py-2 text-xs ring-1 ring-inset ${
            form.grade === 'critical' ? 'bg-red-50 text-red-700 ring-red-600/20'
              : form.grade === 'moderate' ? 'bg-amber-50 text-amber-700 ring-amber-600/20'
                : 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'
          }`}>
            {GRADE_EMOJI[form.grade]} <span className="font-medium">{t(form.grade)}:</span> {t(GRADE_HINT[form.grade])}
          </div>

          <Textarea
            label={t('What this fault means')}
            rows={3}
            placeholder={t('One line, for whoever has to decide what to do about it.')}
            value={form.description}
            error={formErrors.description?.[0]}
            onChange={(e) => onField('description', e.target.value)}
          />

          {/* `on_site` lives on the picker half only, so a library-only word has nowhere to keep it.
              Hidden rather than shown-and-ignored: an inert checkbox is a lie about what saving does. */}
          {(!editing || editing.fault_id) && (
            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                checked={form.on_site}
                onChange={(e) => onField('on_site', e.target.checked)}
              />
              {t('Can be repaired on site (no need to send the car to a garage)')}
            </label>
          )}

          {/* Said before saving, not after. A curator who expects the matcher to start recognising this
              fault in garage notes should find out here that it will not, yet. */}
          {!editing && (
            <div className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-inset ring-amber-600/20">
              {t('Inspectors can tap this straight away. The matcher will not recognise it in written notes until a developer adds its wording to the fault ontology.')}
            </div>
          )}

          {/* A word with no fault type behind it is one of THREE different things, and only the last
              is broken. Saying "nobody can tap this" about a word the config file offers would be
              plainly wrong on 14 rows here. */}
          {editing && !editing.fault_id && (
            <div className={`rounded-lg px-3 py-2 text-xs ring-1 ring-inset ${
              editing.in_picker || editing.withheld
                ? 'bg-slate-50 text-slate-600 ring-slate-300'
                : 'bg-amber-50 text-amber-800 ring-amber-600/20'
            }`}>
              {editing.withheld
                ? t('This word is withheld from the picker on purpose — the garage records it during the repair. Saving will not add it.')
                : editing.in_picker
                  ? t('The config file offers this word directly, so it is tappable without a fault type of its own. Renaming or re-grading it here changes the library half only.')
                  : t('This word has no fault type behind it, so nobody can tap it. Saving writes the missing half and puts it in the picker.')}
            </div>
          )}

          {editing?.grade_disagrees && (
            <div className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-inset ring-amber-600/20">
              {t('The library currently grades this {grade}. It was graded twice, on two screens, before they became one — saving reconciles both halves to the grade above.')
                .replace('{grade}', `${GRADE_EMOJI[editing.keyword_grade]} ${t(editing.keyword_grade)}`)}
            </div>
          )}

          {editing?.authored && (
            <div className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 ring-1 ring-inset ring-slate-300">
              {t('This fault is also written in the application config. Renaming it here sticks, and the next deployment will leave your version alone.')}
            </div>
          )}
        </div>
      </Modal>

      {/* The library half in full — every surface form, the engineering profile, the run log. */}
      <KeywordKnowledgeDrawer
        keywordId={knowledgeFor?.keyword_id}
        open={!!knowledgeFor}
        onClose={() => setKnowledgeFor(null)}
        canManage={canManage}
        onChanged={reload}
      />

      <ConfirmDialog
        open={!!toDelete}
        onClose={() => !deleting && setToDelete(null)}
        onConfirm={confirmDelete}
        loading={deleting}
        title={t('Delete this fault?')}
        confirmText={t('Delete')}
        message={toDelete ? t('“{name}” has never been used on a task, so deleting it loses nothing.').replace('{name}', primary(toDelete)) : ''}
      />
    </div>
  );
}
