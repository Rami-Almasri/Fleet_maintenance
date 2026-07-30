// The persistent per-module top navigation. Rendered by AppLayout under the app
// header on the module Overview AND every section page that belongs to the
// module, so the user always knows which app they're in and can move between its
// sections without "leaving" it. Tabs are permission-filtered via the registry.
//
// When the tabs overflow the bar's width they don't collapse — instead the bar
// scrolls horizontally with an always-visible, grabbable scrollbar (`.tabbar-scroll`),
// and the user can also click-and-drag anywhere on the bar to pan through them.
//
// A section may declare a `menu` (array of { name, route, tone? } and optional
// { heading } separators); its tab then behaves like an Odoo dropdown — clicking
// it reveals the sub-items (e.g. the Maintenance Cycle stages) that deep-link into
// the page. The dropdown is rendered through a portal to document.body so the tab
// bar's horizontal-scroll container can't clip it.

import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link, useLocation } from 'react-router-dom';
import api from '../../api/client';
import { usePermissions } from '../../hooks/usePermissions';
import { visibleSections, OVERVIEW_ROUTE, moduleNameKey, sectionNameKey, menuLabelKey } from '../../config/moduleRegistry';
import { useI18n } from '../../i18n/I18nContext';
import Icon from '../ui/Icon';

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
  const { t, tf, dir, isRTL } = useI18n();
  const { pathname } = useLocation();
  const ModuleIcon = module.icon;
  const moduleName = tf(moduleNameKey(module), module.name);

  // The open dropdown: { name, left, top } (viewport coords of the tab that owns it), or null.
  const [menu, setMenu] = useState(null);
  // Live per-stage ticket counts for the open dropdown, keyed by the menu item's `key`.
  const [counts, setCounts] = useState(null);
  const barRef = useRef(null);
  const menuRef = useRef(null);
  const navRef = useRef(null);

  const closeMenu = () => setMenu(null);

  // Close on route change, outside click, Escape, and any scroll/resize (the portal is fixed-
  // positioned, so it would otherwise drift away from its tab).
  useEffect(() => { setMenu(null); }, [pathname]);
  useEffect(() => {
    if (!menu) return undefined;
    const onDown = (e) => {
      if (barRef.current?.contains(e.target) || menuRef.current?.contains(e.target)) return;
      closeMenu();
    };
    const onKey = (e) => { if (e.key === 'Escape') closeMenu(); };
    const onMove = () => closeMenu();
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    window.addEventListener('scroll', onMove, true);
    window.addEventListener('resize', onMove);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
      window.removeEventListener('scroll', onMove, true);
      window.removeEventListener('resize', onMove);
    };
  }, [menu]);

  // ── Grab-and-drag panning ────────────────────────────────────────────────────
  // Press anywhere on the bar and drag to slide the tabs. A movement threshold keeps
  // this from stealing plain clicks on tabs; once it counts as a drag we swallow the
  // click so the tab under the pointer isn't accidentally activated.
  const drag = useRef({ active: false, moved: false, startX: 0, startLeft: 0 });

  const onPointerDown = (e) => {
    // Only the primary (left) button, and never through a scrollbar-thumb grab (those
    // land on the element too, but the browser handles them — pointerdown y is fine to allow).
    if (e.button !== 0) return;
    const nav = navRef.current;
    if (!nav) return;
    drag.current = { active: true, moved: false, startX: e.clientX, startLeft: nav.scrollLeft };
  };
  const onPointerMove = (e) => {
    const d = drag.current;
    if (!d.active) return;
    const dx = e.clientX - d.startX;
    if (!d.moved && Math.abs(dx) < 5) return; // below threshold — still a click
    if (!d.moved) {
      d.moved = true;
      navRef.current?.classList.add('is-dragging');
      navRef.current?.setPointerCapture?.(e.pointerId);
    }
    navRef.current.scrollLeft = d.startLeft - dx;
  };
  const endDrag = (e) => {
    const d = drag.current;
    if (d.active && d.moved) {
      try { navRef.current?.releasePointerCapture?.(e.pointerId); } catch { /* noop */ }
    }
    navRef.current?.classList.remove('is-dragging');
    // Keep `moved` true through the click event that fires right after pointerup.
    drag.current = { ...d, active: false };
  };
  // Suppress the click that follows a drag so we don't navigate on release.
  const onClickCapture = (e) => {
    if (drag.current.moved) {
      e.preventDefault();
      e.stopPropagation();
      drag.current.moved = false;
    }
  };
  // Let a mouse wheel scroll the bar horizontally when it overflows.
  const onWheel = (e) => {
    const nav = navRef.current;
    if (!nav || nav.scrollWidth <= nav.clientWidth) return;
    if (e.deltaY !== 0 && e.deltaX === 0) { nav.scrollLeft += e.deltaY; }
  };

  // `name` stays the English identity (it keys the open-dropdown state and the
  // React list); `label` is what the user actually reads.
  const tabs = [
    { name: 'Overview', label: t('modules.overview'), route: OVERVIEW_ROUTE(module.id), icon: module.icon },
    ...visibleSections(module, can, roles).map((s) => ({ ...s, label: tf(sectionNameKey(module, s), s.name) })),
  ];

  const isActive = (tab) => {
    if (!tab.route) return false; // "Soon" tabs have no route
    if (tab.route.startsWith('/apps/')) return pathname === tab.route;
    return pathname === tab.route || pathname.startsWith(tab.route + '/');
  };

  const baseTab = 'group/tab relative flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-2 my-1.5 text-sm outline-none transition-colors focus-visible:ring-2 focus-visible:ring-indigo-500/50';
  const activeTab = 'bg-indigo-50 font-semibold text-indigo-600';
  const idleTab = 'font-medium text-slate-500 hover:bg-slate-100/70 hover:text-slate-800';

  const toggleMenu = (tab, e) => {
    if (menu?.name === tab.name) { closeMenu(); return; }
    const r = e.currentTarget.getBoundingClientRect();
    // The panel is portaled to <body> and fixed-positioned, so it can't inherit
    // the bar's direction — anchor it to the tab's leading edge explicitly, which
    // is the right edge under RTL.
    setMenu({
      name: tab.name,
      top: r.bottom + 4,
      ...(isRTL ? { right: window.innerWidth - r.right } : { left: r.left }),
    });
  };

  const openTab = tabs.find((t) => t.menu && menu?.name === t.name);

  // When a dropdown with a countsUrl opens, pull the live per-stage ticket counts so each menu item
  // shows how many tickets sit in that stage. Cleared while closed so numbers are always fresh.
  useEffect(() => {
    if (!openTab?.countsUrl) { setCounts(null); return undefined; }
    let alive = true;
    setCounts(null);
    api.get(openTab.countsUrl)
      .then((r) => {
        if (!alive) return;
        const d = r.data?.data || {};
        const cols = d.columns || {};
        const map = {};
        let total = 0;
        for (const k of Object.keys(cols)) { const n = (cols[k] || []).length; map[k] = n; total += n; }
        map.__all = d.counts?.open_total ?? total;
        setCounts(map);
      })
      .catch(() => { if (alive) setCounts({}); });
    return () => { alive = false; };
  }, [openTab?.name, openTab?.countsUrl]);

  return (
    <div ref={barRef} data-module-bar={module.id} className="glass sticky top-16 z-10 border-b border-slate-200/70">
      <div className="mx-auto flex max-w-7xl items-stretch gap-4 px-4 sm:px-6 lg:px-8">
        <div className="flex shrink-0 items-center gap-2.5 py-3">
          <span className={`flex h-8 w-8 items-center justify-center rounded-lg ${BUBBLE[module.tone] || BUBBLE.slate}`}>
            <ModuleIcon className="h-5 w-5" />
          </span>
          <span className="font-display text-sm font-bold text-slate-900">{moduleName}</span>
        </div>

        <nav
          ref={navRef}
          className="tabbar-scroll flex min-w-0 flex-1 items-stretch gap-1"
          aria-label={t('modules.sectionsOf', { module: moduleName })}
          onPointerDown={onPointerDown}
          onPointerMove={onPointerMove}
          onPointerUp={endDrag}
          onPointerCancel={endDrag}
          onClickCapture={onClickCapture}
          onWheel={onWheel}
        >
          {tabs.map((tab) => {
            const active = isActive(tab);
            const TabIcon = tab.icon;

            if (tab.status === 'soon') {
              return (
                <span
                  key={tab.name}
                  title={t('modules.comingSoonTitle', { name: tab.label })}
                  className="flex cursor-not-allowed items-center gap-1.5 whitespace-nowrap border-b-2 border-transparent px-3 py-3 text-sm font-medium text-slate-300"
                >
                  {TabIcon && <TabIcon className="h-4 w-4" />}
                  {tab.label}
                  <span className="rounded bg-slate-100 px-1 text-[9px] font-bold uppercase text-slate-400">{t('modules.soon')}</span>
                </span>
              );
            }

            // Dropdown tab (e.g. Maintenance Cycle → its stages). The panel itself is portaled below.
            if (tab.menu && tab.menu.length) {
              const open = menu?.name === tab.name;
              return (
                <button
                  key={tab.name}
                  type="button"
                  aria-haspopup="true"
                  aria-expanded={open}
                  aria-current={active ? 'page' : undefined}
                  onClick={(e) => toggleMenu(tab, e)}
                  className={`${baseTab} ${active ? activeTab : idleTab}`}
                >
                  {TabIcon && <TabIcon className="h-4 w-4" />}
                  {tab.label}
                  <Icon.ChevronDown className={`h-3.5 w-3.5 transition-transform ${open ? 'rotate-180' : ''}`} />
                </button>
              );
            }

            return (
              <Link
                key={tab.name}
                to={tab.route}
                aria-current={active ? 'page' : undefined}
                className={`${baseTab} ${active ? activeTab : idleTab}`}
                draggable={false}
              >
                {TabIcon && <TabIcon className="h-4 w-4" />}
                {tab.label}
              </Link>
            );
          })}
        </nav>
      </div>

      {/* Portaled dropdown — fixed to the viewport at the tab's coordinates, so the tab bar's
          horizontal-scroll clipping never hides it. */}
      {openTab && menu && createPortal(
        <div
          ref={menuRef}
          role="menu"
          dir={dir}
          style={{ position: 'fixed', left: menu.left, right: menu.right, top: menu.top, zIndex: 1000 }}
          className="min-w-[13rem] overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-xl shadow-slate-900/10"
        >
          {openTab.menu.map((item) => (
            item.heading ? (
              <p
                key={`h-${item.heading}`}
                className="mt-1 border-t border-slate-100 px-4 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400"
              >
                {tf(menuLabelKey(module, item), item.heading)}
              </p>
            ) : (
              <Link
                key={item.name}
                to={item.route}
                role="menuitem"
                onClick={closeMenu}
                className="flex items-center gap-2.5 px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50 hover:text-slate-900"
              >
                {item.tone && <span className="h-2 w-2 shrink-0 rounded-full" style={{ background: item.tone }} />}
                <span className="flex-1 whitespace-nowrap">{tf(menuLabelKey(module, item), item.name)}</span>
                {item.key && counts && (
                  <span
                    className={`ms-auto inline-flex min-w-[1.5rem] items-center justify-center rounded-full px-1.5 py-0.5 text-[11px] font-bold tabular-nums ${
                      (counts[item.key] || 0) > 0 ? 'bg-slate-100 text-slate-700' : 'bg-slate-50 text-slate-400'
                    } ${item.key === '__all' ? 'bg-indigo-50 text-indigo-600' : ''}`}
                  >
                    {counts[item.key] ?? 0}
                  </span>
                )}
              </Link>
            )
          ))}
        </div>,
        document.body,
      )}
    </div>
  );
}
