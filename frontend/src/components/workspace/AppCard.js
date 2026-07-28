// A single application tile in the Workspace launcher. Two states:
//   • ready — a router link with hover-elevation + click feedback + a nudging arrow
//   • soon  — a disabled, dashed "Coming Soon" roadmap tile (never a dead link)
//
// Fully driven by a config object ({ key, name, desc?, to, icon, tone, status }).
// Used for the section quick-links on each module Overview (ModuleOverview).

import { Link } from 'react-router-dom';
import Icon from '../ui/Icon';
import Badge from '../ui/Badge';

// Per-tone styling. `bubble` colours the icon chip; `glow` is the soft radial
// wash that fades in on hover; `edge` is the left accent rail that grows in. All
// mirror the MetricCard/Badge tones so the dark-mode override layer in index.css
// recolours them automatically.
const TONES = {
  slate:   { bubble: 'bg-slate-100 text-slate-600',     ring: 'ring-slate-200/70',   glow: 'from-slate-400/10',   edge: 'bg-slate-400',   arrow: 'group-hover:text-slate-500' },
  indigo:  { bubble: 'bg-indigo-100 text-indigo-600',   ring: 'ring-indigo-200/70',  glow: 'from-indigo-400/15',  edge: 'bg-indigo-500',  arrow: 'group-hover:text-indigo-500' },
  blue:    { bubble: 'bg-blue-100 text-blue-600',       ring: 'ring-blue-200/70',    glow: 'from-blue-400/15',    edge: 'bg-blue-500',    arrow: 'group-hover:text-blue-500' },
  cyan:    { bubble: 'bg-cyan-100 text-cyan-600',       ring: 'ring-cyan-200/70',    glow: 'from-cyan-400/15',    edge: 'bg-cyan-500',    arrow: 'group-hover:text-cyan-500' },
  emerald: { bubble: 'bg-emerald-100 text-emerald-600', ring: 'ring-emerald-200/70', glow: 'from-emerald-400/15', edge: 'bg-emerald-500', arrow: 'group-hover:text-emerald-500' },
  amber:   { bubble: 'bg-amber-100 text-amber-600',     ring: 'ring-amber-200/70',   glow: 'from-amber-400/20',   edge: 'bg-amber-500',   arrow: 'group-hover:text-amber-500' },
  violet:  { bubble: 'bg-violet-100 text-violet-600',   ring: 'ring-violet-200/70',  glow: 'from-violet-400/15',  edge: 'bg-violet-500',  arrow: 'group-hover:text-violet-500' },
  red:     { bubble: 'bg-red-100 text-red-600',         ring: 'ring-red-200/70',     glow: 'from-red-400/15',     edge: 'bg-red-500',     arrow: 'group-hover:text-red-500' },
};

export default function AppCard({ app }) {
  const IconCmp = app.icon || Icon.Spark;
  const t = TONES[app.tone] || TONES.slate;
  const soon = app.status === 'soon';

  if (soon) {
    return (
      <div
        aria-disabled="true"
        data-app-key={app.key}
        data-app-status="soon"
        title={`${app.name} — coming soon`}
        className="relative flex h-full flex-col rounded-2xl border border-dashed border-slate-300 bg-slate-50/60 p-5"
      >
        <div className="flex items-start justify-between">
          <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-slate-100 text-slate-400">
            <IconCmp className="h-5 w-5" />
          </span>
          <Badge tone="slate">Soon</Badge>
        </div>
        <h3 className="mt-4 font-display text-base font-semibold text-slate-500">{app.name}</h3>
        <p className="mt-1 text-sm leading-relaxed text-slate-400">{app.desc}</p>
      </div>
    );
  }

  return (
    <Link
      to={app.to}
      data-app-key={app.key}
      data-app-status="ready"
      className="hover-lift group relative flex h-full flex-col overflow-hidden rounded-2xl border border-slate-200/70 bg-white p-5 shadow-soft outline-none transition duration-200 active:scale-[0.98] focus-visible:ring-2 focus-visible:ring-indigo-500/60 focus-visible:ring-offset-2 focus-visible:ring-offset-[rgb(var(--bg))]"
    >
      {/* Left accent rail — grows from a hairline to a full edge on hover. */}
      <span className={`absolute inset-y-0 left-0 w-1 origin-top scale-y-0 rounded-r ${t.edge} opacity-0 transition-all duration-200 group-hover:scale-y-100 group-hover:opacity-100`} />
      {/* Soft tone-tinted glow that blooms from the top-right on hover. */}
      <span className={`pointer-events-none absolute -right-10 -top-10 h-32 w-32 rounded-full bg-gradient-to-br ${t.glow} to-transparent opacity-0 blur-2xl transition-opacity duration-300 group-hover:opacity-100`} />

      <div className="relative flex items-start justify-between">
        <span className={`flex h-11 w-11 items-center justify-center rounded-xl ring-1 transition-transform duration-200 group-hover:scale-105 ${t.bubble} ${t.ring}`}>
          <IconCmp className="h-5 w-5" />
        </span>
        <span className="flex items-center gap-2">
          {app.badge && (
            <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-indigo-600">
              {app.badge}
            </span>
          )}
          <span className={`flex h-7 w-7 items-center justify-center rounded-full text-slate-300 transition-all duration-200 group-hover:bg-slate-50 ${t.arrow}`}>
            <Icon.ArrowRight className="h-4 w-4 -translate-x-0.5 transition-transform duration-200 group-hover:translate-x-0" />
          </span>
        </span>
      </div>
      <h3 className="relative mt-4 font-display text-base font-semibold text-slate-900">{app.name}</h3>
      {app.desc && <p className="relative mt-1.5 text-sm leading-relaxed text-slate-500">{app.desc}</p>}
    </Link>
  );
}
