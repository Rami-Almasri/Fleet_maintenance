// Pick a part from the catalog — the control that replaced a free-text box.
//
// The box let every inspector type his own wording, so "Brake pads", "Front brake pads (set)" and
// "break" were three unrelated strings for one part and nothing could be counted or joined. This
// stores a REFERENCE (component_catalog_id); the text beside it is a label, not the identity.
//
// Search runs locally over the whole catalog. The list endpoint returns every row in one call
// precisely so a picker can filter as you type without a request per keystroke — 132 short rows is
// a few tens of kilobytes, and a round trip per character is a worse trade at any size this fleet
// will reach.
//
// It searches everything a person might type: English name, Arabic name, SKU, and the aliases —
// which deliberately include SYMPTOM wording ("battery not charging" → Alternator). That generosity
// is safe HERE because a human then picks from the results. It is not safe for automatic linking,
// which is why PartCatalogMatcher ignores aliases entirely. Generous where a human decides, strict
// where nobody does.

import { useEffect, useMemo, useRef, useState } from 'react';
import { useI18n } from '../../i18n/I18nContext';
import Icon from '../ui/Icon';

const MAX_RESULTS = 8;

/** Lowercase, strip punctuation, collapse spaces — mirrors the backend's normalisation closely enough. */
const norm = (s) => (s || '').toString().toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim();

/**
 * @param {object|null} value    { component_catalog_id, part_name, part_number } — the chosen part
 * @param {function}    onChange receives the same shape (or null when cleared)
 * @param {Array}       catalog  rows from GET /parts-catalog
 * @param {boolean}     loading  catalog still being fetched
 * @param {string}      legacyText  free text from before the picker, kept until reviewed
 */
