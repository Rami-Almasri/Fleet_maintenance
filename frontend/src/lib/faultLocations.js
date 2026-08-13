// The WHERE axis, client side — vocabulary helpers + the preview sentence.
//
// A fault is three facts: WHAT it is (the finding keyword), HOW MANY there are, and WHERE on the car
// they are. This module owns the client half of the last two. Everything here mirrors the backend:
//   App\Support\FaultPhrase          → renderFaultPhrase()
//   App\Services\FaultLocationService → policyFor(), normalizeQuantity(), findingsMissingLocation()
// The backend stays the authority (it re-normalises and re-gates every write); this exists so the
// inspector sees the sentence he is about to file BEFORE he files it, not after.
//
// Nothing here knows what a scratch is. Every function takes the type as a plain label, so a fault
// type added to the catalog tomorrow gets locations with no change to this file.

// Shape contract, shared by the Decide step and the garage-findings step:
//   groups  : [{ key, label, label_ar, locations: [{ key, label, label_ar, precision, ... }] }]
//   policy  : { [keyword]: 'required' | 'optional' | 'none' }
//   value   : { [symptom]: { quantity: number, locations: string[] } }

export const MODE_REQUIRED = 'required';
export const MODE_OPTIONAL = 'optional';
export const MODE_NONE = 'none';

/** Mirror of config('vehicle_locations.max_quantity'); the catalog response overrides it. */
export const DEFAULT_MAX_QUANTITY = 40;

// ── Policy ───────────────────────────────────────────────────────────────────

// Does this fault type take a place, and is it required? Unknown keywords (a custom issue the
// inspector typed) fall back to 'optional' — offer the picker, never demand an answer for something
// we could not classify. Same rule as FaultLocationService::policyFor's default.
export function policyFor(policy, keyword) {
  const mode = policy?.[keyword];
  return mode === MODE_REQUIRED || mode === MODE_NONE ? mode : MODE_OPTIONAL;
}

export const takesLocation = (policy, keyword) => policyFor(policy, keyword) !== MODE_NONE;
export const requiresLocation = (policy, keyword) => policyFor(policy, keyword) === MODE_REQUIRED;

/**
 * The symptoms that require a place and have none — the client half of the intake gate.
 * Returns every offender so the UI can name them all at once, exactly as the API does.
 */
export function findingsMissingLocation(symptoms = [], policy = {}, value = {}) {
  return symptoms.filter(
    (s) => requiresLocation(policy, s) && !(value?.[s]?.locations?.length),
  );
}

/** Ready to submit when nothing still owes a location. */
export function locationsComplete(symptoms, policy, value) {
  return findingsMissingLocation(symptoms, policy, value).length === 0;
}

// ── Vocabulary ───────────────────────────────────────────────────────────────

/** Flatten the grouped catalog into slug → { key, label, label_ar, group }. */
export function locationIndex(groups = []) {
  const out = {};
  groups.forEach((g) => {
    (g.locations || []).forEach((l) => {
      out[l.key] = { ...l, group: g.key, groupLabel: g.label, groupLabelAr: g.label_ar };
    });
  });
  return out;
}

/** One place's label in the active language, falling back to the slug so nothing renders blank. */
export function locationLabel(index, slug, lang = 'en') {
  const loc = index?.[slug];
  if (!loc) return slug;
  return lang === 'ar' && loc.label_ar ? loc.label_ar : loc.label;
}

/** Several places' labels, in the given order. */
export const locationLabels = (index, slugs = [], lang = 'en') =>
  slugs.map((s) => locationLabel(index, s, lang));

/**
 * Filter the grouped catalog by a search term (name, Arabic name, slug, alias) — the same four
 * columns VehicleLocation::scopeSearch matches, so typing "جنط" or "rim" lands on the same row here
 * as it would server-side. Groups left with nothing are dropped.
 */
export function searchGroups(groups = [], query = '') {
  const q = String(query || '').trim().toLowerCase();
  if (!q) return groups;
  const hit = (l) =>
    String(l.label || '').toLowerCase().includes(q) ||
    String(l.label_ar || '').toLowerCase().includes(q) ||
    String(l.key || '').toLowerCase().includes(q) ||
    (l.aliases || []).some((a) => String(a).toLowerCase().includes(q));
  return groups
    .map((g) => {
      const groupHit =
        String(g.label || '').toLowerCase().includes(q) ||
        String(g.label_ar || '').toLowerCase().includes(q);
      return groupHit ? g : { ...g, locations: (g.locations || []).filter(hit) };
    })
    .filter((g) => (g.locations || []).length > 0);
}

// ── The sentence ─────────────────────────────────────────────────────────────

