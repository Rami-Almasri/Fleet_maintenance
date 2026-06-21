import { useState, useEffect, useRef } from 'react';
import { NavLink, Outlet, useNavigate, useLocation } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext';
import { usePermissions } from '../hooks/usePermissions';
import CommandPalette from '../components/CommandPalette';
import ShortcutsHelp from '../components/ShortcutsHelp';
import ErrorBoundary from '../components/ErrorBoundary';
import NotificationBell from '../components/NotificationBell';
import { PageStatProvider, PageStatGauge } from '../components/PageStat';

// Thin gradient bar at the very top that fills as you scroll the page.
function ScrollProgress() {
  const [pct, setPct] = useState(0);
  useEffect(() => {
    const onScroll = () => {
      const h = document.documentElement;
      const max = h.scrollHeight - h.clientHeight;
      setPct(max > 0 ? (h.scrollTop / max) * 100 : 0);
    };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    return () => {
      window.removeEventListener('scroll', onScroll);
      window.removeEventListener('resize', onScroll);
    };
  }, []);
  return <div className="scroll-progress" style={{ width: `${pct}%`, opacity: pct > 0.5 ? 1 : 0 }} />;
}

// Soft drifting color field behind the whole app (pure CSS, see index.css).
function Aurora() {
  return (
    <div className="aurora" aria-hidden="true">
      <div className="aurora__blob aurora__blob--1" />
      <div className="aurora__blob aurora__blob--2" />
      <div className="aurora__blob aurora__blob--3" />
    </div>
  );
}

const greet = (h) => (h < 5 ? 'Good night' : h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : h < 22 ? 'Good evening' : 'Good night');

// Live clock + time-of-day greeting chip for the header.
function LiveClock({ name }) {
  const [now, setNow] = useState(() => new Date());
  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 1000 * 30);
    return () => clearInterval(id);
  }, []);
  const time = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  const date = now.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' });
  const first = (name || '').trim().split(/\s+/)[0];
  return (
    <div className="hidden items-center gap-3 rounded-xl border border-slate-200/70 bg-white/60 px-3 py-1.5 md:flex">
      <div className="leading-tight">
        <p className="text-[11px] font-semibold text-slate-700">{greet(now.getHours())}{first ? `, ${first}` : ''}</p>
        <p className="text-[10px] font-medium text-slate-400">{date}</p>
      </div>
      <div className="h-7 w-px bg-slate-200" />
      <p className="font-mono text-sm font-semibold tabular-nums text-slate-600">{time}</p>
    </div>
  );
}

