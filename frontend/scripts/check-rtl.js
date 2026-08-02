// RTL guardrail — run with `npm run check:rtl`. Reports (and with --write, fixes)
// physical-direction Tailwind utilities that don't mirror under dir="rtl":
// ml/mr/pl/pr, left/right insets, text-left/right, border-l/r, rounded-l/r/tl/tr/bl/br.
// Exits non-zero if any are found, so new ones can't creep back in.
//
// Matching is deliberately conservative:
//   • only in class-like string context (preceded by quote/space/brace/paren)
//   • values must be real Tailwind scale tokens, so prose like "right-aligned"
//     in a comment is never rewritten
//   • a proper right boundary, so `rounded-lg` is NOT `rounded-l` and
//     `border-red-500` is NOT `border-r`
//   • `left-1/2` paired with `-translate-x-1/2` is left alone (centering idiom)
//   • SKIP_FILES below opt out of components whose geometry is truly physical
//
// CSS files are not covered here — those were converted to logical properties
// (margin-inline-start, inset-inline-end, text-align:start, …) directly.
const fs = require('fs');
const path = require('path');

const ROOT = process.argv[2] || path.join(__dirname, '..', 'src');
const WRITE = process.argv.includes('--write');

// Tailwind variant prefixes (sm:, hover:, group-hover:, dark:, peer-focus:, …)
const VARIANTS = '(?:[a-z0-9][a-z0-9-]*:)*';
// A utility ends at a quote, whitespace, backtick or `$` (template interpolation).
const END = '(?=[\\s"\'`$}]|$)';

// A spacing/inset VALUE is a Tailwind scale token — a number, a fraction, or one
// of the named keywords — never an arbitrary English word. Without this, prose in
// comments like "right-side" and "right-aligned" gets rewritten too.
const VALUE = '(?:\\d+(?:\\.\\d+)?(?:\\/\\d+)?|auto|full|px|screen|min|max|fit|\\[[^\\]]*\\])';

const MAP = [
  ['ml', 'ms'], ['mr', 'me'],
  ['pl', 'ps'], ['pr', 'pe'],
  ['left', 'start'], ['right', 'end'],
];

// Files whose horizontal geometry is genuinely PHYSICAL, not reading-order: an
// image before/after wipe and a fuel gauge fill. Their Tailwind classes are
// paired with inline `left:` styles computed from clientX / percentages, so
// flipping only the classes would desync the two. Left alone deliberately.
const SKIP_FILES = [
  'components/inspection/BeforeAfterSlider.js',
  'components/inspection/FuelGaugeSlider.js',
];

// `left-1/2` next to `-translate-x-1/2` is the CENTERING idiom, not a directional
// one: left:50% + translateX(-50%) centers an element. Rewriting it to start-1/2
// makes it right:50% + translateX(-50%) under RTL, which lands half its own width
// off-centre. Never convert those.
const isCenteringIdiom = (src, index, val) =>
  (val === '1/2') && /-translate-x-1\/2/.test(src.slice(Math.max(0, index - 220), index + 220));

const rules = [];
for (const [from, to] of MAP) {
  rules.push({
    re: new RegExp(`(?<=[\\s"'\`{(])(${VARIANTS})(-?)${from}-(${VALUE})${END}`, 'g'),
    fn: (m, v, neg, val, index, src) =>
      (from === 'left' || from === 'right') && isCenteringIdiom(src, index, val)
        ? m
        : `${v}${neg}${to}-${val}`,
    label: `${from}- → ${to}-`,
  });
}
// text alignment
rules.push({
  re: new RegExp(`(?<=[\\s"'\`{(])(${VARIANTS})text-left${END}`, 'g'),
  fn: (_m, v) => `${v}text-start`, label: 'text-left → text-start',
});
rules.push({
  re: new RegExp(`(?<=[\\s"'\`{(])(${VARIANTS})text-right${END}`, 'g'),
  fn: (_m, v) => `${v}text-end`, label: 'text-right → text-end',
});
// borders — bare (`border-l`) or sized (`border-l-2`). Value must be numeric so
// `border-lime-500` / `border-red-500` can't match.
for (const [from, to] of [['l', 's'], ['r', 'e']]) {
  rules.push({
    re: new RegExp(`(?<=[\\s"'\`{(])(${VARIANTS})border-${from}(-\\d+)?${END}`, 'g'),
    fn: (_m, v, size) => `${v}border-${to}${size || ''}`,
    label: `border-${from} → border-${to}`,
  });
}
// radii — corner pairs first (tl/tr/bl/br), then sides. Size is a named scale
// (sm/md/lg/xl/full/none/2xl/…) or absent; `rounded-lg` must NOT match `rounded-l`.
const RADIUS_SIZE = '(-(?:none|sm|md|lg|xl|2xl|3xl|full))?';
for (const [from, to] of [['tl', 'ss'], ['tr', 'se'], ['bl', 'es'], ['br', 'ee']]) {
  rules.push({
    re: new RegExp(`(?<=[\\s"'\`{(])(${VARIANTS})rounded-${from}${RADIUS_SIZE}${END}`, 'g'),
    fn: (_m, v, size) => `${v}rounded-${to}${size || ''}`,
    label: `rounded-${from} → rounded-${to}`,
  });
}
for (const [from, to] of [['l', 's'], ['r', 'e']]) {
  rules.push({
    re: new RegExp(`(?<=[\\s"'\`{(])(${VARIANTS})rounded-${from}${RADIUS_SIZE}${END}`, 'g'),
    fn: (_m, v, size) => `${v}rounded-${to}${size || ''}`,
    label: `rounded-${from} → rounded-${to}`,
  });
}

const files = [];
(function walk(dir) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p);
    else if (/\.jsx?$/.test(e.name)) files.push(p);
  }
})(ROOT);

const tally = {};
const samples = [];
let changedFiles = 0;

const skipped = [];
for (const f of files) {
  const rel = path.relative(ROOT, f).replace(/\\/g, '/');
  if (SKIP_FILES.includes(rel)) { skipped.push(rel); continue; }
  const src = fs.readFileSync(f, 'utf8');
  let out = src;
  for (const r of rules) {
    out = out.replace(r.re, (...args) => {
      const m = args[0];
      const res = r.fn(...args);
      if (m !== res) {
        tally[r.label] = (tally[r.label] || 0) + 1;
        if (samples.length < 40) samples.push(`${path.relative(ROOT, f)}: ${m}  →  ${res}`);
      }
      return res;
    });
  }
  if (out !== src) {
    changedFiles++;
    if (WRITE) fs.writeFileSync(f, out);
  }
}

const total = Object.values(tally).reduce((a, b) => a + b, 0);
console.log(WRITE ? '── APPLIED ──' : total ? '── FOUND (pass --write to fix) ──' : '');
for (const [k, v] of Object.entries(tally).sort((a, b) => b[1] - a[1])) {
  console.log(`  ${String(v).padStart(4)}  ${k}`);
}
console.log(`skipped (physical geometry, by design): ${skipped.join(', ') || 'none'}`);

if (!total) {
  console.log('✓ RTL check passed — no physical-direction utilities found.');
  process.exit(0);
}
console.log(`\n${total} physical-direction utilities across ${changedFiles} files`);
console.log('\nSamples:');
samples.slice(0, 30).forEach((s) => console.log('  ' + s));
if (!WRITE) {
  console.error('\n✗ RTL check failed — these will not mirror under dir="rtl".');
  console.error('  Run `npm run check:rtl -- --write` to convert them.');
  process.exit(1);
}
