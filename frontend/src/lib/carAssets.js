// Car photography and marque logos for the Fleet Registry.
//
// The photographs are the hero shot the public rental catalogue leads with for each model —
// consistent cut-out studio views, imported by `php artisan cars:sync-photos`, which also writes
// the manifest below. Nothing here is hand-curated: re-run the command and both the pictures and
// the mapping move with the catalogue. The marque logos are the official marks, in /public/brands.
//
// READING THE FLEET'S OWN NAMING
// Make and model arrive from the OfficeManager sheet however they were typed: "NISSAN"/"PATROL",
// "Ford"/"MUSTANG Conv", and a long tail of rows where the model landed in the make column
// ("PATROL"/"QX80 BLACK HAWK", "SPORTAGE"/"/ M"). Nothing here repairs that data — it only reads
// it. Both fields fold into one normalised key, matched exactly as the import command normalised
// the same two columns, so a model found in the wrong column still resolves.
//
// A MISS IS NORMAL AND MUST STAY SILENT. Around a third of the fleet is an economy model the
// catalogue does not carry. Those rows fall back to the marque logo, and failing that to a neutral
// silhouette — never to a different car. A picture is a claim about which vehicle this row is; a
// guessed one is a lie that reads as a fact.

import MANIFEST from './carPhotoManifest.json';

/** Marque keyword → logo file. Longest first, so "land rover" beats a bare "rover". */
const BRAND_LOGOS = [
  ['mercedes benz', 'mercedes'],
  ['rolls royce', 'rolls-royce'],
  ['land rover', 'land-rover'],
  ['range rover', 'land-rover'],
  ['lamborghini', 'lamborghini'],
  ['mitsubishi', null],
  ['volkswagen', null],
  ['chevrolet', 'chevrolet'],
  ['mercedes', 'mercedes'],
  ['infiniti', 'infiniti'],
  ['infinity', 'infiniti'],
  ['chrysler', 'chrysler'],
  ['maserati', 'maserati'],
  ['cadillac', 'cadillac'],
  ['corvette', 'corvette'],
  ['hyundai', 'hyundai'],
  ['mclaren', 'mclaren'],
  ['porsche', 'porsche'],
  ['bentley', 'bentley'],
  ['ferrari', 'ferrari'],
  ['citroen', 'citroen.svg'],
  ['lincoln', null],
  ['peugeot', null],
  ['renault', null],
  ['jaguar', 'jaguar'],
  ['nissan', 'nissan'],
  ['toyota', null],
  ['suzuki', null],
  ['lexus', 'lexus'],
  ['dodge', 'dodge'],
  ['honda', null],
  ['mazda', 'mazda'],
  ['tesla', null],
  ['range', 'land-rover'],
  ['rover', 'land-rover'],
  ['jeep', 'jeep'],
  ['audi', 'audi'],
  ['ford', 'ford'],
  ['mini', 'mini'],
  ['bmw', 'bmw'],
  ['gmc', 'gmc'],
  ['kia', 'kia'],
];

/**
 * Which marque a bare model name belongs to.
 *
 * The economy half of the fleet is filed under its model alone — the sheet's make column literally
 * reads "COROLLA", "SPORTAGE", "PICANTO". Those rows carry no marque word at all, so without this
 * they would lose their badge as well as their photograph and land on a blank silhouette. This is a
 * lookup of things that are true about the world (a Cerato is a Kia), not a guess about the data.
 */
