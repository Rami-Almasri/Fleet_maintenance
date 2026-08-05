// WHAT WE KNOW ABOUT THIS FAULT — the ontology's card for one matched concept.
//
// Extracted from KeywordMatchTester so the keyword-admin page and the maintenance workflow render the
// SAME card from the SAME payload. Two implementations of this would drift, and the moment they did,
// the fault a supervisor reads in a ticket would describe itself differently from the one an admin
// reads while curating it.
//
// WHAT IT SHOWS, and nothing else:
//   · the fault, its risk, how strongly the text matched it, and its category
//   · the wording that actually fired, and how each term matched
//   · where that wording came from
//   · what usually causes it and what usually fixes it
//   · whether any of it is sourced from documentation
//
// TWO AUDIENCES, ONE CARD, ONE SWITCH. An admin curating the vocabulary needs the matching layer —
// the score, the terms that fired and how, and where that wording came from — because that layer IS
// the thing they are editing. A supervisor deciding where to send a car does not: they are reading
// the card to learn what the fault usually is, and "engine nois · wording · 100" is the machinery
// showing through ([[operational-language-over-engine-vocabulary]]). `matching={false}` drops the
// score, the term chips and the two reason lines that describe the wording, and keeps what the fault
// IS — its risk, what the fleet has seen alongside it, what usually causes it, what usually fixes it,
// and the caveat about how little of that is documented.
//
// WHAT IT DOES NOT SHOW. No repair history, no cost, no garage, no recommendation — those are claims
// about what HAPPENED, and they belong to the cohort panel that owns them. This card is only ever a
// description of the fault itself, which is why it stays useful on a fault the fleet has never
// repaired. The Yes/No feedback row is likewise not here: it belongs where a human is judging a match
// they just typed, not where one is reading a ticket.

import { useI18n } from '../../i18n/I18nContext';

// Each explanation line says where its evidence came from and is styled by that — a measured fleet
// observation must never look like an unsourced model claim.
const REASON_STYLE = {
  evidence:   { dot: 'bg-blue-500',    text: 'text-blue-800' },
  fleet:      { dot: 'bg-emerald-500', text: 'text-emerald-800 font-medium' },
  graph:      { dot: 'bg-violet-500',  text: 'text-violet-800' },
  curated:    { dot: 'bg-indigo-500',  text: 'text-indigo-800' },
  provenance: { dot: 'bg-sky-500',     text: 'text-sky-800' },
  ungrounded: { dot: 'bg-amber-500',   text: 'text-amber-800' },
  lexical:    { dot: 'bg-slate-400',   text: 'text-slate-600' },
};

const HOW_DOT = {
  exact:  'bg-emerald-500',
  phrase: 'bg-blue-500',
  token:  'bg-violet-500',
  tokens: 'bg-violet-500',
  fuzzy:  'bg-amber-500',
  alias:  'bg-slate-400',
};

const RISK_TONE = {
  red:   'bg-red-50 text-red-700 ring-red-600/20',
  amber: 'bg-amber-50 text-amber-800 ring-amber-600/20',
  green: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
};

export default function FaultKnowledgeCard({ data, className = '', matching = true }) {
  const { t, lang } = useI18n();

  if (!data?.keyword) return null;

  const name     = lang === 'ar' ? data.keyword_ar || data.keyword : data.keyword;
  const category = lang === 'ar' ? data.category_label_ar || data.category_label : data.category_label;
  const strong   = data.confidence === 'strong';

  // 'lexical' is dropped always: it restates the term chips directly above it in prose.
  //
  // 'provenance' ("wording on this fault comes from — 28 from the curated fault ontology") and
  // 'curated' ("4 term(s) were written by your staff") go with the chips when matching is hidden.
  // Both are statements ABOUT the wording, and a sentence explaining where terms came from, printed
  // on a card that no longer shows the terms, is an answer to a question nobody can see being asked.
  const HIDDEN_WITHOUT_MATCHING = ['provenance', 'curated'];
  const reasons = (data.explanation?.reasons || [])
    .filter((r) => r.kind !== 'lexical')
    .filter((r) => matching || !HIDDEN_WITHOUT_MATCHING.includes(r.kind));

  return (
    <div className={`rounded-xl bg-white p-3 ring-1 ring-inset ring-slate-200 ${className}`}>
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm font-semibold text-slate-900" dir="auto">{name}</span>

        {data.risk_label && (
          <span className={`rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ${
            RISK_TONE[data.risk_tone] || RISK_TONE.amber}`}
          >
            {data.risk_label}
          </span>
        )}

        {/* "Strong match · 100" is a grade on the MATCHER, not on the fault. It belongs where someone
            is judging the matcher; on a ticket it invites a supervisor to read a confidence they have
            no scale for and cannot act on. */}
        {matching && (
          <span className={`rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ${
            strong ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-slate-100 text-slate-600 ring-slate-300'}`}
          >
            {t(`keywordAi.${data.confidence}`)} · {data.score}
          </span>
        )}

        {category && <span className="ms-auto text-xs text-slate-400">{category}</span>}
      </div>

      {/* The wording that actually fired, and how. A wrong answer points at the bad term rather than
          at an opaque score — which is why this stays for the admin who can fix the term, and goes
          for the supervisor who cannot. */}
      {matching && data.matches?.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-1.5">
          {data.matches.map((hit, i) => (
            <span
              key={`${hit.term}-${i}`}
              dir="auto"
              className="inline-flex items-center gap-1.5 rounded-full bg-slate-50 px-2 py-0.5 text-[11px] text-slate-600 ring-1 ring-inset ring-slate-200"
            >
              <span className={`h-1.5 w-1.5 rounded-full ${HOW_DOT[hit.how] || 'bg-slate-400'}`} />
              {hit.term}
              <span className="text-slate-400">{t(`keywordAi.how.${hit.how}`)}</span>
            </span>
          ))}
        </div>
      )}

      {/* Provenance, usual causes, usual repairs, and the caveat when nothing has been cited. Each
          line carries its own origin colour, so curated wording never reads as documented fact. */}
      {reasons.length > 0 && (
        <div className="mt-3 space-y-1 border-t border-slate-100 pt-2">
          {reasons.map((r, i) => {
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
    </div>
  );
}
