// Concept Bridge Review — the human benchmark that decides whether 26,839 legacy tickets may be
// enriched with ontology concepts.
//
// THE BLIND PASS IS THE POINT. The matcher's predictions stay hidden until the reviewer has said
// what the text means in their own words. A reviewer who sees our answer first tends to agree with
// it, and the benchmark would then measure agreement instead of correctness — so the gate is
// structural here, not a line in a protocol document.
//
// The old ticket signature and the sampling stratum are never sent to the browser: knowing a row was
// picked as "action_like" gives away the first question.

import { useCallback, useEffect, useMemo, useState } from 'react';
import api from '../api/client';
import { useI18n } from '../i18n/I18nContext';
import Icon from '../components/ui/Icon';

const TYPES = ['fault', 'action', 'part', 'procedure', 'operational', 'unclear'];
const VERDICTS = ['correct_specific', 'correct_broad', 'incorrect'];
const QUALITIES = ['strong', 'medium', 'weak'];

const VERDICT_TONE = {
  correct_specific: 'bg-emerald-600 text-white ring-emerald-500',
  correct_broad: 'bg-amber-500 text-white ring-amber-400',
  incorrect: 'bg-rose-600 text-white ring-rose-500',
  none_predicted: 'bg-slate-600 text-white ring-slate-500',
};
const QUALITY_TONE = {
  strong: 'bg-emerald-600 text-white ring-emerald-500',
  medium: 'bg-amber-500 text-white ring-amber-400',
  weak: 'bg-rose-600 text-white ring-rose-500',
};

