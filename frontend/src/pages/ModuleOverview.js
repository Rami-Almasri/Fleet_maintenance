// ────────────────────────────────────────────────────────────────────────────
// Module Overview — the home tab of every module's mini-app (route /apps/:id).
// The module's tab bar is rendered by AppLayout (persistent across sections);
// this page renders the Overview *content*: a module hero + quick-links into the
// module's sections. Generic — every module gets a consistent, polished launcher
// with zero bespoke code.
// ────────────────────────────────────────────────────────────────────────────

import { useParams, Navigate } from 'react-router-dom';
import { usePermissions } from '../hooks/usePermissions';
import { homePathForRoles } from '../config/access';
import {
  getModule, isModuleVisible, visibleSections,
  moduleNameKey, moduleTaglineKey, sectionNameKey, sectionDescKey,
} from '../config/moduleRegistry';
import AppCard from '../components/workspace/AppCard';
import { useI18n } from '../i18n/I18nContext';

// Per-tone hero styling — a soft gradient wash behind the module identity, plus
// the icon chip colour. Mirrors the registry `tone` keys.
const HERO = {
  indigo:  { grad: 'from-indigo-500/10 via-indigo-500/5',   chip: 'bg-indigo-500/10 text-indigo-600 ring-indigo-500/20',   dot: 'bg-indigo-500' },
  amber:   { grad: 'from-amber-500/15 via-amber-500/5',     chip: 'bg-amber-500/10 text-amber-600 ring-amber-500/20',     dot: 'bg-amber-500' },
  violet:  { grad: 'from-violet-500/10 via-violet-500/5',   chip: 'bg-violet-500/10 text-violet-600 ring-violet-500/20',   dot: 'bg-violet-500' },
  emerald: { grad: 'from-emerald-500/10 via-emerald-500/5', chip: 'bg-emerald-500/10 text-emerald-600 ring-emerald-500/20', dot: 'bg-emerald-500' },
  blue:    { grad: 'from-blue-500/10 via-blue-500/5',       chip: 'bg-blue-500/10 text-blue-600 ring-blue-500/20',         dot: 'bg-blue-500' },
  slate:   { grad: 'from-slate-500/10 via-slate-500/5',     chip: 'bg-slate-500/10 text-slate-600 ring-slate-500/20',     dot: 'bg-slate-500' },
};

function Band({ title, count, children }) {
  const { tp } = useI18n();
  return (
    <section aria-label={title}>
      <div className="mb-4 flex items-baseline justify-between gap-3">
        <h2 className="font-display text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{title}</h2>
        {count != null && (
          <span className="text-xs font-medium text-slate-400">{tp('modules.sectionCount', count)}</span>
        )}
      </div>
      {children}
    </section>
  );
}

export default function ModuleOverview() {
  const { moduleId } = useParams();
  const module = getModule(moduleId);
  const { can, roles } = usePermissions();
  const { t, tf, tp } = useI18n();

  // Bounce an unknown or unreachable module to the user's OWN home — '/' is
  // Dashboard-gated, so sending a supervisor / inspector / driver there would
  // just swap one dead end for the Forbidden wall.
  const home = homePathForRoles(roles);
  if (!module) return <Navigate to={home} replace />;
  if (!isModuleVisible(module, can, roles)) return <Navigate to={home} replace />;

  const moduleName = tf(moduleNameKey(module), module.name);
  const sections = visibleSections(module, can, roles);
  const liveCount = sections.filter((s) => s.status !== 'soon').length;
  const sectionApps = sections.map((s) => ({
    key: s.name, // English identity — stable React key across languages
    name: tf(sectionNameKey(module, s), s.name),
    desc: tf(sectionDescKey(module, s), s.desc),
    to: s.route,
    icon: s.icon,
    tone: module.tone,
    status: s.status,
  }));

  const ModuleIcon = module.icon;
  const hero = HERO[module.tone] || HERO.slate;

  return (
    <div className="py-8">
      <div className="mx-auto max-w-7xl space-y-8 px-4 sm:px-6 lg:px-8">
        {/* Module hero — identity, tagline and a live section count. */}
        <header className={`relative overflow-hidden rounded-2xl border border-slate-200/70 bg-gradient-to-br ${hero.grad} to-transparent p-6 shadow-soft sm:p-8`}>
          <div className="relative flex items-start gap-4 sm:gap-5">
            <span className={`flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl ring-1 ${hero.chip} sm:h-16 sm:w-16`}>
              <ModuleIcon className="h-7 w-7 sm:h-8 sm:w-8" />
            </span>
            <div className="min-w-0 pt-0.5">
              <h1 className="font-display text-2xl font-bold text-slate-900 sm:text-3xl">{moduleName}</h1>
              <p className="mt-1.5 max-w-2xl text-sm leading-relaxed text-slate-500 sm:text-base">{tf(moduleTaglineKey(module), module.tagline)}</p>
              <div className="mt-3 inline-flex items-center gap-2 rounded-full bg-white/70 px-3 py-1 text-xs font-medium text-slate-500 ring-1 ring-slate-200/70">
                <span className={`h-1.5 w-1.5 rounded-full ${hero.dot}`} />
                {tp('modules.activeSectionCount', liveCount)}
              </div>
            </div>
          </div>
        </header>

        <Band title={t('modules.explore', { module: moduleName })} count={liveCount}>
          <div className="stagger grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            {sectionApps.map((app) => (
              <AppCard key={app.key} app={app} />
            ))}
          </div>
        </Band>
      </div>
    </div>
  );
}
