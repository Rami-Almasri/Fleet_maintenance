import { useCallback, useEffect, useMemo, useState } from 'react';
import api from '../../api/client';
import { useToast } from '../ui/Toast';
import { useI18n } from '../../i18n/I18nContext';
import Badge from '../ui/Badge';
import Button from '../ui/Button';
import Modal from '../ui/Modal';
import ConfirmDialog from '../ui/ConfirmDialog';
import { Spinner, EmptyState } from '../ui/Misc';
import { Input, Select } from '../ui/Field';

/**
 * The AI knowledge base behind ONE fault keyword.
 *
 * The library table shows the concept; this drawer shows everything the ontology knows about it —
 * every surface form a human might type (synonyms, workshop wording, abbreviations, spelling
 * variants, misspellings, Arabic), and the engineering profile behind the fault (system,
 * components, causes, repairs, related faults).
 *
 * Two things it deliberately never hides ([[traceability-visibility-requirement]]):
 *  - PROVENANCE. Every term chip says whether a human, a seed or the AI wrote it, and how confident
 *    the source was. Hover any chip for the full breakdown.
 *  - DISAGREEMENT. When the model's severity read differs from the admin's risk grade, it is called
 *    out rather than reconciled — a human owns the grade, the model only gets to have an opinion.
 *
 * Editing a term marks it human-owned on the backend, which permanently protects it from being
 * rewritten by a later enrichment run. That's the contract that makes "Enrich" safe to press twice.
 */

// Order the term groups by how much they matter to a reader — canonical first, then the wordings
// people actually type, then the deliberate typos that exist purely to widen search matching.
const KIND_ORDER = [
  'canonical', 'synonym', 'workshop_phrase', 'customer_phrase', 'abbreviation',
  'spelling_variant', 'misspelling', 'translation',
];

const SOURCE_TONE = { human: 'indigo', seed: 'slate', ai: 'violet' };

const emptyTerm = { term: '', lang: 'en', kind: 'synonym', confidence: 100, workshop_frequency: '', source_quality: '', is_active: true };

