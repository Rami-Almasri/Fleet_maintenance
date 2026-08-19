// "I HAVE THIS FAULT — WHO SHOULD FIX IT?", asked from the garages page itself.
//
// The scorecard below answers "who is good at tyres" from the record alone. That is the right
// question when you are reviewing suppliers and the wrong one when you are holding a car: a fault
// on a Yukon is not the same routing problem as the same fault on a Patrol, and the record cannot
// see severity, queue or cost.
//
// So this panel does NOT re-derive an answer. It asks the SAME engine the assign step asks and
// renders the SAME report component, with the model left optional. One engine, one report, three
// places to reach it — a second "best garage" implementation that disagreed with the assign step by
// a rank would destroy trust in both.
//
// See [[garage-recommendation-engine]], [[garage-finder-page]].

import { useCallback, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api/client';
import useFetch from '../../hooks/useFetch';
import { SectionCard } from '../ui/Table';
import Button from '../ui/Button';
import Icon from '../ui/Icon';
import GarageRecommendations from '../workflow/GarageRecommendations';
import { useI18n } from '../../i18n/I18nContext';

export default function FaultGarageFinder() {
  const { t, lang, isRTL } = useI18n();
  const g = (k, v) => t(`garages.${k}`, v);

  const fetcher = useCallback(async () => {
    const { data } = await api.get('/maintenance-tickets/findings-catalog');
    return data?.data?.categories || [];
  }, []);
  const { data } = useFetch(fetcher);
  const categories = useMemo(() => data || [], [data]);

  const [picked, setPicked] = useState([]);
  const [model, setModel] = useState('');
  // Held separately from `picked` so the report does not redraw under somebody still choosing.
  const [asked, setAsked] = useState(null);

  const label = (c) => (lang === 'ar' && c.label_ar ? c.label_ar : c.label);
  const toggle = (key) => setPicked((cur) => (cur.includes(key) ? cur.filter((k) => k !== key) : [...cur, key]));

  const ask = () => setAsked({ faults: picked, model: model.trim() || undefined });

  return (
    <SectionCard
      title={g('finder.title')}
      subtitle={g('finder.subtitle')}
      actions={
        <Link to="/garages?tab=finder" className="text-xs font-medium text-indigo-600 hover:text-indigo-700">
          {g('finder.full')} {isRTL ? '←' : '→'}
        </Link>
      }
      bodyClass="p-5"
    >
      <div className="flex flex-wrap gap-1.5">
        {categories.map((c) => {
          const on = picked.includes(c.key);
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

      <div className="mt-3 flex flex-wrap items-center gap-3">
        <input
          type="text"
          value={model}
          onChange={(e) => setModel(e.target.value)}
          placeholder={g('finder.modelPlaceholder')}
          className="w-52 rounded-xl border border-slate-300 px-3 py-2 text-sm"
        />
        <Button variant="primary" disabled={picked.length === 0} onClick={ask}>
          <Icon.Wrench className="h-4 w-4" /> {g('finder.submit')}
        </Button>
        <span className="text-xs text-slate-400">
          {picked.length === 0 ? g('finder.needFault') : g('finder.modelHint')}
        </span>
      </div>

      {asked && (
        <div className="mt-5 border-t border-slate-100 pt-4">
          <GarageRecommendations query={asked} selectedVendorId={null} onPick={() => {}} chrome={false} />
          <p className="mt-3 text-xs text-slate-400">{g('finder.readOnly')}</p>
        </div>
      )}
    </SectionCard>
  );
}
