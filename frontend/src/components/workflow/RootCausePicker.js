// Symptom → Root-Cause picker — the structured "diagnostic" second step that sits under the
// FindingsPicker in the maintenance workflow. For each symptom the inspector/mechanic tapped, this
// surfaces the curated short-list of probable root causes (served in the findings catalog under
// `fault_causes`, keyed by normalised symptom) and makes them pick ONE. If the real cause isn't
// listed they can type a custom one — which is sent to the backend with no id, recorded as 'pending'
// and flagged for an admin to fold into the master list.
//
// Shape contract:
//   symptoms : string[]                          — the currently-selected finding tags (from FindingsPicker)
//   catalog  : { [normalisedSymptom]: Cause[] }  — Cause = { id, root_cause, description? }
//   value    : { [symptom]: { root_cause, root_cause_id } }  — the chosen cause per symptom
//   onChange : (nextValue) => void               — receives the full next value object
//
// The chosen { root_cause, root_cause_id } is exactly what rides to the API (a null id == custom).

import { useMemo, useState } from 'react';
import Icon from '../ui/Icon';

// Mirror App\Models\FaultCause::normalizeKey — lowercase + collapse whitespace, so the symptom the
// user picked resolves to the same catalog bucket the backend seeded it under.
export function normalizeSymptom(s) {
  return String(s || '').trim().replace(/\s+/g, ' ').toLowerCase();
}

// Causes the catalog knows for a symptom (empty array if none / unknown symptom).
export function causesFor(catalog, symptom) {
  return (catalog && catalog[normalizeSymptom(symptom)]) || [];
}

// A diagnosis is "complete" when every symptom that HAS a preset cause-list has a cause chosen.
// Custom symptoms with no preset list can't force a pick, so they don't block submission.
export function rootCausesComplete(symptoms, catalog, value) {
  return (symptoms || []).every((s) => {
    if (causesFor(catalog, s).length === 0) return true; // no menu to choose from
    return Boolean(value?.[s]?.root_cause);
  });
}

function CauseChip({ label, active, onClick, title }) {
  return (
    <button
      type="button"
      onClick={onClick}
      title={title || undefined}
      className={`rounded-full px-3 py-1 text-xs font-medium ring-1 transition ${
        active ? 'bg-indigo-600 text-white ring-indigo-600' : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'
      }`}
    >
      {label}
    </button>
  );
}

function SymptomRow({ symptom, causes, choice, onPick, onCustom, onClear }) {
  const [custom, setCustom] = useState('');
  const hasPreset = causes.length > 0;
  // A custom cause = a chosen root_cause with no id (not from the preset list).
  const isCustom = Boolean(choice?.root_cause) && !choice?.root_cause_id;

  const addCustom = () => {
    const text = custom.trim();
    if (!text) return;
    onCustom(text);
    setCustom('');
  };

  return (
    <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
      <p className="mb-2 flex items-center gap-1.5 text-sm font-semibold text-slate-700">
        <Icon.Wrench className="h-3.5 w-3.5 text-slate-400" />
        {symptom}
        {hasPreset && !choice?.root_cause && (
          <span className="ms-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-700">
            Pick a cause
          </span>
        )}
      </p>

      {hasPreset ? (
        <div className="flex flex-wrap gap-1.5">
          {causes.map((c) => (
            <CauseChip
              key={c.id}
              label={c.root_cause}
              title={c.description}
              active={choice?.root_cause_id === c.id}
              onClick={() => (choice?.root_cause_id === c.id ? onClear() : onPick(c))}
            />
          ))}
        </div>
      ) : (
        <p className="mb-2 text-[11px] text-slate-400">No preset causes for this symptom — add one below.</p>
      )}

      {/* Custom cause — sent with no id, flagged for admin review on the server. */}
      <div className="mt-2 border-t border-slate-200/70 pt-2">
        {isCustom ? (
          <div className="flex flex-wrap items-center gap-2">
            <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-3 py-1 text-xs font-medium text-amber-700 ring-1 ring-amber-200">
              {choice.root_cause}
              <button type="button" onClick={onClear} className="text-amber-500 hover:text-amber-700" aria-label="Remove custom cause">×</button>
            </span>
            <span className="text-[11px] text-amber-600">Custom — will be sent for review</span>
          </div>
        ) : (
          <div className="flex gap-2">
            <input
              value={custom}
              onChange={(e) => setCustom(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addCustom(); } }}
              placeholder="Other cause (not listed)…"
              className="flex-1 rounded-xl border border-slate-300 px-3 py-1.5 text-sm text-slate-700 placeholder:text-slate-400 focus:border-indigo-400 focus:outline-none focus:ring-1 focus:ring-indigo-400"
            />
            <button
              type="button"
              onClick={addCustom}
              disabled={!custom.trim()}
              className="inline-flex items-center gap-1 rounded-xl border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:opacity-40"
            >
              <Icon.Plus className="h-4 w-4" /> Add
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

export default function RootCausePicker({ symptoms = [], catalog = {}, value = {}, onChange }) {
  // Only symptoms actually selected get a row; order follows the symptom selection.
  const rows = useMemo(() => symptoms.filter(Boolean), [symptoms]);

  if (rows.length === 0) {
    return (
      <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-400 ring-1 ring-inset ring-slate-200/70">
        Pick a symptom above and its probable root causes will appear here for diagnosis.
      </p>
    );
  }

  const set = (symptom, next) => {
    const copy = { ...value };
    if (next === null) delete copy[symptom];
    else copy[symptom] = next;
    onChange(copy);
  };

  return (
    <div className="space-y-2.5">
      {rows.map((s) => (
        <SymptomRow
          key={s}
          symptom={s}
          causes={causesFor(catalog, s)}
          choice={value[s]}
          onPick={(c) => set(s, { root_cause: c.root_cause, root_cause_id: c.id })}
          onCustom={(text) => set(s, { root_cause: text, root_cause_id: null })}
          onClear={() => set(s, null)}
        />
      ))}
    </div>
  );
}
