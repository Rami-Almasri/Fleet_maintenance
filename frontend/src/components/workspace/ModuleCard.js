// A large module tile on the App Launcher. Clicking opens the module's Overview.
// Hovering (or keyboard-focusing) reveals a mega-menu of the module's sections —
// the Odoo-style "peek inside the app" affordance. Everything is permission-
// filtered via the registry, so a user only ever sees modules/sections they can
// reach.

import { Link } from 'react-router-dom';
import Icon from '../ui/Icon';
import { usePermissions } from '../../hooks/usePermissions';
import { visibleSections, OVERVIEW_ROUTE, moduleNameKey, moduleTaglineKey, sectionNameKey } from '../../config/moduleRegistry';
import { useI18n } from '../../i18n/I18nContext';

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
  const { t, tf, tp } = useI18n();
  const ModuleIcon = module.icon;
  // A direct-link tile (e.g. Dashboard) opens a single page straight away — no
  // sections, so no mega-menu and no "N sections" footer.
  const isLink = Boolean(module.link);
  const sections = isLink ? [] : visibleSections(module, can, roles);
  const liveCount = sections.filter((s) => s.status !== 'soon').length;

  return (
    // `relative z-0` gives a base layer; on hover/focus the whole card group jumps
    // to z-40 so its dropdown paints ABOVE the cards in the row below (which would
    // otherwise cover it, since later grid items paint after earlier ones).
    <div className="group relative z-0 hover:z-40 focus-within:z-40">
      <Link
        to={module.link || OVERVIEW_ROUTE(module.id)}
        data-module-id={module.id}
        className="hover-lift relative flex h-full flex-col rounded-2xl border border-slate-200/60 bg-white p-6 shadow-soft outline-none transition duration-200 active:scale-[0.99] focus-visible:ring-2 focus-visible:ring-indigo-500/60 focus-visible:ring-offset-2 focus-visible:ring-offset-[rgb(var(--bg))]"
      >
        <div className="flex items-start justify-between">
          <span className={`flex h-14 w-14 items-center justify-center rounded-2xl transition-transform duration-200 group-hover:scale-105 ${BUBBLE[module.tone] || BUBBLE.slate}`}>
            <ModuleIcon className="h-7 w-7" />
          </span>
          {/* Points along the reading direction, so it flips under RTL. */}
          <Icon.ArrowRight className="h-5 w-5 -translate-x-1 text-slate-300 opacity-0 transition-all duration-200 group-hover:translate-x-0 group-hover:text-indigo-500 group-hover:opacity-100 rtl:translate-x-1 rtl:-scale-x-100 rtl:group-hover:translate-x-0" />
        </div>
        <h2 className="mt-4 font-display text-lg font-bold text-slate-900">{tf(moduleNameKey(module), module.name)}</h2>
        <p className="mt-1 text-sm text-slate-500">{tf(moduleTaglineKey(module), module.tagline)}</p>
        {!isLink && (
          <p className="mt-4 text-xs font-medium text-slate-400">
            {tp('modules.sectionCount', liveCount)}
          </p>
        )}
      </Link>

      {/* Mega-menu — revealed on hover / keyboard focus within the card group.
          The outer wrapper's `pt-2` is a transparent hover-bridge: it fills the
          gap below the card so the cursor never leaves the group on its way in. */}
      {sections.length > 0 && (
        <div className="invisible absolute inset-x-0 top-full z-20 translate-y-1 pt-2 opacity-0 transition-all duration-150 group-hover:visible group-hover:translate-y-0 group-hover:opacity-100 group-focus-within:visible group-focus-within:translate-y-0 group-focus-within:opacity-100">
          <div className="rounded-2xl border border-slate-200/70 bg-white p-2 shadow-lg">
            <div className="grid grid-cols-1 gap-0.5 sm:grid-cols-2">
              {sections.map((s) => {
                const SIcon = s.icon;
                if (s.status === 'soon') {
                  return (
                    <span key={s.name} className="flex cursor-not-allowed items-center gap-2 rounded-lg px-3 py-2 text-sm text-slate-300">
                      {SIcon && <SIcon className="h-4 w-4" />}
                      <span className="flex-1 truncate">{tf(sectionNameKey(module, s), s.name)}</span>
                      <span className="rounded bg-slate-100 px-1 text-[9px] font-bold uppercase text-slate-400">{t('modules.soon')}</span>
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
                    <span className="truncate">{tf(sectionNameKey(module, s), s.name)}</span>
                  </Link>
                );
              })}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