// Navigation grouped into sections for a calmer, scannable sidebar.
// `desc` powers the per-page info circle (bottom-right "?" button).
const NAV_SECTIONS = [
  {
    title: 'Overview',
    items: [
      { name: 'Dashboard', to: '/', icon: 'M3 12l9-9 9 9M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10', desc: 'Fleet-wide overview: how many cars are available, rented, or in maintenance, plus key totals. Availability and composition come from the OfficeManager lifecycle status.' },
      { name: 'Notifications', to: '/notifications', icon: 'M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 0 0-4-5.7V5a2 2 0 1 0-4 0v.3A6 6 0 0 0 6 11v3.2a2 2 0 0 1-.6 1.4L4 17h5m6 0v1a3 3 0 1 1-6 0v-1m6 0H9', desc: 'Live fleet alerts — overdue rentals, maintenance overruns, expiring documents, service-due cars and approvals. The bell in the top bar updates in real time.' },
    ],
  },
  {
    title: 'Fleet',
    items: [
      { name: 'Vehicles', to: '/vehicles', icon: 'M5 17h14M5 17a2 2 0 0 1-2-2v-3l2-5a2 2 0 0 1 2-1.4h8A2 2 0 0 1 19 7l2 5v3a2 2 0 0 1-2 2M7 17v1a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-1m14 0v1a1 1 0 0 1-1 1h0a1 1 0 0 1-1-1v-1M7 12h10', desc: 'Every car in the fleet. The OfficeManager API is the sole source of which cars exist; the sheet only enriches matched cars. Click a row to open its full profile.' },
      { name: 'Customers', to: '/customers', icon: 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM3 21v-1a6 6 0 0 1 6-6h6a6 6 0 0 1 6 6v1', desc: 'All customers with their contact details and available wallet (carried-forward credit). Open a customer to see their contracts and balance history.' },
      { name: 'Drivers', to: '/drivers', icon: 'M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM6 21v-1a6 6 0 0 1 6-6 6 6 0 0 1 6 6v1M3 9l2 2 3-3', desc: 'Fleet drivers with their licence number, expiry and status. Add, edit or suspend drivers; expiring licences are flagged.' },
      { name: 'Contracts', to: '/contracts', icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2z', desc: 'Rental contracts synced from OfficeManager — all open contracts plus the last 3 months of closed ones. Open or closed status is detected on each sync.' },
      { name: 'Registrations', to: '/registrations', icon: 'M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', desc: 'Vehicle registration & legal status: insurance, Mulkiya, mortgage, RTA fines and status. Each field is sourced from the system that owns it (API, F Insurance, or F RTA).' },
    ],
  },
  {
    title: 'Operations',
    items: [
      { name: 'Overdue Rentals', to: '/overdue-rentals', icon: 'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', desc: 'Open contracts that are past their expected return date — the cars that should be back but are not.' },
      { name: 'Maintenance', to: '/maintenance', icon: 'M11 4a4 4 0 0 0-1 7.9V20a2 2 0 1 0 4 0v-8.1A4 4 0 0 0 11 4zM14.5 4.5l-2 2 3 3 2-2', desc: 'Live repair board: current repair status, garage, issues and priority per car. Read from the latest event for each car in the N-Maintenance sheet log.' },
      { name: 'Return Check', to: '/maintenance-returns', icon: 'M3 12a9 9 0 1 0 9-9 9 9 0 0 0-9 9zm0 0H1m2 0 3-3m-3 3 3 3M16 12l-4 4-2-2', desc: 'Cars the N-Maintenance sheet shows are back from the garage but whose maintenance contract is still open in OfficeManager — these contracts just need closing.' },
      { name: 'Approvals', to: '/maintenance-approvals', icon: 'M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', desc: 'Maintenance items waiting for sign-off before work proceeds.' },
      { name: 'Garages', to: '/garages', icon: 'M3 9l9-6 9 6v11a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z', desc: 'Garages where fleet cars are serviced, with the work routed to each.' },
      { name: 'Vendors', to: '/vendors', icon: 'M3 9l1-5h16l1 5M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9M3 9h18M9 20v-6h6v6', desc: 'Suppliers and service vendors referenced by maintenance and contracts.' },
    ],
  },
  {
    title: 'Insights',
    items: [
      { name: 'Cost Analytics', to: '/maintenance-analytics', icon: 'M3 3v18h18M7 15l3-3 3 3 5-5', desc: 'Maintenance cost trends and breakdowns across the fleet — spend by car, garage, and over time.' },
      { name: 'Damage & Accidents', to: '/damage-accidents', icon: 'M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z', desc: 'Damage and accident records shown as-is per vehicle. Fault is colored red/green based on the liable party and insurance.' },
      { name: 'Exceptional Cases', to: '/anomalies', icon: 'M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z', desc: 'Data conflicts and operational gaps surfaced by nine automated checks — places where fleet data disagrees with itself or with reality.' },
      { name: 'Data Health', to: '/data-health', icon: 'M3 12h4l2 5 4-12 2 7h6', desc: 'Overall data quality signals: missing fields, stale records, and coverage across the sources.' },
      { name: 'Status Mismatch', to: '/status-mismatch', icon: 'M16 3h5v5M21 3l-7 7M8 21H3v-5M3 21l7-7', desc: 'Cars whose status disagrees between sources — e.g. rented in one system but available in another.' },
    ],
  },
  {
    title: 'System',
    items: [
      { name: 'Sheet ↔ API Diff', to: '/sheet-api-diff', icon: 'M9 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h4M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4M9 8h6M9 12h6M9 16h6', desc: 'Side-by-side comparison of the canonical "Faster" sheet against the OfficeManager API, matched by VIN, to spot vehicles that differ.' },
      { name: 'Data Sync', to: '/sync', icon: 'M4 4v6h6M20 20v-6h-6M20 9A8 8 0 0 0 6.3 5.3L4 8m16 8-2.3 2.7A8 8 0 0 1 4 15', desc: 'Run and monitor data syncs from OfficeManager and the sheets, with per-phase status and summaries.' },
      { name: 'Sync Audit', to: '/sync-audit', icon: 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2m-6 9 2 2 4-4', desc: 'Read-only history of CMD sync runs: how many contracts each execution scanned, updated, and auto-corrected (e.g. stale dates cleared).' },
    ],
  },
];

const ALL_ITEMS = NAV_SECTIONS.flatMap((s) => s.items);
const SEARCH_ITEMS = NAV_SECTIONS.flatMap((s) => s.items.map((i) => ({ ...i, section: s.title })));

// Permission required to see each nav destination. A `null`/missing entry means
// "always visible to any authenticated user". These mirror the route guards in
// App.js and the `permission:` middleware on the backend — keep the three in sync.
const NAV_PERMISSIONS = {
  '/': 'dashboard.view',
  '/notifications': null,
  '/vehicles': 'vehicles.view',
  '/customers': 'customers.view',
  '/drivers': 'drivers.view',
  '/contracts': 'contracts.view',
  '/registrations': 'registration.view',
  '/overdue-rentals': 'dashboard.view',
  '/maintenance': 'maintenance.view',
  '/maintenance-returns': 'maintenance.view',
  '/maintenance-approvals': 'maintenance.approve',
  '/garages': 'maintenance.view',
  '/vendors': 'vendors.view',
  '/maintenance-analytics': 'maintenance.view',
  '/damage-accidents': 'maintenance.view',
  '/anomalies': 'insights.view',
  '/data-health': 'insights.view',
  '/status-mismatch': 'insights.view',
  '/sheet-api-diff': 'insights.view',
  '/sync': 'sync.run',
  '/sync-audit': 'sync.run',
};

// Quick actions surfaced at the top of the command palette. `run` receives a
// small context ({ navigate }) so actions stay declarative here.
const QUICK_ACTIONS = [
  { id: 'new-contract', label: 'New contract', keywords: 'create add rental', icon: 'M12 5v14M5 12h14', run: ({ navigate }) => navigate('/contracts/new') },
  { id: 'run-sync', label: 'Run data sync', keywords: 'officemanager refresh import', icon: 'M4 4v6h6M20 20v-6h-6M20 9A8 8 0 0 0 6.3 5.3L4 8m16 8-2.3 2.7A8 8 0 0 1 4 15', run: ({ navigate }) => navigate('/sync') },
  { id: 'data-health', label: 'Check data health', keywords: 'issues quality clean', icon: 'M3 12h4l2 5 4-12 2 7h6', run: ({ navigate }) => navigate('/data-health') },
  { id: 'reload', label: 'Reload app', keywords: 'refresh reset', icon: 'M4 4v6h6M20 9A8 8 0 0 0 6.3 5.3L4 8', run: () => window.location.reload() },
];

// Permission gate per quick action (null = always available).
const QUICK_ACTION_PERMISSIONS = {
  'new-contract': 'contracts.manage',
  'run-sync': 'sync.run',
  'data-health': 'insights.view',
  'reload': null,
};

// Floating "back to top" button that appears once you scroll down a long page.
function ScrollTop() {
  const [show, setShow] = useState(false);
  useEffect(() => {
    const onScroll = () => setShow(document.documentElement.scrollTop > 400);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);
  if (!show) return null;
  return (
    <button
      onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })}
      className="fixed bottom-[11rem] right-6 z-30 flex h-11 w-11 items-center justify-center rounded-full bg-slate-900/90 text-white shadow-lg ring-1 ring-white/10 backdrop-blur transition hover:bg-slate-900 active:scale-90"
      title="Back to top"
      aria-label="Back to top"
    >
      <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 19V5M5 12l7-7 7 7" /></svg>
    </button>
  );
}

// `collapsed` only takes effect at the lg breakpoint (the icon-rail); below lg
// the sidebar is a full-width drawer and always shows labels.
function NavItem({ item, onNavigate, collapsed }) {
  return (
    <NavLink
      to={item.to}
      end={item.to === '/'}
      onClick={onNavigate}
      className={({ isActive }) =>
        [
          'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-all duration-200',
          collapsed ? 'lg:justify-center lg:gap-0 lg:px-0' : '',
          isActive
            ? 'bg-gradient-to-r from-indigo-500/20 to-violet-500/10 text-white shadow-[inset_0_1px_0_0_rgb(255_255_255_/_0.06)]'
            : 'text-slate-400 hover:bg-white/5 hover:text-white',
        ].join(' ')
      }
    >
      {({ isActive }) => (
        <>
          {/* active accent bar */}
          <span
            className={`absolute left-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-r-full bg-gradient-to-b from-indigo-400 to-violet-400 transition-all duration-200 ${
              isActive ? 'opacity-100' : 'opacity-0 group-hover:opacity-40'
            }`}
          />
          <svg
            className={`h-[18px] w-[18px] shrink-0 transition-colors ${isActive ? 'text-indigo-300' : 'text-slate-500 group-hover:text-slate-300'}`}
            fill="none" viewBox="0 0 24 24" stroke="currentColor"
            strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round"
          >
            <path d={item.icon} />
          </svg>
          <span className={`truncate ${collapsed ? 'lg:hidden' : ''}`}>{item.name}</span>
          {/* hover tooltip, only in the collapsed rail (lg+) */}
          <span className={`rail-tip ${collapsed ? 'hidden lg:block' : 'hidden'}`}>{item.name}</span>
        </>
      )}
    </NavLink>
  );
}

export default function AppLayout() {
  const { user, logout } = useAuth();
  const { can } = usePermissions();
  const navigate = useNavigate();
  const location = useLocation();

  // Hide nav items the user can't open, and drop sections left empty.
  const visibleSections = NAV_SECTIONS
    .map((s) => ({ ...s, items: s.items.filter((i) => can(NAV_PERMISSIONS[i.to])) }))
    .filter((s) => s.items.length > 0);
  const visibleSearch = SEARCH_ITEMS.filter((i) => can(NAV_PERMISSIONS[i.to]));
  const [open, setOpen] = useState(false);
  const [cmdOpen, setCmdOpen] = useState(false);
  const [helpOpen, setHelpOpen] = useState(false);
  // Desktop sidebar collapse (icon-rail), remembered across sessions.
  const [collapsed, setCollapsed] = useState(() => localStorage.getItem('fv:rail') === '1');
  // Recently visited pages (paths), most-recent first — feeds the command palette.
  const [recents, setRecents] = useState(() => {
    try { return JSON.parse(localStorage.getItem('fv:recents') || '[]'); } catch { return []; }
  });
  const lastG = useRef(0); // timestamp of the last "g" press, for g+key chords

  useEffect(() => {
    localStorage.setItem('fv:rail', collapsed ? '1' : '0');
  }, [collapsed]);

  const handleLogout = async () => {
    await logout();
    navigate('/login', { replace: true });
  };

  // Global keyboard shortcuts: ⌘K palette, ? help, t top, g+<key> jumps.
  useEffect(() => {
    const onKey = (e) => {
      if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
        e.preventDefault();
        setCmdOpen((v) => !v);
        return;
      }
      // Ignore single-key shortcuts while typing in a field.
      const tag = (e.target?.tagName || '').toLowerCase();
      if (e.metaKey || e.ctrlKey || e.altKey || tag === 'input' || tag === 'textarea' || e.target?.isContentEditable) return;

      if (e.key === '?') { e.preventDefault(); setHelpOpen((v) => !v); return; }
      if (e.key === 't' || e.key === 'T') { window.scrollTo({ top: 0, behavior: 'smooth' }); return; }

      // g + d/v/c/m chord navigation.
      const now = Date.now();
      if (e.key === 'g' || e.key === 'G') { lastG.current = now; return; }
      if (now - lastG.current < 800) {
        const map = { d: '/', v: '/vehicles', c: '/contracts', m: '/maintenance' };
        const dest = map[e.key.toLowerCase()];
        if (dest) { e.preventDefault(); navigate(dest); lastG.current = 0; }
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [navigate]);

  // Derive the current page name for the topbar (longest matching path wins).
  const current = [...ALL_ITEMS]
    .filter((i) => (i.to === '/' ? location.pathname === '/' : location.pathname.startsWith(i.to)))
    .sort((a, b) => b.to.length - a.to.length)[0];

  const initial = (user?.name || '?').charAt(0).toUpperCase();
  const pageName = current?.name;

  // Reflect the current page in the browser tab title.
  useEffect(() => {
    document.title = pageName ? `${pageName} · FleetView` : 'FleetView';
  }, [pageName]);

  // Track recently visited nav pages (exact matches only) for the palette.
  useEffect(() => {
    const hit = ALL_ITEMS.find((i) => (i.to === '/' ? location.pathname === '/' : location.pathname === i.to));
    if (!hit) return;
    setRecents((prev) => {
      const next = [hit.to, ...prev.filter((t) => t !== hit.to)].slice(0, 6);
      localStorage.setItem('fv:recents', JSON.stringify(next));
      return next;
    });
  }, [location.pathname]);

  const runAction = (a) => a.run?.({ navigate });

  return (
    <PageStatProvider>
    <div className="min-h-screen">
      <Aurora />
      <ScrollProgress />
      {/* Mobile overlay */}
      {open && (
        <div className="fixed inset-0 z-30 bg-slate-900/50 backdrop-blur-sm lg:hidden" onClick={() => setOpen(false)} />
      )}

      {/* Sidebar */}
      <aside
        className={`fixed inset-y-0 left-0 z-40 flex w-64 transform flex-col bg-slate-900 text-slate-300 shadow-2xl transition-all duration-300 ease-out lg:translate-x-0 ${
          collapsed ? 'lg:w-20' : 'lg:w-64'
        } ${open ? 'translate-x-0' : '-translate-x-full'}`}
      >
        {/* Brand */}
        <div className={`flex h-16 shrink-0 items-center gap-3 border-b border-white/5 px-5 ${collapsed ? 'lg:justify-center lg:px-0' : ''}`}>
          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-500 font-bold text-white shadow-glow">
            F
          </div>
          <div className={`leading-tight ${collapsed ? 'lg:hidden' : ''}`}>
            <p className="text-[15px] font-bold tracking-tight text-white">FleetView</p>
            <p className="text-[11px] font-medium text-slate-500">Management Suite</p>
          </div>
        </div>

        {/* Nav */}
        <nav className="sidebar-scroll flex-1 space-y-5 overflow-y-auto px-3 py-5">
          {visibleSections.map((section) => (
            <div key={section.title}>
              <p className={`px-3 pb-1.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-600 ${collapsed ? 'lg:hidden' : ''}`}>
                {section.title}
              </p>
              {/* a thin divider stands in for the section label when collapsed */}
              {collapsed && <div className="mx-auto mb-1.5 hidden h-px w-6 bg-white/10 lg:block" />}
              <div className="space-y-0.5">
                {section.items.map((item) => (
                  <NavItem key={item.name} item={item} onNavigate={() => setOpen(false)} collapsed={collapsed} />
                ))}
              </div>
            </div>
          ))}
        </nav>

        {/* Footer: live status + desktop collapse toggle */}
        <div className="shrink-0 border-t border-white/5 px-3 py-3">
          <div className={`flex items-center ${collapsed ? 'lg:flex-col lg:gap-2' : 'justify-between'} gap-2 px-2`}>
            <div className={`flex items-center gap-2 text-[11px] text-slate-500 ${collapsed ? 'lg:hidden' : ''}`}>
              <span className="relative flex h-2 w-2">
                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60" />
                <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-400" />
              </span>
              Live — OfficeManager connected
            </div>
            {/* collapsed state: just the pulsing dot */}
            {collapsed && (
              <span className="relative hidden h-2 w-2 lg:flex">
                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60" />
                <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-400" />
              </span>
            )}
            <button
              onClick={() => setCollapsed((v) => !v)}
              className="hidden rounded-lg p-1.5 text-slate-500 transition hover:bg-white/5 hover:text-white lg:inline-flex"
              title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
              aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
            >
              <svg className={`h-5 w-5 transition-transform ${collapsed ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                <path d="M15 6l-6 6 6 6" />
              </svg>
            </button>
          </div>
        </div>
      </aside>

      {/* Main column */}
      <div className={`transition-all duration-300 ${collapsed ? 'lg:pl-20' : 'lg:pl-64'}`}>
        <header className="glass sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-slate-200/70 px-4 sm:px-6 lg:px-8">
          <button
            className="rounded-lg p-1.5 text-slate-500 transition hover:bg-slate-100 lg:hidden"
            onClick={() => setOpen(true)}
            aria-label="Open menu"
          >
            <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
              <path strokeLinecap="round" strokeLinejoin="round" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
          </button>

          <div className="flex min-w-0 flex-1 items-center gap-2">
            <h2 className="truncate text-[15px] font-semibold text-slate-800">{current?.name || 'FleetView'}</h2>
          </div>

          <div className="flex items-center gap-3">
            <LiveClock name={user?.name} />

            <button
              onClick={() => setHelpOpen(true)}
              className="hidden h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-400 shadow-sm transition hover:text-slate-600 hover:ring-1 hover:ring-slate-200 lg:inline-flex"
              title="Keyboard shortcuts ( ? )"
              aria-label="Keyboard shortcuts"
            >
              <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8"><path strokeLinecap="round" strokeLinejoin="round" d="M9.1 9a3 3 0 1 1 5.8 1c0 2-3 3-3 3M12 17h.01" /></svg>
            </button>

            <button
              onClick={() => setCmdOpen(true)}
              className="hidden items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-400 shadow-sm transition hover:text-slate-600 hover:ring-1 hover:ring-slate-200 sm:flex"
              title="Search (Ctrl / ⌘ K)"
            >
              <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8"><path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-4.3-4.3M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16z" /></svg>
              Search
              <kbd className="rounded border border-slate-200 bg-slate-50 px-1 text-[10px] font-semibold text-slate-400">⌘K</kbd>
            </button>

            <NotificationBell />

            {/* subtle divider between actions and the user identity block */}
            <span className="hidden h-6 w-px bg-slate-200 sm:block" />

            <div className="hidden text-right sm:block">
              <p className="text-sm font-semibold leading-tight text-slate-900">{user?.name}</p>
              <p className="text-xs text-slate-500">{user?.email}</p>
            </div>
            <div className="flex h-9 w-9 items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-sm font-semibold text-white shadow-sm ring-2 ring-white">
              {initial}
            </div>
            <button
              onClick={handleLogout}
              className="rounded-lg p-2 text-slate-400 transition hover:bg-red-50 hover:text-red-600"
              title="Log out"
            >
              <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
                <path d="M16 17l5-5-5-5M21 12H9M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
              </svg>
            </button>
          </div>
        </header>

        {/* Page content — re-animates on every route change; an error here can't blank the app */}
        <main>
          <ErrorBoundary key={location.pathname}>
            <div className="animate-fade-in-up">
              <Outlet />
            </div>
          </ErrorBoundary>
        </main>
      </div>

      <CommandPalette
        open={cmdOpen}
        onClose={() => setCmdOpen(false)}
        items={visibleSearch}
        actions={QUICK_ACTIONS.filter((a) => can(QUICK_ACTION_PERMISSIONS[a.id])).map((a) => ({ ...a, run: () => runAction(a) }))}
        recents={recents}
      />
      <ShortcutsHelp open={helpOpen} onClose={() => setHelpOpen(false)} />
      <PageStatGauge />
      <ScrollTop />
    </div>
    </PageStatProvider>
  );
}
