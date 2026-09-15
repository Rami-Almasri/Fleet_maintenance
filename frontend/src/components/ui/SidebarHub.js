import { useMemo, useRef } from 'react';
import { useSearchParams } from 'react-router-dom';
import { usePermissions } from '../../hooks/usePermissions';
import { pathBlockedForRoles } from '../../config/access';
import { useI18n } from '../../i18n/I18nContext';

/**
 * TabbedHub's sibling, for hubs whose sections are whole working pages rather than two readings of
 * the same board. Same contract, same URL key (`?tab=…`), same two-layer access filtering — the only
 * difference is the shape: a rail down the side that stays put while you work, instead of a strip
 * that scrolls away with the page.
 *
 * Use this when the sections are separate JOBS (review a request, chase a mileage, match a bill) and
 * a person moves between them all day. Use TabbedHub when they are views of one subject.
 *
 * THE RAIL is a real panel, not a list of links: a titled card with an icon tile per section, the
 * section's own one-line purpose under its name, and the active row lifted with the brand wash + a
 * leading accent bar. It reads as a desk with four drawers. On narrow screens the card flattens into
 * a horizontal pill scroller and the hints drop away, because there is no room for a rail there.
 *
 * Colours stay inside the enumerated slate/indigo utilities that index.css re-maps under
 * [data-theme='dark'] — that override layer is what makes the panel correct in the Cockpit theme
 * without a single `dark:` class. Anything outside that set would render light-on-light at night.
 *
 * ACCESS — identical to TabbedHub, and it matters for the same reason. Access is two layers: a
 * permission (`can`) and a role deny list keyed by PATH (config/access.js). Folding a page into a hub
 * changes its path, which would quietly move it out from under its own deny rule. So each section
 * declares `was` — the route it replaced — and is hidden unless the viewer both holds the permission
 * and is not blocked from that original path.
 *
 * Section shape: { key, label, hint?, icon?, permission?, permissionAny?, was?, Component }
 *   permission — omit (or null) for "any authenticated user"; `permissionAny` takes a list instead.
 *   hint       — one line under the label in the rail, so the section says what it is for.
 *
 * Only the active section is mounted, so exactly one data-fetch / poll loop runs at a time.
 */
