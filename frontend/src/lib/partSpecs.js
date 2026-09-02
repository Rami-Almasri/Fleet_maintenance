// Part specifications on the client — WHAT a part is (12V 60Ah, 5W-30, 225/65R17), as opposed to
// which part it is.
//
// THE FIELD DEFINITIONS ARE NOT DUPLICATED HERE. They are fetched once from
// GET /part-specs/dictionary, which serves the same config/part_specs.php the backend validates
// against. That is the whole design: a viscosity added in config becomes selectable in every form
// in the app without a single frontend edit, and a form can never offer an option the API will
// reject. The moment this file starts holding its own copy of the options, the two drift and the
// user finds out by having a save refused for a value the screen offered them.
//
// What DOES live here is the rendering, mirrored from App\Support\PartSpecs so a row can show its
// spec without waiting on a request. Rendering is pure formatting over values the API already
// validated, so a mirror is safe in a way a mirrored vocabulary is not.

import api from '../api/client';

/** Separator between facts in the one-line summary — must match PartSpecs::SUMMARY_GLUE. */
const GLUE = ' · ';

let cache = null;
let inflight = null;

/**
 * The dictionary: { fields: {key: definition}, by_part_type: {catalogId: {slug, fields[]}} }.
 *
 * Cached for the session and de-duplicated across concurrent callers — several panels mount at
 * once on a vehicle profile and would otherwise each fire the same request.
 */
export async function loadSpecDictionary(lang = 'en') {
  if (cache && cache.lang === lang) return cache.data;
  if (inflight) return inflight;

  inflight = api
    .get('/part-specs/dictionary', { params: { locale: lang } })
    .then((res) => {
      const data = res?.data?.data || { fields: {}, by_part_type: {} };
      cache = { lang, data };
      return data;
    })
    .catch(() => ({ fields: {}, by_part_type: {} }))
    .finally(() => {
      inflight = null;
    });

  return inflight;
}

/** Drop the cache — used when the language changes, since labels are served per locale. */
export function resetSpecDictionary() {
  cache = null;
}

/** The field definitions that apply to one part type, in dictionary order. */
export function fieldsForPartType(dictionary, catalogId) {
  const keys = dictionary?.by_part_type?.[catalogId]?.fields || [];
  return keys.map((k) => dictionary?.fields?.[k]).filter(Boolean);
}

export function partTypeHasSpecs(dictionary, catalogId) {
  return fieldsForPartType(dictionary, catalogId).length > 0;
}

/**
 * One stored value as a human reads it — '5w-30' → '5W-30', 4.5 → '4.5 L'.
 *
 * An enum value with no matching option is shown as its raw key rather than as nothing: it means an
 * option was retired after this part was recorded, and a reader can still act on '5w-30' where a
 * blank cell would hide that anything is there at all.
 */
export function renderSpecValue(field, value) {
  if (field == null || value === null || value === undefined || value === '') return '';

  if (field.kind === 'enum') {
    const hit = (field.options || []).find((o) => o.key === value);
    return hit ? hit.label : String(value);
  }

  if (field.kind === 'number') {
    // 4.50 → '4.5', 60.00 → '60'. Trailing zeros read as precision nobody measured.
    const n = Number(value);
    if (Number.isNaN(n)) return String(value);
    const text = String(Math.round(n * 100) / 100);
    return field.unit ? `${text} ${field.unit}` : text;
  }

  return String(value);
}

/**
 * THE ONE LINE — "12V · 60 Ah". Mirrors PartSpecs::summary exactly.
 *
 * Returns '' when nothing summary-worthy is recorded, so a caller can write
 * `{summary && <span>{summary}</span>}` and get no stray separator on an unspecced part.
 */
export function specSummary(dictionary, catalogId, specs) {
  if (!specs) return '';

  return fieldsForPartType(dictionary, catalogId)
    .filter((f) => f.summary && specs[f.key] !== undefined && specs[f.key] !== null)
    .map((f) => ({ order: f.summary_order ?? 999, text: renderSpecValue(f, specs[f.key]) }))
    .filter((p) => p.text)
    .sort((a, b) => a.order - b.order)
    .map((p) => p.text)
    .join(GLUE);
}

/**
 * Every field as a label/value pair, INCLUDING the ones nobody answered.
 *
 * The unanswered ones are the point: "Terminal side — not recorded" is the sentence that gets
 * someone to go and look at the battery. A caller wanting only what is known filters on `recorded`.
 */
export function specDetail(dictionary, catalogId, specs) {
  return fieldsForPartType(dictionary, catalogId).map((f) => {
    const recorded = specs != null && specs[f.key] !== undefined && specs[f.key] !== null;
    return {
      key: f.key,
      label: f.label,
      value: recorded ? renderSpecValue(f, specs[f.key]) : null,
      critical: !!f.critical,
      recorded,
    };
  });
}

/**
 * Where what is about to be fitted disagrees with what the car takes.
 *
 * Mirrors PartSpecs::conflicts: CRITICAL fields only, and only where both sides are known. Absence
 * never raises a warning — "we don't know what this car takes" is not evidence that the part is
 * wrong, and a warning raised on missing data teaches people to dismiss warnings.
 *
 * This is the client-side echo shown while typing. The authoritative answer comes from
 * POST /Vehicle/{id}/part-specs/{part}/check, which is what a save should act on.
 */
export function specConflicts(dictionary, catalogId, expected, actual) {
  if (!expected || !actual) return [];

  const fold = (v) => String(v ?? '').toLowerCase().replace(/[\s\-_/]+/g, '');

  return fieldsForPartType(dictionary, catalogId)
    .filter((f) => f.critical)
    .filter((f) => expected[f.key] != null && actual[f.key] != null)
    .filter((f) => fold(expected[f.key]) !== fold(actual[f.key]))
    .map((f) => ({
      key: f.key,
      label: f.label,
      expected: renderSpecValue(f, expected[f.key]),
      actual: renderSpecValue(f, actual[f.key]),
    }));
}

/** What this car takes, keyed by part-type slug. */
export async function loadVehicleSpecs(vehicleId, lang = 'en') {
  try {
    const res = await api.get(`/Vehicle/${vehicleId}/part-specs`, { params: { locale: lang } });
    return res?.data?.data || {};
  } catch {
    return {};
  }
}

/** A person states what this car takes. `source` is 'manual' or 'manual_book' — never 'observed'. */
export function saveVehicleSpec(vehicleId, catalogId, specs, { source = 'manual', notes = null } = {}) {
  return api.post(`/Vehicle/${vehicleId}/part-specs/${catalogId}`, { specs, source, notes });
}
