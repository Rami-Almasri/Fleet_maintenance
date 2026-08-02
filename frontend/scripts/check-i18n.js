// i18n guardrail — run with `npm run check:i18n`. Exits non-zero on any problem.
//
// The app ships English + Arabic from a single catalog (src/i18n/labels.js). Two
// namespaces keep their English in the table that defines them instead of the
// catalog (`nav` → layouts/AppLayout.js, `modules` → config/moduleRegistry.js),
// because those entries are structural and their `desc` text doubles as the
// documentation for each destination. That split is safe only if the keys stay
// in sync with the routes and section names they shadow — which is what this
// script enforces:
//
//   1. labels.js parses
//   2. every ar.nav.* key maps to a real route/section title in NAV_SECTIONS
//   3. every route/section in NAV_SECTIONS has an Arabic name (and a desc)
//   4. every ar.modules.<id> maps to a real module, and each section/menu slug
//      to a real section/menu entry in that module — and vice versa
//   5. en/ar structural parity everywhere else (Arabic-only plural categories
//      zero/two/few/many are expected and excluded)
//
// Renaming a route, a nav item, a module or a section name will fail this check
// until the matching key in labels.js is renamed too.
const fs = require('fs');
const path = require('path');

const SRC = process.argv[2] || path.join(__dirname, '..', 'src');
let failures = 0;
// Evaluate the phrase dictionary first, then labels.js with that import stubbed.
// Strip the ESM keywords so every declaration stays a plain local, then collect
// the names we care about at the end. (Rewriting `export const X` straight onto
// module.exports breaks `export default X`, which still refers to the local.)
const evalModule = (file, injectName, injectValue) => {
  const src = fs.readFileSync(path.join(SRC, file), 'utf8');
  const m = { exports: {} };
  const cjs = `${src
    .replace(/^import .*$/gm, '')
    .replace(/^export default .*$/gm, '')
    .replace(/^export /gm, '')}
    ;module.exports = {
      ...(typeof LABELS   !== 'undefined' ? { LABELS }   : {}),
      ...(typeof LANGS    !== 'undefined' ? { LANGS }    : {}),
      ...(typeof PHRASES  !== 'undefined' ? { PHRASES }  : {}),
      ...(typeof phrasesAr!== 'undefined' ? { default: phrasesAr } : {}),
    };`;
  new Function('module', 'exports', injectName || '_unused', cjs)(m, m.exports, injectValue);
  return m.exports;
};

const phrasesAr = evalModule('i18n/phrases.ar.js').default || {};
const { LABELS, PHRASES } = evalModule('i18n/labels.js', 'phrasesAr', phrasesAr);
console.log('✓ labels.js parses; langs =', Object.keys(LABELS).join(', '));
console.log(`✓ phrases.ar.js parses; ${Object.keys(phrasesAr).length} phrases`);
if (PHRASES?.ar !== phrasesAr) {
  console.log('✗ PHRASES.ar is not wired to phrases.ar.js');
  failures++;
}

const layoutSrc = fs.readFileSync(path.join(SRC, 'layouts/AppLayout.js'), 'utf8');
const navBlock = layoutSrc.slice(layoutSrc.indexOf('const NAV_SECTIONS'), layoutSrc.indexOf('const ALL_ITEMS'));
const navKey = (to) => (to || '/').replace(/^\//, '').replace(/\//g, '-') || 'home';
const routes = [...navBlock.matchAll(/\bto: '([^']+)'/g)].map((m) => navKey(m[1]));
const sections = [...navBlock.matchAll(/^\s{4}title: '([^']+)'/gm)].map((m) =>
  m[1].toLowerCase().replace(/[^a-z0-9]+/g, '-'),
);

const arItems = LABELS.ar.nav?.items || {};
const arSections = LABELS.ar.nav?.sections || {};

const missing = [...new Set(routes)].filter((k) => !arItems[k]);
const orphan = Object.keys(arItems).filter((k) => !routes.includes(k));
const missingSec = [...new Set(sections)].filter((k) => !arSections[k]);
const orphanSec = Object.keys(arSections).filter((k) => !sections.includes(k));

const noDesc = Object.entries(arItems).filter(([, v]) => !v.desc).map(([k]) => k);

console.log(`nav routes: ${new Set(routes).size}, sections: ${new Set(sections).size}`);
const report = (label, arr) => {
  if (arr.length) failures += arr.length;
  console.log(arr.length ? `✗ ${label}: ${arr.join(', ')}` : `✓ ${label}: none`);
};
report('routes missing Arabic', missing);
report('orphan Arabic item keys', orphan);
report('sections missing Arabic', missingSec);
report('orphan Arabic section keys', orphanSec);
report('Arabic items without desc', noDesc);