export default function KeywordKnowledgeDrawer({ keywordId, open, onClose, canManage, onChanged }) {
  const toast = useToast();
  const { t, lang } = useI18n();

  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [enriching, setEnriching] = useState(false);
  const [langFilter, setLangFilter] = useState('');

  // inline term editor
  const [termForm, setTermForm] = useState(null);   // null = closed, {} = adding, {id} = editing
  const [savingTerm, setSavingTerm] = useState(false);
  const [termToDelete, setTermToDelete] = useState(null);

  const load = useCallback(async () => {
    if (!keywordId) return;
    setLoading(true);
    try {
      const { data: res } = await api.get(`/finding-keywords/${keywordId}`);
      setData(res.data || null);
    } catch (err) {
      toast.error(err.response?.data?.message || t('keywordAi.loadError'));
    } finally {
      setLoading(false);
    }
  }, [keywordId, toast, t]);

  useEffect(() => {
    if (open) {
      setTermForm(null);
      setLangFilter('');
      load();
    }
  }, [open, load]);

  const keyword = data?.keyword;
  const profile = keyword?.profile;
  const aiAvailable = data?.ai_available;
  const graph = data?.graph;
  const evidence = data?.evidence || [];
  const grounding = data?.grounding ?? 0;
  const terms = useMemo(() => keyword?.terms || [], [keyword]);

  const shownTerms = useMemo(
    () => (langFilter ? terms.filter((x) => x.lang === langFilter) : terms),
    [terms, langFilter],
  );

  // Group by kind for display, preserving KIND_ORDER and dropping empty groups.
  const grouped = useMemo(() => {
    const map = {};
    shownTerms.forEach((x) => { (map[x.kind] ||= []).push(x); });
    return KIND_ORDER.filter((k) => map[k]?.length).map((k) => [k, map[k]]);
  }, [shownTerms]);

  const enrich = async () => {
    setEnriching(true);
    try {
      const { data: res } = await api.post(`/finding-keywords/${keywordId}/enrich`);
      setData((d) => ({ ...d, keyword: res.data.keyword }));
      toast.success(res.message || t('keywordAi.enriched'));
      onChanged?.();
    } catch (err) {
      toast.error(err.response?.data?.message || t('keywordAi.enrichError'));
    } finally {
      setEnriching(false);
    }
  };

  const saveTerm = async () => {
    setSavingTerm(true);
    try {
      const payload = {
        term: termForm.term.trim(),
        lang: termForm.lang,
        kind: termForm.kind,
        confidence: Number(termForm.confidence) || 100,
        workshop_frequency: termForm.workshop_frequency || null,
        source_quality: termForm.source_quality || null,
        is_active: termForm.is_active,
      };
      const url = termForm.id
        ? `/finding-keywords/${keywordId}/terms/${termForm.id}`
        : `/finding-keywords/${keywordId}/terms`;
      await api.post(url, payload);
      toast.success(termForm.id ? t('keywordAi.termUpdated') : t('keywordAi.termAdded'));
      setTermForm(null);
      await load();
      onChanged?.();
    } catch (err) {
      toast.error(err.response?.data?.message || t('keywordAi.termSaveError'));
    } finally {
      setSavingTerm(false);
    }
  };

  const deleteTerm = async () => {
    try {
      await api.delete(`/finding-keywords/${keywordId}/terms/${termToDelete.id}`);
      toast.success(t('keywordAi.termRemoved'));
      setTermToDelete(null);
      await load();
      onChanged?.();
    } catch (err) {
      toast.error(err.response?.data?.message || t('keywordAi.termRemoveError'));
    }
  };

  const kwTitle = keyword ? (lang === 'ar' ? keyword.keyword_ar || keyword.keyword : keyword.keyword) : '';

  return (
    <>
      <Modal
        open={open}
        onClose={() => !enriching && onClose()}
        title={t('keywordAi.title')}
        subtitle={kwTitle}
        size="xl"
        footer={
          <>
            <Button variant="secondary" onClick={onClose} disabled={enriching}>{t('common.close')}</Button>
            {canManage && aiAvailable && (
              <Button onClick={enrich} loading={enriching}>
                {profile ? t('keywordAi.refresh') : t('keywordAi.enrich')}
              </Button>
            )}
          </>
        }
      >
        {loading && !keyword ? (
          <div className="flex justify-center py-16"><Spinner className="h-6 w-6" /></div>
        ) : !keyword ? (
          <EmptyState title={t('keywordAi.loadError')} />
        ) : (
          <div className="space-y-6">
            {/* No API key configured — say so plainly rather than offering a button that fails. */}
            {!aiAvailable && (
              <div className="rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600 ring-1 ring-inset ring-slate-300/60">
                {t('keywordAi.notConfigured')}
              </div>
            )}

            {/* Nothing generated yet — explain what pressing Enrich will actually do. */}
            {!profile && (
              <div className="rounded-lg bg-indigo-50 px-4 py-3 text-sm text-indigo-800 ring-1 ring-inset ring-indigo-600/20">
                {t('keywordAi.notEnriched')}
              </div>
            )}

            {profile && (
              <>
                {/* Where the fault lives on the car + how sure the model is about the whole entry. */}
                <section className="space-y-3">
                  <div className="flex flex-wrap items-center gap-2">
                    {profile.vehicle_system && <Badge tone="blue" dot>{profile.vehicle_system}</Badge>}
                    {profile.subsystem && <Badge tone="cyan">{profile.subsystem}</Badge>}
                    {profile.repair_discipline && (
                      <Badge tone="slate">{t(`keywordAi.discipline.${profile.repair_discipline}`)}</Badge>
                    )}
                    {profile.severity_estimate && (
                      <Badge tone={profile.severity_tone || 'amber'} dot>
                        {t('keywordAi.aiSeverity')}: {t(`findingKeywords.risk.${profile.severity_estimate}`)}
                      </Badge>
                    )}
                    <span className="ms-auto text-xs text-slate-500">
                      {t('keywordAi.confidence')} {profile.confidence}%
                    </span>
                  </div>

                  {/* The model disagrees with the admin's grade. Surfaced, never applied. */}
                  {profile.severity_disagrees && (
                    <div className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-inset ring-amber-600/20">
                      {t('keywordAi.severityConflict', {
                        yours: t(`findingKeywords.risk.${keyword.risk}`),
                        ai: t(`findingKeywords.risk.${profile.severity_estimate}`),
                      })}
                    </div>
                  )}

                  {(lang === 'ar' ? profile.summary_ar || profile.summary_en : profile.summary_en) && (
                    <p className="text-sm leading-relaxed text-slate-700" dir="auto">
                      {lang === 'ar' ? profile.summary_ar || profile.summary_en : profile.summary_en}
                    </p>
                  )}
                </section>

                {/* The knowledge payload — four plain lists, no ceremony. */}
                <section className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <KnowledgeList title={t('keywordAi.symptoms')} items={profile.symptoms} tone="amber" />
                  <KnowledgeList title={t('keywordAi.components')} items={profile.components} tone="blue" />
                  <KnowledgeList title={t('keywordAi.causes')} items={profile.likely_causes} tone="violet" />
                  <KnowledgeList title={t('keywordAi.repairs')} items={profile.repair_actions} tone="green" />
                </section>

                {profile.related_faults?.length > 0 && (
                  <section>
                    <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{t('keywordAi.related')}</h4>
                    <div className="flex flex-wrap gap-1.5">
                      {profile.related_faults.map((f) => <Badge key={f} tone="gray">{f}</Badge>)}
                    </div>
                  </section>
                )}

                {/* What the job takes. Documented book time and our own measured turnaround are
                    shown separately on purpose — the gap between them is itself a finding. */}
                {(profile.complexity || profile.inspection_order?.length > 0 || profile.required_tools?.length > 0) && (
                  <section className="rounded-xl bg-slate-50 p-4">
                    <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{t('keywordAi.repairIntel')}</h4>
                    <div className="flex flex-wrap items-center gap-2">
                      {profile.complexity && (
                        <Badge tone={profile.complexity === 'specialist' ? 'red' : profile.complexity === 'complex' ? 'amber' : 'green'} dot>
                          {t(`keywordAi.complexity.${profile.complexity}`)}
                        </Badge>
                      )}
                      {profile.labor_hours_min != null && (
                        <Badge tone="slate">
                          {t('keywordAi.bookTime')}: {profile.labor_hours_min}–{profile.labor_hours_max} h
                        </Badge>
                      )}
                    </div>

                    {profile.inspection_order?.length > 0 && (
                      <div className="mt-3">
                        <p className="mb-1 text-[11px] font-medium uppercase tracking-wide text-slate-400">{t('keywordAi.inspectionOrder')}</p>
                        <ol className="space-y-1">
                          {profile.inspection_order.map((step, i) => (
                            <li key={step} className="flex items-start gap-2 text-sm text-slate-700">
                              <span className="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-[10px] font-semibold text-indigo-700">{i + 1}</span>
                              <span dir="auto">{step}</span>
                            </li>
                          ))}
                        </ol>
                      </div>
                    )}

                    <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                      <KnowledgeList title={t('keywordAi.tools')} items={profile.required_tools} tone="blue" />
                      <KnowledgeList title={t('keywordAi.skills')} items={profile.required_skills} tone="violet" />
                    </div>

                    {/* Cost and duration PREDICTION is owned by the maintenance-side Repair
                        Intelligence module, which works from ~49k real repair signatures. The
                        ontology only carries documented knowledge — pointing at the better source
                        rather than competing with it. */}
                    <p className="mt-3 text-[11px] text-slate-400">{t('keywordAi.predictionNote')}</p>
                  </section>
                )}

                {profile.evidence_sources?.length > 0 && (
                  <section className="rounded-lg bg-slate-50 px-3 py-2">
                    <h4 className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">{t('keywordAi.sources')}</h4>
                    <p className="text-xs text-slate-600">{profile.evidence_sources.join(' · ')}</p>
                  </section>
                )}
              </>
            )}

            {/* ---- The knowledge graph around this fault ---- */}
            {graph && Object.values(graph).some((v) => v?.length > 0) && (
              <section className="space-y-3">
                <h4 className="text-xs font-semibold uppercase tracking-wide text-slate-500">{t('keywordAi.relationships')}</h4>
                {[
                  ['causes', 'amber'], ['repairs', 'green'], ['components', 'blue'],
                  ['inspection', 'indigo'], ['symptoms', 'violet'], ['related', 'slate'],
                ].map(([key, tone]) => (
                  <GraphGroup key={key} title={t(`keywordAi.rel.${key}`)} items={graph[key]} tone={tone} t={t} />
                ))}
              </section>
            )}

            {/* ---- Citations, and an honest grounding score ---- */}
            <section>
              <div className="mb-2 flex items-center gap-2">
                <h4 className="text-xs font-semibold uppercase tracking-wide text-slate-500">{t('keywordAi.evidence')}</h4>
                <Badge tone={grounding >= 60 ? 'green' : grounding >= 25 ? 'amber' : 'gray'}>
                  {t('keywordAi.grounding')} {grounding}%
                </Badge>
              </div>
              {evidence.length === 0 ? (
                <p className="text-xs text-slate-400">{t('keywordAi.noEvidence')}</p>
              ) : (
                <div className="space-y-1">
                  {evidence.map((e) => (
                    <div key={e.id} className={`rounded-lg px-3 py-1.5 text-xs ring-1 ring-inset ${
                      e.is_grounded ? 'bg-blue-50 text-blue-900 ring-blue-600/20' : 'bg-slate-50 text-slate-500 ring-slate-300/50'
                    }`}>
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{e.document_title}</span>
                        {e.section && <span className="opacity-70">{e.section}</span>}
                        <span className="ms-auto opacity-60">{t(`keywordAi.method.${e.retrieval_method}`)}</span>
                      </div>
                      {e.url && (
                        <a href={e.url} target="_blank" rel="noopener noreferrer" className="underline opacity-80">{e.url}</a>
                      )}
                    </div>
                  ))}
                </div>
              )}
            </section>

            {/* ---- Surface forms: the part that actually powers search ---- */}
            <section className="space-y-3">
              <div className="flex flex-wrap items-center gap-3">
                <h4 className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                  {t('keywordAi.terms')} <span className="text-slate-400">({terms.length})</span>
                </h4>
                <Select className="w-32" value={langFilter} onChange={(e) => setLangFilter(e.target.value)}>
                  <option value="">{t('keywordAi.allLangs')}</option>
                  <option value="en">English</option>
                  <option value="ar">العربية</option>
                </Select>
                {canManage && (
                  <Button variant="secondary" size="sm" className="ms-auto" onClick={() => setTermForm({ ...emptyTerm })}>
                    {t('keywordAi.addTerm')}
                  </Button>
                )}
              </div>

              {grouped.length === 0 ? (
                <p className="text-sm text-slate-400">{t('keywordAi.noTerms')}</p>
              ) : (
                grouped.map(([kind, items]) => (
                  <div key={kind}>
                    <p className="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-slate-400">
                      {t(`keywordAi.kind.${kind}`)} <span className="text-slate-300">· {items.length}</span>
                    </p>
                    <div className="flex flex-wrap gap-1.5">
                      {items.map((x) => (
                        <TermChip
                          key={x.id}
                          term={x}
                          canManage={canManage}
                          onEdit={() => setTermForm({ ...x, workshop_frequency: x.workshop_frequency || '', source_quality: x.source_quality || '' })}
                          onDelete={() => setTermToDelete(x)}
                          t={t}
                        />
                      ))}
                    </div>
                  </div>
                ))
              )}
            </section>

            {/* ---- Inline term editor ---- */}
            {termForm && (
              <section className="space-y-3 rounded-xl bg-slate-50 p-4 ring-1 ring-inset ring-slate-200">
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                  {termForm.id ? t('keywordAi.editTerm') : t('keywordAi.addTerm')}
                </p>
                <p className="text-xs text-slate-500">{t('keywordAi.humanOwnedHint')}</p>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <Input
                    label={t('keywordAi.fieldTerm')}
                    dir="auto"
                    value={termForm.term}
                    onChange={(e) => setTermForm((f) => ({ ...f, term: e.target.value }))}
                  />
                  <Select
                    label={t('keywordAi.fieldKind')}
                    value={termForm.kind}
                    onChange={(e) => setTermForm((f) => ({ ...f, kind: e.target.value }))}
                  >
                    {KIND_ORDER.map((k) => <option key={k} value={k}>{t(`keywordAi.kind.${k}`)}</option>)}
                  </Select>
                  <Select
                    label={t('keywordAi.fieldLang')}
                    value={termForm.lang}
                    onChange={(e) => setTermForm((f) => ({ ...f, lang: e.target.value }))}
                  >
                    <option value="en">English</option>
                    <option value="ar">العربية</option>
                  </Select>
                  <Select
                    label={t('keywordAi.fieldFrequency')}
                    value={termForm.workshop_frequency}
                    onChange={(e) => setTermForm((f) => ({ ...f, workshop_frequency: e.target.value }))}
                  >
                    <option value="">—</option>
                    {['very_high', 'high', 'medium', 'low', 'rare'].map((f) => (
                      <option key={f} value={f}>{t(`keywordAi.frequency.${f}`)}</option>
                    ))}
                  </Select>
                </div>

                <div className="flex items-center gap-2">
                  <Button size="sm" onClick={saveTerm} loading={savingTerm} disabled={!termForm.term.trim()}>
                    {t('common.save')}
                  </Button>
                  <Button size="sm" variant="secondary" onClick={() => setTermForm(null)} disabled={savingTerm}>
                    {t('common.cancel')}
                  </Button>
                </div>
              </section>
            )}

            {/* ---- Audit trail: every AI run against this concept ---- */}
            {keyword.runs?.length > 0 && (
              <section>
                <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{t('keywordAi.runs')}</h4>
                <div className="space-y-1">
                  {keyword.runs.map((r) => (
                    <div key={r.id} className="flex flex-wrap items-center gap-2 rounded-lg bg-slate-50 px-3 py-1.5 text-xs text-slate-600">
                      <Badge tone={r.status === 'success' ? 'green' : r.status === 'failed' ? 'red' : 'slate'}>{r.status}</Badge>
                      <span className="font-mono text-[11px] text-slate-400">{r.model}</span>
                      {r.status === 'success' && <span>+{r.terms_added} / ~{r.terms_updated}</span>}
                      {r.error && <span className="text-red-600">{r.error}</span>}
                      <span className="ms-auto text-slate-400">
                        {r.by ? `${r.by} · ` : ''}{new Date(r.created_at).toLocaleString(lang === 'ar' ? 'ar-AE-u-ca-gregory-nu-latn' : undefined)}
                      </span>
                    </div>
                  ))}
                </div>
              </section>
            )}
          </div>
        )}
      </Modal>

      <ConfirmDialog
        open={!!termToDelete}
        onClose={() => setTermToDelete(null)}
        onConfirm={deleteTerm}
        title={t('keywordAi.removeTermTitle')}
        confirmText={t('findingKeywords.remove')}
        message={termToDelete ? t('keywordAi.removeTermMsg', { term: termToDelete.term }) : ''}
      />
    </>
  );
}

