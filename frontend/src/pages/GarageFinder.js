// GARAGE FINDER — "this car has this fault; who is best at it?", answered before a ticket exists.
//
// The same recommendation engine that runs at the assign step, reachable at the moment the question is
// actually asked: an inspector standing at the car, or a supervisor sizing up work that has not been
// filed yet. Nothing in the engine ever needed a ticket — only a model and a set of fault categories —
// so this page feeds it those directly and renders the identical report.
//
// The query is deliberately NOT auto-run on every chip tap. A recommendation that redraws while you are
// still describing the problem trains people to ignore it; the report appears when the question is
// complete, and stays put until the question changes.
//
// See [[garage-recommendation-engine]], [[reason-code-contract]].

import { useCallback, useMemo, useState } from 'react';
import api from '../api/client';
import useFetch from '../hooks/useFetch';
import { PageHeader } from '../components/ui/Misc';
import { SectionCard } from '../components/ui/Table';
import SearchSelect from '../components/ui/SearchSelect';
import Button from '../components/ui/Button';
import Icon from '../components/ui/Icon';
import { useI18n } from '../i18n/I18nContext';
import GarageRecommendations from '../components/workflow/GarageRecommendations';

// CONTRACT with App\Models\Maintenance::FAULT_SEVERITIES, and with the three grades the Decide step
// offers. A severity here escalates the fault's criticality tier exactly as a filed report would, so a
// preview cannot rank differently from the assign step it is previewing.
const SEVERITIES = [
  { value: 'critical', emoji: '🔴' },
  { value: 'moderate', emoji: '🟡' },
  { value: 'routine', emoji: '🟢' },
];