// ── Module registry: every ar.modules.<id> subtree must match a real module,
// and every section slug must match a real section name in that module.
// Evaluate the registry for real (with its imports stubbed) rather than
// regex-scraping it — multi-line section objects and nested `menu` arrays make
// the textual approach wrong in both directions.
const regSrc = fs.readFileSync(path.join(SRC, 'config/moduleRegistry.js'), 'utf8');
const regCjs = regSrc
  .replace(/^import .*$/gm, '')
  .replace(/^export (const|function) /gm, '$1 ')
  .concat('\nmodule.exports = { MODULES };');
const IconStub = new Proxy({}, { get: () => () => null });
const regMod = { exports: {} };
new Function('module', 'Icon', 'isFeatureEnabled', 'DEMO_MODE', 'pathBlockedForRoles', regCjs)(
  regMod, IconStub, () => true, false, () => false,
);
const { MODULES } = regMod.exports;

const slug = (s) => (s || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
const moduleIds = MODULES.map((m) => m.id);
const sectionNamesByModule = Object.fromEntries(
  MODULES.map((m) => [
    m.id,
    {
      sections: new Set(m.sections.map((s) => slug(s.name))),
      menu: new Set(m.sections.flatMap((s) => (s.menu || []).map((e) => slug(e.name || e.heading)))),
    },
  ]),
);

const arModules = LABELS.ar.modules || {};
const badModuleIds = Object.keys(arModules).filter(
  (k) => arModules[k] && typeof arModules[k] === 'object' && arModules[k].sections && !moduleIds.includes(k),
);
const missingModules = moduleIds.filter((id) => !arModules[id]);
const badSectionSlugs = [];
for (const id of moduleIds) {
  const entry = arModules[id];
  if (!entry) continue;
  for (const s of Object.keys(entry.sections || {})) {
    if (!sectionNamesByModule[id].sections.has(s)) badSectionSlugs.push(`${id}.sections.${s}`);
  }
  for (const s of Object.keys(entry.menu || {})) {
    if (!sectionNamesByModule[id].menu.has(s)) badSectionSlugs.push(`${id}.menu.${s}`);
  }
}
const missingSectionSlugs = moduleIds.flatMap((id) =>
  [...sectionNamesByModule[id].sections]
    .filter((s) => !arModules[id]?.sections?.[s])
    .map((s) => `${id}.sections.${s}`),
);
console.log(`modules: ${moduleIds.length}`);
report('registry modules missing Arabic', missingModules);
report('Arabic module ids not in registry', badModuleIds);
report('Arabic slugs not in registry', badSectionSlugs);
report('registry sections missing Arabic', missingSectionSlugs);

// en/ar parity for the shared namespaces. `nav` and the per-module subtrees of
// `modules` are English-in-the-table by design (see labels.js), so exclude them.
const walk = (o, p = '') =>
  Object.entries(o).flatMap(([k, v]) =>
    v && typeof v === 'object' ? walk(v, `${p}${k}.`) : [`${p}${k}`],
  );
// Arabic has plural categories English lacks (zero/two/few/many); those are
// expected to exist only on the ar side, so they aren't parity violations.
const AR_ONLY_PLURAL = /\.(zero|two|few|many)$/;
const exempt = (k) =>
  k === 'nav' || k.startsWith('nav.') ||
  moduleIds.some((id) => k.startsWith(`modules.${id}.`)) ||
  AR_ONLY_PLURAL.test(k);
const enKeys = new Set(walk(LABELS.en).filter((k) => !exempt(k)));
const arKeys = new Set(walk(LABELS.ar).filter((k) => !exempt(k)));
report('keys in en but not ar', [...enKeys].filter((k) => !arKeys.has(k)));
report('keys in ar but not en', [...arKeys].filter((k) => !enKeys.has(k)));
console.log(`total translated keys: en=${enKeys.size} ar=${arKeys.size}`);

// ── Phrase dictionary: report coverage, and flag entries that translate to
// themselves (a copy-paste slip that silently leaves the UI in English).
const untranslated = Object.entries(phrasesAr).filter(([en, ar]) => !ar || ar === en).map(([en]) => en);
report('phrases with no Arabic', untranslated);
// A phrase key carrying a {var} must keep it, or the value vanishes at runtime.
const lostVars = Object.entries(phrasesAr)
  .filter(([en, ar]) => (en.match(/\{(\w+)\}/g) || []).some((v) => !String(ar).includes(v)))
  .map(([en]) => en);
report('phrases dropping a {var}', lostVars);

if (failures) {
  console.error(`\n✗ i18n check failed — ${failures} problem(s). See the ✗ lines above.`);
  process.exit(1);
}
console.log('\n✓ i18n check passed.');
