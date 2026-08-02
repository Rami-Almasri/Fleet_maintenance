import { useState } from 'react';
import FaultKnowledgeCard from '../knowledge/FaultKnowledgeCard';
import api from '../../api/client';
import { useToast } from '../ui/Toast';
import { useI18n } from '../../i18n/I18nContext';

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

// The term-match and reason styling now lives in [[FaultKnowledgeCard]], which renders the card body
// for this page and for the maintenance workflow alike.

export default function KeywordMatchTester() {
  const { t } = useI18n();
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
              return (
                <div key={kw.id} className="rounded-xl bg-white p-3 ring-1 ring-inset ring-slate-200">
                  {/* The card itself is SHARED with the maintenance workflow ([[FaultKnowledgeCard]]).
                      Two copies of this markup would drift, and the day they did, the fault a
                      supervisor reads on a ticket would describe itself differently from the one an
                      admin reads while curating it. What stays here is the part that is only true of
                      this page: a human judging a match they just typed. */}
                  <FaultKnowledgeCard
                    className="!p-0 !ring-0"
                    data={{
                      keyword: kw.keyword, keyword_ar: kw.keyword_ar,
                      category_label: kw.category_label, category_label_ar: kw.category_label_ar,
                      risk_label: kw.risk_label, risk_tone: kw.risk_tone,
                      score: m.score, confidence: m.confidence,
                      matches: m.matches, explanation: m.explanation,
                    }}
                  />

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
