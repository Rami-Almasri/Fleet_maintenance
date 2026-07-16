// Settings — the account & preferences hub. Previously the app had no home for
// "who am I, what can I do, and how do I tune the interface", even though the
// pieces (theme, language, roles, shortcuts) already existed scattered around
// the shell. This page gathers them into one calm, scannable place.
//
// It's intentionally read-mostly: appearance controls persist via their own
// contexts (ThemeContext / I18nContext), and the account block reflects the
// logged-in user from AuthContext. No new backend is required.

import { useTheme } from '../theme/ThemeContext';
import { useAuth } from '../auth/AuthContext';
import { usePermissions } from '../hooks/usePermissions';
import ThemeToggle from '../components/ThemeToggle';
import LanguageToggle from '../components/LanguageToggle';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import { SectionCard } from '../components/ui/Table';
import Icon from '../components/ui/Icon';
import { SHOW_FINANCIALS } from '../config/features';

// One labelled row inside a settings card: title + description on the left,
// the control on the right.
function Row({ title, desc, children, last }) {
  return (
    <div className={`flex items-center justify-between gap-4 py-4 ${last ? '' : 'border-b border-slate-100'}`}>
      <div className="min-w-0">
        <p className="text-sm font-semibold text-slate-900">{title}</p>
        {desc && <p className="mt-0.5 text-xs text-slate-500">{desc}</p>}
      </div>
      <div className="shrink-0">{children}</div>
    </div>
  );
}

// A single keyboard shortcut line: keys on the left, meaning on the right.
function Shortcut({ keys, label }) {
  return (
    <div className="flex items-center justify-between gap-3 py-2">
      <span className="flex items-center gap-1">
        {keys.map((k, i) => (
          <kbd
            key={i}
            className="inline-flex min-w-[1.5rem] items-center justify-center rounded-lg border border-slate-200 bg-slate-50 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-slate-600 shadow-sm"
          >
            {k}
          </kbd>
        ))}
      </span>
      <span className="text-sm text-slate-600">{label}</span>
    </div>
  );
}

// Prettify a permission/role slug: "maintenance.manage" → "Maintenance · Manage".
const pretty = (s) =>
  String(s)
    .split('.')
    .map((p) => p.replace(/(^|[-_])(\w)/g, (_, __, c) => ' ' + c.toUpperCase()).trim())
    .join(' · ');