export default function GarageFinder() {
  const { t, lang } = useI18n();
  const gf = useCallback((k, v) => t(`garageFinder.${k}`, v), [t]);

  const fetcher = useCallback(async () => {
    const [vehicles, catalog] = await Promise.all([
      api.get('/Vehicle'),
      api.get('/maintenance-tickets/findings-catalog'),
    ]);
    return {
      vehicles: vehicles.data?.data || [],
      categories: catalog.data?.data?.categories || [],
    };
  }, []);
  const { data, loading, error } = useFetch(fetcher);

  // Memoised because a fresh `[]` each render would re-run every downstream useMemo forever.
  const vehicles = useMemo(() => data?.vehicles || [], [data]);
  const categories = useMemo(() => data?.categories || [], [data]);

  const [vehicleId, setVehicleId] = useState('');
  const [modelText, setModelText] = useState('');
  // category_key => { symptom, severity }. Presence in the map IS selection.
  const [picked, setPicked] = useState({});
  // The query the report is currently answering. Held separately from `picked` so the report does not
  // redraw underneath someone who is still choosing.
  const [asked, setAsked] = useState(null);

  const vehicle = useMemo(
    () => vehicles.find((v) => String(v.id) === String(vehicleId)) || null,
    [vehicles, vehicleId],
  );

  const vehicleOptions = useMemo(
    () => vehicles.map((v) => ({
      id: v.id,
      label: [v.plate_no || v.plate, v.make, v.model].filter(Boolean).join(' · '),
      sub: v.model || '',
    })),
    [vehicles],
  );

  // The model is what the engine actually scores on — from the chosen car, or typed directly when the
  // question is about a model rather than a specific vehicle.
  const model = vehicle?.model || modelText.trim();
  const pickedKeys = Object.keys(picked);
  const canAsk = model !== '' && pickedKeys.length > 0;

  const toggle = (key) => setPicked((cur) => {
    const next = { ...cur };
    if (next[key]) delete next[key];
    else next[key] = { symptom: '', severity: '' };
    return next;
  });

  const setFor = (key, patch) => setPicked((cur) => ({ ...cur, [key]: { ...cur[key], ...patch } }));

  const ask = () => setAsked({
    model,
    faults: pickedKeys,
    // Only the refinements that were actually given — an empty symptom must not overwrite the
    // category label the report would otherwise use.
    symptoms: Object.fromEntries(pickedKeys.filter((k) => picked[k].symptom).map((k) => [k, picked[k].symptom])),
    severities: Object.fromEntries(pickedKeys.filter((k) => picked[k].severity).map((k) => [k, picked[k].severity])),
  });

  const label = (c) => (lang === 'ar' && c.label_ar ? c.label_ar : c.label);

  return (
    <div className="space-y-5">
      <PageHeader title={gf('title')} subtitle={gf('subtitle')} />

      {error && (
        <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-600/20">{error}</div>
      )}

      {/* ── THE QUESTION ───────────────────────────────────────────────────────────────────────── */}
      <SectionCard title={gf('question.title')} subtitle={gf('question.subtitle')}>
        <div className="space-y-4 p-4">
          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">{gf('question.vehicle')}</span>
              <SearchSelect
                value={vehicleId}
                onChange={(v) => { setVehicleId(v); setModelText(''); }}
                options={vehicleOptions}
                placeholder={loading ? gf('loading') : gf('question.vehiclePlaceholder')}
              />
            </div>
            <div>
              <span className="mb-1 block text-sm font-medium text-slate-700">{gf('question.model')}</span>
              <input
                type="text"
                value={vehicle ? (vehicle.model || '') : modelText}
                onChange={(e) => { setModelText(e.target.value); setVehicleId(''); }}
                placeholder={gf('question.modelPlaceholder')}
                className="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm disabled:bg-slate-50 disabled:text-slate-500"
                disabled={!!vehicle}
              />
              <p className="mt-1 text-xs text-slate-400">{gf('question.modelHint')}</p>
            </div>
          </div>

          {/* ── THE FAULTS ───────────────────────────────────────────────────────────────────── */}
          <div>
            <span className="mb-1.5 block text-sm font-medium text-slate-700">{gf('question.faults')}</span>
            <div className="flex flex-wrap gap-1.5">
              {categories.map((c) => {
                const on = !!picked[c.key];
                return (
                  <button
                    key={c.key}
                    type="button"
                    onClick={() => toggle(c.key)}
                    className={`rounded-lg px-2.5 py-1.5 text-sm font-medium transition ${
                      on ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 ring-1 ring-inset ring-slate-300 hover:bg-slate-50'}`}
                  >
                    {label(c)}
                  </button>
                );
              })}
            </div>
            {categories.length === 0 && !loading && (
              <p className="mt-1 text-xs text-slate-400">{gf('question.noCategories')}</p>
            )}
          </div>

          {/* Per-fault refinement. Only for the faults actually chosen — an inspector describing one
              symptom should never be shown twelve empty rows. */}
          {pickedKeys.length > 0 && (
            <div className="space-y-2 rounded-xl bg-slate-50 p-3 ring-1 ring-inset ring-slate-200">
              <p className="text-[11px] font-bold uppercase tracking-wide text-slate-400">{gf('refine.title')}</p>
              {pickedKeys.map((key) => {
                const cat = categories.find((c) => c.key === key);
                if (!cat) return null;
                return (
                  <div key={key} className="grid gap-2 sm:grid-cols-[10rem,1fr,auto] sm:items-center">
                    <span className="text-sm font-semibold text-slate-700">{label(cat)}</span>
                    <div className="flex flex-wrap gap-1">
                      {(cat.keywords || []).map((kw) => {
                        const on = picked[key].symptom === kw;
                        return (
                          <button
                            key={kw}
                            type="button"
                            onClick={() => setFor(key, { symptom: on ? '' : kw })}
                            className={`rounded-md px-2 py-0.5 text-xs transition ${
                              on ? 'bg-slate-800 text-white' : 'bg-white text-slate-500 ring-1 ring-inset ring-slate-300 hover:bg-slate-100'}`}
                          >
                            {kw}
                          </button>
                        );
                      })}
                    </div>
                    <div className="flex gap-1">
                      {SEVERITIES.map((s) => {
                        const on = picked[key].severity === s.value;
                        return (
                          <button
                            key={s.value}
                            type="button"
                            title={gf('refine.severityHint')}
                            onClick={() => setFor(key, { severity: on ? '' : s.value })}
                            className={`flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium transition ${
                              on ? 'bg-slate-800 text-white' : 'bg-white text-slate-500 ring-1 ring-inset ring-slate-300 hover:bg-slate-100'}`}
                          >
                            <span aria-hidden className="leading-none">{s.emoji}</span>
                            {t(`workflow.faultSeverity.${s.value}`)}
                          </button>
                        );
                      })}
                    </div>
                  </div>
                );
              })}
            </div>
          )}

          <div className="flex items-center gap-3">
            <Button variant="primary" disabled={!canAsk} onClick={ask}>
              <Icon.Wrench className="h-4 w-4" /> {gf('question.submit')}
            </Button>
            {!canAsk && <span className="text-xs text-slate-400">{gf('question.needBoth')}</span>}
          </div>
        </div>
      </SectionCard>

      {/* ── THE ANSWER ─────────────────────────────────────────────────────────────────────────── */}
      {asked && (
        <SectionCard
          title={gf('report.title', { model: asked.model })}
          subtitle={gf('report.subtitle')}
        >
          <div className="p-4">
            {/* Read-only: this page answers a question, it does not dispatch a car. Choosing a garage
                stays at the assign step, where the decision is actually recorded against a ticket. */}
            <GarageRecommendations query={asked} selectedVendorId={null} onPick={() => {}} chrome={false} />
            <p className="mt-3 text-xs text-slate-400">{gf('report.readOnly')}</p>
          </div>
        </SectionCard>
      )}
    </div>
  );
}