export default function CatalogPartPicker({ value, onChange, catalog = [], loading = false, legacyText = '' }) {
  const { t, lang } = useI18n();
  const [query, setQuery] = useState('');
  const [open, setOpen] = useState(false);
  const [cursor, setCursor] = useState(0);
  const boxRef = useRef(null);

  const selected = value?.component_catalog_id ? value : null;
  // A line written before the picker existed: it has words but no reference. It is NOT discarded —
  // the inspector's wording is evidence — but it is shown as needing a decision.
  const unlinked = !selected && !!legacyText;

  useEffect(() => {
    const onDocClick = (e) => {
      if (boxRef.current && !boxRef.current.contains(e.target)) setOpen(false);
    };
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, []);

  const results = useMemo(() => {
    const q = norm(query);
    if (!q) return catalog.slice(0, MAX_RESULTS);

    const scored = [];
    for (const p of catalog) {
      const name = norm(p.name);
      const nameAr = norm(p.name_ar);
      const sku = norm(p.default_part_number);
      const aliases = (p.aliases || []).map(norm);

      // Rank so the obvious answer is first: a name that starts with what was typed beats one that
      // merely contains it, and both beat an alias match.
      let score = null;
      if (name === q || nameAr === q || sku === q) score = 0;
      else if (name.startsWith(q) || nameAr.startsWith(q)) score = 1;
      else if (name.includes(q) || nameAr.includes(q) || sku.includes(q)) score = 2;
      else if (aliases.some((a) => a.includes(q))) score = 3;

      if (score !== null) scored.push({ p, score });
    }

    return scored.sort((a, b) => a.score - b.score || a.p.name.localeCompare(b.p.name))
      .slice(0, MAX_RESULTS)
      .map((s) => s.p);
  }, [query, catalog]);

  const label = (p) => (lang === 'ar' && p.name_ar ? p.name_ar : p.name);
  const secondary = (p) => {
    const other = lang === 'ar' ? p.name : p.name_ar;
    return [other && other !== label(p) ? other : null, p.default_part_number].filter(Boolean).join(' · ');
  };

  const choose = (p) => {
    onChange({
      component_catalog_id: p.id,
      part_name: p.name,               // stored as the label; identity is the id
      part_number: p.default_part_number || null,
    });
    setQuery('');
    setOpen(false);
  };

  const clear = () => {
    onChange(null);
    setQuery('');
    setOpen(true);
  };

  const onKeyDown = (e) => {
    if (!open && (e.key === 'ArrowDown' || e.key === 'Enter')) { setOpen(true); return; }
    if (e.key === 'ArrowDown') { e.preventDefault(); setCursor((c) => Math.min(c + 1, results.length - 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setCursor((c) => Math.max(c - 1, 0)); }
    else if (e.key === 'Enter') { e.preventDefault(); if (results[cursor]) choose(results[cursor]); }
    else if (e.key === 'Escape') { setOpen(false); }
  };

  // ── chosen ────────────────────────────────────────────────────────────────────────────────────
  if (selected) {
    return (
      <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50/60 px-2.5 py-1.5">
        <Icon.Check className="h-4 w-4 shrink-0 text-emerald-600" />
        <span className="min-w-0 flex-1 truncate text-sm text-slate-800" dir="auto">
          {selected.part_name}
          {selected.part_number && (
            <span className="ms-1.5 text-xs text-slate-500">{selected.part_number}</span>
          )}
        </span>
        <button
          type="button"
          onClick={clear}
          className="rounded px-1.5 py-0.5 text-xs font-medium text-slate-500 transition hover:bg-white hover:text-slate-700"
        >
          {t('workflow.requiredParts.change')}
        </button>
      </div>
    );
  }

  // ── searching ─────────────────────────────────────────────────────────────────────────────────
  return (
    <div ref={boxRef} className="relative">
      {/* A line carried over from before the picker. Shown, not silently dropped — and clearly
          marked as still needing a decision, because it is not yet a real part reference. */}
      {unlinked && (
        <div className="mb-1 flex items-start gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-2 py-1.5">
          <Icon.Alert className="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-600" />
          <div className="min-w-0 text-xs">
            <div className="font-medium text-amber-800" dir="auto">“{legacyText}”</div>
            <div className="text-amber-700">{t('workflow.requiredParts.unlinkedHint')}</div>
          </div>
        </div>
      )}

      <div className="relative">
        <input
          type="text"
          role="combobox"
          aria-expanded={open}
          aria-controls="catalog-part-options"
          autoComplete="off"
          value={query}
          disabled={loading}
          onChange={(e) => { setQuery(e.target.value); setOpen(true); setCursor(0); }}
          onFocus={() => setOpen(true)}
          onKeyDown={onKeyDown}
          placeholder={loading ? t('workflow.requiredParts.loading') : t('workflow.requiredParts.searchPlaceholder')}
          className="w-full rounded-lg border-slate-200 pe-8 text-sm placeholder:text-slate-400 focus:border-sky-400 focus:ring-sky-400"
        />
        <Icon.Search className="pointer-events-none absolute end-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
      </div>

      {open && !loading && (
        <ul
          id="catalog-part-options"
          role="listbox"
          className="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg"
        >
          {results.length === 0 && (
            <li className="px-3 py-2 text-xs text-slate-500">
              {/* The catalog is the vocabulary. If the part is genuinely missing it is added there,
                  not typed here — that is the whole point of the change. */}
              {t('workflow.requiredParts.noMatch')}
            </li>
          )}

          {results.map((p, i) => (
            <li key={p.id} role="option" aria-selected={i === cursor}>
              <button
                type="button"
                onMouseEnter={() => setCursor(i)}
                onClick={() => choose(p)}
                className={`block w-full px-3 py-1.5 text-start ${i === cursor ? 'bg-sky-50' : ''}`}
              >
                <span className="block truncate text-sm text-slate-800" dir="auto">{label(p)}</span>
                {secondary(p) && (
                  <span className="block truncate text-xs text-slate-500" dir="auto">{secondary(p)}</span>
                )}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