function Choice({ active, tone, onClick, children, hint }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium ring-1 ring-inset transition
        ${active
          ? tone || 'bg-blue-600 text-white ring-blue-500'
          : 'bg-white text-slate-700 ring-slate-300 hover:bg-slate-50 hover:ring-slate-400'}`}
    >
      {children}
      {hint ? (
        <kbd className={`rounded border px-1 text-[10px] font-mono ${active ? 'border-white/40 text-white/80' : 'border-slate-300 text-slate-400'}`}>
          {hint}
        </kbd>
      ) : null}
    </button>
  );
}

function Section({ n, title, children, hint }) {
  return (
    <div className="mt-6 first:mt-0">
      <div className="mb-2 flex items-baseline gap-2">
        {n ? <span className="text-[11px] font-bold text-slate-400">{n}</span> : null}
        <h3 className="text-[12px] font-bold uppercase tracking-wide text-slate-500">{title}</h3>
      </div>
      {children}
      {hint ? <p className="mt-1.5 text-xs text-slate-500">{hint}</p> : null}
    </div>
  );
}

export default function ConceptBridgeReview() {
  const { t } = useI18n();
  const [state, setState] = useState({ loading: true, error: false, rows: [], progress: null });
  const [i, setI] = useState(0);
  const [revealed, setRevealed] = useState(false);
  const [draft, setDraft] = useState({});
  const [saving, setSaving] = useState(false);
  const [savedAt, setSavedAt] = useState(null);

  const load = useCallback(() => {
    setState((s) => ({ ...s, loading: true, error: false }));
    api.get('/concept-bridge/review')
      .then((r) => {
        const d = r.data?.data || {};
        const rows = d.rows || [];
        setState({ loading: false, error: false, rows, progress: d.progress || null });
        const seed = {};
        rows.forEach((row) => { if (row.answer) seed[row.id] = { ...row.answer }; });
        setDraft(seed);
        // resume at the first unanswered row
        const next = rows.findIndex((row) => !row.answer);
        setI(next === -1 ? 0 : next);
      })
      .catch(() => setState({ loading: false, error: true, rows: [], progress: null }));
  }, []);

  useEffect(() => { load(); }, [load]);

  const row = state.rows[i];
  const ans = (row && draft[row.id]) || {};
  const answeredCount = useMemo(
    () => state.rows.filter((r) => draft[r.id]?.verdict).length,
    [state.rows, draft],
  );

  const patch = (field, value) => {
    if (!row) return;
    setDraft((d) => ({
      ...d,
      [row.id]: { ...d[row.id], [field]: d[row.id]?.[field] === value ? null : value },
    }));
  };
  const setText = (field, value) => {
    if (!row) return;
    setDraft((d) => ({ ...d, [row.id]: { ...d[row.id], [field]: value } }));
  };

  const toggleConcept = (concept, list) => {
    if (!row) return;
    const other = list === 'valid_concepts' ? 'invalid_concepts' : 'valid_concepts';
    setDraft((d) => {
      const cur = d[row.id] || {};
      const inList = (cur[list] || '').split(';').map((s) => s.trim()).filter(Boolean);
      const inOther = (cur[other] || '').split(';').map((s) => s.trim()).filter(Boolean);
      const idx = inList.indexOf(concept);
      if (idx >= 0) inList.splice(idx, 1); else inList.push(concept);
      const oi = inOther.indexOf(concept);
      if (oi >= 0) inOther.splice(oi, 1);
      return { ...d, [row.id]: { ...cur, [list]: inList.join('; '), [other]: inOther.join('; ') } };
    });
  };
  const isIn = (concept, list) =>
    (ans[list] || '').split(';').map((s) => s.trim()).includes(concept);

  const save = async (goNext = true) => {
    if (!row || !ans.verdict) return;
    setSaving(true);
    try {
      await api.post(`/concept-bridge/review/${row.id}`, {
        segment_type: ans.segment_type || null,
        verdict: ans.verdict || null,
        evidence_quality: ans.evidence_quality || null,
        valid_concepts: ans.valid_concepts || null,
        invalid_concepts: ans.invalid_concepts || null,
        missing_concepts: ans.missing_concepts || null,
        notes: ans.notes || null,
      });
      setSavedAt(Date.now());
      setState((s) => ({
        ...s,
        rows: s.rows.map((r) => (r.id === row.id ? { ...r, answer: { ...ans } } : r)),
      }));
      if (goNext && i < state.rows.length - 1) { setI(i + 1); setRevealed(false); window.scrollTo(0, 0); }
    } finally {
      setSaving(false);
    }
  };

  // keyboard: 1-6 pick (blind question first, then verdict), q/w/e quality, space reveal/next
  useEffect(() => {
    const onKey = (e) => {
      if (['INPUT', 'TEXTAREA'].includes(e.target.tagName)) return;
      const n = parseInt(e.key, 10);
      if (e.key === ' ') {
        e.preventDefault();
        if (!revealed) setRevealed(true); else save(true);
        return;
      }
      if (e.key === 'ArrowLeft' && i > 0) { setI(i - 1); setRevealed(true); return; }
      if (e.key === 'ArrowRight' && i < state.rows.length - 1) { setI(i + 1); setRevealed(false); return; }
      if (!revealed) { if (n >= 1 && n <= 6) patch('segment_type', TYPES[n - 1]); return; }
      if (n >= 1 && n <= 3) patch('verdict', VERDICTS[n - 1]);
      if ('qwe'.includes(e.key)) patch('evidence_quality', QUALITIES['qwe'.indexOf(e.key)]);
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  });

  if (state.loading) return <div className="p-8 text-slate-500">{t('conceptBridge.loading')}</div>;
  if (state.error) return <div className="p-8 text-rose-600">{t('conceptBridge.loadError')}</div>;
  if (!state.rows.length) return <div className="p-8 text-slate-500">{t('conceptBridge.empty')}</div>;

  const total = state.rows.length;
  const pct = Math.round((100 * answeredCount) / total);
  const showPreds = revealed || !!row.answer;

  return (
    <div className="mx-auto max-w-3xl px-4 pb-24 pt-6">
      <header className="mb-4">
        <h1 className="text-xl font-bold text-slate-900">{t('conceptBridge.title')}</h1>
        <p className="mt-1 text-sm text-slate-600">{t('conceptBridge.subtitle')}</p>
      </header>

      <div className="mb-5">
        <div className="mb-1.5 flex items-center justify-between text-xs text-slate-500">
          <span>{t('conceptBridge.progress', { i: i + 1, total, done: answeredCount })}</span>
          <span>{pct}%</span>
        </div>
        <div className="h-1.5 overflow-hidden rounded-full bg-slate-200">
          <div className="h-full rounded-full bg-blue-600 transition-all" style={{ width: `${pct}%` }} />
        </div>
      </div>

      {/* ── the question ───────────────────────────────────────────────── */}
      <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <blockquote className="rounded-lg border-s-4 border-blue-500 bg-slate-50 px-4 py-3.5 text-lg font-medium leading-relaxed text-slate-900">
          {row.segment}
        </blockquote>
        <p className="mt-2 text-[11px] text-slate-400">
          {t('conceptBridge.rowMeta', { ticket: row.ticket_id ?? '—', field: row.field || '—' })}
        </p>

        <Section n="1" title={t('conceptBridge.q1')}>
          <div className="flex flex-wrap gap-2">
            {TYPES.map((k, n) => (
              <Choice key={k} active={ans.segment_type === k} hint={String(n + 1)}
                onClick={() => patch('segment_type', k)}>
                {t(`conceptBridge.type.${k}`)}
              </Choice>
            ))}
          </div>
        </Section>

        <Section n="2" title={t('conceptBridge.q2')} hint={t('conceptBridge.q2Hint')}>
          <textarea
            rows={2}
            value={ans.missing_concepts || ''}
            onChange={(e) => setText('missing_concepts', e.target.value)}
            placeholder={t('conceptBridge.q2Placeholder')}
            className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          />
        </Section>
      </div>

      {/* ── the reveal gate ────────────────────────────────────────────── */}
      {!showPreds ? (
        <div className="mt-4 rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center">
          <p className="mb-3 text-sm text-slate-500">{t('conceptBridge.revealHint')}</p>
          <button type="button" onClick={() => setRevealed(true)}
            className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">
            {t('conceptBridge.reveal')}
            <kbd className="rounded border border-white/40 px-1 text-[10px] font-mono text-white/80">{t('space')}</kbd>
          </button>
        </div>
      ) : (
        <div className="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
          <Section n="3" title={t('conceptBridge.q3')}>
            {row.predictions.length === 0 ? (
              <p className="text-sm text-slate-500">{t('conceptBridge.noPrediction')}</p>
            ) : (
              <div className="space-y-2">
                {row.predictions.map((p) => (
                  <div key={p.rank} className="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3.5 py-2.5">
                    <span className="flex-1 text-sm font-semibold text-slate-800">{p.concept}</span>
                    <span className="rounded-full bg-white px-2 py-0.5 font-mono text-[11px] text-slate-500 ring-1 ring-inset ring-slate-200">{p.score}</span>
                    {p.rank === 1 && row.stage ? (
                      <span className="rounded-full bg-white px-2 py-0.5 font-mono text-[11px] text-slate-500 ring-1 ring-inset ring-slate-200">{row.stage}</span>
                    ) : null}
                    <span className="flex gap-1.5">
                      <button type="button" onClick={() => toggleConcept(p.concept, 'valid_concepts')}
                        className={`rounded-md px-2.5 py-1 text-xs font-semibold ring-1 ring-inset transition ${
                          isIn(p.concept, 'valid_concepts')
                            ? 'bg-emerald-600 text-white ring-emerald-500'
                            : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-100'}`}>
                        {t('conceptBridge.yes')}
                      </button>
                      <button type="button" onClick={() => toggleConcept(p.concept, 'invalid_concepts')}
                        className={`rounded-md px-2.5 py-1 text-xs font-semibold ring-1 ring-inset transition ${
                          isIn(p.concept, 'invalid_concepts')
                            ? 'bg-rose-600 text-white ring-rose-500'
                            : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-100'}`}>
                        {t('conceptBridge.no')}
                      </button>
                    </span>
                  </div>
                ))}
              </div>
            )}
            {row.matched_term ? (
              <p className="mt-2 text-[11px] text-slate-400">
                {t('conceptBridge.matchedOn', { term: row.matched_term })}
              </p>
            ) : null}
          </Section>

          <Section n="4"
            title={row.predictions[0]
              ? t('conceptBridge.q4', { concept: row.predictions[0].concept })
              : t('conceptBridge.q4Plain')}
            hint={t('conceptBridge.q4Hint')}>
            <div className="flex flex-wrap gap-2">
              {(row.predictions.length ? VERDICTS : ['none_predicted']).map((v, n) => (
                <Choice key={v} active={ans.verdict === v} tone={VERDICT_TONE[v]}
                  hint={row.predictions.length ? String(n + 1) : undefined}
                  onClick={() => patch('verdict', v)}>
                  {t(`conceptBridge.verdict.${v}`)}
                </Choice>
              ))}
            </div>
          </Section>

          {(ans.verdict === 'correct_specific' || ans.verdict === 'correct_broad') && (
            <Section n="5" title={t('conceptBridge.q5')} hint={t('conceptBridge.q5Hint')}>
              <div className="flex flex-wrap gap-2">
                {QUALITIES.map((q, n) => (
                  <Choice key={q} active={ans.evidence_quality === q} tone={QUALITY_TONE[q]} hint={'qwe'[n]}
                    onClick={() => patch('evidence_quality', q)}>
                    {t(`conceptBridge.quality.${q}`)}
                  </Choice>
                ))}
              </div>
            </Section>
          )}

          <Section title={t('conceptBridge.notes')}>
            <input
              type="text"
              value={ans.notes || ''}
              onChange={(e) => setText('notes', e.target.value)}
              placeholder={t('conceptBridge.notesPlaceholder')}
              className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </Section>
        </div>
      )}

      {/* ── nav ────────────────────────────────────────────────────────── */}
      <div className="fixed inset-x-0 bottom-0 border-t border-slate-200 bg-white/95 backdrop-blur">
        <div className="mx-auto flex max-w-3xl items-center gap-3 px-4 py-3">
          <button type="button" disabled={i === 0}
            onClick={() => { setI(i - 1); setRevealed(true); window.scrollTo(0, 0); }}
            className="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 disabled:opacity-40">
            {t('conceptBridge.back')}
          </button>
          <div className="flex-1" />
          {savedAt && Date.now() - savedAt < 2000 ? (
            <span className="flex items-center gap-1 text-xs font-medium text-emerald-600">
              <Icon.Check className="h-3.5 w-3.5" />{t('conceptBridge.saved')}
            </span>
          ) : null}
          <button type="button" disabled={!ans.verdict || saving} onClick={() => save(true)}
            className="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-40">
            {i === total - 1 ? t('conceptBridge.saveFinish') : t('conceptBridge.saveNext')}
          </button>
        </div>
      </div>
    </div>
  );
}