/** One surface form. The title attribute carries the full provenance so nothing is hidden. */
function TermChip({ term, canManage, onEdit, onDelete, t }) {
  const tone = SOURCE_TONE[term.source] || 'gray';
  const detail = [
    `${t('keywordAi.confidence')} ${term.confidence}%`,
    term.workshop_frequency ? t(`keywordAi.frequency.${term.workshop_frequency}`) : null,
    term.source_quality ? term.source_quality.toUpperCase() : null,
    t(`keywordAi.source.${term.source}`),
    `${t('keywordAi.rank')} ${term.search_rank}`,
  ].filter(Boolean).join(' · ');

  return (
    <span
      title={detail}
      className={`group inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs ring-1 ring-inset ${
        term.is_active ? 'bg-white ring-slate-300' : 'bg-slate-100 text-slate-400 line-through ring-slate-200'
      }`}
      dir="auto"
    >
      <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${
        tone === 'indigo' ? 'bg-indigo-500' : tone === 'violet' ? 'bg-violet-400' : 'bg-slate-300'
      }`} />
      <span className="font-medium text-slate-800">{term.term}</span>
      <span className="text-slate-400">{term.confidence}%</span>
      {canManage && (
        <span className="hidden gap-1 group-hover:inline-flex">
          <button type="button" onClick={onEdit} className="text-slate-400 hover:text-indigo-600" aria-label={t('Edit')}>✎</button>
          <button type="button" onClick={onDelete} className="text-slate-400 hover:text-red-600" aria-label={t('Delete')}>×</button>
        </span>
      )}
    </span>
  );
}

/**
 * One relation group from the graph, ranked.
 *
 * A `fleet` item is rendered differently and labelled with its counts on purpose: "92% of 214 of
 * our own cases" is a categorically stronger claim than a documented association, and flattening
 * the two into one list would teach staff to trust them equally.
 */
function GraphGroup({ title, items, tone, t }) {
  if (!items?.length) return null;

  return (
    <div>
      <p className="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-slate-400">{title}</p>
      <div className="space-y-1">
        {items.map((item) => (
          <div key={item.edge_id} className="flex flex-wrap items-center gap-2 rounded-lg bg-white px-3 py-1.5 text-sm ring-1 ring-inset ring-slate-200">
            <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${
              tone === 'amber' ? 'bg-amber-500' : tone === 'green' ? 'bg-emerald-500'
                : tone === 'blue' ? 'bg-blue-500' : tone === 'indigo' ? 'bg-indigo-500'
                : tone === 'violet' ? 'bg-violet-500' : 'bg-slate-400'
            }`} />
            <span className="text-slate-800" dir="auto">{item.label}</span>

            {item.source === 'fleet' ? (
              <Badge tone="emerald">
                {item.observed_rate}% {t('keywordAi.ofCases', { n: item.observed_count })}
              </Badge>
            ) : (
              <span className="text-xs text-slate-400">{item.weight}%</span>
            )}

            {item.scope && <Badge tone="cyan">{item.scope}</Badge>}

            {/* Parts / tools / skills this repair needs, pulled through the graph. */}
            {item.requires && Object.entries(item.requires).map(([rel, vals]) => (
              <span key={rel} className="text-[11px] text-slate-500">
                {vals.join(', ')}
              </span>
            ))}
          </div>
        ))}
      </div>
    </div>
  );
}

function KnowledgeList({ title, items, tone }) {
  if (!items?.length) return null;
  return (
    <div>
      <h4 className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">{title}</h4>
      <ul className="space-y-1">
        {items.map((x) => (
          <li key={x} className="flex items-start gap-2 text-sm text-slate-700">
            <span className={`mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full ${
              tone === 'amber' ? 'bg-amber-400' : tone === 'blue' ? 'bg-blue-400' : tone === 'violet' ? 'bg-violet-400' : 'bg-emerald-400'
            }`} />
            <span dir="auto">{x}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
