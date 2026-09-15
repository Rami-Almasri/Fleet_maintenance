import { useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import { PageHeader } from './Misc';
import Tabs from './Tabs';
import { usePermissions } from '../../hooks/usePermissions';
import { pathBlockedForRoles } from '../../config/access';
import { useI18n } from '../../i18n/I18nContext';

/**
 * The shape every consolidated page uses: one title, a tab strip, and the ACTIVE tab's page mounted
 * below it. Pages that used to be separate routes become tabs here; each keeps its own URL as a
 * redirect (see RedirectToTab in App.js), so bookmarks and deep links still land in the right place.
 *
 * Only the active tab is mounted, so exactly one data-fetch / poll loop runs at a time.
 *
 * WHY THE TABS ARE FILTERED HERE
 * Access in this app is two layers: a permission (`can`) and a role-based deny list keyed by PATH
 * (config/access.js — a supervisor holds maintenance.view but must not see /procurement). Folding a
 * page into a hub changes its path, which would silently move it out from under its own deny rule.
 * So each tab declares `was` — the route it replaced — and is hidden unless the viewer both holds
 * the permission and is not blocked from that original path. A role that could open three of five
 * pages before still sees exactly those three, now as tabs.
 *
 * Tab shape: { key, label, icon?, permission?, was?, Component }
 *   permission — omit (or null) for "any authenticated user"; `permissionAny` takes a list instead.
 *   was        — the pre-consolidation route, checked against the role deny list.
 */
export default function TabbedHub({ title, subtitle, ariaLabel, tabs, initialTab, children }) {
  const { can, canAny, roles } = usePermissions();
  const { t } = useI18n();

  const visible = useMemo(
    () =>
      tabs.filter((tab) => {
        const allowed = tab.permissionAny ? canAny(tab.permissionAny) : can(tab.permission ?? null);
        return allowed && !(tab.was && pathBlockedForRoles(tab.was, roles));
      }),
    [tabs, can, canAny, roles],
  );

  // The active tab lives in the URL (?tab=…) so every section stays deep-linkable. An unknown or
  // not-permitted tab falls back to `initialTab` if the hub named one, then to the first tab this
  // user can actually see.
  //
  // `initialTab` exists for the links that predate a hub's tab order: /parts?focus=812 means the
  // requests board and always did, and putting a new tab in front of it must not silently redirect
  // every such link. The hub reads its own deep-link params and names the tab they belong to.
  const [searchParams, setSearchParams] = useSearchParams();
  const current =
    visible.find((tab) => tab.key === searchParams.get('tab')) ||
    (initialTab ? visible.find((tab) => tab.key === initialTab) : null) ||
    visible[0];
  // Switching tabs drops the previous section's own params (?focus, ?ticket, …) — they mean nothing
  // to the tab being opened.
  const setActive = (key) => setSearchParams({ tab: key }, { replace: true });
  const Active = current?.Component;

  return (
    <div>
      <div className="mx-auto max-w-7xl px-4 pt-8 sm:px-6 lg:px-8">
        <PageHeader title={title} subtitle={subtitle}>{children}</PageHeader>
        {visible.length > 1 && (
          <Tabs tabs={visible} active={current?.key} onChange={setActive} ariaLabel={ariaLabel} />
        )}
      </div>

      {Active ? (
        <div role="tabpanel" id={`panel-${current.key}`} aria-labelledby={`tab-${current.key}`}>
          <Active />
        </div>
      ) : (
        // Every tab filtered out. The route gate let this user in on one permission but the deny
        // list took the rest away — say so plainly instead of rendering an empty page.
        <div className="mx-auto max-w-7xl px-4 py-16 text-center text-sm text-slate-500 sm:px-6 lg:px-8">
          {t('You don’t have access to this page')}
        </div>
      )}
    </div>
  );
}