const MODEL_MARQUE = [
  ['grand cherokee', 'jeep'], ['cherokee', 'jeep'], ['compass', 'jeep'], ['wrangler', 'jeep'],
  ['santafe', 'hyundai'], ['santa fe', 'hyundai'], ['elantra', 'hyundai'], ['accent', 'hyundai'],
  ['tucson', 'hyundai'], ['sonata', 'hyundai'], ['creta', 'hyundai'], ['h1', 'hyundai'],
  ['sportage', 'kia'], ['cerato', 'kia'], ['picanto', 'kia'], ['optima', 'kia'],
  ['sorento', 'kia'], ['seltos', 'kia'], ['carnival', 'kia'], ['soul', 'kia'], ['rio', 'kia'],
  ['corolla', 'toyota'], ['yaris', 'toyota'], ['camry', 'toyota'], ['rav4', 'toyota'],
  ['land cruiser', 'toyota'], ['hilux', 'toyota'], ['prado', 'toyota'],
  ['patrol', 'nissan'], ['sunny', 'nissan'], ['altima', 'nissan'], ['sentra', 'nissan'],
  ['tiida', 'nissan'], ['x trail', 'nissan'], ['kicks', 'nissan'], ['pathfinder', 'nissan'],
  ['qx80', 'infiniti'], ['qx60', 'infiniti'], ['qx50', 'infiniti'],
  ['lancer', 'mitsubishi'], ['pajero', 'mitsubishi'], ['attrage', 'mitsubishi'],
  ['accord', 'honda'], ['civic', 'honda'], ['crv', 'honda'], ['cr v', 'honda'],
  ['mustang', 'ford'], ['explorer', 'ford'], ['bronco', 'ford'], ['edge', 'ford'],
  ['camaro', 'chevrolet'], ['malibu', 'chevrolet'], ['impala', 'chevrolet'],
  ['traverse', 'chevrolet'], ['equinox', 'chevrolet'], ['tahoe', 'chevrolet'],
  ['charger', 'dodge'], ['challenger', 'dodge'], ['durango', 'dodge'], ['journey', 'dodge'],
  ['yukon', 'gmc'], ['corsair', 'lincoln'], ['levante', 'maserati'], ['ghibli', 'maserati'],
];

/** Words that identify no model and so must never carry a match on their own. */
const NOISE = ['rent', 'rental', 'dubai', 'car', 'cars', 'new', 'old', 'version', 'faster'];

/** The sheet's unambiguous abbreviations, spelled out. Mirrors SyncCarPhotos::SYNONYMS. */
const SYNONYMS = { conv: 'convertible', coup: 'coupe', cabrio: 'convertible' };

/**
 * The words worth matching on, identically to SyncCarPhotos::tokens() in the backend — the manifest
 * keys were built by that function, so any drift here silently empties the photo column.
 *
 * Drops the sheet's trailing grade suffix ("RIO / M", "CERATO / N"), punctuation, the noise words
 * above, and four-digit years.
 */
function tokens(s) {
  return String(s || '')
    .toLowerCase()
    .replace(/\s*\/\s*[a-z]\b.*$/, ' ')
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()
    .split(' ')
    .map((w) => SYNONYMS[w] || w)
    .filter((w, i, all) =>
      w !== '' && all.indexOf(w) === i && !NOISE.includes(w) && !/^(19|20)\d{2}$/.test(w));
}

const keyOf = (make, model) => tokens(`${make || ''} ${model || ''}`).join(' ');
const hayOf = (make, model) => keyOf(make, model);

/** The photograph for this car, or null when the catalogue carries no such model. Never guesses. */
export function carPhoto(make, model) {
  const slug = MANIFEST[keyOf(make, model)];
  return slug ? `/cars/${slug}.webp` : null;
}

/**
 * The marque logo for this car, or null.
 *
 * Tried on the marque words first, then — for the rows filed under a bare model name — on what that
 * model is known to be. Also used on its own by the composition chart, which only ever has a make.
 */
export function brandLogo(make, model = '') {
  const hay = hayOf(make, model);
  if (!hay) return null;

  let hit = BRAND_LOGOS.find(([kw]) => hay.includes(kw));
  if (!hit) {
    const marque = MODEL_MARQUE.find(([kw]) => hay.includes(kw));
    if (marque) hit = BRAND_LOGOS.find(([kw]) => kw === marque[1]);
  }
  if (!hit || !hit[1]) return null;

  // Most marks are the catalogue's .webp artwork; the few we had to draw ship as .svg and carry
  // their own extension in the table.
  return `/brands/${hit[1].includes('.') ? hit[1] : `${hit[1]}.webp`}`;
}

/**
 * The one-line name a person would use for this car. Where the sheet put the model in the make
 * column, `make` and `model` repeat each other — printing both would read "PATROL PATROL".
 */
export function vehicleName(make, model) {
  const a = String(make || '').trim();
  const b = String(model || '').trim();
  if (!a) return b || '—';
  if (!b) return a;
  const na = tokens(a).join(' ');
  const nb = tokens(b).join(' ');
  if (!nb) return a;
  if (nb.startsWith(na) || na === nb) return b;
  return `${a} ${b}`;
}

/** The initial shown in place of a logo for a marque we hold no mark for. */
export function brandInitial(make, model) {
  const hay = hayOf(make, model);
  return hay ? hay[0].toUpperCase() : '?';
}
