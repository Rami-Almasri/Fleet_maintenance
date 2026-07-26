// ────────────────────────────────────────────────────────────────────────────
// App Launcher — the FleetView landing page. Odoo-style: it shows ONLY the
// business modules as large cards (with a hover mega-menu of their sections),
// plus a search box and an optional "recent apps" row. No KPIs, no charts, no
// activity feed — all operational information lives inside each module's
// Overview. Everything is generated from config/moduleRegistry.js.
// ────────────────────────────────────────────────────────────────────────────

import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { usePermissions } from '../hooks/usePermissions';
import { useAuth } from '../auth/AuthContext';
import { visibleModules, moduleForPath, isModuleVisible, OVERVIEW_ROUTE } from '../config/moduleRegistry';
import { SearchInput, EmptyState } from '../components/ui/Misc';
import Icon from '../components/ui/Icon';
import ModuleCard from '../components/workspace/ModuleCard';

const greeting = () => {
  const h = new Date().getHours();
  if (h < 12) return 'Good morning';
  if (h < 18) return 'Good afternoon';
  return 'Good evening';
};

// Recently visited modules, derived from the paths AppLayout already records.
function useRecentModules(can, roles) {
  return useMemo(() => {
    let paths = [];
    try { paths = JSON.parse(localStorage.getItem('fv:recents') || '[]'); } catch { paths = []; }
    const seen = new Set();
    const out = [];
    for (const p of paths) {
      const m = moduleForPath(p);
      if (m && !seen.has(m.id) && isModuleVisible(m, can, roles)) {
        seen.add(m.id);
        out.push(m);
      }
      if (out.length >= 4) break;
    }
    return out;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
}

export default function ModuleLauncher() {
  const { can, roles, permissions } = usePermissions();
  const { user } = useAuth();
  const [query, setQuery] = useState('');
  const recent = useRecentModules(can, roles);
  const first = user?.name ? String(user.name).trim().split(/\s+/)[0] : '';

  const modules = useMemo(() => {
    const all = visibleModules(can, roles);
    const q = query.trim().toLowerCase();
    if (!q) return all;
    return all.filter(
      (m) =>
        m.name.toLowerCase().includes(q) ||
        m.tagline.toLowerCase().includes(q) ||
        m.sections.some((s) => s.name.toLowerCase().includes(q)),
    );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [permissions, roles, query]);

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-8 px-4 sm:px-6 lg:px-8">
        {/* Header — greeting + module search only. */}
        <div className="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <h1 className="font-display text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
              {greeting()}{first ? <>, <span className="text-gradient">{first}</span></> : ''}
            </h1>
            <p className="mt-1 text-sm text-slate-500">Choose an application to get started.</p>
          </div>
          <div className="w-full sm:w-72">
            <SearchInput value={query} onChange={setQuery} placeholder="Search applications…" />
          </div>
        </div>

        {/* Recent apps (optional). */}
        {!query && recent.length > 0 && (
          <div>
            <h2 className="mb-3 font-display text-xs font-bold uppercase tracking-wide text-slate-400">Recent</h2>
            <div className="flex flex-wrap gap-2">
              {recent.map((m) => {
                const MIcon = m.icon;
                return (
                  <Link
                    key={m.id}
                    to={OVERVIEW_ROUTE(m.id)}
                    className="flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3.5 py-1.5 text-sm font-medium text-slate-700 shadow-soft outline-none transition hover:border-indigo-300 hover:text-indigo-600 focus-visible:ring-2 focus-visible:ring-indigo-500/50"
                  >
                    <MIcon className="h-4 w-4 text-slate-400" />
                    {m.name}
                  </Link>
                );
              })}
            </div>
          </div>
        )}

        {/* Module grid. */}
        {modules.length > 0 ? (
          <div className="stagger grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {modules.map((m) => (
              <ModuleCard key={m.id} module={m} />
            ))}
          </div>
        ) : (
          <div className="rounded-2xl border border-slate-200/60 bg-white shadow-soft">
            <EmptyState
              icon={<Icon.Search className="h-6 w-6" />}
              title={query ? 'No matching applications' : 'No applications available'}
              message={
                query
                  ? `Nothing matches “${query}”. Try a different search.`
                  : 'You don’t have access to any modules yet. Ask an administrator to grant permissions.'
              }
            />
          </div>
        )}
      </div>
    </div>
  );
}
