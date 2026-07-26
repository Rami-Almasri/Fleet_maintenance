// The persistent per-module top navigation. Rendered by AppLayout under the app
// header on the module Overview AND every section page that belongs to the
// module, so the user always knows which app they're in and can move between its
// sections without "leaving" it. Tabs are permission-filtered via the registry.

import { Link, useLocation } from 'react-router-dom';
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

export default function ModuleTabBar({ module }) {
  const { can, roles } = usePermissions();
  const { pathname } = useLocation();
  const ModuleIcon = module.icon;

  const tabs = [
    { name: 'Overview', route: OVERVIEW_ROUTE(module.id), icon: module.icon },
    ...visibleSections(module, can, roles),
  ];

  const isActive = (tab) => {
    if (!tab.route) return false; // "Soon" tabs have no route
    if (tab.route.startsWith('/apps/')) return pathname === tab.route;
    return pathname === tab.route || pathname.startsWith(tab.route + '/');
  };

  return (
    <div data-module-bar={module.id} className="glass sticky top-16 z-10 border-b border-slate-200/70">
      <div className="mx-auto flex max-w-7xl items-stretch gap-4 px-4 sm:px-6 lg:px-8">
        <div className="flex shrink-0 items-center gap-2.5 py-3">
          <span className={`flex h-8 w-8 items-center justify-center rounded-lg ${BUBBLE[module.tone] || BUBBLE.slate}`}>
            <ModuleIcon className="h-5 w-5" />
          </span>
          <span className="font-display text-sm font-bold text-slate-900">{module.name}</span>
        </div>

        <nav className="sidebar-scroll flex items-stretch gap-1 overflow-x-auto" aria-label={`${module.name} sections`}>
          {tabs.map((tab) => {
            const active = isActive(tab);
            const TabIcon = tab.icon;
            if (tab.status === 'soon') {
              return (
                <span
                  key={tab.name}
                  title={`${tab.name} — coming soon`}
                  className="flex cursor-not-allowed items-center gap-1.5 whitespace-nowrap border-b-2 border-transparent px-3 py-3 text-sm font-medium text-slate-300"
                >
                  {TabIcon && <TabIcon className="h-4 w-4" />}
                  {tab.name}
                  <span className="rounded bg-slate-100 px-1 text-[9px] font-bold uppercase text-slate-400">Soon</span>
                </span>
              );
            }
            return (
              <Link
                key={tab.name}
                to={tab.route}
                aria-current={active ? 'page' : undefined}
                className={`flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-3 text-sm outline-none transition focus-visible:ring-2 focus-visible:ring-indigo-500/50 ${
                  active
                    ? 'border-indigo-500 font-semibold text-indigo-600'
                    : 'border-transparent font-medium text-slate-500 hover:text-slate-800'
                }`}
              >
                {TabIcon && <TabIcon className="h-4 w-4" />}
                {tab.name}
              </Link>
            );
          })}
        </nav>
      </div>
    </div>
  );
}
