// A large module tile on the App Launcher. Clicking opens the module's Overview.
// Hovering (or keyboard-focusing) reveals a mega-menu of the module's sections —
// the Odoo-style "peek inside the app" affordance. Everything is permission-
// filtered via the registry, so a user only ever sees modules/sections they can
// reach.

import { Link } from 'react-router-dom';
import Icon from '../ui/Icon';
import { usePermissions } from '../../hooks/usePermissions';
import { visibleSections, OVERVIEW_ROUTE } from '../../config/moduleRegistry';

const BUBBLE = {
  indigo:  'bg-indigo-100 text-indigo-600',
  amber:   'bg-amber-100 text-amber-600',
  violet:  'bg-violet-100 text-violet-600',
  emerald: 'bg-emerald-100 text-emerald-600',
  blue:    'bg-blue-100 text-blue-600',
  slate:   'bg-slate-100 text-slate-600',
};

export default function ModuleCard({ module }) {
  const { can, roles } = usePermissions();
  const ModuleIcon = module.icon;
  const sections = visibleSections(module, can, roles);
  const liveCount = sections.filter((s) => s.status !== 'soon').length;

  return (
    <div className="group relative">
      <Link
        to={OVERVIEW_ROUTE(module.id)}
        data-module-id={module.id}
        className="hover-lift relative flex h-full flex-col rounded-2xl border border-slate-200/60 bg-white p-6 shadow-soft outline-none transition duration-200 active:scale-[0.99] focus-visible:ring-2 focus-visible:ring-indigo-500/60 focus-visible:ring-offset-2 focus-visible:ring-offset-[rgb(var(--bg))]"
      >
        <div className="flex items-start justify-between">
          <span className={`flex h-14 w-14 items-center justify-center rounded-2xl transition-transform duration-200 group-hover:scale-105 ${BUBBLE[module.tone] || BUBBLE.slate}`}>
            <ModuleIcon className="h-7 w-7" />
          </span>
          <Icon.ArrowRight className="h-5 w-5 -translate-x-1 text-slate-300 opacity-0 transition-all duration-200 group-hover:translate-x-0 group-hover:text-indigo-500 group-hover:opacity-100" />
        </div>
        <h2 className="mt-4 font-display text-lg font-bold text-slate-900">{module.name}</h2>
        <p className="mt-1 text-sm text-slate-500">{module.tagline}</p>
        <p className="mt-4 text-xs font-medium text-slate-400">
          {liveCount} {liveCount === 1 ? 'section' : 'sections'}
        </p>
      </Link>

      {/* Mega-menu — revealed on hover / keyboard focus within the card group. */}
      {sections.length > 0 && (
        <div className="invisible absolute inset-x-0 top-full z-20 mt-2 translate-y-1 rounded-2xl border border-slate-200/70 bg-white p-2 opacity-0 shadow-lg transition-all duration-150 group-hover:visible group-hover:translate-y-0 group-hover:opacity-100 group-focus-within:visible group-focus-within:translate-y-0 group-focus-within:opacity-100">
          <div className="grid grid-cols-1 gap-0.5 sm:grid-cols-2">
            {sections.map((s) => {
              const SIcon = s.icon;
              if (s.status === 'soon') {
                return (
                  <span key={s.name} className="flex cursor-not-allowed items-center gap-2 rounded-lg px-3 py-2 text-sm text-slate-300">
                    {SIcon && <SIcon className="h-4 w-4" />}
                    <span className="flex-1 truncate">{s.name}</span>
                    <span className="rounded bg-slate-100 px-1 text-[9px] font-bold uppercase text-slate-400">Soon</span>
                  </span>
                );
              }
              return (
                <Link
                  key={s.name}
                  to={s.route}
                  className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-slate-600 outline-none transition hover:bg-slate-50 hover:text-slate-900 focus-visible:ring-2 focus-visible:ring-indigo-500/50"
                >
                  {SIcon && <SIcon className="h-4 w-4 text-slate-400" />}
                  <span className="truncate">{s.name}</span>
                </Link>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}
