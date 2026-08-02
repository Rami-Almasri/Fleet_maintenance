// "What fault is this?" — the knowledge base, answering inside the inspector's own search box.
//
// WHY THIS IS NOT A SECOND SEARCH BOX. FindingsPicker's search is a substring filter over the catalog:
// it is fast, predictable, and it fails completely the moment someone writes the way a technician
// actually writes. "الموتر يسخن" is not a substring of "Overheating"; neither is "engine shaking" a
// substring of "Engine vibration". Those are exactly the sentences worth capturing, and the old
// behaviour was to show "no issue matches" and let the inspector invent a custom tag — which lands
// outside the vocabulary and outside every analytic built on it.
//
// So this mounts UNDER the existing box and only speaks when the literal search has nothing: one
// input, two strategies, the cheap one first. It never becomes a competing place to type.
//
// RULES IT ENFORCES
//   · NEVER auto-selects. A match is a suggestion with an Add button; findings feed severity, garage
//     routing and the recommendation engine, and an unconfirmed guess must not enter that chain.
//   · Confidence changes the CLAIM, not just a number. A strong match is offered as an answer; a weak
//     one is offered as a question ("did you mean…?"). The inspector should be able to tell those
//     apart without reading a score.
//   · A fault the ontology understands but the catalog does not offer (`selectable: false` — a garage
//     diagnosis like "Water pump failure") is shown WITHOUT an Add button and said out loud, rather
//     than rendered as a dead chip. See `understanding_only` in config/maintenance_findings.php.
//   · The Yes/No verdict is the only ground truth this system ever gets about its own retrieval, and
//     it is filed with the ticket and car it was given on (`context: test_findings`) so field
//     observations are never averaged with admin experiments from the keyword page.
//
// Detail (provenance, grounding, which term fired) sits behind "Why?" — this renders on a phone, in a
// yard, held by the one person in the workflow whose time is genuinely scarce.

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import api from '../../api/client';
import Icon from '../ui/Icon';
import { useI18n } from '../../i18n/I18nContext';

// Below this the engine's own answer is treated as a question rather than a statement. Mirrors
// config('knowledge_platform.matching.strong') — the backend already grades each match 'strong' or
// 'possible', and this only decides the wording when that grade is missing.
const STRONG_SCORE = 70;

// Long enough that a fault name isn't re-queried on every keystroke, short enough to feel immediate.
const DEBOUNCE_MS = 450;

// Two words is where "brak" stops being a prefix and starts being an attempt at a sentence.
const MIN_QUERY = 3;

