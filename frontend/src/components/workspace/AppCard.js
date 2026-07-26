// A single application tile in the Workspace launcher. Two states:
//   • ready — a router link with hover-elevation + click feedback + a nudging arrow
//   • soon  — a disabled, dashed "Coming Soon" roadmap tile (never a dead link)
//
// Fully driven by a config object ({ key, name, desc?, to, icon, tone, status }).
// Used for the section quick-links on each module Overview (ModuleOverview).

import { Link } from 'react-router-dom';
import Icon from '../ui/Icon';
import Badge from '../ui/Badge';

// Icon-bubble tones — mirror MetricCard/Badge so the dark-mode override layer in
// index.css recolours them automatically. `bg-{t}-100 text-{t}-600`.
const TONES = {
  slate:   'bg-slate-100 text-slate-600',
  indigo:  'bg-indigo-100 text-indigo-600',
  blue:    'bg-blue-100 text-blue-600',
  cyan:    'bg-cyan-100 text-cyan-600',
  emerald: 'bg-emerald-100 text-emerald-600',
  amber:   'bg-amber-100 text-amber-600',
  violet:  'bg-violet-100 text-violet-600',
  red:     'bg-red-100 text-red-600',
};

export default function AppCard({ app }) {
  const IconCmp = app.icon || Icon.Spark;
  const tone = TONES[app.tone] || TONES.slate;
  const soon = app.status === 'soon';

  if (soon) {
    return (
      <div
        aria-disabled="true"
        data-app-key={app.key}
        data-app-status="soon"
        title={`${app.name} — coming soon`}
        className="relative flex h-full flex-col rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-5"
      >
        <div className="flex items-start justify-between">
          <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-slate-100 text-slate-400">
            <IconCmp className="h-5 w-5" />
          </span>
          <Badge tone="slate">Soon</Badge>
        </div>
        <h3 className="mt-4 font-display text-base font-semibold text-slate-500">{app.name}</h3>
        <p className="mt-1 text-sm text-slate-400">{app.desc}</p>
      </div>
    );
  }

  return (
    <Link
      to={app.to}
      data-app-key={app.key}
      data-app-status="ready"
      className="hover-lift group relative flex h-full flex-col rounded-2xl border border-slate-200/60 bg-white p-5 shadow-soft outline-none transition duration-200 active:scale-[0.98] focus-visible:ring-2 focus-visible:ring-indigo-500/60 focus-visible:ring-offset-2 focus-visible:ring-offset-[rgb(var(--bg))]"
    >
      <div className="flex items-start justify-between">
        <span className={`flex h-11 w-11 items-center justify-center rounded-xl transition-transform duration-200 group-hover:scale-105 ${tone}`}>
          <IconCmp className="h-5 w-5" />
        </span>
        <span className="flex items-center gap-2">
          {app.badge && (
            <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-indigo-600">
              {app.badge}
            </span>
          )}
          <Icon.ArrowRight className="h-4 w-4 -translate-x-1 text-slate-300 opacity-0 transition-all duration-200 group-hover:translate-x-0 group-hover:text-indigo-500 group-hover:opacity-100" />
        </span>
      </div>
      <h3 className="mt-4 font-display text-base font-semibold text-slate-900">{app.name}</h3>
      {app.desc && <p className="mt-1 text-sm text-slate-500">{app.desc}</p>}
    </Link>
  );
}
