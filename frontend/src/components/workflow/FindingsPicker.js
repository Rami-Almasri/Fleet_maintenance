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

import { useMemo, useState } from 'react';
import Icon from '../ui/Icon';
import { useI18n } from '../../i18n/I18nContext';

// Degrade gracefully if the catalog request fails: a small built-in set keeps the modal usable
// offline. The real, comprehensive library lives in the backend config.
const FALLBACK = [
  { key: 'common', label: 'Common issues', keywords: ['Brakes', 'Engine noise', 'A/C', 'Suspension', 'Tyres', 'Warning light', 'Body / dent', 'Electrical', 'Gearbox', 'Steering'] },
];

// Risk tone → dot colour (matches Badge tones used in the Keyword Risk Library).
const RISK_DOT = { red: 'bg-red-500', amber: 'bg-amber-500', green: 'bg-emerald-500' };

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

function Chip({ label, tone, active, locked, lockedTitle, onClick }) {
  const dot = tone && RISK_DOT[tone] && !active ? <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${RISK_DOT[tone]}`} /> : null;
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
    </button>
  );
}

export default function FindingsPicker({ catalog, keywordMeta = {}, value = [], onChange, locked = [], onSiteOnly = false, onSiteKeywords = [], suggested = [], statusConditions = [] }) {
  const { t, lang } = useI18n();
  const [custom, setCustom] = useState('');
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

  // Already on the ticket → locked. Matched case-insensitively so "Engine noise" and "engine noise"
  // are treated as the same issue.
  const lockedSet = useMemo(
    () => new Set(locked.map((l) => String(l).toLowerCase())),
    [locked],
  );
  const isLocked = (k) => lockedSet.has(k.toLowerCase());

  // Every keyword the catalog knows about — used to split the selection into "from the library"
  // vs "custom", so custom tags render in their own removable row.
  const known = useMemo(
    () => new Set(allCategories.flatMap((c) => c.keywords.map((k) => k.toLowerCase()))),
    [allCategories],
  );
  const customTags = useMemo(
    () => value.filter((v) => !known.has(v.toLowerCase())),
    [value, known],
  );
  // Locked findings that aren't in the catalog (e.g. an inspector's custom note) — surfaced in their
  // own row so the Garage user sees the full "already reported" picture, not just the preset chips.
  const lockedCustoms = useMemo(
    () => locked.filter((l) => !known.has(String(l).toLowerCase())),
    [locked, known],
  );

  const has = (k) => value.some((v) => v.toLowerCase() === k.toLowerCase());
  const toggle = (k) => {
    if (isLocked(k)) return; // already reported — never selectable
    onChange(has(k) ? value.filter((v) => v.toLowerCase() !== k.toLowerCase()) : [...value, k]);
  };

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

      {categories.map((cat) => (
        <div key={cat.key}>
          <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{catLabel(cat)}</p>
          <div className="flex flex-wrap gap-1.5">
            {cat.keywords.map((k) => (
              <Chip
                key={k}
                label={kwLabel(k)}
                tone={kwTone(k)}
                active={has(k)}
                locked={isLocked(k)}
                lockedTitle={t('findingsPicker.alreadyReported')}
                onClick={() => toggle(k)}
              />
            ))}
          </div>
        </div>
      ))}

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

        {customTags.length > 0 && (
          <div className="mb-2 flex flex-wrap gap-1.5">
            {customTags.map((tag) => (
              <span key={tag} className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-3 py-1 text-xs font-medium text-amber-700 ring-1 ring-amber-200">
                {tag}
                <button type="button" onClick={() => toggle(tag)} className="text-amber-500 hover:text-amber-700" aria-label={t('findingsPicker.remove', { label: tag })}>×</button>
              </span>
            ))}
          </div>
        )}
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
