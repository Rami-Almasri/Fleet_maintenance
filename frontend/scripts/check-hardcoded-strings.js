// Hardcoded-English guardrail — run with `npm run check:i18n` (or on its own via
// `node scripts/check-hardcoded-strings.js`). Exits non-zero when a file gains user-visible English
// that never passes through the i18n catalog.
//
// WHY THIS EXISTS. check-i18n.js proves the CATALOG is complete and en/ar are in lock-step — but a
// page can be 100% hardcoded English and still pass it, because a string that never reaches the
// catalog is invisible to a catalog check. That is how ~180 files drifted out of Arabic while the
// guardrail stayed green. This script closes that hole from the other side: it reads the COMPONENTS
// and asks whether the words a user actually sees went through t()/tf()/tp().
//
// HOW IT DECIDES. A string is a violation when it is user-visible English that is not an argument to
// a resolver. Concretely, before matching, the script blanks out:
//   • comments and imports                     — not user-visible
//   • every t() / tf() / tp() call             — the fallback English inside tf() is CORRECT, not a
//                                                violation; that is the documented migration idiom
//   • className / style / key / id / to / href / type / name / data-* attributes — not prose
// What remains is JSX text nodes and the user-visible attributes (placeholder, title, aria-label,
// alt, label), which is what a person reads off the screen.
//
// BASELINE, NOT BIG BANG. i18n-baseline.json records the count each un-migrated file is KNOWN to
// have. The check fails when a file goes ABOVE its baseline, or when a file not in the baseline has
// any at all. So the existing backlog does not block anyone, a new page must be localized from the
// start, and an un-migrated page can only get better. When you translate a file, run with --update
// to write the lower number back (the script never raises a baseline for you — that would silently
// bless a regression).
//
//   node scripts/check-hardcoded-strings.js            # check
//   node scripts/check-hardcoded-strings.js --update   # re-record improvements
//   node scripts/check-hardcoded-strings.js --report    # full per-file listing with samples
const fs = require('fs');
const path = require('path');

const SRC = path.join(__dirname, '..', 'src');
const BASELINE_FILE = path.join(__dirname, 'i18n-baseline.json');
const UPDATE = process.argv.includes('--update');
const REPORT = process.argv.includes('--report');

// Files whose English is in the table BY DESIGN — the arrangement documented at the top of
// labels.js. Their English lives beside the key/route/tone it describes, their Arabic lives in
// labels.js, and consumers resolve them with tf(). check-i18n.js separately enforces that every one
// of their keys has Arabic, so they are covered — just not by this script.
const ENGLISH_IN_TABLE = new Set([
  'layouts/AppLayout.js',
  'config/moduleRegistry.js',
  'config/maintenanceLanes.js',
  'lib/faultCategories.js',
  'lib/repairCapture.js',
  'lib/maintenanceCheckpoints.js',
]);

const files = [];
(function walk(dir) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p);
    else if (/\.js$/.test(e.name) && !/\.test\.js$/.test(e.name)) files.push(p);
  }
})(SRC);

// Blank a matched span while preserving its length, so later offsets and line numbers stay true.
const blank = (s, re) => s.replace(re, (m) => m.replace(/[^\n]/g, ' '));

// Blank balanced call arguments for `name(` — regex can't match nested parens, so scan.
function blankCalls(src, names) {
  let out = src;
  for (const name of names) {
    const re = new RegExp(`(^|[^\\w.$])${name}\\(`, 'g');
    let m;
    while ((m = re.exec(out)) !== null) {
      const open = m.index + m[0].length - 1;
      let depth = 0;
      let i = open;
      let quote = null;
      for (; i < out.length; i++) {
        const c = out[i];
        if (quote) {
          if (c === '\\') i++;
          else if (c === quote) quote = null;
          continue;
        }
        if (c === '"' || c === "'" || c === '`') { quote = c; continue; }
        if (c === '(') depth++;
        else if (c === ')') { depth--; if (depth === 0) break; }
      }
      if (i > open) {
        const span = out.slice(open, i + 1).replace(/[^\n]/g, ' ');
        out = out.slice(0, open) + span + out.slice(i + 1);
        re.lastIndex = open;
      }
    }
  }
  return out;
}

