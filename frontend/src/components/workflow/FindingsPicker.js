// Category-grouped, tap-to-toggle "Findings" picker for the maintenance workflow.
//
// Drives both the Inspector's test-drive report and the workshop's garage-findings step. The keyword
// library is the CENTRAL one served by GET /maintenance-tickets/findings-catalog (config/maintenance_findings.php) —
// this component never hardcodes the list, it just renders whatever catalog it's given. On top of the
// preset chips it offers a free-text "custom issue" box so anything not in the library can still be
// captured as a tag. `value` is a flat array of the selected keyword strings; `onChange` returns the
// next array. Whatever ends up in `value` is exactly what gets persisted as findings tags.
//
// Bilingual: the SAVED value is always the English keyword (the stable analytics key), but each chip is
// DISPLAYED in the active language — Arabic label from `keywordMeta[keyword].ar` when the UI is Arabic,
// category name from `cat.label_ar`. A small risk dot (from keywordMeta[keyword].tone) mirrors the
// Keyword Risk Library so the inspector sees how serious each fault is while tapping.
//
// `locked` is the data-purity guard: any keyword already on the ticket (e.g. the Inspector's findings,
// seen by the Garage user) is rendered "Already reported" — selected-looking, disabled and un-clickable —
// so the same issue can never be added twice. Locking is computed from whatever `locked` the caller
// passes, so it reflects the live ticket the moment the picker opens.
//
// PRESENTATION — the catalog is long (13 categories × ~7 keywords), so it is NOT dumped as one endless
// wall of chips. Each category is a COLLAPSED accordion row showing its name + how many of its issues are
// picked; the inspector opens only the systems they actually looked at. A search box across the top
// filters every keyword (English + Arabic + category name) at once and auto-opens whatever matches, so a
// known fault is one type away instead of a scroll hunt. A pinned "selected" tray keeps every pick visible
// (and removable) no matter which categories are closed.

import { useEffect, useMemo, useRef, useState } from 'react';
import Icon from '../ui/Icon';
import FindingsAiSuggestion from './FindingsAiSuggestion';
import { useI18n } from '../../i18n/I18nContext';

// Degrade gracefully if the catalog request fails: a small built-in set keeps the modal usable
// offline. The real, comprehensive library lives in the backend config.
const FALLBACK = [
  { key: 'common', label: 'Common issues', keywords: ['Brakes', 'Engine noise', 'A/C', 'Suspension', 'Tyres', 'Warning light', 'Body / dent', 'Electrical', 'Gearbox', 'Steering'] },
];

// Risk tone → dot colour (matches Badge tones used in the Keyword Risk Library).
const RISK_DOT = { red: 'bg-red-500', amber: 'bg-amber-500', green: 'bg-emerald-500' };

// Catalog categories that are PLANNED WORK rather than defects. Mirrors the backend's single authority,
// EventClassificationService::isServiceCategory (config `maintenance_findings.service_ontology_categories`).
// Kept as one named constant rather than an inline string so the next service category is a one-line
// change in two files instead of a hunt through JSX.
const SERVICE_CATEGORIES = new Set(['routine']);

// Which live diagnostic condition (see DiagnosticGateService::context — 'oil' | 'battery' | 'tyres')
// backs each monitored routine keyword. Mirrors the backend's Maintenance::routineServiceTypeFor /
// DiagnosticGateService::routineStatus mapping — used here to warn in real time, before submission,
// when the tapped keyword's car status says it isn't actually due.
const CONDITION_FOR_KEYWORD = {
  'oil change':          'oil',
  'battery replacement': 'battery',
  'tire rotation':       'tyres',
  'tire change':         'tyres',
};

/**
 * WHAT KIND OF WORK THIS WORD IS — service, fault, damage, or nothing the catalogs recognise.
 *
 * Shown on the word ITSELF, at the moment of picking, because the distinction is real work and not a
 * label: a service is planned upkeep falling due, a fault is a claim the car failed, and only the
 * fault feeds Top Faults, recurrence and the health score. "Oil Change" and "Engine noise" used to
 * look identical on this screen while going to completely different places.
 *
 * `null` renders as "unclassified" rather than being hidden. A keyword neither catalog recognises is
 * promoted by the legacy shield, which defaults it to `fault` — so leaving the badge off would let a
 * word quietly become a fault while looking like a considered choice. 21 of the catalog's 95 words
 * are in exactly that state today; the badge is how anyone finds out.
 */