export default function Settings() {
  const { theme } = useTheme();
  const { user, logout } = useAuth();
  const { roles, permissions, isSuperAdmin } = usePermissions();

  const initial = (user?.name || '?').charAt(0).toUpperCase();

  return (
    <div className="py-8">
      <div className="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
        {/* Hero header — clean navy identity band */}
        <div className="relative overflow-hidden rounded-2xl bg-navy-950 px-6 py-7 ring-1 ring-white/10 sm:px-8">
          <div className="relative flex items-center gap-5">
            <div className="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-indigo-600 text-2xl font-bold text-white ring-2 ring-white/20">
              {initial}
            </div>
            <div className="min-w-0">
              <p className="text-xs font-semibold uppercase tracking-widest text-brand-300/90">Settings</p>
              <h1 className="mt-1 truncate font-display text-3xl font-bold tracking-tight text-white">{user?.name || 'Your account'}</h1>
              <p className="mt-0.5 truncate text-sm text-slate-300">{user?.email}</p>
            </div>
          </div>
        </div>

        {/* Appearance */}
        <SectionCard
          title="Appearance"
          subtitle="Tune how Faster looks and reads on your device"
        >
          <div className="px-1">
            <Row title="Theme" desc={`Currently ${theme === 'dark' ? 'Cockpit (dark)' : 'Platinum (light)'} — switch between the day and night surfaces.`}>
              <div className="flex items-center gap-2">
                <span className="text-xs font-medium text-slate-500">{theme === 'dark' ? 'Cockpit' : 'Platinum'}</span>
                <ThemeToggle />
              </div>
            </Row>
            <Row title="Language" desc="Switch the interface language. The toggle also flips layout direction for right-to-left languages." last>
              <LanguageToggle />
            </Row>
          </div>
        </SectionCard>

        {/* Account & access */}
        <SectionCard
          title="Account & Access"
          subtitle="Your identity and what you're permitted to do across the platform"
        >
          <div className="px-1">
            <Row title="Name" desc="Shown in the top bar and on any records you create.">
              <span className="text-sm font-semibold text-slate-900">{user?.name || '—'}</span>
            </Row>
            <Row title="Email" desc="The address you sign in with.">
              <span className="text-sm text-slate-700">{user?.email || '—'}</span>
            </Row>
            <Row
              title="Roles"
              desc={isSuperAdmin ? 'Super-admin — full access to every feature, including ones added later.' : 'The role(s) that grant your permissions.'}
              last={!permissions.length}
            >
              <div className="flex max-w-[16rem] flex-wrap justify-end gap-1.5">
                {roles.length ? (
                  roles.map((r) => (
                    <Badge key={r} tone={r === 'super-admin' ? 'violet' : 'indigo'}>{pretty(r)}</Badge>
                  ))
                ) : (
                  <span className="text-sm text-slate-400">No role assigned</span>
                )}
              </div>
            </Row>

            {/* Permission grid — a reassuring, glanceable "here's what you can reach". */}
            {permissions.length > 0 && (
              <div className="py-4">
                <p className="text-sm font-semibold text-slate-900">Permissions</p>
                <p className="mb-3 mt-0.5 text-xs text-slate-500">
                  {isSuperAdmin ? 'Super-admin bypasses these checks — everything is available.' : `${permissions.length} capabilities granted to your account.`}
                </p>
                <div className="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                  {permissions.slice(0, 40).map((p) => (
                    <div key={p} className="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-1.5 text-xs text-slate-600 ring-1 ring-inset ring-slate-200/60">
                      <Icon.Check className="h-3.5 w-3.5 shrink-0 text-emerald-500" />
                      <span className="truncate">{pretty(p)}</span>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        </SectionCard>

        {/* Keyboard shortcuts */}
        <SectionCard
          title="Keyboard Shortcuts"
          subtitle="Move around Faster without touching the mouse"
          actions={<Badge tone="gray">power user</Badge>}
        >
          <div className="grid grid-cols-1 gap-x-10 px-1 sm:grid-cols-2">
            <div className="divide-y divide-slate-100">
              <Shortcut keys={['⌘', 'K']} label="Open command palette / search" />
              <Shortcut keys={['?']} label="Show keyboard shortcuts" />
              <Shortcut keys={['t']} label="Scroll back to top" />
            </div>
            <div className="divide-y divide-slate-100">
              <Shortcut keys={['g', 'd']} label="Go to Dashboard" />
              <Shortcut keys={['g', 'v']} label="Go to Vehicles" />
              <Shortcut keys={['g', 'c']} label="Go to Contracts" />
              <Shortcut keys={['g', 'm']} label="Go to Maintenance" />
            </div>
          </div>
        </SectionCard>

        {/* About / build info */}
        <SectionCard title="About" subtitle="This build of Faster">
          <div className="px-1">
            <Row title="Financial widgets" desc="Money figures (balances, wallets, invoice/payment & cost totals) across the app.">
              <Badge tone={SHOW_FINANCIALS ? 'emerald' : 'gray'}>{SHOW_FINANCIALS ? 'Visible' : 'Hidden'}</Badge>
            </Row>
            <Row title="Source of truth" desc="Which cars exist and their rental contracts." last>
              <span className="text-sm text-slate-700">OfficeManager API</span>
            </Row>
          </div>
        </SectionCard>

        {/* Session */}
        <SectionCard title="Session" subtitle="Sign out of this device">
          <div className="flex items-center justify-between gap-4 px-1 py-2">
            <p className="text-sm text-slate-500">You'll be returned to the login screen. Your data stays synced on the server.</p>
            <Button variant="danger" onClick={logout} className="shrink-0">
              <Icon.ArrowRight className="h-4 w-4" />
              Log out
            </Button>
          </div>
        </SectionCard>
      </div>
    </div>
  );
}