export default function FindingsAiSuggestion({
  query,
  onAdd,
  isSelected,
  isLocked,
  ticketId = null,
  vehicleId = null,
  context = 'test_findings',
}) {
  const { t, lang } = useI18n();
  const [result, setResult]   = useState(null);
  const [loading, setLoading] = useState(false);
  const [failed, setFailed]   = useState(false);
  const [judged, setJudged]   = useState({});   // keyword id → 'correct' | 'wrong'
  const [why, setWhy]         = useState({});   // keyword id → bool

  // Guards against the classic debounce race: a slow answer for "eng" must never overwrite a fast
  // answer for "engine shaking". Only the newest request may write state.
  const requestRef = useRef(0);

  const trimmed = query.trim();

  useEffect(() => {
    if (trimmed.length < MIN_QUERY) {
      setResult(null);
      setFailed(false);
      return undefined;
    }

    const seq = ++requestRef.current;
    const timer = setTimeout(async () => {
      setLoading(true);
      setFailed(false);
      try {
        const { data } = await api.post('/finding-keywords/resolve', { text: trimmed, limit: 3 });
        if (seq === requestRef.current) {
          setResult(data.data);
          setJudged({});
          setWhy({});
        }
      } catch {
        // A failed lookup must never block the picker — the category list and the custom-issue box
        // both still work, so this degrades to exactly the old behaviour.
        if (seq === requestRef.current) { setResult(null); setFailed(true); }
      } finally {
        if (seq === requestRef.current) setLoading(false);
      }
    }, DEBOUNCE_MS);

    return () => clearTimeout(timer);
  }, [trimmed]);

  const judge = useCallback(async (match, correct) => {
    const id = match.keyword.id;
    setJudged((j) => ({ ...j, [id]: correct ? 'correct' : 'wrong' }));
    try {
      await api.post('/finding-keywords/match-feedback', {
        text: result.query,
        keyword_id: id,
        correct,
        score: match.score,
        context,
        maintenance_id: ticketId,
        vehicle_id: vehicleId,
      });
    } catch {
      setJudged((j) => ({ ...j, [id]: undefined }));   // let them try again
    }
  }, [result, context, ticketId, vehicleId]);

  const matches = useMemo(() => (result?.matches || []).filter(Boolean), [result]);

  if (trimmed.length < MIN_QUERY) return null;

  if (loading) {
    return (
      <p className="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500 ring-1 ring-inset ring-slate-200">
        <Icon.Spark className="h-3.5 w-3.5 animate-pulse text-indigo-500" />
        {t('findingsAi.thinking')}
      </p>
    );
  }

  if (failed || matches.length === 0) {
    return (
      <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500 ring-1 ring-inset ring-slate-200">
        {failed ? t('findingsAi.error') : t('findingsAi.noIdea', { query: trimmed })}
      </p>
    );
  }

  const [top, ...alternates] = matches;
  const strong = top.confidence === 'strong' || top.score >= STRONG_SCORE;

  return (
    <div className="rounded-xl bg-indigo-50/60 p-3 ring-1 ring-inset ring-indigo-200">
      <p className="mb-2 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-indigo-700">
        <Icon.Spark className="h-3 w-3" />
        {/* The header states how much to trust what follows, before it is read. */}
        {strong ? t('findingsAi.suggestedTitle') : t('findingsAi.unsureTitle')}
      </p>

      <MatchCard
        match={top}
        primary
        t={t}
        lang={lang}
        onAdd={onAdd}
        isSelected={isSelected}
        isLocked={isLocked}
        judged={judged[top.keyword.id]}
        onJudge={judge}
        showWhy={!!why[top.keyword.id]}
        onToggleWhy={() => setWhy((w) => ({ ...w, [top.keyword.id]: !w[top.keyword.id] }))}
      />

      {/* Runners-up stay collapsed to one line each. The inspector who disagrees with the top answer
          usually knows what they meant, and a second guess is cheaper than reopening the accordions. */}
      {alternates.length > 0 && (
        <div className="mt-2 border-t border-indigo-200/70 pt-2">
          <p className="mb-1.5 text-[11px] text-slate-500">{t('findingsAi.orDidYouMean')}</p>
          <div className="flex flex-wrap gap-1.5">
            {alternates.map((m) => {
              const name = lang === 'ar' ? m.keyword.keyword_ar || m.keyword.keyword : m.keyword.keyword;
              const taken = isSelected(m.keyword.keyword) || isLocked(m.keyword.keyword);
              if (!m.selectable) {
                return (
                  <span
                    key={m.keyword.id}
                    title={t('findingsAi.notSelectableHint')}
                    className="inline-flex cursor-not-allowed items-center gap-1 rounded-full bg-white/70 px-2.5 py-1 text-xs text-slate-400 ring-1 ring-inset ring-slate-200"
                  >
                    {name}
                  </span>
                );
              }
              return (
                <button
                  key={m.keyword.id}
                  type="button"
                  disabled={taken}
                  onClick={() => onAdd(m.keyword.keyword)}
                  className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset transition ${
                    taken
                      ? 'cursor-not-allowed bg-slate-100 text-slate-400 ring-slate-200'
                      : 'bg-white text-indigo-700 ring-indigo-300 hover:bg-indigo-100'
                  }`}
                >
                  {taken ? <Icon.Check className="h-3 w-3" /> : <Icon.Plus className="h-3 w-3" />} {name}
                </button>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────────────────────────────

const RISK_DOT = { red: 'bg-red-500', amber: 'bg-amber-500', green: 'bg-emerald-500' };

function MatchCard({ match, t, lang, onAdd, isSelected, isLocked, judged, onJudge, showWhy, onToggleWhy }) {
  const kw    = match.keyword;
  const name  = lang === 'ar' ? kw.keyword_ar || kw.keyword : kw.keyword;
  const taken = isSelected(kw.keyword) || isLocked(kw.keyword);

  // What actually fired, in the inspector's words rather than the engine's — one term, not the list.
  const firedTerm = match.matches?.[0]?.term;

  return (
    <div className="rounded-lg bg-white p-2.5 ring-1 ring-inset ring-slate-200">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm font-semibold text-slate-900" dir="auto">{name}</span>
        <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">
          <span className={`h-1.5 w-1.5 rounded-full ${RISK_DOT[kw.risk_tone] || 'bg-slate-400'}`} />
          {kw.risk_label}
        </span>
        <span className="text-[11px] text-slate-400">
          {lang === 'ar' ? kw.category_label_ar || kw.category_label : kw.category_label}
        </span>
      </div>

      {firedTerm && (
        <p className="mt-1 text-[11px] text-slate-500" dir="auto">
          {t('findingsAi.matchedFrom')} <span className="font-medium text-slate-600">“{firedTerm}”</span>
        </p>
      )}

      {/* The two lines a technician wants next to a fault name. Skipped entirely when the concept has
          neither — an empty "usually caused by:" reads as missing data rather than absent data. */}
      {match.causes?.length > 0 && (
        <p className="mt-1.5 text-[11px] text-slate-600">
          <span className="text-slate-400">{t('findingsAi.usuallyCausedBy')}</span> {match.causes.join(' · ')}
        </p>
      )}
      {match.fixes?.length > 0 && (
        <p className="mt-0.5 text-[11px] text-slate-600">
          <span className="text-slate-400">{t('findingsAi.usuallyFixedBy')}</span>{' '}
          {match.fixes.map((f) => (lang === 'ar' ? f.label_ar || f.label : f.label)).join(' · ')}
        </p>
      )}

      <div className="mt-2.5 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-2">
        {/* THE CONFIRMATION STEP. Nothing above this line has changed the ticket. */}
        {match.selectable ? (
          <button
            type="button"
            disabled={taken}
            onClick={() => onAdd(kw.keyword)}
            className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition ${
              taken
                ? 'cursor-not-allowed bg-slate-100 text-slate-400'
                : 'bg-indigo-600 text-white hover:bg-indigo-700'
            }`}
          >
            {taken ? <Icon.Check className="h-3.5 w-3.5" /> : <Icon.Plus className="h-3.5 w-3.5" />}
            {taken ? t('findingsAi.alreadyAdded') : t('findingsAi.addAsFinding')}
          </button>
        ) : (
          // Understood, deliberately not offerable. Saying so is more honest than hiding the match:
          // the inspector learns the system knows the fault, and that this is not where it gets logged.
          <span className="inline-flex items-start gap-1.5 text-[11px] text-slate-500">
            <Icon.Info className="mt-px h-3.5 w-3.5 shrink-0 text-slate-400" />
            {t('findingsAi.notSelectable')}
          </span>
        )}

        <button
          type="button"
          onClick={onToggleWhy}
          className="ms-auto inline-flex items-center gap-1 text-[11px] font-medium text-slate-500 transition hover:text-indigo-600"
        >
          {t('findingsAi.why')}
          <Icon.ChevronDown className={`h-3 w-3 transition ${showWhy ? 'rotate-180' : ''}`} />
        </button>
      </div>

      {showWhy && (
        <div className="mt-2 space-y-1 border-t border-slate-100 pt-2">
          {(match.matches || []).map((hit, i) => (
            <p key={`${hit.term}-${i}`} className="text-[11px] text-slate-500" dir="auto">
              · “{hit.term}” — {t(`keywordAi.how.${hit.how}`)}
            </p>
          ))}
          {(match.explanation?.reasons || [])
            .filter((r) => r.kind !== 'lexical')
            .map((r, i) => (
              <p key={`r-${i}`} className="text-[11px] text-slate-500">· {r.text}</p>
            ))}
          <p className="pt-0.5 text-[11px] text-slate-400">
            {t('findingsAi.score', { score: match.score })}
          </p>
        </div>
      )}

      {/* The learning loop. Deliberately answerable whether or not the fault was added — "right fault,
          but not what I'm logging" is a confirmation, and "wrong fault" is the rarer, better signal. */}
      <div className="mt-2 flex items-center gap-2 border-t border-slate-100 pt-2">
        {judged ? (
          <span className="text-[11px] text-slate-500">
            {judged === 'correct' ? t('findingsAi.thanksCorrect') : t('findingsAi.thanksWrong')}
          </span>
        ) : (
          <>
            <span className="text-[11px] text-slate-400">{t('findingsAi.wasThisRight')}</span>
            <button
              type="button"
              onClick={() => onJudge(match, true)}
              className="rounded-full px-2 py-0.5 text-[11px] font-semibold text-emerald-700 transition hover:bg-emerald-50"
            >
              {t('findingsAi.yes')}
            </button>
            <button
              type="button"
              onClick={() => onJudge(match, false)}
              className="rounded-full px-2 py-0.5 text-[11px] font-semibold text-red-600 transition hover:bg-red-50"
            >
              {t('findingsAi.no')}
            </button>
          </>
        )}
      </div>
    </div>
  );
}