const KIND_STYLE = {
  service: 'bg-blue-100 text-blue-700 ring-blue-600/20',
  fault:   'bg-red-100 text-red-700 ring-red-600/20',
  damage:  'bg-purple-100 text-purple-700 ring-purple-600/20',
  unknown: 'bg-slate-100 text-slate-500 ring-slate-400/20',
};

function KindBadge({ kind, label, t, className = '' }) {
  const style = KIND_STYLE[kind || 'unknown'] || KIND_STYLE.unknown;
  const text  = kind ? (label || kind) : t('findingsPicker.kind.unclassified');

  return (
    <span
      title={kind ? t('findingsPicker.kind.hint', { kind: text }) : t('findingsPicker.kind.unclassifiedHint')}
      className={`inline-flex shrink-0 items-center rounded px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide ring-1 ring-inset ${style} ${className}`}
    >
      {text}
    </span>
  );
}

function Chip({ label, tone, kind, kindLabel, t, active, locked, lockedTitle, required, requiredTitle, onClick }) {
  const dot = tone && RISK_DOT[tone] && !active ? <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${RISK_DOT[tone]}`} /> : null;

  // REQUIRED is the opposite of locked, and must not look like it. Locked means "already reported,
  // you cannot add it again" — greyed out and off. Required means "this IS being done, and you do
  // not get to remove it": it renders SELECTED, in the selected colour, with a padlock.
  if (required) {
    return (
      <span
        title={requiredTitle}
        aria-disabled="true"
        className="inline-flex cursor-not-allowed items-center gap-1.5 rounded-full bg-rose-600 px-3 py-1 text-xs font-semibold text-white ring-1 ring-rose-600"
      >
        <span aria-hidden>🔒</span> {label}
      </span>
    );
  }
  if (locked) {
    return (
      <span
        title={lockedTitle}
        aria-disabled="true"
        className="inline-flex cursor-not-allowed items-center gap-1 rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-400 ring-1 ring-slate-200"
      >
        <Icon.Check className="h-3 w-3" /> {label}
      </span>
    );
  }
  return (
    <button
      type="button"
      onClick={onClick}
      className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium ring-1 transition ${
        active ? 'bg-indigo-600 text-white ring-indigo-600' : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'
      }`}
    >
      {dot}
      {label}
      <KindBadge kind={kind} label={kindLabel} t={t} className={active ? 'opacity-90' : ''} />
    </button>
  );
}

