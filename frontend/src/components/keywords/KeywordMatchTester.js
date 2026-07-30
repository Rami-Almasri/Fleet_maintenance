import { useState } from 'react';
import api from '../../api/client';
import { useToast } from '../ui/Toast';
import { useI18n } from '../../i18n/I18nContext';
import Badge from '../ui/Badge';
import Button from '../ui/Button';
import { Card } from '../ui/Misc';

/**
 * "Which fault is this describing?" — the knowledge base, pointed at real sentences.
 *
 * Type what a technician would actually write ("the car makes a strange metallic sound when
 * braking", "الموتر يسخن", "break noise") and the ontology answers with ranked fault concepts —
 * without the exact keyword appearing anywhere in the text.
 *
 * It is not a toy. This is the honest coverage test for the library: a keyword that only ever
 * matches its own name is under-described, and the empty result tells you which one to enrich next.
 * Every match shows WHICH term fired and HOW (exact / phrase / tokens / fuzzy), so a wrong answer
 * points straight at the bad term rather than at an opaque score.
 */

const HOW_TONE = { exact: 'green', phrase: 'blue', tokens: 'violet', fuzzy: 'amber' };

// Each explanation bullet says where its evidence came from, and is styled by that — a measured
// fleet observation must never look like an unsourced model claim.
const REASON_STYLE = {
  lexical:    { dot: 'bg-slate-400',   text: 'text-slate-600' },
  evidence:   { dot: 'bg-blue-500',    text: 'text-blue-800' },
  fleet:      { dot: 'bg-emerald-500', text: 'text-emerald-800 font-medium' },
  graph:      { dot: 'bg-violet-500',  text: 'text-violet-800' },
  curated:    { dot: 'bg-indigo-500',  text: 'text-indigo-800' },
  ungrounded: { dot: 'bg-amber-500',   text: 'text-amber-800' },
};

