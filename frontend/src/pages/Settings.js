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
import { ThemeStudioInline } from '../components/ThemePicker';
import LanguageToggle from '../components/LanguageToggle';
import Badge from '../components/ui/Badge';
import Button from '../components/ui/Button';
import { SectionCard } from '../components/ui/Table';
import Icon from '../components/ui/Icon';
import { SHOW_FINANCIALS } from '../config/features';
import { useI18n } from '../i18n/I18nContext';

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
  const { t } = useI18n();

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
              <p className="text-xs font-semibold uppercase tracking-widest text-brand-300/90">{t('settings.eyebrow')}</p>
              <h1 className="mt-1 truncate font-display text-3xl font-bold tracking-tight text-white">{user?.name || t('settings.yourAccount')}</h1>
              <p className="mt-0.5 truncate text-sm text-slate-300">{user?.email}</p>
            </div>
          </div>
        </div>

        {/* Appearance */}
        <SectionCard
          title={t('settings.appearance.title')}
          subtitle={t('settings.appearance.subtitle')}
        >
          <div className="px-1">
            <Row title={t('settings.appearance.theme')} desc={t('settings.appearance.themeDesc', { theme: t(theme === 'dark' ? 'settings.appearance.dark' : 'settings.appearance.light') })}>
              <div className="flex items-center gap-2">
                <span className="text-xs font-medium text-slate-500">{t(theme === 'dark' ? 'settings.appearance.darkShort' : 'settings.appearance.lightShort')}</span>
                <ThemeToggle />
              </div>
            </Row>
            <Row title={t('settings.appearance.language')} desc={t('settings.appearance.languageDesc')}>
              <LanguageToggle />
            </Row>
            {/* Colours get the full row width rather than the right-hand control
                slot — the swatch grids and the live preview need it. */}
            <div className="py-4">
              <p className="text-sm font-semibold text-slate-900">{t('Colours')}</p>
              <p className="mt-0.5 text-xs text-slate-500">
                {t('Choose the primary and accent colours the whole app is painted in. Saved on this device only.')}
              </p>
              <div className="mt-4">
                <ThemeStudioInline />
              </div>
            </div>
          </div>
        </SectionCard>

        {/* Account & access */}
        <SectionCard
          title={t('settings.account.title')}
          subtitle={t('settings.account.subtitle')}
        >
          <div className="px-1">
            <Row title={t('settings.account.name')} desc={t('settings.account.nameDesc')}>
              <span className="text-sm font-semibold text-slate-900">{user?.name || '—'}</span>
            </Row>
            <Row title={t('settings.account.email')} desc={t('settings.account.emailDesc')}>
              <span className="text-sm text-slate-700">{user?.email || '—'}</span>
            </Row>
            <Row
              title={t('settings.account.roles')}
              desc={t(isSuperAdmin ? 'settings.account.rolesSuperDesc' : 'settings.account.rolesDesc')}
              last={!permissions.length}
            >
              <div className="flex max-w-[16rem] flex-wrap justify-end gap-1.5">
                {roles.length ? (
                  roles.map((r) => (
                    <Badge key={r} tone={r === 'super-admin' ? 'violet' : 'indigo'}>{pretty(r)}</Badge>
                  ))
                ) : (
                  <span className="text-sm text-slate-400">{t('settings.account.noRole')}</span>
                )}
              </div>
            </Row>

            {/* Permission grid — a reassuring, glanceable "here's what you can reach". */}
            {permissions.length > 0 && (
              <div className="py-4">
                <p className="text-sm font-semibold text-slate-900">{t('settings.account.permissions')}</p>
                <p className="mb-3 mt-0.5 text-xs text-slate-500">
                  {isSuperAdmin ? t('settings.account.permsSuper') : t('settings.account.permsCount', { n: permissions.length })}
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
          title={t('settings.shortcuts.title')}
          subtitle={t('settings.shortcuts.subtitle')}
          actions={<Badge tone="gray">{t('settings.shortcuts.badge')}</Badge>}
        >
          <div className="grid grid-cols-1 gap-x-10 px-1 sm:grid-cols-2">
            <div className="divide-y divide-slate-100">
              <Shortcut keys={['⌘', 'K']} label={t('settings.shortcuts.palette')} />
              <Shortcut keys={['?']} label={t('settings.shortcuts.help')} />
              <Shortcut keys={['t']} label={t('settings.shortcuts.top')} />
            </div>
            <div className="divide-y divide-slate-100">
              <Shortcut keys={['g', 'd']} label={t('shortcuts.navigation.dashboard')} />
              <Shortcut keys={['g', 'v']} label={t('shortcuts.navigation.vehicles')} />
              <Shortcut keys={['g', 'c']} label={t('shortcuts.navigation.contracts')} />
              <Shortcut keys={['g', 'm']} label={t('shortcuts.navigation.maintenance')} />
            </div>
          </div>
        </SectionCard>

        {/* About / build info */}
        <SectionCard title={t('settings.about.title')} subtitle={t('settings.about.subtitle')}>
          <div className="px-1">
            <Row title={t('settings.about.financial')} desc={t('settings.about.financialDesc')}>
              <Badge tone={SHOW_FINANCIALS ? 'emerald' : 'gray'}>{t(SHOW_FINANCIALS ? 'settings.about.visible' : 'settings.about.hidden')}</Badge>
            </Row>
            <Row title={t('settings.about.source')} desc={t('settings.about.sourceDesc')} last>
              {/* Product name — stays English in every language. */}
              <span className="text-sm text-slate-700">{'OfficeManager API'}</span>
            </Row>
          </div>
        </SectionCard>

        {/* Session */}
        <SectionCard title={t('settings.session.title')} subtitle={t('settings.session.subtitle')}>
          <div className="flex items-center justify-between gap-4 px-1 py-2">
            <p className="text-sm text-slate-500">{t('settings.session.note')}</p>
            <Button variant="danger" onClick={logout} className="shrink-0">
              <Icon.ArrowRight className="h-4 w-4 rtl:-scale-x-100" />
              {t('shell.logout')}
            </Button>
          </div>
        </SectionCard>
      </div>
    </div>
  );
}