// `ticketId` / `vehicleId` / `aiContext` are only used to ANCHOR the AI match verdicts (see
// FindingsAiSuggestion): a Yes/No given on a real car during a real inspection is ground truth about
// the vocabulary, and is filed apart from admin experiments on the keyword-library page. They are
// optional — the picker works identically without them, the verdicts just lose their provenance.
export default function FindingsPicker({ catalog, keywordMeta = {}, value = [], onChange, locked = [], required = [], requiredNote = null, onSiteOnly = false, onSiteKeywords = [], suggested = [], statusConditions = [], ticketId = null, vehicleId = null, aiContext = 'test_findings' }) {
  const { t, tf, lang } = useI18n();
  const [custom, setCustom] = useState('');
  const [query, setQuery] = useState('');
  // Which category accordions are open. Everything starts CLOSED — the inspector opens the systems they
  // actually inspected. A category holding a pick auto-opens once (see the effect below) so a selection
  // is never hidden behind a closed row.
  const [openCats, setOpenCats] = useState(() => new Set());
  const allCategories = catalog?.length ? catalog : FALLBACK;

  // Reality-check lookup by condition key ('oil' | 'battery' | 'tyres') → its live status entry.
  const conditionByKey = useMemo(() => {
    const map = {};
    (statusConditions || []).forEach((c) => { if (c?.key) map[c.key] = c; });
    return map;
  }, [statusConditions]);

  // Repair-Location "On-Site" checklist filter. When the ticket is being routed to the mobile lane we
  // show only the MINOR tasks: categories flagged `on_site`, PLUS the Auto-On-Site keyword tokens
  // (battery / oil) even from an In-Shop category — and always any keyword already selected, so
  // narrowing the checklist can never hide (and orphan) something the inspector already picked.
  const onSiteMatch = (k) =>
    (onSiteKeywords || []).some((tok) => tok && String(k).toLowerCase().includes(String(tok).toLowerCase()));
  const selectedSet = useMemo(() => new Set(value.map((v) => String(v).toLowerCase())), [value]);
  const categories = useMemo(() => {
    if (!onSiteOnly) return allCategories;
    return allCategories
      .map((c) =>
        c.on_site
          ? c
          : { ...c, keywords: c.keywords.filter((k) => onSiteMatch(k) || selectedSet.has(k.toLowerCase())) })
      .filter((c) => c.keywords.length > 0);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [allCategories, onSiteOnly, onSiteKeywords, selectedSet]);

  // Display helpers — Arabic when the UI is Arabic, English otherwise (saved value stays English).
  const kwLabel = (k) => (lang === 'ar' ? keywordMeta[k]?.ar || k : k);
  const catLabel = (c) => (lang === 'ar' ? c.label_ar || c.label : c.label);
  const kwTone = (k) => keywordMeta[k]?.tone;
  // Service | Fault | Damage | unclassified — resolved server-side by the SAME classifier that will
  // type the MaintenanceTask, so the badge can never disagree with what actually gets created.
  const kwKind  = (k) => keywordMeta[k]?.kind ?? null;
  const kwKindLabel = (k) => keywordMeta[k]?.kind_label ?? null;

  // Already on the ticket → locked. Matched case-insensitively so "Engine noise" and "engine noise"
  // are treated as the same issue.
  const lockedSet = useMemo(
    () => new Set(locked.map((l) => String(l).toLowerCase())),
    [locked],
  );
  const isLocked = (k) => lockedSet.has(k.toLowerCase());

  // ── REQUIRED findings — decided before this screen, and not removable on it ────────────────
  // An oil recall is the case this exists for: the customer's rental was interrupted BECAUSE the car
  // needs an oil change, so "Oil Change" is not one of six routine boxes an inspector may untick on
  // his way past. It arrives selected and stays selected. (Locked ≠ required: locked means "already
  // reported, don't add it twice"; required means "this is happening".)
  const requiredSet = useMemo(
    () => new Set(required.map((r) => String(r).toLowerCase())),
    [required],
  );
  const isRequired = (k) => requiredSet.has(k.toLowerCase());

  // Put them in the selection the moment the picker opens, so a report can never be submitted
  // without them — and re-assert if anything downstream drops one.
  useEffect(() => {
    if (!required.length) return;
    const missing = required.filter((r) => !value.some((v) => v.toLowerCase() === String(r).toLowerCase()));
    if (missing.length) onChange([...value, ...missing]);
  }, [required, value, onChange]);

  // Every keyword the catalog knows about — used to split the selection into "from the library"
  // vs "custom", so custom tags render in their own removable row.
  const known = useMemo(
    () => new Set(allCategories.flatMap((c) => c.keywords.map((k) => k.toLowerCase()))),
    [allCategories],
  );
  // Locked findings that aren't in the catalog (e.g. an inspector's custom note) — surfaced in their
  // own row so the Garage user sees the full "already reported" picture, not just the preset chips.
  const lockedCustoms = useMemo(
    () => locked.filter((l) => !known.has(String(l).toLowerCase())),
    [locked, known],
  );

  // Causes + repairs for each SELECTED finding, read from the catalog payload rather than fetched.
  // A custom tag the inspector typed has no concept behind it and is skipped — an empty "usually
  // caused by:" would read as missing data rather than as a fault we have nothing curated for.
  const knowledgeForSelected = useMemo(
    () => value
      .map((k) => {
        const meta = keywordMeta[k] || {};
        return { keyword: k, causes: meta.causes || [], fixes: meta.fixes || [] };
      })
      .filter((entry) => entry.causes.length > 0 || entry.fixes.length > 0),
    [value, keywordMeta],
  );

  const has = (k) => value.some((v) => v.toLowerCase() === k.toLowerCase());
  const toggle = (k) => {
    if (isLocked(k)) return;   // already reported — never selectable
    if (isRequired(k)) return; // decided upstream — this one is not the inspector's to drop
    onChange(has(k) ? value.filter((v) => v.toLowerCase() !== k.toLowerCase()) : [...value, k]);
  };

  // ── Search ────────────────────────────────────────────────────────────────────
  // One box over the WHOLE catalog: matches the English keyword (the saved value), its Arabic label, and
  // the category name — so "brake" / "فرامل" / "Brakes" all land. A category whose NAME matches keeps all
  // of its keywords (you asked for the system, you get the system); otherwise only the matching keywords
  // survive. While searching, every surviving category renders open regardless of the accordion state.
  const q = query.trim().toLowerCase();
  const kwMatches = (k) => k.toLowerCase().includes(q) || String(keywordMeta[k]?.ar || '').toLowerCase().includes(q);
  const visibleCategories = useMemo(() => {
    if (!q) return categories;
    return categories
      .map((c) => {
        const catHit = String(c.label || '').toLowerCase().includes(q) || String(c.label_ar || '').toLowerCase().includes(q);
        return catHit ? c : { ...c, keywords: c.keywords.filter(kwMatches) };
      })
      .filter((c) => c.keywords.length > 0);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [categories, q, keywordMeta]);
  const searching = q.length > 0;
  const matchCount = useMemo(
    () => visibleCategories.reduce((n, c) => n + c.keywords.length, 0),
    [visibleCategories],
  );

  // How many of a category's issues are currently picked — the badge that lets a closed row still report
  // what's inside it.
  const pickedIn = (cat) => cat.keywords.filter((k) => has(k) || isLocked(k)).length;

  const isOpen = (cat) => searching || openCats.has(cat.key);
  const toggleCat = (key) =>
    setOpenCats((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key); else next.add(key);
      return next;
    });
  const setAllOpen = (open) => setOpenCats(open ? new Set(categories.map((c) => c.key)) : new Set());

  // Auto-open any category that already carries a pick (a resumed draft, or the Garage user seeing the
  // inspector's locked findings). Runs once per catalog load — after that the inspector owns the state.
  const autoOpened = useRef(false);
  useEffect(() => {
    if (autoOpened.current || !allCategories.length) return;
    autoOpened.current = true;
    const preset = allCategories
      .filter((c) => c.keywords.some((k) => selectedSet.has(k.toLowerCase()) || lockedSet.has(k.toLowerCase())))
      .map((c) => c.key);
    if (preset.length) setOpenCats(new Set(preset));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [allCategories]);

  // Ready-entry-point row — the exact keyword(s) this ticket was system-flagged for (an oil change /
  // battery / tyre service the car's data says is DUE), from DiagnosticGateService. One tap confirms it
  // as a finding, no need to hunt the category it lives in. Drops off once selected or already reported.
  //
  // Data-driven guard: a monitored routine (oil / battery / tyres) is NEVER offered here while the car's
  // live status says it isn't due. That's the "confirm this replacement" → "…but it's not due" trap the
  // reality-check below would immediately flag. The keyword can still be picked manually from the category
  // list (which then shows that warning) — it just isn't pre-suggested as a done deal.
  const pendingSuggested = suggested.filter((k) => {
    if (!k || has(k) || isLocked(k)) return false;
    const condKey = CONDITION_FOR_KEYWORD[String(k).toLowerCase()];
    const cond = condKey ? conditionByKey[condKey] : null;
    return !(cond && cond.status === 'ok');
  });

  // Reality-check warning — a SELECTED keyword that maps to a monitored routine (oil/battery/tyres)
  // whose car's live status says it is NOT due right now. Fires the instant the chip is tapped, before
  // submission, so a mis-tap or an unnecessary job gets caught here instead of on the Data Health audit.
  //
  // This is no longer only advice. Submitting it anyway is allowed — the person is in front of the car
  // and may be able to see what the odometer cannot — but the finding is then HELD: it is not turned
  // into work, and the ticket cannot leave its stage until an approver signs it off or refuses it. The
  // sentence below says so, because a warning that hides a consequence is worse than no warning.
  // See [[FindingApprovalService]].
  const conflicts = value
    .map((v) => {
      const condKey = CONDITION_FOR_KEYWORD[String(v).toLowerCase()];
      const cond = condKey ? conditionByKey[condKey] : null;
      return cond && cond.status === 'ok' ? { keyword: v, cond } : null;
    })
    .filter(Boolean);

  const addCustom = () => {
    // Allow several at once, comma-separated; skip blanks, anything already selected, and anything
    // already reported on the ticket (no duplicates across the lifecycle).
    const fresh = custom
      .split(',')
      .map((s) => s.trim())
      .filter((s) => s && !has(s) && !isLocked(s));
    if (fresh.length) onChange([...value, ...fresh]);
    setCustom('');
  };

  return (
    <div className="space-y-3">
      {pendingSuggested.length > 0 && (
        <div className="rounded-lg bg-indigo-50/70 p-2.5 ring-1 ring-inset ring-indigo-200">
          <p className="mb-1.5 flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wide text-indigo-700">
            <Icon.Flag className="h-3 w-3" /> {t('findingsPicker.suggestedTitle')}
          </p>
          <div className="flex flex-wrap gap-1.5">
            {pendingSuggested.map((k) => (
              <button
                key={`suggested-${k}`}
                type="button"
                onClick={() => toggle(k)}
                title={t('findingsPicker.suggestedHint')}
                className="inline-flex items-center gap-1.5 rounded-full border border-dashed border-indigo-400 bg-white px-3 py-1 text-xs font-medium text-indigo-700 transition hover:bg-indigo-100"
              >
                <Icon.Plus className="h-3 w-3" /> {kwLabel(k)}
              </button>
            ))}
          </div>
        </div>
      )}

      {conflicts.length > 0 && (
        <div className="rounded-lg bg-amber-50 p-2.5 ring-1 ring-inset ring-amber-300">
          {conflicts.map(({ keyword, cond }) => (
            <p key={`conflict-${keyword}`} className="flex items-start gap-1.5 text-xs text-amber-800">
              <Icon.Alert className="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-500" />
              <span>
                <strong>{kwLabel(keyword)}</strong> — {t('findingsPicker.statusConflict', { summary: cond.summary || t('findingsPicker.statusConflictFallback') })}
                {' '}
                <span className="font-semibold">{t('findingsPicker.statusConflictHeld')}</span>
              </span>
            </p>
          ))}
        </div>
      )}

      {lockedSet.size > 0 && (
        <p className="flex items-center gap-1.5 rounded-lg bg-slate-50 px-2.5 py-1.5 text-[11px] text-slate-500 ring-1 ring-inset ring-slate-200/70">
          <Icon.Check className="h-3 w-3 text-slate-400" />
          {t('findingsPicker.lockedNote')}
        </p>
      )}

      {/* Search — one box across the entire catalog, so a known fault never needs a category hunt. */}
      <div className="space-y-2">
        <div className="relative">
          <Icon.Search className="pointer-events-none absolute inset-y-0 start-3 my-auto h-4 w-4 text-slate-400" />
          <input
            type="search"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder={t('findingsPicker.searchPlaceholder')}
            className="w-full rounded-xl border border-slate-300 bg-white py-2 pe-9 ps-9 text-sm text-slate-700 placeholder:text-slate-400 focus:border-indigo-400 focus:outline-none focus:ring-1 focus:ring-indigo-400"
          />
          {query && (
            <button
              type="button"
              onClick={() => setQuery('')}
              aria-label={t('findingsPicker.clearSearch')}
              className="absolute inset-y-0 end-2 my-auto flex h-6 w-6 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
            >
              <Icon.X className="h-3.5 w-3.5" />
            </button>
          )}
        </div>

        <div className="flex items-center justify-between gap-2 text-[11px]">
          <span className="font-medium text-slate-500">
            {searching
              ? t('findingsPicker.searchResults', { count: matchCount })
              : t('findingsPicker.selectedCount', { count: value.length })}
          </span>
          {!searching && (
            <div className="flex items-center gap-2">
              <button type="button" onClick={() => setAllOpen(true)} className="font-semibold text-indigo-600 transition hover:underline">
                {t('findingsPicker.expandAll')}
              </button>
              <span className="text-slate-300">·</span>
              <button type="button" onClick={() => setAllOpen(false)} className="font-semibold text-slate-500 transition hover:underline">
                {t('findingsPicker.collapseAll')}
              </button>
            </div>
          )}
        </div>
      </div>

      {/* Pinned selection tray — every pick stays visible (and removable) with all categories closed. */}
      {value.length > 0 && (
        <div className="rounded-xl bg-indigo-50/60 p-2.5 ring-1 ring-inset ring-indigo-200">
          <div className="mb-1.5 flex flex-wrap items-center justify-between gap-2">
            <p className="text-[10px] font-semibold uppercase tracking-wide text-indigo-700">
              {t('findingsPicker.selectedTitle')}
            </p>
            {/* THE SPLIT, COUNTED. What is about to be raised as planned upkeep vs. as something
                wrong with the car — the one number a supervisor scanning this tray actually wants,
                and previously had to work out by reading every word. Unclassified is called out
                separately rather than folded into either side. */}
            <span className="flex flex-wrap items-center gap-1">
              {['service', 'fault', 'damage'].map((kind) => {
                const picked = value.filter((k) => kwKind(k) === kind);
                return picked.length > 0 ? (
                  <KindBadge key={kind} kind={kind} label={`${picked.length} ${kwKindLabel(picked[0]) || kind}`} t={t} />
                ) : null;
              })}
              {value.filter((k) => !kwKind(k)).length > 0 && (
                <KindBadge kind={null} t={t} />
              )}
            </span>
          </div>
          <div className="flex flex-wrap gap-1.5">
            {value.map((k) => (
              <span key={`sel-${k}`} className="inline-flex items-center gap-1 rounded-full bg-indigo-600 px-2.5 py-1 text-xs font-medium text-white">
                {kwLabel(k)}
                <KindBadge kind={kwKind(k)} label={kwKindLabel(k)} t={t} />
                <button
                  type="button"
                  onClick={() => toggle(k)}
                  aria-label={t('findingsPicker.remove', { label: kwLabel(k) })}
                  className="text-indigo-200 transition hover:text-white"
                >
                  ×
                </button>
              </span>
            ))}
          </div>

          {/* WHAT EACH PICKED FAULT USUALLY MEANS.
              This used to appear only when the matcher had to FIND the fault — so an inspector who
              knew the name and tapped the chip got nothing, and one who typed "فيه رجة" got the
              causes and repairs. The knowledge is the same either way; only the route to it differed.
              Now every selection carries it, from the catalog the picker already fetched. */}
          {knowledgeForSelected.length > 0 && (
            <div className="mt-2 space-y-1.5 border-t border-indigo-200/70 pt-2">
              {knowledgeForSelected.map(({ keyword, causes, fixes }) => (
                <div key={`kn-${keyword}`} className="rounded-lg bg-white/80 px-2.5 py-2">
                  <p className="text-[11px] font-semibold text-slate-800">{kwLabel(keyword)}</p>
                  {causes.length > 0 && (
                    <p className="mt-0.5 text-[11px] leading-snug text-slate-600">
                      <span className="text-slate-400">{t('findingsAi.usuallyCausedBy')}</span> {causes.join(' · ')}
                    </p>
                  )}
                  {fixes.length > 0 && (
                    <p className="mt-0.5 text-[11px] leading-snug text-slate-600">
                      <span className="text-slate-400">{t('findingsAi.usuallyFixedBy')}</span>{' '}
                      {fixes.map((f) => (lang === 'ar' ? f.label_ar || f.label : f.label)).join(' · ')}
                    </p>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Categories — collapsed accordions. The header reports what's inside so a closed row is never a
          black box; the chips only render once it's open (or while a search is filtering). */}
      <div className="divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200 bg-white">
        {visibleCategories.map((cat) => {
          const open = isOpen(cat);
          const picked = pickedIn(cat);
          return (
            <div key={cat.key}>
              <button
                type="button"
                onClick={() => toggleCat(cat.key)}
                aria-expanded={open}
                className={`flex w-full items-center gap-2 px-3 py-2.5 text-start transition ${open ? 'bg-slate-50/80' : 'hover:bg-slate-50'}`}
              >
                <Icon.ChevronDown className={`h-4 w-4 shrink-0 text-slate-400 transition-transform ${open ? '' : '-rotate-90 rtl:rotate-90'}`} />
                <span className="min-w-0 truncate text-sm font-semibold text-slate-700">{catLabel(cat)}</span>
                {/* PLANNED WORK, NOT A DEFECT. Routine servicing sits in the same picker as the fault
                    categories, so without a marker an inspector reads "Oil Change" as something found
                    wrong with the car. The backend types these as kind=service from the catalog; this is
                    the same statement made visible at the point of selection (audit M9). */}
                {SERVICE_CATEGORIES.has(cat.key) && (
                  <span className="shrink-0 rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-700 ring-1 ring-inset ring-sky-200">
                    {t('findingsPicker.plannedService')}
                  </span>
                )}
                <span className="min-w-0 flex-1" />
                {picked > 0 && (
                  <span className="inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-indigo-600 px-1.5 text-[10px] font-bold text-white">
                    {picked}
                  </span>
                )}
                <span className="text-[11px] tabular-nums text-slate-400">{cat.keywords.length}</span>
              </button>
              {open && (
                <div className="flex flex-wrap gap-1.5 border-t border-slate-100 bg-white px-3 pb-3 pt-2.5">
                  {cat.keywords.map((k) => (
                    <Chip
                      key={k}
                      label={kwLabel(k)}
                      tone={kwTone(k)}
                      kind={kwKind(k)}
                      kindLabel={kwKindLabel(k)}
                      t={t}
                      active={has(k)}
                      locked={isLocked(k)}
                      lockedTitle={t('findingsPicker.alreadyReported')}
                      required={isRequired(k)}
                      requiredTitle={requiredNote || tf('findingsPicker.requiredNote', 'Required — decided before this step, and not removable here.')}
                      onClick={() => toggle(k)}
                    />
                  ))}
                </div>
              )}
            </div>
          );
        })}

        {visibleCategories.length === 0 && (
          <div className="space-y-2 px-1 py-3">
            <p className="text-center text-xs text-slate-400">
              {t('findingsPicker.noLiteralMatch', { query })}
            </p>
            {/* THE HANDOFF. The substring filter has given up; the ontology gets the same text before
                the inspector is pushed toward a custom tag. One box, two strategies — see
                [[FindingsAiSuggestion]] for why this is not a second search field. */}
            <FindingsAiSuggestion
              query={query}
              onAdd={(k) => { if (!has(k) && !isLocked(k)) onChange([...value, k]); }}
              isSelected={has}
              isLocked={isLocked}
              ticketId={ticketId}
              vehicleId={vehicleId}
              context={aiContext}
            />
          </div>
        )}
      </div>

      {/* Custom issue — captured as a tag like any other, so it stays searchable/reportable. */}
      <div className="border-t border-slate-100 pt-3">
        <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{t('findingsPicker.customTitle')}</p>

        {/* Already-reported custom notes (locked) — shown disabled so they read as covered. */}
        {lockedCustoms.length > 0 && (
          <div className="mb-2 flex flex-wrap gap-1.5">
            {lockedCustoms.map((tag) => (
              <Chip key={`locked-${tag}`} label={tag} locked lockedTitle={t('findingsPicker.alreadyReported')} />
            ))}
          </div>
        )}

        {/* Free-typed tags aren't echoed here — the pinned selection tray above already lists (and
            removes) every pick, preset or custom, so this stays a pure entry box. */}
        <div className="flex gap-2">
          <input
            value={custom}
            onChange={(e) => setCustom(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addCustom(); } }}
            placeholder={t('findingsPicker.customPlaceholder')}
            className="flex-1 rounded-xl border border-slate-300 px-3 py-2 text-sm text-slate-700 placeholder:text-slate-400 focus:border-indigo-400 focus:outline-none focus:ring-1 focus:ring-indigo-400"
          />
          <button
            type="button"
            onClick={addCustom}
            disabled={!custom.trim()}
            className="inline-flex items-center gap-1 rounded-xl border border-slate-300 px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:opacity-40"
          >
            <Icon.Plus className="h-4 w-4" /> {t('findingsPicker.add')}
          </button>
        </div>
      </div>
    </div>
  );
}