// Mass nouns — mirrors FaultPhrase::UNCOUNTABLE. "2 rusts" is not English.
const UNCOUNTABLE = ['rust', 'corrosion', 'wear', 'paint', 'oil', 'smoke', 'odour', 'odor'];

// Irregulars the naive +s rule gets wrong. Deliberately tiny: anything not here and not a plain word
// takes the unambiguous "2 × Label" form rather than a confidently wrong plural.
const IRREGULAR = { scratch: 'scratches', crack: 'cracks', patch: 'patches', loss: 'losses', leak: 'leaks' };

function pluralize(word) {
  const lower = word.toLowerCase();
  if (IRREGULAR[lower]) return IRREGULAR[lower];
  if (/(s|x|z|ch|sh)$/i.test(word)) return `${word}es`;
  if (/[^aeiou]y$/i.test(word)) return `${word.slice(0, -1)}ies`;
  return `${word}s`;
}

/**
 * "a", "a and b", "a, b and c" — and the Arabic equivalents. Mirrors FaultPhrase::joinList.
 */
export function joinList(items = [], lang = 'en') {
  const list = items.map((i) => String(i || '').trim()).filter(Boolean);
  if (list.length === 0) return '';
  if (list.length === 1) return list[0];
  const last = list.pop();
  return `${list.join(lang === 'ar' ? '، ' : ', ')}${lang === 'ar' ? ' و' : ' and '}${last}`;
}

/**
 * The preview sentence: "2 scratches — rims and body".
 *
 * Same conservative pluralisation as the backend: a curated fault name is a human phrase, not a noun,
 * so anything with a slash or more than three words takes "2 × Label" instead of an invented plural.
 * Arabic renders count + singular (no productive plural rule exists for an arbitrary noun) and joins
 * with "و".
 */
export function renderFaultPhrase(type, quantity = 1, locations = [], lang = 'en') {
  const label = String(type || '').trim();
  const qty = Math.max(1, Number(quantity) || 1);
  const places = joinList(locations, lang);

  if (!label) return places;

  let head = label;
  if (qty > 1) {
    if (lang === 'ar') {
      head = `${qty} ${label}`;
    } else {
      const words = label.split(/\s+/);
      const last = words[words.length - 1] || '';
      const inflectable =
        !label.includes('/') &&
        words.length <= 3 &&
        !UNCOUNTABLE.includes(last.toLowerCase()) &&
        /^[\p{L}]+$/u.test(last);
      if (inflectable) {
        const out = [...words.slice(0, -1), pluralize(last)].join(' ');
        head = `${qty} ${out.charAt(0).toLowerCase()}${out.slice(1)}`;
      } else {
        head = `${qty} × ${label}`;
      }
    }
  }

  return places ? `${head} — ${places}` : head;
}

/** The preview for one row of the editor's value object. */
export function previewFor(symptom, detail, index, lang = 'en') {
  return renderFaultPhrase(
    symptom,
    detail?.quantity || 1,
    locationLabels(index, detail?.locations || [], lang),
    lang,
  );
}

// ── The API payload ──────────────────────────────────────────────────────────

/**
 * Turn the editor's value into the `details` list POST /report expects:
 *   [{ symptom, quantity, locations: [slug] }]
 *
 * Only symptoms still selected are sent, and only ones carrying something worth saying — a fault left
 * at quantity 1 with no place sends nothing, which the API reads as "this writer has nothing to add"
 * rather than as a clear instruction. Types whose policy is `none` are skipped entirely so a stale
 * selection cannot leak a location onto a fault that has no place.
 */
export function buildDetails(symptoms = [], value = {}, policy = {}) {
  return symptoms
    .filter((s) => takesLocation(policy, s))
    .map((s) => {
      const d = value?.[s] || {};
      const quantity = Math.max(1, Number(d.quantity) || 1);
      const locations = Array.isArray(d.locations) ? d.locations.filter(Boolean) : [];
      if (quantity === 1 && locations.length === 0) return null;
      return { symptom: s, quantity, locations };
    })
    .filter(Boolean);
}

/** The same two fields, merged onto each entry of a garage-findings payload. */
export function withDetails(findings = [], value = {}, policy = {}) {
  return findings.map((f) => {
    const symptom = typeof f === 'string' ? f : f.text;
    if (!takesLocation(policy, symptom)) return f;
    const d = value?.[symptom] || {};
    const quantity = Math.max(1, Number(d.quantity) || 1);
    const locations = Array.isArray(d.locations) ? d.locations.filter(Boolean) : [];
    if (quantity === 1 && locations.length === 0) return f;
    return { ...(typeof f === 'string' ? { text: f } : f), quantity, locations };
  });
}
