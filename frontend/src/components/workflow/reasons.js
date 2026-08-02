// Rendering the recommendation engine's reasons in the reader's language.
//
// The engine no longer sends sentences. Every reason arrives as { code, params, text } — the code names
// WHICH claim is being made, the params carry the facts, and `text` is the frozen English kept for the
// audit trail. This module is the only place that turns the first two into something a person reads;
// `text` is deliberately never displayed, because displaying it is how the Arabic UI ended up half
// English in the first place.
//
// A reason list is joined with the reader's own list grammar, not with an English "a, b and c" — Arabic
// separates with و and no comma before it, and a sentence assembled server-side can't know that.

/** Confidence levels are themselves translatable words, not data — swap them before interpolating. */
const CONFIDENCE_PARAMS = { more_evidence_than: ['a', 'b'] };

/**
 * One reason → one string in the active language.
 *
 * Unknown codes fall back to the frozen English rather than rendering a raw key. A reason the engine
 * added but the label table has not caught up with should read as a slightly-off sentence, never as
 * `workflow.garageRec.reason.some_new_code` — that failure mode is exactly what this whole change set
 * exists to remove.
 */
export function renderReason(t, reason) {
  if (!reason) return '';
  if (typeof reason === 'string') return reason;            // tolerate any not-yet-migrated producer
  const { code, params = {}, text = '' } = reason;
  if (!code) return text;

  const swap = CONFIDENCE_PARAMS[code];
  const vars = swap
    ? { ...params, ...Object.fromEntries(swap.map((k) => [k, t(`workflow.garageRec.confidence.${params[k]}`)])) }
    : params;

  const key = `workflow.garageRec.reason.${code}`;
  const out = t(key, vars);
  // t() echoes the key back when it has no entry for it.
  return out === key ? text : out;
}

/** A reason list, joined the way the active language joins lists. */
export function renderReasons(t, reasons, sep) {
  const parts = (reasons || []).map((r) => renderReason(t, r)).filter(Boolean);
  if (sep) return parts.join(sep);
  if (parts.length === 0) return '';
  if (parts.length === 1) return parts[0];
  const last = parts[parts.length - 1];
  return `${parts.slice(0, -1).join(t('workflow.garageRec.listComma'))}${t('workflow.garageRec.listAnd')}${last}`;
}

/**
 * A composed reason — a sentence whose clauses are themselves reason lists (the verdict, the
 * trade-off). The engine picks the SHAPE (`verdict_trade` vs `verdict_leads_all`) and supplies the
 * clause lists; the language supplies the sentence that frames them.
 */
export function renderComposed(t, composed) {
  if (!composed) return '';
  if (typeof composed === 'string') return composed;
  const { code, params = {}, parts = {}, text = '' } = composed;
  if (!code) return text;

  const vars = { ...params };
  Object.entries(parts).forEach(([slot, list]) => {
    vars[slot] = renderReasons(t, list);
  });

  const key = `workflow.garageRec.composed.${code}`;
  const out = t(key, vars);
  if (out === key) return text;

  // Being the ONLY credible garage is a different situation from being the best of several, and a
  // supervisor weighing an override needs to know there was nothing to override to. Only the
  // winner-reason shape sets `sole`; for every other shape the label is already a whole sentence.
  if (composed.sole === undefined) return out;
  return composed.sole ? out + t('workflow.garageRec.composed.soleSuffix') : `${out}.`;
}
