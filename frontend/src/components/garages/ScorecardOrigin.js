// WHERE EVERY NUMBER ON THIS PAGE CAME FROM.
//
// This page grades suppliers. A grade somebody cannot audit is a grade they will dispute the first
// time it goes against them, and rightly — so the measure, the table it came from, the method and
// the known limitation all ship WITH the report rather than living in a document nobody opens.
//
// It also carries the one thing a scorecard is most tempted to hide: the measure we deliberately do
// NOT score on. On-time returns look like the obvious quality metric and are unusable here, and
// saying so out loud is the difference between an honest page and a confident one.
//
// See [[traceability-visibility-requirement]].

import { useState } from 'react';
import Icon from '../ui/Icon';
import { num } from '../../lib/format';
import { useI18n } from '../../i18n/I18nContext';

export default function ScorecardOrigin({ provenance }) {
  const { t } = useI18n();
  const g = (k, v) => t(`garages.${k}`, v);
  const [open, setOpen] = useState(false);

  if (!provenance) return null;

  return (
    <div className="rounded-2xl border border-slate-200/60 bg-white shadow-soft">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="flex w-full items-center justify-between gap-3 px-5 py-3.5 text-start"
        aria-expanded={open}
      >
        <span className="flex items-center gap-2">
          <Icon.Info className="h-4 w-4 text-slate-400" />
          <span className="text-sm font-semibold text-slate-800">{g('origin.title')}</span>
          <span className="text-xs text-slate-400">
            {g('origin.summary', { graded: num(provenance.pairs_graded), total: num(provenance.pairs_total) })}
          </span>
        </span>
        <Icon.ChevronDown className={`h-4 w-4 shrink-0 text-slate-400 transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>

      {open && (
        <div className="space-y-4 border-t border-slate-100 px-5 py-4">
          <p className="rounded-xl bg-slate-50 px-3 py-2.5 text-xs leading-relaxed text-slate-600 ring-1 ring-inset ring-slate-200">
            <span className="font-semibold text-slate-700">{g('origin.fairTitle')} </span>
            {provenance.fair_comparison}
          </p>

          <div className="overflow-x-auto">
            <table className="w-full min-w-[34rem] text-xs">
              <thead>
                <tr className="border-b border-slate-100 text-[11px] uppercase tracking-wide text-slate-400">
                  <th className="py-2 text-start font-semibold">{g('origin.col.measure')}</th>
                  <th className="py-2 text-start font-semibold">{g('origin.col.source')}</th>
                  <th className="py-2 text-start font-semibold">{g('origin.col.method')}</th>
                </tr>
              </thead>
              <tbody>
                {(provenance.sources || []).map((s) => (
                  <tr key={s.measure} className="border-b border-slate-50 align-top last:border-0">
                    <td className="py-2 pe-3 font-medium text-slate-800">{s.measure}</td>
                    <td className="py-2 pe-3 font-mono text-[11px] text-slate-500">{s.table}</td>
                    <td className="py-2 leading-relaxed text-slate-600">
                      {s.method}
                      {s.note && <span className="mt-0.5 block text-slate-400">{s.note}</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <dl className="grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
            {[
              [g('origin.domainFloor'), g('origin.repairs', { n: provenance.domain_floor })],
              [g('origin.garageFloor'), g('origin.repairs', { n: provenance.garage_floor })],
              [g('origin.material'), g('origin.points', { n: provenance.material_pts })],
              [g('origin.vocabulary'), provenance.classifier_version],
            ].map(([k, v]) => (
              <div key={k} className="rounded-lg bg-slate-50 px-3 py-2">
                <dt className="text-[11px] text-slate-400">{k}</dt>
                <dd className="mt-0.5 font-semibold text-slate-700">{v}</dd>
              </div>
            ))}
          </dl>
        </div>
      )}
    </div>
  );
}