// A run of text only counts as prose if it reads like prose: letters, and no JavaScript operators.
// This is what keeps `{cond ? a : b}` fragments and `arr.length > 0 && ...` out of the results.
const OPERATORS = /[=<>{}[\]()|&?:;+*/\\`$@_^~]/;
const hasWord = (s) => /[A-Za-z]{2,}/.test(s) && /[a-z]/.test(s);
const isProse = (s) => hasWord(s) && !OPERATORS.test(s) && !/^[A-Z0-9_]+$/.test(s);

// Attributes a user reads. `label` and `subtitle` are here because this codebase's Input / Select /
// Modal / SectionCard render them as visible text — a form whose fields are all `label="…"` is
// entirely untranslated even though it has no JSX text at all. Everything else (className, key,
// to, …) is machinery and stays out.
const VISIBLE_ATTR = /\b(placeholder|title|aria-label|alt|label|subtitle)=(?:"([^"]{2,120})"|'([^']{2,120})')/g;

function scan(file) {
  let src = fs.readFileSync(file, 'utf8');
  src = blank(src, /\/\*[\s\S]*?\*\//g);          // block comments
  src = blank(src, /^[ \t]*\/\/.*$/gm);           // line comments
  src = blank(src, /^import[\s\S]*?from\s+['"].*?['"];?$/gm);
  src = blank(src, /^export\s+.*?from\s+['"].*?['"];?$/gm);
  // The resolvers. Anything inside them is already localized — including tf()'s English fallback.
  src = blankCalls(src, ['t', 'tf', 'tp']);
  // Machinery attributes, blanked so their values never read as prose.
  src = blank(src, /\b(className|style|key|id|to|href|type|name|value|data-[\w-]+)=(?:"[^"]*"|'[^']*'|\{[^{}]*\})/g);

  const hits = [];
  const lineOf = (idx) => src.slice(0, idx).split('\n').length;

  // JSX text nodes: >text< on one line.
  for (const m of src.matchAll(/>([^<>{}\n]{2,120})</g)) {
    const s = m[1].trim();
    if (isProse(s)) hits.push({ line: lineOf(m.index), text: s });
  }
  // User-visible attributes.
  for (const m of src.matchAll(VISIBLE_ATTR)) {
    const s = (m[2] || m[3] || '').trim();
    if (isProse(s)) hits.push({ line: lineOf(m.index), text: s });
  }
  return hits;
}

const baseline = fs.existsSync(BASELINE_FILE)
  ? JSON.parse(fs.readFileSync(BASELINE_FILE, 'utf8'))
  : { files: {} };

const results = {};
for (const f of files) {
  const rel = path.relative(SRC, f).replace(/\\/g, '/');
  if (rel.startsWith('i18n/') || ENGLISH_IN_TABLE.has(rel)) continue;
  const hits = scan(f);
  if (hits.length) results[rel] = hits;
}

if (REPORT) {
  // `--report <substring>` narrows to matching paths and lists EVERY hit — the working view when
  // you sit down to translate one file.
  const filter = process.argv[process.argv.indexOf('--report') + 1];
  const only = filter && !filter.startsWith('--') ? filter : null;
  const rows = Object.entries(results)
    .filter(([rel]) => !only || rel.includes(only))
    .sort((a, b) => b[1].length - a[1].length);
  for (const [rel, hits] of rows) {
    console.log(`\n${String(hits.length).padStart(4)}  ${rel}`);
    (only ? hits : hits.slice(0, 5)).forEach((h) => console.log(`      ${rel}:${h.line}  ${h.text}`));
  }
  console.log(`\n${rows.length} files, ${rows.reduce((a, [, h]) => a + h.length, 0)} strings.`);
  process.exit(0);
}

if (UPDATE) {
  const next = {};
  for (const rel of Object.keys(results).sort()) {
    const found = results[rel].length;
    const prior = baseline.files[rel];
    // Never raise a baseline automatically — that would bless a regression as the new normal.
    next[rel] = prior === undefined ? found : Math.min(prior, found);
  }
  fs.writeFileSync(
    BASELINE_FILE,
    `${JSON.stringify({
      _comment: 'Known-untranslated counts per file. See scripts/check-hardcoded-strings.js. Counts may only go DOWN; a new file must be 0. Refresh with `npm run check:i18n:update` after translating.',
      files: next,
    }, null, 2)}\n`,
  );
  console.log(`✓ baseline updated — ${Object.keys(next).length} files with known hardcoded English.`);
  process.exit(0);
}

let failures = 0;
const regressions = [];
const newFiles = [];
for (const [rel, hits] of Object.entries(results)) {
  const allowed = baseline.files[rel];
  if (allowed === undefined) newFiles.push({ rel, hits });
  else if (hits.length > allowed) regressions.push({ rel, found: hits.length, allowed, hits });
}

if (newFiles.length) {
  failures += newFiles.length;
  console.log(`\n✗ ${newFiles.length} file(s) with hardcoded English and no baseline entry.`);
  console.log('  New code must use t()/tf() — see the RULE at the top of src/i18n/labels.js.');
  for (const { rel, hits } of newFiles) {
    console.log(`\n  ${rel}  (${hits.length})`);
    hits.slice(0, 5).forEach((h) => console.log(`    ${rel}:${h.line}  ${h.text}`));
    if (hits.length > 5) console.log(`    … and ${hits.length - 5} more`);
  }
}

if (regressions.length) {
  failures += regressions.length;
  console.log(`\n✗ ${regressions.length} file(s) gained hardcoded English.`);
  for (const { rel, found, allowed, hits } of regressions) {
    console.log(`\n  ${rel}  (${allowed} → ${found})`);
    hits.slice(0, 5).forEach((h) => console.log(`    ${rel}:${h.line}  ${h.text}`));
  }
}

const improved = Object.keys(baseline.files).filter(
  (rel) => (results[rel]?.length ?? 0) < baseline.files[rel],
);
const totalKnown = Object.values(baseline.files).reduce((a, b) => a + b, 0);
const totalNow = Object.values(results).reduce((a, h) => a + h.length, 0);

if (failures) {
  console.error(`\n✗ hardcoded-string check failed — ${failures} file(s). See the ✗ lines above.`);
  process.exit(1);
}

console.log(`✓ hardcoded English: ${totalNow} strings across ${Object.keys(results).length} files, all at or below baseline (${totalKnown}).`);
if (improved.length) {
  console.log(`  ${improved.length} file(s) improved — run \`npm run check:i18n:update\` to lock the gain in.`);
}