export default function SidebarHub({ title, subtitle, ariaLabel, icon, sections }) {
  const { can, canAny, roles } = usePermissions();
  const { t } = useI18n();
  const btnRefs = useRef([]);

  const visible = useMemo(
    () =>
      sections.filter((s) => {
        const allowed = s.permissionAny ? canAny(s.permissionAny) : can(s.permission ?? null);
        return allowed && !(s.was && pathBlockedForRoles(s.was, roles));
      }),
    [sections, can, canAny, roles],
  );

  // The active section lives in the URL (?tab=…) so every section stays deep-linkable, and so the
  // retired routes can keep redirecting in through the same RedirectToTab helper the other hubs use.
  const [searchParams, setSearchParams] = useSearchParams();
  const current = visible.find((s) => s.key === searchParams.get('tab')) || visible[0];
  // Switching sections drops the previous one's own params (?focus, ?ticket, …) — they mean nothing
  // to the section being opened.
  const setActive = (key) => setSearchParams({ tab: key }, { replace: true });
  const Active = current?.Component;

  const onKeyDown = (e) => {
    const idx = visible.findIndex((s) => s.key === current?.key);
    if (idx < 0) return;
    let next = null;
    // The rail is vertical on desktop and horizontal on mobile, so honour both axes rather than
    // guessing which one the user is looking at.
    if (e.key === 'ArrowDown' || e.key === 'ArrowRight') next = (idx + 1) % visible.length;
    else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') next = (idx - 1 + visible.length) % visible.length;
    else if (e.key === 'Home') next = 0;
    else if (e.key === 'End') next = visible.length - 1;
    if (next === null) return;
    e.preventDefault();
    setActive(visible[next].key);
    btnRefs.current[next]?.focus();
  };

  if (!Active) {
    // Every section filtered out. The route let this user in but the deny list took the rest away —
    // say so plainly instead of rendering an empty shell.
    return (
      <div className="mx-auto max-w-7xl px-4 py-16 text-center text-sm text-slate-500 sm:px-6 lg:px-8">
        {t('You don’t have access to this page')}
      </div>
    );
  }

  const HubIcon = icon;

  return (
    <div className="flex flex-col lg:flex-row lg:items-start">
      {/* 8.5rem = the app header (h-20) plus the module tab bar that sits sticky under it (top-20,
          ~57px tall). The rail parks just below both and scrolls internally if it ever outgrows the
          viewport. See AppLayout's <header> and components/workspace/ModuleTabBar. */}
      <aside className="shrink-0 px-4 pt-5 sm:px-6 lg:sticky lg:top-[8.5rem] lg:max-h-[calc(100vh-9.5rem)] lg:w-[19.5rem] lg:overflow-y-auto lg:px-5 lg:py-8">
        <div className="overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-card">
          {/* Masthead. The faint brand wash is what separates "a panel" from "a box". It is a flat
              `bg-indigo-50/40` and NOT a gradient on purpose: index.css's dark layer re-maps whole
              background utilities, but nothing patches gradient STOPS, so a from-white/via-white
              masthead would stay glaring white in the Cockpit theme. */}
          <div className="relative border-b border-slate-100 bg-indigo-50/40 px-4 py-4">
            <div className="flex items-center gap-2.5">
              {HubIcon && (
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-glow">
                  <HubIcon className="h-5 w-5" />
                </span>
              )}
              <h1 className="font-display text-base font-bold leading-tight text-slate-900">{title}</h1>
            </div>
            {subtitle && <p className="mt-2 text-xs leading-relaxed text-slate-500">{subtitle}</p>}
          </div>

          {visible.length > 1 && (
            <nav
              role="tablist"
              aria-orientation="vertical"
              aria-label={ariaLabel}
              onKeyDown={onKeyDown}
              className="stagger flex gap-1.5 overflow-x-auto p-2 lg:flex-col lg:overflow-x-visible"
            >
              {visible.map((s, i) => {
                const selected = s.key === current.key;
                const SectionIcon = s.icon;
                return (
                  <button
                    key={s.key}
                    ref={(el) => { btnRefs.current[i] = el; }}
                    type="button"
                    role="tab"
                    id={`tab-${s.key}`}
                    aria-selected={selected}
                    aria-controls={`panel-${s.key}`}
                    tabIndex={selected ? 0 : -1}
                    onClick={() => setActive(s.key)}
                    className={`focus-ring-self group relative flex shrink-0 items-center gap-3 whitespace-nowrap rounded-xl py-2.5 pe-3 ps-4 text-start outline-none transition focus-visible:ring-2 focus-visible:ring-indigo-500/50 lg:w-full lg:whitespace-normal ${
                      selected
                        ? 'bg-indigo-50 shadow-soft'
                        : 'hover:bg-slate-100/70'
                    }`}
                  >
                    {/* Leading accent bar — the one element that makes "which drawer am I in?"
                        readable at a glance from across the desk. */}
                    <span
                      aria-hidden
                      className={`absolute inset-y-2.5 start-1 w-1 rounded-full transition-all ${
                        selected ? 'bg-indigo-500 opacity-100' : 'bg-slate-300 opacity-0 group-hover:opacity-100'
                      }`}
                    />
                    {/* No `group-hover:` colour changes anywhere below. The dark layer in index.css
                        patches plain utilities only — a group-hover background or a group-hover
                        slate-900 label would render white-on-white / black-on-black at night. The
                        hover affordance is therefore the row wash, the accent bar and the chevron,
                        all of which are either mapped utilities or pure opacity/transform. */}
                    {SectionIcon && (
                      <span
                        className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition ${
                          selected ? 'bg-indigo-600 text-white shadow-glow' : 'bg-slate-100 text-slate-500'
                        }`}
                      >
                        {SectionIcon}
                      </span>
                    )}
                    <span className="min-w-0 flex-1">
                      <span className={`block text-sm leading-tight ${selected ? 'font-semibold text-indigo-700' : 'font-medium text-slate-700'}`}>
                        {s.label}
                      </span>
                      {s.hint && (
                        <span className={`mt-0.5 hidden text-[11px] leading-snug lg:block ${selected ? 'text-indigo-600/75' : 'text-slate-400'}`}>
                          {s.hint}
                        </span>
                      )}
                    </span>
                    {/* Direction cue. Present only on the row you are pointing at (or sitting in),
                        so the rail stays quiet until you engage with it. */}
                    <svg
                      aria-hidden
                      viewBox="0 0 24 24"
                      fill="none"
                      stroke="currentColor"
                      strokeWidth="2.2"
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      className={`hidden h-3.5 w-3.5 shrink-0 text-slate-400 transition-all rtl:-scale-x-100 lg:block ${
                        selected
                          ? 'text-indigo-500 opacity-100'
                          : '-translate-x-1 opacity-0 group-hover:translate-x-0 group-hover:opacity-100 rtl:translate-x-1 rtl:group-hover:translate-x-0'
                      }`}
                    >
                      <path d="M9 6l6 6-6 6" />
                    </svg>
                  </button>
                );
              })}
            </nav>
          )}
        </div>
      </aside>

      <div role="tabpanel" id={`panel-${current.key}`} aria-labelledby={`tab-${current.key}`} className="min-w-0 flex-1">
        <Active />
      </div>
    </div>
  );
}