export default function KeywordMatchTester() {
  const { t, lang } = useI18n();
  const toast = useToast();
  const [text, setText] = useState('');
  const [result, setResult] = useState(null);
  const [loading, setLoading] = useState(false);
  const [judged, setJudged] = useState({});   // keyword id → 'correct' | 'wrong'

  const run = async (e) => {
    e?.preventDefault();
    if (!text.trim()) return;
    setLoading(true);
    setJudged({});
    try {
      const { data } = await api.post('/finding-keywords/resolve', { text });
      setResult(data.data);
    } catch (err) {
      setResult({ matches: [], error: err.response?.data?.message || t('keywordAi.matchError') });
    } finally {
      setLoading(false);
    }
  };

  /**
   * Tell the engine whether it got this right. This is the continuous-learning loop's only source
   * of ground truth — a rejection is fed into the next enrichment of that fault as an instruction
   * to tighten its vocabulary, so the same wrong match stops happening.
   */
  const judge = async (keywordId, score, correct) => {
    setJudged((j) => ({ ...j, [keywordId]: correct ? 'correct' : 'wrong' }));
    try {
      const { data } = await api.post('/finding-keywords/match-feedback', {
        text: result.query, keyword_id: keywordId, correct, score, context: 'match_tester',
      });
      toast.success(data.message);
    } catch (err) {
      setJudged((j) => ({ ...j, [keywordId]: undefined }));
      toast.error(err.response?.data?.message || t('keywordAi.feedbackError'));
    }
  };

  const examples = t('keywordAi.examples').split('|');

  return (
    <Card className="p-5">
      <div className="mb-3">
        <h3 className="font-display text-sm font-semibold text-slate-900">{t('keywordAi.testerTitle')}</h3>
        <p className="mt-0.5 text-xs text-slate-500">{t('keywordAi.testerHint')}</p>
      </div>

      <form onSubmit={run} className="flex flex-col gap-2 sm:flex-row">
        <input
          type="text"
          dir="auto"
          value={text}
          onChange={(e) => setText(e.target.value)}
          placeholder={t('keywordAi.testerPlaceholder')}
          className="flex-1 rounded-xl border-0 bg-white px-4 py-2.5 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-indigo-500"
        />
        <Button type="submit" loading={loading} disabled={!text.trim()}>{t('keywordAi.match')}</Button>
      </form>

      {/* One-tap examples, so the first thing a new admin does is see it work. */}
      {!result && (
        <div className="mt-2 flex flex-wrap gap-1.5">
          {examples.map((ex) => (
            <button
              key={ex}
              type="button"
              onClick={() => setText(ex)}
              dir="auto"
              className="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 transition hover:bg-indigo-50 hover:text-indigo-700"
            >
              {ex}
            </button>
          ))}
        </div>
      )}

      {result && (
        <div className="mt-4 space-y-2">
          {/* Show the comparison key too — it's how you spot a normalisation surprise. */}
          <p className="text-[11px] text-slate-400">
            {t('keywordAi.normalizedAs')} <span className="font-mono">{result.normalized}</span>
          </p>

          {result.matches?.length === 0 ? (
            <p className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-500">{t('keywordAi.noMatch')}</p>
          ) : (
            result.matches.map((m) => {
              const kw = m.keyword;
              const name = lang === 'ar' ? kw.keyword_ar || kw.keyword : kw.keyword;
              return (
                <div key={kw.id} className="rounded-xl bg-white p-3 ring-1 ring-inset ring-slate-200">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium text-slate-900" dir="auto">{name}</span>
                    <Badge tone={kw.risk_tone || 'amber'} dot>{kw.risk_label}</Badge>
                    <Badge tone={m.confidence === 'strong' ? 'green' : 'slate'}>
                      {t(`keywordAi.${m.confidence}`)} · {m.score}
                    </Badge>
                    <span className="ms-auto text-xs text-slate-400">
                      {lang === 'ar' ? kw.category_label_ar || kw.category_label : kw.category_label}
                    </span>
                  </div>

                  {/* The "why": the terms that fired and the rule that matched them. */}
                  <div className="mt-2 flex flex-wrap gap-1.5">
                    {m.matches.map((hit, i) => (
                      <span
                        key={`${hit.term}-${i}`}
                        dir="auto"
                        className="inline-flex items-center gap-1.5 rounded-full bg-slate-50 px-2 py-0.5 text-[11px] text-slate-600 ring-1 ring-inset ring-slate-200"
                      >
                        <span className={`h-1.5 w-1.5 rounded-full ${
                          HOW_TONE[hit.how] === 'green' ? 'bg-emerald-500'
                            : HOW_TONE[hit.how] === 'blue' ? 'bg-blue-500'
                            : HOW_TONE[hit.how] === 'violet' ? 'bg-violet-500' : 'bg-amber-500'
                        }`} />
                        {hit.term}
                        <span className="text-slate-400">{t(`keywordAi.how.${hit.how}`)}</span>
                      </span>
                    ))}
                  </div>

                  {/* Full reasoning: documentation, fleet history, graph context — each labelled
                      by where it came from, and grounded vs not stated plainly. */}
                  {m.explanation?.reasons?.length > 0 && (
                    <div className="mt-3 space-y-1 border-t border-slate-100 pt-2">
                      {m.explanation.reasons
                        .filter((r) => r.kind !== 'lexical')   // already shown as chips above
                        .map((r, i) => {
                          const style = REASON_STYLE[r.kind] || REASON_STYLE.lexical;
                          return (
                            <div key={i} className="flex items-start gap-2 text-xs">
                              <span className={`mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full ${style.dot}`} />
                              <span className={style.text}>
                                {r.text}
                                {r.meta?.url && (
                                  <a href={r.meta.url} target="_blank" rel="noopener noreferrer" className="ms-1 underline">
                                    {t('keywordAi.viewSource')}
                                  </a>
                                )}
                              </span>
                            </div>
                          );
                        })}
                    </div>
                  )}

                  {/* Was this right? The only ground truth the learning loop ever gets. */}
                  <div className="mt-3 flex items-center gap-2 border-t border-slate-100 pt-2">
                    {judged[kw.id] ? (
                      <span className="text-xs text-slate-500">
                        {judged[kw.id] === 'correct' ? t('keywordAi.thanksCorrect') : t('keywordAi.thanksWrong')}
                      </span>
                    ) : (
                      <>
                        <span className="text-xs text-slate-400">{t('keywordAi.wasThisRight')}</span>
                        <button
                          type="button"
                          onClick={() => judge(kw.id, m.score, true)}
                          className="rounded-full px-2 py-0.5 text-xs font-medium text-emerald-700 transition hover:bg-emerald-50"
                        >
                          {t('keywordAi.yes')}
                        </button>
                        <button
                          type="button"
                          onClick={() => judge(kw.id, m.score, false)}
                          className="rounded-full px-2 py-0.5 text-xs font-medium text-red-600 transition hover:bg-red-50"
                        >
                          {t('keywordAi.no')}
                        </button>
                      </>
                    )}
                    {typeof m.explanation?.grounding === 'number' && (
                      <span className="ms-auto text-[11px] text-slate-400" title={t('keywordAi.groundingHint')}>
                        {t('keywordAi.grounding')} {m.explanation.grounding}%
                      </span>
                    )}
                  </div>
                </div>
              );
            })
          )}
        </div>
      )}
    </Card>
  );
}
